<?php
declare(strict_types=1);

use danog\MadelineProto\API;
use danog\MadelineProto\Settings;
use danog\MadelineProto\Settings\AppInfo;

require __DIR__ . '/vendor/autoload.php';

header('Content-Type: application/json');

$response = [
    'success' => false,
    'results' => [],
];

try {
    // 1) Basic method guard
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Use POST with JSON body: {"phones": [...], "names": [...]}');
    }

    // 2) Parse JSON body
    $rawBody = file_get_contents('php://input');
    if ($rawBody === false || $rawBody === '') {
        throw new RuntimeException('Empty request body');
    }

    $data = json_decode($rawBody, true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid JSON body');
    }

    $phones = $data['phones'] ?? null;
    $names  = $data['names']  ?? [];

    if (!is_array($phones) || count($phones) === 0) {
        throw new RuntimeException('"phones" must be a non-empty array');
    }
    if (!is_array($names)) {
        $names = [];
    }

    // 3) Ensure telegram session exists
    $sessionFile = __DIR__ . '/session.madeline';
    if (!file_exists($sessionFile)) {
        throw new RuntimeException('Telegram session not initialized. Open /login.php first and log in.');
    }

    // 4) Create MadelineProto API instance (no preload logic)
    $settings = new Settings();
    $appInfo  = $settings->getAppInfo();

    $apiId   = getenv('API_ID');
    $apiHash = getenv('API_HASH');

    if (!$apiId || !$apiHash) {
        throw new RuntimeException('API_ID or API_HASH env variables are not set in Railway.');
    }

    $appInfo->setApiId((int)$apiId);
    $appInfo->setApiHash((string)$apiHash);
    $settings->setAppInfo($appInfo);

    $MadelineProto = new API($sessionFile, $settings);
    $MadelineProto->start();

    // 5) Auto-clean contacts if count > 2000 (on dedicated account)
    try {
        $contactsObj = $MadelineProto->contacts->getContacts();
        $userList    = $contactsObj['users'] ?? [];
        $countUsers  = is_array($userList) ? count($userList) : 0;

        if ($countUsers > 2000) {
            // Delete synced/saved contacts
            $MadelineProto->contacts->resetSaved();
        }
    } catch (\Throwable $cleanupEx) {
        // Non-fatal; just log to error log and continue
        error_log('contacts cleanup failed: ' . $cleanupEx->getMessage());
    }

    // 6) Normalize phones to +2519xxxxxxxx & prepare import payload
    $importContacts = [];
    $indexMap       = []; // canonical phone => index(es) in input phones array
    foreach ($phones as $i => $rawPhone) {
        $canon = et_format_for_tg((string)$rawPhone);
        if ($canon === null) {
            $response['results'][] = [
                'phone'   => (string)$rawPhone,
                'user'    => null,
                'photos'  => [],
                'error'   => 'Unsupported or invalid phone format for this lookup',
            ];
            continue;
        }

        $name = '';
        if (array_key_exists($i, $names) && is_string($names[$i])) {
            $name = trim($names[$i]);
        }
        if ($name === '') {
            $name = 'Imported';
        }

        $importContacts[] = [
            '_'         => 'inputPhoneContact',
            'client_id' => $i + 1,
            'phone'     => $canon,
            'first_name'=> $name,
            'last_name' => '',
        ];

        $indexMap[$canon][] = $i;
    }

    if (empty($importContacts)) {
        throw new RuntimeException('No valid phones to import.');
    }

    // 7) Import contacts (ephemeral — names from our request)
    $importResult = $MadelineProto->contacts->importContacts([
        'contacts' => $importContacts,
    ]);

    // Map: phone => user (from import result)
    $usersByPhone = buildUsersByPhone($importResult);

    // 8) For each canonical phone, fetch smallest profile photo and return base64
    $results = [];
    foreach ($indexMap as $canonPhone => $indices) {
        $resTemplate = [
            'phone'  => $canonPhone,
            'user'   => null,
            'photos' => [],
            'error'  => null,
        ];

        if (!isset($usersByPhone[$canonPhone])) {
            $resTemplate['error'] = 'User not found after import';
            // Apply same result for all indices referring to this phone
            foreach ($indices as $idx) {
                $results[$idx] = $resTemplate;
            }
            continue;
        }

        $user = $usersByPhone[$canonPhone];

        $resTemplate['user'] = [
            'id'         => $user['id'] ?? null,
            'username'   => $user['username'] ?? null,
            'first_name' => $user['first_name'] ?? null,
            'last_name'  => $user['last_name'] ?? null,
            'phone'      => $user['phone'] ?? null,
            'bot'        => $user['bot'] ?? false,
        ];

        // Try to get one small profile photo
        try {
            $photoDataUri = getSmallProfilePhotoAsDataUri($MadelineProto, $user);
            if ($photoDataUri !== null) {
                $resTemplate['photos'][] = [
                    'data_uri' => $photoDataUri,
                    'mime'     => 'image/jpeg',
                ];
            }
        } catch (\Throwable $photoEx) {
            $resTemplate['error'] = 'Photo fetch error: ' . $photoEx->getMessage();
        }

        foreach ($indices as $idx) {
            $results[$idx] = $resTemplate;
        }
    }

    // Keep original order by ksort on index
    ksort($results);
    $response['results'] = array_values($results);
    $response['success'] = true;

} catch (\Throwable $e) {
    $response['success'] = false;
    $response['error']   = $e->getMessage();
}

echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
exit;


/**
 * Normalize Ethiopian-style numbers into +2519xxxxxxxx
 * Supports:
 *  - 9xxxxxxxx
 *  - 09xxxxxxxx
 *  - 2519xxxxxxxxx
 * Returns null if format is not a mobile number starting with 9.
 */
function et_format_for_tg(string $raw): ?string
{
    $digits = preg_replace('/\D+/', '', $raw);
    if ($digits === '') {
        return null;
    }

    // If starts with 0 (e.g. 0910..., 09xxxxxxxx) -> drop leading 0
    if ($digits[0] === '0') {
        $digits = substr($digits, 1);
    }

    // If starts with 251 (already with country code)
    if (strpos($digits, '251') === 0) {
        if (strlen($digits) >= 12) {
            // keep last 9 digits
            $digits = substr($digits, -9);
        } else {
            return null;
        }
    } else {
        // no country code, assume local: take last 9 digits
        if (strlen($digits) >= 9) {
            $digits = substr($digits, -9);
        } else {
            return null;
        }
    }

    // For Ethiopian mobile, local part should start with 9 (9xxxxxxxx)
    if ($digits[0] !== '9') {
        return null;
    }

    return '+251' . $digits;
}

/**
 * Build map phone => user array from contacts.importContacts result.
 */
function buildUsersByPhone(array $importResult): array
{
    $map = [];

    $users = $importResult['users'] ?? [];
    if (!is_array($users)) {
        return $map;
    }

    foreach ($users as $u) {
        if (!isset($u['_']) || $u['_'] !== 'user') {
            continue;
        }
        $phone = $u['phone'] ?? null;
        if (!$phone) {
            continue;
        }

        // Telegram phones typically stored without leading +
        if ($phone[0] !== '+') {
            $phone = '+' . $phone;
        }

        $map[$phone] = $u;
    }

    return $map;
}

/**
 * Get smallest profile photo for a user as data:image/jpeg;base64,... URI.
 */
function getSmallProfilePhotoAsDataUri(API $MadelineProto, array $user): ?string
{
    if (empty($user['id'])) {
        return null;
    }

    // photos.getUserPhotos: limit 1
    $photos = $MadelineProto->photos->getUserPhotos([
        'user_id' => $user,
        'offset'  => 0,
        'max_id'  => 0,
        'limit'   => 1,
    ]);

    if (empty($photos['photos'][0])) {
        return null;
    }

    $photo = $photos['photos'][0];

    // Try to pick the smallest size from 'sizes'
    $smallest = null;
    $smallestArea = null;

    if (isset($photo['sizes']) && is_array($photo['sizes']) && count($photo['sizes']) > 0) {
        foreach ($photo['sizes'] as $size) {
            $w = $size['w'] ?? null;
            $h = $size['h'] ?? null;
            if ($w === null || $h === null) {
                continue;
            }
            $area = $w * $h;
            if ($smallest === null || $area < $smallestArea) {
                $smallest = $size;
                $smallestArea = $area;
            }
        }
    }

    if ($smallest !== null) {
        $bytes = $MadelineProto->downloadToBytes($smallest);
    } else {
        // fallback: download the whole photo object
        $bytes = $MadelineProto->downloadToBytes($photo);
    }

    if (!is_string($bytes) || $bytes === '') {
        return null;
    }

    $b64 = base64_encode($bytes);
    return 'data:image/jpeg;base64,' . $b64;
}
