<?php

namespace Lunar\Payment\controllers\front;

use \Db;
use \Cart;
use \Tools;
use \Module;
use \Validate;
use \Configuration;

use Lunar\Lunar as ApiClient;
use Lunar\Payment\methods\LunarCardMethod;
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

    /** @var Lunarpayment */
    public $module;

    /** @var LunarCardMethod|LunarMobilePayMethod|null */
    protected $paymentMethod = null;
    
    protected ApiClient $lunarApiClient;

    public $errors = [];
    protected string $intentIdKey = '_lunar_intent_id';
    protected bool $testMode = false;
    protected ?Cart $cart = null;
    protected array $args = [];
    protected string $publicKey = '';


    public function __construct()
    {
        parent::__construct();
        $this->setTemplate('module:lunarpayment/views/templates/front/empty.tpl');
                
        $this->setPaymentMethod();

        if (!$this->paymentMethod) {
            $this->redirectBackWithNotification('Payment method not loaded');
        }
    }
    
    /**
     * @return void
     */
    private function setPaymentMethod()
    {
        switch(Tools::getValue('lunar_method')) {
            case LunarCardMethod::METHOD_NAME:
                $this->paymentMethod = $this->module->cardMethod;
                break;
            case LunarMobilePayMethod::METHOD_NAME:
                $this->paymentMethod = $this->module->mobilePayMethod;
                break;
            default:
                return;  
        }
    }

    /**
     * 
     */
    public function init()
    {
        parent::init();

        $this->cart = $this->context->cart;

        $this->validate();

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
    }

    /**
     * 
     */
    protected function getPaymentIntentCookie()
    {
        return $this->context->cookie->{$this->intentIdKey};
    }

    /**
     * 
     */
    protected function savePaymentIntentCookie($paymentIntentId)
    {
        $this->context->cookie->__set($this->intentIdKey, $paymentIntentId);
        $this->context->cookie->write();
    }

    /**
     * @return void
     */
    private function validate()
    {
        $this->validateCustomer();
        $this->checkIfContextIsValid();
        $this->checkIfPaymentOptionIsAvailable();
    }

    /**
     * @return void
     */
    private function validateCustomer()
    {
        if (!Validate::isLoadedObject($this->context->customer)) {
            $this->redirectBackWithNotification('Customer validation failed');
        }
    }

    /**
     * @return void
     */
    private function checkIfContextIsValid()
    {
        if (
            !(
                true === Validate::isLoadedObject($this->cart)
                && true === Validate::isUnsignedInt($this->cart->id_customer)
                && true === Validate::isUnsignedInt($this->cart->id_address_delivery)
                && true === Validate::isUnsignedInt($this->cart->id_address_invoice)
                && false === $this->cart->isVirtualCart()
            )
        ) {
            $this->redirectBackWithNotification('Context validations failed');
        }
    }

    /**
     * Check that this payment option is still available 
     * (maybe someone saved the url or changed other things)
     *
     * @return void
     */
    private function checkIfPaymentOptionIsAvailable()
    {
        $valid = true;
        $modules = Module::getPaymentModules();

        if (
            'enabled' != $this->getConfigValue('METHOD_STATUS')
            || 
            empty($modules)
        ) {
            $valid = false;
        }

        foreach ($modules as $module) {
            if (!(isset($module['name']) || $this->module->name === $module['name'])) {
                $valid = false;
            }
        }

        if (!$valid) {
            $this->redirectBackWithNotification('Payment method validation failed');
        }
    }

    /**
     * 
     */
    protected function redirectBackWithNotification(string $errorMessage)
    {
        // $this->errors['error_code'] = 'Lunar error';
        // $this->errors['msg_long'] = $errorMessage;
        $this->errors['error_msg'] = $this->errorMessage($errorMessage);
        $this->redirectWithNotifications('index.php?controller=order');
    }
    
    /**
     * @return mixed
     */
    protected function getConfigValue($configKey)
    {
        if (isset($this->paymentMethod->{$configKey})) {
            return Configuration::get($this->paymentMethod->{$configKey});
        }

        return null;
    }

    /**
     * 
     */
    protected function errorMessage($string)
    {
        return $this->t($string);
    }

    /**
     * 
     */
    protected function t($string)
    {
        return $this->module->t($string);
    }
}
