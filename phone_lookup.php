<?php
// phone_lookup.php: HTTP API to look up Telegram user + profile photo by phone

header('Content-Type: application/json');

require __DIR__ . '/vendor/autoload.php';

use danog\MadelineProto\API;
use danog\MadelineProto\Settings;
use danog\MadelineProto\Logger;

/**
 * Normalize Ethiopian phone to canonical +2519xxxxxxxx
 * Accepts: 9xxxxxxxx, 09xxxxxxxx, 2519xxxxxxxx, +2519xxxxxxxx, etc.
 */
function et_format_for_tg(string $raw): ?string {
    $digits = preg_replace('/\D+/', '', $raw);
    if ($digits === '') {
        return null;
    }

    // If starts with 0 (0910..., 09xxxxxxxx) -> drop leading 0
    if ($digits[0] === '0') {
        $digits = substr($digits, 1);
    }

    // If starts with 251 (already has country code)
    if (strpos($digits, '251') === 0) {
        if (strlen($digits) >= 12) {
            // last 9 digits are local mobile
            $digits = substr($digits, -9);
        } else {
            return null;
        }
    } else {
        // No country code, assume local: take last 9 digits
        if (strlen($digits) >= 9) {
            $digits = substr($digits, -9);
        } else {
            return null;
        }
    }

    // For mobile: must start with 9xxxxxxx
    if ($digits[0] !== '9') {
        return null;
    }

    return '+251' . $digits; // canonical E.164
}

// Read API credentials from environment
$apiId   = (int) getenv('API_ID');
$apiHash = getenv('API_HASH');

if (!$apiId || !$apiHash) {
    http_response_code(500);
    echo json_encode([
        'error'   => 'API_ID and/or API_HASH environment variables are not set',
        'success' => false,
    ]);
    exit;
}

// Initialize MadelineProto
try {
    $settings = new Settings;

    $settings->getAppInfo()
        ->setApiId($apiId)
        ->setApiHash($apiHash)
        ->setDeviceModel('Railway PHP client')
        ->setSystemVersion('Railway PHP 8.2')
        ->setAppVersion('1.0');

    $settings->getLogger()
        ->setLevel(Logger::LEVEL_ERROR);

    $MadelineProto = new API('session.madeline', $settings);
    $MadelineProto->start();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error'   => 'Failed to init MadelineProto: ' . $e->getMessage(),
        'success' => false,
    ]);
    exit;
}

// Read JSON input body
$raw   = file_get_contents('php://input');
$input = json_decode($raw, true);

if (!is_array($input) || !isset($input['phones']) || !is_array($input['phones'])) {
    http_response_code(400);
    echo json_encode([
        'error'   => 'Send JSON body: { "phones": ["+2519...", "09...", "9..."] }',
        'success' => false,
    ]);
    exit;
}

// Load existing contacts once to avoid renaming them to "Imported"
$existingContacts = [];
try {
    $contactsRes = $MadelineProto->contacts->getContacts();
    if (!empty($contactsRes['users'])) {
        foreach ($contactsRes['users'] as $u) {
            if (!empty($u['phone'])) {
                // Normalize stored phones to digits (e.g. 2519..., or 9...)
                $storedDigits = preg_replace('/\D+/', '', $u['phone']);
                if ($storedDigits !== '') {
                    $existingContacts[$storedDigits] = [
                        'first_name' => $u['first_name'] ?? '',
                        'last_name'  => $u['last_name'] ?? '',
                    ];
                }
            }
        }
    }
} catch (Throwable $e) {
    // If this fails, we just won't preserve names
}

$result = [];

foreach ($input['phones'] as $rawPhone) {
    $rawPhone = trim((string) $rawPhone);
    if ($rawPhone === '') {
        continue;
    }

    // Canonical E.164: +2519xxxxxxxx
    $canonical = et_format_for_tg($rawPhone);
    if ($canonical === null) {
        $result[] = [
            'phone'   => $rawPhone,
            'user'    => null,
            'photos'  => [],
            'error'   => 'Cannot normalize phone to +2519xxxxxxxx',
        ];
        continue;
    }

    // For Telegram contact import, use digits only: 2519xxxxxxxx
    $digits     = preg_replace('/\D+/', '', $canonical); // "251910902269"
    $localPhone = substr($digits, -9);                   // "910902269"

    $entry = [
        'phone'   => $canonical, // always return canonical
        'user'    => null,
        'photos'  => [],
        'error'   => null,
    ];

    try {
        // Decide name to use in import, preserving existing if present
        $existing = $existingContacts[$digits] ?? null;

        $firstName = 'Imported';
        $lastName  = '';

        if ($existing) {
            $firstName = $existing['first_name'] ?: 'Imported';
            $lastName  = $existing['last_name']  ?: '';
        }

        // Import or refresh contact (needed so "My Contacts" privacy works)
        $importRes = $MadelineProto->contacts->importContacts([
            'contacts' => [
                [
                    '_'          => 'inputPhoneContact',
                    'phone'      => $digits,   // "2519xxxxxxxx"
                    'first_name' => $firstName,
                    'last_name'  => $lastName,
                ],
            ],
        ]);

        $userId  = null;
        $userRaw = null;

        if (!empty($importRes['users'])) {
            $userRaw = reset($importRes['users']);
            $userId  = $userRaw['id'];
        }

        if ($userId === null) {
            $entry['error'] = 'User not found, not on Telegram, or not visible for this account';
            $result[] = $entry;
            continue;
        }

        // User info
        $entry['user'] = [
            'id'         => $userRaw['id']         ?? null,
            'username'   => $userRaw['username']   ?? null,
            'first_name' => $userRaw['first_name'] ?? null,
            'last_name'  => $userRaw['last_name']  ?? null,
            'phone'      => $userRaw['phone']      ?? null,
            'bot'        => $userRaw['bot']        ?? false,
        ];

        // Fetch only the first profile photo for speed
        $photosRes = $MadelineProto->photos->getUserPhotos([
            'user_id' => $userId,
            'offset'  => 0,
            'max_id'  => 0,
            'limit'   => 1, // 1 photo only
        ]);

        if (empty($photosRes['photos'])) {
            // No visible photos
            $result[] = $entry;
            continue;
        }

        $photo = $photosRes['photos'][0]; // single photo object

        $photosDir = __DIR__ . '/photos';
        if (!is_dir($photosDir)) {
            mkdir($photosDir, 0775, true);
        }

        // Build base: 910902269_name
        $fullName = trim(
            ($userRaw['first_name'] ?? '') . ' ' . ($userRaw['last_name'] ?? '')
        );
        if ($fullName === '') {
            $fullName = 'telegram_user';
        }

        $nameSlug = strtolower($fullName);
        $nameSlug = preg_replace('/[^a-z0-9]+/i', '_', $nameSlug);
        $nameSlug = trim($nameSlug, '_');

        // Final file name: 910902269_name.jpg
        $fileName = $localPhone . '_' . $nameSlug . '.jpg';
        $filePath = $photosDir . '/' . $fileName;

        // Download the photo as-is (no resizing for now; can optimize later)
        $MadelineProto->downloadToFile($photo, $filePath);

        // Build public URL based on current request
        $scheme   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $basePath = rtrim(dirname($_SERVER['PHP_SELF']), '/');

        $publicUrl = sprintf(
            '%s://%s%s/photos/%s',
            $scheme,
            $host,
            $basePath,
            $fileName
        );

        $entry['photos'][] = [
            'file' => $fileName,
            'url'  => $publicUrl,
        ];

        $result[] = $entry;
    } catch (Throwable $e) {
        $entry['error'] = $e->getMessage();
        $result[] = $entry;
    }
}

echo json_encode(
    [
        'success' => true,
        'results' => $result,
    ],
    JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
);
