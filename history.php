<?php
require_once __DIR__ . '/config/db.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$accountQuery = $pdo->prepare(
    'SELECT full_name, role, status FROM users WHERE user_id = ?'
);
$accountQuery->execute([$_SESSION['user_id']]);
$account = $accountQuery->fetch();

if (!$account || $account['status'] !== 'active') {
    $_SESSION = [];
    session_destroy();

    header('Location: login.php');
    exit;
}

if ($account['role'] !== 'admin') {
    http_response_code(403);
    exit('You do not have permission to open this page.');
}

function historyEscape($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$categories = [
    'Chronic Disease',
    'Surgery',
    'Family History',
    'Lifestyle',
    'Mental Health',
    'Other'
];

$statuses = ['Active', 'Resolved', 'Ongoing'];

if (empty($_SESSION['history_token'])) {
    $_SESSION['history_token'] = bin2hex(random_bytes(32));
}

$values = [
    'patient_id' => '',
    'category' => '',
    'condition_name' => '',
    'diagnosed_date' => '',
    'status' => 'Active',
    'notes' => ''
];

$error = '';
$loadError = '';
$patientOptions = [];

$success = $_SESSION['history_success'] ?? '';
$savedPatientId = (int) ($_SESSION['history_saved_patient_id'] ?? 0);

unset(
    $_SESSION['history_success'],
    $_SESSION['history_saved_patient_id']
);

$selectedId = filter_input(
    INPUT_GET,
    'patient_id',
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if ($selectedId) {
    $values['patient_id'] = (string) $selectedId;
}

try {
    $patientOptions = $pdo->query(
        'SELECT patient_id, full_name, mrn
         FROM patients
         ORDER BY full_name, patient_id
         LIMIT 500'
    )->fetchAll();
} catch (PDOException $exception) {
    error_log($exception->getMessage());
    $loadError = 'The patient list could not be loaded.';
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

    $dateValid = true;

    if ($values['diagnosed_date'] !== '') {
        $recordDate = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $values['diagnosed_date']
        );

        $dateValid = $recordDate
            && $recordDate->format('Y-m-d') === $values['diagnosed_date']
            && $recordDate <= new DateTimeImmutable('today');
    }

    if (
        !is_string($token)
        || !hash_equals($_SESSION['history_token'], $token)
    ) {
        $error = 'The form expired. Refresh this page and try again.';
    } elseif (!$patientId) {
        $error = 'Select a patient.';
    } elseif (!in_array($values['category'], $categories, true)) {
        $error = 'Select a valid history category.';
    } elseif (
        $values['condition_name'] === ''
        || strlen($values['condition_name']) > 150
    ) {
        $error = 'Enter a condition or history title of up to 150 characters.';
    } elseif (!$dateValid) {
        $error = 'Enter a valid date that is not in the future, or leave it blank.';
    } elseif (!in_array($values['status'], $statuses, true)) {
        $error = 'Select a valid status.';
    } elseif (strlen($values['notes']) > 5000) {
        $error = 'Notes must not exceed 5,000 characters.';
    } else {
        try {
            $check = $pdo->prepare(
                'SELECT patient_id FROM patients WHERE patient_id = ?'
            );
            $check->execute([$patientId]);

            if (!$check->fetchColumn()) {
                $error = 'The selected patient no longer exists.';
            } else {
                $pdo->beginTransaction();

                $insert = $pdo->prepare(
                    "INSERT INTO medical_history
                    (
                        patient_id,
                        category,
                        condition_name,
                        diagnosed_date,
                        status,
                        notes,
                        created_by
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?)"
                );

                $insert->execute([
                    $patientId,
                    $values['category'],
                    $values['condition_name'],
                    $values['diagnosed_date'] ?: null,
                    $values['status'],
                    $values['notes'] ?: null,
                    $_SESSION['user_id']
                ]);

                logAction(
                    $pdo,
                    $_SESSION['user_id'],
                    $patientId,
                    'ADD_MEDICAL_HISTORY',
                    'Medical history entry created'
                );

                $pdo->commit();

                $_SESSION['history_success'] =
                    'Medical history saved successfully.';

                $_SESSION['history_saved_patient_id'] = $patientId;
                $_SESSION['history_token'] = bin2hex(random_bytes(32));

                header('Location: history.php?patient_id=' . $patientId);
                exit;
            }
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log($exception->getMessage());

            $error = 'The history entry could not be saved. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Add Medical History | MHMS</title>

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

        .topbar {
            padding: 20px 5%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            background: #102a3d;
            color: white;
        }

        .brand {
            font-size: 22px;
            font-weight: 800;
            letter-spacing: 3px;
        }

        .navigation {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }

        .navigation a {
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
            margin: 0 0 10px;
            font-size: 30px;
        }

        h2 {
            margin: 0 0 22px;
            font-size: 21px;
        }

        .subtitle {
            margin-bottom: 26px;
            color: #62788a;
            line-height: 1.8;
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

        button {
            width: 100%;
            padding: 15px;
            border: 0;
            border-radius: 10px;
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

        .success a {
            display: inline-block;
            margin-top: 8px;
            color: #125530;
            font-weight: 600;
        }

        .help p,
        .help li {
            color: #62788a;
            font-size: 14px;
            line-height: 1.8;
        }

        .help ul {
            padding-left: 20px;
        }

        .note {
            color: #62788a;
            font-size: 12px;
            line-height: 1.8;
        }

        .helper {
            margin: -12px 0 20px;
            color: #62788a;
            font-size: 12px;
            line-height: 1.7;
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

        <nav class="navigation" aria-label="Page navigation">
            <a href="dashboard.php">Dashboard</a>
            <a href="patients.php">Patients</a>
            <a href="patient_view.php">Patient profiles</a>
        </nav>
    </header>

    <main>
        <h1>Add medical history</h1>

        <p class="subtitle">
            Record a fictional patient’s previous condition,
            surgery, family history, or other background information.
        </p>

        <?php if ($success !== ''): ?>
            <div class="alert success" role="status">
                <?= historyEscape($success) ?>

                <?php if ($savedPatientId > 0): ?>
                    <br>
                    <a href="patient_view.php?id=<?= $savedPatientId ?>">
                        View the updated patient profile
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($loadError !== ''): ?>
            <div class="alert error" role="alert">
                <?= historyEscape($loadError) ?>
            </div>
        <?php elseif (!$patientOptions): ?>
            <section class="card">
                <h2>No patients available</h2>
                <p>
                    Open <a href="patients.php">Patient management</a>
                    and add a fictional patient first.
                </p>
            </section>
        <?php else: ?>
            <div class="layout">
                <section class="card">
                    <h2>History entry</h2>

                    <?php if ($error !== ''): ?>
                        <div class="alert error" role="alert">
                            <?= historyEscape($error) ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="history.php">
                        <input
                            type="hidden"
                            name="token"
                            value="<?= historyEscape(
                                $_SESSION['history_token']
                            ) ?>"
                        >

                        <label for="patient_id">Patient *</label>

                        <select id="patient_id" name="patient_id" required>
                            <option value="">Select a patient</option>

                            <?php foreach ($patientOptions as $patient): ?>
                                <option
                                    value="<?= (int) $patient['patient_id'] ?>"
                                    <?= (string) $patient['patient_id'] ===
                                        $values['patient_id']
                                        ? 'selected' : '' ?>
                                >
                                    <?= historyEscape(
                                        $patient['full_name']
                                        . ' — '
                                        . $patient['mrn']
                                    ) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <label for="category">Category *</label>

                        <select id="category" name="category" required>
                            <option value="">Select a category</option>

                            <?php foreach ($categories as $category): ?>
                                <option
                                    value="<?= historyEscape($category) ?>"
                                    <?= $values['category'] === $category
                                        ? 'selected' : '' ?>
                                >
                                    <?= historyEscape($category) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <label for="condition_name">
                            Condition or history title *
                        </label>

                        <input
                            id="condition_name"
                            name="condition_name"
                            maxlength="150"
                            placeholder="Example: Previous knee surgery"
                            value="<?= historyEscape(
                                $values['condition_name']
                            ) ?>"
                            required
                        >

                        <div class="row">
                            <div>
                                <label for="diagnosed_date">
                                    Relevant date — optional
                                </label>

                                <input
                                    id="diagnosed_date"
                                    name="diagnosed_date"
                                    type="date"
                                    max="<?= date('Y-m-d') ?>"
                                    value="<?= historyEscape(
                                        $values['diagnosed_date']
                                    ) ?>"
                                >
                            </div>

                            <div>
                                <label for="status">Status *</label>

                                <select id="status" name="status" required>
                                    <?php foreach ($statuses as $status): ?>
                                        <option
                                            value="<?= historyEscape($status) ?>"
                                            <?= $values['status'] === $status
                                                ? 'selected' : '' ?>
                                        >
                                            <?= historyEscape($status) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <p class="helper">
                            Leave the date blank if it is unknown.
                            For family history, explain whose history
                            is being recorded in the notes.
                        </p>

                        <label for="notes">Notes — optional</label>

                        <textarea
                            id="notes"
                            name="notes"
                            rows="5"
                            maxlength="5000"
                            placeholder="Enter fictional background details"
                        ><?= historyEscape($values['notes']) ?></textarea>

                        <button type="submit">Save medical history</button>

                        <p class="note">
                            * Required fields. Use fictional data only.
                            The patient selector shows up to 500 profiles.
                        </p>
                    </form>
                </section>

                <aside class="card help">
                    <h2>What this form does</h2>

                    <ul>
                        <li>Links the entry to the selected patient.</li>
                        <li>Saves its category, date, status, and notes.</li>
                        <li>Records which account created the entry.</li>
                        <li>Adds the entry to the patient-profile page.</li>
                    </ul>

                    <p>
                        This form does not diagnose conditions,
                        recommend medication, or verify clinical accuracy.
                    </p>

                    <p>
                        Allergy records and consultations will use
                        separate forms.
                    </p>
                </aside>
            </div>
        <?php endif; ?>
    </main>
</body>
</html>