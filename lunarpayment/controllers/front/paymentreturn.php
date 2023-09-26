<?php

use \Order;
use \Customer;
use \OrderCore;
use \Configuration;
use Lunar\Exception\ApiException;
use Lunar\Payment\controllers\front\AbstractLunarFrontController;

/**
 * 
 */
class LunarpaymentPaymentReturnModuleFrontController extends AbstractLunarFrontController 
{
    private OrderCore $order;
    private Customer $customer;
    private $currencyCode = '';
    private $totalAmount = '';
    private string $paymentIntentId = '';

    public function __construct() {
        parent::__construct();
        $this->display_column_right = false;
        $this->display_column_left  = false;
    }

    public function postProcess()
    {
        $captured = 'NO';
        $errorResponse = null;
        $delayedMode = ('delayed' == $this->getConfigValue('CHECKOUT_MODE'));
        
        $this->customer  = new Customer((int) $this->cart->id_customer);
        $this->totalAmount = (string) $this->cart->getOrderTotal(true, Cart::BOTH);
        $this->currencyCode = Currency::getIsoCodeById((int) $this->cart->id_currency);

        $paymentIntentId = $this->getPaymentIntentFromCart();

        if (!$paymentIntentId) {
            // @TOTO cancel payment (get intent from cookie)
            // $this->redirectBackWithNotification('The current cart has been modified. Your payment has been cancelled. Please make another payment.');
            $this->redirectBackWithNotification('The current cart has been modified. <br> Please make another payment.');
        }

        try {
            $apiResponse = $this->lunarApiClient->payments()->fetch($paymentIntentId);

            if (!$this->parseApiTransactionResponse($apiResponse)) {
                $errorResponse = $apiResponse;
                throw new PrestaShopPaymentException();
            }

            if ($delayedMode) {
                $data = [
                    'amount' => [
                        'currency' => $this->currencyCode,
                        'decimal' => $this->totalAmount,
                    ],
                ];

                $response = $this->lunarApiClient->payments()->capture($paymentIntentId, $data);

                if ('completed' == $response['captureState']) {
                    $captured = 'YES';
                } else {
                    $errorResponse = $response;
                    throw new PrestaShopPaymentException();
                }
            }

            $orderStatusCode = $delayedMode ? Configuration::get('PS_OS_PAYMENT') : $this->getConfigValue('ORDER_STATUS');

            if ($this->orderValidation($orderStatusCode)) {
                $message = 'Trx ID: ' . $paymentIntentId . '
                    Authorized Amount: ' . $this->totalAmount . '
                    Captured Amount: ' . $captured == 'YES' ? $this->totalAmount : '0' . '
                    Order time: ' . 'test_time' . '
                    Currency code: ' . $this->currencyCode;

                $message = strip_tags($message, '<br>');
                $this->maybeAddOrderMessage($message);
                
            } else {
                $this->lunarApiClient->payments()->cancel($paymentIntentId, ['amount' => $this->totalAmount]); //Cancel Order
                $this->redirectBackWithNotification('Error validating the order. Plase contact system administrator');
            }

        } catch (ApiException $e) {
            $errorResponse = ['text' => $e->getMessage()];
        } catch (\PrestaShopPaymentException $ppe) {
            // parsed bellow
        }

        if ($errorResponse) {
            return $this->redirectToErrorPage($errorResponse);
        }

        $this->module->storeTransactionID($paymentIntentId, $this->module->currentOrder, $this->totalAmount, $captured);
        
        $this->redirect_after = $this->context->link->getPageLink('order-confirmation', true,
            (int) $this->context->language->id,
            [
                'id_cart' => (int) $this->cart->id,
                'id_module' => (int) $this->module->id,
                'id_order' => $this->module->currentOrder,
                'key' => $this->customer->secure_key,
            ]
        );
    }

    /**
     * 
     */
    private function orderValidation($orderStatusCode)
    {
        $isValidOrder = $this->module->validateOrder(
            $this->cart->id, 
            $orderStatusCode, 
            $this->cart->getOrderTotal(), 
            $this->module->displayName . ' (' . $this->paymentMethod->METHOD_NAME . ')', 
            null,
            [
                'transaction_id' => $this->paymentIntentId
            ], 
            (int) $this->cart->id_currency, 
            false, 
            $this->customer->secure_key
        );

        if ($isValidOrder) {
            $this->order = Order::getByCartId($this->cart->id);
        }

        return $isValidOrder;
    }
    
    /**
     * 
     */
    private function redirectToErrorPage($transactionResult = null)
    {
        $data = ["lunar_order_error" => 1];

        $transactionResult 
            ? $data + ["lunar_error_message" => $this->getResponseError($transactionResult)]
            : null;

        $this->context->smarty->assign($data);
        
        return $this->setTemplate('module:lunarpayment/views/templates/front/payment_error.tpl');
    }

    /**
     * Parses api transaction response for errors
     */
    private function parseApiTransactionResponse($transaction)
    {
        if (! $this->isTransactionSuccessful($transaction)) {
            PrestaShopLogger::addLog("Transaction with error: " . json_encode($transaction, JSON_PRETTY_PRINT));
            return false;
        }

        return true;
    }

    /**
     * Checks if the transaction was successful and
     * the data was not tempered with.
     * 
     * @return bool
     */
    private function isTransactionSuccessful($transaction)
    {   
        $matchCurrency = $this->currencyCode == $transaction['amount']['currency'];
        $matchAmount = $this->totalAmount == $transaction['amount']['decimal'];

        return (true == $transaction['authorisationCreated'] && $matchCurrency && $matchAmount);
    }

    /**
     * Gets errors from a failed api request
     * @param array $result The result returned by the api wrapper.
     * @return string
     */
    private function getResponseError($result)
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
    private function maybeAddOrderMessage($message)
    {
        if (! Validate::isCleanHtml($message)) {
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
            $msg->id_cart     = (int) $this->cart->id;
            $msg->id_customer = (int) $this->cart->id_customer;
            $msg->id_order    = (int) $this->module->currentOrder;
            $msg->private     = 1;
            $msg->add();
        }
    }
}
