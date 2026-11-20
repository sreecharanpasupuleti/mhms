<?php
require_once __DIR__ . '/config/db.php';

date_default_timezone_set('Asia/Kolkata');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

function analyticsEscape($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

try {
    $query = $pdo->prepare(
        "SELECT u.full_name, u.role, u.status, d.doctor_id
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
    exit('Analytics is available to administrators and doctors only.');
}

$monthsInput = $_GET['months'] ?? '6';

$months = is_string($monthsInput)
    && in_array($monthsInput, ['3', '6', '12'], true)
        ? (int) $monthsInput
        : 6;

$today = new DateTimeImmutable('today');

$firstMonth = $today
    ->modify('first day of this month')
    ->modify('-' . ($months - 1) . ' months');

$tomorrow = $today->modify('+1 day');

$startDate = $firstMonth->format('Y-m-d');
$endExclusive = $tomorrow->format('Y-m-d');

$periodLabel = $firstMonth->format('d M Y')
    . ' – ' . $today->format('d M Y');

$monthData = [];

for ($index = 0; $index < $months; $index++) {
    $month = $firstMonth->modify('+' . $index . ' months');
    $monthData[$month->format('Y-m')] = [
        'label' => $month->format('M Y'),
        'count' => 0
    ];
}

$appointmentCounts = [
    'Scheduled' => 0,
    'Completed' => 0,
    'Cancelled' => 0
];

$totalConsultations = 0;
$uniquePatients = 0;
$totalAppointments = 0;
$error = '';

try {
    $visitConditions = 'v.visit_date >= ? AND v.visit_date < ?';
    $visitParameters = [$startDate, $endExclusive];

    if (!$isAdmin) {
        $visitConditions .= ' AND v.doctor_id = ?';
        $visitParameters[] = $doctorId;
    }

    $query = $pdo->prepare(
        "SELECT DATE_FORMAT(v.visit_date, '%Y-%m') AS month_key,
                COUNT(*) AS visit_count
         FROM visits v
         WHERE $visitConditions
         GROUP BY DATE_FORMAT(v.visit_date, '%Y-%m')
         ORDER BY month_key"
    );

    $query->execute($visitParameters);

    foreach ($query->fetchAll() as $row) {
        if (isset($monthData[$row['month_key']])) {
            $monthData[$row['month_key']]['count'] =
                (int) $row['visit_count'];
        }
    }

    $query = $pdo->prepare(
        "SELECT COUNT(*) AS total_visits,
                COUNT(DISTINCT v.patient_id) AS unique_patients
         FROM visits v
         WHERE $visitConditions"
    );

    $query->execute($visitParameters);
    $summary = $query->fetch();

    $totalConsultations = (int) $summary['total_visits'];
    $uniquePatients = (int) $summary['unique_patients'];

    // Appointment counts use appointment start date, not creation date.
    $appointmentConditions = 'a.starts_at >= ? AND a.starts_at < ?';
    $appointmentParameters = [
        $startDate . ' 00:00:00',
        $endExclusive . ' 00:00:00'
    ];

    if (!$isAdmin) {
        $appointmentConditions .= ' AND a.doctor_id = ?';
        $appointmentParameters[] = $doctorId;
    }

    $query = $pdo->prepare(
        "SELECT a.status, COUNT(*) AS appointment_count
         FROM appointments a
         WHERE $appointmentConditions
         GROUP BY a.status"
    );

    $query->execute($appointmentParameters);

    foreach ($query->fetchAll() as $row) {
        if (isset($appointmentCounts[$row['status']])) {
            $appointmentCounts[$row['status']] =
                (int) $row['appointment_count'];
        }
    }

    $totalAppointments = array_sum($appointmentCounts);

} catch (PDOException $exception) {
    error_log($exception->getMessage());
    $error = 'Analytics could not be fully loaded. Please try again.';
}

$maximumMonthlyCount = max(
    1,
    ...array_column(array_values($monthData), 'count')
);

$dashboard = $isAdmin
    ? 'dashboard.php'
    : 'doctor_dashboard.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Analytics | MHMS</title>

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
            max-width: 1250px;
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

        .filter {
            display: flex;
            align-items: end;
            flex-wrap: wrap;
            gap: 14px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-size: 14px;
            font-weight: 600;
        }

        select {
            padding: 12px;
            border: 1px solid #cad7e0;
            border-radius: 9px;
            background: white;
            color: #173247;
            font: inherit;
            font-size: 14px;
        }

        select:focus {
            outline: 3px solid #d9f0f2;
            border-color: #087e8b;
        }

        button {
            padding: 13px 18px;
            border: 0;
            border-radius: 9px;
            background: #087e8b;
            color: white;
            font: inherit;
            font-size: 14px;
            cursor: pointer;
        }

        button:hover { background: #066772; }

        .stats {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 18px;
            margin: 24px 0;
        }

        .stat {
            padding: 23px;
            border: 1px solid #e1eaf0;
            border-radius: 15px;
            background: white;
        }

        .stat span {
            color: #62788a;
            font-size: 13px;
        }

        .stat strong {
            display: block;
            margin-top: 14px;
            font-size: 34px;
        }

        .chart-row {
            display: grid;
            grid-template-columns: 85px minmax(0, 1fr) 45px;
            gap: 14px;
            align-items: center;
            margin: 19px 0;
            font-size: 13px;
        }

        .track {
            height: 20px;
            overflow: hidden;
            border-radius: 7px;
            background: #eef3f7;
        }

        .bar {
            height: 100%;
            border-radius: 7px;
            background: linear-gradient(90deg, #087e8b, #39a9b4);
        }

        .number {
            text-align: right;
            font-weight: 700;
        }

        .status-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 16px;
        }

        .status-card {
            padding: 20px;
            border: 1px solid #e1eaf0;
            border-radius: 12px;
        }

        .status-card strong {
            display: block;
            margin: 12px 0;
            font-size: 28px;
        }

        .status-card p {
            margin: 0;
            color: #62788a;
            font-size: 12px;
        }

        .status-track {
            height: 10px;
            margin-top: 16px;
            overflow: hidden;
            border-radius: 6px;
            background: #eef3f7;
        }

        .status-bar {
            height: 100%;
            background: #087e8b;
        }

        .scheduled .status-bar { background: #2879bf; }
        .completed .status-bar { background: #21834d; }
        .cancelled .status-bar { background: #b56b30; }

        .error {
            padding: 16px;
            margin: 24px 0;
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

        .table-wrap { overflow-x: auto; }

        table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            font-size: 13px;
        }

        th {
            padding: 12px;
            background: #f3f7fa;
        }

        td {
            padding: 13px 12px;
            border-bottom: 1px solid #e1eaf0;
        }

        details {
            margin-top: 24px;
        }

        summary {
            margin-bottom: 16px;
            color: #08727d;
            cursor: pointer;
            font-size: 14px;
        }

        @media (max-width: 650px) {
            .stats, .status-grid {
                grid-template-columns: 1fr;
            }

            main { padding: 24px 14px; }
            .card { padding: 20px; }
            .chart-row {
                grid-template-columns: 70px minmax(0, 1fr) 35px;
                gap: 9px;
            }
        }
    </style>
</head>
<body>
    <header>
        <div class="brand">MHMS</div>

        <nav aria-label="Page navigation">
            <a href="index.php">Workspace</a>
            <a href="<?= analyticsEscape($dashboard) ?>">Dashboard</a>
        </nav>
    </header>

    <main>
        <h1><?= $isAdmin ? 'System analytics' : 'Your activity analytics' ?></h1>

        <p class="muted">
            <?= $isAdmin
                ? 'Aggregate figures across recorded system activity.'
                : 'Aggregate figures for your own recorded consultations and appointments.' ?>
        </p>

        <section class="card">
            <form method="GET" action="analytics.php" class="filter">
                <div>
                    <label for="months">Calendar-month window</label>
                    <select id="months" name="months">
                        <?php foreach ([3, 6, 12] as $option): ?>
                            <option
                                value="<?= $option ?>"
                                <?= $months === $option ? 'selected' : '' ?>
                            >
                                Last <?= $option ?> calendar months
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="submit">Update analytics</button>
            </form>

            <p class="note">
                Period: <?= analyticsEscape($periodLabel) ?>.
                Includes the current partial month through today,
                using India Standard Time to establish today’s date.
            </p>
        </section>

        <?php if ($error !== ''): ?>
            <div class="error" role="alert">
                <?= analyticsEscape($error) ?>
            </div>
        <?php else: ?>
            <section class="stats" aria-label="Period totals">
                <article class="stat">
                    <span>Recorded consultations</span>
                    <strong><?= $totalConsultations ?></strong>
                </article>

                <article class="stat">
                    <span>Unique patients in those consultations</span>
                    <strong><?= $uniquePatients ?></strong>
                </article>

                <article class="stat">
                    <span>Appointments starting within the period</span>
                    <strong><?= $totalAppointments ?></strong>
                </article>
            </section>

            <section class="card">
                <h2>Consultations per month</h2>

                <?php foreach ($monthData as $month): ?>
                    <?php
                    $width = round(
                        ($month['count'] / $maximumMonthlyCount) * 100,
                        2
                    );
                    ?>

                    <div class="chart-row">
                        <span><?= analyticsEscape($month['label']) ?></span>

                        <div class="track" aria-hidden="true">
                            <div
                                class="bar"
                                style="width: <?= $width ?>%;"
                            ></div>
                        </div>

                        <span class="number">
                            <?= $month['count'] ?>
                        </span>
                    </div>
                <?php endforeach; ?>

                <p class="note">
                    Bars are scaled relative to the busiest month
                    in this window. Numbers are consultation counts,
                    not percentages. Months without records show zero.
                </p>

                <details>
                    <summary>View the monthly data table</summary>

                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Month</th>
                                    <th>Consultations</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php foreach ($monthData as $month): ?>
                                    <tr>
                                        <td><?= analyticsEscape(
                                            $month['label']
                                        ) ?></td>
                                        <td><?= $month['count'] ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </details>
            </section>

            <section class="card">
                <h2>Appointment statuses</h2>

                <div class="status-grid">
                    <?php foreach ($appointmentCounts as $status => $count): ?>
                        <?php
                        $percentage = $totalAppointments > 0
                            ? round(($count / $totalAppointments) * 100, 1)
                            : 0;
                        ?>

                        <article class="status-card <?= strtolower($status) ?>">
                            <span><?= analyticsEscape($status) ?></span>
                            <strong><?= $count ?></strong>

                            <p>
                                <?= $percentage ?>% of appointments
                                in this period
                            </p>

                            <div class="status-track" aria-hidden="true">
                                <div
                                    class="status-bar"
                                    style="width: <?= $percentage ?>%;"
                                ></div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>

                <p class="note">
                    Statuses are the values currently stored in the database.
                    A past appointment still marked Scheduled is not
                    automatically considered missed or completed.
                    Future appointments after today are excluded.
                </p>
            </section>

            <p class="note">
                <?= $isAdmin
                    ? 'Administrator figures include all matching records.'
                    : 'Doctor aggregates include your historical consultations and appointments, including patients no longer assigned to you. No patient-level records are shown here.' ?>
                These are activity summaries, not measures of treatment
                quality or clinical outcomes.
            </p>
        <?php endif; ?>

        <p class="note">
            Academic demonstration · Fictional data only.
            Charts are rendered locally without an external chart service.
        </p>
    </main>
</body>
</html>