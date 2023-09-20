<?php

use Lunar\Payment\methods\LunarCardMethod;
use Lunar\Payment\controllers\front\AbstractLunarFrontController;

/**
 * 
 */
class LunarpaymentCardModuleFrontController extends AbstractLunarFrontController
{
    public function __construct()
    {
        parent::__construct(LunarCardMethod::METHOD_NAME);
    }
}