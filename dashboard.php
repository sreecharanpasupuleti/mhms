<?php
require_once __DIR__ . '/config/db.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$query = $pdo->prepare(
    "SELECT u.full_name, u.role, u.status,
            d.doctor_id, d.specialization
     FROM users u
     LEFT JOIN doctors d ON u.user_id = d.user_id
     WHERE u.user_id = ?"
);

$query->execute([$_SESSION['user_id']]);
$account = $query->fetch();

if (!$account || $account['status'] !== 'active') {
    $_SESSION = [];
    session_destroy();

    header('Location: login.php');
    exit;
}

if (
    !in_array($account['role'], ['admin', 'doctor'], true)
    || ($account['role'] === 'doctor' && !$account['doctor_id'])
) {
    http_response_code(403);
    exit('An administrator or doctor profile is required.');
}

header('Cache-Control: no-store');

$isAdmin = $account['role'] === 'admin';
$doctorId = (int) ($account['doctor_id'] ?? 0);

if (empty($_SESSION['logout_token'])) {
    $_SESSION['logout_token'] = bin2hex(random_bytes(32));
}

function dashEscape($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$links = $isAdmin
    ? [
        ['Patients', 'patients.php', 'Add and search patient records'],
        ['Patient profiles', 'patient_view.php', 'View recorded patient history'],
        ['Medical history', 'history.php', 'Add background history entries'],
        ['Allergies', 'allergies.php', 'Record fictional allergy information'],
        ['Doctors', 'doctors.php', 'Create and list doctor accounts'],
        ['Patient assignments', 'assignments.php', 'Assign patients to doctors'],
        ['Lab reports', 'report_upload.php', 'Upload and download demo reports']
    ]
    : [
        ['Add consultation', 'visit_add.php', 'Record a visit for an assigned patient'],
        ['Prescriptions', 'prescription_add.php', 'Add or review prescription entries'],
        ['Print prescriptions', 'prescription_print.php', 'Open the printable demo document'],
        ['Lab reports', 'report_upload.php', 'Manage reports for assigned patients']
    ];

$stats = [];
$activity = [];
$assignedPatients = [];
$recentVisits = [];
$error = '';

try {
    if ($isAdmin) {
        $stats = [
            ['Patients', (int) $pdo->query(
                'SELECT COUNT(*) FROM patients'
            )->fetchColumn()],

            ['Doctors', (int) $pdo->query(
                'SELECT COUNT(*) FROM doctors'
            )->fetchColumn()],

            ['Consultations', (int) $pdo->query(
                'SELECT COUNT(*) FROM visits'
            )->fetchColumn()],

            ['Lab reports', (int) $pdo->query(
                'SELECT COUNT(*) FROM lab_reports'
            )->fetchColumn()]
        ];

        $activity = $pdo->query(
            "SELECT a.action, a.created_at, u.full_name
             FROM audit_logs a
             LEFT JOIN users u ON a.user_id = u.user_id
             ORDER BY a.log_id DESC
             LIMIT 8"
        )->fetchAll();

    } else {
        $query = $pdo->prepare(
            'SELECT COUNT(*) FROM patient_assignments WHERE doctor_id = ?'
        );
        $query->execute([$doctorId]);
        $assignedCount = (int) $query->fetchColumn();

        $query = $pdo->prepare(
            'SELECT COUNT(*) FROM visits WHERE doctor_id = ?'
        );
        $query->execute([$doctorId]);
        $visitCount = (int) $query->fetchColumn();

        $query = $pdo->prepare(
            "SELECT COUNT(*)
             FROM prescriptions rx
             JOIN visits v ON rx.visit_id = v.visit_id
             WHERE v.doctor_id = ?"
        );
        $query->execute([$doctorId]);
        $medicineCount = (int) $query->fetchColumn();

        $query = $pdo->prepare(
            "SELECT COUNT(*)
             FROM lab_reports r
             JOIN patient_assignments a
                 ON r.patient_id = a.patient_id
             WHERE a.doctor_id = ?"
        );
        $query->execute([$doctorId]);
        $reportCount = (int) $query->fetchColumn();

        $stats = [
            ['Assigned patients', $assignedCount],
            ['Your consultations', $visitCount],
            ['Medicine entries', $medicineCount],
            ['Accessible reports', $reportCount]
        ];

        $query = $pdo->prepare(
            "SELECT p.full_name, p.mrn, a.created_at
             FROM patient_assignments a
             JOIN patients p ON a.patient_id = p.patient_id
             WHERE a.doctor_id = ?
             ORDER BY a.assignment_id DESC
             LIMIT 20"
        );
        $query->execute([$doctorId]);
        $assignedPatients = $query->fetchAll();

        $query = $pdo->prepare(
            "SELECT v.visit_id, v.visit_date, v.diagnosis,
                    p.full_name AS patient_name
             FROM visits v
             JOIN patients p ON v.patient_id = p.patient_id
             JOIN patient_assignments a
                 ON a.patient_id = v.patient_id
                 AND a.doctor_id = v.doctor_id
             WHERE v.doctor_id = ?
             ORDER BY v.visit_date DESC, v.visit_id DESC
             LIMIT 10"
        );
        $query->execute([$doctorId]);
        $recentVisits = $query->fetchAll();
    }

} catch (PDOException $exception) {
    error_log($exception->getMessage());

    $error = 'Some dashboard information could not be loaded. '
        . 'Displayed counts or lists may be incomplete.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $isAdmin ? 'Admin' : 'Doctor' ?> Dashboard | MHMS</title>

    <style>
        :root {
            --navy: #102a3d;
            --teal: #087e8b;
            --muted: #62788a;
            --border: #e1eaf0;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: #f3f7fa;
            color: var(--navy);
            font-family: "Segoe UI", Arial, sans-serif;
        }

        .layout {
            display: grid;
            grid-template-columns: 250px minmax(0, 1fr);
            min-height: 100vh;
        }

        .sidebar {
            padding: 28px 18px;
            background: var(--navy);
            color: white;
        }

        .brand {
            padding: 0 12px;
            font-size: 25px;
            font-weight: 800;
            letter-spacing: 3px;
        }

        .brand-note {
            padding: 0 12px;
            margin: 10px 0 28px;
            color: #a7c2d3;
            font-size: 12px;
            line-height: 1.8;
        }

        .nav-item {
            display: block;
            padding: 12px;
            margin-bottom: 7px;
            border-radius: 9px;
            color: #d9e7ef;
            text-decoration: none;
            font-size: 14px;
        }

        a.nav-item:hover,
        a.nav-item:focus-visible {
            background: #24495e;
            color: white;
        }

        .active {
            background: var(--teal);
            color: white;
        }

        .signout {
            margin-top: 24px;
        }

        .signout button {
            width: 100%;
            padding: 12px;
            border: 1px solid #567080;
            border-radius: 9px;
            background: transparent;
            color: white;
            font: inherit;
            font-size: 14px;
            cursor: pointer;
        }

        .signout button:hover {
            background: #24495e;
        }

        main {
            min-width: 0;
            padding: 30px;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            margin-bottom: 26px;
        }

        .eyebrow {
            margin: 0 0 8px;
            color: var(--muted);
            font-size: 12px;
            letter-spacing: 1.5px;
            text-transform: uppercase;
        }

        h1 {
            margin: 0;
            font-size: clamp(25px, 3vw, 32px);
        }

        .role {
            padding: 9px 14px;
            border-radius: 20px;
            background: #dff0f2;
            color: #086572;
            font-size: 13px;
            font-weight: 600;
        }

        .hero {
            padding: 30px;
            margin-bottom: 24px;
            border-radius: 20px;
            background: linear-gradient(115deg, #087e8b, #10465e);
            color: white;
        }

        .hero h2 {
            margin: 0 0 12px;
            font-size: 26px;
        }

        .hero p {
            margin: 0;
            max-width: 750px;
            color: #d9eef0;
            line-height: 1.8;
        }

        .hero-button {
            display: inline-block;
            margin-top: 20px;
            padding: 12px 18px;
            border-radius: 9px;
            background: white;
            color: #086572;
            text-decoration: none;
            font-size: 14px;
            font-weight: 700;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 18px;
            margin-bottom: 24px;
        }

        .stat {
            padding: 22px;
            border: 1px solid var(--border);
            border-radius: 16px;
            background: white;
        }

        .stat span {
            color: var(--muted);
            font-size: 13px;
        }

        .stat strong {
            display: block;
            margin-top: 14px;
            font-size: 34px;
        }

        .section-title {
            margin: 30px 0 18px;
            font-size: 21px;
        }

        .modules {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 28px;
        }

        .module {
            display: block;
            padding: 22px;
            border: 1px solid var(--border);
            border-radius: 15px;
            background: white;
            color: var(--navy);
            text-decoration: none;
            transition: transform 0.2s, border-color 0.2s;
        }

        .module:hover {
            transform: translateY(-3px);
            border-color: #087e8b;
        }

        .module h3 {
            margin: 0 0 10px;
            font-size: 16px;
        }

        .module p {
            margin: 0;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.7;
        }

        .card {
            padding: 25px;
            margin-bottom: 24px;
            border: 1px solid var(--border);
            border-radius: 16px;
            background: white;
            min-width: 0;
        }

        .card h2 {
            margin: 0 0 20px;
            font-size: 20px;
        }

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
            color: var(--muted);
        }

        td {
            padding: 15px 10px;
            border-bottom: 1px solid var(--border);
        }

        .badge {
            display: inline-block;
            padding: 5px 8px;
            border-radius: 6px;
            background: #e6f3f4;
            color: #08727d;
            font-size: 11px;
        }

        .table-link {
            color: #08727d;
            font-weight: 600;
        }

        .error {
            padding: 16px;
            margin-bottom: 22px;
            border-radius: 10px;
            background: #feecec;
            color: #991b1b;
            line-height: 1.7;
        }

        .note,
        footer {
            color: var(--muted);
            font-size: 12px;
            line-height: 1.8;
        }

        @media (max-width: 1150px) {
            .modules {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 750px) {
            .layout {
                grid-template-columns: 1fr;
            }

            .sidebar {
                padding: 20px;
            }

            .brand-note {
                margin-bottom: 16px;
            }

            .sidebar nav {
                display: flex;
                gap: 7px;
                flex-wrap: wrap;
            }

            .nav-item {
                margin: 0;
                background: #1b3d53;
                font-size: 12px;
            }

            .signout {
                margin-top: 16px;
            }

            .signout button {
                width: auto;
            }

            main {
                padding: 24px 16px;
            }

            .topbar {
                align-items: flex-start;
            }
        }

        @media (max-width: 450px) {
            .modules {
                grid-template-columns: 1fr;
            }

            .hero {
                padding: 24px;
            }

            .topbar {
                flex-direction: column;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            .module {
                transition: none;
            }

            .module:hover {
                transform: none;
            }
        }
    </style>
</head>
<body>
    <div class="layout">
        <aside class="sidebar">
            <div class="brand">MHMS</div>

            <p class="brand-note">
                Medical History<br>
                Management System
            </p>

            <nav aria-label="Main navigation">
                <div class="nav-item active" aria-current="page">
                    Dashboard
                </div>

                <?php foreach ($links as $link): ?>
                    <a
                        class="nav-item"
                        href="<?= dashEscape($link[1]) ?>"
                    >
                        <?= dashEscape($link[0]) ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <form class="signout" method="POST" action="logout.php">
                <input
                    type="hidden"
                    name="token"
                    value="<?= dashEscape($_SESSION['logout_token']) ?>"
                >

                <button type="submit">Sign out</button>
            </form>
        </aside>

        <main>
            <header class="topbar">
                <div>
                    <p class="eyebrow">
                        <?= $isAdmin ? 'Administrator' : 'Doctor' ?> workspace
                    </p>
                    <h1>Dashboard overview</h1>
                </div>

                <div class="role">
                    <?= $isAdmin ? 'Administrator' : 'Doctor' ?>
                </div>
            </header>

            <section class="hero">
                <h2>
                    Welcome, <?= dashEscape($account['full_name']) ?>.
                </h2>

                <p>
                    <?php if ($isAdmin): ?>
                        Manage fictional patient records, doctor accounts,
                        assignments, medical history, and reports
                        from one workspace.
                    <?php else: ?>
                        Record consultations, manage prescription entries,
                        and access reports for your assigned patients.
                    <?php endif; ?>
                </p>

                <a
                    class="hero-button"
                    href="<?= $isAdmin ? 'patients.php' : 'visit_add.php' ?>"
                >
                    <?= $isAdmin ? 'Manage patients' : 'Add consultation' ?>
                </a>
            </section>

            <?php if ($error !== ''): ?>
                <div class="error" role="alert">
                    <?= dashEscape($error) ?>
                </div>
            <?php endif; ?>

            <section class="stats" aria-label="Database statistics">
                <?php foreach ($stats as $stat): ?>
                    <article class="stat">
                        <span><?= dashEscape($stat[0]) ?></span>
                        <strong><?= (int) $stat[1] ?></strong>
                    </article>
                <?php endforeach; ?>
            </section>

            <h2 class="section-title">Your modules</h2>

            <section class="modules" aria-label="Available modules">
                <?php foreach ($links as $link): ?>
                    <a class="module" href="<?= dashEscape($link[1]) ?>">
                        <h3><?= dashEscape($link[0]) ?></h3>
                        <p><?= dashEscape($link[2]) ?></p>
                    </a>
                <?php endforeach; ?>
            </section>

            <?php if ($isAdmin): ?>
                <section class="card">
                    <h2>Recent system activity</h2>

                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Action</th>
                                    <th>Recorded time</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php if (!$activity): ?>
                                    <tr>
                                        <td colspan="3">
                                            No activity available.
                                        </td>
                                    </tr>
                                <?php endif; ?>

                                <?php foreach ($activity as $item): ?>
                                    <tr>
                                        <td><?= dashEscape(
                                            $item['full_name'] ?? 'Unknown user'
                                        ) ?></td>

                                        <td>
                                            <span class="badge">
                                                <?= dashEscape(
                                                    $item['action']
                                                ) ?>
                                            </span>
                                        </td>

                                        <td><?= dashEscape(
                                            $item['created_at']
                                        ) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <p class="note">Latest 8 recorded actions.</p>
                </section>

            <?php else: ?>
                <section class="card">
                    <h2>Your assigned patients</h2>

                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Patient</th>
                                    <th>Medical record number</th>
                                    <th>Assigned on</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php if (!$assignedPatients): ?>
                                    <tr>
                                        <td colspan="3">
                                            No patients assigned yet.
                                            Ask the administrator to
                                            create an assignment.
                                        </td>
                                    </tr>
                                <?php endif; ?>

                                <?php foreach ($assignedPatients as $patient): ?>
                                    <tr>
                                        <td><?= dashEscape(
                                            $patient['full_name']
                                        ) ?></td>

                                        <td><?= dashEscape(
                                            $patient['mrn']
                                        ) ?></td>

                                        <td><?= dashEscape(
                                            $patient['created_at']
                                        ) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <p class="note">Latest 20 assignments.</p>
                </section>

                <section class="card">
                    <h2>Your recent consultations</h2>

                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Patient</th>
                                    <th>Recorded assessment</th>
                                    <th>Prescription</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php if (!$recentVisits): ?>
                                    <tr>
                                        <td colspan="4">
                                            No eligible consultations available.
                                        </td>
                                    </tr>
                                <?php endif; ?>

                                <?php foreach ($recentVisits as $visit): ?>
                                    <tr>
                                        <td><?= dashEscape(
                                            $visit['visit_date']
                                        ) ?></td>

                                        <td><?= dashEscape(
                                            $visit['patient_name']
                                        ) ?></td>

                                        <td><?= dashEscape(
                                            $visit['diagnosis']
                                        ) ?></td>

                                        <td>
                                            <a
                                                class="table-link"
                                                href="prescription_add.php?visit_id=<?= (int) $visit['visit_id'] ?>"
                                            >
                                                Open builder
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <p class="note">
                        Latest 10 consultations for patients still assigned
                        to you. “Medicine entries” counts individual rows,
                        not prescription documents.
                    </p>
                </section>
            <?php endif; ?>

            <footer>
                Academic prototype · Fictional patient data only.
                This dashboard connects the modules built so far;
                it is not a production-readiness certification.
            </footer>
        </main>
    </div>
</body>
</html>