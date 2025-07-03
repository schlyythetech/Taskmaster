<?php
require_once '../../includes/functions.php';
require_once '../../config/database.php';

// Check if user is logged in and is admin
if (!isLoggedIn() || !isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
    setMessage("You must be logged in as an administrator to access this page.", "danger");
    redirect('../auth/login.php');
    exit;
}

// Set page title
$page_title = "Admin Dashboard";
include '../../includes/header.php';
?>

<div class="container mt-4">
    <h2>Administration Dashboard</h2>
    
    <div class="admin-section">
        <h3>Database Management</h3>
        <div class="card-group">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">Database Cleanup</h5>
                    <p class="card-text">Identify and fix orphaned records in the database.</p>
                    <a href="admin/db_cleanup.php" class="btn btn-primary">Run Cleanup Tool</a>
                </div>
            </div>
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">Database Integrity</h5>
                    <p class="card-text">Check and fix database integrity issues.</p>
                    <a href="fix_db_integrity.php?dry_run=1" class="btn btn-secondary">Check Integrity</a>
                    <a href="fix_db_integrity.php" class="btn btn-primary">Fix Integrity Issues</a>
                </div>
            </div>
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">Database Triggers</h5>
                    <p class="card-text">Update database triggers for data integrity.</p>
                    <a href="update_db_triggers.php" class="btn btn-primary">Update Triggers</a>
                </div>
            </div>
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">System Diagnostics</h5>
                    <p class="card-text">Check database connection and PHP configuration.</p>
                    <a href="check_db.php" class="btn btn-primary">Run Diagnostics</a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?> 