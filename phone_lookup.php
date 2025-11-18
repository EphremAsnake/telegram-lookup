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

// ---- Session file (persist login) ----
$sessionFile = __DIR__ . '/telegram_lookup.session';

// ---- Initialize MadelineProto ----
try {
    $settings = new Settings;

    $settings->getAppInfo()
        ->setApiId($apiId)
        ->setApiHash($apiHash)
        ->setDeviceModel('Railway PHP client')
        ->setSystemVersion('Railway PHP 8.x')
        ->setAppVersion('Telegram Lookup 1.0')
        ->setLangCode('en');

    $settings->getLogger()
        ->setLevel(Logger::ERROR);

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

if (!is_array($input) ||
    !isset($input['phones']) ||
    !is_array($input['phones']) ||
    empty($input['phones'])
) {
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

// ---- Contacts housekeeping: limit total saved contacts on dedicated lookup account ----
$existingContacts = []; // we no longer preload names; this account is used only for lookups
try {
    $contactsRes = $MadelineProto->contacts->getContacts();
    $contactCount = 0;

    if (!empty($contactsRes['users']) && is_array($contactsRes['users'])) {
        $contactCount = count($contactsRes['users']);
    }

    if ($contactCount > 2000) {
        // Too many contacts saved in the Telegram cloud for this dedicated account.
        // We clear them to avoid hitting Telegram limits and to keep the account clean.
        try {
            $idsToDelete = [];
            foreach ($contactsRes['users'] as $u) {
                if (isset($u['id'])) {
                    $idsToDelete[] = $u['id'];
                }
            }

            if (!empty($idsToDelete)) {
                $MadelineProto->contacts->deleteContacts([
                    'id' => $idsToDelete,
                ]);
            }
        } catch (Throwable $inner) {
            // Ignore cleanup errors; lookup will still continue.
        }
    }

    // Intentionally NOT preserving existing contact names:
    // all names come from the request payload or fall back to "Imported".
} catch (Throwable $e) {
    // If this fails, we simply continue without contact housekeeping.
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
        $entry = [
            'phone'   => $rawPhone,
            'user'    => null,
            'photos'  => [],
            'error'   => 'Invalid Ethiopian mobile format',
        ];
        $result[] = $entry;
        continue;
    }

    $digits     = preg_replace('/\D+/', '', $canonical);
    $localPhone = substr($digits, -9); // "9xxxxxxxxx"

    // Name from caller, if provided
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
        // Decide name to use when importing: prefer provided name from request,
        // otherwise fall back to a generic placeholder. We do NOT preserve any
        // previously saved contact names on this dedicated lookup account.
        $firstName = 'Imported';
        $lastName  = '';

        if ($providedName !== '') {
            $firstName = $providedName;
        }

        // Import/refresh contact (needed for "My Contacts" photo privacy)
        $importRes = $MadelineProto->contacts->importContacts([
            'contacts' => [
                [
                    'phone'      => $localPhone,
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

        // Basic user info
        $entry['user'] = [
            'id'         => $userRaw['id'] ?? null,
            'username'   => $userRaw['username'] ?? null,
            'first_name' => $userRaw['first_name'] ?? null,
            'last_name'  => $userRaw['last_name'] ?? null,
            'phone'      => $userRaw['phone'] ?? null,
            'bot'        => $userRaw['bot'] ?? false,
        ];

        // Fetch profile photos
        $photosRes = $MadelineProto->photos->getUserPhotos([
            'user_id' => $userId,
            'offset'  => 0,
            'max_id'  => 0,
            'limit'   => 5,
        ]);

        if (!empty($photosRes['photos'])) {
            foreach ($photosRes['photos'] as $photo) {
                try {
                    $file = $MadelineProto->downloadToString($photo);

                    // Optimize (resize + recompress)
                    $fileOptimized = optimize_image_jpeg($file, 256, 80);
                    $base64        = base64_encode($fileOptimized);

                    $entry['photos'][] = [
                        'data_uri' => 'data:image/jpeg;base64,' . $base64,
                        'mime'     => 'image/jpeg',
                        'phone'    => $canonical,
                    ];
                } catch (Throwable $e) {
                    // Ignore broken photos but keep others
                }
            }
        }

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
