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

if ( ! class_exists( 'Lunar\\Client' ) ) {
	require_once( 'api/Client.php' );
}

require_once __DIR__.'/vendor/autoload.php';

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;
use Paylike\Exception\ApiException;
use Paylike\Paylike as ApiClient;
use Lunar\Client;
use Lunar\Transaction;

class LunarPayment extends PaymentModule {
	private $html = '';
	protected $statuses_array = array();

	const VENDOR_NAME = 'lunar';
	const MODULE_CODE = 'lunarpayment';
	const MODULE_VERSION = '1.0.0';

	const PLUGIN_CHECKOUT_MODE = 'LUNAR_CHECKOUT_MODE';
	const PLUGIN_LANGUAGE_CODE = 'LUNAR_LANGUAGE_CODE';
	const PLUGIN_ORDER_STATUS = 'LUNAR_ORDER_STATUS';
	const PLUGIN_PAYMENT_METHOD_DESC = 'LUNAR_PAYMENT_METHOD_DESC';
	const PLUGIN_PAYMENT_METHOD_TITLE = 'LUNAR_PAYMENT_METHOD_TITLE';
	const PLUGIN_POPUP_DESC = 'LUNAR_POPUP_DESC';
	const PLUGIN_POPUP_TITLE = 'LUNAR_POPUP_TITLE';
	const PLUGIN_SHOW_POPUP_DESC = 'LUNAR_SHOW_POPUP_DESC';
	const PLUGIN_STATUS = 'LUNAR_STATUS';
	const PLUGIN_LIVE_PUBLIC_KEY = 'LUNAR_LIVE_PUBLIC_KEY';
	const PLUGIN_LIVE_SECRET_KEY = 'LUNAR_LIVE_SECRET_KEY';
	const PLUGIN_TEST_PUBLIC_KEY = 'LUNAR_TEST_PUBLIC_KEY';
	const PLUGIN_TEST_SECRET_KEY = 'LUNAR_TEST_SECRET_KEY';
	const PLUGIN_SECRET_KEY = 'LUNAR_SECRET_KEY';
	const PLUGIN_TRANSACTION_MODE = 'LUNAR_TRANSACTION_MODE';
	const PLUGIN_PAYMENT_METHOD_LOGO = 'LUNAR_PAYMENT_METHOD_LOGO';
	const PLUGIN_PAYMENT_METHOD_CREDITCARD_LOGO = 'LUNAR_PAYMENT_METHOD_CREDITCARD_LOGO';

	public function __construct() {
		$this->name      = self::MODULE_CODE;
		$this->tab       = 'payments_gateways';
		$this->version   = self::MODULE_VERSION;
		$this->author    = 'Lunar';
		$this->bootstrap = true;

		$this->currencies      = true;
		$this->currencies_mode = 'checkbox';

		$this->validationPublicKeys = array ('live'=>array(),'test'=>array());

		parent::__construct();

		$this->displayName      = $this->l( ucfirst(self::VENDOR_NAME) );
		$this->description      = $this->l( 'Receive payments with ' . ucfirst(self::VENDOR_NAME) );
		$this->confirmUninstall = $this->l( 'Are you sure about removing ' . ucfirst(self::VENDOR_NAME) . '?' );
		$popupDescription       = $this->l( 'Secure payment with credit card via © ' . ucfirst(self::VENDOR_NAME) );
	}

	public function install() {
		$popup_title   = ( ! empty( Configuration::get( 'PS_SHOP_NAME' ) ) ) ? Configuration::get( 'PS_SHOP_NAME' ) : 'Payment';
		$language_code = $this->context->language->iso_code;

		Configuration::updateValue( self::PLUGIN_LANGUAGE_CODE, $language_code );
		Configuration::updateValue( $language_code . '_' . self::PLUGIN_PAYMENT_METHOD_TITLE, 'Credit card' );
		Configuration::updateValue( self::PLUGIN_PAYMENT_METHOD_LOGO, 'visa.svg,visa-electron.svg,mastercard.svg,mastercard-maestro.svg' );
		Configuration::updateValue( $language_code . '_' . self::PLUGIN_PAYMENT_METHOD_DESC, 'Secure payment with credit card via © ' . ucfirst(self::VENDOR_NAME) );
		Configuration::updateValue( $language_code . '_' . self::PLUGIN_POPUP_TITLE, $popup_title );
		Configuration::updateValue( self::PLUGIN_SHOW_POPUP_DESC, 'no' );
		Configuration::updateValue( $language_code . '_' . self::PLUGIN_POPUP_DESC, '' );
		Configuration::updateValue( self::PLUGIN_TRANSACTION_MODE, 'live' ); // defaults to live mode
		Configuration::updateValue( self::PLUGIN_TEST_SECRET_KEY, '' );
		Configuration::updateValue( self::PLUGIN_TEST_PUBLIC_KEY, '' );
		Configuration::updateValue( self::PLUGIN_LIVE_SECRET_KEY, '' );
		Configuration::updateValue( self::PLUGIN_LIVE_PUBLIC_KEY, '' );
		Configuration::updateValue( self::PLUGIN_CHECKOUT_MODE, 'delayed' );
		Configuration::updateValue( self::PLUGIN_ORDER_STATUS, Configuration::get( self::PLUGIN_ORDER_STATUS ) );
		Configuration::updateValue( self::PLUGIN_STATUS, 'enabled' );
		Configuration::updateValue( self::PLUGIN_SECRET_KEY, '' );

		return ( parent::install()
		         && $this->registerHook( 'payment' )
		         && $this->registerHook( 'paymentOptions' )
		         && ((int)substr(_PS_VERSION_, 0, 1) < 8 ? $this->registerHook( 'paymentReturn' ) : $this->registerHook( 'displayPaymentReturn' ))
		         && $this->registerHook( 'DisplayAdminOrder' )
		         && ((int)substr(_PS_VERSION_, 0, 1) < 8 ? $this->registerHook( 'BackOfficeHeader' ) : $this->registerHook( 'displayBackOfficeHeader' ))
		         && $this->registerHook( 'actionOrderStatusPostUpdate' )
		         && $this->registerHook( 'actionOrderSlipAdd' )
		         && $this->installDb() );
	}

	public function installDb() {
		return (
			Db::getInstance()->Execute( 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . self::VENDOR_NAME . "_admin` (
                `id`					    	INT(11) NOT NULL AUTO_INCREMENT,
                `" . self::VENDOR_NAME . "_tid`	VARCHAR(255) NOT NULL,
                `order_id`						INT(11) NOT NULL,
                `payed_at`						DATETIME NOT NULL,
                `payed_amount`					DECIMAL(20,6) NOT NULL,
                `refunded_amount`				DECIMAL(20,6) NOT NULL,
                `captured`		    			VARCHAR(255) NOT NULL,
                PRIMARY KEY						(`id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=latin1 AUTO_INCREMENT=1 ;" )

			&& Db::getInstance()->Execute( 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . self::VENDOR_NAME . "_logos` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `name` VARCHAR(255) NOT NULL,
                `slug` VARCHAR(255) NOT NULL,
                `file_name` VARCHAR(255) NOT NULL,
                `default_logo` INT(11) NOT NULL DEFAULT 1 COMMENT '1=Default',
                `created_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`)
                ) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8;" )

			&& Db::getInstance()->insert(
				self::VENDOR_NAME . "_logos",
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
		$sql->from( self::VENDOR_NAME . "_logos", 'PL' );
		$sql->where( 'PL.default_logo != 1' );
		$logos = Db::getInstance()->executes( $sql );

		foreach ( $logos as $logo ) {
			if ( file_exists( _PS_MODULE_DIR_ . self::MODULE_CODE . '/views/img/' . $logo['file_name'] ) ) {
				unlink( _PS_MODULE_DIR_ . self::MODULE_CODE . '/views/img/' . $logo['file_name'] );
			}
		}

		//Fetch all languages and delete plugin configurations which has language iso_code as prefix
		$languages = Language::getLanguages( true, $this->context->shop->id );
		foreach ( $languages as $language ) {
			$language_code = $language['iso_code'];
			Configuration::deleteByName( $language_code . '_' . self::PLUGIN_PAYMENT_METHOD_TITLE );
			Configuration::deleteByName( $language_code . '_' . self::PLUGIN_PAYMENT_METHOD_DESC );
			Configuration::deleteByName( $language_code . '_' . self::PLUGIN_POPUP_TITLE );
			Configuration::deleteByName( $language_code . '_' . self::PLUGIN_POPUP_DESC );
		}

		return (
			parent::uninstall()
			&& Db::getInstance()->Execute( 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . self::VENDOR_NAME . "_admin`" )
			&& Db::getInstance()->Execute( 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . self::VENDOR_NAME . "_logos`" )
			&& Configuration::deleteByName( self::PLUGIN_PAYMENT_METHOD_TITLE )
			&& Configuration::deleteByName( self::PLUGIN_PAYMENT_METHOD_LOGO )
			&& Configuration::deleteByName( self::PLUGIN_PAYMENT_METHOD_DESC )
			&& Configuration::deleteByName( self::PLUGIN_POPUP_TITLE )
			&& Configuration::deleteByName( self::PLUGIN_SHOW_POPUP_DESC )
			&& Configuration::deleteByName( self::PLUGIN_POPUP_DESC )
			&& Configuration::deleteByName( self::PLUGIN_TRANSACTION_MODE )
			&& Configuration::deleteByName( self::PLUGIN_TEST_SECRET_KEY )
			&& Configuration::deleteByName( self::PLUGIN_TEST_PUBLIC_KEY )
			&& Configuration::deleteByName( self::PLUGIN_LIVE_SECRET_KEY )
			&& Configuration::deleteByName( self::PLUGIN_LIVE_PUBLIC_KEY )
			&& Configuration::deleteByName( self::PLUGIN_CHECKOUT_MODE )
			&& Configuration::deleteByName( self::PLUGIN_ORDER_STATUS )
			&& Configuration::deleteByName( self::PLUGIN_STATUS )
			&& Configuration::deleteByName( self::PLUGIN_SECRET_KEY )
		);
	}

	public function getPSV() {
		return Tools::substr( _PS_VERSION_, 0, 5 );
	}

	public function getContent() {
		$this->html = '';
		if ( Tools::isSubmit( 'submit' . ucfirst(self::VENDOR_NAME) ) ) {
			$language_code = Configuration::get( self::PLUGIN_LANGUAGE_CODE );
			$valid         = true;

			$PAYMENT_METHOD_TITLE = ! empty( Tools::getvalue( $language_code . '_' . self::PLUGIN_PAYMENT_METHOD_TITLE ) ) ? Tools::getvalue( $language_code . '_' . self::PLUGIN_PAYMENT_METHOD_TITLE ) : '';
			$PAYMENT_METHOD_DESC  = ! empty( Tools::getvalue( $language_code . '_' . self::PLUGIN_PAYMENT_METHOD_DESC ) ) ? Tools::getvalue( $language_code . '_' . self::PLUGIN_PAYMENT_METHOD_DESC ) : '';
			$PLUGIN_POPUP_TITLE          = ( ! empty( Tools::getvalue( $language_code . '_' . self::PLUGIN_POPUP_TITLE ) ) ) ? Tools::getvalue( $language_code . '_' . self::PLUGIN_POPUP_TITLE ) : '';
			$PLUGIN_POPUP_DESC          = ( ! empty( Tools::getvalue( $language_code . '_' . self::PLUGIN_POPUP_DESC ) ) ) ? Tools::getvalue( $language_code . '_' . self::PLUGIN_POPUP_DESC ) : '';

			if ( empty( $PAYMENT_METHOD_TITLE ) ) {
				$this->context->controller->errors[ $language_code . '_' . self::PLUGIN_PAYMENT_METHOD_TITLE ] = $this->l( 'Payment method title required!' );
				$PAYMENT_METHOD_TITLE = ( ! empty( Configuration::get( $language_code . '_' . self::PLUGIN_PAYMENT_METHOD_TITLE ) ) ) ? Configuration::get( $language_code . '_' . self::PLUGIN_PAYMENT_METHOD_TITLE ) : '';
				$valid = false;
			}

			if ( count( Tools::getvalue( self::PLUGIN_PAYMENT_METHOD_CREDITCARD_LOGO ) ) > 1 ) {
				$creditCardLogo = implode( ',', Tools::getvalue( self::PLUGIN_PAYMENT_METHOD_CREDITCARD_LOGO ) );
			} else {
				$creditCardLogo = Tools::getvalue( self::PLUGIN_PAYMENT_METHOD_CREDITCARD_LOGO );
			}

			if ( Tools::getvalue( self::PLUGIN_TRANSACTION_MODE ) == 'test' ) {
				/** Load db value or set it to empty **/
				$PLUGIN_TEST_SECRET_KEY = ( ! empty( Configuration::get( self::PLUGIN_TEST_SECRET_KEY ) ) ) ? Configuration::get( self::PLUGIN_TEST_SECRET_KEY ) : '';
				$validationPublicKeyMessage = $this->validateAppKeyField(Tools::getvalue( self::PLUGIN_TEST_SECRET_KEY ),'test');
				if($validationPublicKeyMessage){
					$this->context->controller->errors[self::PLUGIN_TEST_SECRET_KEY] = $validationPublicKeyMessage;
					$valid                                                        = false;
				}else{
					$PLUGIN_TEST_SECRET_KEY = ( ! empty( Tools::getvalue( self::PLUGIN_TEST_SECRET_KEY ) ) ) ? Tools::getvalue( self::PLUGIN_TEST_SECRET_KEY ) : '';
				}

				/** Load db value or set it to empty **/
				$PLUGIN_TEST_PUBLIC_KEY = ( ! empty( Configuration::get( self::PLUGIN_TEST_PUBLIC_KEY ) ) ) ? Configuration::get( self::PLUGIN_TEST_PUBLIC_KEY ) : '';
				$validationAppKeyMessage = $this->validatePublicKeyField(Tools::getvalue( self::PLUGIN_TEST_PUBLIC_KEY ),'test');
				if($validationAppKeyMessage){
					$this->context->controller->errors[self::PLUGIN_TEST_PUBLIC_KEY] = $validationAppKeyMessage;
					$valid                                                        = false;
				}else{
					$PLUGIN_TEST_PUBLIC_KEY = ( ! empty( Tools::getvalue( self::PLUGIN_TEST_PUBLIC_KEY ) ) ) ? Tools::getvalue( self::PLUGIN_TEST_PUBLIC_KEY ) : '';
				}

			} elseif ( Tools::getvalue( self::PLUGIN_TRANSACTION_MODE ) == 'live' ) {
				/** Load db value or set it to empty **/
				$PLUGIN_LIVE_SECRET_KEY = ( ! empty( Configuration::get( self::PLUGIN_LIVE_SECRET_KEY ) ) ) ? Configuration::get( self::PLUGIN_LIVE_SECRET_KEY ) : '';
				$validationPublicKeyMessage = $this->validateAppKeyField(Tools::getvalue( self::PLUGIN_LIVE_SECRET_KEY ),'live');
				if($validationPublicKeyMessage){
					$this->context->controller->errors[self::PLUGIN_LIVE_SECRET_KEY] = $validationPublicKeyMessage;
					$valid                                                        = false;
				}else{
					$PLUGIN_LIVE_SECRET_KEY = ( ! empty( Tools::getvalue( self::PLUGIN_LIVE_SECRET_KEY ) ) ) ? Tools::getvalue( self::PLUGIN_LIVE_SECRET_KEY ) : '';
				}

				/** Load db value or set it to empty **/
				$PLUGIN_LIVE_PUBLIC_KEY = ( ! empty( Configuration::get( self::PLUGIN_LIVE_PUBLIC_KEY ) ) ) ? Configuration::get( self::PLUGIN_LIVE_PUBLIC_KEY ) : '';
				$validationAppKeyMessage = $this->validatePublicKeyField(Tools::getvalue( self::PLUGIN_LIVE_PUBLIC_KEY ),'live');
				if($validationAppKeyMessage){
					$this->context->controller->errors[self::PLUGIN_LIVE_PUBLIC_KEY] = $validationAppKeyMessage;
					$valid                                                        = false;
				}else{
					$PLUGIN_LIVE_PUBLIC_KEY = ( ! empty( Tools::getvalue( self::PLUGIN_LIVE_PUBLIC_KEY ) ) ) ? Tools::getvalue( self::PLUGIN_LIVE_PUBLIC_KEY ) : '';
				}
			}

			Configuration::updateValue( self::PLUGIN_TRANSACTION_MODE, $language_code );
			Configuration::updateValue( $language_code . '_' . self::PLUGIN_PAYMENT_METHOD_TITLE, $PAYMENT_METHOD_TITLE );
			Configuration::updateValue( self::PLUGIN_PAYMENT_METHOD_LOGO, $creditCardLogo );
			Configuration::updateValue( $language_code . '_' . self::PLUGIN_PAYMENT_METHOD_DESC, $PAYMENT_METHOD_DESC );
			Configuration::updateValue( $language_code . '_' . self::PLUGIN_POPUP_TITLE, $PLUGIN_POPUP_TITLE );
			Configuration::updateValue( self::PLUGIN_SHOW_POPUP_DESC, Tools::getvalue( self::PLUGIN_SHOW_POPUP_DESC ) );
			Configuration::updateValue( $language_code . '_' . self::PLUGIN_POPUP_DESC, $PLUGIN_POPUP_DESC );
			Configuration::updateValue( self::PLUGIN_TRANSACTION_MODE, Tools::getvalue( self::PLUGIN_TRANSACTION_MODE ) );
			if ( Tools::getvalue( self::PLUGIN_TRANSACTION_MODE ) == 'test' ) {
				Configuration::updateValue( self::PLUGIN_TEST_SECRET_KEY, $PLUGIN_TEST_SECRET_KEY );
				Configuration::updateValue( self::PLUGIN_TEST_PUBLIC_KEY, $PLUGIN_TEST_PUBLIC_KEY );
			}
			if ( Tools::getvalue( self::PLUGIN_TRANSACTION_MODE ) == 'live' ) {
				Configuration::updateValue( self::PLUGIN_LIVE_SECRET_KEY, $PLUGIN_LIVE_SECRET_KEY );
				Configuration::updateValue( self::PLUGIN_LIVE_PUBLIC_KEY, $PLUGIN_LIVE_PUBLIC_KEY );
			}
			Configuration::updateValue( self::PLUGIN_CHECKOUT_MODE, Tools::getValue( self::PLUGIN_CHECKOUT_MODE ) );
			Configuration::updateValue( self::PLUGIN_ORDER_STATUS, Tools::getValue( self::PLUGIN_ORDER_STATUS ) );
			Configuration::updateValue( self::PLUGIN_STATUS, Tools::getValue( self::PLUGIN_STATUS ) );

			if ( $valid ) {
				$this->context->controller->confirmations[] = $this->l( 'Settings saved successfully' );
			}
		}

		//Get configuration form
		$this->html .= $this->renderForm();

		$this->html .= $this->getModalForAddMoreLogo();

		$this->context->controller->addJS( $this->_path . 'views/js/backoffice.js' );

		return $this->html;
	}

	public function renderForm() {
		$this->languages_array = array();
		$this->statuses_array  = array();
		$this->logos_array     = array();

		$language_code = Configuration::get( self::PLUGIN_LANGUAGE_CODE );

		//Fetch all active languages
		$languages = Language::getLanguages( true, $this->context->shop->id );
		foreach ( $languages as $language ) {
			$data = array(
				'id_option' => $language['iso_code'],
				'name'      => $language['name']
			);
			array_push( $this->languages_array, $data );
		}

		//Fetch Status list
		$valid_statuses = array( '2', '3', '4', '5', '12' );
		$statuses       = OrderState::getOrderStates( (int) $this->context->language->id );
		foreach ( $statuses as $status ) {
			//$this->statuses_array[$status['id_order_state']] = $status['name'];
			if ( in_array( $status['id_order_state'], $valid_statuses ) ) {
				$data = array(
					'id_option' => $status['id_order_state'],
					'name'      => $status['name']
				);
				array_push( $this->statuses_array, $data );
			}
		}

		//$sql = 'SELECT * FROM `'._DB_PREFIX_. self::VENDOR_NAME . "_logos`";
		$sql = new DbQuery();
		$sql->select( '*' );
		$sql->from( self::VENDOR_NAME . "_logos" );
		$logos = Db::getInstance()->executes( $sql );

		foreach ( $logos as $logo ) {
			$data = array(
				'id_option' => $logo['file_name'],
				'name'      => $logo['name']
			);
			array_push( $this->logos_array, $data );
		}

		//Set configuration form fields
		$fields_form = array(
			'form' => array(
				'legend' => array(
					'title' => $this->l( ucfirst(self::VENDOR_NAME) . ' Payments Settings' ),
					'icon'  => 'icon-cogs'
				),
				'input'  => array(
					/*array(
                        'type' => 'select',
                        'label' => '<span data-toggle="tooltip" title="'.$this->l('Language').'">'.$this->l('Language').'<i class="process-icon-help-new help-icon" aria-hidden="true"></i></span>',
                        'name' => self::PLUGIN_LANGUAGE_CODE,
                        'class' => self::VENDOR_NAME . "-config self::VENDOR_NAME . "-language",
                        'options' => array(
                            'query' => $this->languages_array,
                            'id' => 'id_option',
                            'name' => 'name'
                        ),
                    ),*/
					array(
						'type'     => 'text',
						'label'    => '<span data-toggle="tooltip" title="' . $this->l( 'Payment method title' ) . '">' . $this->l( 'Payment method title' ) . '<i class="process-icon-help-new help-icon" aria-hidden="true"></i></span>',
						'name'     => $language_code . '_' . self::PLUGIN_PAYMENT_METHOD_TITLE,
						'class'    => self::VENDOR_NAME . "-config",
						'required' => true
					),
					array(
						'type'     => 'select',
						'label'    => '<span data-toggle="tooltip" title="' . $this->l( 'Choose a logo to show in frontend checkout page.' ) . '">' . $this->l( 'Payment method credit card logos' ) . '<i class="process-icon-help-new help-icon" aria-hidden="true"></i></span>',
						'name'     => self::PLUGIN_PAYMENT_METHOD_CREDITCARD_LOGO . '[]',
						'class'    => self::VENDOR_NAME . "-config creditcard-logo",
						'multiple' => true,
						'options'  => array(
							'query' => $this->logos_array,
							'id'    => 'id_option',
							'name'  => 'name'
						),
					),
					array(
						'type'  => 'textarea',
						'label' => '<span data-toggle="tooltip" title="' . $this->l( 'Payment method description' ) . '">' . $this->l( 'Payment method description' ) . '<i class="process-icon-help-new help-icon" aria-hidden="true"></i></span>',
						'name'  => $language_code . '_' . self::PLUGIN_PAYMENT_METHOD_DESC,
						'class' => self::VENDOR_NAME . "-config",
						//'required' => true
					),
					array(
						'type'  => 'text',
						'label' => '<span data-toggle="tooltip" title="' . $this->l( 'The text shown in the popup where the customer inserts the card details' ) . '">' . $this->l( 'Payment popup title' ) . '<i class="process-icon-help-new help-icon" aria-hidden="true"></i></span>',
						'name'  => $language_code . '_' . self::PLUGIN_POPUP_TITLE,
						'class' => self::VENDOR_NAME . "-config",
						//'required' => true
					),
					array(
						'type'    => 'select',
						'lang'    => true,
						'label'   => '<span data-toggle="tooltip" title="' . $this->l( 'If this is set to no the product list will be shown' ) . '">' . $this->l( 'Show payment popup description' ) . '<i class="process-icon-help-new help-icon" aria-hidden="true"></i></span>',
						'name'    => self::PLUGIN_SHOW_POPUP_DESC,
						'class'   => self::VENDOR_NAME . "-config",
						'options' => array(
							'query' => array(
								array(
									'id_option' => 'yes',
									'name'      => 'Yes'
								),
								array(
									'id_option' => 'no',
									'name'      => 'No'
								),
							),
							'id'    => 'id_option',
							'name'  => 'name'
						)
					),
					array(
						'type'  => 'text',
						'label' => '<span data-toggle="tooltip" title="' . $this->l( 'Text description that shows up on the payment popup.' ) . '">' . $this->l( 'Popup description' ) . '<i class="process-icon-help-new help-icon" aria-hidden="true"></i></span>',
						'name'  => $language_code . '_' . self::PLUGIN_POPUP_DESC,
						'class' => self::VENDOR_NAME . "-config"
					),
					array(
						'type'     => 'select',
						'lang'     => true,
						'label'    => '<span data-toggle="tooltip" title="' . $this->l( 'In test mode, you can create a successful transaction with the card number 4100 0000 0000 0000 with any CVC and a valid expiration date.' ) . '">' . $this->l( 'Transaction mode' ) . '<i class="process-icon-help-new help-icon" aria-hidden="true"></i></span>',
						'name'     => self::PLUGIN_TRANSACTION_MODE,
						'class'    => self::VENDOR_NAME . "-config",
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
						'label'    => '<span data-toggle="tooltip" title="' . $this->l( 'Get it from your ' . ucfirst(self::VENDOR_NAME) . ' dashboard' ) . '">' . $this->l( 'Test mode App Key' ) . '<i class="process-icon-help-new help-icon" aria-hidden="true"></i></span>',
						'name'     => self::PLUGIN_TEST_SECRET_KEY,
						'class'    => self::VENDOR_NAME . "-config",
						'required' => true
					),
					array(
						'type'     => 'text',
						'label'    => '<span data-toggle="tooltip" title="' . $this->l( 'Get it from your ' . ucfirst(self::VENDOR_NAME) . ' dashboard' ) . '">' . $this->l( 'Test mode Public Key' ) . '<i class="process-icon-help-new help-icon" aria-hidden="true"></i></span>',
						'name'     => self::PLUGIN_TEST_PUBLIC_KEY,
						'class'    => self::VENDOR_NAME . "-config",
						'required' => true
					),
					array(
						'type'     => 'text',
						'label'    => '<span data-toggle="tooltip" title="' . $this->l( 'Get it from your ' . ucfirst(self::VENDOR_NAME) . ' dashboard' ) . '">' . $this->l( 'App Key' ) . '<i class="process-icon-help-new help-icon" aria-hidden="true"></i></span>',
						'name'     => self::PLUGIN_LIVE_SECRET_KEY,
						'class'    => self::VENDOR_NAME . "-config",
						'required' => true
					),
					array(
						'type'     => 'text',
						'label'    => '<span data-toggle="tooltip" title="' . $this->l( 'Get it from your ' . ucfirst(self::VENDOR_NAME) . ' dashboard' ) . '">' . $this->l( 'Public Key' ) . '<i class="process-icon-help-new help-icon" aria-hidden="true"></i></span>',
						'name'     => self::PLUGIN_LIVE_PUBLIC_KEY,
						'class'    => self::VENDOR_NAME . "-config",
						'required' => true
					),
					array(
						'type'     => 'select',
						'lang'     => true,
						'label'    => '<span data-toggle="tooltip" title="' . $this->l( 'If you deliver your product instantly (e.g. a digital product), choose Instant mode. If not, use Delayed' ) . '">' . $this->l( 'Capture mode' ) . '<i class="process-icon-help-new help-icon" aria-hidden="true"></i></span>',
						'name'     => self::PLUGIN_CHECKOUT_MODE,
						'class'    => self::VENDOR_NAME . "-config",
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
						'name'    => self::PLUGIN_ORDER_STATUS,
						'class'   => self::VENDOR_NAME . "-config",
						'options' => array(
							'query' => $this->statuses_array,
							'id'    => 'id_option',
							'name'  => 'name'
						)
					),
					array(
						'type'    => 'select',
						'lang'    => true,
						'name'    => self::PLUGIN_STATUS,
						'label'   => $this->l( 'Status' ),
						'class'   => self::VENDOR_NAME . "-config",
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
		$this->fields_form                = array();

		$helper->identifier    = $this->identifier;
		$helper->submit_action = 'submit' . ucfirst(self::VENDOR_NAME);
		$helper->currentIndex  = $this->context->link->getAdminLink( 'AdminModules', false ) . '&configure=' . self::MODULE_CODE . '&tab_module=' . $this->tab . '&module_name=' . self::MODULE_CODE;
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
		$language_code = Configuration::get( self::PLUGIN_LANGUAGE_CODE );

		$creditCardLogo = explode( ',', Configuration::get( self::PLUGIN_PAYMENT_METHOD_LOGO ) );

		$payment_method_title = $this->getTranslatedModuleConfig(self::PLUGIN_PAYMENT_METHOD_TITLE);
		$payment_method_desc  = $this->getTranslatedModuleConfig(self::PLUGIN_PAYMENT_METHOD_DESC);
		$popup_title          = $this->getTranslatedModuleConfig(self::PLUGIN_POPUP_TITLE);
		$popup_description    = $this->getTranslatedModuleConfig(self::PLUGIN_POPUP_DESC);

		if ( empty( $payment_method_title ) ) {
			$this->context->controller->errors[ $language_code . '_' . self::PLUGIN_PAYMENT_METHOD_TITLE ] = $this->l( 'Payment method title required!' );
		}

		if ( Configuration::get( self::PLUGIN_TRANSACTION_MODE ) == 'test' ) {
			$validationAppKeyMessage = $this->validateAppKeyField(Configuration::get( self::PLUGIN_TEST_SECRET_KEY ),'test');
			if ($validationAppKeyMessage && empty($this->context->controller->errors[self::PLUGIN_TEST_SECRET_KEY])) {
				$this->context->controller->errors[self::PLUGIN_TEST_SECRET_KEY] = $validationAppKeyMessage;
			}
			$validationPublicKeyMessage = $this->validatePublicKeyField(Configuration::get( self::PLUGIN_TEST_PUBLIC_KEY ),'test');
			if ($validationPublicKeyMessage && empty($this->context->controller->errors[self::PLUGIN_TEST_PUBLIC_KEY])) {
				$this->context->controller->errors[self::PLUGIN_TEST_PUBLIC_KEY] = $validationPublicKeyMessage;
			}
		} elseif ( Configuration::get( self::PLUGIN_TRANSACTION_MODE) == 'live' ) {
			$validationAppKeyMessage = $this->validateAppKeyField(Configuration::get( self::PLUGIN_LIVE_SECRET_KEY ),'live');
			if ($validationAppKeyMessage && empty($this->context->controller->errors[self::PLUGIN_LIVE_SECRET_KEY])) {
				$this->context->controller->errors[self::PLUGIN_LIVE_SECRET_KEY] = $validationAppKeyMessage;
			}
			$validationPublicKeyMessage = $this->validatePublicKeyField(Configuration::get( self::PLUGIN_LIVE_PUBLIC_KEY ),'live');
			if ($validationPublicKeyMessage && empty($this->context->controller->errors[self::PLUGIN_LIVE_PUBLIC_KEY])) {
				$this->context->controller->errors[self::PLUGIN_LIVE_PUBLIC_KEY] = $validationPublicKeyMessage;
			}
		}
		//print_r($this->context->controller->errors);
		//die(Configuration::get(self::PLUGIN_TRANSACTION_MODE));

		return array(
			self::PLUGIN_LANGUAGE_CODE                          => Configuration::get( self::PLUGIN_LANGUAGE_CODE ),
			$language_code . '_' . self::PLUGIN_PAYMENT_METHOD_TITLE => $payment_method_title,
			self::PLUGIN_PAYMENT_METHOD_CREDITCARD_LOGO . '[]'       => $creditCardLogo,
			$language_code . '_' . self::PLUGIN_PAYMENT_METHOD_DESC  => $payment_method_desc,
			$language_code . '_' . self::PLUGIN_POPUP_TITLE          => $popup_title,
			self::PLUGIN_SHOW_POPUP_DESC                        => Configuration::get( self::PLUGIN_SHOW_POPUP_DESC ),
			$language_code . '_' . self::PLUGIN_POPUP_DESC           => $popup_description,
			self::PLUGIN_TRANSACTION_MODE                       => Configuration::get( self::PLUGIN_TRANSACTION_MODE ),
			self::PLUGIN_TEST_PUBLIC_KEY                        => Configuration::get( self::PLUGIN_TEST_PUBLIC_KEY ),
			self::PLUGIN_TEST_SECRET_KEY                        => Configuration::get( self::PLUGIN_TEST_SECRET_KEY ),
			self::PLUGIN_LIVE_PUBLIC_KEY                        => Configuration::get( self::PLUGIN_LIVE_PUBLIC_KEY ),
			self::PLUGIN_LIVE_SECRET_KEY                        => Configuration::get( self::PLUGIN_LIVE_SECRET_KEY ),
			self::PLUGIN_CHECKOUT_MODE                          => Configuration::get( self::PLUGIN_CHECKOUT_MODE ),
			self::PLUGIN_ORDER_STATUS                           => Configuration::get( self::PLUGIN_ORDER_STATUS ),
			self::PLUGIN_STATUS                                 => Configuration::get( self::PLUGIN_STATUS ),
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

	public function hookPaymentOptions( $params ) {
		//ensure plugin key is set
		if ( Configuration::get( self::PLUGIN_TRANSACTION_MODE ) == 'test' ) {
			if ( ! Configuration::get( self::PLUGIN_TEST_PUBLIC_KEY ) || ! Configuration::get( self::PLUGIN_TEST_SECRET_KEY ) ) {
				return false;
			} else {
				$PLUGIN_PUBLIC_KEY = Configuration::get( self::PLUGIN_TEST_PUBLIC_KEY );
				Configuration::updateValue( self::PLUGIN_SECRET_KEY, Configuration::get( self::PLUGIN_TEST_SECRET_KEY ) );
			}
		}

		if ( Configuration::get( self::PLUGIN_TRANSACTION_MODE ) == 'live' ) {
			if ( ! Configuration::get( self::PLUGIN_LIVE_PUBLIC_KEY ) || ! Configuration::get( self::PLUGIN_LIVE_SECRET_KEY ) ) {
				return false;
			} else {
				$PLUGIN_PUBLIC_KEY = Configuration::get( self::PLUGIN_LIVE_PUBLIC_KEY );
				Configuration::updateValue( self::PLUGIN_SECRET_KEY, Configuration::get( self::PLUGIN_LIVE_SECRET_KEY ) );
			}
		}

		if ( ! Configuration::get( self::PLUGIN_TEST_PUBLIC_KEY ) && ! Configuration::get( self::PLUGIN_TEST_SECRET_KEY ) && ! Configuration::get( self::PLUGIN_LIVE_PUBLIC_KEY ) && ! Configuration::get( self::PLUGIN_LIVE_SECRET_KEY ) ) {
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

		$payment_method_title = $this->getTranslatedModuleConfig(self::PLUGIN_PAYMENT_METHOD_TITLE);
		$payment_method_desc  = $this->getTranslatedModuleConfig(self::PLUGIN_PAYMENT_METHOD_DESC);
		$popup_title          = $this->getTranslatedModuleConfig(self::PLUGIN_POPUP_TITLE);

		if ( Configuration::get( self::PLUGIN_SHOW_POPUP_DESC ) == 'yes' ) {
			$popup_description = $this->getTranslatedModuleConfig(self::PLUGIN_POPUP_DESC);
		} else {
			//$popup_description = implode( ", & ", $products_label );
			$popup_description = '';
		}

		$redirect_url = $this->context->link->getModuleLink( self::MODULE_CODE, 'paymentreturn', array(), true, (int) $this->context->language->id );

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
			'active_status'             	 => Configuration::get( self::PLUGIN_TRANSACTION_MODE ),
			'PLUGIN_PUBLIC_KEY'          	 => $PLUGIN_PUBLIC_KEY,
			'PS_SSL_ENABLED'                 => ( Configuration::get( 'PS_SSL_ENABLED' ) ? 'https' : 'http' ),
			'http_host'                      => Tools::getHttpHost(),
			'shop_name'                      => $this->context->shop->name,
			'payment_method_title'           => $payment_method_title,
			'payment_method_creditcard_logo' => explode( ',', Configuration::get( self::PLUGIN_PAYMENT_METHOD_LOGO ) ),
			'payment_method_desc'            => $payment_method_desc,
			'this_plugin_status'			 => Configuration::get( self::PLUGIN_STATUS ),
			'popup_title'                    => $popup_title,
			'popup_description'              => $popup_description,
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
		$newOption->setModuleName( self::MODULE_CODE )
		          ->setCallToActionText( $this->trans( $payment_method_title, array() ) )
		          ->setAction( $this->context->link->getModuleLink( self::MODULE_CODE, 'validation', array(), true ) )
		          ->setAdditionalInformation( $this->display( __FILE__, 'views/templates/hook/payment.tpl' ) );
		$payment_options = array( $newOption );

		return $payment_options;
	}

	private function getTranslatedModuleConfig(string $key)
	{
		$language_code = Configuration::get( self::PLUGIN_LANGUAGE_CODE );
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

	private function paymentReturn( $params ) {
		if ( ! $this->active || ! isset( $params['objOrder'] ) || $params['objOrder']->module != self::MODULE_CODE ) {
			return false;
		}

		if ( isset( $params['objOrder'] ) && Validate::isLoadedObject( $params['objOrder'] ) && isset( $params['objOrder']->valid ) && isset( $params['objOrder']->reference ) ) {
			$this->smarty->assign(
				self::VENDOR_NAME . "_order",
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
		$query = 'INSERT INTO ' . _DB_PREFIX_ . self::VENDOR_NAME . '_admin (`'
					. self::VENDOR_NAME . '_tid`, `order_id`, `payed_amount`, `payed_at`, `captured`) VALUES ("'
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

			$query = 'UPDATE ' . _DB_PREFIX_ . self::VENDOR_NAME . '_admin SET ' . $fieldsStr
						. ' WHERE `' . self::VENDOR_NAME . '_tid`="' . pSQL( $plugin_id_transaction )
						. '" AND `order_id`="' . (int)$order_id . '"';

			return Db::getInstance()->Execute( $query );
		} else {
			return false;
		}
	}

	public function hookDisplayAdminOrder( $params ) {
		$id_order = $params['id_order'];
		$order    = new Order( (int) $id_order );
		if ( $order->module == self::MODULE_CODE ) {
			$order_token        = Tools::getAdminToken( 'AdminOrders' . (int) Tab::getIdFromClassName( 'AdminOrders' ) . (int) $this->context->employee->id );
			$dbModuleTransaction = Db::getInstance()->getRow( 'SELECT * FROM ' . _DB_PREFIX_ . self::VENDOR_NAME .'_admin WHERE order_id = ' . (int) $id_order );
			$this->context->smarty->assign( array(
				'ps_version'         			  => _PS_VERSION_,
				'id_order'           			  => $id_order,
				'order_token'        			  => $order_token,
				self::VENDOR_NAME . "transaction" => $dbModuleTransaction,
				'not_captured_text'	  			  => $this->l('Captured Transaction prior to Refund via ' . ucfirst(self::VENDOR_NAME)),
				'checkbox_text' 	  			  => $this->l('Refund ' . ucfirst(self::VENDOR_NAME))
			) );

			return $this->display( __FILE__, 'views/templates/hook/admin-order.tpl' );
		}
	}

	public function dispErrors( $string = 'Fatal error', $htmlentities = true, Context $context = null ) {
		if ( true ) {
			return ( Tools::htmlentitiesUTF8( ucfirst(self::VENDOR_NAME) . ': ' . Tools::stripslashes( $string ) ) . '<br/>' );
		}

		return Context::getContext()->getTranslator()->trans( 'Fatal error', array(), 'Admin.Notifications.Error' );
	}

	public function hookActionOrderSlipAdd( $params ){
		/* Check if "Refund" checkbox is checked */
		if (Tools::isSubmit('doRefund' .  ucfirst(self::VENDOR_NAME))) {
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
		$dbModuleTransaction = Db::getInstance()->getRow( 'SELECT * FROM ' . _DB_PREFIX_ . self::VENDOR_NAME . '_admin WHERE order_id = ' . (int) $id_order );
		if ( empty( $dbModuleTransaction ) ) {
			return false;
		}

		/* If Capture or Void */
		if ( $order_state->id == (int) Configuration::get( self::PLUGIN_ORDER_STATUS ) || $order_state->id == (int) Configuration::get( 'PS_OS_CANCELED' ) ) {
			/* If custom Captured status  */
			if ( $order_state->id == (int) Configuration::get( self::PLUGIN_ORDER_STATUS ) ) {
				$response = $this->doPaymentAction($id_order,"capture");
			}

			/* If Canceled status */
			if ( $order_state->id == (int) Configuration::get( 'PS_OS_CANCELED' ) ) {
				$response = $this->doPaymentAction($id_order,"void");
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

		if ( Tools::getIsset( 'vieworder' ) && Tools::getIsset( 'id_order' ) && Tools::getIsset( self::VENDOR_NAME . "_action" ) ) {
			$plugin_action = Tools::getValue( self::VENDOR_NAME . "_action" );
			$id_order = (int) Tools::getValue( 'id_order' );
			$response = $this->doPaymentAction($id_order,$plugin_action,true,Tools::getValue( self::VENDOR_NAME . "_amount_to_refund" ));
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
			$sql->from( self::VENDOR_NAME . "_logos", 'PL' );
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
				$target_dir    = _PS_MODULE_DIR_ . self::MODULE_CODE . '/views/img/';
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
					$query = 'INSERT INTO ' . _DB_PREFIX_ . self::VENDOR_NAME . '_logos (`name`, `slug`, `file_name`, `default_logo`, `created_at`)
								VALUES ("' . pSQL( $logo_name ) . '", "' . pSQL( $logo_slug ) . '", "' . pSQL( $file_name ) . '", 0, NOW())';

					if ( Db::getInstance()->Execute( $query ) ) {
						$response = array(
							'status'  => 1,
							'message' => "The file " . basename( $file_name ) . " has been uploaded."
						);
						//Configuration::updateValue(self::PLUGIN_PAYMENT_METHOD_CREDITCARD_LOGO, basename($file_name));
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
			$language_code = ( ! empty( Tools::getvalue( 'lang_code' ) ) ) ? Tools::getvalue( 'lang_code' ) : Configuration::get( self::PLUGIN_LANGUAGE_CODE );
			Configuration::updateValue( self::PLUGIN_LANGUAGE_CODE, $language_code );
			$token = Tools::getAdminToken( 'AdminModules' . (int) Tab::getIdFromClassName( 'AdminModules' ) . (int) $this->context->employee->id );
			$link  = $this->context->link->getAdminLink( 'AdminModules' ) . '&token=' . $token . '&configure=' . self::MODULE_CODE . '&tab_module=' . $this->tab . '&module_name=' . self::MODULE_CODE;
			Tools::redirectAdmin( $link );
		}

		if ( Tools::getValue( 'configure' ) == self::MODULE_CODE ) {
			$this->context->controller->addCSS( $this->_path . 'views/css/backoffice.css' );
		}
	}

	/**
	 * Call action via API.
	 *
	 * @param string $id_order - the order id
	 * @param string $plugin_action - the action to be called.
	 * @param boolean $change_status - change status flag, default false.
	 * @param float $plugin_amount_to_refund - the refund amount, default 0.
	 *
	 * @return mixed
	 */
	 protected function doPaymentAction($id_order, $plugin_action, $change_status = false, $plugin_amount_to_refund = 0){
		$order              = new Order( (int) $id_order );
		$dbModuleTransaction = Db::getInstance()->getRow( 'SELECT * FROM ' . _DB_PREFIX_ . self::VENDOR_NAME . "_admin WHERE order_id = " . (int) $id_order );
		$transactionid      = $dbModuleTransaction[self::VENDOR_NAME . "_tid"];
		Client::setKey( Configuration::get( self::PLUGIN_SECRET_KEY ) );
		$fetch    			= Transaction::fetch( $transactionid );
		$customer 			= new Customer( $order->id_customer );

		switch ( $plugin_action ) {
			case "capture":
				if ( $dbModuleTransaction['captured'] == 'YES' ) {
					$response = array(
						'warning' => 1,
						'message' => $this->dispErrors( $this->l('Transaction already Captured.') ),
					);
				} elseif ( isset( $dbModuleTransaction ) ) {
					$amount   = ( ! empty( $fetch['transaction']['pendingAmount'] ) ) ? (int) $fetch['transaction']['pendingAmount'] : 0;
					$currency = new Currency( (int) $order->id_currency );
					if ( $amount ) {
						/* Capture transaction */
						$data    = array(
							'currency'   => $currency->iso_code,
							'amount'     => $amount,
						);
						$capture = Transaction::capture( $transactionid, $data );

						if ( is_array( $capture ) && ! empty( $capture['error'] ) && $capture['error'] == 1 ) {
							PrestaShopLogger::addLog( $capture['message'] );
							$response = array(
								'error'   => 1,
								'message' => $this->dispErrors( $capture['message'] ),
							);
						} else {
							if ( ! empty( $capture['transaction'] ) ) {
								//Update order status
								if($change_status){
									$order->setCurrentState( (int) Configuration::get( self::PLUGIN_ORDER_STATUS ), $this->context->employee->id );
								}

								/* Update transaction details */
								$fields = array(
									'captured' => 'YES',
								);
								$this->updateTransactionID( $transactionid, (int) $id_order, $fields );

								/* Set message */
								$message = 'Trx ID: ' . $transactionid . '
								Authorized Amount: ' . $capture['transaction']['amount'] . '
								Captured Amount: ' . $capture['transaction']['capturedAmount'] . '
								Order time: ' . $capture['transaction']['created'] . '
								Currency code: ' . $capture['transaction']['currency'];

								$message = strip_tags( $message, '<br>' );
								if ( Validate::isCleanHtml( $message ) ) {
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

								/* Set response */
								$response = array(
									'success' => 1,
									'message' => $this->dispErrors( $this->l('Transaction successfully Captured.') ),
								);
							} else {
								if ( ! empty( $capture[0]['message'] ) ) {
									$response = array(
										'warning' => 1,
										'message' => $this->dispErrors( $capture[0]['message'] ),
									);
								} else {
									$response = array(
										'error'   => 1,
										'message' => $this->dispErrors( $this->l('Opps! An error occured while Capture.') ),
									);
								}
							}
						}
					} else {
						$response = array(
							'error'   => 1,
							'message' => $this->dispErrors( $this->l('Invalid amount to Capture.') ),
						);
					}
				} else {
					$response = array(
						'error'   => 1,
						'message' => $this->dispErrors( $this->l('Invalid ' . ucfirst(self::VENDOR_NAME) . ' Transaction.') ),
					);
				}

				break;

			case "refund":
				if ( $dbModuleTransaction['captured'] == 'NO' ) {
					$response = array(
						'warning' => 1,
						'message' => $this->dispErrors( $this->l('You need to Captured Transaction prior to Refund.') ),
					);
				} elseif ( isset( $dbModuleTransaction ) ) {

					$currency = new Currency( (int) $order->id_currency );

					if ( ! Validate::isPrice( $plugin_amount_to_refund ) ) {
						$response = array(
							'error'   => 1,
							'message' => $this->dispErrors( $this->l('Invalid amount to Refund.') ),
						);
					} else {
						/* Refund transaction */
						$amount              = $plugin_amount_to_refund;
						$data                = array(
							'descriptor' => '',
							'amount'     => $amount,
						);

						$refund              = Transaction::refund( $transactionid, $data );

						if ( is_array( $refund ) && ! empty( $refund['error'] ) && $refund['error'] == 1 ) {
							PrestaShopLogger::addLog( $refund['message'] );
							$response = array(
								'error'   => 1,
								'message' => $this->dispErrors( $refund['message'] ),
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
								$this->updateTransactionID( $transactionid, (int) $id_order, $fields );

								/* Set message */
								$message = 'Trx ID: ' . $transactionid . '
									Authorized Amount: ' . $refund['transaction']['amount'] . '
									Refunded Amount: ' . $refund['transaction']['refundedAmount'] . '
									Order time: ' . $refund['transaction']['created'] . '
									Currency code: ' . $refund['transaction']['currency'];

								$message = strip_tags( $message, '<br>' );
								if ( Validate::isCleanHtml( $message ) ) {
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

								/* Set response */
								$response = array(
									'success' => 1,
									'message' => $this->dispErrors( $this->l('Transaction successfully Refunded.') ),
								);
							} else {
								if ( ! empty( $refund[0]['message'] ) ) {
									$response = array(
										'warning' => 1,
										'message' => $this->dispErrors( $refund[0]['message'] ),
									);
								} else {
									$response = array(
										'error'   => 1,
										'message' => $this->dispErrors( $this->l('Opps! An error occured while Refund.') ),
									);
								}
							}
						}
					}
				} else {
					$response = array(
						'error'   => 1,
						'message' => $this->dispErrors( $this->l('Invalid ' . ucfirst(self::VENDOR_NAME) . ' Transaction.') ),
					);
				}

				break;

			case "void":
				if ( $dbModuleTransaction['captured'] == 'YES' ) {
					$response = array(
						'warning' => 1,
						'message' => $this->dispErrors( $this->l('You can\'t Void transaction now . It\'s already Captured, try to Refund.') ),
					);
				} elseif ( isset( $dbModuleTransaction ) ) {
					$currency = new Currency( (int) $order->id_currency );
					/* Void transaction */
					$amount = (int) $fetch['transaction']['amount'] - $fetch['transaction']['refundedAmount'];
					$data   = array(
						'amount' => $amount,
					);
					$void   = Transaction::void( $transactionid, $data );

					if ( is_array( $void ) && ! empty( $void['error'] ) && $void['error'] == 1 ) {
						PrestaShopLogger::addLog( $void['message'] );
						$response = array(
							'error'   => 1,
							'message' => $this->dispErrors( $void['message'] ),
						);
					} else {
						if ( ! empty( $void['transaction'] ) ) {
							//Update order status
							if($change_status){
								$order->setCurrentState( (int) Configuration::get( 'PS_OS_CANCELED' ), $this->context->employee->id );
							}

							/* Set message */
							$message = 'Trx ID: ' . $transactionid . '
									Authorized Amount: ' . $void['transaction']['amount'] . '
									Refunded Amount: ' . $void['transaction']['refundedAmount'] . '
									Order time: ' . $void['transaction']['created'] . '
									Currency code: ' . $void['transaction']['currency'];

							$message = strip_tags( $message, '<br>' );
							if ( Validate::isCleanHtml( $message ) ) {
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

							/* Set response */
							$response = array(
								'success' => 1,
								'message' => $this->dispErrors( $this->l('Transaction successfully Voided.') ),
							);
						} else {
							if ( ! empty( $void[0]['message'] ) ) {
								$response = array(
									'warning' => 1,
									'message' => $this->dispErrors( $void[0]['message'] ),
								);
							} else {
								$response = array(
									'error'   => 1,
									'message' => $this->dispErrors( $this->l('Opps! An error occured while Void.') ),
								);
							}
						}
					}
				} else {
					$response = array(
						'error'   => 1,
						'message' => $this->dispErrors( $this->l('Invalid ' . ucfirst(self::VENDOR_NAME) . ' Transaction.') ),
					);
				}
				break;
		}
		return $response;
	}
}
