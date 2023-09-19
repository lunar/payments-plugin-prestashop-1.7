<?php
/**
 *
 * @author    Lunar <support@lunar.app>
 * @copyright Copyright (c) permanent, Lunar
 * @license   Addons PrestaShop license limitation
 * @link      https://lunar.app
 *
 */

if ( ! defined( '_PS_VERSION_' ) ) {
	exit;
}

require_once __DIR__.'/vendor/autoload.php';

use Lunar\Exception\ApiException;
use Lunar\Lunar as ApiClient;
use Lunar\Payment\methods\LunarCardsMethod;
use Lunar\Payment\methods\LunarMobilePayMethod;

/**
 * 
 */
class LunarPayment extends PaymentModule 
{
	private LunarCardsMethod $cardsMethod;
	private LunarMobilePayMethod $mobilePayMethod;

	/**
	 * 
	 */
	public function __construct() {
		$this->name      = 'lunarpayment';
		$this->tab       = 'payments_gateways';
		$this->version   = json_decode(file_get_contents(__DIR__ . '/composer.json'))->version;
		$this->author    = 'Lunar';
		$this->bootstrap = true;
		$this->ps_versions_compliancy = ['min' => '1.7', 'max' => _PS_VERSION_];

		$this->currencies      = true;
		$this->currencies_mode = 'checkbox';

		$this->displayName      = 'Lunar';
		$this->description      = $this->l( 'Receive payments with Lunar' );
		$this->confirmUninstall = $this->l( 'Are you sure about removing Lunar?' );

		parent::__construct();

		$this->cardsMethod = new LunarCardsMethod($this);
		$this->mobilePayMethod = new LunarMobilePayMethod($this);
	}

	/**
	 * 
	 */
	public function install() 
	{
		return ( 
			parent::install()
			&& $this->registerHook( 'payment' )
			&& $this->registerHook( 'paymentOptions' )
			&& (version_compare(_PS_VERSION_, '8', '<') ? $this->registerHook( 'paymentReturn' ) : $this->registerHook( 'displayPaymentReturn' ))
			&& $this->registerHook( 'DisplayAdminOrder' )
			&& (version_compare(_PS_VERSION_, '8', '<') ? $this->registerHook( 'BackOfficeHeader' ) : $this->registerHook( 'displayBackOfficeHeader' ))
			&& $this->registerHook( 'actionOrderStatusPostUpdate' )
			&& $this->registerHook( 'actionOrderSlipAdd' )
			&& $this->createDbTables()
			&& $this->cardsMethod->install()
			&& $this->mobilePayMethod->install()
		);
	}

	public function createDbTables() {
		return (
			Db::getInstance()->execute( 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . "lunar_transactions` (
                `id`				INT(11) NOT NULL AUTO_INCREMENT,
                `lunar_tid`			VARCHAR(255) NOT NULL,
                `order_id`			INT(11) NOT NULL,
                `payed_at`			DATETIME NOT NULL,
                `payed_amount`		DECIMAL(20,6) NOT NULL,
                `refunded_amount`	DECIMAL(20,6) NOT NULL,
                `captured`		    VARCHAR(255) NOT NULL,
                PRIMARY KEY			(`id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=latin1 AUTO_INCREMENT=1 ;" )

			&& $this->cardsMethod->createSeedLogosTable()
		);
	}

	public function uninstall()
	{
		return (
			parent::uninstall()
			&& Db::getInstance()->execute( 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . "lunar_transactions`" )
			&& Db::getInstance()->execute( 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . "lunar_logos`" )
			&& $this->cardsMethod->uninstall()
			&& $this->mobilePayMethod->uninstall()
		);
	}

	public function getPSV() {
		return Tools::substr( _PS_VERSION_, 0, 5 );
	}


	/**
	 * 
	 */
	public function getContent() {
		if ( Tools::isSubmit( 'submitLunar' ) ) {
			if (
				$this->cardsMethod->updateConfiguration()
				&& $this->mobilePayMethod->updateConfiguration()
			) {
				$this->context->controller->confirmations[] = $this->l( 'Settings were saved successfully' );
			}
		}
	
		// $this->context->controller->addJS( $this->_path . 'views/js/backoffice.js' );
		
		//Get configuration form
		// return $this->renderForm(); // . $this->getModalForAddMoreLogo(); // disabled for the moment because of an error
		return $this->renderForm() . $this->renderScript();
	}

	public function renderForm()
	{
		$cards_fields_form = $this->cardsMethod->getFormFields();
		$mobilepay_fields_form = $this->mobilePayMethod->getFormFields();

		// we want only inputs to be merged
		$form_fields['form']['legend'] = $cards_fields_form['form']['legend'];
		$form_fields['form']['tabs'] = [
			'lunar_cards' => $this->l('Cards Configuration'),
			'lunar_mobilepay' => $this->l('Mobile Pay Configuration'),
		];
		$form_fields['form']['input'] = array_merge_recursive($cards_fields_form['form']['input'], $mobilepay_fields_form['form']['input']);
		$form_fields['form']['submit'] = $cards_fields_form['form']['submit'];


		$lang                              = new Language( (int) Configuration::get( 'PS_LANG_DEFAULT' ) );
		$helper                            = new HelperForm();
		$helper->default_form_language     = $lang->id;
		$helper->allow_employee_form_lang  = Configuration::get( 'PS_BO_ALLOW_EMPLOYEE_FORM_LANG' ) ?? 0;
		$helper->show_toolbar              = false;
		$helper->table                     = $this->table;
		$helper->identifier    			   = $this->identifier;
		$helper->token         			   = Tools::getAdminTokenLite( 'AdminModules' );
		$helper->submit_action 			   = 'submitLunar';
		$helper->currentIndex  			   = $this->context->link->getAdminLink( 'AdminModules', false ) 
												. '&configure=lunarpayment&tab_module=' 
												. $this->tab . '&module_name=lunarpayment';
		$helper->tpl_vars      			   = [
			'fields_value' => $this->getConfigFieldsValues(),
			// 'languages'    => $this->context->controller->getLanguages(),
			'id_language'  => $this->context->language->id
		];


		$errors = $this->context->controller->errors;
		foreach ( $form_fields['form']['input'] as $key => $field ) {
			if ( array_key_exists( $field['name'], $errors ) ) {
				$form_fields['form']['input'][ $key ]['class'] .= ' has-error';
			}
		}

		return $helper->generateForm([$form_fields]);
	}

	/**
	 * 
	 */
	public function getConfigFieldsValues() {
		$lunarCardsConfigValues = $this->cardsMethod->getConfiguration();
		$lunarMobilePayConfigValues = $this->mobilePayMethod->getConfiguration();

		return array_merge(
			$lunarCardsConfigValues,
			$lunarMobilePayConfigValues
		);
	}

	/**
     * @param $params
     *
     * @return array
     *
     * @throws Exception
     * @throws SmartyException
	 */
	public function hookPaymentOptions( $params )
	{
		if (!$this->active) {
            return;
        }

		// $products       = $params['cart']->getProducts();
		// $products_array = [];
		// $products_label = [];
		// $p              = 0;
		// foreach ( $products as $product ) {
		// 	$products_array[]     = array(
		// 		$this->l( 'ID' )       => $product['id_product'],
		// 		$this->l( 'Name' )     => $product['name'],
		// 		$this->l( 'Quantity' ) => $product['cart_quantity']
		// 	);
		// 	$products_label[ $p ] = $product['quantity'] . 'x ' . $product['name'];
		// 	$p ++;
		// }

		// $base_uri			= __PS_BASE_URI__;
		// $redirect_url = $this->context->link->getModuleLink( $this->name, 'paymentreturn', [], true, (int) $this->context->language->id );

		// $amount = $params['cart']->getOrderTotal();
		// $currency            = new Currency( (int) $params['cart']->id_currency );
		// $currency_code       = $currency->iso_code;
		// $customer            = new Customer( (int) $params['cart']->id_customer );
		// $name                = $customer->firstname . ' ' . $customer->lastname;
		// $email               = $customer->email;
		// $customer_address    = new Address( (int) ( $params['cart']->id_address_delivery ) );
		// $telephone           = $customer_address->phone ?? $customer_address->phone_mobile ?? '';
		// $address             = $customer_address->address1 . ', ' 
		// 						. $customer_address->address2 . ', ' 
		// 						. $customer_address->city . ', ' 
		// 						. $customer_address->country . ' - ' 
		// 						. $customer_address->postcode;

		// return [
		// 	'currency_code'			=> $currency_code,
		// 	'products'				=> str_replace("\u0022","\\\\\"",json_encode(  $products_array ,JSON_HEX_QUOT)),
		// 	'name'					=> $name,
		// 	'email'					=> $email,
		// 	'telephone'				=> $telephone,
		// 	'address'				=> $address,
		// 	'ip'					=> Tools::getRemoteAddr(),
		// 	'locale'				=> $this->context->language->iso_code,
		// 	'platform_version'		=> _PS_VERSION_,
		// 	'platform'				=> [
		// 		'name' => 'Prestashop',
		// 		'version' => _PS_VERSION_,
		// 	],
		// 	'lunarPluginVersion'		=> $this->version,
		// ];
		
		if (
			('disabled' == Configuration::get( $this->cardsMethod->METHOD_STATUS)
				&& 'disabled' == Configuration::get( $this->mobilePayMethod->METHOD_STATUS))
			||
			(!$this->cardsMethod->isConfigured() 
				&& !$this->mobilePayMethod->isConfigured())
		) {
			return;
		}

		$payment_options = [];
		$frontendVars = [
			'module_path' => $this->_path,
		];

		if (
			'enabled' == Configuration::get( $this->cardsMethod->METHOD_STATUS)
			&& $this->cardsMethod->isConfigured()
		) {
			$frontendVars = array_merge($frontendVars, [
				'lunar_cards_shop_title' => Configuration::get($this->cardsMethod->SHOP_TITLE),
				'lunar_cards_title' => Configuration::get($this->cardsMethod->METHOD_TITLE),
				'lunar_cards_desc' => Configuration::get($this->cardsMethod->METHOD_DESCRIPTION),
				'accepted_cards' => explode( ',', Configuration::get( $this->cardsMethod->ACCEPTED_CARDS ) ),
			]);
			$payment_options[] = $this->cardsMethod->getPaymentOption();
		}


		if (
			'enabled' == Configuration::get( $this->mobilePayMethod->METHOD_STATUS)
			&& $this->mobilePayMethod->isConfigured()
		) {
			$frontendVars = array_merge($frontendVars, [
				'lunar_mobilepay_shop_title' => Configuration::get($this->mobilePayMethod->SHOP_TITLE),
				'lunar_mobilepay_title'	=> Configuration::get($this->mobilePayMethod->METHOD_TITLE),
				'lunar_mobilepay_desc' => Configuration::get($this->mobilePayMethod->METHOD_DESCRIPTION),
			]);
			$payment_options[] = $this->mobilePayMethod->getPaymentOption();
		}

		$this->context->smarty->assign($frontendVars);

		return $payment_options;
	}

	/** PS 8 compatibility */
	public function hookDisplayPaymentReturn( $params ) {
		return $this->paymentReturn($params);
	}

	/** PS 1.7 compatibility */
	public function hookPaymentReturn( $params ) {
		return $this->paymentReturn($params);
	}

	/**
	 * 
	 */
	private function paymentReturn( $params ) {
		if ( ! $this->active || ! isset( $params['objOrder'] ) || $params['objOrder']->module != $this->name ) {
			return false;
		}

		if ( isset( $params['objOrder'] ) && Validate::isLoadedObject( $params['objOrder'] ) && isset( $params['objOrder']->valid ) && isset( $params['objOrder']->reference ) ) {
			$this->smarty->assign(
				"lunar_order",
				array(
					'id'        => $params['objOrder']->id,
					'reference' => $params['objOrder']->reference,
					'valid'     => $params['objOrder']->valid
				)
			);

			return $this->display( __FILE__, 'views/templates/hook/payment-return.tpl' );
		}
	}

	public function storeTransactionID( $lunar_txn_id, $order_id, $total, $captured = 'NO' ) {
		$query = 'INSERT INTO ' . _DB_PREFIX_ . 'lunar_transactions (`'
					. 'lunar_tid`, `order_id`, `payed_amount`, `payed_at`, `captured`) VALUES ("'
					. pSQL( $lunar_txn_id ) . '", "' . pSQL( $order_id ) . '", "' . pSQL( $total ) . '" , NOW(), "' . pSQL( $captured ) . '")';

		return Db::getInstance()->execute( $query );
	}

	public function updateTransactionID( $lunar_txn_id, $order_id, $fields = [] ) {
		if ( $lunar_txn_id && $order_id && ! empty( $fields ) ) {
			$fieldsStr  = '';
			$fieldCount = count( $fields );
			$counter    = 0;

			foreach ( $fields as $field => $value ) {
				$counter ++;
				$fieldsStr .= '`' . pSQL( $field ). '` = "' . pSQL( $value ) . '"';

				if ( $counter < $fieldCount ) {
					$fieldsStr .= ', ';
				}
			}

			$query = 'UPDATE ' . _DB_PREFIX_ . 'lunar_transactions SET ' . $fieldsStr
						. ' WHERE `' . 'lunar_tid`="' . pSQL( $lunar_txn_id )
						. '" AND `order_id`="' . (int)$order_id . '"';

			return Db::getInstance()->execute( $query );
		} else {
			return false;
		}
	}

	public function hookDisplayAdminOrder( $params ) {
		$id_order = $params['id_order'];
		$order    = new Order( (int) $id_order );

		if ( $order->module == $this->name ) {
			$order_token = Tools::getAdminToken( 'AdminOrders' . (int)  Tab::getIdFromClassName('AdminOrders') . (int) $this->context->employee->id );
			$dbModuleTransaction = Db::getInstance()->getRow( 'SELECT * FROM ' . _DB_PREFIX_ . 'lunar_transactions WHERE order_id = ' . (int) $id_order );
			$this->context->smarty->assign( array(
				'ps_version'         			  => _PS_VERSION_,
				'id_order'           			  => $id_order,
				'order_token'        			  => $order_token,
				"lunartransaction" 				  => $dbModuleTransaction,
				'not_captured_text'	  			  => $this->l('Captured Transaction prior to Refund via Lunar'),
				'checkbox_text' 	  			  => $this->l('Refund Lunar')
			) );

			return $this->display( __FILE__, 'views/templates/hook/admin-order.tpl' );
		}
	}

	public function displayErrors( $string = 'Fatal error', $htmlentities = true, Context $context = null ) {
		if ( true ) {
			return ( Tools::htmlentitiesUTF8( 'Lunar: ' . stripslashes( $string ) ) . '<br/>' );
		}

		return Context::getContext()->getTranslator()->trans( 'Fatal error', [], 'Admin.Notifications.Error' );
	}

	public function hookActionOrderSlipAdd( $params ){
		/* Check if "Refund" checkbox is checked */
		if (Tools::isSubmit('doRefundLunar')) {
			$id_order = $params['order']->id;
			$amount = 0;

			/* Calculate total amount */
			foreach ($params['productList'] as $product) {
				$amount += floatval($product['amount']);
			}

			/* Add shipping to total */
			if (Tools::getValue('partialRefundShippingCost')) {
				$amount += floatval(Tools::getValue('partialRefundShippingCost'));
			}

			/* For prestashop version > 1.7.7 */
			if  ($refundData = \Tools::getValue('cancel_product')) {
				$shipping_amount = floatval(str_replace(',', '.', $refundData['shipping_amount']));
				if(isset($refundData['shipping']) && $refundData['shipping']==1 && $shipping_amount == 0){
					$shipping_amount = floatval(str_replace(',', '.', $params['order']->total_shipping));
				}
				$amount += $shipping_amount;
			}

			/* Init payment action */
			$response = $this->doPaymentAction($id_order,"refund",false,$amount);
			PrestaShopLogger::addLog( $response['message'] );

			/* Add response to cookies  */
			if(isset($response['error']) && $response['error'] == '1'){
				$this->context->cookie->__set('response_error', $response['message']);
				$this->context->cookie->write();
			}elseif(isset($response['warning']) && $response['warning'] == '1'){
				$this->context->cookie->__set('response_warnings', $response['message']);
				$this->context->cookie->write();
			}else{
				$this->context->cookie->__set('response_confirmations', $response['message']);
				$this->context->cookie->write();
			}
		}
	}

	public function hookActionOrderStatusPostUpdate( $params ) {
		$order_state = $params['newOrderStatus'];
		$id_order    = $params['id_order'];

		/* Skip if no module transaction */
		$dbModuleTransaction = Db::getInstance()->getRow( 'SELECT * FROM ' . _DB_PREFIX_ . 'lunar_transactions WHERE order_id = ' . (int) $id_order );
		if ( empty( $dbModuleTransaction ) ) {
			return false;
		}

		/* If Capture or Cancel */
		if ( $order_state->id == (int) Configuration::get( self::ORDER_STATUS ) || $order_state->id == (int) Configuration::get( 'PS_OS_CANCELED' ) ) {
			/* If custom Captured status  */
			if ( $order_state->id == (int) Configuration::get( self::ORDER_STATUS ) ) {
				$response = $this->doPaymentAction($id_order,"capture");
			}

			/* If Canceled status */
			if ( $order_state->id == (int) Configuration::get( 'PS_OS_CANCELED' ) ) {
				$response = $this->doPaymentAction($id_order,"cancel");
			}

			/* Log response */
			PrestaShopLogger::addLog( $response['message'] );

			/* Add response to cookies  */
			if(isset($response['error']) && $response['error'] == '1'){
				$this->context->cookie->__set('response_error', $response['message']);
				$this->context->cookie->write();
			}elseif(isset($response['warning']) && $response['warning'] == '1'){
				$this->context->cookie->__set('response_warnings', $response['message']);
				$this->context->cookie->write();
			}else{
				$this->context->cookie->__set('response_confirmations', $response['message']);
				$this->context->cookie->write();
			}
		}
	}

	/** PS 8 compatibility */
	public function hookDisplayBackOfficeHeader() {
		$this->backOfficeHeader();
	}

	/** PS 1.7 compatibility */
	public function hookBackOfficeHeader() {
		$this->backOfficeHeader();
	}

	private function backOfficeHeader() {
		if ($this->context->cookie->__isset('response_error')) {
			/** Display persistent */
			$this->context->controller->errors[] = '<p>'.$this->context->cookie->__get('response_error').'</p>';
			/** Clean persistent error */
			$this->context->cookie->__unset('response_error');
		}

		if ($this->context->cookie->__isset('response_warnings')) {
			/** Display persistent */
			$this->context->controller->warnings[] = '<p>'.$this->context->cookie->__get('response_warnings').'</p>';
			/** Clean persistent */
			$this->context->cookie->__unset('response_warnings');
		}

		if ($this->context->cookie->__isset('response_confirmations')) {
			/** Display persistent */
			$this->context->controller->confirmations[] = '<p>'.$this->context->cookie->__get('response_confirmations').'</p>';
			/** Clean persistent */
			$this->context->cookie->__unset('response_confirmations');
		}

		if ( Tools::getIsset( 'vieworder' ) && Tools::getIsset( 'id_order' ) && Tools::getIsset( "lunar_action" ) ) {
			$plugin_action = Tools::getValue( "lunar_action" );
			$id_order = (int) Tools::getValue( 'id_order' );
			$response = $this->doPaymentAction($id_order,$plugin_action,true,Tools::getValue( "lunar_amount_to_refund" ));
			die( json_encode( $response ) );
		}

		if ( Tools::getIsset( 'upload_logo' ) ) {
			$logo_name = Tools::getValue( 'logo_name' );

			if ( empty( $logo_name ) ) {
				$response = array(
					'status'  => 0,
					'message' => 'Please give logo name.'
				);
				die( json_encode( $response ) );
			}

			$logo_slug = Tools::strtolower( str_replace( ' ', '-', $logo_name ) );
			$sql       = new DbQuery();
			$sql->select( '*' );
			$sql->from( "lunar_logos", 'PL' );
			$sql->where( 'PL.slug = "' . pSQL($logo_slug) . '"' );
			$logos = Db::getInstance()->executes( $sql );
			if ( ! empty( $logos ) ) {
				$response = array(
					'status'  => 0,
					'message' => 'This name already exists.'
				);
				die( json_encode( $response ) );
			}

			if ( ! empty( $_FILES['logo_file']['name'] ) ) {
				$target_dir    = _PS_MODULE_DIR_ . $this->name . '/views/img/';
				$name          = basename( $_FILES['logo_file']["name"] );
				$path_parts    = pathinfo( $name );
				$extension     = $path_parts['extension'];
				$file_name     = $logo_slug . '.' . $extension;
				$target_file   = $target_dir . basename( $file_name );
				$imageFileType = pathinfo( $target_file, PATHINFO_EXTENSION );

				/*$check = getimagesize($_FILES['logo_file']["tmp_name"]);
                if($check === false) {
                    $response = array(
                        'status' => 0,
                        'message' => 'File is not an image. Please upload JPG, JPEG, PNG or GIF file.'
                    );
                    die(json_encode($response));
                }*/

				// Check if file already exists
				if ( file_exists( $target_file ) ) {
					$response = array(
						'status'  => 0,
						'message' => 'Sorry, file already exists.'
					);
					die( json_encode( $response ) );
				}

				// Allow certain file formats
				if ( $imageFileType != "jpg" && $imageFileType != "png" && $imageFileType != "jpeg"
				     && $imageFileType != "gif" && $imageFileType != "svg" ) {
					$response = array(
						'status'  => 0,
						'message' => 'Sorry, only JPG, JPEG, PNG, GIF & SVG files are allowed.'
					);
					die( json_encode( $response ) );
				}

				if ( move_uploaded_file( $_FILES['logo_file']["tmp_name"], $target_file ) ) {
					$query = 'INSERT INTO ' . _DB_PREFIX_ . 'lunar_logos (`name`, `slug`, `file_name`, `default_logo`, `created_at`)
								VALUES ("' . pSQL( $logo_name ) . '", "' . pSQL( $logo_slug ) . '", "' . pSQL( $file_name ) . '", 0, NOW())';

					if ( Db::getInstance()->execute( $query ) ) {
						$response = array(
							'status'  => 1,
							'message' => "The file " . basename( $file_name ) . " has been uploaded."
						);
						//Configuration::updateValue(self::ACCEPTED_CARDS, basename($file_name));
						die( json_encode( $response ) );
					} else {
						unlink( $target_file );
						$response = array(
							'status'  => 0,
							'message' => "Oops! An error occured while save logo."
						);
						die( json_encode( $response ) );
					}
				} else {
					$response = array(
						'status'  => 0,
						'message' => 'Sorry, there was an error uploading your file.'
					);
					die( json_encode( $response ) );
				}
			} else {
				$response = array(
					'status'  => 0,
					'message' => 'Please select a file for upload.'
				);
				die( json_encode( $response ) );
			}
		}

		// if ( Tools::getIsset( 'change_language' ) ) {
		// 	$language_code = ( ! empty( Tools::getvalue( 'lang_code' ) ) ) ? Tools::getvalue( 'lang_code' ) : Configuration::get( self::LANGUAGE_CODE );
		// 	Configuration::updateValue( self::LANGUAGE_CODE, $language_code );
		// 	$token = Tools::getAdminToken( 'AdminModules' . (int)  Tab::getIdFromClassName( 'AdminModules' ) . (int) $this->context->employee->id );
		// 	$link  = $this->context->link->getAdminLink( 'AdminModules' ) . '&token=' . $token . '&configure=lunarpayment&tab_module=' . $this->tab . '&module_name=lunarpayment';
		// 	Tools::redirectAdmin( $link );
		// }

		if ( Tools::getValue( 'configure' ) == $this->name ) {
			$this->context->controller->addCSS( $this->_path . 'views/css/backoffice.css' );
		}
	}

	/**
	 * Call action via API.
	 *
	 * @param string $id_order - the order id
	 * @param string $plugin_action - the action to be called.
	 * @param boolean $change_status - change status flag
	 * @param float $plugin_amount_to_refund - the refund amount
	 *
	 * @return mixed
	 */
	 protected function doPaymentAction($id_order, $plugin_action, $change_status = false, $plugin_amount_to_refund = 0){
		$order                 = new Order( (int) $id_order );
		$dbModuleTransaction   = Db::getInstance()->getRow( 'SELECT * FROM ' . _DB_PREFIX_ . "lunar_transactions WHERE order_id = " . (int) $id_order );
		$isTransactionCaptured = $dbModuleTransaction['captured'] == 'YES';
		$transactionId         = $dbModuleTransaction["lunar_tid"];

		$secretKey = Configuration::get( self::TRANSACTION_MODE ) == 'live'
						? Configuration::get( self::LIVE_SECRET_KEY )
						: Configuration::get( self::TEST_SECRET_KEY );

		$apiClient = new ApiClient( $secretKey );

		$fetch    = $apiClient->payments()->fetch( $transactionId );
		$customer = new Customer( $order->id_customer );
		$currency = new Currency( (int) $order->id_currency );

		switch ( $plugin_action ) {
			case "capture":
				if ( $isTransactionCaptured ) {
					$response = array(
						'warning' => 1,
						'message' => $this->displayErrors( $this->l('Transaction already Captured.') ),
					);
				} elseif ( isset( $dbModuleTransaction ) ) {
					$amount   = ( ! empty( $fetch['transaction']['pendingAmount'] ) ) ? (int) $fetch['transaction']['pendingAmount'] : 0;

					if ( $amount ) {
						/* Capture transaction */
						$data    = array(
							'currency'   => $currency->iso_code,
							'amount'     => $amount,
						);

						$capture = $apiClient->payments()->capture( $transactionId, $data );

						if ( is_array( $capture ) && ! empty( $capture['error'] ) && $capture['error'] == 1 ) {
							PrestaShopLogger::addLog( $capture['message'] );
							$response = array(
								'error'   => 1,
								'message' => $this->displayErrors( $capture['message'] ),
							);
						} else {
							if ( ! empty( $capture['transaction'] ) ) {
								//Update order status
								if($change_status){
									$order->setCurrentState( (int) Configuration::get( self::ORDER_STATUS ), $this->context->employee->id );
								}

								/* Update transaction details */
								$fields = array(
									'captured' => 'YES',
								);
								$this->updateTransactionID( $transactionId, (int) $id_order, $fields );

								/* Set message */
								$message = 'Trx ID: ' . $transactionId . '
											Authorized Amount: ' . $capture['transaction']['amount'] . '
											Captured Amount: ' . $capture['transaction']['capturedAmount'] . '
											Order time: ' . $capture['transaction']['created'] . '
											Currency code: ' . $capture['transaction']['currency'];

								$message = strip_tags( $message, '<br>' );
								$this->maybeAddOrderMessage($message, $customer, $order);

								/* Set response */
								$response = array(
									'success' => 1,
									'message' => $this->displayErrors( $this->l('Transaction successfully Captured.') ),
								);
							} else {
								if ( ! empty( $capture[0]['message'] ) ) {
									$response = array(
										'warning' => 1,
										'message' => $this->displayErrors( $capture[0]['message'] ),
									);
								} else {
									$response = array(
										'error'   => 1,
										'message' => $this->displayErrors( $this->l('Oops! An error occurred while Capture.') ),
									);
								}
							}
						}
					} else {
						$response = array(
							'error'   => 1,
							'message' => $this->displayErrors( $this->l('Invalid amount to Capture.') ),
						);
					}
				} else {
					$response = array(
						'error'   => 1,
						'message' => $this->displayErrors( $this->l('Invalid Lunar Transaction.') ),
					);
				}

				break;

			case "refund":
				if ( ! $isTransactionCaptured ) {
					$response = array(
						'warning' => 1,
						'message' => $this->displayErrors( $this->l('You need to Captured Transaction prior to Refund.') ),
					);
				} elseif ( isset( $dbModuleTransaction ) ) {

					if ( ! Validate::isPrice( $plugin_amount_to_refund ) ) {
						$response = array(
							'error'   => 1,
							'message' => $this->displayErrors( $this->l('Invalid amount to Refund.') ),
						);
					} else {
						/* Refund transaction */
						$amount              = $plugin_amount_to_refund;
						$data                = array(
							'descriptor' => '',
							'amount'     => $amount,
						);

						$refund = $apiClient->payments()->refund( $transactionId, $data );

						if ( is_array( $refund ) && ! empty( $refund['error'] ) && $refund['error'] == 1 ) {
							PrestaShopLogger::addLog( $refund['message'] );
							$response = array(
								'error'   => 1,
								'message' => $this->displayErrors( $refund['message'] ),
							);
						} else {
							if ( ! empty( $refund['transaction'] ) ) {
								//Update order status
								if($change_status){
									$order->setCurrentState( (int) Configuration::get( 'PS_OS_REFUND' ), $this->context->employee->id );
								}

								/* Update transaction details */
								$fields = array(
									'refunded_amount' => $dbModuleTransaction['refunded_amount'] + $plugin_amount_to_refund,
								);
								$this->updateTransactionID( $transactionId, (int) $id_order, $fields );

								/* Set message */
								$message = 'Trx ID: ' . $transactionId . '
											Authorized Amount: ' . $refund['transaction']['amount'] . '
											Refunded Amount: ' . $refund['transaction']['refundedAmount'] . '
											Order time: ' . $refund['transaction']['created'] . '
											Currency code: ' . $refund['transaction']['currency'];

								$message = strip_tags( $message, '<br>' );
								$this->maybeAddOrderMessage($message, $customer, $order);

								/* Set response */
								$response = array(
									'success' => 1,
									'message' => $this->displayErrors( $this->l('Transaction successfully Refunded.') ),
								);
							} else {
								if ( ! empty( $refund[0]['message'] ) ) {
									$response = array(
										'warning' => 1,
										'message' => $this->displayErrors( $refund[0]['message'] ),
									);
								} else {
									$response = array(
										'error'   => 1,
										'message' => $this->displayErrors( $this->l('Oops! An error occurred while Refund.') ),
									);
								}
							}
						}
					}
				} else {
					$response = array(
						'error'   => 1,
						'message' => $this->displayErrors( $this->l('Invalid Lunar Transaction.') ),
					);
				}

				break;

			case "cancel":
				if ( $isTransactionCaptured ) {
					$response = array(
						'warning' => 1,
						'message' => $this->displayErrors( $this->l('You can\'t Cancel transaction now . It\'s already Captured, try to Refund.') ),
					);
				} elseif ( isset( $dbModuleTransaction ) ) {

					/* Cancel transaction */
					$amount = (int) $fetch['transaction']['amount'] - $fetch['transaction']['refundedAmount'];
					$data   = array(
						'amount' => $amount,
					);

					$cancel   = $apiClient->payments()->cancel( $transactionId, $data );

					if ( is_array( $cancel ) && ! empty( $cancel['error'] ) && $cancel['error'] == 1 ) {
						PrestaShopLogger::addLog( $cancel['message'] );
						$response = array(
							'error'   => 1,
							'message' => $this->displayErrors( $cancel['message'] ),
						);
					} else {
						if ( ! empty( $cancel['transaction'] ) ) {
							//Update order status
							if($change_status){
								$order->setCurrentState( (int) Configuration::get( 'PS_OS_CANCELED' ), $this->context->employee->id );
							}

							/* Set message */
							$message = 'Trx ID: ' . $transactionId . '
										Authorized Amount: ' . $cancel['transaction']['amount'] . '
										Refunded Amount: ' . $cancel['transaction']['refundedAmount'] . '
										Order time: ' . $cancel['transaction']['created'] . '
										Currency code: ' . $cancel['transaction']['currency'];

							$message = strip_tags( $message, '<br>' );
							$this->maybeAddOrderMessage($message, $customer, $order);

							/* Set response */
							$response = array(
								'success' => 1,
								'message' => $this->displayErrors( $this->l('Transaction successfully Canceled.') ),
							);
						} else {
							if ( ! empty( $cancel[0]['message'] ) ) {
								$response = array(
									'warning' => 1,
									'message' => $this->displayErrors( $cancel[0]['message'] ),
								);
							} else {
								$response = array(
									'error'   => 1,
									'message' => $this->displayErrors( $this->l('Oops! An error occurred while Cancel.') ),
								);
							}
						}
					}
				} else {
					$response = array(
						'error'   => 1,
						'message' => $this->displayErrors( $this->l('Invalid Lunar Transaction.') ),
					);
				}
				break;
		}
		return $response;
	}

	/**
	 * 
	 */
	private function maybeAddOrderMessage(string $message, Customer $customer, Order $order)
	{
		if ( ! Validate::isCleanHtml( $message ) ) {
			return;
		}

		if ( $this->getPSV() == '1.7.2' ) {
			$id_customer_thread = CustomerThread::getIdCustomerThreadByEmailAndIdOrder( $customer->email, $order->id );
			if ( ! $id_customer_thread ) {
				$customer_thread              = new CustomerThread();
				$customer_thread->id_contact  = 0;
				$customer_thread->id_customer = (int) $order->id_customer;
				$customer_thread->id_shop     = (int) $this->context->shop->id;
				$customer_thread->id_order    = (int) $order->id;
				$customer_thread->id_lang     = (int) $this->context->language->id;
				$customer_thread->email       = $customer->email;
				$customer_thread->status      = 'open';
				$customer_thread->token       = Tools::passwdGen( 12 );
				$customer_thread->add();
			} else {
				$customer_thread = new CustomerThread( (int) $id_customer_thread );
			}

			$customer_message                     = new CustomerMessage();
			$customer_message->id_customer_thread = $customer_thread->id;
			$customer_message->id_employee        = 0;
			$customer_message->message            = $message;
			$customer_message->private            = 1;
			$customer_message->add();

		} else {
			$msg              = new Message();
			$msg->message     = $message;
			$msg->id_cart     = (int) $order->id_cart;
			$msg->id_customer = (int) $order->id_customer;
			$msg->id_order    = (int) $order->id;
			$msg->private     = 1;
			$msg->add();
		}
	}

	/**
	 * 
	 */
	public function renderScript() {
		$this->context->smarty->assign([
			'request_uri' => $this->context->link->getAdminLink( 'AdminOrders', false ),
		]);

		return $this->display( __FILE__, 'views/templates/admin/script.tpl' );
	}

	/**
	 * 
	 */
	// public function getModalForAddMoreLogo() {
	// 	$this->context->smarty->assign( array(
	// 		'request_uri' => $this->context->link->getAdminLink( 'AdminOrders', false ),
	// 		'tok'         => Tools::getAdminToken( 'AdminOrders' )
	// 	) );

	// 	return $this->display( __FILE__, 'views/templates/admin/modal.tpl' );
	// }
			
	/**
	 * @TODO make call to this method when activate translations
	 */
	// private function getTranslatedModuleConfig(string $key)
	// {
	// 	$language_code = Configuration::get( self::LANGUAGE_CODE );
	// 	return ( ! empty( Configuration::get( $language_code . '_' . $key ) ) ) 
	// 				? Configuration::get( $language_code . '_' . $key ) 
	// 				: ( ! empty( Configuration::get( 'en_' . $key ) ) 
	// 						? Configuration::get( 'en_' . $key ) 
	// 						: '' );
	// }
}
