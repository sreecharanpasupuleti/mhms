<?php
require_once __DIR__ . '/config/db.php';

header('Cache-Control: no-store');

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

function patientAccountEscape($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

try {
    $query = $pdo->prepare(
        'SELECT role, status FROM users WHERE user_id = ?'
    );
    $query->execute([$_SESSION['user_id']]);
    $account = $query->fetch();

} catch (PDOException $exception) {
    error_log($exception->getMessage());
    http_response_code(500);
    exit('Your account could not be checked. Try again.');
}

if (!$account || $account['status'] !== 'active') {
    $_SESSION = [];
    session_destroy();

    header('Location: login.php');
    exit;
}

if ($account['role'] !== 'admin') {
    http_response_code(403);
    exit('Only administrators can create patient accounts.');
}

if (empty($_SESSION['patient_account_token'])) {
    $_SESSION['patient_account_token'] = bin2hex(random_bytes(32));
}

$patients = [];
$linkedAccounts = [];
$error = '';
$loadError = '';

$selectedPatient = '';
$email = '';

$success = $_SESSION['patient_account_success'] ?? '';
unset($_SESSION['patient_account_success']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['token'] ?? '';

    $patientInput = $_POST['patient_id'] ?? '';
    $emailInput = $_POST['email'] ?? '';
    $passwordInput = $_POST['password'] ?? '';
    $confirmInput = $_POST['confirm_password'] ?? '';

    $selectedPatient = is_string($patientInput)
        ? trim($patientInput)
        : '';

    $email = is_string($emailInput) ? trim($emailInput) : '';
    $password = is_string($passwordInput) ? $passwordInput : '';
    $confirmPassword = is_string($confirmInput) ? $confirmInput : '';

    $patientId = filter_var(
        $selectedPatient,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if (
        !is_string($token)
        || !hash_equals($_SESSION['patient_account_token'], $token)
    ) {
        $error = 'The form expired. Refresh the page and try again.';

    } elseif (!$patientId) {
        $error = 'Select a patient.';

    } elseif (
        strlen($email) > 120
        || !filter_var($email, FILTER_VALIDATE_EMAIL)
    ) {
        $error = 'Enter a valid login email address.';

    } elseif (strlen($password) < 12 || strlen($password) > 72) {
        $error = 'Use a password between 12 and 72 bytes long.';

    } elseif ($password !== $confirmPassword) {
        $error = 'The passwords do not match.';

    } else {
        try {
            $pdo->beginTransaction();

            $query = $pdo->prepare(
                'SELECT patient_id, full_name, user_id
                 FROM patients
                 WHERE patient_id = ?
                 FOR UPDATE'
            );
            $query->execute([$patientId]);
            $patient = $query->fetch();

            if (!$patient) {
                throw new RuntimeException(
                    'The selected patient no longer exists.'
                );
            }

            if (!empty($patient['user_id'])) {
                throw new RuntimeException(
                    'This patient already has a linked login account.'
                );
            }

            $query = $pdo->prepare(
                'SELECT user_id FROM users WHERE email = ?'
            );
            $query->execute([$email]);

            if ($query->fetchColumn()) {
                throw new RuntimeException(
                    'This email is already used by another account.'
                );
            }

            $query = $pdo->prepare(
                "INSERT INTO users
                 (full_name, email, password_hash, role, status)
                 VALUES (?, ?, ?, 'patient', 'active')"
            );

            $query->execute([
                $patient['full_name'],
                $email,
                password_hash($password, PASSWORD_DEFAULT)
            ]);

            $patientUserId = (int) $pdo->lastInsertId();

            $query = $pdo->prepare(
                'UPDATE patients SET user_id = ?
                 WHERE patient_id = ? AND user_id IS NULL'
            );

            $query->execute([$patientUserId, $patientId]);

            if ($query->rowCount() !== 1) {
                throw new RuntimeException(
                    'The account link could not be created.'
                );
            }

            logAction(
                $pdo,
                $_SESSION['user_id'],
                $patientId,
                'CREATE_PATIENT_ACCOUNT',
                'Patient login created; user ID ' . $patientUserId
            );

            $pdo->commit();

            $_SESSION['patient_account_success'] =
                'Patient account created and linked successfully. '
                . 'Existing medical records were not changed.';

            $_SESSION['patient_account_token'] =
                bin2hex(random_bytes(32));

            header('Location: patient_accounts.php');
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

            $error = 'The account could not be created. '
                . 'Check that the email is not already in use '
                . 'and try again.';
        }
    }
}

try {
    $patients = $pdo->query(
        'SELECT patient_id, full_name, mrn
         FROM patients
         WHERE user_id IS NULL
         ORDER BY full_name, patient_id
         LIMIT 500'
    )->fetchAll();

    $linkedAccounts = $pdo->query(
        "SELECT p.full_name, p.mrn, u.email, u.status
         FROM patients p
         JOIN users u ON p.user_id = u.user_id
         WHERE u.role = 'patient'
         ORDER BY u.user_id DESC
         LIMIT 100"
    )->fetchAll();

} catch (PDOException $exception) {
    error_log($exception->getMessage());
    $loadError = 'Patient account information could not be loaded.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Patient Accounts | MHMS</title>

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
            background: #102a3d;
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
        }

        .brand {
            font-size: 22px;
            font-weight: 800;
            letter-spacing: 3px;
        }

        nav {
            display: flex;
            gap: 18px;
            flex-wrap: wrap;
        }

        nav a {
            color: white;
            text-decoration: none;
            font-size: 14px;
        }

        main {
            max-width: 1300px;
            margin: auto;
            padding: 32px 24px;
        }

        h1 { margin: 0 0 12px; font-size: 30px; }
        h2 { margin: 0 0 22px; font-size: 21px; }

        .muted {
            color: #62788a;
            line-height: 1.8;
        }

        .layout {
            display: grid;
            grid-template-columns: 390px minmax(0, 1fr);
            gap: 24px;
            align-items: start;
            margin-top: 24px;
        }

        .card {
            padding: 26px;
            background: white;
            border: 1px solid #e1eaf0;
            border-radius: 18px;
            min-width: 0;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-size: 14px;
            font-weight: 600;
        }

        input, select {
            width: 100%;
            padding: 13px;
            margin-bottom: 20px;
            border: 1px solid #cad7e0;
            border-radius: 9px;
            background: white;
            color: #173247;
            font: inherit;
            font-size: 14px;
        }

        input:focus, select:focus {
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

        button:hover { background: #066772; }

        .alert {
            padding: 16px;
            margin: 20px 0;
            border-radius: 10px;
            line-height: 1.8;
        }

        .success { background: #e5f6eb; color: #166534; }
        .error { background: #feecec; color: #991b1b; }
        .warning { background: #fff8e8; color: #795718; }

        .table-wrap { overflow-x: auto; }

        table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            font-size: 13px;
        }

        th {
            padding: 13px 10px;
            background: #f3f7fa;
            color: #62788a;
        }

        td {
            padding: 15px 10px;
            border-bottom: 1px solid #e1eaf0;
        }

        .badge {
            display: inline-block;
            padding: 5px 8px;
            border-radius: 6px;
            background: #eaf0f4;
            color: #465f72;
        }

        .note {
            color: #62788a;
            font-size: 12px;
            line-height: 1.8;
        }

        @media (max-width: 950px) {
            .layout { grid-template-columns: 1fr; }
        }

        @media (max-width: 500px) {
            main { padding: 24px 14px; }
            .card { padding: 20px; }

            header {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>
    <header>
        <div class="brand">MHMS</div>

        <nav aria-label="Page navigation">
            <a href="dashboard.php">Dashboard</a>
            <a href="patients.php">Patients</a>
            <a href="patient_view.php">Patient profiles</a>
        </nav>
    </header>

    <main>
        <h1>Patient login accounts</h1>

        <p class="muted">
            Link a login account to an existing fictional patient record.
            This does not create a second patient profile.
        </p>

        <div class="alert warning">
            This step creates the account only.
            Patient login is not enabled in the current login page yet.
            We will build the patient dashboard and enable login next.
        </div>

        <?php if ($success !== ''): ?>
            <div class="alert success" role="status">
                <?= patientAccountEscape($success) ?>
            </div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="alert error" role="alert">
                <?= patientAccountEscape($error) ?>
            </div>
        <?php endif; ?>

        <?php if ($loadError !== ''): ?>
            <div class="alert error" role="alert">
                <?= patientAccountEscape($loadError) ?>
            </div>
        <?php else: ?>
            <div class="layout">
                <section class="card">
                    <h2>Create patient login</h2>

                    <?php if (!$patients): ?>
                        <p class="muted">
                            No unlinked patient records are available.
                            Existing patients may already have accounts.
                            Check the linked-account list before adding
                            another patient.
                        </p>
                    <?php else: ?>
                        <form method="POST" action="patient_accounts.php">
                            <input
                                type="hidden"
                                name="token"
                                value="<?= patientAccountEscape(
                                    $_SESSION['patient_account_token']
                                ) ?>"
                            >

                            <label for="patient_id">Existing patient *</label>

                            <select id="patient_id" name="patient_id" required>
                                <option value="">Select patient</option>

                                <?php foreach ($patients as $patient): ?>
                                    <option
                                        value="<?= (int) $patient['patient_id'] ?>"
                                        <?= $selectedPatient ===
                                            (string) $patient['patient_id']
                                            ? 'selected' : '' ?>
                                    >
                                        <?= patientAccountEscape(
                                            $patient['full_name']
                                            . ' — ' . $patient['mrn']
                                        ) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <label for="email">Login email *</label>
                            <input
                                id="email"
                                name="email"
                                type="email"
                                maxlength="120"
                                value="<?= patientAccountEscape($email) ?>"
                                required
                            >

                            <label for="password">Password *</label>
                            <input
                                id="password"
                                name="password"
                                type="password"
                                minlength="12"
                                maxlength="72"
                                autocomplete="new-password"
                                required
                            >

                            <label for="confirm_password">
                                Confirm password *
                            </label>

                            <input
                                id="confirm_password"
                                name="confirm_password"
                                type="password"
                                minlength="12"
                                maxlength="72"
                                autocomplete="new-password"
                                required
                            >

                            <button type="submit">
                                Create patient account
                            </button>

                            <p class="note">
                                Use fictional demo credentials.
                                For this test, use an ASCII password
                                of 12–72 characters.
                                Passwords are not displayed after saving.
                            </p>

                            <p class="note">
                                The login email is separate from the
                                patient’s contact email. This form does
                                not overwrite the contact email.
                            </p>
                        </form>
                    <?php endif; ?>
                </section>

                <section class="card">
                    <h2>Linked patient accounts</h2>

                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Patient</th>
                                    <th>Medical record no.</th>
                                    <th>Login email</th>
                                    <th>Status</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php if (!$linkedAccounts): ?>
                                    <tr>
                                        <td colspan="4">
                                            No linked patient accounts yet.
                                        </td>
                                    </tr>
                                <?php endif; ?>

                                <?php foreach ($linkedAccounts as $linked): ?>
                                    <tr>
                                        <td><?= patientAccountEscape(
                                            $linked['full_name']
                                        ) ?></td>

                                        <td><?= patientAccountEscape(
                                            $linked['mrn']
                                        ) ?></td>

                                        <td><?= patientAccountEscape(
                                            $linked['email']
                                        ) ?></td>

                                        <td>
                                            <span class="badge">
                                                <?= patientAccountEscape(
                                                    $linked['status']
                                                ) ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <p class="note">
                        Showing the latest 100 linked patient accounts.
                        Patient selector: up to 500 unlinked records.
                    </p>
                </section>
            </div>
        <?php endif; ?>

        <p class="note">
            Academic demonstration only.
            Creating a real patient account would also require an
            appropriate identity-verification and account-delivery process.
            Those processes are not implemented here.
        </p>
    </main>
</body>
</html>