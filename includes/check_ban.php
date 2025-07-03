<?php
/**
 * Check Ban Status
 * 
 * This file checks if the current logged-in user is banned.
 * If they are, they are logged out and redirected to the login page.
 */

// Make sure we have the database connection and functions available
if (!function_exists('isLoggedIn') || !function_exists('isUserBanned') || !isset($conn)) {
    return;
}

// Only check ban status if user is logged in
if (isLoggedIn()) {
    $user_id = $_SESSION['user_id'];
    
    // Check if user is banned
    if (isUserBanned($conn, $user_id)) {
        // Record user logout
        if (function_exists('recordUserLogout')) {
            recordUserLogout($conn, $user_id);
        }
        
        // Log the user out
        session_unset();
        session_destroy();
        
        // Start a new session for the message
        session_start();
        
        // Redirect to login page with message
        setMessage("Your account has been suspended. Please contact the administrator for more information.", "danger");
        
        // Determine the correct path to redirect to
        $current_path = $_SERVER['PHP_SELF'];
        if (strpos($current_path, '/admin/') !== false) {
            header("Location: ../pages/auth/login.php");
        } elseif (strpos($current_path, '/pages/') !== false) {
            header("Location: ../auth/login.php");
        } else {
            header("Location: pages/auth/login.php");
        }
        exit;
    }
}
?> 