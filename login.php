<?php
// login.php: Run this once (CLI) to log in and create session.madeline

require __DIR__ . '/vendor/autoload.php';

use danog\MadelineProto\API;
use danog\MadelineProto\Settings;
use danog\MadelineProto\Logger;

// Read API credentials from environment variables
$apiId   = (int) getenv('API_ID');
$apiHash = getenv('API_HASH');

if (!$apiId || !$apiHash) {
    fwrite(STDERR, "ERROR: API_ID and/or API_HASH environment variables are not set.\n");
    exit(1);
}

try {
    $settings = new Settings;

    $settings->getAppInfo()
        ->setApiId($apiId)
        ->setApiHash($apiHash)
        ->setDeviceModel('Railway PHP client')
        ->setSystemVersion('Railway PHP 8.2')
        ->setAppVersion('1.0');

    $settings->getLogger()
        ->setLevel(Logger::LEVEL_INFO);

    // Create API instance with session file
    $MadelineProto = new API('session.madeline', $settings);

    echo "Starting MadelineProto login...\n";
    $MadelineProto->start();

    echo "✅ Logged in successfully. Session saved as session.madeline\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Login failed: " . $e->getMessage() . "\n");
    exit(1);
}
