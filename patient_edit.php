<?php
require_once __DIR__ . '/config/db.php';

header('Cache-Control: no-store');

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

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

if ($account['role'] !== 'admin') {
    http_response_code(403);
    exit('Only administrators can edit patient details.');
}

function editEscape($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

if (empty($_SESSION['patient_edit_token'])) {
    $_SESSION['patient_edit_token'] = bin2hex(random_bytes(32));
}

$fields = [
    'full_name',
    'dob',
    'gender',
    'blood_group',
    'phone',
    'email',
    'address',
    'emergency_contact'
];

$genders = ['Male', 'Female', 'Other'];
$bloodGroups = ['', 'A+', 'A-', 'B+', 'B-', 'O+', 'O-', 'AB+', 'AB-'];

$patientInput = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? ($_POST['patient_id'] ?? '')
    : ($_GET['id'] ?? '');

$patientId = is_string($patientInput)
    ? filter_var(
        $patientInput,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    )
    : false;

$options = [];
$patient = null;
$values = array_fill_keys($fields, '');
$error = '';
$loadError = '';

$success = $_SESSION['patient_edit_success'] ?? '';
unset($_SESSION['patient_edit_success']);

try {
    $options = $pdo->query(
        'SELECT patient_id, full_name, mrn
         FROM patients
         ORDER BY full_name, patient_id
         LIMIT 500'
    )->fetchAll();

    if ($patientId) {
        $query = $pdo->prepare(
            'SELECT * FROM patients WHERE patient_id = ?'
        );
        $query->execute([$patientId]);
        $patient = $query->fetch();

        if (!$patient) {
            $error = 'The selected patient was not found.';
        } else {
            foreach ($fields as $field) {
                $values[$field] = (string) ($patient[$field] ?? '');
            }
        }

    } elseif ($patientInput !== '') {
        $error = 'Choose a valid patient.';
    }

} catch (PDOException $exception) {
    error_log($exception->getMessage());
    $loadError = 'Patient details could not be loaded.';
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && $patient
    && $loadError === ''
) {
    foreach ($fields as $field) {
        $input = $_POST[$field] ?? '';
        $values[$field] = is_string($input) ? trim($input) : '';
    }

    $reasonInput = $_POST['change_reason'] ?? '';
    $reason = is_string($reasonInput) ? trim($reasonInput) : '';

    $token = $_POST['token'] ?? '';

    $birthDate = DateTimeImmutable::createFromFormat(
        '!Y-m-d',
        $values['dob']
    );

    if (
        !is_string($token)
        || !hash_equals($_SESSION['patient_edit_token'], $token)
    ) {
        $error = 'The form expired. Refresh the page and try again.';
    } elseif (
        $values['full_name'] === ''
        || strlen($values['full_name']) > 100
    ) {
        $error = 'Enter a patient name of up to 100 characters.';
    } elseif (
        !$birthDate
        || $birthDate->format('Y-m-d') !== $values['dob']
        || $birthDate > new DateTimeImmutable('today')
    ) {
        $error = 'Enter a valid date of birth that is not in the future.';
    } elseif (!in_array($values['gender'], $genders, true)) {
        $error = 'Select a valid gender.';
    } elseif (!in_array($values['blood_group'], $bloodGroups, true)) {
        $error = 'Select a valid blood group.';
    } elseif (!preg_match('/^[0-9+() .-]{7,20}$/', $values['phone'])) {
        $error = 'Enter a phone number containing 7–20 permitted characters.';
    } elseif (
        $values['email'] !== ''
        && (
            strlen($values['email']) > 120
            || !filter_var($values['email'], FILTER_VALIDATE_EMAIL)
        )
    ) {
        $error = 'Enter a valid contact email or leave it blank.';
    } elseif (strlen($values['address']) > 2000) {
        $error = 'Address must not exceed 2,000 characters.';
    } elseif (strlen($values['emergency_contact']) > 100) {
        $error = 'Emergency contact must not exceed 100 characters.';
    } elseif ($reason === '' || strlen($reason) > 200) {
        $error = 'Enter a short reason for the update, up to 200 characters.';
    } else {
        try {
            $pdo->beginTransaction();

            $query = $pdo->prepare(
                'SELECT * FROM patients WHERE patient_id = ? FOR UPDATE'
            );
            $query->execute([$patientId]);
            $currentPatient = $query->fetch();

            if (!$currentPatient) {
                throw new RuntimeException(
                    'The patient record is no longer available.'
                );
            }

            $query = $pdo->prepare(
                'SELECT MIN(visit_date) FROM visits WHERE patient_id = ?'
            );
            $query->execute([$patientId]);
            $earliestVisit = $query->fetchColumn();

            if (
                $earliestVisit
                && $values['dob'] > $earliestVisit
            ) {
                throw new RuntimeException(
                    'Date of birth cannot be after an existing consultation date.'
                );
            }

            $changedFields = [];

            foreach ($fields as $field) {
                if (
                    (string) ($currentPatient[$field] ?? '')
                    !== $values[$field]
                ) {
                    $changedFields[] = $field;
                }
            }

            if (!$changedFields) {
                $pdo->rollBack();

                $error = 'No details changed. Update a field before saving.';

            } else {
                $query = $pdo->prepare(
                    'UPDATE patients
                     SET full_name = ?, dob = ?, gender = ?,
                         blood_group = ?, phone = ?, email = ?,
                         address = ?, emergency_contact = ?
                     WHERE patient_id = ?'
                );

                $query->execute([
                    $values['full_name'],
                    $values['dob'],
                    $values['gender'],
                    $values['blood_group'] ?: null,
                    $values['phone'],
                    $values['email'] ?: null,
                    $values['address'] ?: null,
                    $values['emergency_contact'] ?: null,
                    $patientId
                ]);

                // Keep a linked patient account's display name consistent.
                // The account's login email is deliberately not changed.
                if (
                    !empty($currentPatient['user_id'])
                    && in_array('full_name', $changedFields, true)
                ) {
                    $query = $pdo->prepare(
                        "UPDATE users SET full_name = ?
                         WHERE user_id = ? AND role = 'patient'"
                    );

                    $query->execute([
                        $values['full_name'],
                        $currentPatient['user_id']
                    ]);
                }

                logAction(
                    $pdo,
                    $_SESSION['user_id'],
                    $patientId,
                    'EDIT_PATIENT',
                    'Changed fields: ' . implode(', ', $changedFields)
                    . '. Reason: ' . $reason
                );

                $pdo->commit();

                $_SESSION['patient_edit_success'] =
                    'Patient details updated successfully.';

                $_SESSION['patient_edit_token'] =
                    bin2hex(random_bytes(32));

                header('Location: patient_edit.php?id=' . $patientId);
                exit;
            }

        } catch (RuntimeException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $error = $exception->getMessage();

        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log($exception->getMessage());
            $error = 'Patient details could not be updated. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Edit Patient | MHMS</title>

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
            max-width: 950px;
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
            padding: 28px;
            margin: 22px 0;
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

        input, select, textarea {
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

        input:focus, select:focus, textarea:focus {
            outline: 3px solid #d9f0f2;
            border-color: #087e8b;
        }

        textarea { resize: vertical; }

        .row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
        }

        button, .button {
            display: inline-block;
            padding: 13px 18px;
            border: 0;
            border-radius: 9px;
            background: #087e8b;
            color: white;
            font: inherit;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
        }

        button:hover, .button:hover {
            background: #066772;
        }

        .actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .secondary {
            background: #e6f2f4;
            color: #086572;
        }

        .secondary:hover {
            background: #d1e9ed;
        }

        .alert {
            padding: 16px;
            margin: 20px 0;
            border-radius: 10px;
            line-height: 1.8;
        }

        .success {
            background: #e5f6eb;
            color: #166534;
        }

        .error {
            background: #feecec;
            color: #991b1b;
        }

        .note {
            color: #62788a;
            font-size: 12px;
            line-height: 1.8;
        }

        .mrn {
            font-family: monospace;
            color: #62788a;
            font-size: 13px;
            overflow-wrap: anywhere;
        }

        @media (max-width: 600px) {
            main { padding: 24px 14px; }
            .card { padding: 20px; }
            .row { grid-template-columns: 1fr; gap: 0; }

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
            <a href="dashboard.php">Dashboard</a>
            <a href="patients.php">Patients</a>
            <a href="patient_view.php">Patient profiles</a>
        </nav>
    </header>

    <main>
        <h1>Edit patient details</h1>

        <p class="muted">
            Update demographic and contact information.
            Clinical records are not changed by this form.
        </p>

        <?php if ($success !== ''): ?>
            <div class="alert success" role="status">
                <?= editEscape($success) ?>
            </div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="alert error" role="alert">
                <?= editEscape($error) ?>
            </div>
        <?php endif; ?>

        <?php if ($loadError !== ''): ?>
            <div class="alert error" role="alert">
                <?= editEscape($loadError) ?>
            </div>
        <?php else: ?>
            <section class="card">
                <h2>Choose a patient</h2>

                <?php if (!$options): ?>
                    <p class="muted">
                        No patients available. Add a fictional patient first.
                    </p>
                <?php else: ?>
                    <form method="GET" action="patient_edit.php">
                        <label for="selected_patient">Patient</label>

                        <select id="selected_patient" name="id" required>
                            <option value="">Select patient</option>

                            <?php foreach ($options as $option): ?>
                                <option
                                    value="<?= (int) $option['patient_id'] ?>"
                                    <?= (int) $patientId ===
                                        (int) $option['patient_id']
                                        ? 'selected' : '' ?>
                                >
                                    <?= editEscape(
                                        $option['full_name']
                                        . ' — ' . $option['mrn']
                                    ) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <button type="submit">Load details</button>
                    </form>

                    <p class="note">Showing up to 500 patients.</p>
                <?php endif; ?>
            </section>

            <?php if ($patient): ?>
                <section class="card">
                    <h2>Edit selected patient</h2>

                    <p class="mrn">
                        Medical record number:
                        <?= editEscape($patient['mrn']) ?>
                    </p>

                    <form method="POST" action="patient_edit.php">
                        <input
                            type="hidden"
                            name="token"
                            value="<?= editEscape(
                                $_SESSION['patient_edit_token']
                            ) ?>"
                        >

                        <input
                            type="hidden"
                            name="patient_id"
                            value="<?= (int) $patientId ?>"
                        >

                        <label for="full_name">Full name *</label>
                        <input
                            id="full_name"
                            name="full_name"
                            maxlength="100"
                            value="<?= editEscape($values['full_name']) ?>"
                            required
                        >

                        <div class="row">
                            <div>
                                <label for="dob">Date of birth *</label>
                                <input
                                    id="dob"
                                    name="dob"
                                    type="date"
                                    max="<?= date('Y-m-d') ?>"
                                    value="<?= editEscape($values['dob']) ?>"
                                    required
                                >
                            </div>

                            <div>
                                <label for="gender">Gender *</label>
                                <select id="gender" name="gender" required>
                                    <option value="">Select</option>

                                    <?php foreach ($genders as $gender): ?>
                                        <option
                                            value="<?= editEscape($gender) ?>"
                                            <?= $values['gender'] === $gender
                                                ? 'selected' : '' ?>
                                        >
                                            <?= editEscape($gender) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="row">
                            <div>
                                <label for="blood_group">Blood group</label>

                                <select id="blood_group" name="blood_group">
                                    <?php foreach ($bloodGroups as $group): ?>
                                        <option
                                            value="<?= editEscape($group) ?>"
                                            <?= $values['blood_group'] === $group
                                                ? 'selected' : '' ?>
                                        >
                                            <?= $group === ''
                                                ? 'Unknown'
                                                : editEscape($group) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div>
                                <label for="phone">Phone *</label>
                                <input
                                    id="phone"
                                    name="phone"
                                    type="tel"
                                    minlength="7"
                                    maxlength="20"
                                    value="<?= editEscape($values['phone']) ?>"
                                    required
                                >
                            </div>
                        </div>

                        <label for="email">Contact email — optional</label>
                        <input
                            id="email"
                            name="email"
                            type="email"
                            maxlength="120"
                            value="<?= editEscape($values['email']) ?>"
                        >

                        <p class="note">
                            Contact email is not the account login email.
                            This form does not change login credentials.
                        </p>

                        <label for="emergency_contact">
                            Emergency contact — optional
                        </label>
                        <input
                            id="emergency_contact"
                            name="emergency_contact"
                            maxlength="100"
                            value="<?= editEscape(
                                $values['emergency_contact']
                            ) ?>"
                        >

                        <label for="address">Address — optional</label>
                        <textarea
                            id="address"
                            name="address"
                            rows="3"
                            maxlength="2000"
                        ><?= editEscape($values['address']) ?></textarea>

                        <label for="change_reason">Reason for update *</label>
                        <input
                            id="change_reason"
                            name="change_reason"
                            maxlength="200"
                            placeholder="Example: Correcting demo contact details"
                            required
                        >

                        <p class="note">
                            The reason is recorded in the audit log.
                            Do not include unnecessary medical information.
                        </p>

                        <div class="actions">
                            <button type="submit">Save changes</button>

                            <a
                                class="button secondary"
                                href="patient_view.php?id=<?= (int) $patientId ?>"
                            >
                                View patient profile
                            </a>
                        </div>

                        <p class="note">
                            * Required fields. The medical record number
                            cannot be changed here. This version records
                            changed field names and your reason, but does
                            not maintain a full before-and-after revision history.
                        </p>
                    </form>
                </section>
            <?php endif; ?>
        <?php endif; ?>
    </main>
</body>
</html>