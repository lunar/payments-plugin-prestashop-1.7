<?php

namespace Lunar\Payment\controllers\front;

use \Db;
use \Cart;
use \Order;
use \Tools;
use \Module;
use \Address;
use \Currency;
use \Customer;
use \Validate;
use \Configuration;
use \PrestaShopLogger;


use Lunar\Lunar as ApiClient;
use Lunar\Exception\ApiException;
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

    /** 
     * @var LunarCardMethod|LunarMobilePayMethod|null $method 
     */
    protected $paymentMethod = null;
    
    private ApiClient $lunarApiClient;

    public $errors = [];
    private string $intentIdKey = '_lunar_intent_id';
    private bool $testMode = false;
    private bool $isInstantMode = false;
    private ?Order $order = null;
    private ?Cart $cart = null;
    private array $args = [];
    private string $publicKey = '';


    public function __construct(string $methodName)
    {
        parent::__construct();
        $this->setTemplate('module:lunarpayment/views/templates/front/empty.tpl');
                
        $this->setPaymentMethod($methodName);

        if (!$this->paymentMethod) {
            $this->redirectBackWithNotification('Payment method not loaded');
        }
    }
    
    /**
     * @return void
     */
    private function setPaymentMethod($methodName)
    {
        switch($methodName) {
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
     * @see FrontController::postProcess()
     */
    public function postProcess()
    {

        // @TODO move bellow code to another controller (paymentreturn)
        // also move the following methods
        // parseApiTransactionResponse()
        // isTransactionSuccessful()
        // getResponseError()

        if (Tools::getValue('cart_id')) {

            $this->isInstantMode = ('instant' == $this->getConfigValue('CHECKOUT_MODE'));

            $this->cart = new \Cart(Tools::getValue('cart_id'));

            $customer  = new Customer((int) $this->cart->id_customer);

            $paymentIntentId = $this->getPaymentIntentFromCart();

            if (!$paymentIntentId) {
                
            }

            $orderIsValid = $this->module->validateOrder(
                $this->cart->id, 
                $this->getConfigValue('ORDER_STATUS'), 
                $this->cart->getOrderTotal(), 
                $this->module->displayName . ' (' . ucfirst($this->paymentMethod->METHOD_NAME) . ')', 
                NULL,
                [
                    'transaction_id' => $paymentIntentId
                ], 
                (int) $this->cart->id_currency, 
                false, 
                $customer->secure_key
            );

            $orderId = (int) Order::getIdByCartId((int) $this->cart->id );


            if ($orderIsValid) {
                // @TODO save transaction in lunar_transactions table
            }

            Tools::redirect($this->context->link->getPageLink('order-confirmation', true,
                (int) $this->context->language->id,
                [
                    'id_cart' => (int) $this->cart->id,
                    'id_module' => (int) $this->module->id,
                    'id_order' =>  $orderId,
                    'key' => $customer->secure_key,
                ]
            ));
        } 


        $this->validate();
                
        $this->setArgs();

        $paymentIntentId = $this->getPaymentIntentFromCart();

        if (! $paymentIntentId) {
            try {
                $paymentIntentId = $this->lunarApiClient->payments()->create($this->args);
            } catch(ApiException $e) {
                $this->redirectBackWithNotification($e->getMessage());
            }
        }

        if (! $paymentIntentId) {
            $this->redirectBackWithNotification('An error occurred creating payment for order. Please try again or contact system administrator.'); // <a href="/">Go to homepage</a>'
        }

        $this->savePaymentIntentOnCart($paymentIntentId);

        /** @see ControllerCore $redirect_after */
        $this->redirect_after = isset($this->args['test'])
                                ? self::TEST_REMOTE_URL . $paymentIntentId
                                : self::REMOTE_URL . $paymentIntentId;
    }


    /**
     * SET ARGS
     */
    private function setArgs()
    {
        if ($this->testMode) {
            $this->args['test'] = $this->getTestObject();
        }

        $customer  = new Customer( (int) $this->cart->id_customer );
        $name      = $customer->firstname . ' ' . $customer->lastname;
        $email     = $customer->email;
        $address   = new Address( (int) ( $this->cart->id_address_delivery ) );
        $telephone = $address->phone ?? $address->phone_mobile ?? '';
        $address   = $address->address1 . ', ' . $address->address2 . ', ' . $address->city 
                    . ', ' . $address->country . ' - ' . $address->postcode;

        $this->args['amount'] = [
            'currency' => $this->context->currency->iso_code,
            'decimal' => (string) $this->cart->getOrderTotal(),
        ];

        $this->args['custom'] = [
			'products' => $this->getFormattedProducts(),
            'customer' => [
                'name' => $name,
                'email' => $email,
                'telephone' => $telephone,
                'address' => $address,
                'ip' => Tools::getRemoteAddr(),
            ],
			'platform' => [
				'name' => 'Prestashop',
				'version' => _PS_VERSION_,
			],
			'lunarPluginVersion' => $this->module->version,
        ];

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

        $this->args['redirectUrl'] = $this->getCurrentURL() . '&cart_id=' . $this->cart->id;
        $this->args['preferredPaymentMethod'] = $this->paymentMethod->METHOD_NAME;
    }

    /**
     * 
     */
    private function getPaymentIntentFromCart()
    {
        return $this->getCartCheckoutSessionData()[$this->intentIdKey] ?? '';
    }

    /**
     * 
     */
    private function savePaymentIntentOnCart($paymentIntentId)
    {
        $cartCheckoutSessionData =  $this->getCartCheckoutSessionData();

        $cartCheckoutSessionData = array_merge(
            [$this->intentIdKey => $paymentIntentId], 
            $cartCheckoutSessionData
        );

        return Db::getInstance()->execute(
            'UPDATE ' . _DB_PREFIX_ . 'cart SET checkout_session_data = "' 
                . pSQL(json_encode($cartCheckoutSessionData)) 
                . '" WHERE id_cart = ' . (int) $this->cart->id
        );
    }

    /**
     * 
     */
    private function getCartCheckoutSessionData()
    {
        $rawData = Db::getInstance()->getValue(
            'SELECT checkout_session_data FROM ' . _DB_PREFIX_ . 'cart WHERE id_cart = ' . (int) $this->cart->id
        );
        $cartCheckoutSessionData = json_decode($rawData ?? '', true);
        if (!is_array($cartCheckoutSessionData)) {
            $cartCheckoutSessionData = [];
        }
        
        return $cartCheckoutSessionData;
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
    private function redirectBackWithNotification(string $errorMessage)
    {
        // $this->errors['error_code'] = 'Lunar error';
        // $this->errors['msg_long'] = $errorMessage;
        $this->errors['error_msg'] = $this->errorMessage($errorMessage);
        $this->redirectWithNotifications('index.php?controller=order');
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
                    "currency" => $this->context->currency->iso_code,
                    
                ],
                "balance" => [
                    "decimal"  => "25000.99",
                    "currency" => $this->context->currency->iso_code,
                    
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
     * @return mixed
     */
    private function getConfigValue($configKey)
    {
        if (isset($this->paymentMethod->{$configKey})) {
            return Configuration::get($this->paymentMethod->{$configKey});
        }

        return null;
    }

    /**
     * 
     */
    private function getFormattedProducts()
    {
		$products_array = [];

        $products = $this->context->cart->getProducts();
		
        foreach ( $products as $product ) {
			$products_array[] = [
				$this->t( 'ID' ) => $product['id_product'],
				$this->t( 'Name' ) => $product['name'],
				$this->t( 'Quantity' ) => $product['cart_quantity']
            ];
		}

        return str_replace("\u0022","\\\\\"", json_encode($products_array, JSON_HEX_QUOT));
    }

    /**
     * 
     */
    private function errorMessage($string)
    {
        return $this->t($string);
    }

    /**
     * 
     */
    private function t($string)
    {
        return $this->module->l($string);
    }
}
