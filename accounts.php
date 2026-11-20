<?php
require_once __DIR__ . '/config/db.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

function accountEscape($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

try {
    $query = $pdo->prepare(
        'SELECT role, status FROM users WHERE user_id = ?'
    );
    $query->execute([$_SESSION['user_id']]);
    $administrator = $query->fetch();

} catch (PDOException $exception) {
    error_log($exception->getMessage());
    http_response_code(500);
    exit('Your account could not be checked. Please try again.');
}

if (!$administrator || $administrator['status'] !== 'active') {
    $_SESSION = [];
    session_destroy();

    header('Location: login.php');
    exit;
}

if ($administrator['role'] !== 'admin') {
    http_response_code(403);
    exit('Only administrators can manage account status.');
}

if (empty($_SESSION['account_status_token'])) {
    $_SESSION['account_status_token'] = bin2hex(random_bytes(32));
}

$error = '';
$loadError = '';
$accounts = [];

$success = $_SESSION['account_status_success'] ?? '';
unset($_SESSION['account_status_success']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['token'] ?? '';
    $userInput = $_POST['user_id'] ?? '';
    $statusInput = $_POST['new_status'] ?? '';
    $reasonInput = $_POST['reason'] ?? '';

    $userId = is_string($userInput)
        ? filter_var(
            $userInput,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        )
        : false;

    $newStatus = is_string($statusInput) ? $statusInput : '';
    $reason = is_string($reasonInput) ? trim($reasonInput) : '';

    if (
        !is_string($token)
        || !hash_equals($_SESSION['account_status_token'], $token)
    ) {
        $error = 'The form expired. Refresh the page and try again.';

    } elseif (!$userId) {
        $error = 'Invalid account reference.';

    } elseif (!in_array($newStatus, ['active', 'inactive'], true)) {
        $error = 'Invalid account status.';

    } elseif ($reason === '' || strlen($reason) > 200) {
        $error = 'Enter a reason of up to 200 characters.';

    } else {
        try {
            $pdo->beginTransaction();

            $query = $pdo->prepare(
                'SELECT user_id, full_name, role, status
                 FROM users
                 WHERE user_id = ?
                 FOR UPDATE'
            );
            $query->execute([$userId]);
            $target = $query->fetch();

            if (!$target) {
                throw new RuntimeException('Account not found.');
            }

            // Protect administrator accounts and the current account.
            if (
                !in_array($target['role'], ['doctor', 'patient'], true)
                || $userId === (int) $_SESSION['user_id']
            ) {
                throw new RuntimeException(
                    'This page can change doctor and patient accounts only.'
                );
            }

            if ($target['status'] === $newStatus) {
                throw new RuntimeException(
                    'The account already has this status. Refresh the page.'
                );
            }

            $query = $pdo->prepare(
                'UPDATE users SET status = ? WHERE user_id = ?'
            );
            $query->execute([$newStatus, $userId]);

            $patientId = null;

            if ($target['role'] === 'patient') {
                $query = $pdo->prepare(
                    'SELECT patient_id FROM patients WHERE user_id = ?'
                );
                $query->execute([$userId]);
                $linkedId = $query->fetchColumn();

                $patientId = $linkedId ? (int) $linkedId : null;
            }

            logAction(
                $pdo,
                $_SESSION['user_id'],
                $patientId,
                'CHANGE_ACCOUNT_STATUS',
                'Account user ID ' . $userId
                . ' changed from ' . $target['status']
                . ' to ' . $newStatus
                . '. Reason: ' . $reason
            );

            $pdo->commit();

            $_SESSION['account_status_success'] =
                'Account status updated to ' . $newStatus
                . '. Existing records were not deleted.';

            $_SESSION['account_status_token'] =
                bin2hex(random_bytes(32));

            header('Location: accounts.php');
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
            $error = 'The account status could not be changed.';
        }
    }
}

$searchInput = $_GET['search'] ?? '';
$roleInput = $_GET['role'] ?? '';

$search = is_string($searchInput)
    ? substr(trim($searchInput), 0, 100)
    : '';

$role = is_string($roleInput)
    && in_array($roleInput, ['doctor', 'patient'], true)
        ? $roleInput
        : '';

try {
    $conditions = ["role IN ('doctor', 'patient')"];
    $parameters = [];

    if ($search !== '') {
        $conditions[] = '(full_name LIKE ? OR email LIKE ?)';
        $term = '%' . $search . '%';
        $parameters[] = $term;
        $parameters[] = $term;
    }

    if ($role !== '') {
        $conditions[] = 'role = ?';
        $parameters[] = $role;
    }

    $where = implode(' AND ', $conditions);

    $query = $pdo->prepare(
        "SELECT user_id, full_name, email, role, status
         FROM users
         WHERE $where
         ORDER BY user_id DESC
         LIMIT 100"
    );
    $query->execute($parameters);
    $accounts = $query->fetchAll();

} catch (PDOException $exception) {
    error_log($exception->getMessage());
    $loadError = 'Account records could not be loaded.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Account Status | MHMS</title>

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

        header a {
            color: white;
            text-decoration: none;
        }

        main {
            max-width: 1450px;
            margin: auto;
            padding: 32px 24px;
        }

        h1 { margin: 0 0 12px; font-size: 30px; }
        h2 { margin: 0 0 22px; font-size: 21px; }

        .muted {
            color: #62788a;
            line-height: 1.8;
        }

        .card {
            padding: 26px;
            margin: 24px 0;
            border: 1px solid #e1eaf0;
            border-radius: 18px;
            background: white;
        }

        .filters {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 18px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-size: 14px;
            font-weight: 600;
        }

        input, select {
            width: 100%;
            padding: 12px;
            margin-bottom: 16px;
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

        button, .button {
            display: inline-block;
            padding: 11px 15px;
            border: 0;
            border-radius: 8px;
            background: #087e8b;
            color: white;
            font: inherit;
            font-size: 13px;
            text-decoration: none;
            cursor: pointer;
        }

        button:hover, .button:hover {
            background: #066772;
        }

        .deactivate {
            background: #fff0f0;
            color: #a12626;
        }

        .deactivate:hover {
            background: #fbdcdc;
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
        }

        td {
            padding: 16px 10px;
            border-bottom: 1px solid #e1eaf0;
            vertical-align: top;
        }

        .status-form {
            min-width: 250px;
        }

        .status-form input {
            font-size: 12px;
            margin-bottom: 9px;
        }

        .badge {
            display: inline-block;
            padding: 5px 8px;
            border-radius: 6px;
            background: #edf1f5;
            color: #4b6173;
        }

        .badge.active {
            background: #e5f6eb;
            color: #166534;
        }

        .alert {
            padding: 16px;
            margin: 20px 0;
            border-radius: 10px;
            line-height: 1.8;
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

        .note {
            color: #62788a;
            font-size: 12px;
            line-height: 1.8;
        }

        @media (max-width: 600px) {
            main { padding: 24px 14px; }
            .card { padding: 20px; }
            .filters { grid-template-columns: 1fr; gap: 0; }
        }
    </style>
</head>
<body>
    <header>
        <div class="brand">MHMS</div>
        <a href="dashboard.php">Administrator dashboard</a>
    </header>

    <main>
        <h1>Account activation and deactivation</h1>

        <p class="muted">
            Manage doctor and patient login access without deleting
            their stored records.
        </p>

        <div class="alert warning">
            Deactivation does not cancel appointments, remove assignments,
            or erase clinical records. Review appointments separately
            when disabling a doctor account.
        </div>

        <?php if ($success !== ''): ?>
            <div class="alert success" role="status">
                <?= accountEscape($success) ?>
            </div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="alert error" role="alert">
                <?= accountEscape($error) ?>
            </div>
        <?php endif; ?>

        <section class="card">
            <h2>Find an account</h2>

            <form method="GET" action="accounts.php">
                <div class="filters">
                    <div>
                        <label for="search">Name or login email</label>
                        <input
                            id="search"
                            name="search"
                            maxlength="100"
                            value="<?= accountEscape($search) ?>"
                        >
                    </div>

                    <div>
                        <label for="role">Account role</label>
                        <select id="role" name="role">
                            <option value="">Doctors and patients</option>

                            <option
                                value="doctor"
                                <?= $role === 'doctor' ? 'selected' : '' ?>
                            >
                                Doctors
                            </option>

                            <option
                                value="patient"
                                <?= $role === 'patient' ? 'selected' : '' ?>
                            >
                                Patients
                            </option>
                        </select>
                    </div>
                </div>

                <button type="submit">Search accounts</button>
                <a class="button" href="accounts.php">Clear search</a>
            </form>
        </section>

        <?php if ($loadError !== ''): ?>
            <div class="alert error" role="alert">
                <?= accountEscape($loadError) ?>
            </div>
        <?php else: ?>
            <section class="card">
                <h2>Login accounts</h2>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Login email</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Change status</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php if (!$accounts): ?>
                                <tr>
                                    <td colspan="5">
                                        No matching doctor or patient accounts.
                                    </td>
                                </tr>
                            <?php endif; ?>

                            <?php foreach ($accounts as $target): ?>
                                <?php
                                $nextStatus = $target['status'] === 'active'
                                    ? 'inactive'
                                    : 'active';

                                $buttonLabel = $nextStatus === 'inactive'
                                    ? 'Deactivate account'
                                    : 'Activate account';
                                ?>

                                <tr>
                                    <td><?= accountEscape(
                                        $target['full_name']
                                    ) ?></td>

                                    <td><?= accountEscape(
                                        $target['email']
                                    ) ?></td>

                                    <td><?= accountEscape(
                                        $target['role']
                                    ) ?></td>

                                    <td>
                                        <span class="badge <?= 
                                            $target['status'] === 'active'
                                                ? 'active' : ''
                                        ?>">
                                            <?= accountEscape(
                                                $target['status']
                                            ) ?>
                                        </span>
                                    </td>

                                    <td>
                                        <form
                                            method="POST"
                                            action="accounts.php"
                                            class="status-form"
                                            onsubmit="return confirm('Change the status of this account?')"
                                        >
                                            <input
                                                type="hidden"
                                                name="token"
                                                value="<?= accountEscape(
                                                    $_SESSION['account_status_token']
                                                ) ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="user_id"
                                                value="<?= (int) $target['user_id'] ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="new_status"
                                                value="<?= accountEscape(
                                                    $nextStatus
                                                ) ?>"
                                            >

                                            <label for="reason-<?= (int) $target['user_id'] ?>">
                                                Reason for change
                                            </label>

                                            <input
                                                id="reason-<?= (int) $target['user_id'] ?>"
                                                name="reason"
                                                maxlength="200"
                                                placeholder="Short administrative reason"
                                                required
                                            >

                                            <button
                                                type="submit"
                                                class="<?= $nextStatus === 'inactive'
                                                    ? 'deactivate' : '' ?>"
                                            >
                                                <?= accountEscape($buttonLabel) ?>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <p class="note">
                    Showing up to 100 matching accounts.
                    Administrator accounts are excluded.
                    Passwords and account roles cannot be changed here.
                </p>
            </section>
        <?php endif; ?>

        <p class="note">
            Academic prototype. Updated pages reject inactive accounts
            when requested again. This version does not centrally revoke
            every session file or remove information already displayed
            in an open browser tab.
        </p>
    </main>
</body>
</html>