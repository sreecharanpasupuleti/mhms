<?php
require_once __DIR__ . '/config/db.php';

date_default_timezone_set('Asia/Kolkata');
header('Cache-Control: no-store');

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

function followupEscape($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

try {
    $query = $pdo->prepare(
        "SELECT u.role, u.status, d.doctor_id
         FROM users u
         LEFT JOIN doctors d ON u.user_id = d.user_id
         WHERE u.user_id = ?"
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

$isAdmin = $account['role'] === 'admin';
$doctorId = (int) ($account['doctor_id'] ?? 0);

if (
    !$isAdmin
    && ($account['role'] !== 'doctor' || !$doctorId)
) {
    http_response_code(403);
    exit('An administrator or doctor account is required.');
}

$dashboardLink = $isAdmin
    ? 'dashboard.php'
    : 'doctor_dashboard.php';

$today = date('Y-m-d');
$tomorrow = (new DateTimeImmutable('today'))
    ->modify('+1 day')->format('Y-m-d');

$nextWeek = (new DateTimeImmutable('today'))
    ->modify('+7 days')->format('Y-m-d');

$allowedFilters = ['today', 'next7', 'past', 'all'];

$filterInput = $_GET['filter'] ?? 'today';
$filter = is_string($filterInput)
    && in_array($filterInput, $allowedFilters, true)
        ? $filterInput
        : 'today';

$filterLabels = [
    'today' => 'Today',
    'next7' => 'Next 7 days',
    'past' => 'Past dates',
    'all' => 'All follow-ups'
];

$counts = [
    'today' => 0,
    'next7' => 0,
    'past' => 0,
    'all' => 0
];

$followups = [];
$error = '';

try {
    $scope = 'v.follow_up_date IS NOT NULL';
    $scopeParameters = [];

    if (!$isAdmin) {
        $scope .= "
            AND v.doctor_id = ?
            AND EXISTS (
                SELECT 1
                FROM patient_assignments pa
                WHERE pa.patient_id = v.patient_id
                  AND pa.doctor_id = v.doctor_id
            )
        ";

        $scopeParameters[] = $doctorId;
    }

    $query = $pdo->prepare(
        "SELECT
            COUNT(*) AS total_count,
            COALESCE(SUM(v.follow_up_date = ?), 0) AS today_count,
            COALESCE(
                SUM(v.follow_up_date BETWEEN ? AND ?), 0
            ) AS next_count,
            COALESCE(SUM(v.follow_up_date < ?), 0) AS past_count
         FROM visits v
         WHERE $scope"
    );

    $query->execute(array_merge(
        [$today, $tomorrow, $nextWeek, $today],
        $scopeParameters
    ));

    $summary = $query->fetch();

    $counts = [
        'today' => (int) $summary['today_count'],
        'next7' => (int) $summary['next_count'],
        'past' => (int) $summary['past_count'],
        'all' => (int) $summary['total_count']
    ];

    $conditions = $scope;
    $parameters = $scopeParameters;
    $order = 'v.follow_up_date ASC, v.visit_id DESC';

    if ($filter === 'today') {
        $conditions .= ' AND v.follow_up_date = ?';
        $parameters[] = $today;

    } elseif ($filter === 'next7') {
        $conditions .= ' AND v.follow_up_date BETWEEN ? AND ?';
        $parameters[] = $tomorrow;
        $parameters[] = $nextWeek;

    } elseif ($filter === 'past') {
        $conditions .= ' AND v.follow_up_date < ?';
        $parameters[] = $today;
        $order = 'v.follow_up_date DESC, v.visit_id DESC';

    } else {
        $order = 'v.follow_up_date DESC, v.visit_id DESC';
    }

    $query = $pdo->prepare(
        "SELECT v.visit_id, v.patient_id, v.visit_date,
                v.follow_up_date, v.diagnosis,
                p.full_name AS patient_name, p.mrn,
                u.full_name AS doctor_name
         FROM visits v
         JOIN patients p ON v.patient_id = p.patient_id
         JOIN doctors d ON v.doctor_id = d.doctor_id
         JOIN users u ON d.user_id = u.user_id
         WHERE $conditions
         ORDER BY $order
         LIMIT 100"
    );

    $query->execute($parameters);
    $followups = $query->fetchAll();

} catch (PDOException $exception) {
    error_log($exception->getMessage());

    $error = 'Follow-up information could not be loaded. '
        . 'Counts and lists may be incomplete.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Follow-up Dashboard | MHMS</title>

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
            max-width: 1350px;
            margin: auto;
            padding: 32px 24px;
        }

        h1 {
            margin: 0 0 12px;
            font-size: 30px;
        }

        h2 {
            margin: 0 0 20px;
            font-size: 21px;
        }

        .muted {
            color: #62788a;
            line-height: 1.8;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 18px;
            margin: 26px 0;
        }

        .stat {
            display: block;
            padding: 22px;
            border: 1px solid #e1eaf0;
            border-radius: 15px;
            background: white;
            color: #173247;
            text-decoration: none;
        }

        .stat:hover {
            border-color: #087e8b;
        }

        .stat.active {
            border-color: #087e8b;
            background: #eaf6f7;
        }

        .stat span {
            color: #62788a;
            font-size: 13px;
        }

        .stat strong {
            display: block;
            margin-top: 14px;
            font-size: 33px;
        }

        .card {
            padding: 26px;
            border: 1px solid #e1eaf0;
            border-radius: 18px;
            background: white;
        }

        .notice {
            padding: 16px;
            margin-bottom: 24px;
            border-radius: 10px;
            background: #fff8e8;
            color: #795718;
            font-size: 14px;
            line-height: 1.8;
        }

        .error {
            padding: 16px;
            margin-bottom: 22px;
            border-radius: 10px;
            background: #feecec;
            color: #991b1b;
            line-height: 1.8;
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
            vertical-align: top;
        }

        .mrn {
            display: block;
            margin-top: 7px;
            color: #62788a;
            font-family: monospace;
            font-size: 11px;
        }

        .button {
            display: inline-block;
            padding: 10px 13px;
            border-radius: 8px;
            background: #087e8b;
            color: white;
            text-decoration: none;
            white-space: nowrap;
        }

        .button:hover {
            background: #066772;
        }

        .note {
            color: #62788a;
            font-size: 12px;
            line-height: 1.8;
        }

        @media (max-width: 800px) {
            .stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
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
            <a href="<?= followupEscape($dashboardLink) ?>">Dashboard</a>
            <a href="appointments.php">Appointments</a>
            <a href="patient_view.php">Patient profiles</a>
        </nav>
    </header>

    <main>
        <h1>Follow-up dashboard</h1>

        <p class="muted">
            Review follow-up dates recorded in consultations.
            Today: <?= followupEscape($today) ?>, India Standard Time.
        </p>

        <div class="notice">
            A recorded follow-up date is not a booked appointment.
            Past dates are shown for review; this prototype does not
            yet record whether the follow-up was completed, cancelled,
            or missed.
        </div>

        <?php if ($error !== ''): ?>
            <div class="error" role="alert">
                <?= followupEscape($error) ?>
            </div>
        <?php endif; ?>

        <section class="stats" aria-label="Follow-up filters">
            <?php foreach ($filterLabels as $key => $label): ?>
                <a
                    class="stat <?= $filter === $key ? 'active' : '' ?>"
                    href="followups.php?filter=<?= followupEscape($key) ?>"
                    <?= $filter === $key ? 'aria-current="page"' : '' ?>
                >
                    <span><?= followupEscape($label) ?></span>
                    <strong><?= $counts[$key] ?></strong>
                </a>
            <?php endforeach; ?>
        </section>

        <section class="card">
            <h2><?= followupEscape($filterLabels[$filter]) ?></h2>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Follow-up date</th>
                            <th>Patient</th>
                            <th>Doctor</th>
                            <th>Original visit</th>
                            <th>Recorded assessment</th>
                            <th>Action</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if (!$followups): ?>
                            <tr>
                                <td colspan="6">
                                    No follow-up records available
                                    for this filter.
                                </td>
                            </tr>
                        <?php endif; ?>

                        <?php foreach ($followups as $followup): ?>
                            <tr>
                                <td><?= followupEscape(
                                    $followup['follow_up_date']
                                ) ?></td>

                                <td>
                                    <?= followupEscape(
                                        $followup['patient_name']
                                    ) ?>

                                    <span class="mrn">
                                        <?= followupEscape($followup['mrn']) ?>
                                    </span>
                                </td>

                                <td><?= followupEscape(
                                    $followup['doctor_name']
                                ) ?></td>

                                <td>
                                    <?= followupEscape(
                                        $followup['visit_date']
                                    ) ?>
                                    <br>
                                    Visit #<?= (int) $followup['visit_id'] ?>
                                </td>

                                <td><?= followupEscape(
                                    $followup['diagnosis']
                                    ?: 'Not recorded'
                                ) ?></td>

                                <td>
                                    <a
                                        class="button"
                                        href="patient_view.php?id=<?= (int) $followup['patient_id'] ?>"
                                    >
                                        Open profile
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <p class="note">
                Showing up to 100 matching consultation records.
                Counts represent follow-up entries, not unique patients.
                “Next 7 days” means tomorrow through seven days from today.
            </p>

            <p class="note">
                Doctors see only their own consultation follow-ups for
                patients currently assigned to them. Administrators
                see all recorded follow-up dates.
            </p>
        </section>
    </main>
</body>
</html>