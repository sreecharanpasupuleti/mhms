<?php
require_once __DIR__ . '/config/db.php';

date_default_timezone_set('Asia/Kolkata');
header('Cache-Control: no-store');

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

function appointmentEscape($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function appointmentInputDate($value) {
    $date = DateTimeImmutable::createFromFormat(
        '!Y-m-d\TH:i',
        $value
    );

    return $date && $date->format('Y-m-d\TH:i') === $value
        ? $date
        : false;
}

$query = $pdo->prepare(
    "SELECT u.role, u.status, d.doctor_id
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

$isAdmin = $account['role'] === 'admin';
$doctorId = (int) ($account['doctor_id'] ?? 0);

if (
    !$isAdmin
    && ($account['role'] !== 'doctor' || !$doctorId)
) {
    http_response_code(403);
    exit('An administrator or doctor account is required.');
}

$dashboard = $isAdmin
    ? 'dashboard.php'
    : 'doctor_dashboard.php';

if (empty($_SESSION['appointment_token'])) {
    $_SESSION['appointment_token'] = bin2hex(random_bytes(32));
}

$error = '';
$loadError = '';
$assignments = [];
$appointments = [];

$values = [
    'assignment_id' => '',
    'starts_at' => '',
    'ends_at' => '',
    'reason' => ''
];

$success = $_SESSION['appointment_success'] ?? '';
unset($_SESSION['appointment_success']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['token'] ?? '';
    $action = $_POST['action'] ?? '';

    if (
        !is_string($token)
        || !hash_equals($_SESSION['appointment_token'], $token)
    ) {
        $error = 'The form expired. Refresh the page and try again.';

    } elseif (!$isAdmin) {
        $error = 'Only administrators can change appointments.';

    } elseif ($action === 'book') {
        foreach ($values as $field => $unused) {
            $input = $_POST[$field] ?? '';
            $values[$field] = is_string($input) ? trim($input) : '';
        }

        $assignmentId = filter_var(
            $values['assignment_id'],
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        $start = appointmentInputDate($values['starts_at']);
        $end = appointmentInputDate($values['ends_at']);

        if (!$assignmentId) {
            $error = 'Select an assigned patient–doctor pair.';
        } elseif (!$start || !$end) {
            $error = 'Enter valid start and end times.';
        } elseif ($start <= new DateTimeImmutable()) {
            $error = 'Choose a start time in the future.';
        } elseif ($end <= $start) {
            $error = 'End time must be after start time.';
        } elseif (
            ($end->getTimestamp() - $start->getTimestamp()) > 4 * 3600
        ) {
            $error = 'For this prototype, appointment duration '
                . 'must not exceed four hours.';
        } elseif (
            $values['reason'] === ''
            || strlen($values['reason']) > 255
        ) {
            $error = 'Enter a reason of up to 255 characters.';
        } else {
            try {
                $pdo->beginTransaction();

                $query = $pdo->prepare(
                    'SELECT patient_id, doctor_id
                     FROM patient_assignments
                     WHERE assignment_id = ?'
                );
                $query->execute([$assignmentId]);
                $assignment = $query->fetch();

                if (!$assignment) {
                    throw new RuntimeException(
                        'That assignment is no longer available.'
                    );
                }

                // Serialize bookings for the selected doctor.
                $query = $pdo->prepare(
                    'SELECT doctor_id FROM doctors
                     WHERE doctor_id = ? FOR UPDATE'
                );
                $query->execute([$assignment['doctor_id']]);

                if (!$query->fetchColumn()) {
                    throw new RuntimeException('Doctor profile not found.');
                }

                // Serialize bookings for the selected patient too.
                $query = $pdo->prepare(
                    'SELECT patient_id FROM patients
                     WHERE patient_id = ? FOR UPDATE'
                );
                $query->execute([$assignment['patient_id']]);

                if (!$query->fetchColumn()) {
                    throw new RuntimeException('Patient profile not found.');
                }

                // Recheck assignment and account state before booking.
                $query = $pdo->prepare(
                    "SELECT a.assignment_id
                     FROM patient_assignments a
                     JOIN doctors d ON a.doctor_id = d.doctor_id
                     JOIN users u ON d.user_id = u.user_id
                     WHERE a.assignment_id = ?
                       AND a.patient_id = ?
                       AND a.doctor_id = ?
                       AND u.status = 'active'
                       AND u.role = 'doctor'
                     FOR UPDATE"
                );

                $query->execute([
                    $assignmentId,
                    $assignment['patient_id'],
                    $assignment['doctor_id']
                ]);

                if (!$query->fetchColumn()) {
                    throw new RuntimeException(
                        'The assignment or active doctor account '
                        . 'is no longer available.'
                    );
                }

                $startSql = $start->format('Y-m-d H:i:s');
                $endSql = $end->format('Y-m-d H:i:s');

                // Adjacent appointments are allowed.
                // Example: 10:00–10:30 and 10:30–11:00 do not overlap.
                $query = $pdo->prepare(
                    "SELECT appointment_id
                     FROM appointments
                     WHERE status <> 'Cancelled'
                       AND (doctor_id = ? OR patient_id = ?)
                       AND starts_at < ?
                       AND ends_at > ?
                     LIMIT 1
                     FOR UPDATE"
                );

                $query->execute([
                    $assignment['doctor_id'],
                    $assignment['patient_id'],
                    $endSql,
                    $startSql
                ]);

                if ($query->fetchColumn()) {
                    throw new RuntimeException(
                        'This time overlaps an existing appointment '
                        . 'for the doctor or patient. Choose another slot.'
                    );
                }

                $query = $pdo->prepare(
                    "INSERT INTO appointments
                     (patient_id, doctor_id, starts_at, ends_at,
                      reason, status, created_by)
                     VALUES (?, ?, ?, ?, ?, 'Scheduled', ?)"
                );

                $query->execute([
                    $assignment['patient_id'],
                    $assignment['doctor_id'],
                    $startSql,
                    $endSql,
                    $values['reason'],
                    $_SESSION['user_id']
                ]);

                $appointmentId = (int) $pdo->lastInsertId();

                logAction(
                    $pdo,
                    $_SESSION['user_id'],
                    $assignment['patient_id'],
                    'BOOK_APPOINTMENT',
                    'Appointment created; ID ' . $appointmentId
                );

                $pdo->commit();

                $_SESSION['appointment_success'] =
                    'Appointment booked successfully.';

                $_SESSION['appointment_token'] =
                    bin2hex(random_bytes(32));

                header('Location: appointments.php');
                exit;

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

                $error = 'The appointment could not be booked. '
                    . 'Refresh and try again.';
            }
        }

    } elseif (in_array($action, ['cancel', 'complete'], true)) {
        $idInput = $_POST['appointment_id'] ?? '';

        $appointmentId = is_string($idInput)
            ? filter_var(
                $idInput,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            )
            : false;

        if (!$appointmentId) {
            $error = 'Invalid appointment reference.';
        } else {
            try {
                $pdo->beginTransaction();

                $query = $pdo->prepare(
                    'SELECT patient_id, starts_at, status
                     FROM appointments
                     WHERE appointment_id = ?
                     FOR UPDATE'
                );
                $query->execute([$appointmentId]);
                $appointment = $query->fetch();

                if (!$appointment) {
                    throw new RuntimeException('Appointment not found.');
                }

                if ($appointment['status'] !== 'Scheduled') {
                    throw new RuntimeException(
                        'Only scheduled appointments can be changed.'
                    );
                }

                if (
                    $action === 'complete'
                    && new DateTimeImmutable($appointment['starts_at'])
                        > new DateTimeImmutable()
                ) {
                    throw new RuntimeException(
                        'A future appointment cannot be marked completed.'
                    );
                }

                $newStatus = $action === 'cancel'
                    ? 'Cancelled'
                    : 'Completed';

                $query = $pdo->prepare(
                    'UPDATE appointments SET status = ?
                     WHERE appointment_id = ?'
                );
                $query->execute([$newStatus, $appointmentId]);

                logAction(
                    $pdo,
                    $_SESSION['user_id'],
                    $appointment['patient_id'],
                    'UPDATE_APPOINTMENT',
                    'Appointment ID ' . $appointmentId
                    . ' marked ' . $newStatus
                );

                $pdo->commit();

                $_SESSION['appointment_success'] =
                    'Appointment marked ' . strtolower($newStatus) . '.';

                $_SESSION['appointment_token'] =
                    bin2hex(random_bytes(32));

                header('Location: appointments.php');
                exit;

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
                $error = 'The appointment status could not be changed.';
            }
        }

    } else {
        $error = 'Invalid appointment action.';
    }
}

try {
    if ($isAdmin) {
        $assignments = $pdo->query(
            "SELECT a.assignment_id,
                    p.full_name AS patient_name,
                    u.full_name AS doctor_name
             FROM patient_assignments a
             JOIN patients p ON a.patient_id = p.patient_id
             JOIN doctors d ON a.doctor_id = d.doctor_id
             JOIN users u ON d.user_id = u.user_id
             WHERE u.status = 'active' AND u.role = 'doctor'
             ORDER BY p.full_name, u.full_name
             LIMIT 500"
        )->fetchAll();

        $appointments = $pdo->query(
            "SELECT a.*, p.full_name AS patient_name,
                    u.full_name AS doctor_name
             FROM appointments a
             JOIN patients p ON a.patient_id = p.patient_id
             JOIN doctors d ON a.doctor_id = d.doctor_id
             JOIN users u ON d.user_id = u.user_id
             ORDER BY a.starts_at DESC, a.appointment_id DESC
             LIMIT 100"
        )->fetchAll();

    } else {
        $query = $pdo->prepare(
            "SELECT a.*, p.full_name AS patient_name,
                    u.full_name AS doctor_name
             FROM appointments a
             JOIN patients p ON a.patient_id = p.patient_id
             JOIN doctors d ON a.doctor_id = d.doctor_id
             JOIN users u ON d.user_id = u.user_id
             JOIN patient_assignments pa
                 ON pa.patient_id = a.patient_id
                 AND pa.doctor_id = a.doctor_id
             WHERE a.doctor_id = ?
             ORDER BY a.starts_at DESC, a.appointment_id DESC
             LIMIT 100"
        );
        $query->execute([$doctorId]);
        $appointments = $query->fetchAll();
    }

} catch (PDOException $exception) {
    error_log($exception->getMessage());

    $loadError = 'Appointment data could not be loaded. '
        . 'Check that the appointments table was created.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Appointments | MHMS</title>

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

        header a { color: white; text-decoration: none; }

        main {
            max-width: 1400px;
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
            padding: 26px;
            margin: 24px 0;
            background: white;
            border: 1px solid #e1eaf0;
            border-radius: 18px;
        }

        label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 8px;
        }

        input, select {
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

        input:focus, select:focus {
            outline: 3px solid #d9f0f2;
            border-color: #087e8b;
        }

        .row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
        }

        button {
            padding: 12px 16px;
            border: 0;
            border-radius: 8px;
            background: #087e8b;
            color: white;
            font: inherit;
            font-size: 13px;
            cursor: pointer;
        }

        button:hover { background: #066772; }

        .cancel {
            background: #fff0f0;
            color: #a12626;
        }

        .cancel:hover { background: #fbdcdc; }

        .alert {
            padding: 16px;
            margin: 20px 0;
            border-radius: 10px;
            line-height: 1.8;
        }

        .success { background: #e5f6eb; color: #166534; }
        .error { background: #feecec; color: #991b1b; }

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
            background: #e6f3f4;
            color: #08727d;
        }

        .actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
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
        }
    </style>
</head>
<body>
    <header>
        <div class="brand">MHMS</div>
        <a href="<?= appointmentEscape($dashboard) ?>">Dashboard</a>
    </header>

    <main>
        <h1>Appointments</h1>

        <p class="muted">
            All appointment times on this page use India Standard Time
            (Asia/Kolkata).
        </p>

        <?php if ($success !== ''): ?>
            <div class="alert success" role="status">
                <?= appointmentEscape($success) ?>
            </div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="alert error" role="alert">
                <?= appointmentEscape($error) ?>
            </div>
        <?php endif; ?>

        <?php if ($loadError !== ''): ?>
            <div class="alert error" role="alert">
                <?= appointmentEscape($loadError) ?>
            </div>
        <?php endif; ?>

        <?php if ($isAdmin && $loadError === ''): ?>
            <section class="card">
                <h2>Book an appointment</h2>

                <?php if (!$assignments): ?>
                    <p class="muted">
                        Create a patient–doctor assignment first at
                        <a href="assignments.php">Patient assignments</a>.
                    </p>
                <?php else: ?>
                    <form method="POST" action="appointments.php">
                        <input type="hidden" name="action" value="book">

                        <input
                            type="hidden"
                            name="token"
                            value="<?= appointmentEscape(
                                $_SESSION['appointment_token']
                            ) ?>"
                        >

                        <label for="assignment_id">
                            Assigned patient and doctor *
                        </label>

                        <select
                            id="assignment_id"
                            name="assignment_id"
                            required
                        >
                            <option value="">Select a pair</option>

                            <?php foreach ($assignments as $assignment): ?>
                                <option
                                    value="<?= (int) $assignment['assignment_id'] ?>"
                                    <?= $values['assignment_id'] ===
                                        (string) $assignment['assignment_id']
                                        ? 'selected' : '' ?>
                                >
                                    <?= appointmentEscape(
                                        $assignment['patient_name']
                                        . ' — '
                                        . $assignment['doctor_name']
                                        . ' — Assignment #'
                                        . $assignment['assignment_id']
                                    ) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <div class="row">
                            <div>
                                <label for="starts_at">Start time — IST *</label>
                                <input
                                    id="starts_at"
                                    name="starts_at"
                                    type="datetime-local"
                                    value="<?= appointmentEscape(
                                        $values['starts_at']
                                    ) ?>"
                                    required
                                >
                            </div>

                            <div>
                                <label for="ends_at">End time — IST *</label>
                                <input
                                    id="ends_at"
                                    name="ends_at"
                                    type="datetime-local"
                                    value="<?= appointmentEscape(
                                        $values['ends_at']
                                    ) ?>"
                                    required
                                >
                            </div>
                        </div>

                        <label for="reason">Appointment reason *</label>
                        <input
                            id="reason"
                            name="reason"
                            maxlength="255"
                            value="<?= appointmentEscape($values['reason']) ?>"
                            placeholder="Fictional appointment for demonstration"
                            required
                        >

                        <button type="submit">Book appointment</button>

                        <p class="note">
                            Up to 500 active patient–doctor assignments.
                            Maximum duration: four hours.
                            Adjacent slots are allowed; overlapping slots
                            for the doctor or patient are rejected.
                        </p>
                    </form>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if ($loadError === ''): ?>
            <section class="card">
                <h2>
                    <?= $isAdmin ? 'Recorded appointments' : 'Your appointments' ?>
                </h2>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Patient</th>
                                <th>Doctor</th>
                                <th>Start — IST</th>
                                <th>End — IST</th>
                                <th>Reason</th>
                                <th>Status</th>
                                <?php if ($isAdmin): ?>
                                    <th>Actions</th>
                                <?php endif; ?>
                            </tr>
                        </thead>

                        <tbody>
                            <?php if (!$appointments): ?>
                                <tr>
                                    <td colspan="<?= $isAdmin ? 7 : 6 ?>">
                                        No accessible appointments recorded.
                                    </td>
                                </tr>
                            <?php endif; ?>

                            <?php foreach ($appointments as $appointment): ?>
                                <tr>
                                    <td><?= appointmentEscape(
                                        $appointment['patient_name']
                                    ) ?></td>

                                    <td><?= appointmentEscape(
                                        $appointment['doctor_name']
                                    ) ?></td>

                                    <td><?= appointmentEscape(
                                        $appointment['starts_at']
                                    ) ?></td>

                                    <td><?= appointmentEscape(
                                        $appointment['ends_at']
                                    ) ?></td>

                                    <td><?= appointmentEscape(
                                        $appointment['reason']
                                    ) ?></td>

                                    <td>
                                        <span class="badge">
                                            <?= appointmentEscape(
                                                $appointment['status']
                                            ) ?>
                                        </span>
                                    </td>

                                    <?php if ($isAdmin): ?>
                                        <td>
                                            <?php if (
                                                $appointment['status']
                                                === 'Scheduled'
                                            ): ?>
                                                <form
                                                    method="POST"
                                                    action="appointments.php"
                                                    class="actions"
                                                >
                                                    <input
                                                        type="hidden"
                                                        name="token"
                                                        value="<?= appointmentEscape(
                                                            $_SESSION['appointment_token']
                                                        ) ?>"
                                                    >

                                                    <input
                                                        type="hidden"
                                                        name="appointment_id"
                                                        value="<?= (int) $appointment['appointment_id'] ?>"
                                                    >

                                                    <button
                                                        type="submit"
                                                        name="action"
                                                        value="cancel"
                                                        class="cancel"
                                                        onclick="return confirm('Cancel this appointment?')"
                                                    >
                                                        Cancel
                                                    </button>

                                                    <?php if (
                                                        new DateTimeImmutable(
                                                            $appointment['starts_at']
                                                        ) <= new DateTimeImmutable()
                                                    ): ?>
                                                        <button
                                                            type="submit"
                                                            name="action"
                                                            value="complete"
                                                            onclick="return confirm('Mark this appointment completed?')"
                                                        >
                                                            Complete
                                                        </button>
                                                    <?php endif; ?>
                                                </form>
                                            <?php else: ?>
                                                —
                                            <?php endif; ?>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <p class="note">
                    Latest 100 appointments by start time, newest first.
                    Cancelled appointments are retained for reference.
                    Completing an appointment does not automatically
                    create a consultation record.
                </p>
            </section>
        <?php endif; ?>

        <p class="note">
            Academic demonstration only. Doctor working hours,
            holidays, reminders, and in-place rescheduling are not
            implemented in this version.
        </p>
    </main>
</body>
</html>