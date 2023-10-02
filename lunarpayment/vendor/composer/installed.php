<?php return array(
    'root' => array(
        'name' => 'lunar/plugin-prestashop',
        'pretty_version' => '2.0.0',
        'version' => '2.0.0.0',
        'reference' => NULL,
        'type' => 'prestashop-module',
        'install_path' => __DIR__ . '/../../',
        'aliases' => array(),
        'dev' => true,
    ),
    'versions' => array(
        'lunar/payments-api-sdk' => array(
            'pretty_version' => 'dev-initial-dev',
            'version' => 'dev-initial-dev',
            'reference' => 'f9fa8411414e3ff3a3122588b97d5e398cb0b237',
            'type' => 'library',
            'install_path' => __DIR__ . '/../lunar/payments-api-sdk',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
        'lunar/plugin-prestashop' => array(
            'pretty_version' => '2.0.0',
            'version' => '2.0.0.0',
            'reference' => NULL,
            'type' => 'prestashop-module',
            'install_path' => __DIR__ . '/../../',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
    ),
);
