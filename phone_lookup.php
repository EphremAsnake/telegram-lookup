<?php
// phone_lookup.php: HTTP API to look up Telegram user + profile photo by phone
declare(strict_types=1);

header('Content-Type: application/json');

// ---- Load MadelineProto from madeline.php (single-file install) ----
if (!file_exists(__DIR__ . '/madeline.php')) {
    copy('https://phar.madelineproto.xyz/madeline.php', __DIR__ . '/madeline.php');
}
require __DIR__ . '/madeline.php';

use danog\MadelineProto\API;
use danog\MadelineProto\Settings;
use danog\MadelineProto\Logger;

/**
 * Normalize Ethiopian phone to canonical +2519xxxxxxxx
 * Accepts: 9xxxxxxxx, 09xxxxxxxx, 2519xxxxxxxx, +2519xxxxxxxx, etc.
 * Returns null if cannot normalize.
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

/**
 * Resize & recompress JPEG image data (in memory) using GD, return new binary.
 * If GD not available or something fails, original data is returned.
 */
function optimize_image_jpeg(string $data, int $maxSize = 256, int $quality = 80): string
{
    if (!extension_loaded('gd')) {
        return $data;
    }

    $src = @imagecreatefromstring($data);
    if (!$src) {
        return $data;
    }

    $width  = imagesx($src);
    $height = imagesy($src);

    $scale = min($maxSize / $width, $maxSize / $height, 1.0);

    if ($scale < 1.0) {
        $newW = (int) round($width * $scale);
        $newH = (int) round($height * $scale);
        $dst  = imagecreatetruecolor($newW, $newH);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $width, $height);
        imagedestroy($src);
        $src = $dst;
    }

    ob_start();
    imagejpeg($src, null, $quality);
    $optimized = ob_get_clean();
    imagedestroy($src);

    return $optimized !== false ? $optimized : $data;
}

// ---- Read API credentials from env ----
$apiId   = (int) getenv('API_ID');
$apiHash = getenv('API_HASH');

if (!$apiId || !$apiHash) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'API_ID and/or API_HASH environment variables are not set'
    ]);
    exit;
}

// ---- Ensure Telegram session exists ----
$sessionFile = __DIR__ . '/session.madeline';
if (!file_exists($sessionFile)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Telegram session not initialized. Open /login.php first and log in.'
    ]);
    exit;
}

// ---- Initialize MadelineProto ----
try {
    $settings = new Settings;

    $settings->getAppInfo()
        ->setApiId($apiId)
        ->setApiHash($apiHash)
        ->setDeviceModel('Railway PHP client')
        ->setSystemVersion('Railway PHP 8.x')
        ->setAppVersion('1.0');

    $settings->getLogger()
        ->setLevel(Logger::LEVEL_ERROR);

    $MadelineProto = new API($sessionFile, $settings);
    $MadelineProto->start();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Failed to init MadelineProto: ' . $e->getMessage(),
    ]);
    exit;
}

// ---- Read JSON input body ----
$raw   = file_get_contents('php://input');
$input = json_decode($raw, true);

if (!is_array($input) || !isset($input['phones']) || !is_array($input['phones'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => 'Send JSON body: { "phones": ["+2519...", "09...", "9..."], "names": ["Name A", "Name B", ...] }',
    ]);
    exit;
}

// Optional parallel names array (same indexes as phones[])
$inputNames = [];
if (isset($input['names']) && is_array($input['names'])) {
    $inputNames = $input['names'];
}

// ---- Check contact count and clear if needed ----
try {
    $contactsRes = $MadelineProto->contacts->getContacts();
    $contactCount = !empty($contactsRes['users']) ? count($contactsRes['users']) : 0;
    
    // If we have more than 1000 contacts, clear all to free up space
    if ($contactCount > 1000) {
        $allContacts = !empty($contactsRes['users']) ? $contactsRes['users'] : [];
        $contactIds = [];
        
        foreach ($allContacts as $contact) {
            if (!empty($contact['id'])) {
                $contactIds[] = $contact['id'];
            }
        }
        
        if (!empty($contactIds)) {
            $MadelineProto->contacts->deleteContacts(['id' => $contactIds]);
        }
    }
} catch (Throwable $e) {
    // If this fails, we continue anyway - might be rate limited or other issue
}

$result = [];

// We keep order; no dedupe here so names[] stays aligned.
// You can dedupe on the caller side if you want.
$phones = array_values(array_map('strval', $input['phones']));

foreach ($phones as $idx => $rawPhone) {
    $rawPhone = trim($rawPhone);
    if ($rawPhone === '') {
        continue;
    }

    // Normalize Ethiopian mobiles: always use canonical +2519xxxxxxxx
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

    // Digits-only for contact import: e.g. "251910902269"
    $digits     = preg_replace('/\D+/', '', $canonical);
    $localPhone = substr($digits, -9); // "910902269"

    // Name from caller (BigQuery), if provided
    $providedName = '';
    if (array_key_exists($idx, $inputNames) && is_string($inputNames[$idx])) {
        $providedName = trim($inputNames[$idx]);
    }

    $entry = [
        'phone'   => $canonical,
        'user'    => null,
        'photos'  => [],
        'error'   => null,
    ];

    try {
        // Always use provided name if available, otherwise use "Imported"
        $firstName = $providedName !== '' ? $providedName : 'Imported';
        $lastName  = '';

        // Import/refresh contact (needed for "My Contacts" photo privacy)
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

        // Basic user info (their Telegram profile name etc.)
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
            'limit'   => 1,
        ]);

        if (empty($photosRes['photos'])) {
            // No visible photos
            $result[] = $entry;
            continue;
        }

        $photo = $photosRes['photos'][0];

        // Download to a temporary file
        $tmpFile = tempnam(sys_get_temp_dir(), 'tg_');
        $MadelineProto->downloadToFile($photo, $tmpFile);

        $data = file_get_contents($tmpFile);
        @unlink($tmpFile);

        if ($data === false || $data === '') {
            $result[] = $entry;
            continue;
        }

        // Downscale & recompress for speed / size
        $optimized = optimize_image_jpeg($data, 256, 80);

        $b64 = base64_encode($optimized);
        $dataUri = 'data:image/jpeg;base64,' . $b64;

        $entry['photos'][] = [
            'data_uri' => $dataUri,
            'mime'     => 'image/jpeg',
            'phone'    => $canonical,
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
