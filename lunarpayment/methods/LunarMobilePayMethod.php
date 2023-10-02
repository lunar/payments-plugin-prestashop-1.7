<?php

namespace Lunar\Payment\methods;

use \Tools;
use \Configuration;

/**
 * 
 */
class LunarMobilePayMethod extends AbstractLunarMethod
{
    const METHOD_NAME = 'mobilePay';

    public string $METHOD_NAME = self::METHOD_NAME;
    public string $DESCRIPTION = 'Secure payment with Mobile Pay via © Lunar';
    public string $FILE_NAME = 'mobilepaymethod';
    public string $CONFIGURATION_ID = '';

    protected $tabName = 'lunar_mobilepay';

    /**
     * 
     */
    public function __construct($module)
    {
        parent::__construct($module);
        $this->CONFIGURATION_ID = 'LUNAR_' . $this->METHOD_NAME . '_CONFIGURATION_ID';   
    }

    /**
     * 
     */
    public function install()
    {
        return parent::install();
    }

    /**
     * 
     */
    public function uninstall()
    {
        return (
            Configuration::deleteByName( $this->CONFIGURATION_ID ) 
            && parent::uninstall()
        );
    }
    
    /**
	 * 
	 */
	public function getConfiguration()
    {
        return array_merge(parent::getConfiguration(), [$this->CONFIGURATION_ID =>  Configuration::get($this->CONFIGURATION_ID)]);
	}
    
    /**
	 * 
	 */
	public function updateConfiguration()
    {

        Configuration::updateValue( $this->CONFIGURATION_ID, Tools::getvalue( $this->CONFIGURATION_ID ));

        return parent::updateConfiguration();
	}

    /**
     * 
     */
    public function getFormFields()
    {
        $configurationIDField = [
            'type'     => 'text',
            'tab'      => $this->tabName,
            'label'    => '<span data-toggle="tooltip" title="' . $this->t( 'Get it from your Lunar dashboard' ) . '">' . $this->t( 'Configuration ID' ) . '<i class="process-icon-help-new help-icon" aria-hidden="true"></i></span>',
            'name'     => $this->CONFIGURATION_ID,
            'class'    => "lunar-config",
            'required' => true
        ];

        $parentFields = parent::getFormFields();

        // insert config field in precise position
        $tempFields = [];
        foreach ($parentFields['form']['input'] as $input) {           
            $tempFields[] = $input;
            if ($input['name'] == $this->LOGO_URL) {
                $tempFields[] = $configurationIDField;
            }
        }

        $parentFields['form']['input'] = $tempFields;

        return $parentFields;
    }
}
