<?php
require_once __DIR__ . '/config/db.php';

date_default_timezone_set('Asia/Kolkata');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

function summaryEscape($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function summaryTable($rows, $columns, $emptyMessage) {
    if (!$rows) {
        echo '<p class="muted">'
            . summaryEscape($emptyMessage)
            . '</p>';
        return;
    }

    echo '<div class="table-wrap"><table><thead><tr>';

    foreach ($columns as $label) {
        echo '<th>' . summaryEscape($label) . '</th>';
    }

    echo '</tr></thead><tbody>';

    foreach ($rows as $row) {
        echo '<tr>';

        foreach ($columns as $field => $label) {
            $value = $row[$field] ?? '';

            echo '<td>'
                . summaryEscape(
                    $value !== '' && $value !== null
                        ? $value
                        : 'Not recorded'
                )
                . '</td>';
        }

        echo '</tr>';
    }

    echo '</tbody></table></div>';
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
    exit('Your account could not be checked.');
}

if (!$account || $account['status'] !== 'active') {
    $_SESSION = [];
    session_destroy();

    header('Location: login.php');
    exit;
}

$role = $account['role'];
$doctorId = (int) ($account['doctor_id'] ?? 0);

if (
    !in_array($role, ['admin', 'doctor', 'patient'], true)
    || ($role === 'doctor' && !$doctorId)
) {
    http_response_code(403);
    exit('This account cannot access medical summaries.');
}

$dashboardLinks = [
    'admin' => 'dashboard.php',
    'doctor' => 'doctor_dashboard.php',
    'patient' => 'patient_dashboard.php'
];

$patientId = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

$options = [];
$patient = null;
$history = [];
$allergies = [];
$visits = [];
$medicines = [];
$reports = [];
$error = '';

try {
    if ($role === 'patient') {
        // Patient-supplied IDs are ignored.
        $query = $pdo->prepare(
            'SELECT * FROM patients WHERE user_id = ?'
        );
        $query->execute([$_SESSION['user_id']]);
        $patient = $query->fetch();

        if (!$patient) {
            http_response_code(403);
            $error = 'No patient profile is linked to this account.';
        } else {
            $patientId = (int) $patient['patient_id'];
        }

    } else {
        if ($role === 'admin') {
            $options = $pdo->query(
                'SELECT patient_id, full_name, mrn
                 FROM patients
                 ORDER BY full_name, patient_id
                 LIMIT 500'
            )->fetchAll();

        } else {
            $query = $pdo->prepare(
                "SELECT p.patient_id, p.full_name, p.mrn
                 FROM patients p
                 JOIN patient_assignments a
                     ON a.patient_id = p.patient_id
                 WHERE a.doctor_id = ?
                 ORDER BY p.full_name, p.patient_id
                 LIMIT 500"
            );
            $query->execute([$doctorId]);
            $options = $query->fetchAll();
        }

        if ($patientId) {
            if ($role === 'admin') {
                $query = $pdo->prepare(
                    'SELECT * FROM patients WHERE patient_id = ?'
                );
                $query->execute([$patientId]);

            } else {
                $query = $pdo->prepare(
                    "SELECT p.*
                     FROM patients p
                     JOIN patient_assignments a
                         ON a.patient_id = p.patient_id
                     WHERE p.patient_id = ? AND a.doctor_id = ?"
                );
                $query->execute([$patientId, $doctorId]);
            }

            $patient = $query->fetch();

            if (!$patient) {
                http_response_code(403);
                $error = 'This patient summary is unavailable to your account.';
            }

        } elseif (isset($_GET['id'])) {
            $error = 'Select a valid patient.';
        }
    }

    if ($patient && $error === '') {
        $query = $pdo->prepare(
            'SELECT category, condition_name, diagnosed_date, status, notes
             FROM medical_history
             WHERE patient_id = ?
             ORDER BY diagnosed_date DESC, history_id DESC'
        );
        $query->execute([$patientId]);
        $history = $query->fetchAll();

        $query = $pdo->prepare(
            'SELECT allergen, reaction, severity
             FROM allergies
             WHERE patient_id = ?
             ORDER BY allergy_id DESC'
        );
        $query->execute([$patientId]);
        $allergies = $query->fetchAll();

        $query = $pdo->prepare(
            "SELECT v.visit_id, v.visit_date, v.symptoms, v.diagnosis,
                    v.treatment_plan, v.follow_up_date,
                    u.full_name AS doctor_name
             FROM visits v
             JOIN doctors d ON v.doctor_id = d.doctor_id
             JOIN users u ON d.user_id = u.user_id
             WHERE v.patient_id = ?
             ORDER BY v.visit_date DESC, v.visit_id DESC"
        );
        $query->execute([$patientId]);
        $visits = $query->fetchAll();

        $query = $pdo->prepare(
            "SELECT rx.medicine_name, rx.dosage, rx.frequency,
                    rx.duration, rx.instructions,
                    v.visit_id, v.visit_date
             FROM prescriptions rx
             JOIN visits v ON rx.visit_id = v.visit_id
             WHERE v.patient_id = ?
             ORDER BY v.visit_date DESC, v.visit_id DESC,
                      rx.prescription_id"
        );
        $query->execute([$patientId]);
        $medicines = $query->fetchAll();

        $query = $pdo->prepare(
            'SELECT test_name, report_date, result_summary
             FROM lab_reports
             WHERE patient_id = ?
             ORDER BY report_date DESC, report_id DESC'
        );
        $query->execute([$patientId]);
        $reports = $query->fetchAll();

        logAction(
            $pdo,
            $_SESSION['user_id'],
            $patientId,
            'VIEW_MEDICAL_SUMMARY',
            'Opened printable medical summary'
        );
    }

} catch (PDOException $exception) {
    error_log($exception->getMessage());

    $patient = null;
    $error = 'The summary could not be fully loaded. Please try again.';
}

$generatedAt = date('Y-m-d H:i') . ' IST';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Demo Medical Summary | MHMS</title>

    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: #edf3f7;
            color: #173247;
            font-family: "Segoe UI", Arial, sans-serif;
        }

        .topbar {
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

        .topbar a { color: white; text-decoration: none; }

        main {
            max-width: 1150px;
            margin: auto;
            padding: 30px 24px;
        }

        .controls {
            padding: 25px;
            margin-bottom: 24px;
            border: 1px solid #dde7ee;
            border-radius: 16px;
            background: white;
        }

        h1 { margin: 0 0 20px; font-size: 27px; }

        label {
            display: block;
            margin-bottom: 9px;
            font-size: 14px;
            font-weight: 600;
        }

        select {
            width: 100%;
            padding: 13px;
            margin-bottom: 16px;
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
            font-weight: 600;
            cursor: pointer;
        }

        button:hover { background: #066772; }

        .print-button { margin-top: 18px; }

        .sheet {
            padding: 40px;
            border: 1px solid #dde7ee;
            border-radius: 14px;
            background: white;
        }

        .sheet-header {
            padding-bottom: 20px;
            border-bottom: 3px solid #087e8b;
        }

        .sheet-header h2 {
            margin: 0 0 10px;
            color: #087e8b;
            font-size: 28px;
        }

        .sheet-header p { margin: 6px 0; }

        .banner {
            padding: 12px;
            margin: 22px 0;
            border: 1px solid #b78120;
            background: #fff8e8;
            color: #795718;
            text-align: center;
            font-size: 13px;
            line-height: 1.7;
        }

        .details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px 24px;
        }

        .detail {
            padding: 10px 0;
            border-bottom: 1px solid #e1eaf0;
            font-size: 14px;
            line-height: 1.8;
            overflow-wrap: anywhere;
        }

        .detail span {
            display: block;
            color: #62788a;
            font-size: 12px;
        }

        section.summary-section { margin-top: 30px; }

        h3 {
            margin: 0 0 16px;
            font-size: 19px;
        }

        .table-wrap { overflow-x: auto; }

        table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            font-size: 12px;
        }

        th {
            padding: 12px 8px;
            background: #eef5f7;
            border-bottom: 1px solid #cbdde4;
        }

        td {
            padding: 13px 8px;
            border-bottom: 1px solid #dce6ec;
            vertical-align: top;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
        }

        .visit {
            padding: 18px;
            margin-bottom: 14px;
            border: 1px solid #dce6ec;
            border-radius: 10px;
        }

        .visit p {
            margin: 8px 0;
            font-size: 13px;
            line-height: 1.8;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
        }

        .muted, .note {
            color: #62788a;
            line-height: 1.8;
        }

        .note { font-size: 12px; }

        .error {
            padding: 16px;
            margin-bottom: 20px;
            border-radius: 10px;
            background: #feecec;
            color: #991b1b;
            line-height: 1.8;
        }

        .sheet-footer {
            margin-top: 30px;
            padding-top: 18px;
            border-top: 1px solid #dce6ec;
        }

        @media (max-width: 600px) {
            main { padding: 20px 12px; }
            .sheet { padding: 24px 18px; }
            .details { grid-template-columns: 1fr; }
        }

        @media print {
            @page {
                size: A4;
                margin: 15mm;
            }

            body {
                background: white;
                color: black;
            }

            .no-print { display: none !important; }

            main {
                max-width: none;
                margin: 0;
                padding: 0;
            }

            .sheet {
                padding: 0;
                border: 0;
                border-radius: 0;
            }

            .table-wrap { overflow: visible; }

            table {
                table-layout: fixed;
                font-size: 9pt;
            }

            th, td { padding: 7px 5px; }

            thead { display: table-header-group; }

            tr, .detail {
                break-inside: avoid;
            }

            h3 { break-after: avoid; }

            .banner {
                color: black;
                border-color: black;
            }

            .muted, .note { color: #333; }
        }
    </style>
</head>
<body>
    <header class="topbar no-print">
        <div class="brand">MHMS</div>

        <a href="<?= summaryEscape($dashboardLinks[$role]) ?>">
            Back to dashboard
        </a>
    </header>

    <main>
        <section class="controls no-print">
            <h1>Medical summary</h1>

            <?php if ($role !== 'patient'): ?>
                <?php if ($options): ?>
                    <form method="GET" action="medical_summary.php">
                        <label for="patient">Choose accessible patient</label>

                        <select id="patient" name="id" required>
                            <option value="">Select patient</option>

                            <?php foreach ($options as $option): ?>
                                <option
                                    value="<?= (int) $option['patient_id'] ?>"
                                    <?= (int) $patientId ===
                                        (int) $option['patient_id']
                                        ? 'selected' : '' ?>
                                >
                                    <?= summaryEscape(
                                        $option['full_name']
                                        . ' — ' . $option['mrn']
                                    ) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <button type="submit">Open summary</button>
                    </form>

                    <p class="note">
                        Showing up to 500 accessible patient profiles.
                    </p>
                <?php else: ?>
                    <p class="muted">
                        No accessible patient profiles are available.
                    </p>
                <?php endif; ?>
            <?php else: ?>
                <p class="muted">
                    This document contains records linked to your account.
                </p>
            <?php endif; ?>

            <?php if ($patient && $error === ''): ?>
                <button
                    class="print-button"
                    type="button"
                    onclick="window.print()"
                >
                    Print / Save as PDF
                </button>
            <?php endif; ?>
        </section>

        <?php if ($error !== ''): ?>
            <div class="error no-print" role="alert">
                <?= summaryEscape($error) ?>
            </div>
        <?php endif; ?>

        <?php if ($patient && $error === ''): ?>
            <article class="sheet">
                <header class="sheet-header">
                    <h2>MHMS · Medical Summary</h2>
                    <p>Medical History Management System</p>
                    <p class="note">
                        Summary opened: <?= summaryEscape($generatedAt) ?>
                    </p>
                </header>

                <div class="banner">
                    ACADEMIC DEMONSTRATION — FICTIONAL DATA ONLY
                    <br>
                    Not a clinically verified medical document
                    or a valid prescription.
                </div>

                <section class="details">
                    <?php
                    $details = [
                        'Patient name' => $patient['full_name'],
                        'Medical record number' => $patient['mrn'],
                        'Date of birth' => $patient['dob'],
                        'Gender' => $patient['gender'],
                        'Blood group' => $patient['blood_group'],
                        'Phone' => $patient['phone'],
                        'Contact email' => $patient['email'],
                        'Emergency contact' => $patient['emergency_contact'],
                        'Address' => $patient['address']
                    ];
                    ?>

                    <?php foreach ($details as $label => $value): ?>
                        <div class="detail">
                            <span><?= summaryEscape($label) ?></span>
                            <?= summaryEscape($value ?: 'Not recorded') ?>
                        </div>
                    <?php endforeach; ?>
                </section>

                <section class="summary-section">
                    <h3>Recorded allergies</h3>

                    <?php
                    summaryTable(
                        $allergies,
                        [
                            'allergen' => 'Allergen',
                            'reaction' => 'Reaction',
                            'severity' => 'Recorded severity'
                        ],
                        'No allergy entries recorded. This does not '
                        . 'confirm the absence of allergies.'
                    );
                    ?>
                </section>

                <section class="summary-section">
                    <h3>Medical history</h3>

                    <?php
                    summaryTable(
                        $history,
                        [
                            'category' => 'Category',
                            'condition_name' => 'Condition / history',
                            'diagnosed_date' => 'Relevant date',
                            'status' => 'Status',
                            'notes' => 'Notes'
                        ],
                        'No medical-history entries recorded.'
                    );
                    ?>
                </section>

                <section class="summary-section">
                    <h3>Consultations</h3>

                    <?php if (!$visits): ?>
                        <p class="muted">No consultations recorded.</p>
                    <?php endif; ?>

                    <?php foreach ($visits as $visit): ?>
                        <article class="visit">
                            <p>
                                <strong>
                                    Visit #<?= (int) $visit['visit_id'] ?>
                                    · <?= summaryEscape($visit['visit_date']) ?>
                                </strong>
                            </p>

                            <p>Doctor: <?= summaryEscape(
                                $visit['doctor_name']
                            ) ?></p>

                            <p>Symptoms: <?= summaryEscape(
                                $visit['symptoms'] ?: 'Not recorded'
                            ) ?></p>

                            <p>Recorded assessment: <?= summaryEscape(
                                $visit['diagnosis'] ?: 'Not recorded'
                            ) ?></p>

                            <p>Treatment plan: <?= summaryEscape(
                                $visit['treatment_plan'] ?: 'Not recorded'
                            ) ?></p>

                            <p>Follow-up date: <?= summaryEscape(
                                $visit['follow_up_date'] ?: 'Not recorded'
                            ) ?></p>
                        </article>
                    <?php endforeach; ?>
                </section>

                <section class="summary-section">
                    <h3>Historical prescription entries</h3>

                    <?php
                    summaryTable(
                        $medicines,
                        [
                            'visit_id' => 'Visit #',
                            'visit_date' => 'Visit date',
                            'medicine_name' => 'Medicine',
                            'dosage' => 'Dosage',
                            'frequency' => 'Frequency',
                            'duration' => 'Duration',
                            'instructions' => 'Instructions'
                        ],
                        'No prescription entries recorded.'
                    );
                    ?>

                    <p class="note">
                        Historical entries are not a verified
                        current-medication list.
                    </p>
                </section>

                <section class="summary-section">
                    <h3>Report register</h3>

                    <?php
                    summaryTable(
                        $reports,
                        [
                            'test_name' => 'Report name',
                            'report_date' => 'Report date',
                            'result_summary' => 'Recorded summary'
                        ],
                        'No reports recorded.'
                    );
                    ?>

                    <p class="note">
                        This register lists report information.
                        Uploaded report files are not embedded in this document.
                    </p>
                </section>

                <footer class="sheet-footer note">
                    Academic prototype. This document reproduces recorded
                    information without clinical verification.
                    A follow-up date does not automatically book an appointment.
                    <br>
                    The application logs opening the summary, not whether
                    printing or saving the PDF was completed.
                </footer>
            </article>
        <?php endif; ?>
    </main>
</body>
</html>