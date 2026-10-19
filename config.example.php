<?php
// config.example.php - Example Local Overrides for GLOW NET
// Duplicate this file as "config.local.php" to customize your credentials.
// "config.local.php" is automatically ignored by Git.

return [
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306, // au 3307 kwa XAMPP instances za ziada
        'name' => 'glownet_db',
        'user' => 'root',
        'pass' => '',
    ],
    'admin' => [
        'username' => 'admin',
        'default_password' => 'admin123',
    ],
    'gateway' => [
        'provider'   => 'sandbox', // 'sandbox' au 'zenopay'
        'account_id' => '',
        'api_key'    => '',
        'secret_key' => '',
    ]
];
