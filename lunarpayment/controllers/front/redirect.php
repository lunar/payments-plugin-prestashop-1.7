<?php

use Lunar\Exception\ApiException;
use Lunar\Payment\controllers\front\AbstractLunarFrontController;


/**
 * 
 */
class LunarpaymentRedirectModuleFrontController extends AbstractLunarFrontController
{
    /**
     * @see FrontController::postProcess()
     */
    public function postProcess()
    {               
        $this->setArgs();

        $paymentIntentId = $this->getPaymentIntentCookie();

        if (! $paymentIntentId) {
            try {
                $paymentIntentId = $this->lunarApiClient->payments()->create($this->args);
                $this->savePaymentIntentCookie($paymentIntentId);
            } catch(ApiException $e) {
                $this->redirectBackWithNotification($e->getMessage());
            }
        }

        if (! $paymentIntentId) {
            $this->redirectBackWithNotification('An error occurred creating payment intent. Please try again or contact system administrator.');
        }

        /** @see ControllerCore $redirect_after */
        $this->redirect_after = ($this->testMode ? self::TEST_REMOTE_URL : self::REMOTE_URL) . $paymentIntentId;
    }

    
    /**
     * SET ARGS
     */
    private function setArgs()
    {
        $customer  = new Customer( (int) $this->contextCart->id_customer );
        $name      = $customer->firstname . ' ' . $customer->lastname;
        $email     = $customer->email;
        $address   = new Address( (int) ( $this->contextCart->id_address_delivery ) );
        $telephone = $address->phone ?? $address->phone_mobile ?? '';
        $address   = $address->address1 . ', ' . $address->address2 . ', ' . $address->city 
                    . ', ' . $address->country . ' - ' . $address->postcode;

        $this->args = [
            'integration' => [
                'key' => $this->publicKey,
                'name' => $this->getConfigValue('SHOP_TITLE') ?? Configuration::get('PS_SHOP_NAME'),
                'logo' => $this->getConfigValue('LOGO_URL'),
            ],
            'amount' => [
                'currency' => $this->context->currency->iso_code,
                'decimal' => (string) $this->contextCart->getOrderTotal(),
            ],
            'custom' => [
                // 'orderId' => '', // the order is not created at this point
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
            ],
            'redirectUrl' => $this->context->link->getModuleLink(
                $this->module->name,
                'paymentreturn',
                ['lunar_method' => $this->paymentMethod->METHOD_NAME],
                true,
                (int) $this->context->language->id
            ),
            'preferredPaymentMethod' => $this->paymentMethod->METHOD_NAME,
        ];

        if ($this->getConfigValue('CONFIGURATION_ID')) {
            $this->args['mobilePayConfiguration'] = [
                'configurationID' => $this->getConfigValue('CONFIGURATION_ID'),
                'logo' => $this->getConfigValue('LOGO_URL'),
            ];
        }

        if ($this->testMode) {
            $this->args['test'] = $this->getTestObject();
        }
    }

    /**
     * 
     */
    private function getFormattedProducts()
    {
		$products_array = [];

        $products = $this->contextCart->getProducts();
		
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
}