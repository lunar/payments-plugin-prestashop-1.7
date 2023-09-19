<?php

namespace Lunar\Payment\methods;


use \Tools;
use \Context;
use \OrderState;
use \Configuration;
use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

/**
 * 
 */
abstract class AbstractLunarMethod
{
    public string $METHOD_NAME;
    public string $DESCRIPTION;
    public string $FILE_NAME;

	// public string $LANGUAGE_CODE;
	public string $METHOD_STATUS;
	public string $TRANSACTION_MODE;
	public string $LIVE_PUBLIC_KEY;
	public string $LIVE_SECRET_KEY;
	public string $TEST_PUBLIC_KEY;
	public string $TEST_SECRET_KEY;
	public string $LOGO_URL;
	public string $CHECKOUT_MODE;
	public string $ORDER_STATUS;
	public string $METHOD_DESCRIPTION;
	public string $METHOD_TITLE;
	public string $SHOP_TITLE;

    public $module;
    public Context $context;
    protected $tabName;
    protected $validationPublicKeys = ['live' => [], 'test' => []];

    /**
     * 
     */
	protected function __construct($module) {
        $this->module = $module;
		$this->context = Context::getContext();

        $configKeyBegin = 'LUNAR_' . $this->METHOD_NAME;

        $this->METHOD_STATUS      = $configKeyBegin . '_METHOD_STATUS';
        $this->TRANSACTION_MODE   = $configKeyBegin . '_TRANSACTION_MODE';
        $this->LIVE_PUBLIC_KEY    = $configKeyBegin . '_LIVE_PUBLIC_KEY';
        $this->LIVE_SECRET_KEY    = $configKeyBegin . '_LIVE_SECRET_KEY';
        $this->TEST_PUBLIC_KEY    = $configKeyBegin . '_TEST_PUBLIC_KEY';
        $this->TEST_SECRET_KEY    = $configKeyBegin . '_TEST_SECRET_KEY';
        $this->LOGO_URL           = $configKeyBegin . '_LOGO_URL';
        $this->CHECKOUT_MODE      = $configKeyBegin . '_CHECKOUT_MODE';
        $this->ORDER_STATUS       = $configKeyBegin . '_ORDER_STATUS';
        $this->METHOD_DESCRIPTION = $configKeyBegin . '_METHOD_DESCRIPTION';
        $this->METHOD_TITLE       = $configKeyBegin . '_METHOD_TITLE';
        $this->SHOP_TITLE         = $configKeyBegin . '_SHOP_TITLE';
	}

    public function getPaymentOption()
    {
        $paymentOption = new PaymentOption();

        $this->METHOD_NAME == LunarMobilePayMethod::METHOD_NAME 
            // ? $paymentOption->setLogo(Media::getMediaPath(_PS_MODULE_DIR_ . $this->name . '/views/img/mobilepay-logo.png')) // @TODO test this on staging
            ? $paymentOption->setLogo(('/modules/lunarpayment/views/img/mobilepay-logo.png')) 
            : null;

		return $paymentOption->setModuleName( $this->module->name )
					->setCallToActionText(Configuration::get($this->METHOD_TITLE))
					->setAction( $this->context->link->getModuleLink( $this->module->name, $this->FILE_NAME, [], true ) )
					->setAdditionalInformation( $this->context->smarty->fetch('module:lunarpayment/views/templates/hook/' . $this->FILE_NAME . '.tpl' ) );
		
    }

    protected function install()
    {
		Configuration::updateValue( $this->METHOD_STATUS, 'enabled' );
		Configuration::updateValue( $this->TRANSACTION_MODE, 'live' ); // defaults to live mode
		Configuration::updateValue( $this->TEST_SECRET_KEY, '' );
		Configuration::updateValue( $this->TEST_PUBLIC_KEY, '' );
		Configuration::updateValue( $this->LIVE_SECRET_KEY, '' );
		Configuration::updateValue( $this->LIVE_PUBLIC_KEY, '' );
		Configuration::updateValue( $this->LOGO_URL, '' );
		Configuration::updateValue( $this->CHECKOUT_MODE, 'delayed' );
		Configuration::updateValue( $this->ORDER_STATUS, Configuration::get( $this->ORDER_STATUS ) );
		Configuration::updateValue( $this->METHOD_TITLE, $this->METHOD_NAME );
		Configuration::updateValue( $this->METHOD_DESCRIPTION, $this->DESCRIPTION );
		Configuration::updateValue( $this->SHOP_TITLE, Configuration::get( 'PS_SHOP_NAME' ) ?? 'Payment' );

		// $language_code = $this->context->language->iso_code;
		// Configuration::updateValue( $this->LANGUAGE_CODE, $language_code );
		// Configuration::updateValue( $language_code . '_' . $this->METHOD_TITLE, 'Cards' );
		// Configuration::updateValue( $language_code . '_' . $this->METHOD_DESCRIPTION, 'Secure payment with card via © Lunar' );
		// Configuration::updateValue( $language_code . '_' . $this->SHOP_TITLE, $shop_title );
    }

    protected function uninstall()
    {
        //Fetch all languages and delete plugin configurations which has language iso_code as prefix
        // $languages = Language::getLanguages( true, $this->context->shop->id );
        // foreach ( $languages as $language ) {
        // 	$language_code = $language['iso_code'];
        // 	Configuration::deleteByName( $language_code . '_' . $this->METHOD_TITLE );
        // 	Configuration::deleteByName( $language_code . '_' . $this->METHOD_DESCRIPTION );
        // 	Configuration::deleteByName( $language_code . '_' . $this->SHOP_TITLE );
        // }

        return (
            Configuration::deleteByName( $this->METHOD_STATUS )
            && Configuration::deleteByName( $this->TRANSACTION_MODE )
            && Configuration::deleteByName( $this->TEST_SECRET_KEY )
            && Configuration::deleteByName( $this->TEST_PUBLIC_KEY )
            && Configuration::deleteByName( $this->LIVE_SECRET_KEY )
            && Configuration::deleteByName( $this->LIVE_PUBLIC_KEY )
            && Configuration::deleteByName( $this->LOGO_URL )
            && Configuration::deleteByName( $this->CHECKOUT_MODE )
            && Configuration::deleteByName( $this->ORDER_STATUS )
            && Configuration::deleteByName( $this->METHOD_TITLE )
            && Configuration::deleteByName( $this->METHOD_DESCRIPTION )
            && Configuration::deleteByName( $this->SHOP_TITLE )
        );
    }
    
    /**
	 * 
	 */
	protected function getConfiguration()
    {
        // $language_code = $this->context->language->iso_code;

        return [
            $this->METHOD_STATUS 		 => Configuration::get( $this->METHOD_STATUS ),
			$this->TRANSACTION_MODE  	 => Configuration::get( $this->TRANSACTION_MODE ),
			$this->TEST_PUBLIC_KEY   	 => Configuration::get( $this->TEST_PUBLIC_KEY ),
			$this->TEST_SECRET_KEY   	 => Configuration::get( $this->TEST_SECRET_KEY ),
			$this->LIVE_PUBLIC_KEY   	 => Configuration::get( $this->LIVE_PUBLIC_KEY ),
			$this->LIVE_SECRET_KEY   	 => Configuration::get( $this->LIVE_SECRET_KEY ),
			$this->LOGO_URL    			 => Configuration::get( $this->LOGO_URL ),
			$this->CHECKOUT_MODE     	 => Configuration::get( $this->CHECKOUT_MODE ),
			$this->ORDER_STATUS      	 => Configuration::get( $this->ORDER_STATUS ),
			$this->METHOD_TITLE  		 => Configuration::get($this->METHOD_TITLE),
			$this->METHOD_DESCRIPTION    => Configuration::get($this->METHOD_DESCRIPTION),
			$this->SHOP_TITLE            => Configuration::get($this->SHOP_TITLE),
            
			// $this->LANGUAGE_CODE     	=> Configuration::get( $this->LANGUAGE_CODE ),
			// $language_code . '_' . $this->METHOD_TITLE => Configuration::get($this->METHOD_TITLE),
			// $language_code . '_' . $this->METHOD_DESCRIPTION  => Configuration::get($this->METHOD_DESCRIPTION),
			// $language_code . '_' . $this->SHOP_TITLE           => Configuration::get($this->SHOP_TITLE),
		];
	}

        
    /**
	 * 
	 */
	protected function updateConfiguration() {
        $isSaveAllowed = true;
        
        // $language_code = Configuration::get( $this->LANGUAGE_CODE );
        // $payment_method_title = Tools::getvalue( $language_code . '_' . $this->METHOD_TITLE ) ?? '';
        // $payment_method_desc  = Tools::getvalue( $language_code . '_' . $this->METHOD_DESCRIPTION ) ?? '';
        // $shop_title = Tools::getvalue( $language_code . '_' . $this->SHOP_TITLE ) ?? '';

        $methodStatus = Tools::getValue( $this->METHOD_STATUS );
        if ('disabled' == $methodStatus) {
            Configuration::updateValue( $this->METHOD_STATUS, $methodStatus );
            return $isSaveAllowed;
        }
        Configuration::updateValue( $this->METHOD_STATUS, $methodStatus );

        $payment_method_title = Tools::getvalue( $this->METHOD_TITLE ) ?? '';
        $payment_method_desc  = Tools::getvalue( $this->METHOD_DESCRIPTION ) ?? '';
        $shop_title = Tools::getvalue( $this->SHOP_TITLE ) ?? '';
        $logoURL = Tools::getvalue( $this->LOGO_URL ) ?? '';

        if ( empty( $payment_method_title ) ) {
            // $this->context->controller->errors[ $language_code . '_' . $this->METHOD_TITLE ] = $this->l( 'Payment method title required!' );
            $this->context->controller->errors[ $this->METHOD_TITLE ] = $this->errorMessage( 'Payment method title is required!' );
            // $payment_method_title = $this->getTranslatedModuleConfig($this->METHOD_TITLE);
            $payment_method_title = Configuration::get($this->METHOD_TITLE);
            $isSaveAllowed = false;
        }

        if ( !$this->validateLogoURL($logoURL) ) {
            $isSaveAllowed = false;
        }

        // @TODO remove these 4 lines and activate validation
        $test_secret_key = Tools::getvalue( $this->TEST_SECRET_KEY ) ?? '';
        $test_public_key = Tools::getvalue( $this->TEST_PUBLIC_KEY ) ?? '';
        $live_secret_key = Tools::getvalue( $this->LIVE_SECRET_KEY ) ?? '';
        $live_public_key = Tools::getvalue( $this->LIVE_PUBLIC_KEY ) ?? '';

        // $isSaveAllowed = $this->validateKeys();

        // Configuration::updateValue( $this->LANGUAGE_CODE, $language_code );

        

        $transactionMode = Tools::getvalue( $this->TRANSACTION_MODE );
        Configuration::updateValue( $this->TRANSACTION_MODE, $transactionMode );
        
        if ('test' == $transactionMode) {
            Configuration::updateValue( $this->TEST_PUBLIC_KEY, $test_public_key );
            Configuration::updateValue( $this->TEST_SECRET_KEY, $test_secret_key );
        }
        if ('live' == $transactionMode) {
            Configuration::updateValue( $this->LIVE_PUBLIC_KEY, $live_public_key );
            Configuration::updateValue( $this->LIVE_SECRET_KEY, $live_secret_key );
        }
        
        Configuration::updateValue( $this->LOGO_URL, Tools::getValue( $this->LOGO_URL ) );

        Configuration::updateValue( $this->CHECKOUT_MODE, Tools::getValue( $this->CHECKOUT_MODE ) );
        Configuration::updateValue( $this->ORDER_STATUS, Tools::getValue( $this->ORDER_STATUS ) );
        Configuration::updateValue( $this->METHOD_TITLE, $payment_method_title );
        Configuration::updateValue( $this->METHOD_DESCRIPTION, $payment_method_desc );
        Configuration::updateValue( $this->SHOP_TITLE, $shop_title );
        
        // Configuration::updateValue( $language_code . '_' . $this->METHOD_TITLE, $payment_method_title );
        // Configuration::updateValue( $language_code . '_' . $this->METHOD_DESCRIPTION, $payment_method_desc );
        // Configuration::updateValue( $language_code . '_' . $this->SHOP_TITLE, $shop_title );

        return $isSaveAllowed;
	}

    /**
	 * @return bool
	 */
	private function validateLogoURL(string $url)
	{
		$errorMessage = '';

        if (! $url) {
            $errorMessage = $this->errorMessage('Logo URL is required');
		
		} elseif (! preg_match('/^https:\/\//', $url)) {
            $errorMessage = $this->errorMessage('The image url must begin with https://.');
		
		} elseif (!$this->fileExists($url)) {
            $errorMessage = $this->errorMessage('The image file doesn\'t seem to be valid');
		}
		if ($errorMessage) {
			$this->context->controller->errors[ $this->LOGO_URL ] = $errorMessage;
			return false;
		}
		
		return true;
	}

    /**
     * @return bool
     */
    private function fileExists($url)
    {
        $valid = true;

        $c = curl_init();
        curl_setopt($c, CURLOPT_URL, $url);
        curl_setopt($c, CURLOPT_HEADER, 1);
        curl_setopt($c, CURLOPT_NOBODY, 1);
        curl_setopt($c, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($c, CURLOPT_FRESH_CONNECT, 1);
        
        if(!curl_exec($c)){
            $valid = false;
        }

        curl_close($c);

        return $valid;
    }

	/**
	 * 
	 */
	public function isConfigured()
	{
		if ('test' == Configuration::get($this->TRANSACTION_MODE)) {
			return Configuration::get($this->TEST_PUBLIC_KEY) && Configuration::get($this->TEST_SECRET_KEY);
		} else {
			return Configuration::get($this->LIVE_PUBLIC_KEY) && Configuration::get($this->LIVE_SECRET_KEY);
		}
	}

	/**
	 * 
	 */
    protected function validateKeys()
    {
        $isSaveAllowed = true;
        
        // $test_secret_key = Tools::getvalue( $this->TEST_SECRET_KEY ) ?? '';
        // $test_public_key = Tools::getvalue( $this->TEST_PUBLIC_KEY ) ?? '';
        // $live_secret_key = Tools::getvalue( $this->LIVE_SECRET_KEY ) ?? '';
        // $live_public_key = Tools::getvalue( $this->LIVE_PUBLIC_KEY ) ?? '';

        // if ('test' == $transactionMode) {
        // 	/** Load db value or set it to empty **/
        // 	$test_secret_key = Configuration::get( $this->TEST_SECRET_KEY ) ?? '';
        // 	$validationPublicKeyMessage = $this->validateAppKeyField(Tools::getvalue( $this->TEST_SECRET_KEY ), 'test');
        // 	if($validationPublicKeyMessage){
        // 		$this->context->controller->errors[$this->TEST_SECRET_KEY] = $validationPublicKeyMessage;
        // 		$isSaveAllowed = false;
        // 	} else{
        // 		$test_secret_key = Tools::getvalue( $this->TEST_SECRET_KEY ) ?? '';
        // 	}

        // 	/** Load db value or set it to empty **/
        // 	$test_public_key = Configuration::get( $this->TEST_PUBLIC_KEY ) ?? '';
        // 	$validationAppKeyMessage = $this->validatePublicKeyField(Tools::getvalue( $this->TEST_PUBLIC_KEY ), 'test');
        // 	if($validationAppKeyMessage){
        // 		$this->context->controller->errors[$this->TEST_PUBLIC_KEY] = $validationAppKeyMessage;
        // 		$isSaveAllowed = false;
        // 	} else{
        // 		$test_public_key = Tools::getvalue( $this->TEST_PUBLIC_KEY ) ?? '';
        // 	}

        // } elseif ('live' == $transactionMode) {
        // 	/** Load db value or set it to empty **/
        // 	$live_secret_key = Configuration::get( $this->LIVE_SECRET_KEY ) ?? '';
        // 	$validationPublicKeyMessage = $this->validateAppKeyField(Tools::getvalue( $this->LIVE_SECRET_KEY ), 'live');
        // 	if($validationPublicKeyMessage){
        // 		$this->context->controller->errors[$this->LIVE_SECRET_KEY] = $validationPublicKeyMessage;
        // 		$isSaveAllowed = false;
        // 	} else{
        // 		$live_secret_key = Tools::getvalue( $this->LIVE_SECRET_KEY ) ?? '';
        // 	}

        // 	/** Load db value or set it to empty **/
        // 	$live_public_key = Configuration::get( $this->LIVE_PUBLIC_KEY ) ?? '';
        // 	$validationAppKeyMessage = $this->validatePublicKeyField(Tools::getvalue( $this->LIVE_PUBLIC_KEY ), 'live');
        // 	if($validationAppKeyMessage){
        // 		$this->context->controller->errors[$this->LIVE_PUBLIC_KEY] = $validationAppKeyMessage;
        // 		$isSaveAllowed = false;
        // 	} else{
        // 		$live_public_key = Tools::getvalue( $this->LIVE_PUBLIC_KEY ) ?? '';
        // 	}
        // }

        return $isSaveAllowed;
    }

    
	// /**
	//  * Validate the App key.
	//  *
	//  * @param string $value - the value of the input.
	//  * @param string $mode - the transaction mode 'test' | 'live'.
	//  *
	//  * @return string - the error message
	//  */
	// public function validateAppKeyField( $value, $mode ) {
	// 	/** Check if the key value is empty **/
	// 	if ( ! $value ) {
	// 		return $this->l( 'The App Key is required!' );
	// 	}
	// 	/** Load the client from API**/
	// 	$apiClient = new ApiClient( $value );
	// 	try {
	// 		/** Load the identity from API**/
	// 		$identity = $apiClient->apps()->fetch();
	// 	} catch ( ApiException $exception ) {
	// 		PrestaShopLogger::addLog( $exception );
	// 		return $this->l( "The App Key doesn't seem to be valid!");
	// 	}

	// 	try {
	// 		/** Load the merchants public keys list corresponding for current identity **/
	// 		$merchants = $apiClient->merchants()->find( $identity['id'] );
	// 		if ( $merchants ) {
	// 			foreach ( $merchants as $merchant ) {
	// 				/** Check if the key mode is the same as the transaction mode **/
	// 				if(($mode == 'test' && $merchant['test']) || ($mode != 'test' && !$merchant['test'])){
	// 					$this->validationPublicKeys[$mode][] = $merchant['key'];
	// 				}
	// 			}
	// 		}
	// 	} catch ( ApiException $exception ) {
	// 		PrestaShopLogger::addLog( $exception );
	// 	}

	// 	/** Check if public keys array for the current mode is populated **/
	// 	if ( empty( $this->validationPublicKeys[$mode] ) ) {
	// 		/** Generate the error based on the current mode **/
	// 		// $error = $this->l( 'The '.$mode .' App Key is not valid or set to '.array_values(array_diff(array_keys($this->validationPublicKeys), array($mode)))[0].' mode!' );
	// 		$error = $this->l( 'The App Key is not valid or set to different mode!' );
	// 		PrestaShopLogger::addLog( $error );
	// 		return $error;
	// 	}
	// }

	// /**
	//  * Validate the Public key.
	//  *
	//  * @param string $value - the value of the input.
	//  * @param string $mode - the transaction mode 'test' | 'live'.
	//  *
	//  * @return mixed
	//  * @throws Exception
	//  */
	// public function validatePublicKeyField($value, $mode) {
	// 	/** Check if the key value is not empty **/
	// 	if ( ! $value ) {
	// 		return $this->l( 'The Public Key is required!' );
	// 	}
	// 	/** Check if the local stored public keys array is empty OR the key is not in public keys list **/
	// 	if ( empty( $this->validationPublicKeys[$mode] ) || ! in_array( $value, $this->validationPublicKeys[$mode] ) ) {
	// 		$error = $this->l( 'The Public Key doesn\'t seem to be valid!' );
	// 		PrestaShopLogger::addLog( $error );
	// 		return $error;
	// 	}
	// }

    protected function getFormFields()
    {
		// $languages_array = [];
		//Fetch all active languages
		// $language_code = Configuration::get( $this->LANGUAGE_CODE );
		// $languages = Language::getLanguages( true, $this->context->shop->id );
		// foreach ( $languages as $language ) {
		// 	$data = array(
		// 		'id_option' => $language['iso_code'],
		// 		'name'      => $language['name']
		// 	);
		// 	array_push( $languages_array, $data );
		// }

		//Fetch Status list
        $statuses_array  = [];
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

        return [
			'form' => array(
				'legend' => array(
					'title' => $this->l( 'Lunar Payments Settings' ),
					'icon'  => 'icon-cogs'
				),
				'input'  => array(
					// array(
                    //     'type' => 'select',
                    //     'tab'  => $this->tabName,
                    //     'label' => '<span data-toggle="tooltip" title="'.$this->l('Language').'">'.$this->l('Language').'<i class="process-icon-help-new help-icon" aria-hidden="true"></i></span>',
                    //     'name' => $this->LANGUAGE_CODE,
                    //     'class' => "lunar-config lunar-language",
                    //     'options' => array(
                    //         'query' => $languages_array,
                    //         'id' => 'id_option',
                    //         'name' => 'name'
                    //     ),
                    // ),
					array(
						'type'    => 'select',
                        'tab'     => $this->tabName,
						'lang'    => true,
						'name'    => $this->METHOD_STATUS,
						'label'   => $this->l( 'Status' ),
						'class'   => "lunar-config",
						'options' => array(
							'query' => array(
								array(
									'id_option' => 'enabled',
									'name'      => 'Enabled',
								),
								array(
									'id_option' => 'disabled',
									'name'      => 'Disabled',
								),
							),
							'id'    => 'id_option',
							'name'  => 'name'
						)
					),
					array(
						'type'     => 'select',
                        'tab'      => $this->tabName,
						'lang'     => true,
						'label'    => '<span data-toggle="tooltip" title="' . $this->l( 'In test mode, you can create a successful transaction with the card number 4111 1111 1111 1111 with any CVC and a valid expiration date.' ) . '">' . $this->l( 'Transaction mode' ) . '<i class="process-icon-help-new help-icon" aria-hidden="true"></i></span>',
						'name'     => $this->TRANSACTION_MODE,
						'class'    => "lunar-config",
						'options'  => array(
							'query' => array(
								array(
									'id_option' => 'live',
									'name'      => 'Live',
								),
								array(
									'id_option' => 'test',
									'name'      => 'Test',
								),
							),
							'id'    => 'id_option',
							'name'  => 'name',
						),
						'required' => true,
					),
					array(
						'type'     => 'text',
                        'tab'      => $this->tabName,
						'label'    => '<span data-toggle="tooltip" title="' . $this->l( 'Get it from your Lunar dashboard' ) . '">' . $this->l( 'Test mode App Key' ) . '<i class="process-icon-help-new help-icon" aria-hidden="true"></i></span>',
						'name'     => $this->TEST_SECRET_KEY,
						'class'    => "lunar-config",
						'required' => true,
					),
					array(
						'type'     => 'text',
                        'tab'      => $this->tabName,
						'label'    => '<span data-toggle="tooltip" title="' . $this->l( 'Get it from your Lunar dashboard' ) . '">' . $this->l( 'Test mode Public Key' ) . '<i class="process-icon-help-new help-icon" aria-hidden="true"></i></span>',
						'name'     => $this->TEST_PUBLIC_KEY,
						'class'    => "lunar-config",
						'required' => true,
					),
					array(
						'type'     => 'text',
                        'tab'      => $this->tabName,
						'label'    => '<span data-toggle="tooltip" title="' . $this->l( 'Get it from your Lunar dashboard' ) . '">' . $this->l( 'App Key' ) . '<i class="process-icon-help-new help-icon" aria-hidden="true"></i></span>',
						'name'     => $this->LIVE_SECRET_KEY,
						'class'    => "lunar-config",
						'required' => true,
					),
					array(
						'type'     => 'text',
                        'tab'      => $this->tabName,
						'label'    => '<span data-toggle="tooltip" title="' . $this->l( 'Get it from your Lunar dashboard' ) . '">' . $this->l( 'Public Key' ) . '<i class="process-icon-help-new help-icon" aria-hidden="true"></i></span>',
						'name'     => $this->LIVE_PUBLIC_KEY,
						'class'    => "lunar-config",
						'required' => true,
					),
					array(
						'type'     => 'text',
                        'tab'      => $this->tabName,
						'label'    => '<span data-toggle="tooltip" title="' . $this->l( 'Must be a link begins with "https://" to a JPG,JPEG or PNG file' ) . '">' . $this->l( 'Logo URL' ) . '<i class="process-icon-help-new help-icon" aria-hidden="true"></i></span>',
						'name'     => $this->LOGO_URL,
						'class'    => "lunar-config",
						'required' => true,
					),
					array(
						'type'     => 'select',
                        'tab'      => $this->tabName,
						'lang'     => true,
						'label'    => '<span data-toggle="tooltip" title="' . $this->l( 'If you deliver your product instantly (e.g. a digital product), choose Instant mode. If not, use Delayed' ) . '">' . $this->l( 'Capture mode' ) . '<i class="process-icon-help-new help-icon" aria-hidden="true"></i></span>',
						'name'     => $this->CHECKOUT_MODE,
						'class'    => "lunar-config",
						'options'  => array(
							'query' => array(
								array(
									'id_option' => 'delayed',
									'name'      => $this->l( 'Delayed' ),
								),
								array(
									'id_option' => 'instant',
									'name'      => $this->l( 'Instant' ),
								),
							),
							'id'    => 'id_option',
							'name'  => 'name'
						),
						'required' => true,
					),
					array(
						'type'    => 'select',
                        'tab'      => $this->tabName,
						'lang'    => true,
						'label'   => '<span data-toggle="tooltip" title="' . $this->l( 'The transaction will be captured once the order has the chosen status' ) . '">' . $this->l( 'Capture on order status (delayed mode)' ) . '<i class="process-icon-help-new help-icon" aria-hidden="true"></i></span>',
						'name'    => $this->ORDER_STATUS,
						'class'   => "lunar-config",
						'options' => array(
							'query' => $statuses_array,
							'id'    => 'id_option',
							'name'  => 'name'
						)
					),
					array(
						'type'     => 'text',
                        'tab'      => $this->tabName,
						'label'    => '<span data-toggle="tooltip" title="' . $this->l( 'Payment method title' ) . '">' . $this->l( 'Payment method title' ) . '<i class="process-icon-help-new help-icon" aria-hidden="true"></i></span>',
						// 'name'     => $language_code . '_' . $this->METHOD_TITLE,
						'name'     => $this->METHOD_TITLE,
						'class'    => "lunar-config",
						'required' => true,
					),
					array(
						'type'  => 'textarea',
                        'tab'      => $this->tabName,
						'label' => '<span data-toggle="tooltip" title="' . $this->l( 'Description' ) . '">' . $this->l( 'Description' ) . '<i class="process-icon-help-new help-icon" aria-hidden="true"></i></span>',
						// 'name'  => $language_code . '_' . $this->METHOD_DESCRIPTION,
						'name'  => $this->METHOD_DESCRIPTION,
						'class' => "lunar-config",
						//'required' => true,
					),
					array(
						'type'  => 'text',
                        'tab'      => $this->tabName,
						'label' => '<span data-toggle="tooltip" title="' . $this->l( 'The text shown in the page where the customer is redirected' ) . '">' . $this->l( 'Shop title' ) . '<i class="process-icon-help-new help-icon" aria-hidden="true"></i></span>',
						// 'name'  => $language_code . '_' . $this->SHOP_TITLE,
						'name'  => $this->SHOP_TITLE,
						'class' => "lunar-config",
						//'required' => true,
					),
				),
				'submit' => array(
					'title' => $this->l( 'Save' ),
				),

			),
        ];
    }

    /**
     * 
     */
    protected function l($string)
    {
        return $this->module->l($string);
    }

    /**
     * 
     */
    protected function errorMessage($string)
    {
        return $this->l($string) . " ($this->METHOD_NAME)";
    }
}