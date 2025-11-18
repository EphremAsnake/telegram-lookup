<?php
// index.php - front controller / router for Railway
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

// Serve photos directly: /photos/filename.jpg
if (strpos($path, '/photos/') === 0) {
    $file = __DIR__ . $path; // e.g. /app/photos/9109...jpg

    if (is_file($file)) {
        // Very simple content-type detection
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        switch ($ext) {
            case 'jpg':
            case 'jpeg':
                header('Content-Type: image/jpeg');
                break;
            case 'png':
                header('Content-Type: image/png');
                break;
            default:
                header('Content-Type: application/octet-stream');
        }

        readfile($file);
    } else {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Photo not found\n";
    }
    exit;
}

// Simple router for our PHP endpoints
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
