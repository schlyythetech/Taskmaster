<?php
require_once '../../includes/functions.php';

// Check if user is logged in
if (isLoggedIn()) {
    // Redirect to dashboard
    redirect('../core/dashboard.php');
} else {
    // Redirect to login page
    redirect('../auth/login.php');
}
?>
