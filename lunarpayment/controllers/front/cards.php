<?php

use Lunar\Payment\methods\LunarCardsMethod;
use Lunar\Payment\controllers\front\AbstractLunarFrontController;

/**
 * 
 */
class LunarpaymentCardsModuleFrontController extends AbstractLunarFrontController
{
    public function __construct()
    {
        parent::__construct();
        
        $this->initialize(new LunarCardsMethod($this->module));
    }
}