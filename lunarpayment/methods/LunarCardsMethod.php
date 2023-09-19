<?php

namespace Lunar\Payment\methods;

use \Db;
use \Tools;
use \DbQuery;
use \Configuration;

/**
 * 
 */
class LunarCardsMethod extends AbstractLunarMethod
{
    const METHOD_NAME = 'Cards';
    
    public string $METHOD_NAME = self::METHOD_NAME;
    public string $DESCRIPTION = 'Secure payment with card via © Lunar';
    public string $FILE_NAME = 'cardsmethod';
	public string $ACCEPTED_CARDS = '';

    protected $tabName = 'lunar_cards';


    public function __construct($module)
    {
        parent::__construct($module);

        $this->ACCEPTED_CARDS = 'LUNAR_' . $this->METHOD_NAME . '_ACCEPTED_CARDS';
    }

    /**
     * 
     */
    public function install()
    {
        return (
            Configuration::updateValue( $this->ACCEPTED_CARDS, 'visa.svg,visa-electron.svg,mastercard.svg,mastercard-maestro.svg' )
            && parent::install()
        );
    }

    /**
     * 
     */
    public function uninstall()
    {
        $sql = new DbQuery();
		$sql->select( '*' );
		$sql->from( "lunar_logos", 'PL' );
		$sql->where( 'PL.default_logo != 1' );
		$logos = Db::getInstance()->executes( $sql );

		foreach ( $logos as $logo ) {
			if ( file_exists( _PS_MODULE_DIR_ . $this->module->name . '/views/img/' . $logo['file_name'] ) ) {
				unlink( _PS_MODULE_DIR_ . $this->module->name . '/views/img/' . $logo['file_name'] );
			}
		}
        
        return (
            Configuration::deleteByName( $this->ACCEPTED_CARDS ) 
            && parent::uninstall()
        );
    }
    
    /**
	 * 
	 */
	public function getConfiguration()
    {
        return array_merge(
            parent::getConfiguration(), 
            [
                $this->ACCEPTED_CARDS . '[]' =>  Configuration::get($this->ACCEPTED_CARDS)
            ]
        );
	}
    
    /**
	 * 
	 */
	public function updateConfiguration()
    {
        $acceptedCards = Tools::getvalue( $this->ACCEPTED_CARDS );

        if ( $acceptedCards && count( $acceptedCards ) > 1 ) {
            $acceptedCards = implode( ',', $acceptedCards );
        } else {
            $acceptedCards = $acceptedCards;
        }

        Configuration::updateValue( $this->ACCEPTED_CARDS, $acceptedCards );

        return parent::updateConfiguration();
	}

    /**
     * 
     */
    public function getFormFields()
    {
        $logos_array     = [];

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

        $acceptedCardsField = [
            'type'     => 'select',
            'tab'      => $this->tabName,
            'label'    => '<span data-toggle="tooltip" title="' . $this->l( 'Choose logos to show in frontend checkout page.' ) . '">' . $this->l( 'Accepted cards' ) . '<i class="process-icon-help-new help-icon" aria-hidden="true"></i></span>',
            'name'     => $this->ACCEPTED_CARDS,
            'class'    => "lunar-config accepted-cards",
            'multiple' => true,
            'options'  => array(
                'query' => $logos_array,
                'id'    => 'id_option',
                'name'  => 'name'
            ),
        ];

        $parentFields = parent::getFormFields();
        $parentFields['form']['input'][] = $acceptedCardsField;

        return $parentFields;
    }
}
