<?php
require_once __DIR__ . '/config/db.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

function removeAssignmentEscape($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

try {
    $query = $pdo->prepare(
        'SELECT role, status FROM users WHERE user_id = ?'
    );
    $query->execute([$_SESSION['user_id']]);
    $administrator = $query->fetch();

} catch (PDOException $exception) {
    error_log($exception->getMessage());
    http_response_code(500);
    exit('Your account could not be checked. Please try again.');
}

if (!$administrator || $administrator['status'] !== 'active') {
    $_SESSION = [];
    session_destroy();

    header('Location: login.php');
    exit;
}

if ($administrator['role'] !== 'admin') {
    http_response_code(403);
    exit('Only administrators can remove patient assignments.');
}

if (empty($_SESSION['remove_assignment_token'])) {
    $_SESSION['remove_assignment_token'] =
        bin2hex(random_bytes(32));
}

$error = '';
$loadError = '';
$assignments = [];

$success = $_SESSION['remove_assignment_success'] ?? '';
unset($_SESSION['remove_assignment_success']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['token'] ?? '';
    $idInput = $_POST['assignment_id'] ?? '';
    $reasonInput = $_POST['reason'] ?? '';

    $assignmentId = is_string($idInput)
        ? filter_var(
            $idInput,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        )
        : false;

    $reason = is_string($reasonInput) ? trim($reasonInput) : '';

    if (
        !is_string($token)
        || !hash_equals(
            $_SESSION['remove_assignment_token'],
            $token
        )
    ) {
        $error = 'The form expired. Refresh the page and try again.';

    } elseif (!$assignmentId) {
        $error = 'Invalid assignment reference.';

    } elseif ($reason === '' || strlen($reason) > 200) {
        $error = 'Enter a reason of up to 200 characters.';

    } else {
        try {
            $pdo->beginTransaction();

            $query = $pdo->prepare(
                'SELECT assignment_id, patient_id, doctor_id
                 FROM patient_assignments
                 WHERE assignment_id = ?
                 FOR UPDATE'
            );
            $query->execute([$assignmentId]);
            $assignment = $query->fetch();

            if (!$assignment) {
                throw new RuntimeException(
                    'This assignment no longer exists. Refresh the page.'
                );
            }

            $query = $pdo->prepare(
                'DELETE FROM patient_assignments
                 WHERE assignment_id = ?'
            );
            $query->execute([$assignmentId]);

            logAction(
                $pdo,
                $_SESSION['user_id'],
                $assignment['patient_id'],
                'REMOVE_PATIENT_ASSIGNMENT',
                'Removed assignment ID ' . $assignmentId
                . ' for doctor ID ' . $assignment['doctor_id']
                . '. Reason: ' . $reason
            );

            $pdo->commit();

            $_SESSION['remove_assignment_success'] =
                'Assignment removed successfully. '
                . 'Patient and clinical records were not deleted. '
                . 'Existing appointments were not cancelled.';

            $_SESSION['remove_assignment_token'] =
                bin2hex(random_bytes(32));

            header('Location: assignment_remove.php');
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

            $error = 'The assignment could not be removed. '
                . 'Please try again.';
        }
    }
}

$searchInput = $_GET['search'] ?? '';

$search = is_string($searchInput)
    ? substr(trim($searchInput), 0, 100)
    : '';

try {
    $sql = "
        SELECT a.assignment_id, a.patient_id, a.doctor_id,
               a.created_at,
               p.full_name AS patient_name, p.mrn,
               u.full_name AS doctor_name,
               u.status AS doctor_status,
               (
                   SELECT COUNT(*)
                   FROM appointments ap
                   WHERE ap.patient_id = a.patient_id
                     AND ap.doctor_id = a.doctor_id
                     AND ap.status = 'Scheduled'
               ) AS scheduled_appointments
        FROM patient_assignments a
        JOIN patients p ON a.patient_id = p.patient_id
        JOIN doctors d ON a.doctor_id = d.doctor_id
        JOIN users u ON d.user_id = u.user_id
    ";

    $parameters = [];

    if ($search !== '') {
        $sql .= "
            WHERE p.full_name LIKE ?
               OR p.mrn LIKE ?
               OR u.full_name LIKE ?
        ";

        $term = '%' . $search . '%';
        $parameters = [$term, $term, $term];
    }

    $sql .= ' ORDER BY a.assignment_id DESC LIMIT 100';

    $query = $pdo->prepare($sql);
    $query->execute($parameters);
    $assignments = $query->fetchAll();

} catch (PDOException $exception) {
    error_log($exception->getMessage());
    $loadError = 'Assignment records could not be loaded.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Remove Patient Assignment | MHMS</title>

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
            border: 1px solid #e1eaf0;
            border-radius: 18px;
            background: white;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-size: 13px;
            font-weight: 600;
        }

        input {
            width: 100%;
            padding: 12px;
            margin-bottom: 12px;
            border: 1px solid #cad7e0;
            border-radius: 9px;
            background: white;
            color: #173247;
            font: inherit;
            font-size: 14px;
        }

        input:focus {
            outline: 3px solid #d9f0f2;
            border-color: #087e8b;
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

        button:hover, .button:hover {
            background: #066772;
        }

        .remove {
            background: #fff0f0;
            color: #a12626;
        }

        .remove:hover {
            background: #fbdcdc;
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

        .warning {
            background: #fff8e8;
            color: #795718;
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
            padding: 16px 10px;
            border-bottom: 1px solid #e1eaf0;
            vertical-align: top;
        }

        .remove-form {
            min-width: 250px;
        }

        .small {
            display: block;
            margin-top: 7px;
            color: #62788a;
            font-size: 11px;
            line-height: 1.7;
        }

        .badge {
            display: inline-block;
            padding: 5px 8px;
            border-radius: 6px;
            background: #eef3f6;
            color: #465f72;
        }

        .note {
            color: #62788a;
            font-size: 12px;
            line-height: 1.8;
        }

        @media (max-width: 550px) {
            main { padding: 24px 14px; }
            .card { padding: 20px; }

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
            <a href="assignments.php">Create assignment</a>
            <a href="appointments.php">Appointments</a>
        </nav>
    </header>

    <main>
        <h1>Remove a patient assignment</h1>

        <p class="muted">
            Withdraw a doctor’s current patient assignment
            without deleting historical records.
        </p>

        <div class="alert warning">
            Existing appointments are not automatically cancelled.
            Review the scheduled-appointment count before removal.
            After removal, those appointments will no longer appear
            in the doctor’s assignment-restricted appointment view,
            but the administrator can still manage them.
        </div>

        <?php if ($success !== ''): ?>
            <div class="alert success" role="status">
                <?= removeAssignmentEscape($success) ?>
            </div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="alert error" role="alert">
                <?= removeAssignmentEscape($error) ?>
            </div>
        <?php endif; ?>

        <section class="card">
            <h2>Find an assignment</h2>

            <form method="GET" action="assignment_remove.php">
                <label for="search">Patient name, MRN, or doctor name</label>

                <input
                    id="search"
                    name="search"
                    maxlength="100"
                    value="<?= removeAssignmentEscape($search) ?>"
                >

                <button type="submit">Search</button>
                <a class="button" href="assignment_remove.php">Clear search</a>
            </form>
        </section>

        <?php if ($loadError !== ''): ?>
            <div class="alert error" role="alert">
                <?= removeAssignmentEscape($loadError) ?>
            </div>
        <?php else: ?>
            <section class="card">
                <h2>Current assignments</h2>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Patient</th>
                                <th>Doctor</th>
                                <th>Assigned on</th>
                                <th>Scheduled appointments</th>
                                <th>Remove assignment</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php if (!$assignments): ?>
                                <tr>
                                    <td colspan="5">
                                        No matching assignments found.
                                    </td>
                                </tr>
                            <?php endif; ?>

                            <?php foreach ($assignments as $assignment): ?>
                                <tr>
                                    <td>
                                        <?= removeAssignmentEscape(
                                            $assignment['patient_name']
                                        ) ?>

                                        <span class="small">
                                            <?= removeAssignmentEscape(
                                                $assignment['mrn']
                                            ) ?>
                                        </span>
                                    </td>

                                    <td>
                                        <?= removeAssignmentEscape(
                                            $assignment['doctor_name']
                                        ) ?>

                                        <span class="small">
                                            Account status:
                                            <?= removeAssignmentEscape(
                                                $assignment['doctor_status']
                                            ) ?>
                                        </span>
                                    </td>

                                    <td><?= removeAssignmentEscape(
                                        $assignment['created_at']
                                    ) ?></td>

                                    <td>
                                        <span class="badge">
                                            <?= (int) $assignment[
                                                'scheduled_appointments'
                                            ] ?>
                                        </span>
                                    </td>

                                    <td>
                                        <form
                                            method="POST"
                                            action="assignment_remove.php"
                                            class="remove-form"
                                            onsubmit="return confirm('Remove this patient–doctor assignment? Records and appointments will not be deleted.')"
                                        >
                                            <input
                                                type="hidden"
                                                name="token"
                                                value="<?= removeAssignmentEscape(
                                                    $_SESSION['remove_assignment_token']
                                                ) ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="assignment_id"
                                                value="<?= (int) $assignment[
                                                    'assignment_id'
                                                ] ?>"
                                            >

                                            <label for="reason-<?= (int)
                                                $assignment['assignment_id']
                                            ?>">
                                                Reason for removal
                                            </label>

                                            <input
                                                id="reason-<?= (int)
                                                    $assignment['assignment_id']
                                                ?>"
                                                name="reason"
                                                maxlength="200"
                                                placeholder="Short administrative reason"
                                                required
                                            >

                                            <button
                                                type="submit"
                                                class="remove"
                                            >
                                                Remove assignment
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <p class="note">
                    Latest 100 matching assignments.
                    The appointment count includes all entries still
                    marked Scheduled, including past dates.
                </p>
            </section>
        <?php endif; ?>

        <p class="note">
            Assignment removal is recorded in the audit log.
            It does not revoke files already downloaded or erase
            information already displayed in a browser.
            You can create a new assignment later through
            the Create assignment page.
        </p>
    </main>
</body>
</html>