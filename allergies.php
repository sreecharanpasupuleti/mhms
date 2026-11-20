<?php
require_once __DIR__ . '/config/db.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$accountQuery = $pdo->prepare(
    'SELECT role, status FROM users WHERE user_id = ?'
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

function allergyEscape($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

if (empty($_SESSION['allergy_token'])) {
    $_SESSION['allergy_token'] = bin2hex(random_bytes(32));
}

$values = [
    'patient_id' => '',
    'allergen' => '',
    'reaction' => '',
    'severity' => ''
];

$severities = ['Mild', 'Moderate', 'Severe'];

$error = '';
$loadError = '';
$patients = [];

$success = $_SESSION['allergy_success'] ?? '';
$savedPatientId = (int) ($_SESSION['allergy_saved_patient_id'] ?? 0);

unset(
    $_SESSION['allergy_success'],
    $_SESSION['allergy_saved_patient_id']
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
    $patients = $pdo->query(
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

    if (
        !is_string($token)
        || !hash_equals($_SESSION['allergy_token'], $token)
    ) {
        $error = 'The form expired. Refresh the page and try again.';
    } elseif (!$patientId) {
        $error = 'Select a patient.';
    } elseif (
        $values['allergen'] === ''
        || strlen($values['allergen']) > 100
    ) {
        $error = 'Enter an allergen of up to 100 characters.';
    } elseif (
        $values['reaction'] === ''
        || strlen($values['reaction']) > 200
    ) {
        $error = 'Enter a reaction description of up to 200 characters.';
    } elseif (!in_array($values['severity'], $severities, true)) {
        $error = 'Select a severity.';
    } else {
        try {
            $query = $pdo->prepare(
                'SELECT patient_id FROM patients WHERE patient_id = ?'
            );
            $query->execute([$patientId]);

            if (!$query->fetchColumn()) {
                $error = 'The selected patient no longer exists.';
            } else {
                $pdo->beginTransaction();

                $insert = $pdo->prepare(
                    'INSERT INTO allergies
                     (patient_id, allergen, reaction, severity)
                     VALUES (?, ?, ?, ?)'
                );

                $insert->execute([
                    $patientId,
                    $values['allergen'],
                    $values['reaction'],
                    $values['severity']
                ]);

                logAction(
                    $pdo,
                    $_SESSION['user_id'],
                    $patientId,
                    'ADD_ALLERGY',
                    'Allergy entry created'
                );

                $pdo->commit();

                $_SESSION['allergy_success'] =
                    'Allergy entry saved successfully.';

                $_SESSION['allergy_saved_patient_id'] = $patientId;
                $_SESSION['allergy_token'] = bin2hex(random_bytes(32));

                header('Location: allergies.php?patient_id=' . $patientId);
                exit;
            }
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log($exception->getMessage());
            $error = 'The allergy entry could not be saved. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Record Allergy | MHMS</title>

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
            max-width: 1000px;
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
            margin-bottom: 26px;
            color: #62788a;
            line-height: 1.8;
        }

        .layout {
            display: grid;
            grid-template-columns: minmax(0, 1.5fr) minmax(0, 1fr);
            gap: 24px;
            align-items: start;
        }

        .card {
            padding: 28px;
            background: white;
            border: 1px solid #e1eaf0;
            border-radius: 18px;
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

        button,
        .profile-button {
            display: inline-block;
            padding: 14px 20px;
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

        button {
            width: 100%;
        }

        button:hover,
        .profile-button:hover {
            background: #066772;
        }

        .profile-button {
            margin-top: 14px;
        }

        .alert {
            padding: 18px;
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

        .help {
            border-top: 4px solid #d99d34;
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

        @media (max-width: 750px) {
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

        <nav aria-label="Page navigation">
            <a href="dashboard.php">Dashboard</a>
            <a href="patients.php">Patients</a>
            <a href="patient_view.php">Patient profiles</a>
            <a href="history.php">Medical history</a>
        </nav>
    </header>

    <main>
        <h1>Record an allergy</h1>

        <p class="subtitle">
            Add a fictional allergy record to the selected
            patient’s profile.
        </p>

        <?php if ($success !== ''): ?>
            <div class="alert success" role="status">
                <?= allergyEscape($success) ?>

                <?php if ($savedPatientId > 0): ?>
                    <br>
                    <a
                        class="profile-button"
                        href="patient_view.php?id=<?= $savedPatientId ?>"
                    >
                        Open updated patient profile
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($loadError !== ''): ?>
            <div class="alert error" role="alert">
                <?= allergyEscape($loadError) ?>
            </div>
        <?php elseif (!$patients): ?>
            <section class="card">
                <h2>No patients available</h2>
                <p>
                    Add a fictional patient in
                    <a href="patients.php">Patient management</a> first.
                </p>
            </section>
        <?php else: ?>
            <div class="layout">
                <section class="card">
                    <h2>Allergy details</h2>

                    <?php if ($error !== ''): ?>
                        <div class="alert error" role="alert">
                            <?= allergyEscape($error) ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="allergies.php">
                        <input
                            type="hidden"
                            name="token"
                            value="<?= allergyEscape(
                                $_SESSION['allergy_token']
                            ) ?>"
                        >

                        <label for="patient_id">Patient *</label>

                        <select id="patient_id" name="patient_id" required>
                            <option value="">Select a patient</option>

                            <?php foreach ($patients as $patient): ?>
                                <option
                                    value="<?= (int) $patient['patient_id'] ?>"
                                    <?= (string) $patient['patient_id'] ===
                                        $values['patient_id']
                                        ? 'selected' : '' ?>
                                >
                                    <?= allergyEscape(
                                        $patient['full_name']
                                        . ' — '
                                        . $patient['mrn']
                                    ) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <label for="allergen">Allergen *</label>

                        <input
                            id="allergen"
                            name="allergen"
                            maxlength="100"
                            placeholder="Example: Demo allergen"
                            value="<?= allergyEscape($values['allergen']) ?>"
                            required
                        >

                        <label for="reaction">Recorded reaction *</label>

                        <textarea
                            id="reaction"
                            name="reaction"
                            rows="4"
                            maxlength="200"
                            placeholder="Enter the fictional reaction details"
                            required
                        ><?= allergyEscape($values['reaction']) ?></textarea>

                        <label for="severity">Recorded severity *</label>

                        <select id="severity" name="severity" required>
                            <option value="">Select severity</option>

                            <?php foreach ($severities as $severity): ?>
                                <option
                                    value="<?= allergyEscape($severity) ?>"
                                    <?= $values['severity'] === $severity
                                        ? 'selected' : '' ?>
                                >
                                    <?= allergyEscape($severity) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <button type="submit">Save allergy entry</button>

                        <p class="note">
                            * Required fields. The selector shows
                            up to 500 patient profiles.
                        </p>
                    </form>
                </section>

                <aside class="card help">
                    <h2>Demonstration notes</h2>

                    <ul>
                        <li>Select the correct patient before saving.</li>
                        <li>Enter fictional information only.</li>
                        <li>
                            Severity is manually entered;
                            the system does not determine it.
                        </li>
                        <li>
                            Saved entries appear under Recorded allergies
                            on the patient-profile page.
                        </li>
                    </ul>

                    <p>
                        An empty allergy list means no entries are
                        recorded. It does not confirm the absence
                        of allergies.
                    </p>
                </aside>
            </div>
        <?php endif; ?>
    </main>
</body>
</html>