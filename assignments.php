<?php
require_once __DIR__ . '/config/db.php';

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
    exit('Only administrators can assign patients.');
}

function assignmentEscape($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

if (empty($_SESSION['assignment_token'])) {
    $_SESSION['assignment_token'] = bin2hex(random_bytes(32));
}

$patients = [];
$doctors = [];
$assignments = [];

$error = '';
$loadError = '';
$listError = '';

$selectedPatient = '';
$selectedDoctor = '';

$success = $_SESSION['assignment_success'] ?? '';
unset($_SESSION['assignment_success']);

try {
    $patients = $pdo->query(
        'SELECT patient_id, full_name, mrn
         FROM patients
         ORDER BY full_name, patient_id
         LIMIT 500'
    )->fetchAll();

    $doctors = $pdo->query(
        "SELECT d.doctor_id, u.full_name, d.specialization
         FROM doctors d
         JOIN users u ON d.user_id = u.user_id
         WHERE u.status = 'active' AND u.role = 'doctor'
         ORDER BY u.full_name, d.doctor_id
         LIMIT 500"
    )->fetchAll();

} catch (PDOException $exception) {
    error_log($exception->getMessage());
    $loadError = 'The patient or doctor list could not be loaded.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['token'] ?? '';

    $patientInput = $_POST['patient_id'] ?? '';
    $doctorInput = $_POST['doctor_id'] ?? '';

    $selectedPatient = is_string($patientInput) ? $patientInput : '';
    $selectedDoctor = is_string($doctorInput) ? $doctorInput : '';

    $patientId = filter_var(
        $selectedPatient,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    $doctorId = filter_var(
        $selectedDoctor,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if (
        !is_string($token)
        || !hash_equals($_SESSION['assignment_token'], $token)
    ) {
        $error = 'The form expired. Refresh the page and try again.';
    } elseif (!$patientId || !$doctorId) {
        $error = 'Select both a patient and a doctor.';
    } else {
        try {
            $query = $pdo->prepare(
                'SELECT patient_id FROM patients WHERE patient_id = ?'
            );
            $query->execute([$patientId]);
            $patientExists = $query->fetchColumn();

            $query = $pdo->prepare(
                "SELECT d.doctor_id
                 FROM doctors d
                 JOIN users u ON d.user_id = u.user_id
                 WHERE d.doctor_id = ?
                   AND u.status = 'active'
                   AND u.role = 'doctor'"
            );
            $query->execute([$doctorId]);
            $doctorExists = $query->fetchColumn();

            if (!$patientExists || !$doctorExists) {
                $error = 'Select an existing patient and an active doctor.';
            } else {
                $query = $pdo->prepare(
                    'SELECT assignment_id
                     FROM patient_assignments
                     WHERE patient_id = ? AND doctor_id = ?'
                );
                $query->execute([$patientId, $doctorId]);

                if ($query->fetchColumn()) {
                    $error = 'This patient is already assigned to this doctor.';
                } else {
                    $pdo->beginTransaction();

                    $query = $pdo->prepare(
                        'INSERT INTO patient_assignments
                         (patient_id, doctor_id, assigned_by)
                         VALUES (?, ?, ?)'
                    );

                    $query->execute([
                        $patientId,
                        $doctorId,
                        $_SESSION['user_id']
                    ]);

                    logAction(
                        $pdo,
                        $_SESSION['user_id'],
                        $patientId,
                        'ASSIGN_PATIENT',
                        'Patient assigned to doctor ID ' . $doctorId
                    );

                    $pdo->commit();

                    $_SESSION['assignment_success'] =
                        'Patient assigned successfully.';

                    $_SESSION['assignment_token'] =
                        bin2hex(random_bytes(32));

                    header('Location: assignments.php');
                    exit;
                }
            }
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log($exception->getMessage());

            $error = 'The assignment could not be saved. '
                . 'Check that the patient_assignments table exists '
                . 'and that the assignment is not already listed.';
        }
    }
}

try {
    $assignments = $pdo->query(
        "SELECT a.assignment_id, a.created_at,
                p.full_name AS patient_name, p.mrn,
                du.full_name AS doctor_name,
                au.full_name AS assigned_by_name
         FROM patient_assignments a
         JOIN patients p ON a.patient_id = p.patient_id
         JOIN doctors d ON a.doctor_id = d.doctor_id
         JOIN users du ON d.user_id = du.user_id
         JOIN users au ON a.assigned_by = au.user_id
         ORDER BY a.assignment_id DESC
         LIMIT 100"
    )->fetchAll();

} catch (PDOException $exception) {
    error_log($exception->getMessage());

    $listError = 'Assignments could not be loaded. '
        . 'Confirm that you completed the SQL step first.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Patient Assignments | MHMS</title>

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

        nav {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }

        nav a {
            color: white;
            text-decoration: none;
            font-size: 14px;
        }

        main {
            max-width: 1350px;
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
            grid-template-columns: 370px minmax(0, 1fr);
            gap: 24px;
            align-items: start;
        }

        .card {
            padding: 26px;
            border: 1px solid #e1eaf0;
            border-radius: 18px;
            background: white;
            min-width: 0;
        }

        label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 8px;
        }

        select {
            width: 100%;
            padding: 13px;
            margin-bottom: 22px;
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
            width: 100%;
            padding: 14px;
            border: 0;
            border-radius: 9px;
            background: #087e8b;
            color: white;
            font: inherit;
            font-weight: 600;
            cursor: pointer;
        }

        button:hover {
            background: #066772;
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

        .table-wrap {
            overflow-x: auto;
        }

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
            white-space: nowrap;
        }

        td {
            padding: 16px 10px;
            border-bottom: 1px solid #e1eaf0;
        }

        .mrn {
            display: block;
            margin-top: 6px;
            font-family: monospace;
            font-size: 11px;
            color: #62788a;
        }

        .note {
            color: #62788a;
            font-size: 12px;
            line-height: 1.8;
        }

        @media (max-width: 950px) {
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
            <a href="doctors.php">Doctors</a>
            <a href="patient_view.php">Patient profiles</a>
        </nav>
    </header>

    <main>
        <h1>Patient assignments</h1>

        <p class="subtitle">
            Select which doctor is assigned to a fictional patient.
            A patient can be assigned to more than one doctor.
        </p>

        <?php if ($success !== ''): ?>
            <div class="alert success" role="status">
                <?= assignmentEscape($success) ?>
            </div>
        <?php endif; ?>

        <div class="layout">
            <section class="card">
                <h2>Create assignment</h2>

                <?php if ($error !== ''): ?>
                    <div class="alert error" role="alert">
                        <?= assignmentEscape($error) ?>
                    </div>
                <?php endif; ?>

                <?php if ($loadError !== ''): ?>
                    <div class="alert error" role="alert">
                        <?= assignmentEscape($loadError) ?>
                    </div>

                <?php elseif (!$patients || !$doctors): ?>
                    <p class="note">
                        Create at least one patient and one active doctor
                        before assigning a patient.
                    </p>

                    <p><a href="patients.php">Manage patients</a></p>
                    <p><a href="doctors.php">Manage doctors</a></p>

                <?php else: ?>
                    <form method="POST" action="assignments.php">
                        <input
                            type="hidden"
                            name="token"
                            value="<?= assignmentEscape(
                                $_SESSION['assignment_token']
                            ) ?>"
                        >

                        <label for="patient_id">Patient *</label>

                        <select id="patient_id" name="patient_id" required>
                            <option value="">Select a patient</option>

                            <?php foreach ($patients as $patient): ?>
                                <option
                                    value="<?= (int) $patient['patient_id'] ?>"
                                    <?= $selectedPatient ===
                                        (string) $patient['patient_id']
                                        ? 'selected' : '' ?>
                                >
                                    <?= assignmentEscape(
                                        $patient['full_name']
                                        . ' — '
                                        . $patient['mrn']
                                    ) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <label for="doctor_id">Doctor *</label>

                        <select id="doctor_id" name="doctor_id" required>
                            <option value="">Select a doctor</option>

                            <?php foreach ($doctors as $doctor): ?>
                                <option
                                    value="<?= (int) $doctor['doctor_id'] ?>"
                                    <?= $selectedDoctor ===
                                        (string) $doctor['doctor_id']
                                        ? 'selected' : '' ?>
                                >
                                    <?= assignmentEscape(
                                        $doctor['full_name']
                                        . ' — '
                                        . $doctor['specialization']
                                    ) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <button type="submit">Assign patient</button>

                        <p class="note">
                            Selectors show up to 500 patients and
                            500 active doctors. Duplicate patient–doctor
                            assignments are not allowed.
                        </p>
                    </form>
                <?php endif; ?>
            </section>

            <section class="card">
                <h2>Recorded assignments</h2>

                <?php if ($listError !== ''): ?>
                    <div class="alert error" role="alert">
                        <?= assignmentEscape($listError) ?>
                    </div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Patient</th>
                                    <th>Doctor</th>
                                    <th>Assigned by</th>
                                    <th>Recorded time</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php if (!$assignments): ?>
                                    <tr>
                                        <td colspan="4">
                                            No assignments created yet.
                                        </td>
                                    </tr>
                                <?php endif; ?>

                                <?php foreach ($assignments as $assignment): ?>
                                    <tr>
                                        <td>
                                            <?= assignmentEscape(
                                                $assignment['patient_name']
                                            ) ?>

                                            <span class="mrn">
                                                <?= assignmentEscape(
                                                    $assignment['mrn']
                                                ) ?>
                                            </span>
                                        </td>

                                        <td>
                                            <?= assignmentEscape(
                                                $assignment['doctor_name']
                                            ) ?>
                                        </td>

                                        <td>
                                            <?= assignmentEscape(
                                                $assignment['assigned_by_name']
                                            ) ?>
                                        </td>

                                        <td>
                                            <?= assignmentEscape(
                                                $assignment['created_at']
                                            ) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <p class="note">
                        Showing the latest 100 assignments.
                        This module currently creates and lists assignments;
                        removal will be added separately.
                    </p>
                <?php endif; ?>
            </section>
        </div>
    </main>
</body>
</html>