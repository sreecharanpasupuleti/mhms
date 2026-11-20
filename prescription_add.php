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
    exit('Sign in with a doctor account to manage prescriptions.');
}

$doctorId = (int) $doctor['doctor_id'];

function rxEscape($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

if (empty($_SESSION['rx_token'])) {
    $_SESSION['rx_token'] = bin2hex(random_bytes(32));
}

$fields = [
    'medicine_name',
    'dosage',
    'frequency',
    'duration',
    'instructions'
];

$rows = [[
    'medicine_name' => '',
    'dosage' => '',
    'frequency' => '',
    'duration' => '',
    'instructions' => ''
]];

$error = '';
$loadError = '';
$consultations = [];
$selectedVisit = null;
$medicines = [];

$success = $_SESSION['rx_success'] ?? '';
unset($_SESSION['rx_success']);

$visitInput = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? ($_POST['visit_id'] ?? '')
    : ($_GET['visit_id'] ?? '');

$visitId = is_string($visitInput)
    ? filter_var(
        $visitInput,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    )
    : false;

try {
    $query = $pdo->prepare(
        "SELECT v.visit_id, v.visit_date, p.full_name, p.mrn
         FROM visits v
         JOIN patients p ON v.patient_id = p.patient_id
         JOIN patient_assignments a
             ON a.patient_id = v.patient_id
             AND a.doctor_id = v.doctor_id
         WHERE v.doctor_id = ?
         ORDER BY v.visit_date DESC, v.visit_id DESC
         LIMIT 200"
    );

    $query->execute([$doctorId]);
    $consultations = $query->fetchAll();

    if ($visitId) {
        $query = $pdo->prepare(
            "SELECT v.visit_id, v.patient_id, v.visit_date, v.diagnosis,
                    p.full_name, p.mrn
             FROM visits v
             JOIN patients p ON v.patient_id = p.patient_id
             JOIN patient_assignments a
                 ON a.patient_id = v.patient_id
                 AND a.doctor_id = v.doctor_id
             WHERE v.visit_id = ? AND v.doctor_id = ?"
        );

        $query->execute([$visitId, $doctorId]);
        $selectedVisit = $query->fetch();

        if (!$selectedVisit) {
            $error = 'This consultation is unavailable to your account.';
        }
    } elseif ($visitInput !== '') {
        $error = 'Choose a valid consultation.';
    }
} catch (PDOException $exception) {
    error_log($exception->getMessage());
    $loadError = 'Consultations could not be loaded.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $loadError === '') {
    $token = $_POST['token'] ?? '';
    $submittedRows = $_POST['medicines'] ?? null;

    if (
        !is_string($token)
        || !hash_equals($_SESSION['rx_token'], $token)
    ) {
        $error = 'The form expired. Refresh the page and try again.';
    } elseif (!$selectedVisit) {
        $error = 'Select an available consultation first.';
    } elseif (
        !is_array($submittedRows)
        || count($submittedRows) < 1
        || count($submittedRows) > 15
    ) {
        $error = 'Enter between 1 and 15 medicine rows.';
    } elseif ($error === '') {
        $rows = [];

        foreach ($submittedRows as $submittedRow) {
            if (!is_array($submittedRow)) {
                $error = 'Invalid medicine row.';
                break;
            }

            $cleanRow = [];

            foreach ($fields as $field) {
                $value = $submittedRow[$field] ?? '';

                if (!is_string($value)) {
                    $error = 'Invalid medicine field.';
                    break;
                }

                $cleanRow[$field] = trim($value);
            }

            if ($error !== '') {
                break;
            }

            $rows[] = $cleanRow;
        }

        if ($error === '') {
            foreach ($rows as $index => $row) {
                $rowNumber = $index + 1;

                if (
                    $row['medicine_name'] === ''
                    || strlen($row['medicine_name']) > 150
                ) {
                    $error = "Row $rowNumber: enter a medicine name "
                        . 'of up to 150 characters.';
                    break;
                }

                foreach (['dosage', 'frequency', 'duration'] as $field) {
                    if ($row[$field] === '' || strlen($row[$field]) > 100) {
                        $error = "Row $rowNumber: $field is required "
                            . 'and must not exceed 100 characters.';
                        break;
                    }
                }

                if ($error !== '') {
                    break;
                }

                if (strlen($row['instructions']) > 2000) {
                    $error = "Row $rowNumber: instructions must not "
                        . 'exceed 2,000 characters.';
                    break;
                }
            }
        }

        if ($error === '') {
            try {
                $pdo->beginTransaction();

                // Lock the visit while checking and saving its prescription.
                $query = $pdo->prepare(
                    'SELECT patient_id FROM visits
                     WHERE visit_id = ? AND doctor_id = ?
                     FOR UPDATE'
                );
                $query->execute([$visitId, $doctorId]);
                $lockedVisit = $query->fetch();

                if (!$lockedVisit) {
                    throw new RuntimeException(
                        'The consultation is no longer available.'
                    );
                }

                $query = $pdo->prepare(
                    'SELECT assignment_id FROM patient_assignments
                     WHERE patient_id = ? AND doctor_id = ?
                     FOR UPDATE'
                );
                $query->execute([
                    $lockedVisit['patient_id'],
                    $doctorId
                ]);

                if (!$query->fetchColumn()) {
                    throw new RuntimeException(
                        'You are no longer assigned to this patient.'
                    );
                }

                $query = $pdo->prepare(
                    'SELECT prescription_id FROM prescriptions
                     WHERE visit_id = ? LIMIT 1'
                );
                $query->execute([$visitId]);

                if ($query->fetchColumn()) {
                    throw new RuntimeException(
                        'A prescription already exists for this consultation. '
                        . 'This version does not overwrite saved prescriptions.'
                    );
                }

                $insert = $pdo->prepare(
                    'INSERT INTO prescriptions
                     (visit_id, medicine_name, dosage, frequency,
                      duration, instructions)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );

                foreach ($rows as $row) {
                    $insert->execute([
                        $visitId,
                        $row['medicine_name'],
                        $row['dosage'],
                        $row['frequency'],
                        $row['duration'],
                        $row['instructions'] ?: null
                    ]);
                }

                logAction(
                    $pdo,
                    $_SESSION['user_id'],
                    $lockedVisit['patient_id'],
                    'ADD_PRESCRIPTION',
                    'Prescription saved for visit ID ' . $visitId
                );

                $pdo->commit();

                $_SESSION['rx_success'] =
                    'Prescription saved successfully.';

                $_SESSION['rx_token'] = bin2hex(random_bytes(32));

                header('Location: prescription_add.php?visit_id=' . $visitId);
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
                $error = 'The prescription could not be saved. Try again.';
            }
        }
    }
}

if ($selectedVisit && $loadError === '') {
    try {
        $query = $pdo->prepare(
            'SELECT medicine_name, dosage, frequency, duration, instructions
             FROM prescriptions
             WHERE visit_id = ?
             ORDER BY prescription_id'
        );
        $query->execute([$visitId]);
        $medicines = $query->fetchAll();

    } catch (PDOException $exception) {
        error_log($exception->getMessage());
        $loadError = 'Saved prescription information could not be loaded.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Prescription Builder | MHMS</title>

    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: #f3f7fa;
            color: #173247;
            font-family: "Segoe UI", Arial, sans-serif;
        }

        header {
            background: #102a3d;
            color: white;
            padding: 20px 5%;
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

        nav { display: flex; gap: 20px; flex-wrap: wrap; }
        nav a { color: white; text-decoration: none; }

        main {
            max-width: 1150px;
            margin: auto;
            padding: 32px 24px;
        }

        h1 { margin: 0 0 12px; font-size: 30px; }
        h2 { margin: 0 0 20px; font-size: 21px; }

        .muted {
            color: #62788a;
            line-height: 1.8;
        }

        .card {
            padding: 26px;
            background: white;
            border: 1px solid #e1eaf0;
            border-radius: 18px;
            margin: 22px 0;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-size: 13px;
            font-weight: 600;
        }

        input, select, textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #cad7e0;
            border-radius: 9px;
            font: inherit;
            font-size: 14px;
            background: white;
            color: #173247;
            margin-bottom: 18px;
        }

        input:focus, select:focus, textarea:focus {
            outline: 3px solid #d9f0f2;
            border-color: #087e8b;
        }

        textarea { resize: vertical; }

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

        button:hover { background: #066772; }
        button:disabled { opacity: 0.5; cursor: not-allowed; }

        .secondary {
            background: #e7f2f4;
            color: #086572;
        }

        .secondary:hover { background: #d3e9ed; }

        .remove {
            background: #fff0f0;
            color: #a12626;
            margin-bottom: 12px;
        }

        .remove:hover { background: #fbdcdc; }

        .medicine {
            border: 1px solid #dce7ee;
            background: #fbfdfe;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 18px;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 15px;
        }

        .actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .alert {
            padding: 16px;
            border-radius: 10px;
            line-height: 1.7;
            margin: 20px 0;
        }

        .success { background: #e5f6eb; color: #166534; }
        .error { background: #feecec; color: #991b1b; }
        .warning { background: #fff7e7; color: #795718; }

        .table-wrap { overflow-x: auto; }

        table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            font-size: 14px;
        }

        th { background: #f3f7fa; padding: 13px; }
        td { padding: 15px 13px; border-bottom: 1px solid #e1eaf0; }

        .note { font-size: 12px; color: #62788a; line-height: 1.8; }

        @media (max-width: 650px) {
            .grid { grid-template-columns: 1fr; gap: 0; }
            main { padding: 24px 14px; }
            .card { padding: 20px; }
        }
    </style>
</head>
<body>
    <header>
        <div class="brand">MHMS</div>
        <nav aria-label="Page navigation">
            <a href="doctor_dashboard.php">Doctor dashboard</a>
            <a href="visit_add.php">Add consultation</a>
        </nav>
    </header>

    <main>
        <h1>Prescription builder</h1>
        <p class="muted">
            Record fictional medicine entries for one of your consultations.
        </p>

        <div class="alert warning">
            Academic demonstration only. No medicine recommendations,
            dose calculations, or interaction checks are provided.
        </div>

        <?php if ($success !== ''): ?>
            <div class="alert success" role="status">
                <?= rxEscape($success) ?>
            </div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="alert error" role="alert">
                <?= rxEscape($error) ?>
            </div>
        <?php endif; ?>

        <?php if ($loadError !== ''): ?>
            <div class="alert error" role="alert">
                <?= rxEscape($loadError) ?>
            </div>
        <?php else: ?>
            <section class="card">
                <h2>Choose consultation</h2>

                <?php if (!$consultations): ?>
                    <p class="muted">
                        No eligible consultations found. Create a consultation
                        for an assigned patient first.
                    </p>
                <?php else: ?>
                    <form method="GET" action="prescription_add.php">
                        <label for="visit_id">Your consultation</label>

                        <select id="visit_id" name="visit_id" required>
                            <option value="">Select consultation</option>

                            <?php foreach ($consultations as $consultation): ?>
                                <option
                                    value="<?= (int) $consultation['visit_id'] ?>"
                                    <?= (int) $visitId ===
                                        (int) $consultation['visit_id']
                                        ? 'selected' : '' ?>
                                >
                                    <?= rxEscape(
                                        $consultation['full_name']
                                        . ' — ' . $consultation['visit_date']
                                        . ' — Visit #' . $consultation['visit_id']
                                        . ' — ' . $consultation['mrn']
                                    ) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <button type="submit">Open consultation</button>
                    </form>

                    <p class="note">
                        Showing up to 200 of your latest eligible consultations.
                    </p>
                <?php endif; ?>
            </section>

            <?php if ($selectedVisit): ?>
                <section class="card">
                    <h2><?= rxEscape($selectedVisit['full_name']) ?></h2>

                    <p class="muted">
                        Visit #<?= (int) $selectedVisit['visit_id'] ?>
                        · <?= rxEscape($selectedVisit['visit_date']) ?>
                    </p>

                    <p class="muted">
                        Recorded assessment:
                        <?= rxEscape($selectedVisit['diagnosis']) ?>
                    </p>
                </section>

                <?php if ($medicines): ?>
                    <section class="card">
                        <h2>Saved prescription entries</h2>

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
                                            <?php foreach ($fields as $field): ?>
                                                <td><?= rxEscape(
                                                    $medicine[$field] ?? ''
                                                ) ?></td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <p class="note">
                            Saved entries are read-only in this version.
                            Corrections and version history will be added
                            separately; this form does not overwrite records.
                        </p>
                    </section>
                <?php else: ?>
                    <section class="card">
                        <h2>Add medicine entries</h2>

                        <form method="POST" action="prescription_add.php">
                            <input
                                type="hidden"
                                name="token"
                                value="<?= rxEscape($_SESSION['rx_token']) ?>"
                            >

                            <input
                                type="hidden"
                                name="visit_id"
                                value="<?= (int) $visitId ?>"
                            >

                            <div id="medicineRows">
                                <?php foreach ($rows as $index => $row): ?>
                                    <div class="medicine">
                                        <label>
                                            Medicine name *
                                            <input
                                                name="medicines[<?= $index ?>][medicine_name]"
                                                maxlength="150"
                                                value="<?= rxEscape($row['medicine_name']) ?>"
                                                required
                                            >
                                        </label>

                                        <div class="grid">
                                            <?php foreach (
                                                ['dosage', 'frequency', 'duration']
                                                as $field
                                            ): ?>
                                                <label>
                                                    <?= rxEscape(ucfirst($field)) ?> *
                                                    <input
                                                        name="medicines[<?= $index ?>][<?= $field ?>]"
                                                        maxlength="100"
                                                        value="<?= rxEscape($row[$field]) ?>"
                                                        required
                                                    >
                                                </label>
                                            <?php endforeach; ?>
                                        </div>

                                        <label>
                                            Instructions — optional
                                            <textarea
                                                name="medicines[<?= $index ?>][instructions]"
                                                rows="2"
                                                maxlength="2000"
                                            ><?= rxEscape($row['instructions']) ?></textarea>
                                        </label>

                                        <button
                                            type="button"
                                            class="remove"
                                        >Remove row</button>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <div class="actions">
                                <button
                                    type="button"
                                    class="secondary"
                                    id="addMedicine"
                                >Add another medicine</button>

                                <button type="submit">Save prescription</button>
                            </div>

                            <p class="note">
                                * Required fields. Maximum 15 medicine rows.
                                Use fictional text for testing.
                            </p>
                        </form>
                    </section>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    </main>

    <script>
        const container = document.getElementById('medicineRows');
        const addButton = document.getElementById('addMedicine');

        if (container && addButton) {
            let nextIndex = <?= count($rows) ?>;

            function updateButtons() {
                const rows = container.querySelectorAll('.medicine');

                rows.forEach(row => {
                    row.querySelector('.remove').disabled = rows.length === 1;
                });

                addButton.disabled = rows.length >= 15;
            }

            addButton.addEventListener('click', () => {
                if (container.children.length >= 15) return;

                const clone = container.firstElementChild.cloneNode(true);

                clone.querySelectorAll('input, textarea').forEach(field => {
                    field.value = '';
                    field.name = field.name.replace(
                        /medicines\[\d+\]/,
                        'medicines[' + nextIndex + ']'
                    );
                });

                nextIndex++;
                container.appendChild(clone);
                updateButtons();
                clone.querySelector('input').focus();
            });

            container.addEventListener('click', event => {
                if (
                    event.target.classList.contains('remove')
                    && container.children.length > 1
                ) {
                    event.target.closest('.medicine').remove();
                    updateButtons();
                }
            });

            updateButtons();
        }
    </script>
</body>
</html>
