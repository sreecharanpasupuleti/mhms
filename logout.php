<?php
require_once __DIR__ . '/config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Use the Sign out button to log out.');
}

$token = $_POST['token'] ?? '';

if (
    !is_string($token)
    || empty($_SESSION['logout_token'])
    || !hash_equals($_SESSION['logout_token'], $token)
) {
    http_response_code(403);
    exit('The sign-out form expired. Go back, refresh, and try again.');
}

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $parameters = session_get_cookie_params();

    setcookie(
        session_name(),
        '',
        time() - 42000,
        $parameters['path'],
        $parameters['domain'],
        $parameters['secure'],
        $parameters['httponly']
    );
}

session_destroy();

header('Location: login.php');
exit;