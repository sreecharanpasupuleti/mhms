<?php
require_once __DIR__ . '/config/db.php';

date_default_timezone_set('Asia/Kolkata');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

function portalEscape($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

try {
    $query = $pdo->prepare(
        'SELECT role, status FROM users WHERE user_id = ?'
    );
    $query->execute([$_SESSION['user_id']]);
    $account = $query->fetch();

    if (!$account || $account['status'] !== 'active') {
        $_SESSION = [];
        session_destroy();

        header('Location: login.php');
        exit;
    }

    if ($account['role'] !== 'patient') {
        http_response_code(403);
        exit('Sign in with a patient account to open this portal.');
    }

    // Ownership is derived from the authenticated account.
    $query = $pdo->prepare(
        'SELECT * FROM patients WHERE user_id = ?'
    );
    $query->execute([$_SESSION['user_id']]);
    $patient = $query->fetch();

    if (!$patient) {
        http_response_code(403);
        exit('No patient profile is linked to this account. Contact the administrator.');
    }

} catch (PDOException $exception) {
    error_log($exception->getMessage());
    http_response_code(500);
    exit('The account could not be checked. Please try again.');
}

$patientId = (int) $patient['patient_id'];

// Local XAMPP path. Configure separately before online deployment.
$storageDirectory = 'C:/xampp/mhms_private_reports';

// Patient report download: always scope the query to this patient.
if (isset($_GET['download'])) {
    $reportId = filter_input(
        INPUT_GET,
        'download',
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if (!$reportId) {
        http_response_code(400);
        exit('Invalid report reference.');
    }

    try {
        $query = $pdo->prepare(
            'SELECT report_id, file_path FROM lab_reports
             WHERE report_id = ? AND patient_id = ?'
        );
        $query->execute([$reportId, $patientId]);
        $report = $query->fetch();

        if (!$report) {
            http_response_code(403);
            exit('This report is unavailable to your account.');
        }

        $storedName = $report['file_path'] ?? '';

        if (!preg_match(
            '/^[a-f0-9]{32}\.(pdf|jpg|png)$/D',
            $storedName
        )) {
            http_response_code(404);
            exit('A protected download is not available for this report.');
        }

        $path = $storageDirectory . '/' . $storedName;

        if (!is_file($path) || !is_readable($path)) {
            http_response_code(404);
            exit('The report file is missing or unreadable.');
        }

        logAction(
            $pdo,
            $_SESSION['user_id'],
            $patientId,
            'PATIENT_DOWNLOAD_REPORT',
            'Patient downloaded report ID ' . $reportId
        );

        $extension = pathinfo($storedName, PATHINFO_EXTENSION);

        header('Content-Type: application/octet-stream');
        header(
            'Content-Disposition: attachment; filename="demo-report-'
            . $reportId . '.' . $extension . '"'
        );
        header('Content-Length: ' . filesize($path));

        session_write_close();
        readfile($path);
        exit;

    } catch (PDOException $exception) {
        error_log($exception->getMessage());
        http_response_code(500);
        exit('The report could not be downloaded.');
    }
}

if (empty($_SESSION['logout_token'])) {
    $_SESSION['logout_token'] = bin2hex(random_bytes(32));
}

$history = [];
$allergies = [];
$visits = [];
$medicines = [];
$reports = [];
$appointments = [];
$error = '';

try {
    $query = $pdo->prepare(
        'SELECT category, condition_name, diagnosed_date, status, notes
         FROM medical_history WHERE patient_id = ?
         ORDER BY diagnosed_date DESC, history_id DESC'
    );
    $query->execute([$patientId]);
    $history = $query->fetchAll();

    $query = $pdo->prepare(
        'SELECT allergen, reaction, severity
         FROM allergies WHERE patient_id = ?
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
                v.visit_id, v.visit_date,
                u.full_name AS doctor_name
         FROM prescriptions rx
         JOIN visits v ON rx.visit_id = v.visit_id
         JOIN doctors d ON v.doctor_id = d.doctor_id
         JOIN users u ON d.user_id = u.user_id
         WHERE v.patient_id = ?
         ORDER BY v.visit_date DESC, v.visit_id DESC,
                  rx.prescription_id"
    );
    $query->execute([$patientId]);
    $medicines = $query->fetchAll();

    $query = $pdo->prepare(
        'SELECT report_id, test_name, report_date,
                result_summary, file_path
         FROM lab_reports WHERE patient_id = ?
         ORDER BY report_date DESC, report_id DESC'
    );
    $query->execute([$patientId]);
    $reports = $query->fetchAll();

    $query = $pdo->prepare(
        "SELECT a.starts_at, a.ends_at, a.reason, a.status,
                u.full_name AS doctor_name
         FROM appointments a
         JOIN doctors d ON a.doctor_id = d.doctor_id
         JOIN users u ON d.user_id = u.user_id
         WHERE a.patient_id = ?
         ORDER BY a.starts_at DESC, a.appointment_id DESC"
    );
    $query->execute([$patientId]);
    $appointments = $query->fetchAll();

    logAction(
        $pdo,
        $_SESSION['user_id'],
        $patientId,
        'VIEW_PATIENT_PORTAL',
        'Patient opened own portal'
    );

} catch (PDOException $exception) {
    error_log($exception->getMessage());
    $error = 'Your records could not be fully loaded. Please try again.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Health Records | MHMS</title>

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

        main {
            max-width: 1200px;
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

        .hero h1 { margin: 0 0 12px; font-size: 29px; }

        .hero p {
            margin: 8px 0;
            color: #def0f1;
            line-height: 1.8;
        }

        nav {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 24px;
        }

        nav a {
            padding: 10px 14px;
            border-radius: 8px;
            background: #e1eff2;
            color: #086572;
            text-decoration: none;
            font-size: 13px;
        }

        .card {
            padding: 26px;
            margin-bottom: 24px;
            border: 1px solid #e1eaf0;
            border-radius: 17px;
            background: white;
            min-width: 0;
        }

        h2 { margin: 0 0 20px; font-size: 21px; }
        h3 { margin: 12px 0; font-size: 17px; }

        .record {
            padding: 18px;
            margin-top: 14px;
            border: 1px solid #e1eaf0;
            border-radius: 12px;
        }

        .record p {
            margin: 8px 0;
            font-size: 14px;
            line-height: 1.8;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
        }

        .muted {
            color: #62788a;
            line-height: 1.8;
        }

        .note {
            color: #62788a;
            font-size: 12px;
            line-height: 1.8;
        }

        .details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
        }

        .detail {
            padding: 12px 0;
            border-bottom: 1px solid #e1eaf0;
            line-height: 1.8;
            overflow-wrap: anywhere;
            font-size: 14px;
        }

        .detail span {
            display: block;
            color: #62788a;
            font-size: 12px;
        }

        .allergy {
            padding: 16px;
            margin-top: 12px;
            border: 1px solid #eed4a9;
            border-radius: 10px;
            background: #fff8eb;
            font-size: 14px;
            line-height: 1.8;
        }

        .badge {
            display: inline-block;
            padding: 5px 8px;
            border-radius: 6px;
            background: #e6f3f4;
            color: #08727d;
            font-size: 12px;
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

        button:hover, .button:hover { background: #066772; }

        .table-wrap { overflow-x: auto; }

        table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            font-size: 13px;
        }

        th { padding: 13px 10px; background: #f3f7fa; }

        td {
            padding: 15px 10px;
            border-bottom: 1px solid #e1eaf0;
            vertical-align: top;
        }

        .error {
            padding: 18px;
            border-radius: 10px;
            background: #feecec;
            color: #991b1b;
            line-height: 1.8;
        }

        section[id] { scroll-margin-top: 20px; }

        @media (max-width: 600px) {
            main { padding: 24px 14px; }
            .hero, .card { padding: 22px; }
            .details { grid-template-columns: 1fr; }
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
                value="<?= portalEscape($_SESSION['logout_token']) ?>"
            >
            <button type="submit">Sign out</button>
        </form>
    </header>

    <main>
        <section class="hero">
            <h1>Welcome, <?= portalEscape($patient['full_name']) ?>.</h1>
            <p>Your personal medical-record portal</p>
            <p>Medical record number: <?= portalEscape($patient['mrn']) ?></p>
        </section>

        <?php if ($error !== ''): ?>
            <div class="error" role="alert">
                <?= portalEscape($error) ?>
            </div>
        <?php else: ?>
            <nav aria-label="My record sections">
                <a href="#details">My details</a>
                <a href="#history">History</a>
                <a href="#allergies">Allergies</a>
                <a href="#visits">Consultations</a>
                <a href="#prescriptions">Prescriptions</a>
                <a href="#reports">Reports</a>
                <a href="#appointments">Appointments</a>
            </nav>

            <section class="card" id="details">
                <h2>My details</h2>

                <div class="details">
                    <?php
                    $details = [
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
                            <span><?= portalEscape($label) ?></span>
                            <?= portalEscape($value ?: 'Not recorded') ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <p class="note">
                    Contact the administrator to request a correction.
                    This portal does not allow direct record editing.
                </p>
            </section>

            <section class="card" id="history">
                <h2>My recorded history</h2>

                <?php if (!$history): ?>
                    <p class="muted">No history entries recorded.</p>
                <?php endif; ?>

                <?php foreach ($history as $entry): ?>
                    <article class="record">
                        <span class="badge">
                            <?= portalEscape($entry['category']) ?>
                        </span>
                        <h3><?= portalEscape($entry['condition_name']) ?></h3>
                        <p>Date: <?= portalEscape(
                            $entry['diagnosed_date'] ?: 'Not recorded'
                        ) ?></p>
                        <p>Status: <?= portalEscape($entry['status']) ?></p>
                        <?php if ($entry['notes']): ?>
                            <p><?= portalEscape($entry['notes']) ?></p>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </section>

            <section class="card" id="allergies">
                <h2>Recorded allergies</h2>

                <?php if (!$allergies): ?>
                    <p class="muted">
                        No entries recorded. This does not confirm
                        the absence of allergies.
                    </p>
                <?php endif; ?>

                <?php foreach ($allergies as $allergy): ?>
                    <div class="allergy">
                        <strong><?= portalEscape($allergy['allergen']) ?></strong>
                        <br>
                        Reaction: <?= portalEscape(
                            $allergy['reaction'] ?: 'Not recorded'
                        ) ?>
                        <br>
                        Recorded severity:
                        <?= portalEscape($allergy['severity']) ?>
                    </div>
                <?php endforeach; ?>
            </section>

            <section class="card" id="visits">
                <h2>My consultations</h2>

                <?php if (!$visits): ?>
                    <p class="muted">No consultations recorded.</p>
                <?php endif; ?>

                <?php foreach ($visits as $visit): ?>
                    <article class="record">
                        <span class="badge">
                            Visit #<?= (int) $visit['visit_id'] ?>
                            · <?= portalEscape($visit['visit_date']) ?>
                        </span>
                        <h3><?= portalEscape($visit['doctor_name']) ?></h3>
                        <p>Recorded assessment: <?= portalEscape(
                            $visit['diagnosis'] ?: 'Not recorded'
                        ) ?></p>
                        <p>Symptoms: <?= portalEscape(
                            $visit['symptoms'] ?: 'Not recorded'
                        ) ?></p>
                        <p>Treatment plan: <?= portalEscape(
                            $visit['treatment_plan'] ?: 'Not recorded'
                        ) ?></p>
                        <p>Follow-up date: <?= portalEscape(
                            $visit['follow_up_date'] ?: 'Not recorded'
                        ) ?></p>
                    </article>
                <?php endforeach; ?>

                <p class="note">
                    A follow-up date does not automatically book an appointment.
                </p>
            </section>

            <section class="card" id="prescriptions">
                <h2>Recorded prescription entries</h2>

                <?php if (!$medicines): ?>
                    <p class="muted">No medicine entries recorded.</p>
                <?php else: ?>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Visit / doctor</th>
                                    <th>Medicine</th>
                                    <th>Dosage</th>
                                    <th>Frequency</th>
                                    <th>Duration</th>
                                    <th>Instructions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($medicines as $medicine): ?>
                                    <tr>
                                        <td>
                                            <?= portalEscape($medicine['visit_date']) ?>
                                            <br>
                                            Visit #<?= (int) $medicine['visit_id'] ?>
                                            <br>
                                            <?= portalEscape($medicine['doctor_name']) ?>
                                        </td>
                                        <?php foreach (
                                            ['medicine_name', 'dosage', 'frequency',
                                             'duration', 'instructions'] as $field
                                        ): ?>
                                            <td><?= portalEscape(
                                                $medicine[$field] ?: 'Not recorded'
                                            ) ?></td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <p class="note">
                    Historical entries are not a verified current-medication
                    list. All records in this academic prototype are fictional.
                </p>
            </section>

            <section class="card" id="reports">
                <h2>My reports</h2>

                <?php if (!$reports): ?>
                    <p class="muted">No reports recorded.</p>
                <?php endif; ?>

                <?php foreach ($reports as $report): ?>
                    <article class="record">
                        <h3><?= portalEscape($report['test_name']) ?></h3>
                        <p>Date: <?= portalEscape(
                            $report['report_date'] ?: 'Not recorded'
                        ) ?></p>

                        <?php if ($report['result_summary']): ?>
                            <p><?= portalEscape($report['result_summary']) ?></p>
                        <?php endif; ?>

                        <?php if (preg_match(
                            '/^[a-f0-9]{32}\.(pdf|jpg|png)$/D',
                            $report['file_path'] ?? ''
                        )): ?>
                            <a
                                class="button"
                                href="patient_dashboard.php?download=<?= (int) $report['report_id'] ?>"
                            >
                                Download my report
                            </a>
                        <?php else: ?>
                            <p class="note">
                                Protected download unavailable for this entry.
                            </p>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </section>

            <section class="card" id="appointments">
                <h2>My appointments</h2>

                <?php if (!$appointments): ?>
                    <p class="muted">No appointments recorded.</p>
                <?php else: ?>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Start — IST</th>
                                    <th>End — IST</th>
                                    <th>Doctor</th>
                                    <th>Reason</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($appointments as $appointment): ?>
                                    <tr>
                                        <?php foreach (
                                            ['starts_at', 'ends_at', 'doctor_name',
                                             'reason', 'status'] as $field
                                        ): ?>
                                            <td><?= portalEscape(
                                                $appointment[$field]
                                            ) ?></td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <p class="note">
                    Appointment times use India Standard Time.
                    Contact the administrator for appointment changes.
                </p>
            </section>

            <p class="note">
                Academic demonstration · Fictional data only.
                This read-only portal shows records linked to your account.
            </p>
        <?php endif; ?>
    </main>
</body>
</html>