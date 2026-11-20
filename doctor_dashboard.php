<?php
require_once __DIR__ . '/config/db.php';

header('Cache-Control: no-store');

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

try {
    $query = $pdo->prepare(
        "SELECT u.full_name, u.role, u.status,
                d.doctor_id, d.specialization,
                dep.department_name
         FROM users u
         LEFT JOIN doctors d ON u.user_id = d.user_id
         LEFT JOIN departments dep
             ON d.department_id = dep.department_id
         WHERE u.user_id = ?"
    );

    $query->execute([$_SESSION['user_id']]);
    $doctor = $query->fetch();

} catch (PDOException $exception) {
    error_log($exception->getMessage());
    http_response_code(500);
    exit('The account could not be checked. Please try again.');
}

if (!$doctor || $doctor['status'] !== 'active') {
    $_SESSION = [];
    session_destroy();

    header('Location: login.php');
    exit;
}

if ($doctor['role'] === 'admin') {
    header('Location: dashboard.php');
    exit;
}

if ($doctor['role'] !== 'doctor' || !$doctor['doctor_id']) {
    http_response_code(403);
    exit('A doctor account and doctor profile are required.');
}

function doctorDashEscape($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$doctorId = (int) $doctor['doctor_id'];

if (empty($_SESSION['logout_token'])) {
    $_SESSION['logout_token'] = bin2hex(random_bytes(32));
}

$assignedPatients = [];
$recentVisits = [];
$assignedCount = 0;
$visitCount = 0;
$reportCount = 0;
$error = '';

try {
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
         FROM lab_reports r
         JOIN patient_assignments a ON a.patient_id = r.patient_id
         WHERE a.doctor_id = ?"
    );
    $query->execute([$doctorId]);
    $reportCount = (int) $query->fetchColumn();

    $query = $pdo->prepare(
        "SELECT p.patient_id, p.full_name, p.mrn, a.created_at
         FROM patient_assignments a
         JOIN patients p ON a.patient_id = p.patient_id
         WHERE a.doctor_id = ?
         ORDER BY a.assignment_id DESC
         LIMIT 100"
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

} catch (PDOException $exception) {
    error_log($exception->getMessage());

    $error = 'Some dashboard information could not be loaded. '
        . 'Counts or lists may be incomplete.';
}

$modules = [
    ['Patient profiles', 'patient_view.php', 'Review assigned patient records'],
    ['Add consultation', 'visit_add.php', 'Record a fictional patient visit'],
    ['Prescriptions', 'prescription_add.php', 'Add or review medicine entries'],
    ['Print prescriptions', 'prescription_print.php', 'Print a saved demo prescription'],
    ['Lab reports', 'report_upload.php', 'Upload or download patient reports']
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Doctor Dashboard | MHMS</title>

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

        button,
        .button {
            display: inline-block;
            padding: 11px 15px;
            border: 0;
            border-radius: 8px;
            background: #087e8b;
            color: white;
            font: inherit;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
        }

        button:hover,
        .button:hover {
            background: #066772;
        }

        main {
            max-width: 1300px;
            margin: auto;
            padding: 30px 24px;
        }

        .hero {
            padding: 30px;
            margin-bottom: 24px;
            border-radius: 20px;
            background: linear-gradient(115deg, #087e8b, #10465e);
            color: white;
        }

        .hero h1 {
            margin: 0 0 14px;
            font-size: 29px;
        }

        .hero p {
            margin: 7px 0;
            color: #def0f1;
            line-height: 1.8;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 18px;
            margin-bottom: 24px;
        }

        .stat,
        .card,
        .module {
            border: 1px solid #e1eaf0;
            border-radius: 16px;
            background: white;
        }

        .stat {
            padding: 22px;
        }

        .stat span {
            color: #62788a;
            font-size: 13px;
        }

        .stat strong {
            display: block;
            margin-top: 12px;
            font-size: 33px;
        }

        .modules {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 25px;
        }

        .module {
            display: block;
            padding: 22px;
            color: #173247;
            text-decoration: none;
        }

        .module:hover {
            border-color: #087e8b;
        }

        .module h2 {
            margin: 0 0 10px;
            font-size: 17px;
        }

        .module p {
            margin: 0;
            color: #62788a;
            font-size: 13px;
            line-height: 1.7;
        }

        .card {
            padding: 25px;
            margin-bottom: 24px;
            min-width: 0;
        }

        .card h2 {
            margin: 0 0 20px;
            font-size: 21px;
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
            color: #62788a;
        }

        td {
            padding: 15px 10px;
            border-bottom: 1px solid #e1eaf0;
        }

        .text-link {
            color: #08727d;
            font-weight: 600;
        }

        .note,
        footer {
            color: #62788a;
            font-size: 12px;
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

        @media (max-width: 800px) {
            .modules {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 550px) {
            main { padding: 24px 14px; }

            .stats,
            .modules {
                grid-template-columns: 1fr;
            }

            .hero { padding: 24px; }
            .card { padding: 20px; }
        }
    </style>
</head>
<body>
    <header>
        <div class="brand">MHMS</div>

        <form method="POST" action="logout.php">
            <input
                type="hidden"
                name="token"
                value="<?= doctorDashEscape($_SESSION['logout_token']) ?>"
            >

            <button type="submit">Sign out</button>
        </form>
    </header>

    <main>
        <section class="hero">
            <h1>
                Welcome, <?= doctorDashEscape($doctor['full_name']) ?>.
            </h1>

            <p>Doctor workspace</p>

            <p>
                <?= doctorDashEscape(
                    $doctor['department_name'] ?: 'Department not recorded'
                ) ?>
                ·
                <?= doctorDashEscape(
                    $doctor['specialization'] ?: 'Specialization not recorded'
                ) ?>
            </p>
        </section>

        <?php if ($error !== ''): ?>
            <div class="error" role="alert">
                <?= doctorDashEscape($error) ?>
            </div>
        <?php endif; ?>

        <section class="stats" aria-label="Your statistics">
            <article class="stat">
                <span>Currently assigned patients</span>
                <strong><?= $assignedCount ?></strong>
            </article>

            <article class="stat">
                <span>Your recorded consultations</span>
                <strong><?= $visitCount ?></strong>
            </article>

            <article class="stat">
                <span>Accessible lab reports</span>
                <strong><?= $reportCount ?></strong>
            </article>
        </section>

        <section class="modules" aria-label="Doctor modules">
            <?php foreach ($modules as $module): ?>
                <a
                    class="module"
                    href="<?= doctorDashEscape($module[1]) ?>"
                >
                    <h2><?= doctorDashEscape($module[0]) ?></h2>
                    <p><?= doctorDashEscape($module[2]) ?></p>
                </a>
            <?php endforeach; ?>
        </section>

        <section class="card">
            <h2>Your assigned patients</h2>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Patient</th>
                            <th>Medical record number</th>
                            <th>Assigned on</th>
                            <th>Action</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if (!$assignedPatients): ?>
                            <tr>
                                <td colspan="4">
                                    No patients assigned.
                                    Ask the administrator to create an assignment.
                                </td>
                            </tr>
                        <?php endif; ?>

                        <?php foreach ($assignedPatients as $patient): ?>
                            <tr>
                                <td><?= doctorDashEscape(
                                    $patient['full_name']
                                ) ?></td>

                                <td><?= doctorDashEscape(
                                    $patient['mrn']
                                ) ?></td>

                                <td><?= doctorDashEscape(
                                    $patient['created_at']
                                ) ?></td>

                                <td>
                                    <a
                                        class="button"
                                        href="patient_view.php?id=<?= (int) $patient['patient_id'] ?>"
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
                Showing the latest 100 assignments.
                Use Patient profiles above to select from up to
                500 accessible patient profiles.
            </p>
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
                                    No consultations available for your
                                    currently assigned patients.
                                </td>
                            </tr>
                        <?php endif; ?>

                        <?php foreach ($recentVisits as $visit): ?>
                            <tr>
                                <td><?= doctorDashEscape(
                                    $visit['visit_date']
                                ) ?></td>

                                <td><?= doctorDashEscape(
                                    $visit['patient_name']
                                ) ?></td>

                                <td><?= doctorDashEscape(
                                    $visit['diagnosis']
                                ) ?></td>

                                <td>
                                    <a
                                        class="text-link"
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
                Latest 10 eligible consultations.
                The consultation statistic includes your historical visits;
                this list is restricted to currently assigned patients.
            </p>
        </section>

        <footer>
            Academic prototype · Fictional patient data only.
            Patient profiles display recorded information;
            they do not verify clinical accuracy.
        </footer>
    </main>
</body>
</html>