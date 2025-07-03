<?php
/**
 * Database Cleanup Script
 * 
 * This script identifies and fixes orphaned records in the database,
 * ensuring data integrity.
 */
require_once '../includes/functions.php';
require_once '../config/database.php';
require_once '../includes/db_utils.php';

// Check if user is logged in and is admin
if (!isLoggedIn() || $_SESSION['role'] !== 'admin') {
    setMessage("You must be logged in as an administrator to perform this action.", "danger");
    redirect('../login.php');
    exit;
}

// Default mode is dry run (only report issues)
$dryRun = true;
$results = null;

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        setMessage("Invalid security token. Please try again.", "danger");
        redirect('../admin.php');
        exit;
    }
    
    // Check if fix mode is enabled
    $dryRun = !isset($_POST['fix_mode']) || $_POST['fix_mode'] !== '1';
    
    // Run the cleanup
    $results = DbUtils::cleanupOrphanedRecords($conn, $dryRun);
    
    // Set message based on results
    if ($results['success']) {
        if ($dryRun) {
            setMessage("Database scan completed. Found " . 
                       $results['orphaned_connections'] . " orphaned connections and " . 
                       $results['orphaned_notifications'] . " orphaned notifications.", 
                       "info");
        } else {
            setMessage("Database cleanup completed. Fixed " . 
                       $results['orphaned_connections'] . " orphaned connections and " . 
                       $results['orphaned_notifications'] . " orphaned notifications.", 
                       "success");
        }
    } else {
        setMessage("Error during database cleanup: " . ($results['error'] ?? 'Unknown error'), "danger");
    }
}

// Run a scan if results are not already set
if ($results === null) {
    $results = DbUtils::cleanupOrphanedRecords($conn, true);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Cleanup - TaskMaster Admin</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    
    <style>
        .problem-details {
            max-height: 400px;
            overflow-y: auto;
            margin-top: 20px;
        }
        .icon-success {
            color: #198754;
        }
        .icon-warning {
            color: #ffc107;
        }
        .icon-danger {
            color: #dc3545;
        }
    </style>
</head>
<body>
    <?php include '../includes/navbar.php'; ?>
    
    <div class="container py-4">
        <div class="row mb-4">
            <div class="col-md-12">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="../dashboard.php">Dashboard</a></li>
                        <li class="breadcrumb-item"><a href="../admin.php">Admin Panel</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Database Cleanup</li>
                    </ol>
                </nav>
                
                <h1 class="mb-4">Database Cleanup</h1>
                
                <?php displayMessage(); ?>
                
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h2 class="h4 mb-0">Database Integrity Check</h2>
                    </div>
                    <div class="card-body">
                        <p>This tool identifies and fixes orphaned records in the database, ensuring data integrity.</p>
                        
                        <div class="row g-4 mb-4">
                            <div class="col-md-6">
                                <div class="card h-100">
                                    <div class="card-body text-center">
                                        <h3 class="h5 mb-3">Connections</h3>
                                        <div class="display-4 mb-3">
                                            <?php if ($results['orphaned_connections'] > 0): ?>
                                                <i class="fas fa-exclamation-triangle icon-warning"></i>
                                            <?php else: ?>
                                                <i class="fas fa-check-circle icon-success"></i>
                                            <?php endif; ?>
                                        </div>
                                        <p class="mb-0"><?php echo $results['orphaned_connections']; ?> orphaned connections found</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card h-100">
                                    <div class="card-body text-center">
                                        <h3 class="h5 mb-3">Notifications</h3>
                                        <div class="display-4 mb-3">
                                            <?php if ($results['orphaned_notifications'] > 0): ?>
                                                <i class="fas fa-exclamation-triangle icon-warning"></i>
                                            <?php else: ?>
                                                <i class="fas fa-check-circle icon-success"></i>
                                            <?php endif; ?>
                                        </div>
                                        <p class="mb-0"><?php echo $results['orphaned_notifications']; ?> orphaned notifications found</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <?php if (!empty($results['details'])): ?>
                            <h3 class="h5 mb-3">Problem Details</h3>
                            <div class="problem-details">
                                <table class="table table-bordered table-striped">
                                    <thead>
                                        <tr>
                                            <th>Type</th>
                                            <th>ID</th>
                                            <th>Details</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($results['details'] as $detail): ?>
                                            <tr>
                                                <td>
                                                    <?php if ($detail['type'] === 'connection'): ?>
                                                        <span class="badge bg-warning">Connection</span>
                                                    <?php elseif ($detail['type'] === 'notification_recipient'): ?>
                                                        <span class="badge bg-info">Notification (Recipient)</span>
                                                    <?php elseif ($detail['type'] === 'notification_sender'): ?>
                                                        <span class="badge bg-secondary">Notification (Sender)</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-dark"><?php echo htmlspecialchars($detail['type']); ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo htmlspecialchars($detail['id']); ?></td>
                                                <td>
                                                    <?php if ($detail['type'] === 'connection'): ?>
                                                        Connection between User ID <?php echo htmlspecialchars($detail['user_id']); ?> 
                                                        and User ID <?php echo htmlspecialchars($detail['connected_user_id']); ?>
                                                    <?php elseif ($detail['type'] === 'notification_recipient'): ?>
                                                        <?php echo htmlspecialchars($detail['notification_type']); ?> notification for 
                                                        non-existent User ID <?php echo htmlspecialchars($detail['user_id']); ?>
                                                    <?php elseif ($detail['type'] === 'notification_sender'): ?>
                                                        <?php echo htmlspecialchars($detail['notification_type']); ?> notification from 
                                                        non-existent User ID <?php echo htmlspecialchars($detail['related_user_id']); ?>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle me-2"></i> No database integrity issues found.
                            </div>
                        <?php endif; ?>
                        
                        <form method="post" action="">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            
                            <?php if (!empty($results['details'])): ?>
                                <div class="form-check mb-3">
                                    <input class="form-check-input" type="checkbox" id="fix_mode" name="fix_mode" value="1">
                                    <label class="form-check-label" for="fix_mode">
                                        <strong>Apply fixes</strong> (otherwise, just scan and report issues)
                                    </label>
                                </div>
                                
                                <button type="submit" class="btn btn-primary">
                                    <?php if ($dryRun): ?>
                                        <i class="fas fa-search me-2"></i> Scan Again
                                    <?php else: ?>
                                        <i class="fas fa-wrench me-2"></i> Fix Issues
                                    <?php endif; ?>
                                </button>
                            <?php else: ?>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-sync-alt me-2"></i> Refresh Scan
                                </button>
                            <?php endif; ?>
                            
                            <a href="../admin.php" class="btn btn-outline-secondary">
                                <i class="fas fa-arrow-left me-2"></i> Back to Admin Panel
                            </a>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <?php include '../includes/footer.php'; ?>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 