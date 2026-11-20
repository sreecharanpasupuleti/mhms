<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$currentRole = $_SESSION['role'] ?? '';
$currentPage = basename($_SERVER['PHP_SELF'] ?? '');

$navDashboards = [
    'admin' => 'dashboard.php',
    'doctor' => 'doctor_dashboard.php',
    'patient' => 'patient_dashboard.php'
];

$navLabels = [
    'admin' => 'Administrator',
    'doctor' => 'Doctor',
    'patient' => 'Patient'
];

$navModules = [
    'admin' => [
        ['Workspace', 'index.php'],
        ['Dashboard', 'dashboard.php'],
        ['Patients', 'patients.php'],
        ['Patient profiles', 'patient_view.php'],
        ['Edit patient details', 'patient_edit.php'],
        ['Medical history', 'history.php'],
        ['Allergies', 'allergies.php'],
        ['Timeline', 'timeline.php'],
        ['Medical summary', 'medical_summary.php'],
        ['Lab reports', 'report_upload.php'],
        ['Doctors', 'doctors.php'],
        ['Patient accounts', 'patient_accounts.php'],
        ['Assignments', 'assignments.php'],
        ['Remove assignment', 'assignment_remove.php'],
        ['Account status', 'accounts.php'],
        ['Appointments', 'appointments.php'],
        ['Reschedule appointment', 'appointment_reschedule.php'],
        ['Follow-ups', 'followups.php'],
        ['Analytics', 'analytics.php'],
        ['Audit logs', 'audit_logs.php'],
        ['Change password', 'change_password.php']
    ],

    'doctor' => [
        ['Workspace', 'index.php'],
        ['Dashboard', 'doctor_dashboard.php'],
        ['Patient profiles', 'patient_view.php'],
        ['Timeline', 'timeline.php'],
        ['Add consultation', 'visit_add.php'],
        ['Prescription builder', 'prescription_add.php'],
        ['Print prescription', 'prescription_print.php'],
        ['Lab reports', 'report_upload.php'],
        ['Medical summary', 'medical_summary.php'],
        ['Appointments', 'appointments.php'],
        ['Follow-ups', 'followups.php'],
        ['Analytics', 'analytics.php'],
        ['Change password', 'change_password.php']
    ],

    'patient' => [
        ['Workspace', 'index.php'],
        ['My records', 'patient_dashboard.php'],
        ['My timeline', 'timeline.php'],
        ['Medical summary', 'medical_summary.php'],
        ['Change password', 'change_password.php']
    ]
];

if (
    isset($navDashboards[$currentRole])
    && isset($navModules[$currentRole])
) {
    $navEscape = static function ($value) {
        return htmlspecialchars(
            (string) $value,
            ENT_QUOTES,
            'UTF-8'
        );
    };
    ?>
    <style>
        .mhms-nav,
        .mhms-nav * {
            box-sizing: border-box;
        }

        .mhms-nav {
            padding: 15px 5%;
            background: #102a3d;
            color: #ffffff;
            font-family: "Segoe UI", Arial, sans-serif;
        }

        .mhms-nav-inner {
            max-width: 1500px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            flex-wrap: wrap;
        }

        .mhms-nav-brand {
            color: #ffffff;
            font-size: 20px;
            font-weight: 800;
            letter-spacing: 2px;
            text-decoration: none;
            white-space: nowrap;
        }

        .mhms-nav-links {
            display: flex;
            align-items: center;
            gap: 7px;
            flex-wrap: wrap;
        }

        .mhms-nav-link,
        .mhms-nav-signout {
            display: inline-block;
            padding: 8px 10px;
            border: 1px solid transparent;
            border-radius: 7px;
            background: transparent;
            color: #e5eef4;
            font: inherit;
            font-size: 12px;
            line-height: 1.4;
            text-decoration: none;
            white-space: nowrap;
        }

        .mhms-nav-link:hover,
        .mhms-nav-link:focus-visible,
        .mhms-nav-signout:hover,
        .mhms-nav-signout:focus-visible {
            border-color: #658293;
            background: #24495e;
            color: #ffffff;
        }

        .mhms-nav-link[aria-current="page"] {
            background: #087e8b;
            color: #ffffff;
        }

        .mhms-nav-signout {
            border-color: #658293;
            cursor: pointer;
        }

        @media (max-width: 650px) {
            .mhms-nav-inner {
                align-items: flex-start;
                flex-direction: column;
            }

            .mhms-nav-links {
                width: 100%;
            }
        }
    </style>

    <header class="mhms-nav">
        <div class="mhms-nav-inner">
            <a
                class="mhms-nav-brand"
                href="<?= $navEscape($navDashboards[$currentRole]) ?>"
            >
                MHMS
            </a>

            <nav
                class="mhms-nav-links"
                aria-label="<?= $navEscape(
                    $navLabels[$currentRole]
                ) ?> navigation"
            >
                <?php foreach ($navModules[$currentRole] as $item): ?>
                    <a
                        class="mhms-nav-link"
                        href="<?= $navEscape($item[1]) ?>"
                        <?= $currentPage === $item[1]
                            ? 'aria-current="page"'
                            : '' ?>
                    >
                        <?= $navEscape($item[0]) ?>
                    </a>
                <?php endforeach; ?>

                <?php if (!empty($_SESSION['logout_token'])): ?>
                    <form method="POST" action="logout.php">
                        <input
                            type="hidden"
                            name="token"
                            value="<?= $navEscape(
                                $_SESSION['logout_token']
                            ) ?>"
                        >

                        <button
                            type="submit"
                            class="mhms-nav-signout"
                        >
                            Sign out
                        </button>
                    </form>
                <?php endif; ?>
            </nav>
        </div>
    </header>
    <?php
}
?>