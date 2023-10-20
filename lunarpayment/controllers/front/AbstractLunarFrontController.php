<?php

namespace Lunar\Payment\controllers\front;

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
    public $errors = [];
    public $ssl = true;

    /** @var LunarCardMethod|LunarMobilePayMethod|null */
    protected $paymentMethod = null;
    
    protected ApiClient $lunarApiClient;

    protected string $intentIdKey = '_lunar_intent_id';
    protected bool $testMode = false;
    protected ?Cart $contextCart = null; // to not interfere with static $cart from PS 1.7
    protected array $args = [];
    protected string $publicKey = '';


    public function __construct()
    {
        parent::__construct();
        $this->setTemplate('module:lunarpayment/views/templates/front/empty.tpl');
                
        $this->paymentMethod = $this->module->getPaymentMethodByName(Tools::getValue('lunar_method'));
        if (!$this->paymentMethod) {
            $this->redirectBackWithNotification('Payment method not loaded');
        }
    }

    /**
     * 
     */
    public function init()
    {
        parent::init();

        $this->contextCart = $this->context->cart;

        $this->validate();
        
		$appKey = $this->getConfigValue('APP_KEY');
        $this->publicKey =  $this->getConfigValue('PUBLIC_KEY');
        $this->testMode = (bool) $_COOKIE['lunar_testmode'];

        /** API Client instance */
        $this->lunarApiClient = new ApiClient($appKey, null, $this->testMode);
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
                true === Validate::isLoadedObject($this->contextCart)
                && true === Validate::isUnsignedInt($this->contextCart->id_customer)
                && true === Validate::isUnsignedInt($this->contextCart->id_address_delivery)
                && true === Validate::isUnsignedInt($this->contextCart->id_address_invoice)
                && false === $this->contextCart->isVirtualCart()
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
