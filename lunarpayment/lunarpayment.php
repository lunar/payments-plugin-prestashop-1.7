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

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

use Lunar\Exception\ApiException;
use Lunar\Lunar as ApiClient;

/**
 * 
 */
class LunarPayment extends PaymentModule 
{
	const PAYMENT_METHOD_STATUS = 'LUNAR_PAYMENT_METHOD_STATUS';
	const TRANSACTION_MODE = 'LUNAR_TRANSACTION_MODE';
	const LIVE_PUBLIC_KEY = 'LUNAR_LIVE_PUBLIC_KEY';
	const LIVE_SECRET_KEY = 'LUNAR_LIVE_SECRET_KEY';
	const TEST_PUBLIC_KEY = 'LUNAR_TEST_PUBLIC_KEY';
	const TEST_SECRET_KEY = 'LUNAR_TEST_SECRET_KEY';
	const LOGO_URL = 'LUNAR_LOGO_URL';
	const CHECKOUT_MODE = 'LUNAR_CHECKOUT_MODE';
	const ORDER_STATUS = 'LUNAR_ORDER_STATUS';
	const LANGUAGE_CODE = 'LUNAR_LANGUAGE_CODE';
	const PAYMENT_METHOD_DESC = 'LUNAR_PAYMENT_METHOD_DESC';
	const PAYMENT_METHOD_TITLE = 'LUNAR_PAYMENT_METHOD_TITLE';
	const SHOP_TITLE = 'LUNAR_SHOP_TITLE';
	const ACCEPTED_CARDS = 'LUNAR_ACCEPTED_CARDS';

	private $validationPublicKeys = ['live' => [], 'test' => []];

	public function __construct() {
		$this->name      = 'lunarpayment';
		$this->tab       = 'payments_gateways';
		$this->version   = json_decode(file_get_contents(__DIR__ . '/composer.json'))->version;
		$this->author    = 'Lunar';
		$this->bootstrap = true;

		$this->currencies      = true;
		$this->currencies_mode = 'checkbox';

		$this->displayName      = 'Lunar';
		$this->description      = $this->l( 'Receive payments with Lunar' );
		$this->confirmUninstall = $this->l( 'Are you sure about removing Lunar?' );

		parent::__construct();
	}

	public function install() {
		$shop_title   = ( ! empty( Configuration::get( 'PS_SHOP_NAME' ) ) ) ? Configuration::get( 'PS_SHOP_NAME' ) : 'Payment';
		$language_code = $this->context->language->iso_code;

		Configuration::updateValue( self::LANGUAGE_CODE, $language_code );
		Configuration::updateValue( self::PAYMENT_METHOD_STATUS, 'enabled' );
		Configuration::updateValue( self::TRANSACTION_MODE, 'live' ); // defaults to live mode
		Configuration::updateValue( self::TEST_SECRET_KEY, '' );
		Configuration::updateValue( self::TEST_PUBLIC_KEY, '' );
		Configuration::updateValue( self::LIVE_SECRET_KEY, '' );
		Configuration::updateValue( self::LIVE_PUBLIC_KEY, '' );
		Configuration::updateValue( self::LOGO_URL, '' );
		Configuration::updateValue( self::CHECKOUT_MODE, 'delayed' );
		Configuration::updateValue( self::ORDER_STATUS, Configuration::get( self::ORDER_STATUS ) );
		Configuration::updateValue( $language_code . '_' . self::PAYMENT_METHOD_TITLE, 'Card' );
		Configuration::updateValue( $language_code . '_' . self::PAYMENT_METHOD_DESC, 'Secure payment with card via © Lunar' );
		Configuration::updateValue( $language_code . '_' . self::SHOP_TITLE, $shop_title );
		Configuration::updateValue( self::ACCEPTED_CARDS, 'visa.svg,visa-electron.svg,mastercard.svg,mastercard-maestro.svg' );

		return ( parent::install()
					&& $this->registerHook( 'payment' )
					&& $this->registerHook( 'paymentOptions' )
					&& (version_compare(_PS_VERSION_, '8', '<') ? $this->registerHook( 'paymentReturn' ) : $this->registerHook( 'displayPaymentReturn' ))
					&& $this->registerHook( 'DisplayAdminOrder' )
					&& (version_compare(_PS_VERSION_, '8', '<') ? $this->registerHook( 'BackOfficeHeader' ) : $this->registerHook( 'displayBackOfficeHeader' ))
					&& $this->registerHook( 'actionOrderStatusPostUpdate' )
					&& $this->registerHook( 'actionOrderSlipAdd' )
					&& $this->installDb()
				);
	}

	public function installDb() {
		return (
			Db::getInstance()->Execute( 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . "lunar_transactions` (
                `id`				INT(11) NOT NULL AUTO_INCREMENT,
                `lunar_tid`			VARCHAR(255) NOT NULL,
                `order_id`			INT(11) NOT NULL,
                `payed_at`			DATETIME NOT NULL,
                `payed_amount`		DECIMAL(20,6) NOT NULL,
                `refunded_amount`	DECIMAL(20,6) NOT NULL,
                `captured`		    VARCHAR(255) NOT NULL,
                PRIMARY KEY			(`id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=latin1 AUTO_INCREMENT=1 ;" )

			&& Db::getInstance()->Execute( 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . "lunar_logos` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `name` VARCHAR(255) NOT NULL,
                `slug` VARCHAR(255) NOT NULL,
                `file_name` VARCHAR(255) NOT NULL,
                `default_logo` INT(11) NOT NULL DEFAULT 1 COMMENT '1=Default',
                `created_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`)
                ) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8;" )

			&& Db::getInstance()->insert(
				"lunar_logos",
				array(
					array(
						'id'         => 1,
						'name'       => pSQL( 'VISA' ),
						'slug'       => pSQL( 'visa' ),
						'file_name'  => pSQL( 'visa.svg' ),
						'created_at' => date( 'Y-m-d H:i:s' ),
					),
					array(
						'id'         => 2,
						'name'       => pSQL( 'VISA Electron' ),
						'slug'       => pSQL( 'visa-electron' ),
						'file_name'  => pSQL( 'visa-electron.svg' ),
						'created_at' => date( 'Y-m-d H:i:s' ),
					),
					array(
						'id'         => 3,
						'name'       => pSQL( 'Mastercard' ),
						'slug'       => pSQL( 'mastercard' ),
						'file_name'  => pSQL( 'mastercard.svg' ),
						'created_at' => date( 'Y-m-d H:i:s' ),
					),
					array(
						'id'         => 4,
						'name'       => pSQL( 'Mastercard Maestro' ),
						'slug'       => pSQL( 'mastercard-maestro' ),
						'file_name'  => pSQL( 'mastercard-maestro.svg' ),
						'created_at' => date( 'Y-m-d H:i:s' ),
					),
				)
			)
		);
	}

	public function uninstall() {
		$sql = new DbQuery();
		$sql->select( '*' );
		$sql->from( "lunar_logos", 'PL' );
		$sql->where( 'PL.default_logo != 1' );
		$logos = Db::getInstance()->executes( $sql );

		foreach ( $logos as $logo ) {
			if ( file_exists( _PS_MODULE_DIR_ . $this->name . '/views/img/' . $logo['file_name'] ) ) {
				unlink( _PS_MODULE_DIR_ . $this->name . '/views/img/' . $logo['file_name'] );
			}
		}

		//Fetch all languages and delete plugin configurations which has language iso_code as prefix
		$languages = Language::getLanguages( true, $this->context->shop->id );
		foreach ( $languages as $language ) {
			$language_code = $language['iso_code'];
			Configuration::deleteByName( $language_code . '_' . self::PAYMENT_METHOD_TITLE );
			Configuration::deleteByName( $language_code . '_' . self::PAYMENT_METHOD_DESC );
			Configuration::deleteByName( $language_code . '_' . self::SHOP_TITLE );
		}

		return (
			parent::uninstall()
			&& Db::getInstance()->Execute( 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . "lunar_transactions`" )
			&& Db::getInstance()->Execute( 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . "lunar_logos`" )
			&& Configuration::deleteByName( self::PAYMENT_METHOD_STATUS )
			&& Configuration::deleteByName( self::TRANSACTION_MODE )
			&& Configuration::deleteByName( self::TEST_SECRET_KEY )
			&& Configuration::deleteByName( self::TEST_PUBLIC_KEY )
			&& Configuration::deleteByName( self::LIVE_SECRET_KEY )
			&& Configuration::deleteByName( self::LIVE_PUBLIC_KEY )
			&& Configuration::deleteByName( self::LOGO_URL )
			&& Configuration::deleteByName( self::CHECKOUT_MODE )
			&& Configuration::deleteByName( self::ORDER_STATUS )
			&& Configuration::deleteByName( self::PAYMENT_METHOD_TITLE )
			&& Configuration::deleteByName( self::PAYMENT_METHOD_DESC )
			&& Configuration::deleteByName( self::SHOP_TITLE )
			&& Configuration::deleteByName( self::ACCEPTED_CARDS )
		);
	}

	public function getPSV() {
		return Tools::substr( _PS_VERSION_, 0, 5 );
	}

	public function getContent() {
		if ( Tools::isSubmit( 'submitLunar' ) ) {
			$language_code = Configuration::get( self::LANGUAGE_CODE );
			$valid         = true;

			$payment_method_title = Tools::getvalue( $language_code . '_' . self::PAYMENT_METHOD_TITLE ) ?? '';
			$payment_method_desc  = Tools::getvalue( $language_code . '_' . self::PAYMENT_METHOD_DESC ) ?? '';
			$shop_title = Tools::getvalue( $language_code . '_' . self::SHOP_TITLE ) ?? '';

			if ( empty( $payment_method_title ) ) {
				$this->context->controller->errors[ $language_code . '_' . self::PAYMENT_METHOD_TITLE ] = $this->l( 'Payment method title required!' );
				$payment_method_title = $this->getTranslatedModuleConfig(self::PAYMENT_METHOD_TITLE);
				$valid = false;
			}

			if ( count( Tools::getvalue( self::ACCEPTED_CARDS ) ) > 1 ) {
				$acceptedCards = implode( ',', Tools::getvalue( self::ACCEPTED_CARDS ) );
			} else {
				$acceptedCards = Tools::getvalue( self::ACCEPTED_CARDS );
			}

			$transactionMode = Tools::getvalue( self::TRANSACTION_MODE );

			// @TODO remove these 4 lines and activate validation
			$test_secret_key = Tools::getvalue( self::TEST_SECRET_KEY ) ?? '';
			$test_public_key = Tools::getvalue( self::TEST_PUBLIC_KEY ) ?? '';
			$live_secret_key = Tools::getvalue( self::LIVE_SECRET_KEY ) ?? '';
			$live_public_key = Tools::getvalue( self::LIVE_PUBLIC_KEY ) ?? '';


			// if ('test' == $transactionMode) {
			// 	/** Load db value or set it to empty **/
			// 	$test_secret_key = Configuration::get( self::TEST_SECRET_KEY ) ?? '';
			// 	$validationPublicKeyMessage = $this->validateAppKeyField(Tools::getvalue( self::TEST_SECRET_KEY ), 'test');
			// 	if($validationPublicKeyMessage){
			// 		$this->context->controller->errors[self::TEST_SECRET_KEY] = $validationPublicKeyMessage;
			// 		$valid = false;
			// 	} else{
			// 		$test_secret_key = Tools::getvalue( self::TEST_SECRET_KEY ) ?? '';
			// 	}

			// 	/** Load db value or set it to empty **/
			// 	$test_public_key = Configuration::get( self::TEST_PUBLIC_KEY ) ?? '';
			// 	$validationAppKeyMessage = $this->validatePublicKeyField(Tools::getvalue( self::TEST_PUBLIC_KEY ), 'test');
			// 	if($validationAppKeyMessage){
			// 		$this->context->controller->errors[self::TEST_PUBLIC_KEY] = $validationAppKeyMessage;
			// 		$valid = false;
			// 	} else{
			// 		$test_public_key = Tools::getvalue( self::TEST_PUBLIC_KEY ) ?? '';
			// 	}

			// } elseif ('live' == $transactionMode) {
			// 	/** Load db value or set it to empty **/
			// 	$live_secret_key = Configuration::get( self::LIVE_SECRET_KEY ) ?? '';
			// 	$validationPublicKeyMessage = $this->validateAppKeyField(Tools::getvalue( self::LIVE_SECRET_KEY ), 'live');
			// 	if($validationPublicKeyMessage){
			// 		$this->context->controller->errors[self::LIVE_SECRET_KEY] = $validationPublicKeyMessage;
			// 		$valid = false;
			// 	} else{
			// 		$live_secret_key = Tools::getvalue( self::LIVE_SECRET_KEY ) ?? '';
			// 	}

			// 	/** Load db value or set it to empty **/
			// 	$live_public_key = Configuration::get( self::LIVE_PUBLIC_KEY ) ?? '';
			// 	$validationAppKeyMessage = $this->validatePublicKeyField(Tools::getvalue( self::LIVE_PUBLIC_KEY ), 'live');
			// 	if($validationAppKeyMessage){
			// 		$this->context->controller->errors[self::LIVE_PUBLIC_KEY] = $validationAppKeyMessage;
			// 		$valid = false;
			// 	} else{
			// 		$live_public_key = Tools::getvalue( self::LIVE_PUBLIC_KEY ) ?? '';
			// 	}
			// }

			
			Configuration::updateValue( 'LUNAR_LANGUAGE_CODE', $language_code );

			Configuration::updateValue( self::PAYMENT_METHOD_STATUS, Tools::getValue( self::PAYMENT_METHOD_STATUS ) );
			Configuration::updateValue( self::TRANSACTION_MODE, $transactionMode );
			
			if ('test' == $transactionMode) {
				Configuration::updateValue( self::TEST_PUBLIC_KEY, $test_public_key );
				Configuration::updateValue( self::TEST_SECRET_KEY, $test_secret_key );
			}
			if ('live' == $transactionMode) {
				Configuration::updateValue( self::LIVE_PUBLIC_KEY, $live_public_key );
				Configuration::updateValue( self::LIVE_SECRET_KEY, $live_secret_key );
			}
			
			Configuration::updateValue( self::LOGO_URL, Tools::getValue( self::LOGO_URL ) );

			Configuration::updateValue( self::CHECKOUT_MODE, Tools::getValue( self::CHECKOUT_MODE ) );
			Configuration::updateValue( self::ORDER_STATUS, Tools::getValue( self::ORDER_STATUS ) );
			Configuration::updateValue( $language_code . '_' . self::PAYMENT_METHOD_TITLE, $payment_method_title );
			Configuration::updateValue( $language_code . '_' . self::PAYMENT_METHOD_DESC, $payment_method_desc );
			Configuration::updateValue( $language_code . '_' . self::SHOP_TITLE, $shop_title );
			Configuration::updateValue( self::ACCEPTED_CARDS, $acceptedCards );

			if ( $valid ) {
				$this->context->controller->confirmations[] = $this->l( 'Settings saved successfully' );
			}
		}

	
		$this->context->controller->addJS( $this->_path . 'views/js/backoffice.js' );
		
		//Get configuration form
		return $this->renderForm(); // . $this->getModalForAddMoreLogo(); // disabled for the moment because of an error
	}

	public function renderForm() {
		$languages_array = array();
		$statuses_array  = array();
		$logos_array     = array();

		$language_code = Configuration::get( self::LANGUAGE_CODE );

		//Fetch all active languages
		$languages = Language::getLanguages( true, $this->context->shop->id );
		foreach ( $languages as $language ) {
			$data = array(
				'id_option' => $language['iso_code'],
				'name'      => $language['name']
			);
			array_push( $languages_array, $data );
		}

		//Fetch Status list
		$valid_statuses = array( '2', '3', '4', '5', '12' );
		$statuses       = OrderState::getOrderStates( (int) $this->context->language->id );
		foreach ( $statuses as $status ) {
			//$statuses_array[$status['id_order_state']] = $status['name'];
			if ( in_array( $status['id_order_state'], $valid_statuses ) ) {
				$data = array(
					'id_option' => $status['id_order_state'],
					'name'      => $status['name']
				);
				array_push( $statuses_array, $data );
			}
		}

		//$sql = 'SELECT * FROM `'._DB_PREFIX_. "lunar_logos`";
		$sql = new DbQuery();
		$sql->select( '*' );
		$sql->from( "lunar_logos" );
		$logos = Db::getInstance()->executes( $sql );

		foreach ( $logos as $logo ) {
			$data = array(
				'id_option' => $logo['file_name'],
				'name'      => $logo['name']
			);
			array_push( $logos_array, $data );
		}

		//Set configuration form fields
		$fields_form = array(
			'form' => array(
				'legend' => array(
					'title' => $this->l( 'Lunar Payments Settings' ),
					'icon'  => 'icon-cogs'
				),
				'input'  => array(
					/*array(
                        'type' => 'select',
                        'label' => '<span data-toggle="tooltip" title="'.$this->l('Language').'">'.$this->l('Language').'<i class="process-icon-help-new help-icon" aria-hidden="true"></i></span>',
                        'name' => self::LANGUAGE_CODE,
                        'class' => "lunar-config "lunar-language",
                        'options' => array(
                            'query' => $languages_array,
                            'id' => 'id_option',
                            'name' => 'name'
                        ),
                    ),*/
					array(
						'type'    => 'select',
						'lang'    => true,
						'name'    => self::PAYMENT_METHOD_STATUS,
						'label'   => $this->l( 'Status' ),
						'class'   => "lunar-config",
						'options' => array(
							'query' => array(
								array(
									'id_option' => 'enabled',
									'name'      => 'Enabled'
								),
								array(
									'id_option' => 'disabled',
									'name'      => 'Disabled'
								),
							),
							'id'    => 'id_option',
							'name'  => 'name'
						)
					),
					array(
						'type'     => 'select',
						'lang'     => true,
						'label'    => '<span data-toggle="tooltip" title="' . $this->l( 'In test mode, you can create a successful transaction with the card number 4111 1111 1111 1111 with any CVC and a valid expiration date.' ) . '">' . $this->l( 'Transaction mode' ) . '<i class="process-icon-help-new help-icon" aria-hidden="true"></i></span>',
						'name'     => self::TRANSACTION_MODE,
						'class'    => "lunar-config",
						'options'  => array(
							'query' => array(
								array(
									'id_option' => 'live',
									'name'      => 'Live'
								),
								array(
									'id_option' => 'test',
									'name'      => 'Test'
								),
							),
							'id'    => 'id_option',
							'name'  => 'name'
						),
						'required' => true
					),
					array(
						'type'     => 'text',
						'label'    => '<span data-toggle="tooltip" title="' . $this->l( 'Get it from your Lunar dashboard' ) . '">' . $this->l( 'Test mode App Key' ) . '<i class="process-icon-help-new help-icon" aria-hidden="true"></i></span>',
						'name'     => self::TEST_SECRET_KEY,
						'class'    => "lunar-config",
						'required' => true
					),
					array(
						'type'     => 'text',
						'label'    => '<span data-toggle="tooltip" title="' . $this->l( 'Get it from your Lunar dashboard' ) . '">' . $this->l( 'Test mode Public Key' ) . '<i class="process-icon-help-new help-icon" aria-hidden="true"></i></span>',
						'name'     => self::TEST_PUBLIC_KEY,
						'class'    => "lunar-config",
						'required' => true
					),
					array(
						'type'     => 'text',
						'label'    => '<span data-toggle="tooltip" title="' . $this->l( 'Get it from your Lunar dashboard' ) . '">' . $this->l( 'App Key' ) . '<i class="process-icon-help-new help-icon" aria-hidden="true"></i></span>',
						'name'     => self::LIVE_SECRET_KEY,
						'class'    => "lunar-config",
						'required' => true
					),
					array(
						'type'     => 'text',
						'label'    => '<span data-toggle="tooltip" title="' . $this->l( 'Get it from your Lunar dashboard' ) . '">' . $this->l( 'Public Key' ) . '<i class="process-icon-help-new help-icon" aria-hidden="true"></i></span>',
						'name'     => self::LIVE_PUBLIC_KEY,
						'class'    => "lunar-config",
						'required' => true
					),
					array(
						'type'     => 'text',
						'label'    => '<span data-toggle="tooltip" title="' . $this->l( 'Must be a link begins with "https://" to a JPG,JPEG or PNG file' ) . '">' . $this->l( 'Logo URL' ) . '<i class="process-icon-help-new help-icon" aria-hidden="true"></i></span>',
						'name'     => self::LOGO_URL,
						'class'    => "lunar-config",
						'required' => true
					),
					array(
						'type'     => 'select',
						'lang'     => true,
						'label'    => '<span data-toggle="tooltip" title="' . $this->l( 'If you deliver your product instantly (e.g. a digital product), choose Instant mode. If not, use Delayed' ) . '">' . $this->l( 'Capture mode' ) . '<i class="process-icon-help-new help-icon" aria-hidden="true"></i></span>',
						'name'     => self::CHECKOUT_MODE,
						'class'    => "lunar-config",
						'options'  => array(
							'query' => array(
								array(
									'id_option' => 'delayed',
									'name'      => $this->l( 'Delayed' )
								),
								array(
									'id_option' => 'instant',
									'name'      => $this->l( 'Instant' )
								),
							),
							'id'    => 'id_option',
							'name'  => 'name'
						),
						'required' => true,
					),
					array(
						'type'    => 'select',
						'lang'    => true,
						'label'   => '<span data-toggle="tooltip" title="' . $this->l( 'The transaction will be captured once the order has the chosen status' ) . '">' . $this->l( 'Capture on order status (delayed mode)' ) . '<i class="process-icon-help-new help-icon" aria-hidden="true"></i></span>',
						'name'    => self::ORDER_STATUS,
						'class'   => "lunar-config",
						'options' => array(
							'query' => $statuses_array,
							'id'    => 'id_option',
							'name'  => 'name'
						)
					),
					array(
						'type'     => 'text',
						'label'    => '<span data-toggle="tooltip" title="' . $this->l( 'Payment method title' ) . '">' . $this->l( 'Payment method title' ) . '<i class="process-icon-help-new help-icon" aria-hidden="true"></i></span>',
						'name'     => $language_code . '_' . self::PAYMENT_METHOD_TITLE,
						'class'    => "lunar-config",
						'required' => true
					),
					array(
						'type'  => 'textarea',
						'label' => '<span data-toggle="tooltip" title="' . $this->l( 'Description' ) . '">' . $this->l( 'Description' ) . '<i class="process-icon-help-new help-icon" aria-hidden="true"></i></span>',
						'name'  => $language_code . '_' . self::PAYMENT_METHOD_DESC,
						'class' => "lunar-config",
						//'required' => true
					),
					array(
						'type'  => 'text',
						'label' => '<span data-toggle="tooltip" title="' . $this->l( 'The text shown in the page where the customer is redirected' ) . '">' . $this->l( 'Shop title' ) . '<i class="process-icon-help-new help-icon" aria-hidden="true"></i></span>',
						'name'  => $language_code . '_' . self::SHOP_TITLE,
						'class' => "lunar-config",
						//'required' => true
					),
					array(
						'type'     => 'select',
						'label'    => '<span data-toggle="tooltip" title="' . $this->l( 'Choose logos to show in frontend checkout page.' ) . '">' . $this->l( 'Accepted cards' ) . '<i class="process-icon-help-new help-icon" aria-hidden="true"></i></span>',
						'name'     => self::ACCEPTED_CARDS,
						'class'    => "lunar-config accepted-cards",
						'multiple' => true,
						'options'  => array(
							'query' => $logos_array,
							'id'    => 'id_option',
							'name'  => 'name'
						),
					),
				),
				'submit' => array(
					'title' => $this->l( 'Save' ),
				),

			),
		);


		$helper                           = new HelperForm();
		$helper->show_toolbar             = false;
		$helper->table                    = $this->table;
		$lang                             = new Language( (int) Configuration::get( 'PS_LANG_DEFAULT' ) );
		$helper->default_form_language    = $lang->id;
		$helper->allow_employee_form_lang = Configuration::get( 'PS_BO_ALLOW_EMPLOYEE_FORM_LANG' ) ? Configuration::get( 'PS_BO_ALLOW_EMPLOYEE_FORM_LANG' ) : 0;
		$helper->identifier    = $this->identifier;
		$helper->submit_action = 'submitLunar';
		$helper->currentIndex  = $this->context->link->getAdminLink( 'AdminModules', false ) . '&configure=lunarpayment&tab_module=' . $this->tab . '&module_name=lunarpayment';
		$helper->token         = Tools::getAdminTokenLite( 'AdminModules' );
		$helper->tpl_vars      = array(
			'fields_value' => $this->getConfigFieldsValues(),
			'languages'    => $this->context->controller->getLanguages(),
			'id_language'  => $this->context->language->id
		);


		$errors = $this->context->controller->errors;
		foreach ( $fields_form['form']['input'] as $key => $field ) {
			if ( array_key_exists( $field['name'], $errors ) ) {
				$fields_form['form']['input'][ $key ]['class'] = ! empty( $fields_form['form']['input'][ $key ]['class'] ) ? $fields_form['form']['input'][ $key ]['class'] . ' has-error' : 'has-error';
			}
		}

		return $helper->generateForm( array( $fields_form ) );
	}

	public function getConfigFieldsValues() {
		$language_code = Configuration::get( self::LANGUAGE_CODE );

		$acceptedCards = explode( ',', Configuration::get( self::ACCEPTED_CARDS ) );

		$payment_method_title = $this->getTranslatedModuleConfig(self::PAYMENT_METHOD_TITLE);
		$payment_method_desc  = $this->getTranslatedModuleConfig(self::PAYMENT_METHOD_DESC);
		$shop_title          = $this->getTranslatedModuleConfig(self::SHOP_TITLE);

		if ( empty( $payment_method_title ) ) {
			$this->context->controller->errors[ $language_code . '_' . self::PAYMENT_METHOD_TITLE ] = $this->l( 'Payment method title required!' );
		}

		// @TODO activate validation when is ready

		// if ( Configuration::get( self::TRANSACTION_MODE ) == 'test' ) {
		// 	$validationAppKeyMessage = $this->validateAppKeyField(Configuration::get( self::TEST_SECRET_KEY ),'test');
		// 	if ($validationAppKeyMessage && empty($this->context->controller->errors[self::TEST_SECRET_KEY])) {
		// 		$this->context->controller->errors[self::TEST_SECRET_KEY] = $validationAppKeyMessage;
		// 	}
		// 	$validationPublicKeyMessage = $this->validatePublicKeyField(Configuration::get( self::TEST_PUBLIC_KEY ),'test');
		// 	if ($validationPublicKeyMessage && empty($this->context->controller->errors[self::TEST_PUBLIC_KEY])) {
		// 		$this->context->controller->errors[self::TEST_PUBLIC_KEY] = $validationPublicKeyMessage;
		// 	}
		// } elseif ( Configuration::get( self::TRANSACTION_MODE) == 'live' ) {
		// 	$validationAppKeyMessage = $this->validateAppKeyField(Configuration::get( self::LIVE_SECRET_KEY ),'live');
		// 	if ($validationAppKeyMessage && empty($this->context->controller->errors[self::LIVE_SECRET_KEY])) {
		// 		$this->context->controller->errors[self::LIVE_SECRET_KEY] = $validationAppKeyMessage;
		// 	}
		// 	$validationPublicKeyMessage = $this->validatePublicKeyField(Configuration::get( self::LIVE_PUBLIC_KEY ),'live');
		// 	if ($validationPublicKeyMessage && empty($this->context->controller->errors[self::LIVE_PUBLIC_KEY])) {
		// 		$this->context->controller->errors[self::LIVE_PUBLIC_KEY] = $validationPublicKeyMessage;
		// 	}
		// }

		//print_r($this->context->controller->errors);
		//die(Configuration::get(self::TRANSACTION_MODE));

		return array(
			self::LANGUAGE_CODE     => Configuration::get( self::LANGUAGE_CODE ),
			self::PAYMENT_METHOD_STATUS            => Configuration::get( self::PAYMENT_METHOD_STATUS ),
			self::TRANSACTION_MODE  => Configuration::get( self::TRANSACTION_MODE ),
			self::TEST_PUBLIC_KEY   => Configuration::get( self::TEST_PUBLIC_KEY ),
			self::TEST_SECRET_KEY   => Configuration::get( self::TEST_SECRET_KEY ),
			self::LIVE_PUBLIC_KEY   => Configuration::get( self::LIVE_PUBLIC_KEY ),
			self::LIVE_SECRET_KEY   => Configuration::get( self::LIVE_SECRET_KEY ),
			self::LOGO_URL    		=> Configuration::get( self::LOGO_URL ),
			self::CHECKOUT_MODE     => Configuration::get( self::CHECKOUT_MODE ),
			self::ORDER_STATUS      => Configuration::get( self::ORDER_STATUS ),
			$language_code . '_' . self::PAYMENT_METHOD_TITLE => $payment_method_title,
			$language_code . '_' . self::PAYMENT_METHOD_DESC  => $payment_method_desc,
			$language_code . '_' . self::SHOP_TITLE           => $shop_title,
			self::ACCEPTED_CARDS . '[]'  => $acceptedCards,
		);
	}

	/**
	 * Validate the App key.
	 *
	 * @param string $value - the value of the input.
	 * @param string $mode - the transaction mode 'test' | 'live'.
	 *
	 * @return string - the error message
	 */
	public function validateAppKeyField( $value, $mode ) {
		/** Check if the key value is empty **/
		if ( ! $value ) {
			return $this->l( 'The App Key is required!' );
		}
		/** Load the client from API**/
		$apiClient = new ApiClient( $value );
		try {
			/** Load the identity from API**/
			$identity = $apiClient->apps()->fetch();
		} catch ( ApiException $exception ) {
			PrestaShopLogger::addLog( $exception );
			return $this->l( "The App Key doesn't seem to be valid!");
		}

		try {
			/** Load the merchants public keys list corresponding for current identity **/
			$merchants = $apiClient->merchants()->find( $identity['id'] );
			if ( $merchants ) {
				foreach ( $merchants as $merchant ) {
					/** Check if the key mode is the same as the transaction mode **/
					if(($mode == 'test' && $merchant['test']) || ($mode != 'test' && !$merchant['test'])){
						$this->validationPublicKeys[$mode][] = $merchant['key'];
					}
				}
			}
		} catch ( ApiException $exception ) {
			PrestaShopLogger::addLog( $exception );
		}

		/** Check if public keys array for the current mode is populated **/
		if ( empty( $this->validationPublicKeys[$mode] ) ) {
			/** Generate the error based on the current mode **/
			// $error = $this->l( 'The '.$mode .' App Key is not valid or set to '.array_values(array_diff(array_keys($this->validationPublicKeys), array($mode)))[0].' mode!' );
			$error = $this->l( 'The App Key is not valid or set to different mode!' );
			PrestaShopLogger::addLog( $error );
			return $error;
		}
	}

	/**
	 * Validate the Public key.
	 *
	 * @param string $value - the value of the input.
	 * @param string $mode - the transaction mode 'test' | 'live'.
	 *
	 * @return mixed
	 * @throws Exception
	 */
	public function validatePublicKeyField($value, $mode) {
		/** Check if the key value is not empty **/
		if ( ! $value ) {
			return $this->l( 'The Public Key is required!' );
		}
		/** Check if the local stored public keys array is empty OR the key is not in public keys list **/
		if ( empty( $this->validationPublicKeys[$mode] ) || ! in_array( $value, $this->validationPublicKeys[$mode] ) ) {
			$error = $this->l( 'The Public Key doesn\'t seem to be valid!' );
			PrestaShopLogger::addLog( $error );
			return $error;
		}
	}

	public function getModalForAddMoreLogo() {
		$this->context->smarty->assign( array(
			'request_uri' => $this->context->link->getAdminLink( 'AdminOrders', false ),
			'tok'         => Tools::getAdminToken( 'AdminOrders' )
		) );

		return $this->display( __FILE__, 'views/templates/admin/modal.tpl' );
	}

	/**
     * @param $params
     *
     * @return array
     *
     * @throws Exception
     * @throws SmartyException
	 */
	public function hookPaymentOptions( $params ) {
		//ensure plugin key is set
		if ( Configuration::get( self::TRANSACTION_MODE ) == 'test' ) {
			if ( ! Configuration::get( self::TEST_PUBLIC_KEY ) || ! Configuration::get( self::TEST_SECRET_KEY ) ) {
				return false;
			} else {
				$PLUGIN_PUBLIC_KEY = Configuration::get( self::TEST_PUBLIC_KEY );
			}
		}

		if ( Configuration::get( self::TRANSACTION_MODE ) == 'live' ) {
			if ( ! Configuration::get( self::LIVE_PUBLIC_KEY ) || ! Configuration::get( self::LIVE_SECRET_KEY ) ) {
				return false;
			} else {
				$PLUGIN_PUBLIC_KEY = Configuration::get( self::LIVE_PUBLIC_KEY );
			}
		}

		if ( ! Configuration::get( self::TEST_PUBLIC_KEY ) && ! Configuration::get( self::TEST_SECRET_KEY ) && ! Configuration::get( self::LIVE_PUBLIC_KEY ) && ! Configuration::get( self::LIVE_SECRET_KEY ) ) {
			return false;
		}

		$products       = $params['cart']->getProducts();
		$products_array = array();
		$products_label = array();
		$p              = 0;
		foreach ( $products as $product ) {
			$products_array[]     = array(
				$this->l( 'ID' )       => $product['id_product'],
				$this->l( 'Name' )     => $product['name'],
				$this->l( 'Quantity' ) => $product['cart_quantity']
			);
			$products_label[ $p ] = $product['quantity'] . 'x ' . $product['name'];
			$p ++;
		}

		$payment_method_title = $this->getTranslatedModuleConfig(self::PAYMENT_METHOD_TITLE);
		$payment_method_desc  = $this->getTranslatedModuleConfig(self::PAYMENT_METHOD_DESC);
		$shop_title          = $this->getTranslatedModuleConfig(self::SHOP_TITLE);

		$redirect_url = $this->context->link->getModuleLink( $this->name, 'paymentreturn', array(), true, (int) $this->context->language->id );

		if ( Configuration::get( 'PS_REWRITING_SETTINGS' ) == 1 ) {
			$redirect_url = Tools::strReplaceFirst( '&', '?', $redirect_url );
		}

		$currency            = new Currency( (int) $params['cart']->id_currency );
		$currency_code       = $currency->iso_code;
		$customer            = new Customer( (int) $params['cart']->id_customer );
		$name                = $customer->firstname . ' ' . $customer->lastname;
		$email               = $customer->email;
		$customer_address    = new Address( (int) ( $params['cart']->id_address_delivery ) );
		$telephone           = $customer_address->phone ?? $customer_address->phone_mobile ?? '';
		$address             = $customer_address->address1 . ', ' . $customer_address->address2 . ', ' . $customer_address->city . ', ' . $customer_address->country . ' - ' . $customer_address->postcode;


		$this->context->smarty->assign( array(
			'active_status'             	 => Configuration::get( self::TRANSACTION_MODE ),
			'PLUGIN_PUBLIC_KEY'          	 => $PLUGIN_PUBLIC_KEY,
			'PS_SSL_ENABLED'                 => ( Configuration::get( 'PS_SSL_ENABLED' ) ? 'https' : 'http' ),
			'http_host'                      => Tools::getHttpHost(),
			'shop_name'                      => $this->context->shop->name,
			'payment_method_title'           => $payment_method_title,
			'accepted_cards' 				 => explode( ',', Configuration::get( self::ACCEPTED_CARDS ) ),
			'payment_method_desc'            => $payment_method_desc,
			'this_plugin_status'			 => Configuration::get( self::PAYMENT_METHOD_STATUS ),
			'shop_title'                     => $shop_title,
			'currency_code'                  => $currency_code,
			'amount'                         => $params['cart']->getOrderTotal(),
			'id_cart'                        => json_encode( $params['cart']->id ),
			'products'                       => str_replace("\u0022","\\\\\"",json_encode(  $products_array ,JSON_HEX_QUOT)),
			'name'                           => $name,
			'email'                          => $email,
			'telephone'                      => $telephone,
			'address'                        => $address,
			'ip'                             => Tools::getRemoteAddr(),
			'locale'                         => $this->context->language->iso_code,
			'platform_version'               => _PS_VERSION_,
			'platform'                       => 'Prestashop',
			'module_version'                 => $this->version,
			'redirect_url'                   => $redirect_url,
			'qry_str'                        => ( Configuration::get( 'PS_REWRITING_SETTINGS' ) ? '?' : '&' ),
			'base_uri'                       => __PS_BASE_URI__,
			'this_plugin_path'  			 => $this->_path
		) );

		$newOption = new PaymentOption();
		$newOption->setModuleName( $this->name )
		          ->setCallToActionText( $this->trans( $payment_method_title, array() ) )
		          ->setAction( $this->context->link->getModuleLink( $this->name, 'validation', array(), true ) )
		          ->setAdditionalInformation( $this->display( __FILE__, 'views/templates/hook/payment.tpl' ) );
		$payment_options = array( $newOption );

		return $payment_options;
	}

	private function getTranslatedModuleConfig(string $key)
	{
		$language_code = Configuration::get( self::LANGUAGE_CODE );
		return ( ! empty( Configuration::get( $language_code . '_' . $key ) ) ) 
					? Configuration::get( $language_code . '_' . $key ) 
					: ( ! empty( Configuration::get( 'en_' . $key ) ) 
							? Configuration::get( 'en_' . $key ) 
							: '' );
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

	public function storeTransactionID( $plugin_id_transaction, $order_id, $total, $captured = 'NO' ) {
		$query = 'INSERT INTO ' . _DB_PREFIX_ . 'lunar_transactions (`'
					. 'lunar_tid`, `order_id`, `payed_amount`, `payed_at`, `captured`) VALUES ("'
					. pSQL( $plugin_id_transaction ) . '", "' . pSQL( $order_id ) . '", "' . pSQL( $total ) . '" , NOW(), "' . pSQL( $captured ) . '")';

		return Db::getInstance()->Execute( $query );
	}

	public function updateTransactionID( $plugin_id_transaction, $order_id, $fields = array() ) {
		if ( $plugin_id_transaction && $order_id && ! empty( $fields ) ) {
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
						. ' WHERE `' . 'lunar_tid`="' . pSQL( $plugin_id_transaction )
						. '" AND `order_id`="' . (int)$order_id . '"';

			return Db::getInstance()->Execute( $query );
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

		return Context::getContext()->getTranslator()->trans( 'Fatal error', array(), 'Admin.Notifications.Error' );
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

					if ( Db::getInstance()->Execute( $query ) ) {
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

		if ( Tools::getIsset( 'change_language' ) ) {
			$language_code = ( ! empty( Tools::getvalue( 'lang_code' ) ) ) ? Tools::getvalue( 'lang_code' ) : Configuration::get( self::LANGUAGE_CODE );
			Configuration::updateValue( self::LANGUAGE_CODE, $language_code );
			$token = Tools::getAdminToken( 'AdminModules' . (int)  Tab::getIdFromClassName( 'AdminModules' ) . (int) $this->context->employee->id );
			$link  = $this->context->link->getAdminLink( 'AdminModules' ) . '&token=' . $token . '&configure=lunarpayment&tab_module=' . $this->tab . '&module_name=lunarpayment';
			Tools::redirectAdmin( $link );
		}

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
}
