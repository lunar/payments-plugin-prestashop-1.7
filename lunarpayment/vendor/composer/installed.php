<?php return array(
    'root' => array(
        'name' => 'lunar/plugin-prestashop',
        'pretty_version' => '2.1.1',
        'version' => '2.1.1.0',
        'reference' => NULL,
        'type' => 'prestashop-module',
        'install_path' => __DIR__ . '/../../',
        'aliases' => array(),
        'dev' => true,
    ),
    'versions' => array(
        'lunar/payments-api-sdk' => array(
            'pretty_version' => 'dev-master',
            'version' => 'dev-master',
            'reference' => '36436f5181dbeb0fdb3bd77b6c040c7a7abcc366',
            'type' => 'library',
            'install_path' => __DIR__ . '/../lunar/payments-api-sdk',
            'aliases' => array(
                0 => '9999999-dev',
            ),
            'dev_requirement' => false,
        ),
        'lunar/plugin-prestashop' => array(
            'pretty_version' => '2.1.1',
            'version' => '2.1.1.0',
            'reference' => NULL,
            'type' => 'prestashop-module',
            'install_path' => __DIR__ . '/../../',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
    ),
);
