<?php
require_once __DIR__ . '/config/db.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$accountQuery = $pdo->prepare(
    'SELECT role, status FROM users WHERE user_id = ?'
);
$accountQuery->execute([$_SESSION['user_id']]);
$account = $accountQuery->fetch();

if (!$account || $account['status'] !== 'active') {
    $_SESSION = [];
    session_destroy();

    header('Location: login.php');
    exit;
}

if ($account['role'] !== 'admin') {
    http_response_code(403);
    exit('Only administrators can manage doctor accounts.');
}

function doctorEscape($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

if (empty($_SESSION['doctor_form_token'])) {
    $_SESSION['doctor_form_token'] = bin2hex(random_bytes(32));
}

$values = [
    'full_name' => '',
    'email' => '',
    'department_id' => '',
    'specialization' => '',
    'license_no' => ''
];

$error = '';
$loadError = '';
$listError = '';
$departments = [];
$doctors = [];

$success = $_SESSION['doctor_success'] ?? '';
unset($_SESSION['doctor_success']);

try {
    $departments = $pdo->query(
        'SELECT department_id, department_name
         FROM departments
         ORDER BY department_name'
    )->fetchAll();
} catch (PDOException $exception) {
    error_log($exception->getMessage());
    $loadError = 'Departments could not be loaded.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($values as $field => $unused) {
        $input = $_POST[$field] ?? '';
        $values[$field] = is_string($input) ? trim($input) : '';
    }

    $passwordInput = $_POST['password'] ?? '';
    $confirmInput = $_POST['confirm_password'] ?? '';

    $password = is_string($passwordInput) ? $passwordInput : '';
    $confirmPassword = is_string($confirmInput) ? $confirmInput : '';

    $token = $_POST['token'] ?? '';

    $departmentId = filter_var(
        $values['department_id'],
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    $validDepartmentIds = array_map(
        'intval',
        array_column($departments, 'department_id')
    );

    if (
        !is_string($token)
        || !hash_equals($_SESSION['doctor_form_token'], $token)
    ) {
        $error = 'The form expired. Refresh the page and try again.';
    } elseif (
        $values['full_name'] === ''
        || strlen($values['full_name']) > 100
    ) {
        $error = 'Enter a doctor name of up to 100 characters.';
    } elseif (
        strlen($values['email']) > 120
        || !filter_var($values['email'], FILTER_VALIDATE_EMAIL)
    ) {
        $error = 'Enter a valid email address.';
    } elseif (
        !$departmentId
        || !in_array($departmentId, $validDepartmentIds, true)
    ) {
        $error = 'Select a valid department.';
    } elseif (
        $values['specialization'] === ''
        || strlen($values['specialization']) > 100
    ) {
        $error = 'Enter a specialization of up to 100 characters.';
    } elseif (
        $values['license_no'] === ''
        || strlen($values['license_no']) > 50
    ) {
        $error = 'Enter a demo license number of up to 50 characters.';
    } elseif (strlen($password) < 12 || strlen($password) > 72) {
        $error = 'Use a password between 12 and 72 bytes long.';
    } elseif ($password !== $confirmPassword) {
        $error = 'The passwords do not match.';
    } else {
        try {
            $check = $pdo->prepare(
                'SELECT user_id FROM users WHERE email = ?'
            );
            $check->execute([$values['email']]);

            if ($check->fetchColumn()) {
                $error = 'This email is already used by an account.';
            } else {
                $pdo->beginTransaction();

                $insertUser = $pdo->prepare(
                    "INSERT INTO users
                    (full_name, email, password_hash, role, status)
                    VALUES (?, ?, ?, 'doctor', 'active')"
                );

                $insertUser->execute([
                    $values['full_name'],
                    $values['email'],
                    password_hash($password, PASSWORD_DEFAULT)
                ]);

                $doctorUserId = (int) $pdo->lastInsertId();

                $insertDoctor = $pdo->prepare(
                    'INSERT INTO doctors
                     (user_id, department_id, specialization, license_no)
                     VALUES (?, ?, ?, ?)'
                );

                $insertDoctor->execute([
                    $doctorUserId,
                    $departmentId,
                    $values['specialization'],
                    $values['license_no']
                ]);

                logAction(
                    $pdo,
                    $_SESSION['user_id'],
                    null,
                    'ADD_DOCTOR',
                    'Doctor account created; user ID ' . $doctorUserId
                );

                $pdo->commit();

                $_SESSION['doctor_success'] =
                    'Doctor account created successfully. '
                    . 'Doctor login testing will follow after '
                    . 'the doctor workspace is built.';

                $_SESSION['doctor_form_token'] =
                    bin2hex(random_bytes(32));

                header('Location: doctors.php');
                exit;
            }
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log($exception->getMessage());

            $error = 'The doctor account could not be created. '
                . 'Check that the email is not already in use and try again.';
        }
    }
}

try {
    $doctors = $pdo->query(
        "SELECT d.doctor_id, d.specialization, d.license_no,
                u.full_name, u.email, u.status,
                dep.department_name
         FROM doctors d
         JOIN users u ON d.user_id = u.user_id
         LEFT JOIN departments dep
             ON d.department_id = dep.department_id
         ORDER BY d.doctor_id DESC
         LIMIT 100"
    )->fetchAll();
} catch (PDOException $exception) {
    error_log($exception->getMessage());
    $listError = 'Doctor records could not be loaded.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Doctor Management | MHMS</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #f3f7fa;
            color: #173247;
            font-family: "Segoe UI", Arial, sans-serif;
        }

        .topbar {
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

        nav {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }

        nav a {
            color: white;
            text-decoration: none;
            font-size: 14px;
        }

        main {
            max-width: 1450px;
            margin: auto;
            padding: 32px 24px;
        }

        h1 {
            margin: 0 0 12px;
            font-size: 30px;
        }

        h2 {
            margin: 0 0 22px;
            font-size: 21px;
        }

        .subtitle {
            margin-bottom: 26px;
            color: #62788a;
            line-height: 1.8;
        }

        .layout {
            display: grid;
            grid-template-columns: 390px minmax(0, 1fr);
            gap: 24px;
            align-items: start;
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

        input,
        select {
            width: 100%;
            padding: 13px;
            margin-bottom: 19px;
            border: 1px solid #cad7e0;
            border-radius: 9px;
            background: white;
            color: #173247;
            font: inherit;
            font-size: 14px;
        }

        input:focus,
        select:focus {
            outline: 3px solid #d9f0f2;
            border-color: #087e8b;
        }

        button {
            width: 100%;
            padding: 14px 20px;
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

        .alert {
            padding: 16px;
            margin-bottom: 22px;
            border-radius: 10px;
            line-height: 1.7;
        }

        .success {
            background: #e5f6eb;
            color: #166534;
        }

        .error {
            background: #feecec;
            color: #991b1b;
        }

        .warning {
            background: #fff8e8;
            color: #795718;
        }

        .table-wrap {
            overflow-x: auto;
        }

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
            white-space: nowrap;
        }

        td {
            padding: 16px 10px;
            border-bottom: 1px solid #e1eaf0;
        }

        tbody tr:hover {
            background: #f8fbfc;
        }

        .badge {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 6px;
            background: #edf1f5;
            color: #4b6173;
            font-size: 12px;
        }

        .badge.active {
            background: #e5f6eb;
            color: #166534;
        }

        .note {
            color: #62788a;
            font-size: 12px;
            line-height: 1.8;
        }

        @media (max-width: 1000px) {
            .layout {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 500px) {
            main {
                padding: 24px 14px;
            }

            .card {
                padding: 20px;
            }

            .topbar {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>
    <header class="topbar">
        <div class="brand">MHMS</div>

        <nav aria-label="Page navigation">
            <a href="dashboard.php">Dashboard</a>
            <a href="patients.php">Patients</a>
            <a href="patient_view.php">Patient profiles</a>
        </nav>
    </header>

    <main>
        <h1>Doctor management</h1>

        <p class="subtitle">
            Create fictional doctor accounts and view their department
            and specialization details.
        </p>

        <div class="alert warning">
            Keep using your administrator login for now.
            The doctor workspace has not been built yet.
        </div>

        <?php if ($success !== ''): ?>
            <div class="alert success" role="status">
                <?= doctorEscape($success) ?>
            </div>
        <?php endif; ?>

        <div class="layout">
            <section class="card">
                <h2>Create doctor account</h2>

                <?php if ($error !== ''): ?>
                    <div class="alert error" role="alert">
                        <?= doctorEscape($error) ?>
                    </div>
                <?php endif; ?>

                <?php if ($loadError !== ''): ?>
                    <div class="alert error" role="alert">
                        <?= doctorEscape($loadError) ?>
                    </div>
                <?php elseif (!$departments): ?>
                    <p>
                        No departments are available. Stop here and
                        report this message before creating an account.
                    </p>
                <?php else: ?>
                    <form method="POST" action="doctors.php">
                        <input
                            type="hidden"
                            name="token"
                            value="<?= doctorEscape(
                                $_SESSION['doctor_form_token']
                            ) ?>"
                        >

                        <label for="full_name">Doctor name *</label>
                        <input
                            id="full_name"
                            name="full_name"
                            maxlength="100"
                            value="<?= doctorEscape($values['full_name']) ?>"
                            required
                        >

                        <label for="email">Login email *</label>
                        <input
                            id="email"
                            name="email"
                            type="email"
                            maxlength="120"
                            value="<?= doctorEscape($values['email']) ?>"
                            required
                        >

                        <label for="department_id">Department *</label>
                        <select
                            id="department_id"
                            name="department_id"
                            required
                        >
                            <option value="">Select department</option>

                            <?php foreach ($departments as $department): ?>
                                <option
                                    value="<?= (int) $department[
                                        'department_id'
                                    ] ?>"
                                    <?= (string) $department['department_id']
                                        === $values['department_id']
                                        ? 'selected' : '' ?>
                                >
                                    <?= doctorEscape(
                                        $department['department_name']
                                    ) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <label for="specialization">Specialization *</label>
                        <input
                            id="specialization"
                            name="specialization"
                            maxlength="100"
                            value="<?= doctorEscape(
                                $values['specialization']
                            ) ?>"
                            required
                        >

                        <label for="license_no">Demo license number *</label>
                        <input
                            id="license_no"
                            name="license_no"
                            maxlength="50"
                            value="<?= doctorEscape($values['license_no']) ?>"
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

                        <label for="confirm_password">Confirm password *</label>
                        <input
                            id="confirm_password"
                            name="confirm_password"
                            type="password"
                            minlength="12"
                            maxlength="72"
                            autocomplete="new-password"
                            required
                        >

                        <button type="submit">Create doctor account</button>

                        <p class="note">
                            All fields are required.
                            Use an ASCII demo password of 12–72 characters.
                            Password fields are cleared if validation fails.
                        </p>

                        <p class="note">
                            License details are stored as entered.
                            This prototype does not verify medical licenses.
                        </p>
                    </form>
                <?php endif; ?>
            </section>

            <section class="card">
                <h2>Doctor accounts</h2>

                <?php if ($listError !== ''): ?>
                    <div class="alert error" role="alert">
                        <?= doctorEscape($listError) ?>
                    </div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Department</th>
                                    <th>Specialization</th>
                                    <th>Status</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php if (!$doctors): ?>
                                    <tr>
                                        <td colspan="5">
                                            No doctors created yet.
                                        </td>
                                    </tr>
                                <?php endif; ?>

                                <?php foreach ($doctors as $doctor): ?>
                                    <tr>
                                        <td>
                                            <?= doctorEscape(
                                                $doctor['full_name']
                                            ) ?>
                                        </td>

                                        <td>
                                            <?= doctorEscape(
                                                $doctor['email']
                                            ) ?>
                                        </td>

                                        <td>
                                            <?= doctorEscape(
                                                $doctor['department_name']
                                                ?: 'Not assigned'
                                            ) ?>
                                        </td>

                                        <td>
                                            <?= doctorEscape(
                                                $doctor['specialization']
                                            ) ?>
                                        </td>

                                        <td>
                                            <span class="badge <?= 
                                                $doctor['status'] === 'active'
                                                ? 'active' : ''
                                            ?>">
                                                <?= doctorEscape(
                                                    $doctor['status']
                                                ) ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <p class="note">
                        Showing up to 100 doctor accounts, newest first.
                        Use fictional accounts for this demonstration.
                    </p>
                <?php endif; ?>
            </section>
        </div>
    </main>
</body>
</html>