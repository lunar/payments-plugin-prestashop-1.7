<?php

use Lunar\Payment\methods\LunarMobilePayMethod;
use Lunar\Payment\controllers\front\AbstractLunarFrontController;

/**
 * 
 */
class LunarpaymentCardModuleFrontController extends AbstractLunarFrontController
{
    public function __construct()
    {
        parent::__construct(LunarMobilePayMethod::METHOD_NAME);
    }
}