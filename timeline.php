<?php
require_once __DIR__ . '/config/db.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

function timelineEscape($value) {
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

$role = $account['role'];
$doctorId = (int) ($account['doctor_id'] ?? 0);

if (
    !in_array($role, ['admin', 'doctor', 'patient'], true)
    || ($role === 'doctor' && !$doctorId)
) {
    http_response_code(403);
    exit('This account cannot access patient timelines.');
}

$dashboards = [
    'admin' => 'dashboard.php',
    'doctor' => 'doctor_dashboard.php',
    'patient' => 'patient_dashboard.php'
];

$types = [
    'all' => 'All record types',
    'history' => 'Medical history',
    'consultation' => 'Consultations',
    'allergy' => 'Allergies',
    'prescription' => 'Prescription entries',
    'report' => 'Lab reports'
];

$typeInput = $_GET['type'] ?? 'all';
$type = is_string($typeInput) && isset($types[$typeInput])
    ? $typeInput
    : 'all';

$patientId = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

$options = [];
$patient = null;
$events = [];
$error = '';

try {
    if ($role === 'patient') {
        // Patient-supplied IDs do not choose the profile.
        $query = $pdo->prepare(
            'SELECT patient_id, full_name, mrn
             FROM patients WHERE user_id = ?'
        );
        $query->execute([$_SESSION['user_id']]);
        $patient = $query->fetch();

        if (!$patient) {
            http_response_code(403);
            $error = 'No patient profile is linked to your account.';
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
                     ON p.patient_id = a.patient_id
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
                    'SELECT patient_id, full_name, mrn
                     FROM patients WHERE patient_id = ?'
                );
                $query->execute([$patientId]);

            } else {
                $query = $pdo->prepare(
                    "SELECT p.patient_id, p.full_name, p.mrn
                     FROM patients p
                     JOIN patient_assignments a
                         ON p.patient_id = a.patient_id
                     WHERE p.patient_id = ? AND a.doctor_id = ?"
                );
                $query->execute([$patientId, $doctorId]);
            }

            $patient = $query->fetch();

            if (!$patient) {
                http_response_code(403);
                $error = 'This patient timeline is unavailable to your account.';
            }

        } elseif (isset($_GET['id'])) {
            $error = 'Select a valid patient.';
        }
    }

    if ($patient && $error === '') {
        $sql = "
            SELECT *
            FROM (
                SELECT
                    'history' AS event_type,
                    history_id AS source_id,
                    CAST(diagnosed_date AS DATETIME) AS event_time,
                    'Relevant history date' AS date_label,
                    condition_name AS title,
                    CONCAT(
                        'Category: ', category,
                        '\nStatus: ', status,
                        '\nNotes: ', COALESCE(notes, 'Not recorded')
                    ) AS description
                FROM medical_history
                WHERE patient_id = ?

                UNION ALL

                SELECT
                    'consultation',
                    v.visit_id,
                    CAST(v.visit_date AS DATETIME),
                    'Consultation date',
                    COALESCE(NULLIF(v.diagnosis, ''), 'Consultation'),
                    CONCAT(
                        'Doctor: ', u.full_name,
                        '\nSymptoms: ', COALESCE(v.symptoms, 'Not recorded'),
                        '\nPlan: ', COALESCE(v.treatment_plan, 'Not recorded'),
                        '\nFollow-up: ',
                        COALESCE(
                            CAST(v.follow_up_date AS CHAR),
                            'Not recorded'
                        )
                    )
                FROM visits v
                JOIN doctors d ON v.doctor_id = d.doctor_id
                JOIN users u ON d.user_id = u.user_id
                WHERE v.patient_id = ?

                UNION ALL

                SELECT
                    'allergy',
                    allergy_id,
                    created_at,
                    'Entry recorded at',
                    allergen,
                    CONCAT(
                        'Recorded reaction: ',
                        COALESCE(reaction, 'Not recorded'),
                        '\nRecorded severity: ', severity
                    )
                FROM allergies
                WHERE patient_id = ?

                UNION ALL

                SELECT
                    'prescription',
                    rx.prescription_id,
                    rx.created_at,
                    'Entry recorded at',
                    rx.medicine_name,
                    CONCAT(
                        'Consultation: #', v.visit_id,
                        ' on ', CAST(v.visit_date AS CHAR),
                        '\nDosage: ', COALESCE(rx.dosage, 'Not recorded'),
                        '\nFrequency: ', COALESCE(rx.frequency, 'Not recorded'),
                        '\nDuration: ', COALESCE(rx.duration, 'Not recorded'),
                        '\nInstructions: ',
                        COALESCE(rx.instructions, 'Not recorded')
                    )
                FROM prescriptions rx
                JOIN visits v ON rx.visit_id = v.visit_id
                WHERE v.patient_id = ?

                UNION ALL

                SELECT
                    'report',
                    report_id,
                    CAST(report_date AS DATETIME),
                    'Report date',
                    test_name,
                    COALESCE(result_summary, 'No summary recorded')
                FROM lab_reports
                WHERE patient_id = ?
            ) AS timeline_events
        ";

        $parameters = array_fill(0, 5, $patientId);

        if ($type !== 'all') {
            $sql .= ' WHERE event_type = ?';
            $parameters[] = $type;
        }

        $sql .= "
            ORDER BY
                event_time IS NULL ASC,
                event_time DESC,
                event_type ASC,
                source_id DESC
            LIMIT 100
        ";

        $query = $pdo->prepare($sql);
        $query->execute($parameters);
        $events = $query->fetchAll();

        logAction(
            $pdo,
            $_SESSION['user_id'],
            $patientId,
            'VIEW_PATIENT_TIMELINE',
            'Opened timeline; filter: ' . $type
        );
    }

} catch (PDOException $exception) {
    error_log($exception->getMessage());

    $patient = null;
    $events = [];
    $error = 'The timeline could not be loaded. Please try again.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Medical Timeline | MHMS</title>

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
            max-width: 1050px;
            margin: auto;
            padding: 32px 24px;
        }

        h1 { margin: 0 0 12px; font-size: 30px; }
        h2 { margin: 0 0 12px; font-size: 23px; }

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

        label {
            display: block;
            margin-bottom: 8px;
            font-size: 14px;
            font-weight: 600;
        }

        select {
            width: 100%;
            padding: 13px;
            margin-bottom: 18px;
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

        .row {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 18px;
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

        .notice {
            padding: 16px;
            margin: 22px 0;
            border-radius: 10px;
            background: #e6f3f4;
            color: #245866;
            font-size: 13px;
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

        .timeline {
            position: relative;
            padding-left: 31px;
            margin-top: 25px;
        }

        .timeline::before {
            content: "";
            position: absolute;
            left: 8px;
            top: 0;
            bottom: 0;
            width: 3px;
            background: #d2e3e9;
        }

        .event {
            position: relative;
            padding: 23px;
            margin-bottom: 20px;
            border: 1px solid #e1eaf0;
            border-radius: 15px;
            background: white;
        }

        .event::before {
            content: "";
            position: absolute;
            left: -30px;
            top: 25px;
            width: 14px;
            height: 14px;
            border: 3px solid #f3f7fa;
            border-radius: 50%;
            background: #087e8b;
            box-shadow: 0 0 0 2px #087e8b;
        }

        .event-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            gap: 12px;
            flex-wrap: wrap;
        }

        .badge {
            padding: 6px 10px;
            border-radius: 7px;
            background: #e6f3f4;
            color: #08727d;
            font-size: 12px;
        }

        .date {
            color: #62788a;
            font-size: 12px;
            line-height: 1.8;
        }

        .event h3 {
            margin: 18px 0 12px;
            font-size: 18px;
            overflow-wrap: anywhere;
        }

        .description {
            margin: 0 0 16px;
            font-size: 14px;
            line-height: 1.9;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
        }

        .note {
            color: #62788a;
            font-size: 12px;
            line-height: 1.8;
        }

        @media (max-width: 600px) {
            main { padding: 24px 14px; }
            .card { padding: 20px; }
            .row { grid-template-columns: 1fr; gap: 0; }
            .event { padding: 18px; }
        }
    </style>
</head>
<body>
    <header>
        <div class="brand">MHMS</div>

        <a href="<?= timelineEscape($dashboards[$role]) ?>">
            Dashboard
        </a>
    </header>

    <main>
        <h1>Medical timeline</h1>

        <p class="muted">
            Review recorded information together and filter by record type.
        </p>

        <section class="card">
            <form method="GET" action="timeline.php">
                <div class="row">
                    <div>
                        <?php if ($role !== 'patient'): ?>
                            <label for="patient">Accessible patient</label>

                            <select id="patient" name="id" required>
                                <option value="">Select a patient</option>

                                <?php foreach ($options as $option): ?>
                                    <option
                                        value="<?= (int) $option['patient_id'] ?>"
                                        <?= (int) $patientId ===
                                            (int) $option['patient_id']
                                            ? 'selected' : '' ?>
                                    >
                                        <?= timelineEscape(
                                            $option['full_name']
                                            . ' — ' . $option['mrn']
                                        ) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php else: ?>
                            <label>Your patient record</label>
                            <p class="muted">
                                <?= timelineEscape(
                                    $patient['full_name'] ?? 'Unavailable'
                                ) ?>
                            </p>
                        <?php endif; ?>
                    </div>

                    <div>
                        <label for="type">Record type</label>

                        <select id="type" name="type">
                            <?php foreach ($types as $key => $label): ?>
                                <option
                                    value="<?= timelineEscape($key) ?>"
                                    <?= $type === $key ? 'selected' : '' ?>
                                >
                                    <?= timelineEscape($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <button type="submit">Show timeline</button>
            </form>

            <?php if ($role !== 'patient'): ?>
                <p class="note">
                    Selector shows up to 500 accessible patient profiles.
                </p>
            <?php endif; ?>
        </section>

        <?php if ($error !== ''): ?>
            <div class="error" role="alert">
                <?= timelineEscape($error) ?>
            </div>

        <?php elseif ($patient): ?>
            <section class="card">
                <h2><?= timelineEscape($patient['full_name']) ?></h2>

                <p class="muted">
                    Medical record number:
                    <?= timelineEscape($patient['mrn']) ?>
                </p>

                <a
                    class="button"
                    href="medical_summary.php<?= $role === 'patient'
                        ? ''
                        : '?id=' . (int) $patientId ?>"
                >
                    Open medical summary
                </a>
            </section>

            <div class="notice">
                Dates have different meanings:
                history entries use their recorded relevant date,
                consultations use the visit date, and reports use
                the report date. Allergy and prescription cards show
                when the entry was recorded—not when an allergy began
                or medicine was taken.
                <br>
                Unknown dates appear last. No date is invented for
                an undated history entry.
            </div>

            <?php if (!$events): ?>
                <section class="card">
                    <h2>No matching entries</h2>
                    <p class="muted">
                        Choose another record type or add fictional
                        records using the appropriate module.
                    </p>
                </section>
            <?php else: ?>
                <div class="timeline">
                    <?php foreach ($events as $event): ?>
                        <article class="event">
                            <div class="event-header">
                                <span class="badge">
                                    <?= timelineEscape(
                                        $types[$event['event_type']]
                                    ) ?>
                                </span>

                                <span class="date">
                                    <?= timelineEscape($event['date_label']) ?>
                                    <br>

                                    <?php if (!$event['event_time']): ?>
                                        Not recorded
                                    <?php elseif (in_array(
                                        $event['event_type'],
                                        ['allergy', 'prescription'],
                                        true
                                    )): ?>
                                        <?= timelineEscape(
                                            $event['event_time']
                                        ) ?>
                                    <?php else: ?>
                                        <?= timelineEscape(
                                            substr($event['event_time'], 0, 10)
                                        ) ?>
                                    <?php endif; ?>
                                </span>
                            </div>

                            <h3><?= timelineEscape($event['title']) ?></h3>

                            <p class="description"><?= timelineEscape(
                                $event['description']
                            ) ?></p>

                            <?php if ($event['event_type'] === 'report'): ?>
                                <a
                                    class="button"
                                    href="<?= $role === 'patient'
                                        ? 'patient_dashboard.php'
                                        : 'report_upload.php' ?>?download=<?= (int) $event['source_id'] ?>"
                                >
                                    Download report
                                </a>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>

                <p class="note">
                    Showing up to 100 matching entries, newest known
                    dates first, with undated entries last.
                    Prescription cards represent individual medicine
                    entries, not separate prescription documents.
                    Entry-recording timestamps use the database’s
                    existing timestamp convention; no timezone
                    conversion is applied here.
                </p>
            <?php endif; ?>

        <?php else: ?>
            <section class="card">
                <h2>Select a patient</h2>
                <p class="muted">
                    Choose an accessible patient above and click Show timeline.
                </p>
            </section>
        <?php endif; ?>

        <p class="note">
            Academic demonstration · Fictional data only.
            Historical prescription entries are not a verified
            current-medication list. Appointments are not included
            in this clinical-record timeline.
        </p>
    </main>
</body>
</html>