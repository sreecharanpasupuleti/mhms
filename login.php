<?php
require_once __DIR__ . '/config/db.php';

header('Cache-Control: no-store');

$error = '';

if (empty($_SESSION['login_token'])) {
    $_SESSION['login_token'] = bin2hex(random_bytes(32));
}

function signInEscape($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['token'] ?? '';
    $emailInput = $_POST['email'] ?? '';
    $passwordInput = $_POST['password'] ?? '';

    $email = is_string($emailInput) ? trim($emailInput) : '';
    $password = is_string($passwordInput) ? $passwordInput : '';

    if (
        !is_string($token)
        || !hash_equals($_SESSION['login_token'], $token)
    ) {
        $error = 'The form expired. Refresh the page and try again.';
    } elseif (
        strlen($email) > 120
        || !filter_var($email, FILTER_VALIDATE_EMAIL)
        || $password === ''
    ) {
        $error = 'Enter a valid email and your password.';
    } else {
        try {
            $query = $pdo->prepare(
                "SELECT user_id, full_name, password_hash, role
                 FROM users
                 WHERE email = ? AND status = 'active'
                 LIMIT 1"
            );
            $query->execute([$email]);
            $user = $query->fetch();

            if (
                !$user
                || !password_verify($password, $user['password_hash'])
            ) {
                $error = 'Invalid email or password.';
            } else {
                $routes = [
                    'admin' => 'dashboard.php',
                    'doctor' => 'doctor_dashboard.php',
                    'patient' => 'patient_dashboard.php'
                ];

                $profileId = null;

                if (!isset($routes[$user['role']])) {
                    $error = 'This account role is not supported.';
                } elseif ($user['role'] === 'doctor') {
                    $query = $pdo->prepare(
                        'SELECT doctor_id FROM doctors WHERE user_id = ?'
                    );
                    $query->execute([$user['user_id']]);
                    $profileId = $query->fetchColumn();

                    if (!$profileId) {
                        $error = 'Doctor profile missing. Contact the administrator.';
                    }
                } elseif ($user['role'] === 'patient') {
                    $query = $pdo->prepare(
                        'SELECT patient_id FROM patients WHERE user_id = ?'
                    );
                    $query->execute([$user['user_id']]);
                    $profileId = $query->fetchColumn();

                    if (!$profileId) {
                        $error = 'Patient profile missing. Contact the administrator.';
                    }
                }

                if ($error === '') {
                    logAction(
                        $pdo,
                        $user['user_id'],
                        $user['role'] === 'patient' ? $profileId : null,
                        'LOGIN',
                        'User logged in'
                    );

                    session_regenerate_id(true);
                    $_SESSION = [];

                    $_SESSION['user_id'] = (int) $user['user_id'];
                    $_SESSION['full_name'] = $user['full_name'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['logout_token'] = bin2hex(random_bytes(32));

                    if ($user['role'] === 'doctor') {
                        $_SESSION['doctor_id'] = (int) $profileId;
                    } elseif ($user['role'] === 'patient') {
                        $_SESSION['patient_id'] = (int) $profileId;
                    }

                    header('Location: ' . $routes[$user['role']]);
                    exit;
                }
            }
        } catch (PDOException $exception) {
            error_log($exception->getMessage());
            $error = 'Login could not be completed. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in | MHMS</title>

    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            padding: 24px;
            display: grid;
            place-items: center;
            background:
                radial-gradient(circle at top left, #164b60, transparent 55%),
                #091e30;
            color: #183247;
            font-family: "Segoe UI", Arial, sans-serif;
        }

        .layout {
            width: 100%;
            max-width: 1000px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            overflow: hidden;
            border-radius: 24px;
            background: white;
            box-shadow: 0 24px 80px rgba(0, 0, 0, 0.25);
        }

        .intro {
            padding: 52px 40px;
            background: linear-gradient(145deg, #087e8b, #10465e);
            color: white;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .brand {
            font-size: 20px;
            font-weight: 700;
            letter-spacing: 3px;
            margin-bottom: 48px;
        }

        .intro h1 {
            margin: 0 0 20px;
            font-size: clamp(28px, 4vw, 42px);
            line-height: 1.15;
        }

        .intro p {
            color: #d4ebee;
            line-height: 1.8;
        }

        .tag {
            margin-top: 32px;
            color: #c1e4e8;
            font-size: 12px;
            letter-spacing: 1px;
        }

        .panel {
            padding: 52px 40px;
            align-self: center;
        }

        h2 {
            margin: 0 0 10px;
            font-size: 28px;
        }

        .subtitle {
            margin-bottom: 28px;
            color: #647789;
            line-height: 1.7;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-size: 14px;
            font-weight: 600;
        }

        input {
            width: 100%;
            padding: 14px;
            margin-bottom: 20px;
            border: 1px solid #cad7e0;
            border-radius: 10px;
            font: inherit;
        }

        input:focus {
            outline: 3px solid #d8f1f3;
            border-color: #087e8b;
        }

        .password-box { position: relative; }
        .password-box input { padding-right: 70px; }

        .toggle {
            position: absolute;
            top: 9px;
            right: 8px;
            padding: 7px;
            border: 0;
            background: transparent;
            color: #087e8b;
            cursor: pointer;
            font: inherit;
            font-size: 13px;
        }

        .submit {
            width: 100%;
            padding: 15px;
            border: 0;
            border-radius: 10px;
            background: #087e8b;
            color: white;
            font: inherit;
            font-weight: 600;
            cursor: pointer;
        }

        .submit:hover { background: #066772; }

        .error {
            padding: 14px;
            margin-bottom: 20px;
            border-radius: 10px;
            background: #fff0f0;
            color: #a12626;
            line-height: 1.7;
        }

        .note {
            margin-top: 24px;
            color: #748596;
            font-size: 12px;
            line-height: 1.8;
        }

        @media (max-width: 700px) {
            .layout { grid-template-columns: 1fr; }
            .intro, .panel { padding: 30px 24px; }
            .brand { margin-bottom: 24px; }
            .tag { margin-top: 16px; }
        }
    </style>
</head>
<body>
    <main class="layout">
        <section class="intro">
            <div class="brand">MHMS</div>

            <h1>A clearer view of every patient’s history.</h1>

            <p>
                A medical history workspace for administrators,
                doctors, and patients.
            </p>

            <div class="tag">
                ACADEMIC DEMONSTRATION · FICTIONAL DATA ONLY
            </div>
        </section>

        <section class="panel">
            <h2>Welcome back</h2>

            <p class="subtitle">
                Sign in to open the workspace linked to your account.
            </p>

            <?php if ($error !== ''): ?>
                <div class="error" role="alert">
                    <?= signInEscape($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="login.php">
                <input
                    type="hidden"
                    name="token"
                    value="<?= signInEscape($_SESSION['login_token']) ?>"
                >

                <label for="email">Email address</label>
                <input
                    id="email"
                    name="email"
                    type="email"
                    maxlength="120"
                    autocomplete="username"
                    placeholder="Enter your login email"
                    required
                >

                <label for="password">Password</label>

                <div class="password-box">
                    <input
                        id="password"
                        name="password"
                        type="password"
                        autocomplete="current-password"
                        placeholder="Enter your password"
                        required
                    >

                    <button
                        id="togglePassword"
                        class="toggle"
                        type="button"
                        aria-controls="password"
                        aria-pressed="false"
                    >Show</button>
                </div>

                <button class="submit" type="submit">Sign in</button>
            </form>

            <p class="note">
                Demo accounts are created by the administrator.
                Use fictional data only.
            </p>
        </section>
    </main>

    <script>
        const toggle = document.getElementById('togglePassword');
        const password = document.getElementById('password');

        toggle.addEventListener('click', () => {
            const reveal = password.type === 'password';

            password.type = reveal ? 'text' : 'password';
            toggle.textContent = reveal ? 'Hide' : 'Show';
            toggle.setAttribute('aria-pressed', String(reveal));
        });
    </script>
</body>
</html>