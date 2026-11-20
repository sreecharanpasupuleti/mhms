<?php
require_once __DIR__ . '/config/db.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function passwordPageEscape($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// Show a one-time confirmation after clearing the authenticated session.
if (!empty($_SESSION['password_change_complete'])) {
    unset($_SESSION['password_change_complete']);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Password Changed | MHMS</title>

        <style>
            * { box-sizing: border-box; }

            body {
                margin: 0;
                min-height: 100vh;
                padding: 24px;
                display: grid;
                place-items: center;
                background: #f3f7fa;
                color: #173247;
                font-family: "Segoe UI", Arial, sans-serif;
            }

            main {
                width: 100%;
                max-width: 500px;
                padding: 32px;
                border: 1px solid #e1eaf0;
                border-radius: 20px;
                background: white;
            }

            h1 { font-size: 26px; }

            p {
                color: #62788a;
                line-height: 1.8;
            }

            .success {
                padding: 15px;
                border-radius: 10px;
                background: #e5f6eb;
                color: #166534;
            }

            a {
                display: inline-block;
                margin-top: 16px;
                padding: 13px 18px;
                border-radius: 9px;
                background: #087e8b;
                color: white;
                text-decoration: none;
            }
        </style>
    </head>
    <body>
        <main>
            <h1>Password changed</h1>

            <p class="success">
                Your password was updated successfully.
            </p>

            <p>
                You have been signed out of this browser session.
                Sign in again using your new password.
            </p>

            <a href="login.php">Return to sign in</a>
        </main>
    </body>
    </html>
    <?php
    exit;
}

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

try {
    $query = $pdo->prepare(
        'SELECT user_id, full_name, role, status
         FROM users WHERE user_id = ?'
    );
    $query->execute([$_SESSION['user_id']]);
    $account = $query->fetch();

} catch (PDOException $exception) {
    error_log($exception->getMessage());
    http_response_code(500);
    exit('Your account could not be checked. Please try again.');
}

if (!$account || $account['status'] !== 'active') {
    $_SESSION = [];
    session_destroy();

    header('Location: login.php');
    exit;
}

$dashboards = [
    'admin' => 'dashboard.php',
    'doctor' => 'doctor_dashboard.php',
    'patient' => 'patient_dashboard.php'
];

if (!isset($dashboards[$account['role']])) {
    http_response_code(403);
    exit('This account role is not supported.');
}

if (empty($_SESSION['change_password_token'])) {
    $_SESSION['change_password_token'] = bin2hex(random_bytes(32));
}

// Basic session-local throttling for this form only.
// This is not a replacement for account-wide production rate limiting.
if (!isset($_SESSION['password_attempt_window'])) {
    $_SESSION['password_attempt_window'] = time();
    $_SESSION['password_attempts'] = 0;
}

if (time() - $_SESSION['password_attempt_window'] >= 900) {
    $_SESSION['password_attempt_window'] = time();
    $_SESSION['password_attempts'] = 0;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['token'] ?? '';
    $currentInput = $_POST['current_password'] ?? '';
    $newInput = $_POST['new_password'] ?? '';
    $confirmInput = $_POST['confirm_password'] ?? '';

    $currentPassword = is_string($currentInput) ? $currentInput : '';
    $newPassword = is_string($newInput) ? $newInput : '';
    $confirmPassword = is_string($confirmInput) ? $confirmInput : '';

    if (
        !is_string($token)
        || !hash_equals($_SESSION['change_password_token'], $token)
    ) {
        $error = 'The form expired. Refresh the page and try again.';

    } elseif ($_SESSION['password_attempts'] >= 5) {
        $error = 'Too many incorrect current-password attempts. '
            . 'Wait until the 15-minute attempt window expires.';

    } elseif ($currentPassword === '') {
        $error = 'Enter your current password.';

    } elseif (
        strlen($newPassword) < 12
        || strlen($newPassword) > 72
        || strpos($newPassword, "\0") !== false
    ) {
        $error = 'Use a new password between 12 and 72 bytes long '
            . 'without null characters.';

    } elseif ($newPassword !== $confirmPassword) {
        $error = 'The new passwords do not match.';

    } else {
        try {
            $pdo->beginTransaction();

            $query = $pdo->prepare(
                'SELECT password_hash, status
                 FROM users WHERE user_id = ?
                 FOR UPDATE'
            );
            $query->execute([$account['user_id']]);
            $currentAccount = $query->fetch();

            if (
                !$currentAccount
                || $currentAccount['status'] !== 'active'
            ) {
                throw new RuntimeException(
                    'This account is no longer active.'
                );
            }

            if (!password_verify(
                $currentPassword,
                $currentAccount['password_hash']
            )) {
                $_SESSION['password_attempts']++;

                throw new RuntimeException(
                    'The current password is incorrect.'
                );
            }

            if (password_verify(
                $newPassword,
                $currentAccount['password_hash']
            )) {
                throw new RuntimeException(
                    'Choose a new password different from your current password.'
                );
            }

            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

            $query = $pdo->prepare(
                'UPDATE users SET password_hash = ? WHERE user_id = ?'
            );
            $query->execute([$newHash, $account['user_id']]);

            $patientId = null;

            if ($account['role'] === 'patient') {
                $query = $pdo->prepare(
                    'SELECT patient_id FROM patients WHERE user_id = ?'
                );
                $query->execute([$account['user_id']]);

                $linkedId = $query->fetchColumn();
                $patientId = $linkedId ? (int) $linkedId : null;
            }

            logAction(
                $pdo,
                $account['user_id'],
                $patientId,
                'CHANGE_PASSWORD',
                'User changed own password'
            );

            $pdo->commit();

            // Clear authentication and rotate the browser's session ID.
            $_SESSION = [];
            session_regenerate_id(true);

            $_SESSION['password_change_complete'] = true;

            header('Location: change_password.php');
            exit;

        } catch (RuntimeException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $error = $exception->getMessage();

        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log($exception->getMessage());

            $error = 'The password could not be changed. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Change Password | MHMS</title>

    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: #f3f7fa;
            color: #173247;
            font-family: "Segoe UI", Arial, sans-serif;
        }

        header {
            padding: 20px 5%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            background: #102a3d;
            color: white;
        }

        .brand {
            font-size: 22px;
            font-weight: 800;
            letter-spacing: 3px;
        }

        header a {
            color: white;
            text-decoration: none;
        }

        main {
            max-width: 650px;
            margin: auto;
            padding: 32px 24px;
        }

        h1 {
            margin: 0 0 12px;
            font-size: 29px;
        }

        .muted {
            color: #62788a;
            line-height: 1.8;
        }

        .card {
            padding: 28px;
            margin-top: 24px;
            border: 1px solid #e1eaf0;
            border-radius: 18px;
            background: white;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-size: 14px;
            font-weight: 600;
        }

        input {
            width: 100%;
            padding: 13px;
            margin-bottom: 22px;
            border: 1px solid #cad7e0;
            border-radius: 9px;
            background: white;
            color: #173247;
            font: inherit;
        }

        input:focus {
            outline: 3px solid #d9f0f2;
            border-color: #087e8b;
        }

        button {
            width: 100%;
            padding: 14px;
            border: 0;
            border-radius: 9px;
            background: #087e8b;
            color: white;
            font: inherit;
            font-weight: 600;
            cursor: pointer;
        }

        button:hover {
            background: #066772;
        }

        .error {
            padding: 16px;
            margin-bottom: 22px;
            border-radius: 10px;
            background: #feecec;
            color: #991b1b;
            line-height: 1.8;
        }

        .note {
            color: #62788a;
            font-size: 12px;
            line-height: 1.8;
        }

        @media (max-width: 500px) {
            main { padding: 24px 14px; }
            .card { padding: 22px; }
        }
    </style>
</head>
<body>
    <header>
        <div class="brand">MHMS</div>

        <a href="<?= passwordPageEscape(
            $dashboards[$account['role']]
        ) ?>">
            Dashboard
        </a>
    </header>

    <main>
        <h1>Change your password</h1>

        <p class="muted">
            Signed in as
            <?= passwordPageEscape($account['full_name']) ?>.
        </p>

        <section class="card">
            <?php if ($error !== ''): ?>
                <div class="error" role="alert">
                    <?= passwordPageEscape($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="change_password.php">
                <input
                    type="hidden"
                    name="token"
                    value="<?= passwordPageEscape(
                        $_SESSION['change_password_token']
                    ) ?>"
                >

                <label for="current_password">Current password</label>
                <input
                    id="current_password"
                    name="current_password"
                    type="password"
                    autocomplete="current-password"
                    required
                >

                <label for="new_password">New password</label>
                <input
                    id="new_password"
                    name="new_password"
                    type="password"
                    minlength="12"
                    maxlength="72"
                    autocomplete="new-password"
                    required
                >

                <label for="confirm_password">Confirm new password</label>
                <input
                    id="confirm_password"
                    name="confirm_password"
                    type="password"
                    minlength="12"
                    maxlength="72"
                    autocomplete="new-password"
                    required
                >

                <button type="submit">Change password and sign out</button>
            </form>

            <p class="note">
                For the demo, use an ASCII password of 12–72 characters.
                Password fields are cleared after an unsuccessful attempt.
                Passwords are never written to the audit log.
            </p>

            <p class="note">
                This version signs out this browser session only.
                Other already signed-in browser sessions are not
                automatically invalidated.
            </p>
        </section>
    </main>
</body>
</html>