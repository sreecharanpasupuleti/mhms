<?php
require_once __DIR__ . '/config/db.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

function auditEscape($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function auditInput($key, $maximumLength = 150) {
    $value = $_GET[$key] ?? '';

    return is_string($value)
        ? substr(trim($value), 0, $maximumLength)
        : '';
}

function auditDate($value) {
    if ($value === '') {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

    return $date && $date->format('Y-m-d') === $value
        ? $date
        : false;
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
    exit('Your account could not be checked. Please try again.');
}

if (!$account || $account['status'] !== 'active') {
    $_SESSION = [];
    session_destroy();

    header('Location: login.php');
    exit;
}

if ($account['role'] !== 'admin') {
    http_response_code(403);
    exit('Only administrators can view audit logs.');
}

$userInput = auditInput('user_id', 20);
$action = auditInput('action', 100);
$patientSearch = auditInput('patient', 100);
$fromInput = auditInput('from', 10);
$toInput = auditInput('to', 10);

$userId = $userInput === ''
    ? null
    : filter_var(
        $userInput,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

$fromDate = auditDate($fromInput);
$toDate = auditDate($toInput);

$pageInput = auditInput('page', 10);

$page = filter_var(
    $pageInput,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

$page = $page ?: 1;
$pageSize = 25;

$users = [];
$actions = [];
$logs = [];
$total = 0;
$totalPages = 1;
$error = '';

if ($userInput !== '' && !$userId) {
    $error = 'Choose a valid user.';
} elseif ($fromDate === false || $toDate === false) {
    $error = 'Enter valid dates.';
} elseif ($fromDate && $toDate && $fromDate > $toDate) {
    $error = 'From date must not be after To date.';
}

try {
    $users = $pdo->query(
        'SELECT user_id, full_name, role
         FROM users
         ORDER BY full_name, user_id
         LIMIT 500'
    )->fetchAll();

    $actions = $pdo->query(
        "SELECT DISTINCT action
         FROM audit_logs
         WHERE action IS NOT NULL AND action <> ''
         ORDER BY action"
    )->fetchAll(PDO::FETCH_COLUMN);

    if ($error === '') {
        $conditions = ['1 = 1'];
        $parameters = [];

        if ($userId) {
            $conditions[] = 'a.user_id = ?';
            $parameters[] = $userId;
        }

        if ($action !== '') {
            $conditions[] = 'a.action = ?';
            $parameters[] = $action;
        }

        if ($patientSearch !== '') {
            $conditions[] = '(p.full_name LIKE ? OR p.mrn LIKE ?)';
            $term = '%' . $patientSearch . '%';
            $parameters[] = $term;
            $parameters[] = $term;
        }

        if ($fromDate) {
            $conditions[] = 'a.created_at >= ?';
            $parameters[] = $fromDate->format('Y-m-d') . ' 00:00:00';
        }

        if ($toDate) {
            // Include the entire To date.
            $conditions[] = 'a.created_at < ?';
            $parameters[] = $toDate
                ->modify('+1 day')
                ->format('Y-m-d') . ' 00:00:00';
        }

        $where = implode(' AND ', $conditions);

        $joins = "
            FROM audit_logs a
            LEFT JOIN users u ON a.user_id = u.user_id
            LEFT JOIN patients p ON a.patient_id = p.patient_id
        ";

        $query = $pdo->prepare(
            "SELECT COUNT(*) $joins WHERE $where"
        );
        $query->execute($parameters);

        $total = (int) $query->fetchColumn();
        $totalPages = max(1, (int) ceil($total / $pageSize));
        $page = min($page, $totalPages);

        $offset = ($page - 1) * $pageSize;

        // LIMIT and OFFSET are server-calculated integers.
        $query = $pdo->prepare(
            "SELECT a.log_id, a.action, a.details,
                    a.ip_address, a.created_at,
                    u.full_name AS user_name,
                    u.role AS user_role,
                    p.full_name AS patient_name,
                    p.mrn
             $joins
             WHERE $where
             ORDER BY a.log_id DESC
             LIMIT $pageSize OFFSET $offset"
        );

        $query->execute($parameters);
        $logs = $query->fetchAll();
    }

} catch (PDOException $exception) {
    error_log($exception->getMessage());
    $error = 'Audit logs could not be loaded. Please try again.';
}

function auditPageLink($pageNumber, $filters) {
    $filters['page'] = $pageNumber;

    return 'audit_logs.php?' . http_build_query($filters);
}

$filters = [
    'user_id' => $userInput,
    'action' => $action,
    'patient' => $patientSearch,
    'from' => $fromInput,
    'to' => $toInput
];

$firstResult = $total > 0 ? (($page - 1) * $pageSize) + 1 : 0;
$lastResult = min($page * $pageSize, $total);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Audit Logs | MHMS</title>

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
            max-width: 1500px;
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
            min-width: 0;
        }

        .filters {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
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

        .actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 22px;
        }

        button, .button {
            display: inline-block;
            padding: 12px 17px;
            border: 0;
            border-radius: 9px;
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

        .secondary {
            background: #e6f2f4;
            color: #086572;
        }

        .secondary:hover {
            background: #d1e9ed;
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
            padding: 15px 10px;
            border-bottom: 1px solid #e1eaf0;
            vertical-align: top;
        }

        .details {
            min-width: 220px;
            max-width: 420px;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
            line-height: 1.8;
        }

        .badge {
            display: inline-block;
            padding: 5px 8px;
            border-radius: 6px;
            background: #e6f3f4;
            color: #08727d;
            font-size: 11px;
        }

        .small {
            display: block;
            margin-top: 6px;
            color: #62788a;
            font-size: 11px;
        }

        .pagination {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
            margin-top: 22px;
        }

        .pagination-links {
            display: flex;
            gap: 10px;
        }

        .note {
            color: #62788a;
            font-size: 12px;
            line-height: 1.8;
        }

        .error {
            padding: 16px;
            margin: 22px 0;
            border-radius: 10px;
            background: #feecec;
            color: #991b1b;
            line-height: 1.8;
        }

        @media (max-width: 900px) {
            .filters {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 550px) {
            main { padding: 24px 14px; }
            .card { padding: 20px; }
            .filters { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <header>
        <div class="brand">MHMS</div>
        <a href="dashboard.php">Administrator dashboard</a>
    </header>

    <main>
        <h1>Audit logs</h1>

        <p class="muted">
            Search the actions recorded by the application.
            This page does not modify or delete log entries.
        </p>

        <section class="card">
            <h2>Filter recorded activity</h2>

            <form method="GET" action="audit_logs.php">
                <div class="filters">
                    <div>
                        <label for="user_id">User</label>
                        <select id="user_id" name="user_id">
                            <option value="">All users</option>

                            <?php foreach ($users as $user): ?>
                                <option
                                    value="<?= (int) $user['user_id'] ?>"
                                    <?= $userId === (int) $user['user_id']
                                        ? 'selected' : '' ?>
                                >
                                    <?= auditEscape(
                                        $user['full_name']
                                        . ' — ' . $user['role']
                                        . ' (#' . $user['user_id'] . ')'
                                    ) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label for="action">Action</label>
                        <select id="action" name="action">
                            <option value="">All actions</option>

                            <?php foreach ($actions as $actionOption): ?>
                                <option
                                    value="<?= auditEscape($actionOption) ?>"
                                    <?= $action === $actionOption
                                        ? 'selected' : '' ?>
                                >
                                    <?= auditEscape($actionOption) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label for="patient">Patient name or MRN</label>
                        <input
                            id="patient"
                            name="patient"
                            maxlength="100"
                            value="<?= auditEscape($patientSearch) ?>"
                            placeholder="Example: Demo Patient One"
                        >
                    </div>

                    <div>
                        <label for="from">From date</label>
                        <input
                            id="from"
                            name="from"
                            type="date"
                            value="<?= auditEscape($fromInput) ?>"
                        >
                    </div>

                    <div>
                        <label for="to">To date</label>
                        <input
                            id="to"
                            name="to"
                            type="date"
                            value="<?= auditEscape($toInput) ?>"
                        >
                    </div>
                </div>

                <div class="actions">
                    <button type="submit">Apply filters</button>

                    <a class="button secondary" href="audit_logs.php">
                        Clear filters
                    </a>
                </div>
            </form>

            <p class="note">
                User selector: up to 500 accounts.
                Date filters use the database timestamps as stored.
                This page does not convert timestamps to another timezone.
            </p>
        </section>

        <?php if ($error !== ''): ?>
            <div class="error" role="alert">
                <?= auditEscape($error) ?>
            </div>
        <?php else: ?>
            <section class="card">
                <h2>Recorded actions</h2>

                <p class="note">
                    Showing <?= $firstResult ?>–<?= $lastResult ?>
                    of <?= $total ?> matching entries.
                </p>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Log #</th>
                                <th>Recorded time</th>
                                <th>User</th>
                                <th>Action</th>
                                <th>Patient</th>
                                <th>Details</th>
                                <th>IP address</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php if (!$logs): ?>
                                <tr>
                                    <td colspan="7">
                                        No matching audit entries found.
                                    </td>
                                </tr>
                            <?php endif; ?>

                            <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td><?= (int) $log['log_id'] ?></td>

                                    <td><?= auditEscape(
                                        $log['created_at']
                                    ) ?></td>

                                    <td>
                                        <?= auditEscape(
                                            $log['user_name']
                                            ?? 'Unavailable account'
                                        ) ?>

                                        <span class="small">
                                            <?= auditEscape(
                                                $log['user_role'] ?? ''
                                            ) ?>
                                        </span>
                                    </td>

                                    <td>
                                        <span class="badge">
                                            <?= auditEscape(
                                                $log['action'] ?? ''
                                            ) ?>
                                        </span>
                                    </td>

                                    <td>
                                        <?= auditEscape(
                                            $log['patient_name'] ?? '—'
                                        ) ?>

                                        <?php if ($log['mrn']): ?>
                                            <span class="small">
                                                <?= auditEscape($log['mrn']) ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <td class="details"><?= auditEscape(
                                        $log['details'] ?? ''
                                    ) ?></td>

                                    <td><?= auditEscape(
                                        $log['ip_address'] ?? ''
                                    ) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="pagination">
                    <span class="note">
                        Page <?= $page ?> of <?= $totalPages ?>
                        · 25 entries per page
                    </span>

                    <div class="pagination-links">
                        <?php if ($page > 1): ?>
                            <a
                                class="button secondary"
                                href="<?= auditEscape(
                                    auditPageLink($page - 1, $filters)
                                ) ?>"
                            >
                                Previous
                            </a>
                        <?php endif; ?>

                        <?php if ($page < $totalPages): ?>
                            <a
                                class="button"
                                href="<?= auditEscape(
                                    auditPageLink($page + 1, $filters)
                                ) ?>"
                            >
                                Next
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </section>
        <?php endif; ?>

        <p class="note">
            Academic prototype. These database logs are not tamper-proof.
            They show only actions explicitly logged by the application.
            Failed-login logging, log-retention policies, and recording
            access to this log viewer are not implemented in this version.
        </p>
    </main>
</body>
</html>