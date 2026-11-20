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

// This first patient-management module is for administrators.
if ($account['role'] !== 'admin') {
    http_response_code(403);
    exit('You do not have permission to open this page.');
}

function patientEscape($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

if (empty($_SESSION['patient_form_token'])) {
    $_SESSION['patient_form_token'] = bin2hex(random_bytes(32));
}

$genders = ['Male', 'Female', 'Other'];
$bloodGroups = ['', 'A+', 'A-', 'B+', 'B-', 'O+', 'O-', 'AB+', 'AB-'];

$values = [
    'full_name' => '',
    'dob' => '',
    'gender' => '',
    'blood_group' => '',
    'phone' => '',
    'email' => '',
    'address' => '',
    'emergency_contact' => ''
];

$error = '';
$listError = '';
$patients = [];

$success = $_SESSION['patient_success'] ?? '';
unset($_SESSION['patient_success']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($values as $field => $unused) {
        $input = $_POST[$field] ?? '';
        $values[$field] = is_string($input) ? trim($input) : '';
    }

    $token = $_POST['token'] ?? '';

    $birthDate = DateTimeImmutable::createFromFormat(
        '!Y-m-d',
        $values['dob']
    );

    if (
        !is_string($token) ||
        !hash_equals($_SESSION['patient_form_token'], $token)
    ) {
        $error = 'The form expired. Refresh the page and try again.';
    } elseif (
        $values['full_name'] === '' ||
        strlen($values['full_name']) > 100
    ) {
        $error = 'Enter a patient name of no more than 100 characters.';
    } elseif (
        !$birthDate ||
        $birthDate->format('Y-m-d') !== $values['dob'] ||
        $birthDate > new DateTimeImmutable('today')
    ) {
        $error = 'Enter a valid date of birth that is not in the future.';
    } elseif (!in_array($values['gender'], $genders, true)) {
        $error = 'Select a gender.';
    } elseif (!in_array($values['blood_group'], $bloodGroups, true)) {
        $error = 'Select a valid blood group.';
    } elseif (
        !preg_match('/^[0-9+() .-]{7,20}$/', $values['phone'])
    ) {
        $error = 'Enter a phone number containing 7–20 permitted characters.';
    } elseif (
        $values['email'] !== '' &&
        (
            strlen($values['email']) > 120 ||
            !filter_var($values['email'], FILTER_VALIDATE_EMAIL)
        )
    ) {
        $error = 'Enter a valid email address or leave it blank.';
    } elseif (strlen($values['emergency_contact']) > 100) {
        $error = 'Emergency contact must not exceed 100 characters.';
    } elseif (strlen($values['address']) > 2000) {
        $error = 'Address must not exceed 2,000 characters.';
    } else {
        try {
            $pdo->beginTransaction();

            // Generate a short, random medical record number.
            $mrn = 'MH' . strtoupper(bin2hex(random_bytes(8)));

            $insert = $pdo->prepare(
                "INSERT INTO patients
                (
                    mrn,
                    full_name,
                    dob,
                    gender,
                    blood_group,
                    phone,
                    email,
                    address,
                    emergency_contact
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );

            $insert->execute([
                $mrn,
                $values['full_name'],
                $values['dob'],
                $values['gender'],
                $values['blood_group'] ?: null,
                $values['phone'],
                $values['email'] ?: null,
                $values['address'] ?: null,
                $values['emergency_contact'] ?: null
            ]);

            $patientId = (int) $pdo->lastInsertId();

            logAction(
                $pdo,
                $_SESSION['user_id'],
                $patientId,
                'ADD_PATIENT',
                'Patient profile created'
            );

            $pdo->commit();

            $_SESSION['patient_success'] =
                'Patient saved successfully. Medical record number: ' . $mrn;

            $_SESSION['patient_form_token'] = bin2hex(random_bytes(32));

            header('Location: patients.php');
            exit;
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log($exception->getMessage());

            $error = 'The patient could not be saved. Please try again.';
        }
    }
}

$searchInput = $_GET['search'] ?? '';
$search = is_string($searchInput)
    ? substr(trim($searchInput), 0, 100)
    : '';

try {
    if ($search !== '') {
        $query = $pdo->prepare(
            "SELECT patient_id, mrn, full_name, dob, gender,
                    blood_group, phone
             FROM patients
             WHERE full_name LIKE ? OR mrn LIKE ? OR phone LIKE ?
             ORDER BY patient_id DESC
             LIMIT 100"
        );

        $term = '%' . $search . '%';
        $query->execute([$term, $term, $term]);
    } else {
        $query = $pdo->query(
            "SELECT patient_id, mrn, full_name, dob, gender,
                    blood_group, phone
             FROM patients
             ORDER BY patient_id DESC
             LIMIT 100"
        );
    }

    $patients = $query->fetchAll();
} catch (PDOException $exception) {
    error_log($exception->getMessage());
    $listError = 'Patient records could not be loaded.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Patient Management | MHMS</title>

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
            background: #102a3d;
            color: white;
            padding: 20px 5%;
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

        .topbar a {
            color: white;
            text-decoration: none;
            font-size: 14px;
        }

        main {
            max-width: 1400px;
            margin: auto;
            padding: 32px 24px;
        }

        .heading {
            margin-bottom: 28px;
        }

        .heading p {
            color: #62788a;
            line-height: 1.7;
        }

        h1 {
            margin: 0;
            font-size: 30px;
        }

        h2 {
            margin: 0 0 22px;
            font-size: 20px;
        }

        .layout {
            display: grid;
            grid-template-columns: 390px minmax(0, 1fr);
            gap: 24px;
            align-items: start;
        }

        .card {
            background: white;
            border: 1px solid #e1eaf0;
            border-radius: 18px;
            padding: 26px;
            min-width: 0;
            box-shadow: 0 5px 20px rgba(16, 42, 61, 0.03);
        }

        label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 7px;
        }

        input,
        select,
        textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #cad7e0;
            border-radius: 9px;
            background: white;
            color: #173247;
            font: inherit;
            font-size: 14px;
            margin-bottom: 17px;
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
            gap: 14px;
        }

        button {
            border: 0;
            padding: 13px 18px;
            border-radius: 9px;
            background: #087e8b;
            color: white;
            font: inherit;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
        }

        button:hover {
            background: #066772;
        }

        .save {
            width: 100%;
        }

        .search {
            display: flex;
            gap: 10px;
            align-items: start;
            margin-bottom: 10px;
        }

        .search input {
            min-width: 0;
        }

        .search button {
            flex-shrink: 0;
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
            background: #f3f7fa;
            color: #62788a;
            padding: 13px 10px;
            white-space: nowrap;
        }

        td {
            padding: 16px 10px;
            border-bottom: 1px solid #e1eaf0;
        }

        tbody tr:hover {
            background: #f8fbfc;
        }

        .mrn {
            font-family: monospace;
            font-size: 12px;
            white-space: nowrap;
        }

        .badge {
            display: inline-block;
            padding: 5px 8px;
            border-radius: 6px;
            background: #e7f4f4;
            color: #08727d;
            white-space: nowrap;
        }

        .alert {
            padding: 15px;
            border-radius: 10px;
            line-height: 1.6;
            margin-bottom: 20px;
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
            line-height: 1.7;
        }

        .clear {
            display: inline-block;
            margin-bottom: 16px;
            color: #087e8b;
            font-size: 13px;
        }

        @media (max-width: 1000px) {
            .layout {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 480px) {
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
        }
    </style>
</head>
<body>
    <header class="topbar">
        <div class="brand">MHMS</div>
        <a href="dashboard.php">Back to dashboard</a>
    </header>

    <main>
        <section class="heading">
            <h1>Patient management</h1>
            <p>
                Create and search patient profiles.
                Use fictional information for this academic project.
            </p>
        </section>

        <?php if ($success !== ''): ?>
            <div class="alert success" role="status">
                <?= patientEscape($success) ?>
            </div>
        <?php endif; ?>

        <div class="layout">
            <section class="card">
                <h2>Add a patient</h2>

                <?php if ($error !== ''): ?>
                    <div class="alert error" role="alert">
                        <?= patientEscape($error) ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="patients.php">
                    <input
                        type="hidden"
                        name="token"
                        value="<?= patientEscape(
                            $_SESSION['patient_form_token']
                        ) ?>"
                    >

                    <label for="full_name">Full name *</label>
                    <input
                        id="full_name"
                        name="full_name"
                        maxlength="100"
                        value="<?= patientEscape($values['full_name']) ?>"
                        required
                    >

                    <label for="dob">Date of birth *</label>
                    <input
                        id="dob"
                        name="dob"
                        type="date"
                        max="<?= date('Y-m-d') ?>"
                        value="<?= patientEscape($values['dob']) ?>"
                        required
                    >

                    <div class="row">
                        <div>
                            <label for="gender">Gender *</label>
                            <select id="gender" name="gender" required>
                                <option value="">Select</option>

                                <?php foreach ($genders as $gender): ?>
                                    <option
                                        value="<?= patientEscape($gender) ?>"
                                        <?= $values['gender'] === $gender
                                            ? 'selected' : '' ?>
                                    >
                                        <?= patientEscape($gender) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label for="blood_group">Blood group</label>
                            <select id="blood_group" name="blood_group">
                                <?php foreach ($bloodGroups as $group): ?>
                                    <option
                                        value="<?= patientEscape($group) ?>"
                                        <?= $values['blood_group'] === $group
                                            ? 'selected' : '' ?>
                                    >
                                        <?= $group === ''
                                            ? 'Unknown'
                                            : patientEscape($group) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <label for="phone">Phone *</label>
                    <input
                        id="phone"
                        name="phone"
                        type="tel"
                        minlength="7"
                        maxlength="20"
                        value="<?= patientEscape($values['phone']) ?>"
                        required
                    >

                    <label for="email">Email — optional</label>
                    <input
                        id="email"
                        name="email"
                        type="email"
                        maxlength="120"
                        value="<?= patientEscape($values['email']) ?>"
                    >

                    <label for="emergency_contact">
                        Emergency contact — optional
                    </label>
                    <input
                        id="emergency_contact"
                        name="emergency_contact"
                        maxlength="100"
                        placeholder="Contact name and phone"
                        value="<?= patientEscape(
                            $values['emergency_contact']
                        ) ?>"
                    >

                    <label for="address">Address — optional</label>
                    <textarea
                        id="address"
                        name="address"
                        rows="3"
                        maxlength="2000"
                    ><?= patientEscape($values['address']) ?></textarea>

                    <button type="submit" class="save">Save patient</button>

                    <p class="note">
                        * Required fields. This creates a patient record,
                        not a patient login account.
                    </p>
                </form>
            </section>

            <section class="card">
                <h2>Patient records</h2>

                <form method="GET" action="patients.php" class="search">
                    <input
                        type="search"
                        name="search"
                        aria-label="Search patients"
                        placeholder="Search by name, MRN, or phone"
                        maxlength="100"
                        value="<?= patientEscape($search) ?>"
                    >

                    <button type="submit">Search</button>
                </form>

                <?php if ($search !== ''): ?>
                    <a class="clear" href="patients.php">Clear search</a>
                <?php endif; ?>

                <?php if ($listError !== ''): ?>
                    <div class="alert error" role="alert">
                        <?= patientEscape($listError) ?>
                    </div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Medical record no.</th>
                                    <th>Name</th>
                                    <th>Gender</th>
                                    <th>Blood group</th>
                                    <th>Phone</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php if (!$patients): ?>
                                    <tr>
                                        <td colspan="5">
                                            No patients found.
                                            Add a fictional patient
                                            using the form.
                                        </td>
                                    </tr>
                                <?php endif; ?>

                                <?php foreach ($patients as $patient): ?>
                                    <tr>
                                        <td class="mrn">
                                            <?= patientEscape(
                                                $patient['mrn']
                                            ) ?>
                                        </td>

                                        <td>
                                            <?= patientEscape(
                                                $patient['full_name']
                                            ) ?>
                                        </td>

                                        <td>
                                            <?= patientEscape(
                                                $patient['gender']
                                            ) ?>
                                        </td>

                                        <td>
                                            <span class="badge">
                                                <?= patientEscape(
                                                    $patient['blood_group']
                                                    ?: 'Unknown'
                                                ) ?>
                                            </span>
                                        </td>

                                        <td>
                                            <?= patientEscape(
                                                $patient['phone']
                                            ) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <p class="note">
                        Showing up to 100 matching records, newest first.
                        Patient-profile viewing will be added next.
                    </p>
                <?php endif; ?>
            </section>
        </div>
    </main>
</body>
</html>