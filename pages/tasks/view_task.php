<?php
require_once '../../includes/functions.php';
require_once '../../config/database.php';
// Check if user is logged in
if (!isLoggedIn()) {
    setMessage('You must be logged in to view task details.', 'danger');
    redirect('../auth/login.php');
}
?>
