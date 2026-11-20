<?php
require_once __DIR__ . '/config/db.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$query = $pdo->prepare(
    "SELECT u.role, u.status, u.full_name, d.doctor_id,
            d.specialization, dep.department_name
     FROM users u
     LEFT JOIN doctors d ON u.user_id = d.user_id
     LEFT JOIN departments dep
         ON d.department_id = dep.department_id
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
    exit('Sign in with a doctor account to print prescriptions.');
}

header('Cache-Control: no-store');

$doctorId = (int) $doctor['doctor_id'];

function printEscape($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$visitId = filter_input(
    INPUT_GET,
    'visit_id',
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

$choices = [];
$visit = null;
$medicines = [];
$error = '';

try {
    $query = $pdo->prepare(
        "SELECT v.visit_id, v.visit_date, p.full_name, p.mrn
         FROM visits v
         JOIN patients p ON v.patient_id = p.patient_id
         JOIN patient_assignments a
             ON a.patient_id = v.patient_id
             AND a.doctor_id = v.doctor_id
         WHERE v.doctor_id = ?
           AND EXISTS (
               SELECT 1
               FROM prescriptions rx
               WHERE rx.visit_id = v.visit_id
           )
         ORDER BY v.visit_date DESC, v.visit_id DESC
         LIMIT 200"
    );

    $query->execute([$doctorId]);
    $choices = $query->fetchAll();

    if ($visitId) {
        $query = $pdo->prepare(
            "SELECT v.visit_id, v.patient_id, v.visit_date,
                    v.diagnosis, v.follow_up_date,
                    p.full_name AS patient_name,
                    p.mrn, p.dob
             FROM visits v
             JOIN patients p ON v.patient_id = p.patient_id
             JOIN patient_assignments a
                 ON a.patient_id = v.patient_id
                 AND a.doctor_id = v.doctor_id
             WHERE v.visit_id = ? AND v.doctor_id = ?"
        );

        $query->execute([$visitId, $doctorId]);
        $visit = $query->fetch();

        if (!$visit) {
            $error = 'This consultation is unavailable to your account.';
        } else {
            $query = $pdo->prepare(
                'SELECT medicine_name, dosage, frequency,
                        duration, instructions
                 FROM prescriptions
                 WHERE visit_id = ?
                 ORDER BY prescription_id'
            );

            $query->execute([$visitId]);
            $medicines = $query->fetchAll();

            if (!$medicines) {
                $error = 'No prescription has been saved for this consultation.';
            } else {
                logAction(
                    $pdo,
                    $_SESSION['user_id'],
                    $visit['patient_id'],
                    'VIEW_PRESCRIPTION_PRINT',
                    'Opened printable prescription for visit ID ' . $visitId
                );
            }
        }
    } elseif (isset($_GET['visit_id'])) {
        $error = 'Select a valid consultation.';
    }

} catch (PDOException $exception) {
    error_log($exception->getMessage());
    $error = 'The printable prescription could not be loaded.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Demo Prescription | MHMS</title>

    <style>
        * {
            box-sizing: border-box;
        }

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
            gap: 20px;
            flex-wrap: wrap;
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
            max-width: 1100px;
            margin: auto;
            padding: 30px 24px;
        }

        .controls {
            padding: 24px;
            margin-bottom: 24px;
            background: white;
            border: 1px solid #dde7ee;
            border-radius: 16px;
        }

        h1 {
            margin: 0 0 18px;
            font-size: 26px;
        }

        label {
            display: block;
            margin-bottom: 10px;
            font-weight: 600;
            font-size: 14px;
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

        button:hover {
            background: #066772;
        }

        .print-button {
            margin-top: 18px;
        }

        .error {
            padding: 16px;
            margin-bottom: 20px;
            border-radius: 10px;
            background: #feecec;
            color: #991b1b;
            line-height: 1.7;
        }

        .note {
            color: #62788a;
            font-size: 13px;
            line-height: 1.8;
        }

        .sheet {
            background: white;
            padding: 40px;
            border: 1px solid #dde7ee;
            border-radius: 14px;
        }

        .sheet-header {
            padding-bottom: 24px;
            border-bottom: 3px solid #087e8b;
        }

        .sheet-header h2 {
            margin: 0 0 10px;
            font-size: 28px;
            color: #087e8b;
        }

        .sheet-header p {
            margin: 5px 0;
            font-size: 14px;
            line-height: 1.6;
        }

        .demo-banner {
            margin: 22px 0;
            padding: 12px;
            border: 1px solid #b78120;
            background: #fff8e8;
            color: #795718;
            text-align: center;
            font-size: 13px;
            font-weight: 700;
        }

        .details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px 24px;
            margin: 24px 0;
        }

        .details div {
            line-height: 1.7;
            font-size: 14px;
            overflow-wrap: anywhere;
        }

        .details span {
            display: block;
            color: #62788a;
            font-size: 12px;
        }

        .assessment {
            margin-bottom: 24px;
            font-size: 14px;
            line-height: 1.8;
            overflow-wrap: anywhere;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            text-align: left;
        }

        th {
            padding: 13px 10px;
            background: #eef5f7;
            border-bottom: 1px solid #cbdde4;
        }

        td {
            padding: 16px 10px;
            border-bottom: 1px solid #dce6ec;
            vertical-align: top;
            overflow-wrap: anywhere;
            white-space: pre-wrap;
        }

        .sheet-footer {
            margin-top: 30px;
            border-top: 1px solid #dce6ec;
            padding-top: 18px;
            color: #62788a;
            font-size: 12px;
            line-height: 1.8;
        }

        @media (max-width: 600px) {
            main {
                padding: 20px 12px;
            }

            .sheet {
                padding: 24px 18px;
            }

            .details {
                grid-template-columns: 1fr;
            }
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

            .no-print {
                display: none !important;
            }

            main {
                max-width: none;
                padding: 0;
                margin: 0;
            }

            .sheet {
                padding: 0;
                border: 0;
                border-radius: 0;
            }

            .table-wrap {
                overflow: visible;
            }

            table {
                table-layout: fixed;
                font-size: 10pt;
            }

            th,
            td {
                padding: 8px 5px;
            }

            thead {
                display: table-header-group;
            }

            tr,
            .details,
            .sheet-header,
            .demo-banner {
                break-inside: avoid;
            }

            .demo-banner {
                color: black;
                border-color: black;
            }
        }
    </style>
</head>
<body>
    <header class="topbar no-print">
        <div class="brand">MHMS</div>

        <nav aria-label="Page navigation">
            <a href="doctor_dashboard.php">Doctor dashboard</a>
            <a href="prescription_add.php">Prescription builder</a>
        </nav>
    </header>

    <main>
        <section class="controls no-print">
            <h1>Print a saved prescription</h1>

            <?php if (!$choices): ?>
                <p class="note">
                    No eligible saved prescriptions were found.
                    Save a prescription for one of your assigned
                    patients first.
                </p>
            <?php else: ?>
                <form method="GET" action="prescription_print.php">
                    <label for="visit_id">Choose a saved prescription</label>

                    <select id="visit_id" name="visit_id" required>
                        <option value="">Select consultation</option>

                        <?php foreach ($choices as $choice): ?>
                            <option
                                value="<?= (int) $choice['visit_id'] ?>"
                                <?= (int) $visitId ===
                                    (int) $choice['visit_id']
                                    ? 'selected' : '' ?>
                            >
                                <?= printEscape(
                                    $choice['full_name']
                                    . ' — ' . $choice['visit_date']
                                    . ' — Visit #' . $choice['visit_id']
                                    . ' — ' . $choice['mrn']
                                ) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <button type="submit">Open prescription</button>
                </form>
            <?php endif; ?>

            <p class="note">
                Showing up to 200 eligible consultations with saved
                prescription entries. This page does not change the
                prescription.
            </p>

            <?php if ($visit && $medicines && $error === ''): ?>
                <button
                    type="button"
                    class="print-button"
                    onclick="window.print()"
                >
                    Print / Save as PDF
                </button>
            <?php endif; ?>
        </section>

        <?php if ($error !== ''): ?>
            <div class="error no-print" role="alert">
                <?= printEscape($error) ?>
            </div>
        <?php endif; ?>

        <?php if ($visit && $medicines && $error === ''): ?>
            <article class="sheet">
                <header class="sheet-header">
                    <h2>MHMS</h2>
                    <p>Medical History Management System</p>
                    <p>
                        Recorded by:
                        <?= printEscape($doctor['full_name']) ?>
                    </p>
                    <p>
                        Specialization:
                        <?= printEscape(
                            $doctor['specialization'] ?: 'Not recorded'
                        ) ?>
                    </p>
                </header>

                <div class="demo-banner">
                    ACADEMIC DEMO — NOT A VALID MEDICAL PRESCRIPTION
                </div>

                <section class="details">
                    <div>
                        <span>Patient name</span>
                        <?= printEscape($visit['patient_name']) ?>
                    </div>

                    <div>
                        <span>Medical record number</span>
                        <?= printEscape($visit['mrn']) ?>
                    </div>

                    <div>
                        <span>Consultation date</span>
                        <?= printEscape($visit['visit_date']) ?>
                    </div>

                    <div>
                        <span>Consultation reference</span>
                        Visit #<?= (int) $visit['visit_id'] ?>
                    </div>
                </section>

                <div class="assessment">
                    <strong>Recorded assessment:</strong>
                    <?= printEscape(
                        $visit['diagnosis'] ?: 'Not recorded'
                    ) ?>
                </div>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
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
                                    <td><?= printEscape(
                                        $medicine['medicine_name']
                                    ) ?></td>

                                    <td><?= printEscape(
                                        $medicine['dosage']
                                    ) ?></td>

                                    <td><?= printEscape(
                                        $medicine['frequency']
                                    ) ?></td>

                                    <td><?= printEscape(
                                        $medicine['duration']
                                    ) ?></td>

                                    <td><?= printEscape(
                                        $medicine['instructions']
                                        ?: 'Not recorded'
                                    ) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <p class="assessment">
                    <strong>Recorded follow-up date:</strong>
                    <?= printEscape(
                        $visit['follow_up_date'] ?: 'Not recorded'
                    ) ?>
                </p>

                <footer class="sheet-footer">
                    Fictional records for academic demonstration only.
                    This document is not signed, clinically verified,
                    or intended for dispensing medication.
                    <br>
                    No medicine recommendations, dosage validation,
                    or interaction checks are provided.
                </footer>
            </article>
        <?php endif; ?>
    </main>
</body>
</html>