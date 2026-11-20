<?php

/*
 * MHMS database configuration
 *
 * Local XAMPP:
 * Uses the existing local database settings.
 *
 * Railway:
 * Uses the MYSQL* environment variables configured
 * on the PHP application service.
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/*
 * Check whether Railway database variables are available.
 */
$railwayHost = getenv('MYSQLHOST');

$usingRailwayDatabase = (
    $railwayHost !== false
    && trim($railwayHost) !== ''
);

/*
 * Detect Railway so that a missing database configuration
 * does not silently fall back to localhost online.
 */
$runningOnRailway = (
    getenv('RAILWAY_ENVIRONMENT_ID') !== false
    || getenv('RAILWAY_PROJECT_ID') !== false
);

if ($runningOnRailway && !$usingRailwayDatabase) {
    error_log(
        'MHMS configuration error: MYSQLHOST is missing '
        . 'from the Railway application service.'
    );

    http_response_code(503);

    exit(
        'Database configuration is incomplete. '
        . 'Please configure the database environment variables.'
    );
}

/*
 * Database connection settings.
 */
if ($usingRailwayDatabase) {
    $requiredVariables = [
        'MYSQLDATABASE',
        'MYSQLUSER',
        'MYSQLPASSWORD'
    ];

    foreach ($requiredVariables as $variableName) {
        $variableValue = getenv($variableName);

        if ($variableValue === false || $variableValue === '') {
            error_log(
                'MHMS configuration error: '
                . $variableName
                . ' is missing.'
            );

            http_response_code(503);

            exit(
                'Database configuration is incomplete. '
                . 'Please configure the database environment variables.'
            );
        }
    }

    define('DB_HOST', $railwayHost);
    define('DB_PORT', getenv('MYSQLPORT') ?: '3306');
    define('DB_NAME', getenv('MYSQLDATABASE'));
    define('DB_USER', getenv('MYSQLUSER'));
    define('DB_PASS', getenv('MYSQLPASSWORD'));
} else {
    /*
     * Local XAMPP settings.
     * These match the settings shown in your screenshot.
     */
    define('DB_HOST', 'localhost');
    define('DB_PORT', '3306');
    define('DB_NAME', 'mhms_db');
    define('DB_USER', 'root');
    define('DB_PASS', '');
}

/*
 * Connect to MySQL using PDO.
 */
try {
    $dsn = 'mysql:host=' . DB_HOST
        . ';port=' . DB_PORT
        . ';dbname=' . DB_NAME
        . ';charset=utf8mb4';

    $pdo = new PDO(
        $dsn,
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
} catch (PDOException $e) {
    /*
     * Record technical details in server logs.
     * Do not display credentials or technical errors
     * to visitors.
     */
    error_log(
        'MHMS database connection failed: '
        . $e->getMessage()
    );

    http_response_code(503);

    exit(
        'Database connection failed. '
        . 'Please check the database configuration '
        . 'and make sure the database server is running.'
    );
}

/*
 * Escape text for display in HTML.
 *
 * This helper is not a replacement for prepared
 * statements when working with SQL.
 */
function sanitize($value)
{
    return htmlspecialchars(
        trim((string) ($value ?? '')),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

/*
 * Record an action in the audit_logs table.
 *
 * The table must contain these columns:
 * user_id, patient_id, action, details, ip_address
 */
function logAction(
    $pdo,
    $userId,
    $patientId,
    $action,
    $details = ''
) {
    $stmt = $pdo->prepare(
        'INSERT INTO audit_logs
            (
                user_id,
                patient_id,
                action,
                details,
                ip_address
            )
         VALUES (?, ?, ?, ?, ?)'
    );

    $stmt->execute([
        $userId,
        $patientId,
        $action,
        $details,
        $_SERVER['REMOTE_ADDR'] ?? ''
    ]);
}
