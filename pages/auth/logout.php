<?php
require_once '../../includes/functions.php';
require_once '../../includes/session_functions.php';
require_once '../../config/database.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Record user logout if user is logged in
if (isset($_SESSION['user_id'])) {
    recordUserLogout($conn, $_SESSION['user_id']);
}

// Unset all session variables
$_SESSION = array();

// If it's desired to kill the session, also delete the session cookie.
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Finally, destroy the session.
session_destroy();

// Set a logout message
setMessage("You have been successfully logged out.", "success");

// Redirect to login page
redirect('../auth/login.php');
?> 