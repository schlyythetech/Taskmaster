<?php
/**
 * Database Integrity Checker and Fixer
 * 
 * This script checks for and fixes various database integrity issues:
 * - Orphaned connections (where one or both users don't exist)
 * - Orphaned notifications (where related users don't exist)
 * - Orphaned project members (where the user or project doesn't exist)
 * - Orphaned tasks (where the project doesn't exist)
 */

require_once '../../includes/functions.php';
require_once '../../config/database.php';
require_once '../../includes/db_utils.php';

// Check if user is logged in as admin
if (!isLoggedIn() || !isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
    setMessage("You must be logged in as an administrator to access this page.", "danger");
    redirect('../auth/login.php');
    exit;
}

// Set header for command-line output
header('Content-Type: text/plain');

// Parse command line arguments
$dryRun = isset($_GET['dry_run']) && $_GET['dry_run'] === '1';
$verbose = isset($_GET['verbose']) && $_GET['verbose'] === '1';

echo "Database Integrity Checker and Fixer\n";
echo "====================================\n\n";

if ($dryRun) {
    echo "RUNNING IN DRY RUN MODE - No changes will be made\n\n";
}

// Stats counters
$stats = [
    'orphaned_connections_found' => 0,
    'orphaned_connections_fixed' => 0,
    'orphaned_notifications_found' => 0,
    'orphaned_notifications_fixed' => 0,
    'orphaned_project_members_found' => 0,
    'orphaned_project_members_fixed' => 0,
    'orphaned_tasks_found' => 0,
    'orphaned_tasks_fixed' => 0
];

try {
    // Begin transaction if not in dry run mode
    if (!$dryRun) {
        $conn->beginTransaction();
    }
    
    // 1. Check for orphaned connections
    echo "Checking for orphaned connections...\n";
    $stmt = $conn->prepare("
        SELECT c.connection_id, c.user_id, c.connected_user_id
        FROM connections c
        LEFT JOIN users u1 ON c.user_id = u1.user_id
        LEFT JOIN users u2 ON c.connected_user_id = u2.user_id
        WHERE u1.user_id IS NULL OR u2.user_id IS NULL
    ");
    $stmt->execute();
    $orphanedConnections = $stmt->fetchAll();
    
    $stats['orphaned_connections_found'] = count($orphanedConnections);
    echo "Found {$stats['orphaned_connections_found']} orphaned connections\n";
    
    if ($stats['orphaned_connections_found'] > 0 && !$dryRun) {
        $deleteStmt = $conn->prepare("DELETE FROM connections WHERE connection_id = ?");
        
        foreach ($orphanedConnections as $connection) {
            if ($verbose) {
                echo "Deleting connection {$connection['connection_id']} between users {$connection['user_id']} and {$connection['connected_user_id']}\n";
            }
            $deleteStmt->execute([$connection['connection_id']]);
            $stats['orphaned_connections_fixed']++;
        }
    }
    
    // 2. Check for orphaned notifications
    echo "\nChecking for orphaned notifications...\n";
    $stmt = $conn->prepare("
        SELECT n.notification_id, n.user_id, n.related_user_id, n.type
        FROM notifications n
        LEFT JOIN users u ON n.user_id = u.user_id
        WHERE u.user_id IS NULL
        UNION
        SELECT n.notification_id, n.user_id, n.related_user_id, n.type
        FROM notifications n
        LEFT JOIN users u ON n.related_user_id = u.user_id
        WHERE n.related_user_id IS NOT NULL AND u.user_id IS NULL
    ");
    $stmt->execute();
    $orphanedNotifications = $stmt->fetchAll();
    
    $stats['orphaned_notifications_found'] = count($orphanedNotifications);
    echo "Found {$stats['orphaned_notifications_found']} orphaned notifications\n";
    
    if ($stats['orphaned_notifications_found'] > 0 && !$dryRun) {
        $deleteStmt = $conn->prepare("DELETE FROM notifications WHERE notification_id = ?");
        
        foreach ($orphanedNotifications as $notification) {
            if ($verbose) {
                echo "Deleting notification {$notification['notification_id']} of type {$notification['type']}\n";
            }
            $deleteStmt->execute([$notification['notification_id']]);
            $stats['orphaned_notifications_fixed']++;
        }
    }
    
    // 3. Check for orphaned project members
    echo "\nChecking for orphaned project members...\n";
    $stmt = $conn->prepare("
        SELECT pm.id, pm.project_id, pm.user_id
        FROM project_members pm
        LEFT JOIN users u ON pm.user_id = u.user_id
        WHERE u.user_id IS NULL
        UNION
        SELECT pm.id, pm.project_id, pm.user_id
        FROM project_members pm
        LEFT JOIN projects p ON pm.project_id = p.project_id
        WHERE p.project_id IS NULL
    ");
    $stmt->execute();
    $orphanedMembers = $stmt->fetchAll();
    
    $stats['orphaned_project_members_found'] = count($orphanedMembers);
    echo "Found {$stats['orphaned_project_members_found']} orphaned project members\n";
    
    if ($stats['orphaned_project_members_found'] > 0 && !$dryRun) {
        $deleteStmt = $conn->prepare("DELETE FROM project_members WHERE id = ?");
        
        foreach ($orphanedMembers as $member) {
            if ($verbose) {
                echo "Deleting project member entry {$member['id']} for user {$member['user_id']} in project {$member['project_id']}\n";
            }
            $deleteStmt->execute([$member['id']]);
            $stats['orphaned_project_members_fixed']++;
        }
    }
    
    // 4. Check for orphaned tasks
    echo "\nChecking for orphaned tasks...\n";
    $stmt = $conn->prepare("
        SELECT t.task_id, t.title, t.project_id
        FROM tasks t
        LEFT JOIN projects p ON t.project_id = p.project_id
        WHERE p.project_id IS NULL
    ");
    $stmt->execute();
    $orphanedTasks = $stmt->fetchAll();
    
    $stats['orphaned_tasks_found'] = count($orphanedTasks);
    echo "Found {$stats['orphaned_tasks_found']} orphaned tasks\n";
    
    if ($stats['orphaned_tasks_found'] > 0 && !$dryRun) {
        $deleteStmt = $conn->prepare("DELETE FROM tasks WHERE task_id = ?");
        
        foreach ($orphanedTasks as $task) {
            if ($verbose) {
                echo "Deleting task {$task['task_id']} ({$task['title']}) from project {$task['project_id']}\n";
            }
            $deleteStmt->execute([$task['task_id']]);
            $stats['orphaned_tasks_fixed']++;
        }
    }
    
    // Commit transaction if not in dry run mode
    if (!$dryRun && $conn->inTransaction()) {
        $conn->commit();
        echo "\nAll changes committed to database.\n";
    }
    
    // Output summary
    echo "\nSummary:\n";
    echo "--------\n";
    echo "Orphaned connections found: {$stats['orphaned_connections_found']}\n";
    echo "Orphaned connections fixed: {$stats['orphaned_connections_fixed']}\n";
    echo "Orphaned notifications found: {$stats['orphaned_notifications_found']}\n";
    echo "Orphaned notifications fixed: {$stats['orphaned_notifications_fixed']}\n";
    echo "Orphaned project members found: {$stats['orphaned_project_members_found']}\n";
    echo "Orphaned project members fixed: {$stats['orphaned_project_members_fixed']}\n";
    echo "Orphaned tasks found: {$stats['orphaned_tasks_found']}\n";
    echo "Orphaned tasks fixed: {$stats['orphaned_tasks_fixed']}\n";
    
} catch (Exception $e) {
    // Roll back transaction if an error occurred
    if (!$dryRun && $conn->inTransaction()) {
        $conn->rollBack();
    }
    
    echo "\nERROR: " . $e->getMessage() . "\n";
    echo "All changes have been rolled back.\n";
}

echo "\nDone.\n";
?> 