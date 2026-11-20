<?php
require_once __DIR__ . '/config/db.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

// Local development checker only.
$remoteAddress = $_SERVER['REMOTE_ADDR'] ?? '';

if (!in_array($remoteAddress, ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('This checker can only be opened on the local computer.');
}

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

function checkEscape($value) {
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
    exit('The administrator account could not be checked.');
}

if (
    !$account
    || $account['status'] !== 'active'
    || $account['role'] !== 'admin'
) {
    http_response_code(403);
    exit('An active administrator account is required.');
}

$checks = [];

function addProjectCheck(&$checks, $group, $item, $passed, $message) {
    $checks[] = [
        'group' => $group,
        'item' => $item,
        'passed' => (bool) $passed,
        'message' => $message
    ];
}

$requiredFiles = [
    'config/db.php',
    'index.php',
    'login.php',
    'logout.php',
    'dashboard.php',
    'doctor_dashboard.php',
    'patient_dashboard.php',
    'patients.php',
    'patient_view.php',
    'patient_edit.php',
    'history.php',
    'allergies.php',
    'doctors.php',
    'assignments.php',
    'assignment_remove.php',
    'patient_accounts.php',
    'accounts.php',
    'visit_add.php',
    'prescription_add.php',
    'prescription_print.php',
    'report_upload.php',
    'appointments.php',
    'appointment_reschedule.php',
    'followups.php',
    'medical_summary.php',
    'timeline.php',
    'audit_logs.php',
    'change_password.php',
    'analytics.php'
];

foreach ($requiredFiles as $file) {
    $path = __DIR__ . '/' . $file;

    $present = is_file($path)
        && is_readable($path)
        && filesize($path) > 0;

    addProjectCheck(
        $checks,
        'Project files',
        $file,
        $present,
        $present
            ? 'Present, readable, and not empty.'
            : 'Missing, empty, or unreadable.'
    );
}

$requiredTables = [
    'users',
    'departments',
    'doctors',
    'patients',
    'medical_history',
    'allergies',
    'visits',
    'prescriptions',
    'lab_reports',
    'documents',
    'audit_logs',
    'patient_assignments',
    'appointments'
];

try {
    $existingTables = $pdo->query(
        'SHOW TABLES'
    )->fetchAll(PDO::FETCH_COLUMN);

    foreach ($requiredTables as $table) {
        $exists = in_array($table, $existingTables, true);

        addProjectCheck(
            $checks,
            'Database tables',
            $table,
            $exists,
            $exists ? 'Table exists.' : 'Required table is missing.'
        );
    }

} catch (PDOException $exception) {
    error_log($exception->getMessage());

    addProjectCheck(
        $checks,
        'Database tables',
        'Table lookup',
        false,
        'The database table list could not be read.'
    );
}

$fileinfoAvailable = class_exists('finfo');

addProjectCheck(
    $checks,
    'Upload support',
    'PHP Fileinfo',
    $fileinfoAvailable,
    $fileinfoAvailable
        ? 'Available for uploaded file-type checks.'
        : 'Unavailable. Report upload validation requires Fileinfo.'
);

$storageDirectory = 'C:/xampp/mhms_private_reports';

$storageAvailable = is_dir($storageDirectory)
    && is_readable($storageDirectory)
    && is_writable($storageDirectory);

addProjectCheck(
    $checks,
    'Upload support',
    'Local private report folder',
    $storageAvailable,
    $storageAvailable
        ? 'Folder exists and reports readable/writable permissions.'
        : 'Folder is missing or its permissions need checking.'
);

$temporaryFiles = [
    'setup_admin.php',
    'setup_doctor.php',
    'test_db.php'
];

foreach ($temporaryFiles as $file) {
    $removed = !is_file(__DIR__ . '/' . $file);

    addProjectCheck(
        $checks,
        'Temporary files',
        $file,
        $removed,
        $removed
            ? 'Not present.'
            : 'Still present. Remove after its setup/test purpose is complete.'
    );
}

$passedCount = count(array_filter(
    $checks,
    function ($check) {
        return $check['passed'];
    }
));

$reviewCount = count($checks) - $passedCount;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Local Project Check | MHMS</title>

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
            max-width: 1200px;
            margin: auto;
            padding: 32px 24px;
        }

        h1 { margin: 0 0 12px; font-size: 30px; }
        h2 { margin: 0 0 20px; font-size: 21px; }

        .muted {
            color: #62788a;
            line-height: 1.8;
        }

        .stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
            margin: 24px 0;
        }

        .stat, .card {
            padding: 25px;
            border: 1px solid #e1eaf0;
            border-radius: 16px;
            background: white;
        }

        .stat strong {
            display: block;
            margin-top: 12px;
            font-size: 34px;
        }

        .card { margin-bottom: 24px; }

        .notice {
            padding: 18px;
            margin: 24px 0;
            border-radius: 12px;
            background: #fff8e8;
            color: #795718;
            line-height: 1.8;
            font-size: 14px;
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
            vertical-align: top;
        }

        .badge {
            display: inline-block;
            padding: 5px 8px;
            border-radius: 6px;
            font-size: 12px;
            white-space: nowrap;
        }

        .pass {
            background: #e5f6eb;
            color: #166534;
        }

        .review {
            background: #fff0f0;
            color: #a12626;
        }

        .note {
            color: #62788a;
            font-size: 12px;
            line-height: 1.8;
        }

        @media (max-width: 550px) {
            main { padding: 24px 14px; }
            .stats { grid-template-columns: 1fr; }
            .card { padding: 20px; }
        }
    </style>
</head>
<body>
    <header>
        <div class="brand">MHMS</div>
        <a href="index.php">Workspace</a>
    </header>

    <main>
        <h1>Local project check</h1>

        <p class="muted">
            Check expected files, table names, upload support,
            and temporary setup files.
        </p>

        <div class="stats">
            <section class="stat">
                <span>Checks passed</span>
                <strong><?= $passedCount ?></strong>
            </section>

            <section class="stat">
                <span>Items to review</span>
                <strong><?= $reviewCount ?></strong>
            </section>
        </div>

        <div class="notice">
            Passing these checks does not prove the application is
            error-free, secure, or ready for real patient data.
            File contents, PHP syntax, database columns, permissions
            across all workflows, backups, and PDF output still
            require separate tests.
        </div>

        <section class="card">
            <h2>Check results</h2>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Group</th>
                            <th>Item</th>
                            <th>Result</th>
                            <th>Details</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($checks as $check): ?>
                            <tr>
                                <td><?= checkEscape($check['group']) ?></td>
                                <td><?= checkEscape($check['item']) ?></td>

                                <td>
                                    <span class="badge <?= $check['passed']
                                        ? 'pass' : 'review' ?>">
                                        <?= $check['passed']
                                            ? 'Pass' : 'Review' ?>
                                    </span>
                                </td>

                                <td><?= checkEscape(
                                    $check['message']
                                ) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <p class="note">
            Local diagnostic tool only. Do not upload this file
            to public hosting. This checker does not create a backup
            or alter any project records.
        </p>
    </main>
</body>
</html>