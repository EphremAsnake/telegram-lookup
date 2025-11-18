<?php
// index.php - front controller / router for Railway
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

switch ($path) {
    case '/login.php':
        require __DIR__ . '/login.php';
        exit;

    case '/phone_lookup.php':
        require __DIR__ . '/phone_lookup.php';
        exit;

    case '/':
    default:
        header('Content-Type: text/plain; charset=utf-8');
        echo "Telegram lookup service is running\n";
        exit;
}
