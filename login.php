<?php
// login.php - Web-based login for MadelineProto on Railway

declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');

require __DIR__ . '/vendor/autoload.php';

use danog\MadelineProto\API;
use danog\MadelineProto\Settings;
use danog\MadelineProto\Logger;
use danog\MadelineProto\Tools;

session_start();

// ---- CONFIG FROM ENV ----
$apiId   = (int) getenv('API_ID');
$apiHash = getenv('API_HASH');

if (!$apiId || !$apiHash) {
    http_response_code(500);
    echo "<h1>API config error</h1><p>API_ID / API_HASH env vars are not set.</p>";
    exit;
}

// ---- INIT MTPROTO ----
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

    $MadelineProto = new API(__DIR__ . '/session.madeline', $settings);
} catch (Throwable $e) {
    http_response_code(500);
    echo "<h1>Init error</h1><pre>" . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "</pre>";
    exit;
}

$message   = '';
$step      = 'phone';   // phone | code | password
$need2FA   = false;
$loggedIn  = false;

// Try to see if already logged in
try {
    $me = $MadelineProto->getSelf();
    if ($me) {
        $loggedIn = true;
    }
} catch (Throwable $e) {
    // Not logged in yet, ignore
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$loggedIn) {
    // Step 1: send code
    if (isset($_POST['action']) && $_POST['action'] === 'send_phone') {
        $phone = trim((string) ($_POST['phone'] ?? ''));

        if ($phone === '') {
            $message = 'Please enter a phone number.';
            $step    = 'phone';
        } else {
            try {
                // Send login code
                $MadelineProto->phoneLogin($phone);
                $_SESSION['tg_phone']     = $phone;
                $_SESSION['tg_code_sent'] = true;
                $message                  = 'Code sent. Check Telegram or SMS and enter the code below.';
                $step                     = 'code';
            } catch (Throwable $e) {
                $message = 'Error sending code: ' . $e->getMessage();
                $step    = 'phone';
            }
        }
    }
    // Step 2: complete with code
    elseif (isset($_POST['action']) && $_POST['action'] === 'send_code' && !empty($_SESSION['tg_code_sent'])) {
        $code = trim((string) ($_POST['code'] ?? ''));

        if ($code === '') {
            $message = 'Please enter the code you received.';
            $step    = 'code';
        } else {
            try {
                $authorization = $MadelineProto->completePhoneLogin($code);

                if (is_array($authorization) && isset($authorization['_'])) {
                    if ($authorization['_'] === 'account.password') {
                        // 2FA enabled, ask for password
                        $_SESSION['tg_needs_2fa'] = true;
                        $message                  = 'This account has 2FA enabled. Enter your Telegram password.';
                        $step                     = 'password';
                        $need2FA                  = true;
                    } elseif ($authorization['_'] === 'account.needSignup') {
                        $message = 'This phone number does not have a Telegram account.';
                        $step    = 'phone';
                    } else {
                        $loggedIn = true;
                        $message  = 'Logged in successfully. You can close this page now.';
                        unset($_SESSION['tg_code_sent'], $_SESSION['tg_phone'], $_SESSION['tg_needs_2fa']);
                    }
                } else {
                    // Probably already authorized
                    $loggedIn = true;
                    $message  = 'Logged in successfully. You can close this page now.';
                    unset($_SESSION['tg_code_sent'], $_SESSION['tg_phone'], $_SESSION['tg_needs_2fa']);
                }
            } catch (Throwable $e) {
                $message = 'Error completing login: ' . $e->getMessage();
                $step    = 'code';
            }
        }
    }
    // Step 3: 2FA password
    elseif (isset($_POST['action']) && $_POST['action'] === 'send_password' && !empty($_SESSION['tg_needs_2fa'])) {
        $password = (string) ($_POST['password'] ?? '');

        if ($password === '') {
            $message = 'Please enter your Telegram 2FA password.';
            $step    = 'password';
            $need2FA = true;
        } else {
            try {
                $MadelineProto->complete2falogin($password);
                $loggedIn = true;
                $message  = 'Logged in successfully with 2FA. You can close this page now.';
                unset($_SESSION['tg_code_sent'], $_SESSION['tg_phone'], $_SESSION['tg_needs_2fa']);
            } catch (Throwable $e) {
                $message = 'Error completing 2FA login: ' . $e->getMessage();
                $step    = 'password';
                $need2FA = true;
            }
        }
    }
}

// If already logged in, adjust step
if ($loggedIn) {
    $step = 'done';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Telegram Login (MadelineProto)</title>
    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: #0f172a;
            color: #e5e7eb;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
        }
        .card {
            background: #020617;
            border-radius: 16px;
            padding: 24px 28px;
            width: 100%;
            max-width: 420px;
            box-shadow: 0 24px 60px rgba(0, 0, 0, 0.65);
            border: 1px solid rgba(148, 163, 184, 0.2);
        }
        h1 {
            font-size: 20px;
            margin: 0 0 12px;
        }
        p {
            margin: 4px 0 12px;
            color: #9ca3af;
        }
        .message {
            padding: 10px 12px;
            border-radius: 10px;
            background: rgba(15, 23, 42, 0.8);
            border: 1px solid rgba(148, 163, 184, 0.35);
            font-size: 13px;
            margin-bottom: 16px;
            white-space: pre-wrap;
        }
        label {
            display: block;
            font-size: 13px;
            margin-bottom: 6px;
            color: #e5e7eb;
        }
        input[type="text"],
        input[type="password"] {
            width: 100%;
            padding: 9px 11px;
            border-radius: 10px;
            border: 1px solid rgba(148, 163, 184, 0.7);
            background: #020617;
            color: #e5e7eb;
            font-size: 14px;
            box-sizing: border-box;
            outline: none;
        }
        input[type="text"]:focus,
        input[type="password"]:focus {
            border-color: #38bdf8;
            box-shadow: 0 0 0 1px rgba(56, 189, 248, 0.35);
        }
        button {
            width: 100%;
            padding: 10px 14px;
            border-radius: 999px;
            border: none;
            background: linear-gradient(135deg, #38bdf8, #6366f1);
            color: white;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            margin-top: 10px;
        }
        button:hover {
            opacity: 0.95;
        }
        .done {
            padding: 16px 14px;
            border-radius: 12px;
            background: rgba(22, 163, 74, 0.12);
            border: 1px solid rgba(34, 197, 94, 0.5);
            color: #bbf7d0;
            font-size: 14px;
            margin-top: 12px;
        }
        .hint {
            font-size: 12px;
            color: #9ca3af;
            margin-top: 4px;
        }
    </style>
</head>
<body>
<div class="card">
    <h1>Telegram Login</h1>
    <p>Log this Railway service into your Telegram account using MadelineProto.</p>

    <?php if ($message !== ''): ?>
        <div class="message">
            <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <?php if ($step === 'done' && $loggedIn): ?>
        <div class="done">
            ✅ Logged in successfully.<br>
            You can now use <code>phone_lookup.php</code> to fetch contacts and photos.
        </div>
    <?php elseif ($step === 'phone'): ?>
        <form method="post">
            <input type="hidden" name="action" value="send_phone">
            <label for="phone">Phone number (with +251)</label>
            <input type="text" id="phone" name="phone" placeholder="+2519xxxxxxxx">
            <button type="submit">Send login code</button>
            <div class="hint">Telegram will send a login code to this phone or your Telegram app.</div>
        </form>
    <?php elseif ($step === 'code'): ?>
        <form method="post">
            <input type="hidden" name="action" value="send_code">
            <label for="code">Code you received</label>
            <input type="text" id="code" name="code" placeholder="12345">
            <button type="submit">Complete login</button>
            <div class="hint">Check your Telegram app or SMS for the code.</div>
        </form>
    <?php elseif ($step === 'password'): ?>
        <form method="post">
            <input type="hidden" name="action" value="send_password">
            <label for="password">Telegram 2FA password</label>
            <input type="password" id="password" name="password" placeholder="Your Telegram password">
            <button type="submit">Complete 2FA login</button>
            <div class="hint">This is required because your Telegram account has an extra password.</div>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
