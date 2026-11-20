<?php
require_once __DIR__ . '/config/db.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

function homeEscape($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

try {
    $query = $pdo->prepare(
        'SELECT full_name, role, status
         FROM users WHERE user_id = ?'
    );

    $query->execute([$_SESSION['user_id']]);
    $account = $query->fetch();

} catch (PDOException $exception) {
    error_log($exception->getMessage());
    http_response_code(500);
    exit('Your account could not be checked. Please try again.');
}

if (!$account || $account['status'] !== 'active') {
    $_SESSION = [];
    session_destroy();

    header('Location: login.php');
    exit;
}

$role = $account['role'];

$roles = [
    'admin' => 'Administrator',
    'doctor' => 'Doctor',
    'patient' => 'Patient'
];

$dashboards = [
    'admin' => 'dashboard.php',
    'doctor' => 'doctor_dashboard.php',
    'patient' => 'patient_dashboard.php'
];

if (!isset($roles[$role])) {
    http_response_code(403);
    exit('This account role is not supported.');
}

if (empty($_SESSION['logout_token'])) {
    $_SESSION['logout_token'] = bin2hex(random_bytes(32));
}

// Each module: title, page, description, category.
$adminModules = [
    [
        'Patients',
        'patients.php',
        'Add and search fictional patient records.',
        'Patient records'
    ],
    [
        'Patient profiles',
        'patient_view.php',
        'Review history, allergies, consultations, prescriptions, and reports.',
        'Patient records'
    ],
    [
        'Edit patient details',
        'patient_edit.php',
        'Correct demographic and contact information.',
        'Patient records'
    ],
    [
        'Medical history',
        'history.php',
        'Add a patient’s recorded background history.',
        'Patient records'
    ],
    [
        'Allergies',
        'allergies.php',
        'Add recorded allergy entries.',
        'Patient records'
    ],
    [
        'Medical timeline',
        'timeline.php',
        'Browse patient records together and filter by type.',
        'Patient records'
    ],
    [
        'Medical summary',
        'medical_summary.php',
        'Open a printable summary and save a demonstration PDF.',
        'Documents'
    ],
    [
        'Lab reports',
        'report_upload.php',
        'Upload and download protected fictional reports.',
        'Documents'
    ],
    [
        'Doctor accounts',
        'doctors.php',
        'Create and list doctor login accounts.',
        'Administration'
    ],
    [
        'Patient login accounts',
        'patient_accounts.php',
        'Link an account to an existing patient record.',
        'Administration'
    ],
    [
        'Assign patient to doctor',
        'assignments.php',
        'Create a patient–doctor assignment.',
        'Administration'
    ],
    [
        'Remove assignment',
        'assignment_remove.php',
        'Withdraw an assignment without deleting clinical records.',
        'Administration'
    ],
    [
        'Account activation',
        'accounts.php',
        'Activate or deactivate doctor and patient logins.',
        'Administration'
    ],
    [
        'Appointments',
        'appointments.php',
        'Book, cancel, or mark appointments completed.',
        'Scheduling'
    ],
    [
        'Reschedule appointment',
        'appointment_reschedule.php',
        'Move a Scheduled appointment to a new available time.',
        'Scheduling'
    ],
    [
        'Follow-up dates',
        'followups.php',
        'Review dates recorded in consultation follow-up fields.',
        'Scheduling'
    ],
    [
        'Analytics',
        'analytics.php',
        'View monthly consultation charts and appointment status counts.',
        'Analytics'
    ],
    [
        'Audit logs',
        'audit_logs.php',
        'Filter recorded application actions by user, patient, and date.',
        'Security'
    ],
    [
        'Change password',
        'change_password.php',
        'Change your own password using your current password.',
        'Security'
    ]
];

$doctorModules = [
    [
        'Assigned patient profiles',
        'patient_view.php',
        'Review information for your currently assigned patients.',
        'Patient records'
    ],
    [
        'Medical timeline',
        'timeline.php',
        'Browse assigned patients’ recorded events.',
        'Patient records'
    ],
    [
        'Add consultation',
        'visit_add.php',
        'Record a visit for an assigned patient.',
        'Clinical records'
    ],
    [
        'Prescription builder',
        'prescription_add.php',
        'Add or review entries for your eligible consultations.',
        'Clinical records'
    ],
    [
        'Print prescription',
        'prescription_print.php',
        'Print or save a demonstration prescription.',
        'Documents'
    ],
    [
        'Lab reports',
        'report_upload.php',
        'Upload or download reports for assigned patients.',
        'Documents'
    ],
    [
        'Medical summary',
        'medical_summary.php',
        'Print a summary for an assigned patient.',
        'Documents'
    ],
    [
        'Appointments',
        'appointments.php',
        'View your appointments for currently assigned patients.',
        'Scheduling'
    ],
    [
        'Follow-up dates',
        'followups.php',
        'Review follow-ups from your eligible consultations.',
        'Scheduling'
    ],
    [
        'Your analytics',
        'analytics.php',
        'View aggregate figures for your recorded activity.',
        'Analytics'
    ],
    [
        'Change password',
        'change_password.php',
        'Change your own account password.',
        'Security'
    ]
];

$patientModules = [
    [
        'My records',
        'patient_dashboard.php',
        'View your recorded details, history, and consultations.',
        'My records'
    ],
    [
        'My prescriptions',
        'patient_dashboard.php#prescriptions',
        'Review historical prescription entries.',
        'My records'
    ],
    [
        'My medical timeline',
        'timeline.php',
        'Browse your recorded events by type.',
        'My records'
    ],
    [
        'My reports',
        'patient_dashboard.php#reports',
        'Download reports linked to your own patient profile.',
        'Documents'
    ],
    [
        'My medical summary',
        'medical_summary.php',
        'Print or save your demonstration summary.',
        'Documents'
    ],
    [
        'My appointments',
        'patient_dashboard.php#appointments',
        'View your appointments and their recorded statuses.',
        'Scheduling'
    ],
    [
        'Change password',
        'change_password.php',
        'Change your own account password.',
        'Security'
    ]
];

$moduleLists = [
    'admin' => $adminModules,
    'doctor' => $doctorModules,
    'patient' => $patientModules
];

$modules = $moduleLists[$role];

$categories = array_values(array_unique(array_column($modules, 3)));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Workspace | MHMS</title>

    <script>
        try {
            const saved = localStorage.getItem('mhms-workspace-theme');

            const darkPreferred = window.matchMedia(
                '(prefers-color-scheme: dark)'
            ).matches;

            document.documentElement.dataset.theme =
                saved === 'light' || saved === 'dark'
                    ? saved
                    : (darkPreferred ? 'dark' : 'light');

        } catch (error) {
            document.documentElement.dataset.theme = 'light';
        }
    </script>

    <style>
        :root {
            color-scheme: light;
            --bg: #f3f7fa;
            --surface: #ffffff;
            --text: #173247;
            --muted: #62788a;
            --border: #dde7ee;
            --accent: #087e8b;
            --soft: #e4f1f3;
            --soft-text: #086572;
            --input: #ffffff;
        }

        :root[data-theme="dark"] {
            color-scheme: dark;
            --bg: #0c1722;
            --surface: #142432;
            --text: #e4eef5;
            --muted: #a4b8c8;
            --border: #2a4052;
            --accent: #168c99;
            --soft: #193c46;
            --soft-text: #a4e0e5;
            --input: #10202d;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font-family: "Segoe UI", Arial, sans-serif;
        }

        .topbar {
            padding: 20px 5%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 18px;
            background: #102a3d;
            color: white;
        }

        .brand {
            font-size: 24px;
            font-weight: 800;
            letter-spacing: 3px;
        }

        .header-actions {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }

        .header-button {
            display: inline-block;
            padding: 10px 14px;
            border: 1px solid #527183;
            border-radius: 8px;
            background: transparent;
            color: white;
            font: inherit;
            font-size: 13px;
            text-decoration: none;
            cursor: pointer;
        }

        .header-button:hover {
            background: #24495e;
        }

        main {
            max-width: 1350px;
            margin: auto;
            padding: 32px 24px;
        }

        .hero {
            padding: 32px;
            margin-bottom: 26px;
            border-radius: 22px;
            background: linear-gradient(115deg, #087e8b, #10465e);
            color: white;
        }

        .eyebrow {
            margin: 0 0 12px;
            color: #c4e9ed;
            font-size: 12px;
            letter-spacing: 1.5px;
            text-transform: uppercase;
        }

        h1 {
            margin: 0 0 14px;
            font-size: clamp(25px, 4vw, 36px);
        }

        .hero p:last-child {
            max-width: 750px;
            margin: 0;
            color: #def0f1;
            line-height: 1.8;
        }

        .filter-panel {
            padding: 24px;
            margin-bottom: 24px;
            border: 1px solid var(--border);
            border-radius: 16px;
            background: var(--surface);
        }

        .filters {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 18px;
        }

        label {
            display: block;
            margin-bottom: 9px;
            font-size: 14px;
            font-weight: 600;
        }

        input, select {
            width: 100%;
            padding: 13px;
            border: 1px solid var(--border);
            border-radius: 9px;
            background: var(--input);
            color: var(--text);
            font: inherit;
            font-size: 14px;
        }

        input:focus, select:focus {
            outline: 3px solid var(--soft);
            border-color: var(--accent);
        }

        .modules {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 18px;
        }

        .module {
            display: flex;
            flex-direction: column;
            align-items: start;
            padding: 25px;
            border: 1px solid var(--border);
            border-radius: 17px;
            background: var(--surface);
            color: var(--text);
            text-decoration: none;
            transition: transform 0.2s, border-color 0.2s;
        }

        .module:hover {
            transform: translateY(-3px);
            border-color: var(--accent);
        }

        .module:focus-visible {
            outline: 3px solid var(--accent);
            outline-offset: 3px;
        }

        .category {
            padding: 5px 8px;
            margin-bottom: 17px;
            border-radius: 6px;
            background: var(--soft);
            color: var(--soft-text);
            font-size: 11px;
        }

        .module h2 {
            margin: 0 0 12px;
            font-size: 19px;
        }

        .module p {
            margin: 0 0 20px;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.8;
        }

        .open {
            margin-top: auto;
            color: var(--soft-text);
            font-size: 13px;
            font-weight: 600;
        }

        .note {
            color: var(--muted);
            font-size: 12px;
            line-height: 1.8;
        }

        .empty {
            padding: 28px;
            border: 1px solid var(--border);
            border-radius: 15px;
            background: var(--surface);
            color: var(--muted);
        }

        footer {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid var(--border);
        }

        [hidden] { display: none !important; }

        @media (max-width: 1000px) {
            .modules {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 600px) {
            main { padding: 24px 14px; }
            .hero { padding: 24px; }
            .modules, .filters { grid-template-columns: 1fr; }
            .module { padding: 22px; }
        }

        @media (prefers-reduced-motion: reduce) {
            .module { transition: none; }
            .module:hover { transform: none; }
        }
    </style>
</head>
<body>
    <header class="topbar">
        <div class="brand">MHMS</div>

        <div class="header-actions">
            <a
                class="header-button"
                href="<?= homeEscape($dashboards[$role]) ?>"
            >
                Dashboard
            </a>

            <button
                id="themeToggle"
                type="button"
                class="header-button"
                aria-pressed="false"
                hidden
            >
                Dark theme
            </button>

            <form method="POST" action="logout.php">
                <input
                    type="hidden"
                    name="token"
                    value="<?= homeEscape($_SESSION['logout_token']) ?>"
                >

                <button class="header-button" type="submit">
                    Sign out
                </button>
            </form>
        </div>
    </header>

    <main>
        <section class="hero">
            <p class="eyebrow">
                <?= homeEscape($roles[$role]) ?> workspace
            </p>

            <h1>
                Welcome, <?= homeEscape($account['full_name']) ?>.
            </h1>

            <p>
                Your available project modules are collected here.
                Search by feature name or choose a category.
            </p>
        </section>

        <section class="filter-panel" id="filterPanel" hidden>
            <div class="filters">
                <div>
                    <label for="moduleSearch">Find a feature</label>

                    <input
                        id="moduleSearch"
                        type="search"
                        placeholder="Search, for example: appointments"
                        autocomplete="off"
                    >
                </div>

                <div>
                    <label for="categoryFilter">Category</label>

                    <select id="categoryFilter">
                        <option value="">All categories</option>

                        <?php foreach ($categories as $category): ?>
                            <option value="<?= homeEscape($category) ?>">
                                <?= homeEscape($category) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <p class="note" id="resultCount" role="status" aria-live="polite">
                <?= count($modules) ?> features available.
            </p>
        </section>

        <section class="modules" aria-label="Available features">
            <?php foreach ($modules as $module): ?>
                <a
                    class="module"
                    href="<?= homeEscape($module[1]) ?>"
                    data-category="<?= homeEscape($module[3]) ?>"
                    data-search="<?= homeEscape(
                        strtolower(
                            $module[0] . ' ' . $module[2] . ' ' . $module[3]
                        )
                    ) ?>"
                >
                    <span class="category">
                        <?= homeEscape($module[3]) ?>
                    </span>

                    <h2><?= homeEscape($module[0]) ?></h2>

                    <p><?= homeEscape($module[2]) ?></p>

                    <span class="open">Open feature →</span>
                </a>
            <?php endforeach; ?>
        </section>

        <div class="empty" id="emptyState" hidden>
            No matching feature found.
            Clear the search or choose another category.
        </div>

        <footer>
            <p class="note">
                Academic demonstration · Fictional data only.
                Each module checks its own access permissions.
                The theme setting applies to this workspace page.
                Existing module pages retain their own styling.
            </p>
        </footer>
    </main>

    <script>
        const themeButton = document.getElementById('themeToggle');

        function updateThemeButton() {
            const dark =
                document.documentElement.dataset.theme === 'dark';

            themeButton.textContent = dark ? 'Light theme' : 'Dark theme';
            themeButton.setAttribute('aria-pressed', String(dark));
        }

        themeButton.hidden = false;
        updateThemeButton();

        themeButton.addEventListener('click', () => {
            const nextTheme =
                document.documentElement.dataset.theme === 'dark'
                    ? 'light'
                    : 'dark';

            document.documentElement.dataset.theme = nextTheme;

            try {
                localStorage.setItem(
                    'mhms-workspace-theme',
                    nextTheme
                );
            } catch (error) {
                // Theme still changes when storage is unavailable.
            }

            updateThemeButton();
        });

        const search = document.getElementById('moduleSearch');
        const category = document.getElementById('categoryFilter');
        const cards = [...document.querySelectorAll('.module')];
        const count = document.getElementById('resultCount');
        const empty = document.getElementById('emptyState');

        document.getElementById('filterPanel').hidden = false;

        function filterModules() {
            const term = search.value.trim().toLowerCase();
            const selectedCategory = category.value;
            let visible = 0;

            cards.forEach(card => {
                const matchesSearch =
                    card.dataset.search.includes(term);

                const matchesCategory =
                    selectedCategory === ''
                    || card.dataset.category === selectedCategory;

                const matches = matchesSearch && matchesCategory;

                card.hidden = !matches;

                if (matches) visible++;
            });

            count.textContent =
                visible + ' of ' + cards.length + ' features shown.';

            empty.hidden = visible !== 0;
        }

        search.addEventListener('input', filterModules);
        category.addEventListener('change', filterModules);
    </script>
</body>
</html>