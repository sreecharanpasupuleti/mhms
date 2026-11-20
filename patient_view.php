<?php
require_once __DIR__ . '/config/db.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

function profileEscape($value) {
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
    exit('The account could not be checked. Please try again.');
}

if (!$account || $account['status'] !== 'active') {
    $_SESSION = [];
    session_destroy();

    header('Location: login.php');
    exit;
}

$isAdmin = $account['role'] === 'admin';
$isDoctor = $account['role'] === 'doctor'
    && !empty($account['doctor_id']);

if (!$isAdmin && !$isDoctor) {
    http_response_code(403);
    exit('An administrator or doctor account is required.');
}

$doctorId = (int) ($account['doctor_id'] ?? 0);

$dashboardLink = $isAdmin
    ? 'dashboard.php'
    : 'doctor_dashboard.php';

$patientId = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

$patientOptions = [];
$patient = null;
$history = [];
$allergies = [];
$visits = [];
$prescriptions = [];
$reports = [];

$error = '';
$age = 'Not recorded';

try {
    // Only list patients this account can access.
    if ($isAdmin) {
        $patientOptions = $pdo->query(
            "SELECT patient_id, full_name, mrn
             FROM patients
             ORDER BY full_name, patient_id
             LIMIT 500"
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
        $patientOptions = $query->fetchAll();
    }

    if ($patientId) {
        // Check the selected patient independently of the dropdown.
        if ($isAdmin) {
            $query = $pdo->prepare(
                'SELECT p.* FROM patients p WHERE p.patient_id = ?'
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

            $error = 'This patient profile is unavailable to your account. '
                . 'If you are a doctor, ask the administrator '
                . 'to check your patient assignment.';

        } else {
            if (!empty($patient['dob'])) {
                $birthDate = DateTimeImmutable::createFromFormat(
                    '!Y-m-d',
                    $patient['dob']
                );

                $today = new DateTimeImmutable('today');

                if ($birthDate && $birthDate <= $today) {
                    $age = $birthDate->diff($today)->y . ' years';
                }
            }

            $query = $pdo->prepare(
                "SELECT category, condition_name, diagnosed_date,
                        status, notes
                 FROM medical_history
                 WHERE patient_id = ?
                 ORDER BY diagnosed_date DESC, history_id DESC"
            );
            $query->execute([$patientId]);
            $history = $query->fetchAll();

            $query = $pdo->prepare(
                "SELECT allergen, reaction, severity
                 FROM allergies
                 WHERE patient_id = ?
                 ORDER BY allergy_id DESC"
            );
            $query->execute([$patientId]);
            $allergies = $query->fetchAll();

            $query = $pdo->prepare(
                "SELECT v.visit_id, v.visit_date, v.symptoms,
                        v.diagnosis, v.treatment_plan,
                        v.follow_up_date,
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
                 ORDER BY v.visit_date DESC,
                          v.visit_id DESC,
                          rx.prescription_id"
            );
            $query->execute([$patientId]);
            $prescriptions = $query->fetchAll();

            $query = $pdo->prepare(
                "SELECT r.report_id, r.test_name, r.report_date,
                        r.result_summary, r.file_path,
                        u.full_name AS uploaded_by_name
                 FROM lab_reports r
                 LEFT JOIN users u ON r.uploaded_by = u.user_id
                 WHERE r.patient_id = ?
                 ORDER BY r.report_date DESC, r.report_id DESC"
            );
            $query->execute([$patientId]);
            $reports = $query->fetchAll();

            logAction(
                $pdo,
                $_SESSION['user_id'],
                $patientId,
                'VIEW_PATIENT',
                'Opened patient profile'
            );
        }

    } elseif (isset($_GET['id'])) {
        $error = 'Choose a valid patient.';
    }

} catch (PDOException $exception) {
    error_log($exception->getMessage());

    // Do not display a partially loaded patient record as complete.
    $patient = null;
    $error = 'The patient profile could not be fully loaded. '
        . 'Please try again.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Patient Profile | MHMS</title>

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
            color: #173247;
            font-family: "Segoe UI", Arial, sans-serif;
        }

        .topbar {
            padding: 20px 5%;
            background: var(--navy);
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
            max-width: 1400px;
            margin: auto;
            padding: 30px 24px;
        }

        h1 {
            margin: 0 0 10px;
            font-size: 30px;
        }

        h2 {
            margin: 0 0 20px;
            font-size: 21px;
        }

        h3 {
            margin: 12px 0;
            font-size: 17px;
        }

        .muted {
            color: var(--muted);
            line-height: 1.8;
        }

        .card {
            padding: 25px;
            margin-bottom: 22px;
            border: 1px solid var(--border);
            border-radius: 17px;
            background: white;
            min-width: 0;
        }

        .selector {
            display: flex;
            gap: 12px;
            align-items: end;
        }

        .selector-field {
            flex: 1;
            min-width: 0;
        }

        label {
            display: block;
            margin-bottom: 9px;
            font-size: 14px;
            font-weight: 600;
        }

        select {
            width: 100%;
            padding: 13px;
            border: 1px solid #cad7e0;
            border-radius: 9px;
            background: white;
            color: #173247;
            font: inherit;
            font-size: 14px;
        }

        select:focus {
            outline: 3px solid #d9f0f2;
            border-color: var(--teal);
        }

        button,
        .button {
            display: inline-block;
            padding: 13px 17px;
            border: 0;
            border-radius: 9px;
            background: var(--teal);
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

        .layout {
            display: grid;
            grid-template-columns: 315px minmax(0, 1fr);
            gap: 24px;
            align-items: start;
        }

        .profile-top { text-align: center; }

        .avatar {
            width: 68px;
            height: 68px;
            display: grid;
            place-items: center;
            margin: 0 auto 18px;
            border-radius: 20px;
            background: #dff1f3;
            color: var(--teal);
            font-size: 27px;
            font-weight: bold;
        }

        .mrn {
            color: var(--muted);
            font-family: monospace;
            font-size: 12px;
            overflow-wrap: anywhere;
        }

        dl { margin: 24px 0 0; }

        .detail {
            padding: 12px 0;
            border-top: 1px solid var(--border);
        }

        dt {
            margin-bottom: 6px;
            color: var(--muted);
            font-size: 12px;
        }

        dd {
            margin: 0;
            font-size: 14px;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 22px;
        }

        .stat {
            padding: 18px;
            background: white;
            border: 1px solid var(--border);
            border-radius: 13px;
        }

        .stat strong {
            display: block;
            margin-bottom: 8px;
            font-size: 27px;
        }

        .stat span {
            color: var(--muted);
            font-size: 12px;
        }

        .section-links {
            display: flex;
            gap: 9px;
            flex-wrap: wrap;
            margin-bottom: 22px;
        }

        .section-links a {
            padding: 9px 12px;
            border-radius: 8px;
            background: #e1eff2;
            color: #086572;
            text-decoration: none;
            font-size: 13px;
        }

        section[id] { scroll-margin-top: 20px; }

        .record {
            padding: 18px;
            margin-top: 14px;
            border: 1px solid var(--border);
            border-radius: 12px;
        }

        .record p {
            margin: 8px 0;
            font-size: 14px;
            line-height: 1.8;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
        }

        .record-header {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            color: var(--muted);
            font-size: 12px;
        }

        .badge {
            display: inline-block;
            padding: 5px 8px;
            border-radius: 6px;
            background: #e6f3f4;
            color: #08727d;
            font-size: 12px;
        }

        .allergy {
            padding: 16px;
            margin-top: 12px;
            border: 1px solid #eed4a9;
            border-radius: 10px;
            background: #fff8eb;
        }

        .allergy p {
            margin: 8px 0 0;
            font-size: 13px;
            line-height: 1.7;
        }

        .table-wrap { overflow-x: auto; }

        table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            font-size: 13px;
        }

        th {
            padding: 12px 10px;
            background: #f3f7fa;
            color: var(--muted);
        }

        td {
            padding: 15px 10px;
            border-bottom: 1px solid var(--border);
            vertical-align: top;
            overflow-wrap: anywhere;
        }

        .cell-note {
            display: block;
            margin-top: 7px;
            color: var(--muted);
            font-size: 11px;
            line-height: 1.7;
        }

        .error {
            padding: 16px;
            margin-bottom: 22px;
            border-radius: 10px;
            background: #feecec;
            color: #991b1b;
            line-height: 1.8;
        }

        .note {
            color: var(--muted);
            font-size: 12px;
            line-height: 1.8;
        }

        @media (max-width: 1000px) {
            .layout { grid-template-columns: 1fr; }
        }

        @media (max-width: 600px) {
            main { padding: 24px 14px; }
            .card { padding: 20px; }
            .stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
            .selector {
                flex-direction: column;
                align-items: stretch;
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
            <a href="<?= profileEscape($dashboardLink) ?>">Dashboard</a>
            <a href="report_upload.php">Lab reports</a>

            <?php if ($isAdmin): ?>
                <a href="patients.php">Manage patients</a>
            <?php else: ?>
                <a href="visit_add.php">Add consultation</a>
            <?php endif; ?>
        </nav>
    </header>

    <main>
        <h1>Patient profile</h1>

        <p class="muted">
            <?= $isAdmin
                ? 'Review a fictional patient’s recorded information.'
                : 'Review records for a patient currently assigned to you.' ?>
        </p>

        <section class="card">
            <?php if ($patientOptions): ?>
                <form method="GET" action="patient_view.php" class="selector">
                    <div class="selector-field">
                        <label for="patient">Choose patient</label>

                        <select id="patient" name="id" required>
                            <option value="">Select a patient</option>

                            <?php foreach ($patientOptions as $option): ?>
                                <option
                                    value="<?= (int) $option['patient_id'] ?>"
                                    <?= (int) $patientId ===
                                        (int) $option['patient_id']
                                        ? 'selected' : '' ?>
                                >
                                    <?= profileEscape(
                                        $option['full_name']
                                        . ' — ' . $option['mrn']
                                    ) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <button type="submit">Open profile</button>
                </form>

                <p class="note">
                    Showing up to 500 accessible patient profiles.
                </p>
            <?php else: ?>
                <p class="muted">
                    No accessible patient profiles are available.
                    <?= $isAdmin
                        ? 'Create a patient record first.'
                        : 'Ask the administrator to assign a patient to you.' ?>
                </p>
            <?php endif; ?>
        </section>

        <?php if ($error !== ''): ?>
            <div class="error" role="alert">
                <?= profileEscape($error) ?>
            </div>
        <?php elseif ($patient): ?>
            <div class="layout">
                <aside>
                    <section class="card">
                        <div class="profile-top">
                            <div class="avatar" aria-hidden="true">P</div>

                            <h2><?= profileEscape(
                                $patient['full_name']
                            ) ?></h2>

                            <div class="mrn">
                                <?= profileEscape($patient['mrn']) ?>
                            </div>
                        </div>

                        <dl>
                            <?php
                            $details = [
                                'Date of birth' => $patient['dob'],
                                'Age' => $age,
                                'Gender' => $patient['gender'],
                                'Blood group' => $patient['blood_group'],
                                'Phone' => $patient['phone'],
                                'Email' => $patient['email'],
                                'Emergency contact' => $patient['emergency_contact'],
                                'Address' => $patient['address']
                            ];
                            ?>

                            <?php foreach ($details as $label => $value): ?>
                                <div class="detail">
                                    <dt><?= profileEscape($label) ?></dt>
                                    <dd><?= profileEscape(
                                        $value ?: 'Not recorded'
                                    ) ?></dd>
                                </div>
                            <?php endforeach; ?>
                        </dl>
                    </section>

                    <section class="card" id="allergies">
                        <h2>Recorded allergies</h2>

                        <?php if (!$allergies): ?>
                            <p class="muted">
                                No allergy entries recorded.
                                This does not confirm the absence of allergies.
                            </p>
                        <?php endif; ?>

                        <?php foreach ($allergies as $allergy): ?>
                            <div class="allergy">
                                <strong><?= profileEscape(
                                    $allergy['allergen']
                                ) ?></strong>

                                <p>
                                    Reaction:
                                    <?= profileEscape(
                                        $allergy['reaction'] ?: 'Not recorded'
                                    ) ?>
                                </p>

                                <p>
                                    Recorded severity:
                                    <?= profileEscape($allergy['severity']) ?>
                                </p>
                            </div>
                        <?php endforeach; ?>
                    </section>
                </aside>

                <div>
                    <section class="stats" aria-label="Record counts">
                        <?php
                        $counts = [
                            'History entries' => count($history),
                            'Consultations' => count($visits),
                            'Medicine entries' => count($prescriptions),
                            'Reports' => count($reports)
                        ];
                        ?>

                        <?php foreach ($counts as $label => $count): ?>
                            <div class="stat">
                                <strong><?= $count ?></strong>
                                <span><?= profileEscape($label) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </section>

                    <nav class="section-links" aria-label="Profile sections">
                        <a href="#history">Medical history</a>
                        <a href="#allergies">Allergies</a>
                        <a href="#consultations">Consultations</a>
                        <a href="#prescriptions">Prescriptions</a>
                        <a href="#reports">Reports</a>
                    </nav>

                    <section class="card" id="history">
                        <h2>Medical history</h2>

                        <?php if (!$history): ?>
                            <p class="muted">No history entries recorded.</p>
                        <?php endif; ?>

                        <?php foreach ($history as $entry): ?>
                            <article class="record">
                                <div class="record-header">
                                    <span class="badge">
                                        <?= profileEscape($entry['category']) ?>
                                    </span>

                                    <span><?= profileEscape(
                                        $entry['diagnosed_date']
                                        ?: 'Date not recorded'
                                    ) ?></span>
                                </div>

                                <h3><?= profileEscape(
                                    $entry['condition_name']
                                ) ?></h3>

                                <p>Status: <?= profileEscape(
                                    $entry['status']
                                ) ?></p>

                                <?php if ($entry['notes']): ?>
                                    <p><?= profileEscape($entry['notes']) ?></p>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </section>

                    <section class="card" id="consultations">
                        <h2>Consultation history</h2>

                        <?php if (!$visits): ?>
                            <p class="muted">No consultations recorded.</p>
                        <?php endif; ?>

                        <?php foreach ($visits as $visit): ?>
                            <article class="record">
                                <div class="record-header">
                                    <span>
                                        Visit #<?= (int) $visit['visit_id'] ?>
                                        · <?= profileEscape(
                                            $visit['visit_date']
                                        ) ?>
                                    </span>

                                    <span><?= profileEscape(
                                        $visit['doctor_name']
                                    ) ?></span>
                                </div>

                                <h3><?= profileEscape(
                                    $visit['diagnosis'] ?: 'Not recorded'
                                ) ?></h3>

                                <p>Symptoms: <?= profileEscape(
                                    $visit['symptoms'] ?: 'Not recorded'
                                ) ?></p>

                                <p>Treatment plan: <?= profileEscape(
                                    $visit['treatment_plan'] ?: 'Not recorded'
                                ) ?></p>

                                <p>Follow-up: <?= profileEscape(
                                    $visit['follow_up_date'] ?: 'Not recorded'
                                ) ?></p>
                            </article>
                        <?php endforeach; ?>
                    </section>

                    <section class="card" id="prescriptions">
                        <h2>Recorded prescription entries</h2>

                        <?php if (!$prescriptions): ?>
                            <p class="muted">No medicine entries recorded.</p>
                        <?php else: ?>
                            <div class="table-wrap">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Medicine / consultation</th>
                                            <th>Dosage</th>
                                            <th>Frequency</th>
                                            <th>Duration</th>
                                            <th>Instructions</th>
                                        </tr>
                                    </thead>

                                    <tbody>
                                        <?php foreach (
                                            $prescriptions as $medicine
                                        ): ?>
                                            <tr>
                                                <td>
                                                    <?= profileEscape(
                                                        $medicine['medicine_name']
                                                    ) ?>

                                                    <span class="cell-note">
                                                        Visit #<?= (int)
                                                            $medicine['visit_id']
                                                        ?>
                                                        · <?= profileEscape(
                                                            $medicine['visit_date']
                                                        ) ?>
                                                        <br>
                                                        <?= profileEscape(
                                                            $medicine['doctor_name']
                                                        ) ?>
                                                    </span>
                                                </td>

                                                <td><?= profileEscape(
                                                    $medicine['dosage']
                                                ) ?></td>

                                                <td><?= profileEscape(
                                                    $medicine['frequency']
                                                ) ?></td>

                                                <td><?= profileEscape(
                                                    $medicine['duration']
                                                ) ?></td>

                                                <td><?= profileEscape(
                                                    $medicine['instructions']
                                                    ?: 'Not recorded'
                                                ) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <p class="note">
                                These are historical recorded entries.
                                They are not a verified current-medication list
                                or treatment recommendations.
                            </p>
                        <?php endif; ?>
                    </section>

                    <section class="card" id="reports">
                        <h2>Lab reports</h2>

                        <?php if (!$reports): ?>
                            <p class="muted">No reports recorded.</p>
                        <?php endif; ?>

                        <?php foreach ($reports as $report): ?>
                            <article class="record">
                                <div class="record-header">
                                    <span><?= profileEscape(
                                        $report['report_date']
                                        ?: 'Date not recorded'
                                    ) ?></span>

                                    <span>
                                        Uploaded by:
                                        <?= profileEscape(
                                            $report['uploaded_by_name']
                                            ?: 'Unknown account'
                                        ) ?>
                                    </span>
                                </div>

                                <h3><?= profileEscape(
                                    $report['test_name']
                                ) ?></h3>

                                <?php if ($report['result_summary']): ?>
                                    <p><?= profileEscape(
                                        $report['result_summary']
                                    ) ?></p>
                                <?php endif; ?>

                                <?php if (preg_match(
                                    '/^[a-f0-9]{32}\.(pdf|jpg|png)$/D',
                                    $report['file_path'] ?? ''
                                )): ?>
                                    <a
                                        class="button"
                                        href="report_upload.php?download=<?= (int) $report['report_id'] ?>"
                                    >
                                        Download report
                                    </a>
                                <?php else: ?>
                                    <p class="note">
                                        No protected download is available
                                        for this older report entry.
                                    </p>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </section>

                    <p class="note">
                        Academic demonstration · Fictional data only.
                        Assigned doctors can review this patient’s recorded
                        history, including entries from other clinicians.
                        This page is read-only.
                    </p>
                </div>
            </div>

        <?php else: ?>
            <section class="card">
                <h2>Select a patient to begin</h2>
                <p class="muted">
                    Choose an accessible patient above and click Open profile.
                </p>
            </section>
        <?php endif; ?>
    </main>
</body>
</html>