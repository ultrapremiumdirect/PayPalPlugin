<?php

namespace Sylius\PayPalPlugin\Service;

use Doctrine\Persistence\ObjectManager;
use SM\Factory\FactoryInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\OrderCheckoutStates;
use Sylius\Component\Core\OrderCheckoutTransitions;
use Sylius\Component\Order\OrderTransitions;
use Sylius\Component\Order\StateResolver\StateResolverInterface;
use Sylius\Component\Payment\Model\PaymentInterface as PaymentInterfaceAlias;
use Sylius\Component\Payment\PaymentTransitions;
use Sylius\PayPalPlugin\Api\CacheAuthorizeClientApiInterface;
use Sylius\PayPalPlugin\Api\CompleteOrderApiInterface;
use Sylius\PayPalPlugin\Api\OrderDetailsApiInterface;
use Sylius\PayPalPlugin\Exception\PaymentNotFoundException;
use Sylius\PayPalPlugin\Payum\Action\StatusAction;
use Sylius\PayPalPlugin\Provider\PaymentProviderInterface;
use Sylius\PayPalPlugin\Updater\PaymentUpdaterInterface;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessor;

class WebhookService
{
    const INSTRUMENT_DECLINED = 'INSTRUMENT_DECLINED';
    const PAYER_ACTION_REQUIRED = 'PAYER_ACTION_REQUIRED';
    const DUPLICATE_INVOICE_ID = 'DUPLICATE_INVOICE_ID';

    private FactoryInterface $stateMachineFactory;
    private PaymentProviderInterface $paymentProvider;
    private ObjectManager $paymentManager;
    private CacheAuthorizeClientApiInterface $authorizeClientApi;
    private CompleteOrderApiInterface $completeOrderApi;
    private OrderDetailsApiInterface $orderDetailsApi;
    private PropertyAccessor $propertyAccessor;
    private PaymentUpdaterInterface $payPalPaymentUpdater;
    private StateResolverInterface $orderPaymentStateResolver;

    public function __construct(
        FactoryInterface                 $stateMachineFactory,
        PaymentProviderInterface         $paymentProvider,
        ObjectManager                    $paymentManager,
        CacheAuthorizeClientApiInterface $authorizeClientApi,
        CompleteOrderApiInterface        $completeOrderApi,
        OrderDetailsApiInterface         $orderDetailsApi,
        PaymentUpdaterInterface          $payPalPaymentUpdater,
        StateResolverInterface           $orderPaymentStateResolver
    )
    {
        $this->stateMachineFactory = $stateMachineFactory;
        $this->paymentProvider = $paymentProvider;
        $this->paymentManager = $paymentManager;
        $this->authorizeClientApi = $authorizeClientApi;
        $this->completeOrderApi = $completeOrderApi;
        $this->orderDetailsApi = $orderDetailsApi;
        $this->propertyAccessor = PropertyAccess::createPropertyAccessor();
        $this->payPalPaymentUpdater = $payPalPaymentUpdater;
        $this->orderPaymentStateResolver = $orderPaymentStateResolver;
    }

    /**
     * @param string $paypalOrderID
     * @param string $payerId
     * @return bool
     */
    public function isValidPaypalOrder(string $paypalOrderID, string $payerId): bool
    {
        try {
            $payment = $this->paymentProvider->getByPayPalOrderId($paypalOrderID);
        } catch (PaymentNotFoundException $e) {
            unset($e);
            return false;
        }

        /** @var PaymentMethodInterface $paymentMethod */
        $paymentMethod = $payment->getMethod();
        $token = $this->authorizeClientApi->authorize($paymentMethod);

        // Retrieve order details
        $details = $this->orderDetailsApi->get($token, $paypalOrderID);

        return $this->propertyAccessor->getValue($details, '[payer][payer_id]') === $payerId;
    }

    /**
     * @param string $paypalOrderID
     * @return void
     * @throws \SM\SMException
     */
    public function handlePaypalOrder(string $paypalOrderID): void
    {
        try {
            $payment = $this->paymentProvider->getByPayPalOrderId($paypalOrderID);
        } catch (PaymentNotFoundException $e) {
            unset($e);
            return;
        }

        if (!is_null($payment) && isset($payment->getDetails()['status'])
            && in_array($payment->getDetails()['status'], [StatusAction::STATUS_CREATED, StatusAction::STATUS_PROCESSING])
            && in_array($payment->getState(), [PaymentInterfaceAlias::STATE_CART, PaymentInterfaceAlias::STATE_NEW, PaymentInterfaceAlias::STATE_PROCESSING])
        ) {
            /** @var OrderInterface $order */
            $order = $payment->getOrder();

            $stateMachine = $this->stateMachineFactory->get($payment, PaymentTransitions::GRAPH);
            if ($stateMachine->can(PaymentTransitions::TRANSITION_CREATE)) {
                $stateMachine->apply(PaymentTransitions::TRANSITION_CREATE);
            }

            /** @var PaymentMethodInterface $paymentMethod */
            $paymentMethod = $payment->getMethod();
            $token = $this->authorizeClientApi->authorize($paymentMethod);

            // Retrieve Paypal order details
            $details = $this->orderDetailsApi->get($token, $paypalOrderID);

            switch ($this->propertyAccessor->getValue($details, '[status]')) {
                case 'APPROVED':
                    if ($this->isOrderFullyPaid($paypalOrderID, $payment, $token)) {
                        $this->ensureOrderCompleted($order);
                        $this->_captureOrder($paypalOrderID, $payment, $token);
                    }
                    break;
                case 'COMPLETED':
                    if ($this->isOrderFullyPaid($paypalOrderID, $payment, $token)) {
                        $this->ensureOrderCompleted($order);
                        $this->_markOrderStatus($details, $payment, StatusAction::STATUS_COMPLETED);
                    }
                    break;
                case 'CREATED':
                    // Do nothing for now
                    break;
                default:
                    if (isset($details['debug_id'])) {
                        $this->_processError($details, $payment);
                    } else {
                        $this->_markOrderStatus($details, $payment, StatusAction::STATUS_PROCESSING);
                    }
                    break;
            }
        }
    }

    /**
     * @param string $paypalOrderID
     * @param PaymentInterface $payment
     * @param string|null $token
     * @param bool $processError
     * @return bool
     * @throws \SM\SMException
     */
    public function isOrderFullyPaid(
        string $paypalOrderID,
        PaymentInterface $payment,
        ?string $token = null,
        bool $processError = true
    ): bool
    {
        if (is_null($token)) {
            /** @var PaymentMethodInterface $paymentMethod */
            $paymentMethod = $payment->getMethod();
            $token = $this->authorizeClientApi->authorize($paymentMethod);
        }

        /** @var OrderInterface $order */
        $order = $payment->getOrder();

        // Retrieve Paypal order details
        $details = $this->orderDetailsApi->get($token, $paypalOrderID);

        // Update Payment amount
        /** @var float|null $totalPaypal */
        $totalPaypal = $this->propertyAccessor->getValue($details, '[purchase_units][0][amount][value]');

        if (is_null($totalPaypal)) {
            return false;
        }

        $totalPaypalInt = (int) round($totalPaypal * 100);
        if ($totalPaypalInt != $order->getTotal()) {

            if ($processError) {
                // Update amount paid
                $this->payPalPaymentUpdater->updateAmount($payment, $totalPaypalInt);
                $this->orderPaymentStateResolver->resolve($order);

                // Mark payment as failed for partial payment
                $this->_processError([
                    "name"=> "PARTIAL_PAYMENT",
                    "message" => sprintf(
                        "The paid total of the Paypal order %s does not match the total of the Sylius order %s",
                        $totalPaypalInt,
                        $order->getTotal()
                    )
                ], $payment);
            }

            return false;
        }

        return true;
    }

    /**
     * @param OrderInterface $order
     * @return void
     * @throws \SM\SMException
     */
    private function ensureOrderCompleted(OrderInterface $order): void
    {
        if ($order->getCheckoutState() !== OrderCheckoutStates::STATE_COMPLETED) {
            // Try to complete Order if not
            $stateMachine = $this->stateMachineFactory->get($order, OrderCheckoutTransitions::GRAPH);
            if ($stateMachine->can(OrderCheckoutTransitions::TRANSITION_COMPLETE)) {
                $stateMachine->apply(OrderCheckoutTransitions::TRANSITION_COMPLETE);
            }
        }
    }

    /**
     * @param string $paypalOrderID
     * @param PaymentInterface $payment
     * @param string $token
     * @return void
     * @throws \SM\SMException
     */
    private function _captureOrder(string $paypalOrderID, PaymentInterface $payment, string $token): void
    {
        /** @var OrderInterface $order */
        $order = $payment->getOrder();

        // Call to capture Paypal order
        $detailsComplete = $this->completeOrderApi->complete($token, $paypalOrderID);

        // Retrieve Paypal order details
        $details = $this->orderDetailsApi->get($token, $paypalOrderID);

        // Update Payment amount
        /** @var float|null $totalPaypal */
        $totalPaypal = $this->propertyAccessor->getValue($details, '[purchase_units][0][amount][value]');
        if (!is_null($totalPaypal)) {
            $totalPaypalInt = (int) round($totalPaypal * 100);
            if ($totalPaypalInt != $order->getTotal()) {
                $this->payPalPaymentUpdater->updateAmount($payment, $totalPaypalInt);
                $this->orderPaymentStateResolver->resolve($order);
            }
        }

        /** @var string|null $orderDetailstatus */
        $orderDetailstatus = $this->propertyAccessor->getValue($details, '[status]');

        if ($orderDetailstatus === StatusAction::STATUS_COMPLETED
            || $orderDetailstatus === StatusAction::STATUS_PROCESSING) {
            $this->_markOrderStatus($details, $payment, $orderDetailstatus);
        } else {
            if (isset($detailsComplete['debug_id'])) {
                $this->_processError($detailsComplete, $payment);
            }
        }
    }

    /**
     * @param array $orderDetails
     * @param PaymentInterface $payment
     * @param string $status
     * @return void
     * @throws \SM\SMException
     */
    private function _markOrderStatus(array $orderDetails, PaymentInterface $payment, string $status): void
    {
        $detailsPayment = array_merge($payment->getDetails(), [
            'status' => $status,
            'paypal_order_details' => $orderDetails
        ]);

        if ($status === StatusAction::STATUS_COMPLETED) {
            $detailsPayment = array_merge($detailsPayment, [
                'transaction_id' => $this->propertyAccessor->getValue(
                    $orderDetails, '[purchase_units][0][payments][captures][0][id]'
                )
            ]);
        }
        $payment->setDetails($detailsPayment);

        // Update state machine
        $stateMachine = $this->stateMachineFactory->get($payment, PaymentTransitions::GRAPH);
        if ($stateMachine->can(PaymentTransitions::TRANSITION_PROCESS)) {
            $stateMachine->apply(PaymentTransitions::TRANSITION_PROCESS);
        }

        if ($stateMachine->can(PaymentTransitions::TRANSITION_COMPLETE) && $status == StatusAction::STATUS_COMPLETED) {
            $stateMachine->apply(PaymentTransitions::TRANSITION_COMPLETE);
        }

        $this->paymentManager->flush();
    }

    /**
     * @param array $err
     * @param PaymentInterface $payment
     * @return void
     * @throws \SM\SMException
     */
    private function _processError(array $err, PaymentInterface $payment): void
    {
        /** @var string|null $errorName */
        $errorName = $this->propertyAccessor->getValue($err, '[name]');
        if ($errorName === 'UNPROCESSABLE_ENTITY' || $errorName === 'PARTIAL_PAYMENT') {

            // Log error in payment details
            $payment->setDetails(array_merge($payment->getDetails(), [
                'status' => StatusAction::STATE_FAILED,
                'error' => $err
            ]));

            $stateMachine = $this->stateMachineFactory->get($payment, PaymentTransitions::GRAPH);
            if ($stateMachine->can(PaymentTransitions::TRANSITION_PROCESS)) {
                $stateMachine->apply(PaymentTransitions::TRANSITION_PROCESS);
            }

            $stateMachine = $this->stateMachineFactory->get($payment, PaymentTransitions::GRAPH);
            if ($stateMachine->can(PaymentTransitions::TRANSITION_FAIL)) {
                $stateMachine->apply(PaymentTransitions::TRANSITION_FAIL);
            }

            $stateMachineOrder = $this->stateMachineFactory->get($payment->getOrder(), OrderTransitions::GRAPH);
            if ($stateMachineOrder->can(OrderTransitions::TRANSITION_CANCEL)) {
                $stateMachineOrder->apply(OrderTransitions::TRANSITION_CANCEL);
            }
        } elseif (in_array($errorName, ['RESOURCE_NOT_FOUND'])) {

            // Log error in payment details
            $payment->setDetails(array_merge($payment->getDetails(), [
                'status' => 'CANCELED',
                'error' => $err
            ]));

            $stateMachine = $this->stateMachineFactory->get($payment, PaymentTransitions::GRAPH);
            if ($stateMachine->can(PaymentTransitions::TRANSITION_CANCEL)) {
                $stateMachine->apply(PaymentTransitions::TRANSITION_CANCEL);
            }
        } else {
            // Log error in payment details
            $payment->setDetails(array_merge($payment->getDetails(), [
                'error' => $err
            ]));
        }

        $this->paymentManager->flush();
    }

    /**
     * @param array $err
     * @return bool
     */
    private function _isProcessorDeclineError(array $err): bool
    {
        $issue = null;
        if (isset($err['details']) && is_array($err['details']) && isset($err['details'][0]))
            $issue = (string)$this->propertyAccessor->getValue($err, '[details][0][issue]');
        return $issue === self::INSTRUMENT_DECLINED || $issue === self::PAYER_ACTION_REQUIRED;
    }

    /**
     * @param array $err
     * @return bool
     */
    private function _isUnprocessableEntityError(array $err): bool
    {
        $issue = null;
        if (isset($err['details']) && is_array($err['details']) && isset($err['details'][0]))
            $issue = (string)$this->propertyAccessor->getValue($err, '[details][0][issue]');
        return $issue === self::DUPLICATE_INVOICE_ID;
    }
}
