<?php

use Lunar\Exception\ApiException;
use Lunar\Payment\controllers\front\AbstractLunarFrontController;

/**
 * 
 */
class LunarpaymentPaymentReturnModuleFrontController extends AbstractLunarFrontController 
{
    public $display_column_right;
    public $display_column_left;

    private Customer $customer;
    private string $currencyCode = '';
    private string $totalAmount = '';
    private string $paymentIntentId = '';
    private bool $captured = false;
    private bool $cartWasTampered = false;
    private string $errorMessage = '';

    public function __construct() {
        parent::__construct();
        $this->display_column_right = false;
        $this->display_column_left  = false;
    }

    public function postProcess()
    {
        $this->customer  = new Customer((int) $this->contextCart->id_customer);
        $this->totalAmount = (string) $this->contextCart->getOrderTotal(true, Cart::BOTH);
        $this->currencyCode = Currency::getIsoCodeById((int) $this->contextCart->id_currency);

        $this->paymentIntentId = $this->getPaymentIntentCookie();

        $instantMode = ('instant' == $this->getConfigValue('CHECKOUT_MODE'));
        $orderStatusCode = $instantMode ? $this->getConfigValue('ORDER_STATUS') : Configuration::get('PS_OS_PAYMENT');

        if (!$this->paymentIntentId) {
            $this->redirectBackWithNotification('The payment intent id wasn\'t found.');
        }

        try {
            $apiResponse = $this->lunarApiClient->payments()->fetch($this->paymentIntentId);

            if (!$this->parseApiTransactionResponse($apiResponse)) {
                !$this->cartWasTampered 
                    ? $this->errorMessage = $this->getResponseError($apiResponse)
                    : null;
                throw new PrestaShopPaymentException();
            }

            if ($instantMode) {
                $captureResponse = $this->lunarApiClient->payments()->capture($this->paymentIntentId, $this->getPaymentData());

                if (!empty($captureResponse) && 'completed' == $captureResponse['captureState']) {
                    $this->captured = true;
                } else {
                    $this->errorMessage = 'Capture payment failed. Please try again or contact system administrator.';
                    $this->maybeCancelPayment();
                    throw new PrestaShopPaymentException();
                }
            }

        } catch (ApiException $e) {
            $this->errorMessage = $e->getMessage();

        } catch (PrestaShopPaymentException $ppe) {
            // stored in errorMessage

        } catch (\Exception $ex) {
            PrestaShopLogger::addLog("Lunar general exception: " . json_encode($ex->getMessage()));
        }

        if ($this->errorMessage) {
            return $this->redirectBackWithNotification($this->errorMessage);
        }

        if ($this->orderValidation($orderStatusCode)) {
            $this->maybeAddOrderMessage();

        } else {
            $this->errorMessage = 'Failed validating the order. Please try again or contact system administrator. ';
            $this->maybeCancelPayment();
                   
            return $this->redirectToErrorPage();
        }

        $this->storeLunarTransaction();

        $this->context->cookie->__unset($this->intentIdKey);

        $this->redirect_after = $this->buildRedirectLink();
    }

    /**
     * 
     */
    private function orderValidation($orderStatusCode): bool
    {
        return $this->module->validateOrder(
            $this->contextCart->id, 
            $orderStatusCode, 
            $this->contextCart->getOrderTotal(), 
            $this->getConfigValue('METHOD_TITLE'),
            null,
            [
                'transaction_id' => $this->paymentIntentId
            ], 
            (int) $this->contextCart->id_currency, 
            false, 
            $this->customer->secure_key
        );
    }
    
    /**
     * 
     */
    private function buildRedirectLink(): string
    {
        return $this->context->link->getPageLink(
                'order-confirmation', 
                true,
                (int) $this->context->language->id,
                [
                    'id_cart' => (int) $this->contextCart->id,
                    'id_module' => (int) $this->module->id,
                    'id_order' => $this->module->currentOrder,
                    'key' => $this->customer->secure_key,
                ]
            );
    }

    /**
     * 
     */
    private function getPaymentData(): array
    {
        return [
            'amount' => [
                'currency' => $this->currencyCode,
                'decimal' => $this->totalAmount,
            ],
        ];
    }
    
    /**
     * 
     */
    private function redirectToErrorPage(): void
    {
        $data = ["lunar_order_error" => 1];

        $this->errorMessage ? array_merge($data, ["lunar_error_message" => $this->errorMessage]) : null;

        $this->context->smarty->assign($data);
        
        $this->setTemplate('module:lunarpayment/views/templates/front/payment_error.tpl');
    }

    /**
     * Parses api transaction response for errors
     */
    private function parseApiTransactionResponse($transaction): bool
    {
        if (! $this->isTransactionSuccessful($transaction)) {
            PrestaShopLogger::addLog("Transaction with error: " . json_encode($transaction));
            return false;
        }

        return true;
    }

    /**
     * Checks if the transaction was successful and
     * the data was not tempered with.
     */
    private function isTransactionSuccessful($transaction): bool
    {   
        $matchCurrency = $this->currencyCode == ($transaction['amount']['currency'] ?? '');
        $matchAmount = $this->totalAmount == ($transaction['amount']['decimal'] ?? '');
        
        if (true == $transaction['authorisationCreated'] && !($matchCurrency && $matchAmount)) {
            $this->cartWasTampered = true;
            $this->errorMessage .= ' Currency or Amount doesn\'t match. Data was tampered. ';
            $this->maybeCancelPayment();
            return false;
        }

        return (true == $transaction['authorisationCreated'] && $matchCurrency && $matchAmount);
    }

    
    /**
     * 
     */
    private function maybeCancelPayment(): void
    {
        // cancel only authorized payments
        if (!$this->captured) {
            $result = $this->lunarApiClient->payments()->cancel($this->paymentIntentId, $this->getPaymentData());
        }

        if (!empty($result) && 'completed' == $result['cancelState']) {
            $this->errorMessage .= 'Your authorized payment was cancelled.';
        }

        $this->context->cookie->__unset($this->intentIdKey);
    }

    /**
     * Gets errors from a failed api request
     * @param array $result The result returned by the api wrapper.
     */
    private function getResponseError($result): string
    {
        $error = [];
        // if this is just one error
        if (isset($result['text'])) {
            return $result['text'];
        }

        if (isset($result['code']) && isset($result['error'])) {
            return $result['code'] . '-' . $result['error'];
        }

        // otherwise this is a multi field error
        if ($result) {
            foreach ($result as $fieldError) {
                $error[] = $fieldError['field'] . ':' . $fieldError['message'];
            }
        }

        return implode(' ', $error);
    }

    /**
     * 
     */
    private function storeLunarTransaction(): bool
    {
        return $this->module->storeTransaction(
            $this->paymentIntentId, 
            $this->module->currentOrder, 
            $this->totalAmount, 
            $this->paymentMethod->METHOD_NAME, 
            $this->captured ? 'YES' : 'NO'
        );
    }

    /**
     * 
     */
    private function maybeAddOrderMessage(): void
    {
        $message = 'Trx ID: ' . $this->paymentIntentId . '
                    Authorized Amount: ' . $this->totalAmount . '
                    Captured Amount: ' . $this->captured ? $this->totalAmount : '0' . '
                    Order time: ' . date('Y-m-d H:i:s') . '
                    Currency code: ' . $this->currencyCode;

        $message = strip_tags($message, '<br>');

        if (! Validate::isCleanHtml($message) || !$this->module->currentOrder) {
            return;
        }

        if ($this->module->getPSV() == '1.7.2') {
            $id_customer_thread = CustomerThread::getIdCustomerThreadByEmailAndIdOrder($this->customer->email, $this->module->currentOrder);
            
            if (! $id_customer_thread) {
                $customer_thread = new CustomerThread();
                $customer_thread->id_contact  = 0;
                $customer_thread->id_customer = (int) $this->customer->id;
                $customer_thread->id_shop     = (int) $this->context->shop->id;
                $customer_thread->id_order    = (int) $this->module->currentOrder;
                $customer_thread->id_lang     = (int) $this->context->language->id;
                $customer_thread->email       = $this->customer->email;
                $customer_thread->status      = 'open';
                $customer_thread->token       = Tools::passwdGen(12);
                $customer_thread->add();
            } else {
                $customer_thread = new CustomerThread((int) $id_customer_thread);
            }

            $customer_message = new CustomerMessage();
            $customer_message->id_customer_thread = $customer_thread->id;
            $customer_message->id_employee        = 0;
            $customer_message->message            = $message;
            $customer_message->private            = 1;
            $customer_message->add();

        } else {
            $msg = new Message();
            $msg->message     = $message;
            $msg->id_cart     = (int) $this->contextCart->id;
            $msg->id_customer = (int) $this->contextCart->id_customer;
            $msg->id_order    = (int) $this->module->currentOrder;
            $msg->private     = 1;
            $msg->add();
        }
    }
}
