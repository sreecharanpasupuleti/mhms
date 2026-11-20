<?php
require_once __DIR__ . '/config/db.php';

date_default_timezone_set('Asia/Kolkata');
header('Cache-Control: no-store');

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

function rescheduleEscape($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function rescheduleDate($value) {
    $date = DateTimeImmutable::createFromFormat(
        '!Y-m-d\TH:i',
        $value
    );

    return $date && $date->format('Y-m-d\TH:i') === $value
        ? $date
        : false;
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
    exit('Your account could not be checked.');
}

if (!$account || $account['status'] !== 'active') {
    $_SESSION = [];
    session_destroy();

    header('Location: login.php');
    exit;
}

if ($account['role'] !== 'admin') {
    http_response_code(403);
    exit('Only administrators can reschedule appointments.');
}

if (empty($_SESSION['reschedule_token'])) {
    $_SESSION['reschedule_token'] = bin2hex(random_bytes(32));
}

$idInput = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? ($_POST['appointment_id'] ?? '')
    : ($_GET['id'] ?? '');

$appointmentId = is_string($idInput)
    ? filter_var(
        $idInput,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    )
    : false;

$options = [];
$appointment = null;
$error = '';
$loadError = '';

$values = [
    'starts_at' => '',
    'ends_at' => '',
    'change_reason' => ''
];

$success = $_SESSION['reschedule_success'] ?? '';
unset($_SESSION['reschedule_success']);

try {
    $options = $pdo->query(
        "SELECT a.appointment_id, a.starts_at,
                p.full_name AS patient_name,
                u.full_name AS doctor_name
         FROM appointments a
         JOIN patients p ON a.patient_id = p.patient_id
         JOIN doctors d ON a.doctor_id = d.doctor_id
         JOIN users u ON d.user_id = u.user_id
         WHERE a.status = 'Scheduled'
         ORDER BY a.starts_at DESC, a.appointment_id DESC
         LIMIT 200"
    )->fetchAll();

    if ($appointmentId) {
        $query = $pdo->prepare(
            "SELECT a.*, p.full_name AS patient_name,
                    u.full_name AS doctor_name
             FROM appointments a
             JOIN patients p ON a.patient_id = p.patient_id
             JOIN doctors d ON a.doctor_id = d.doctor_id
             JOIN users u ON d.user_id = u.user_id
             WHERE a.appointment_id = ?"
        );

        $query->execute([$appointmentId]);
        $appointment = $query->fetch();

        if (!$appointment) {
            $error = 'Appointment not found.';
        } elseif ($appointment['status'] !== 'Scheduled') {
            $error = 'Only Scheduled appointments can be rescheduled.';
        } else {
            $values['starts_at'] = (new DateTimeImmutable(
                $appointment['starts_at']
            ))->format('Y-m-d\TH:i');

            $values['ends_at'] = (new DateTimeImmutable(
                $appointment['ends_at']
            ))->format('Y-m-d\TH:i');
        }

    } elseif ($idInput !== '') {
        $error = 'Select a valid appointment.';
    }

} catch (PDOException $exception) {
    error_log($exception->getMessage());
    $loadError = 'Appointments could not be loaded.';
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && $appointment
    && $error === ''
    && $loadError === ''
) {
    foreach ($values as $field => $unused) {
        $input = $_POST[$field] ?? '';
        $values[$field] = is_string($input) ? trim($input) : '';
    }

    $token = $_POST['token'] ?? '';
    $start = rescheduleDate($values['starts_at']);
    $end = rescheduleDate($values['ends_at']);

    if (
        !is_string($token)
        || !hash_equals($_SESSION['reschedule_token'], $token)
    ) {
        $error = 'The form expired. Refresh and try again.';

    } elseif (!$start || !$end) {
        $error = 'Enter valid start and end times.';

    } elseif ($start <= new DateTimeImmutable()) {
        $error = 'Choose a start time in the future.';

    } elseif ($end <= $start) {
        $error = 'End time must be after start time.';

    } elseif (
        $end->getTimestamp() - $start->getTimestamp() > 4 * 3600
    ) {
        $error = 'Appointment duration must not exceed four hours.';

    } elseif (
        $values['change_reason'] === ''
        || strlen($values['change_reason']) > 200
    ) {
        $error = 'Enter a change reason of up to 200 characters.';

    } else {
        try {
            $pdo->beginTransaction();

            // Use the same doctor-then-patient locking order as booking.
            $query = $pdo->prepare(
                'SELECT doctor_id FROM doctors
                 WHERE doctor_id = ? FOR UPDATE'
            );
            $query->execute([$appointment['doctor_id']]);

            if (!$query->fetchColumn()) {
                throw new RuntimeException('Doctor profile not found.');
            }

            $query = $pdo->prepare(
                'SELECT patient_id FROM patients
                 WHERE patient_id = ? FOR UPDATE'
            );
            $query->execute([$appointment['patient_id']]);

            if (!$query->fetchColumn()) {
                throw new RuntimeException('Patient profile not found.');
            }

            $query = $pdo->prepare(
                'SELECT * FROM appointments
                 WHERE appointment_id = ? FOR UPDATE'
            );
            $query->execute([$appointmentId]);
            $current = $query->fetch();

            if (!$current || $current['status'] !== 'Scheduled') {
                throw new RuntimeException(
                    'This appointment is no longer Scheduled.'
                );
            }

            if (
                (int) $current['doctor_id']
                    !== (int) $appointment['doctor_id']
                || (int) $current['patient_id']
                    !== (int) $appointment['patient_id']
            ) {
                throw new RuntimeException(
                    'The appointment changed. Refresh before continuing.'
                );
            }

            $query = $pdo->prepare(
                "SELECT a.assignment_id
                 FROM patient_assignments a
                 JOIN doctors d ON a.doctor_id = d.doctor_id
                 JOIN users u ON d.user_id = u.user_id
                 WHERE a.patient_id = ?
                   AND a.doctor_id = ?
                   AND u.status = 'active'
                   AND u.role = 'doctor'
                 FOR UPDATE"
            );

            $query->execute([
                $current['patient_id'],
                $current['doctor_id']
            ]);

            if (!$query->fetchColumn()) {
                throw new RuntimeException(
                    'An active doctor and current patient assignment '
                    . 'are required to reschedule.'
                );
            }

            $startSql = $start->format('Y-m-d H:i:s');
            $endSql = $end->format('Y-m-d H:i:s');

            if (
                $startSql === $current['starts_at']
                && $endSql === $current['ends_at']
            ) {
                throw new RuntimeException(
                    'Choose a different time before saving.'
                );
            }

            $query = $pdo->prepare(
                "SELECT appointment_id
                 FROM appointments
                 WHERE appointment_id <> ?
                   AND status <> 'Cancelled'
                   AND (doctor_id = ? OR patient_id = ?)
                   AND starts_at < ?
                   AND ends_at > ?
                 LIMIT 1 FOR UPDATE"
            );

            $query->execute([
                $appointmentId,
                $current['doctor_id'],
                $current['patient_id'],
                $endSql,
                $startSql
            ]);

            if ($query->fetchColumn()) {
                throw new RuntimeException(
                    'The new time overlaps another appointment '
                    . 'for this doctor or patient.'
                );
            }

            $query = $pdo->prepare(
                'UPDATE appointments
                 SET starts_at = ?, ends_at = ?
                 WHERE appointment_id = ?'
            );
            $query->execute([$startSql, $endSql, $appointmentId]);

            logAction(
                $pdo,
                $_SESSION['user_id'],
                $current['patient_id'],
                'RESCHEDULE_APPOINTMENT',
                'Appointment ID ' . $appointmentId
                . '. Old slot: ' . $current['starts_at']
                . ' to ' . $current['ends_at']
                . '. New slot: ' . $startSql . ' to ' . $endSql
                . '. Times: IST. Reason: ' . $values['change_reason']
            );

            $pdo->commit();

            $_SESSION['reschedule_success'] =
                'Appointment rescheduled successfully. '
                . 'The appointment ID, patient, and doctor are unchanged.';

            $_SESSION['reschedule_token'] = bin2hex(random_bytes(32));

            header(
                'Location: appointment_reschedule.php?id=' . $appointmentId
            );
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
            $error = 'The appointment could not be rescheduled. Try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reschedule Appointment | MHMS</title>

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
            flex-wrap: wrap;
        }

        .brand {
            font-size: 22px;
            font-weight: 800;
            letter-spacing: 3px;
        }

        nav { display: flex; gap: 18px; flex-wrap: wrap; }
        nav a { color: white; text-decoration: none; font-size: 14px; }

        main {
            max-width: 950px;
            margin: auto;
            padding: 32px 24px;
        }

        h1 { margin: 0 0 12px; font-size: 30px; }
        h2 { margin: 0 0 22px; font-size: 21px; }

        .muted { color: #62788a; line-height: 1.8; }

        .card {
            padding: 28px;
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

        button, .button {
            display: inline-block;
            padding: 13px 18px;
            border: 0;
            border-radius: 9px;
            background: #087e8b;
            color: white;
            font: inherit;
            font-size: 14px;
            text-decoration: none;
            cursor: pointer;
        }

        button:hover, .button:hover { background: #066772; }

        .alert {
            padding: 16px;
            margin: 20px 0;
            border-radius: 10px;
            line-height: 1.8;
        }

        .success { background: #e5f6eb; color: #166534; }
        .error { background: #feecec; color: #991b1b; }

        .note { color: #62788a; font-size: 12px; line-height: 1.8; }

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
        <nav aria-label="Page navigation">
            <a href="index.php">Workspace</a>
            <a href="appointments.php">Appointments</a>
        </nav>
    </header>

    <main>
        <h1>Reschedule appointment</h1>
        <p class="muted">
            Change a scheduled appointment’s time.
            All times on this page use India Standard Time.
        </p>

        <?php if ($success !== ''): ?>
            <div class="alert success" role="status">
                <?= rescheduleEscape($success) ?>
            </div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="alert error" role="alert">
                <?= rescheduleEscape($error) ?>
            </div>
        <?php endif; ?>

        <?php if ($loadError !== ''): ?>
            <div class="alert error" role="alert">
                <?= rescheduleEscape($loadError) ?>
            </div>
        <?php else: ?>
            <section class="card">
                <h2>Select appointment</h2>

                <?php if (!$options): ?>
                    <p class="muted">No Scheduled appointments available.</p>
                <?php else: ?>
                    <form method="GET" action="appointment_reschedule.php">
                        <label for="appointment">Scheduled appointment</label>

                        <select id="appointment" name="id" required>
                            <option value="">Select appointment</option>

                            <?php foreach ($options as $option): ?>
                                <option
                                    value="<?= (int) $option['appointment_id'] ?>"
                                    <?= (int) $appointmentId ===
                                        (int) $option['appointment_id']
                                        ? 'selected' : '' ?>
                                >
                                    <?= rescheduleEscape(
                                        '#' . $option['appointment_id']
                                        . ' — ' . $option['patient_name']
                                        . ' — ' . $option['doctor_name']
                                        . ' — ' . $option['starts_at']
                                    ) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <button type="submit">Load appointment</button>
                    </form>

                    <p class="note">
                        Latest 200 Scheduled appointments by start time.
                    </p>
                <?php endif; ?>
            </section>

            <?php if (
                $appointment && $appointment['status'] === 'Scheduled'
            ): ?>
                <section class="card">
                    <h2>New appointment time</h2>

                    <p class="muted">
                        Appointment #<?= (int) $appointmentId ?><br>
                        Patient:
                        <?= rescheduleEscape($appointment['patient_name']) ?>
                        <br>
                        Doctor:
                        <?= rescheduleEscape($appointment['doctor_name']) ?>
                    </p>

                    <form method="POST" action="appointment_reschedule.php">
                        <input
                            type="hidden"
                            name="token"
                            value="<?= rescheduleEscape(
                                $_SESSION['reschedule_token']
                            ) ?>"
                        >

                        <input
                            type="hidden"
                            name="appointment_id"
                            value="<?= (int) $appointmentId ?>"
                        >

                        <div class="row">
                            <div>
                                <label for="starts_at">New start — IST</label>
                                <input
                                    id="starts_at"
                                    name="starts_at"
                                    type="datetime-local"
                                    value="<?= rescheduleEscape(
                                        $values['starts_at']
                                    ) ?>"
                                    required
                                >
                            </div>

                            <div>
                                <label for="ends_at">New end — IST</label>
                                <input
                                    id="ends_at"
                                    name="ends_at"
                                    type="datetime-local"
                                    value="<?= rescheduleEscape(
                                        $values['ends_at']
                                    ) ?>"
                                    required
                                >
                            </div>
                        </div>

                        <label for="change_reason">Reason for rescheduling</label>
                        <input
                            id="change_reason"
                            name="change_reason"
                            maxlength="200"
                            value="<?= rescheduleEscape(
                                $values['change_reason']
                            ) ?>"
                            required
                        >

                        <button type="submit">Save new time</button>
                        <a class="button" href="appointments.php">
                            View appointments
                        </a>

                        <p class="note">
                            Maximum duration: four hours.
                            Changing the patient or doctor requires a separate
                            booking. No email or SMS notification is sent.
                        </p>
                    </form>
                </section>
            <?php endif; ?>
        <?php endif; ?>
    </main>
</body>
</html>