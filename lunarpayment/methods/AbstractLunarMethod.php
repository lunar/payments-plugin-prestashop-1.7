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
	public string $APP_KEY;
	public string $PUBLIC_KEY;
	public string $LOGO_URL;
	public string $CHECKOUT_MODE;
	public string $ORDER_STATUS;
	public string $METHOD_DESCRIPTION;
	public string $METHOD_TITLE;
	public string $SHOP_TITLE;

    public $module;
    public Context $context;
    protected $tabName;

	// @TODO refactor this when validation will be ready
    protected array $validationPublicKeys = ['live' => [], 'test' => []];

    /**
     * 
     */
	protected function __construct($module) {
        $this->module = $module;
		$this->context = Context::getContext();
		
        $configKeyBegin = 'LUNAR_' . $this->METHOD_NAME;

        $this->METHOD_STATUS      = $configKeyBegin . '_METHOD_STATUS';
        $this->APP_KEY    	      = $configKeyBegin . '_APP_KEY';
        $this->PUBLIC_KEY    	  = $configKeyBegin . '_PUBLIC_KEY';
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
					->setAction( $this->context->link->getModuleLink( $this->module->name, 'redirect', ['lunar_method' => $this->METHOD_NAME], true ) )
					->setAdditionalInformation( $this->context->smarty->fetch('module:lunarpayment/views/templates/hook/' . $this->FILE_NAME . '.tpl' ) );
		
    }

    protected function install()
    {
		return (
			Configuration::updateValue( $this->METHOD_STATUS, 'enabled' )
			&& Configuration::updateValue( $this->APP_KEY, '' )
			&& Configuration::updateValue( $this->PUBLIC_KEY, '' )
			&& Configuration::updateValue( $this->LOGO_URL, '' )
			&& Configuration::updateValue( $this->CHECKOUT_MODE, 'delayed' )
			&& Configuration::updateValue( $this->ORDER_STATUS, 4 ) // Shipped
			&& Configuration::updateValue( $this->METHOD_TITLE, ucfirst($this->METHOD_NAME) )
			&& Configuration::updateValue( $this->METHOD_DESCRIPTION, $this->DESCRIPTION )
			&& Configuration::updateValue( $this->SHOP_TITLE, Configuration::get( 'PS_SHOP_NAME' ) ?? '' )
		);
    }

    protected function uninstall()
    {
        return (
            Configuration::deleteByName( $this->METHOD_STATUS )
            && Configuration::deleteByName( $this->APP_KEY )
            && Configuration::deleteByName( $this->PUBLIC_KEY )
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
        return [
            $this->METHOD_STATUS 		 => Configuration::get( $this->METHOD_STATUS ),
			$this->APP_KEY   	 	     => Configuration::get( $this->APP_KEY ),
			$this->PUBLIC_KEY   	 	 => Configuration::get( $this->PUBLIC_KEY ),
			$this->LOGO_URL    			 => Configuration::get( $this->LOGO_URL ),
			$this->CHECKOUT_MODE     	 => Configuration::get( $this->CHECKOUT_MODE ),
			$this->ORDER_STATUS      	 => Configuration::get( $this->ORDER_STATUS ),
			$this->METHOD_TITLE  		 => Configuration::get($this->METHOD_TITLE),
			$this->METHOD_DESCRIPTION    => Configuration::get($this->METHOD_DESCRIPTION),
			$this->SHOP_TITLE            => Configuration::get($this->SHOP_TITLE),
		];
	}

        
    /**
	 * 
	 */
	protected function updateConfiguration() {
        $isSaveAllowed = true;
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
            $this->context->controller->errors[ $this->METHOD_TITLE ] = $this->errorMessage( 'Payment method title is required!' );
            $payment_method_title = Configuration::get($this->METHOD_TITLE);
            $isSaveAllowed = false;
        }

        if ( !$this->validateLogoURL($logoURL) ) {
            $isSaveAllowed = false;
        }

        // @TODO remove these lines and activate validation
        $APP_KEY = Tools::getvalue( $this->APP_KEY ) ?? '';
        $public_key = Tools::getvalue( $this->PUBLIC_KEY ) ?? '';

        // $isSaveAllowed = $this->validateKeys();        
        
		Configuration::updateValue( $this->APP_KEY, $app_key );
		Configuration::updateValue( $this->PUBLIC_KEY, $public_key );
        Configuration::updateValue( $this->LOGO_URL, Tools::getValue( $this->LOGO_URL ) );
        Configuration::updateValue( $this->CHECKOUT_MODE, Tools::getValue( $this->CHECKOUT_MODE ) );
        Configuration::updateValue( $this->ORDER_STATUS, Tools::getValue( $this->ORDER_STATUS ) );
        Configuration::updateValue( $this->METHOD_TITLE, $payment_method_title );
        Configuration::updateValue( $this->METHOD_DESCRIPTION, $payment_method_desc );
        Configuration::updateValue( $this->SHOP_TITLE, $shop_title );

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
		return Configuration::get($this->APP_KEY) && Configuration::get($this->PUBLIC_KEY);
	}

	/**
	 * 
	 */
    protected function validateKeys()
    {
        $isSaveAllowed = true;

		// $app_key = Tools::getvalue( $this->APP_KEY ) ?? '';
		// $public_key = Tools::getvalue( $this->PUBLIC_KEY ) ?? '';

		// 	/** Load db value or set it to empty **/
		// 	$app_key = Configuration::get( $this->APP_KEY ) ?? '';
		// 	$validationPublicKeyMessage = $this->validateAppKeyField(Tools::getvalue( $this->APP_KEY ), 'live');
		// 	if($validationPublicKeyMessage){
		// 		$this->context->controller->errors[$this->APP_KEY] = $validationPublicKeyMessage;
		// 		$isSaveAllowed = false;
		// 	} else{
		// 		$app_key = Tools::getvalue( $this->APP_KEY ) ?? '';
		// 	}

		// 	/** Load db value or set it to empty **/
		// 	$public_key = Configuration::get( $this->PUBLIC_KEY ) ?? '';
		// 	$validationAppKeyMessage = $this->validatePublicKeyField(Tools::getvalue( $this->PUBLIC_KEY ), 'live');
		// 	if($validationAppKeyMessage){
		// 		$this->context->controller->errors[$this->PUBLIC_KEY] = $validationAppKeyMessage;
		// 		$isSaveAllowed = false;
		// 	} else{
		// 		$public_key = Tools::getvalue( $this->PUBLIC_KEY ) ?? '';
		// 	}

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
	// 		return $this->t( 'The App Key is required!' );
	// 	}
	// 	/** Load the client from API**/
	// 	$apiClient = new ApiClient( $value );
	// 	try {
	// 		/** Load the identity from API**/
	// 		$identity = $apiClient->apps()->fetch();
	// 	} catch ( ApiException $exception ) {
	// 		PrestaShopLogger::addLog( $exception );
	// 		return $this->t( "The App Key doesn't seem to be valid!");
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
	// 		// $error = $this->t( 'The '.$mode .' App Key is not valid or set to '.array_values(array_diff(array_keys($this->validationPublicKeys), array($mode)))[0].' mode!' );
	// 		$error = $this->t( 'The App Key is not valid or set to different mode!' );
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
	// 		return $this->t( 'The Public Key is required!' );
	// 	}
	// 	/** Check if the local stored public keys array is empty OR the key is not in public keys list **/
	// 	if ( empty( $this->validationPublicKeys[$mode] ) || ! in_array( $value, $this->validationPublicKeys[$mode] ) ) {
	// 		$error = $this->t( 'The Public Key doesn\'t seem to be valid!' );
	// 		PrestaShopLogger::addLog( $error );
	// 		return $error;
	// 	}
	// }

    protected function getFormFields()
    {
		//Fetch Status list
        $statuses_array  = [];
		$valid_statuses = array( '2', '3', '4', '5', '12' );
		$statuses       = OrderState::getOrderStates( (int) $this->context->language->id );
		foreach ( $statuses as $status ) {
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
					'title' => $this->t( 'Lunar Payments Settings' ),
					'icon'  => 'icon-cogs'
				),
				'input'  => array(
					array(
						'type'    => 'select',
                        'tab'     => $this->tabName,
						'lang'    => true,
						'name'    => $this->METHOD_STATUS,
						'label'   => $this->t( 'Status' ),
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
						'type'     => 'text',
                        'tab'      => $this->tabName,
						'label'    => '<span data-toggle="tooltip" title="' . $this->t( 'Get it from your Lunar dashboard' ) . '">' . $this->t( 'App Key' ) . '<i class="process-icon-help-new help-icon" aria-hidden="true"></i></span>',
						'name'     => $this->APP_KEY,
						'class'    => "lunar-config",
						'required' => true,
					),
					array(
						'type'     => 'text',
                        'tab'      => $this->tabName,
						'label'    => '<span data-toggle="tooltip" title="' . $this->t( 'Get it from your Lunar dashboard' ) . '">' . $this->t( 'Public Key' ) . '<i class="process-icon-help-new help-icon" aria-hidden="true"></i></span>',
						'name'     => $this->PUBLIC_KEY,
						'class'    => "lunar-config",
						'required' => true,
					),
					array(
						'type'     => 'text',
                        'tab'      => $this->tabName,
						'label'    => '<span data-toggle="tooltip" title="' . $this->t( 'Must be a link begins with "https://" to a JPG,JPEG or PNG file' ) . '">' . $this->t( 'Logo URL' ) . '<i class="process-icon-help-new help-icon" aria-hidden="true"></i></span>',
						'name'     => $this->LOGO_URL,
						'class'    => "lunar-config",
						'required' => true,
					),
					array(
						'type'     => 'select',
                        'tab'      => $this->tabName,
						'lang'     => true,
						'label'    => '<span data-toggle="tooltip" title="' . $this->t( 'If you deliver your product instantly (e.g. a digital product), choose Instant mode. If not, use Delayed' ) . '">' . $this->t( 'Capture mode' ) . '<i class="process-icon-help-new help-icon" aria-hidden="true"></i></span>',
						'name'     => $this->CHECKOUT_MODE,
						'class'    => "lunar-config",
						'options'  => array(
							'query' => array(
								array(
									'id_option' => 'delayed',
									'name'      => $this->t( 'Delayed' ),
								),
								array(
									'id_option' => 'instant',
									'name'      => $this->t( 'Instant' ),
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
						'label'   => '<span data-toggle="tooltip" title="' . $this->t( 'The transaction will be captured once the order has the chosen status' ) . '">' . $this->t( 'Capture on order status (delayed mode)' ) . '<i class="process-icon-help-new help-icon" aria-hidden="true"></i></span>',
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
						'label'    => '<span data-toggle="tooltip" title="' . $this->t( 'Payment method title' ) . '">' . $this->t( 'Payment method title' ) . '<i class="process-icon-help-new help-icon" aria-hidden="true"></i></span>',
						'name'     => $this->METHOD_TITLE,
						'class'    => "lunar-config",
						'required' => true,
					),
					array(
						'type'  => 'textarea',
                        'tab'      => $this->tabName,
						'label' => '<span data-toggle="tooltip" title="' . $this->t( 'Description' ) . '">' . $this->t( 'Description' ) . '<i class="process-icon-help-new help-icon" aria-hidden="true"></i></span>',
						'name'  => $this->METHOD_DESCRIPTION,
						'class' => "lunar-config",
					),
					array(
						'type'  => 'text',
                        'tab'      => $this->tabName,
						'label' => '<span data-toggle="tooltip" title="' . $this->t( 'The text shown in the page where the customer is redirected' ) . '">' . $this->t( 'Shop title' ) . '<i class="process-icon-help-new help-icon" aria-hidden="true"></i></span>',
						'name'  => $this->SHOP_TITLE,
						'class' => "lunar-config",
					),
				),
				'submit' => array(
					'title' => $this->t( 'Save' ),
				),

			),
        ];
    }

    /**
     * 
     */
    protected function t($string)
    {
        return $this->module->t($string);
    }

    /**
     * 
     */
    protected function errorMessage($string)
    {
        return $this->t($string) . " ($this->METHOD_NAME)";
    }
}
