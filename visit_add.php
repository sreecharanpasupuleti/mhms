<?php
require_once __DIR__ . '/config/db.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$query = $pdo->prepare(
    "SELECT u.role, u.status, u.full_name, d.doctor_id
     FROM users u
     LEFT JOIN doctors d ON u.user_id = d.user_id
     WHERE u.user_id = ?"
);

$query->execute([$_SESSION['user_id']]);
$doctor = $query->fetch();

if (!$doctor || $doctor['status'] !== 'active') {
    $_SESSION = [];
    session_destroy();

    header('Location: login.php');
    exit;
}

if ($doctor['role'] !== 'doctor' || !$doctor['doctor_id']) {
    http_response_code(403);
    exit('Sign in with a doctor account to record consultations.');
}

$doctorId = (int) $doctor['doctor_id'];

function visitEscape($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function validVisitDate($value) {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

    return $date && $date->format('Y-m-d') === $value
        ? $date
        : false;
}

if (empty($_SESSION['visit_token'])) {
    $_SESSION['visit_token'] = bin2hex(random_bytes(32));
}

$values = [
    'patient_id' => '',
    'visit_date' => date('Y-m-d'),
    'symptoms' => '',
    'diagnosis' => '',
    'treatment_plan' => '',
    'follow_up_date' => ''
];

$error = '';
$loadError = '';
$patients = [];

$success = $_SESSION['visit_success'] ?? '';
unset($_SESSION['visit_success']);

try {
    $query = $pdo->prepare(
        "SELECT p.patient_id, p.full_name, p.mrn
         FROM patient_assignments a
         JOIN patients p ON a.patient_id = p.patient_id
         WHERE a.doctor_id = ?
         ORDER BY p.full_name, p.patient_id
         LIMIT 500"
    );

    $query->execute([$doctorId]);
    $patients = $query->fetchAll();

} catch (PDOException $exception) {
    error_log($exception->getMessage());

    $loadError = 'Assigned patients could not be loaded. '
        . 'Confirm that the patient-assignment module is installed.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($values as $field => $unused) {
        $input = $_POST[$field] ?? '';
        $values[$field] = is_string($input) ? trim($input) : '';
    }

    $token = $_POST['token'] ?? '';

    $patientId = filter_var(
        $values['patient_id'],
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    $visitDate = validVisitDate($values['visit_date']);

    $followUpDate = $values['follow_up_date'] !== ''
        ? validVisitDate($values['follow_up_date'])
        : null;

    if (
        !is_string($token)
        || !hash_equals($_SESSION['visit_token'], $token)
    ) {
        $error = 'The form expired. Refresh this page and try again.';
    } elseif (!$patientId) {
        $error = 'Select an assigned patient.';
    } elseif (
        !$visitDate
        || $visitDate > new DateTimeImmutable('today')
    ) {
        $error = 'Enter a valid visit date that is not in the future.';
    } elseif (
        $values['symptoms'] === ''
        || strlen($values['symptoms']) > 5000
    ) {
        $error = 'Enter symptoms of up to 5,000 characters.';
    } elseif (
        $values['diagnosis'] === ''
        || strlen($values['diagnosis']) > 255
    ) {
        $error = 'Enter a diagnosis or assessment of up to 255 characters.';
    } elseif (
        $values['treatment_plan'] === ''
        || strlen($values['treatment_plan']) > 5000
    ) {
        $error = 'Enter a treatment plan of up to 5,000 characters.';
    } elseif (
        $values['follow_up_date'] !== ''
        && (!$followUpDate || $followUpDate < $visitDate)
    ) {
        $error = 'Follow-up must be a valid date on or after the visit date.';
    } else {
        try {
            $pdo->beginTransaction();

            // Recheck and lock the assignment during this save.
            $query = $pdo->prepare(
                'SELECT assignment_id
                 FROM patient_assignments
                 WHERE patient_id = ? AND doctor_id = ?
                 FOR UPDATE'
            );

            $query->execute([$patientId, $doctorId]);

            if (!$query->fetchColumn()) {
                $pdo->rollBack();

                $error = 'You are not assigned to this patient. '
                    . 'Ask the administrator to check the assignment.';
            } else {
                $query = $pdo->prepare(
                    'SELECT dob FROM patients WHERE patient_id = ?'
                );
                $query->execute([$patientId]);
                $patient = $query->fetch();

                if (
                    !$patient
                    || (
                        !empty($patient['dob'])
                        && $values['visit_date'] < $patient['dob']
                    )
                ) {
                    $pdo->rollBack();

                    $error = 'The visit date cannot be before '
                        . 'the patient’s date of birth.';
                } else {
                    $insert = $pdo->prepare(
                        "INSERT INTO visits
                        (
                            patient_id,
                            doctor_id,
                            visit_date,
                            symptoms,
                            diagnosis,
                            treatment_plan,
                            follow_up_date
                        )
                        VALUES (?, ?, ?, ?, ?, ?, ?)"
                    );

                    $insert->execute([
                        $patientId,
                        $doctorId,
                        $values['visit_date'],
                        $values['symptoms'],
                        $values['diagnosis'],
                        $values['treatment_plan'],
                        $values['follow_up_date'] ?: null
                    ]);

                    $visitId = (int) $pdo->lastInsertId();

                    logAction(
                        $pdo,
                        $_SESSION['user_id'],
                        $patientId,
                        'ADD_CONSULTATION',
                        'Consultation created; visit ID ' . $visitId
                    );

                    $pdo->commit();

                    $_SESSION['visit_success'] =
                        'Consultation saved successfully. '
                        . 'Visit ID: ' . $visitId;

                    $_SESSION['visit_token'] =
                        bin2hex(random_bytes(32));

                    header('Location: visit_add.php');
                    exit;
                }
            }
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log($exception->getMessage());

            $error = 'The consultation could not be saved. '
                . 'Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Add Consultation | MHMS</title>

    <style>
        * {
            box-sizing: border-box;
        }

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
            font-size: 14px;
        }

        main {
            max-width: 1100px;
            margin: auto;
            padding: 32px 24px;
        }

        h1 {
            margin: 0 0 12px;
            font-size: 30px;
        }

        h2 {
            margin: 0 0 22px;
            font-size: 21px;
        }

        .subtitle {
            color: #62788a;
            line-height: 1.8;
            margin-bottom: 26px;
        }

        .layout {
            display: grid;
            grid-template-columns: minmax(0, 1.7fr) minmax(0, 1fr);
            gap: 24px;
            align-items: start;
        }

        .card {
            padding: 28px;
            border: 1px solid #e1eaf0;
            border-radius: 18px;
            background: white;
            min-width: 0;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-size: 14px;
            font-weight: 600;
        }

        input,
        select,
        textarea {
            width: 100%;
            padding: 13px;
            margin-bottom: 20px;
            border: 1px solid #cad7e0;
            border-radius: 9px;
            background: white;
            color: #173247;
            font: inherit;
            font-size: 14px;
        }

        input:focus,
        select:focus,
        textarea:focus {
            outline: 3px solid #d9f0f2;
            border-color: #087e8b;
        }

        textarea {
            resize: vertical;
        }

        .row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        button,
        .button-link {
            display: inline-block;
            padding: 14px 20px;
            border: 0;
            border-radius: 9px;
            background: #087e8b;
            color: white;
            font: inherit;
            font-weight: 600;
            font-size: 14px;
            text-decoration: none;
            cursor: pointer;
        }

        button {
            width: 100%;
        }

        button:hover,
        .button-link:hover {
            background: #066772;
        }

        .button-link {
            margin-top: 12px;
        }

        .alert {
            padding: 16px;
            margin-bottom: 22px;
            border-radius: 10px;
            line-height: 1.7;
        }

        .success {
            background: #e5f6eb;
            color: #166534;
        }

        .error {
            background: #feecec;
            color: #991b1b;
        }

        .note,
        .help p,
        .help li {
            color: #62788a;
            line-height: 1.8;
        }

        .note {
            font-size: 12px;
        }

        .help p,
        .help li {
            font-size: 14px;
        }

        .help ul {
            padding-left: 20px;
        }

        @media (max-width: 800px) {
            .layout {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 500px) {
            main {
                padding: 24px 14px;
            }

            .card {
                padding: 20px;
            }

            .row {
                grid-template-columns: 1fr;
                gap: 0;
            }

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
        <a href="doctor_dashboard.php">Back to doctor dashboard</a>
    </header>

    <main>
        <h1>Add consultation</h1>

        <p class="subtitle">
            Signed in as <?= visitEscape($doctor['full_name']) ?>.
            Record a fictional consultation for an assigned patient.
        </p>

        <?php if ($success !== ''): ?>
            <div class="alert success" role="status">
                <?= visitEscape($success) ?>
                <br>
                <a class="button-link" href="doctor_dashboard.php">
                    Check your updated dashboard
                </a>
            </div>
        <?php endif; ?>

        <?php if ($loadError !== ''): ?>
            <div class="alert error" role="alert">
                <?= visitEscape($loadError) ?>
            </div>

        <?php elseif (!$patients): ?>
            <section class="card">
                <h2>No assigned patients</h2>
                <p class="note">
                    Ask the administrator to assign a fictional patient
                    to this doctor using the assignments page.
                </p>
            </section>

        <?php else: ?>
            <div class="layout">
                <section class="card">
                    <h2>Consultation details</h2>

                    <?php if ($error !== ''): ?>
                        <div class="alert error" role="alert">
                            <?= visitEscape($error) ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="visit_add.php">
                        <input
                            type="hidden"
                            name="token"
                            value="<?= visitEscape($_SESSION['visit_token']) ?>"
                        >

                        <label for="patient_id">Assigned patient *</label>

                        <select id="patient_id" name="patient_id" required>
                            <option value="">Select a patient</option>

                            <?php foreach ($patients as $patient): ?>
                                <option
                                    value="<?= (int) $patient['patient_id'] ?>"
                                    <?= $values['patient_id'] ===
                                        (string) $patient['patient_id']
                                        ? 'selected' : '' ?>
                                >
                                    <?= visitEscape(
                                        $patient['full_name']
                                        . ' — '
                                        . $patient['mrn']
                                    ) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <div class="row">
                            <div>
                                <label for="visit_date">Visit date *</label>

                                <input
                                    id="visit_date"
                                    name="visit_date"
                                    type="date"
                                    max="<?= date('Y-m-d') ?>"
                                    value="<?= visitEscape(
                                        $values['visit_date']
                                    ) ?>"
                                    required
                                >
                            </div>

                            <div>
                                <label for="follow_up_date">
                                    Follow-up — optional
                                </label>

                                <input
                                    id="follow_up_date"
                                    name="follow_up_date"
                                    type="date"
                                    value="<?= visitEscape(
                                        $values['follow_up_date']
                                    ) ?>"
                                >
                            </div>
                        </div>

                        <label for="symptoms">Symptoms *</label>

                        <textarea
                            id="symptoms"
                            name="symptoms"
                            rows="3"
                            maxlength="5000"
                            required
                        ><?= visitEscape($values['symptoms']) ?></textarea>

                        <label for="diagnosis">
                            Recorded diagnosis or assessment *
                        </label>

                        <input
                            id="diagnosis"
                            name="diagnosis"
                            maxlength="255"
                            value="<?= visitEscape($values['diagnosis']) ?>"
                            required
                        >

                        <label for="treatment_plan">Treatment plan *</label>

                        <textarea
                            id="treatment_plan"
                            name="treatment_plan"
                            rows="4"
                            maxlength="5000"
                            required
                        ><?= visitEscape(
                            $values['treatment_plan']
                        ) ?></textarea>

                        <button type="submit">Save consultation</button>

                        <p class="note">
                            * Required fields. Use fictional information.
                            The selector shows up to 500 assigned patients.
                        </p>
                    </form>
                </section>

                <aside class="card help">
                    <h2>Before saving</h2>

                    <ul>
                        <li>Check the selected patient and visit date.</li>
                        <li>Enter fictional test information only.</li>
                        <li>
                            Leave follow-up blank if it is not required
                            for your test record.
                        </li>
                        <li>
                            A saved consultation appears on your dashboard
                            and the administrator’s patient-profile page.
                        </li>
                    </ul>

                    <p>
                        This form stores manually entered information.
                        It does not diagnose conditions or recommend
                        treatment.
                    </p>

                    <p>
                        Prescription creation will be added separately.
                    </p>
                </aside>
            </div>
        <?php endif; ?>
    </main>
</body>
</html>