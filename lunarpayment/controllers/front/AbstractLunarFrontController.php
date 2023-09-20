<?php

namespace Lunar\Payment\controllers\front;

use \Order;
use \Tools;
use \Module;
use \Context;
use \Currency;
use \Validate;
use \Configuration;
use Customer;
use \PrestaShopLogger;


use Lunar\Lunar as ApiClient;
use Lunar\Payment\methods\LunarCardsMethod;
use Lunar\Payment\methods\LunarMobilePayMethod;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * 
 */
abstract class AbstractLunarFrontController extends \ModuleFrontController
{
    const REMOTE_URL = 'https://pay.lunar.money/?id=';
    const TEST_REMOTE_URL = 'https://hosted-checkout-git-develop-lunar-app.vercel.app/?id=';

    /** 
     * @var LunarCardsMethod|LunarMobilePayMethod|null $method 
     */
    protected $method = null;
    
    private ApiClient $lunarApiClient;
    public $errors = [];
    private string $transactionId = '';
    private string $intentIdKey = '_lunar_intent_id';
    private string $baseURL = '';
    private bool $testMode = false;
    private bool $isInstantMode = false;
    private ?Order $order = null;
    private array $args = [];
    private string $paymentIntentId = '';
    private string $controllerURL = 'lunar/index/HostedCheckout';
    private string $publicKey = '';


    public function __construct(string $methodName)
    {
        parent::__construct();
        $this->setTemplate('module:lunarpayment/views/templates/front/empty.tpl');
                
        $this->validateCustomer();

        $this->setPaymentMethod($methodName);

        if (!$this->method) {
            $this->errors['error_msg'] = $this->errorMessage('Payment method not loaded');
            $this->redirectWithNotifications('index.php?controller=order');
        }

        $this->baseURL = __PS_BASE_URI__;
        $this->isInstantMode = ('instant' == $this->getConfigValue('CHECKOUT_MODE'));


        $this->testMode = 'test' == $this->getConfigValue('TRANSACTION_MODE');
        if ($this->testMode) {
            $this->publicKey =  $this->getConfigValue('TEST_PUBLIC_KEY');
            $privateKey =  $this->getConfigValue('TEST_SECRET_KEY');
        } else {
            $this->publicKey = $this->getConfigValue('LIVE_PUBLIC_KEY');
            $privateKey = $this->getConfigValue('LIVE_SECRET_KEY');
        }

        /** API Client instance */
        $this->lunarApiClient = new ApiClient($privateKey);  


        // $allValues = Tools::getAllValues();

        // Tools::redirect();  
    }

    /**
     * @return void
     */
    private function setPaymentMethod($methodName)
    {
        switch($methodName) {
            case LunarCardsMethod::METHOD_NAME:
                $this->method = $this->module->cardsMethod;
                break;
            case LunarMobilePayMethod::METHOD_NAME:
                $this->method = $this->module->mobilePayMethod;
                break;
            default:
                return;  
        }
    }


    /**
     * @see FrontController::postProcess()
     */
    public function postProcess()
    {
        if (false === $this->checkIfContextIsValid() || false === $this->checkIfPaymentOptionIsAvailable()) {
            Tools::redirect($this->context->link->getPageLink('order', true, (int) $this->context->language->id));
        }     
        
        $cart = $this->context->cart;
        $customer = new Customer($cart->id_customer);
        
        $orderId = (int) Order::getIdByCartId((int) $cart->id );

        $this->setArgs();

        Tools::redirect($this->context->link->getPageLink('order-confirmation', true, (int) $this->context->language->id,
            [
                'id_cart' => (int) $cart->id,
                'id_module' => (int) $this->module->id,
                'id_order' =>  $orderId,
                'key' => $customer->secure_key,
            ]
        ));
    }


    /**
     * SET ARGS
     */
    private function setArgs()
    {
        if ($this->testMode) {
            $this->args['test'] = $this->getTestObject();
        }

        $this->args['integration'] = [
            'key' => $this->publicKey,
            'name' => $this->getConfigValue('SHOP_TITLE') ?? Configuration::get('PS_SHOP_NAME'),
            'logo' => $this->getConfigValue('LOGO_URL'),
        ];

        if ($this->getConfigValue('CONFIGURATION_ID')) {
            $this->args['mobilePayConfiguration'] = [
                'configurationID' => $this->getConfigValue('CONFIGURATION_ID'),
                'logo' => $this->getConfigValue('LOGO_URL'),
            ];
        }

        $this->args['custom'] = [
            'orderId' => $this->order->id,
        ];

        $this->args['redirectUrl'] = $this->getCurrentURL();
        $this->args['preferredPaymentMethod'] = $this->getConfigValue('METHOD_NAME');
    }

    /**
     *
     */
    private function validateCustomer()
    {
        if (!Validate::isLoadedObject($this->context->customer)) {
            $this->errors = ['message' => 'No customer found! Please enter valid data!'];
            $this->redirectBack();
        }
    }

    /**
     * @return bool
     */
    private function checkIfContextIsValid()
    {
        return true === Validate::isLoadedObject($this->context->cart)
            && true === Validate::isUnsignedInt($this->context->cart->id_customer)
            && true === Validate::isUnsignedInt($this->context->cart->id_address_delivery)
            && true === Validate::isUnsignedInt($this->context->cart->id_address_invoice)
            && false === $this->context->cart->isVirtualCart();
    }

    /**
     * Check that this payment option is still available 
     * (maybe someone saved the url or changed other things)
     *
     * @return bool
     */
    private function checkIfPaymentOptionIsAvailable()
    {
        if (!$this->getConfigValue('METHOD_STATUS')) {
            return false;
        }

        $modules = Module::getPaymentModules();

        if (empty($modules)) {
            return false;
        }

        // @TODO change module check with method check
        foreach ($modules as $module) {
            if (isset($module['name']) && $this->module->name === $module['name']) {
                return true;
            }
        }

        return false;
    }

    /**
     * 
     */
    private function redirectBack()
    {
        // $this->redirectWithNotifications($this->getCurrentURL());
        Tools::redirect($this->getCurrentURL());
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
        $matchCurrency = Currency::getIsoCodeById($this->order->id_currency) == $transaction['amount']['currency'];
        $matchAmount = $this->args['amount']['decimal'] == $transaction['amount']['decimal'];

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
    private function getTestObject(): array
    {
        return [
            "card"        => [
                "scheme"  => "supported",
                "code"    => "valid",
                "status"  => "valid",
                "limit"   => [
                    "decimal"  => "25000.99",
                    "currency" => $this->args['amount']['currency'],
                    
                ],
                "balance" => [
                    "decimal"  => "25000.99",
                    "currency" => $this->args['amount']['currency'],
                    
                ]
            ],
            "fingerprint" => "success",
            "tds"         => array(
                "fingerprint" => "success",
                "challenge"   => true,
                "status"      => "authenticated"
            ),
        ];
    }
    
    /**
     * @return string|null
     */
    private function getConfigValue($configKey)
    {
        if (isset($this->method->{$configKey})) {
            return Configuration::get($this->method->{$configKey});
        }

        return null;
    }

    /**
     * 
     */
    private function errorMessage($string)
    {
        return $this->module->l($string);
    }
}