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
        $MadelineProto->contacts->resetSaved();
    }
} catch (Throwable $e) {
    // If this fails, we continue anyway - might be rate limited or other issue
}

$phones = array_values(array_map('strval', $input['phones']));

// Limit batch size to 25
$batchSize = 25;
$result = [];

// Process in batches
for ($i = 0; $i < count($phones); $i += $batchSize) {
    $batchPhones = array_slice($phones, $i, $batchSize);
    $batchResult = processPhoneBatch($MadelineProto, $batchPhones, $inputNames, $i);
    $result = array_merge($result, $batchResult);
    
    // Add delay between batches to avoid flood waits
    if ($i + $batchSize < count($phones)) {
        sleep(1);
    }
}

echo json_encode(
    [
        'success' => true,
        'results' => $result,
    ],
    JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
);

/**
 * Process a batch of phones
 */
function processPhoneBatch(API $MadelineProto, array $batchPhones, array $inputNames, int $offset): array {
    $batchResults = [];
    
    // Prepare batch data and normalize phones
    $normalizedPhones = [];
    foreach ($batchPhones as $idx => $rawPhone) {
        $globalIdx = $offset + $idx;
        $rawPhone = trim($rawPhone);
        if ($rawPhone === '') {
            $batchResults[$globalIdx] = [
                'phone'   => $rawPhone,
                'user'    => null,
                'photos'  => [],
                'error'   => 'Empty phone number',
            ];
            continue;
        }

        // Normalize Ethiopian mobiles
        $canonical = et_format_for_tg($rawPhone);
        if ($canonical === null) {
            $batchResults[$globalIdx] = [
                'phone'   => $rawPhone,
                'user'    => null,
                'photos'  => [],
                'error'   => 'Cannot normalize phone to +2519xxxxxxxx',
            ];
            continue;
        }

        $digits = preg_replace('/\D+/', '', $canonical);
        $providedName = '';
        if (array_key_exists($globalIdx, $inputNames) && is_string($inputNames[$globalIdx])) {
            $providedName = trim($inputNames[$globalIdx]);
        }

        $normalizedPhones[$globalIdx] = [
            'original_phone' => $rawPhone,
            'canonical' => $canonical,
            'digits' => $digits,
            'providedName' => $providedName,
            'globalIdx' => $globalIdx
        ];
    }

    // Import contacts in batch
    $contactsToImport = [];
    foreach ($normalizedPhones as $globalIdx => $data) {
        $firstName = $data['providedName'] !== '' ? $data['providedName'] : 'Imported';
        $contactsToImport[] = [
            '_'          => 'inputPhoneContact',
            'phone'      => $data['digits'],
            'first_name' => $firstName,
            'last_name'  => '',
            'client_id'  => $globalIdx // Store index to map back
        ];
    }

    $importedUsers = [];
    if (!empty($contactsToImport)) {
        try {
            $importRes = $MadelineProto->contacts->importContacts([
                'contacts' => $contactsToImport,
            ]);
            
            // Map imported users back to their phones
            if (!empty($importRes['users'])) {
                foreach ($importRes['users'] as $userRaw) {
                    $userPhone = $userRaw['phone'] ?? null;
                    if ($userPhone) {
                        foreach ($normalizedPhones as $globalIdx => $data) {
                            if ($data['canonical'] === '+'.$userPhone) {
                                $importedUsers[$globalIdx] = [
                                    'userRaw' => $userRaw,
                                    'userId' => $userRaw['id'],
                                    'phoneData' => $data
                                ];
                                break;
                            }
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            // Continue with individual lookups if batch import fails
            error_log("Batch import failed: " . $e->getMessage());
        }
    }

    // Process each phone in the batch
    foreach ($normalizedPhones as $globalIdx => $data) {
        // If we already have a result for this phone (from error during normalization), skip
        if (isset($batchResults[$globalIdx])) {
            continue;
        }

        $entry = [
            'phone'   => $data['canonical'],
            'user'    => null,
            'photos'  => [],
            'error'   => null,
        ];

        try {
            // Check if user was imported successfully
            if (isset($importedUsers[$globalIdx])) {
                $userData = $importedUsers[$globalIdx];
                
                // Basic user info
                $entry['user'] = [
                    'id'         => $userData['userRaw']['id'] ?? null,
                    'username'   => $userData['userRaw']['username'] ?? null,
                    'first_name' => $userData['userRaw']['first_name'] ?? null,
                    'last_name'  => $userData['userRaw']['last_name'] ?? null,
                    'phone'      => $userData['userRaw']['phone'] ?? null,
                    'bot'        => $userData['userRaw']['bot'] ?? false,
                ];

                // Fetch profile photo
                $photosRes = $MadelineProto->photos->getUserPhotos([
                    'user_id' => $userData['userId'],
                    'offset'  => 0,
                    'max_id'  => 0,
                    'limit'   => 1,
                ]);

                if (!empty($photosRes['photos'])) {
                    $photo = $photosRes['photos'][0];

                    // Download to a temporary file
                    $tmpFile = tempnam(sys_get_temp_dir(), 'tg_');
                    $MadelineProto->downloadToFile($photo, $tmpFile);

                    $dataContent = file_get_contents($tmpFile);
                    @unlink($tmpFile);

                    if ($dataContent !== false && $dataContent !== '') {
                        // Downscale & recompress for speed / size
                        $optimized = optimize_image_jpeg($dataContent, 256, 80);
                        $b64 = base64_encode($optimized);
                        $dataUri = 'data:image/jpeg;base64,' . $b64;

                        $entry['photos'][] = [
                            'data_uri' => $dataUri,
                            'mime'     => 'image/jpeg',
                            'phone'    => $data['canonical'],
                        ];
                    }
                }
            } else {
                $entry['error'] = 'User not found, not on Telegram, or not visible for this account';
            }

            $batchResults[$globalIdx] = $entry;
        } catch (Throwable $e) {
            $entry['error'] = $e->getMessage();
            $batchResults[$globalIdx] = $entry;
        }
    }

    // Sort results by original index and return
    ksort($batchResults);
    return array_values($batchResults);
}
