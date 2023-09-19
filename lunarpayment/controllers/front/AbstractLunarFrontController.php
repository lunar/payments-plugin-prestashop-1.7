<?php

namespace Lunar\Payment\controllers\front;

use \Order;
use \Module;
use \Context;
use \Currency;
use \Validate;
use \Configuration;
use \PrestaShopLogger;


use Lunar\Lunar as ApiClient;

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
    protected $method;
    
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


    public function __construct()
    {
        parent::__construct();
        $this->setTemplate('module:lunarpayment/views/templates/front/empty.tpl');
                
        $this->validateCustomer();


        // $allValues = Tools::getAllValues();

        // Tools::redirect();  
    }

    /**
     * 
     */
    public function initialize($method)
    {
        $this->method = $method;

        $this->baseURL = __PS_BASE_URI__;
        $this->isInstantMode = ('instant' == $this->getConfigValue($this->method->CHECKOUT_MODE));


        $this->testMode = 'test' == $this->getConfigValue($this->method->TRANSACTION_MODE);
        if ($this->testMode) {
            $this->publicKey =  $this->getConfigValue($this->method->TEST_PUBLIC_KEY);
            $privateKey =  $this->getConfigValue($this->method->TEST_SECRET_KEY);
        } else {
            $this->publicKey = $this->getConfigValue($this->method->LIVE_PUBLIC_KEY);
            $privateKey = $this->getConfigValue($this->method->LIVE_SECRET_KEY);
        }

        /** API Client instance */
        $this->lunarApiClient = new ApiClient($privateKey);  
    }


    /**
     * @see FrontController::postProcess()
     */
    public function postProcess()
    {
        file_put_contents("zzz.log", json_encode('POST PREOCESS', JSON_PRETTY_PRINT) . PHP_EOL, FILE_APPEND);
    }

    /**
     *
     */
    protected function validateCustomer()
    {
        if (!Validate::isLoadedObject($this->context->customer)) {
            $this->errors = ['message' => 'No customer found! Please enter valid data!'];
            $this->redirectBack();
        }
    }

    /**
     * Check that this payment option is still available 
     * (maybe someone saved the url or changed other things)
     *
     * @return bool
     */
    protected function checkIfPaymentOptionIsAvailable()
    {
        if (!$this->getConfigValue($this->method->METHOD_STATUS)) {
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
    protected function redirectBack()
    {
        $this->redirectWithNotifications($this->getCurrentURL());
    }

        /**
     * Parses api transaction response for errors
     */
    protected function parseApiTransactionResponse($transaction)
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
    protected function isTransactionSuccessful($transaction)
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
    protected function getResponseError($result)
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
    protected function getTestObject(): array
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
     *
     */
    protected function getConfigValue($configKey)
    {
        return Configuration::get($configKey);
    }
}