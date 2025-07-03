<?php
/**
 * Database Maintenance Functions
 * 
 * This file contains functions for database maintenance and cleanup operations.
 */

/**
 * Clean up orphaned records in the database
 * 
 * @param PDO $conn Database connection
 * @param bool $dryRun If true, only report issues without fixing them
 * @return array Results of the cleanup operation
 */
function cleanupOrphanedRecords($conn, $dryRun = false) {
    $results = [
        'orphaned_connections' => 0,
        'orphaned_notifications' => 0,
        'details' => []
    ];
    
    try {
        // Begin transaction
        $conn->beginTransaction();
        
        // Find orphaned connections
        $stmt = $conn->prepare("
            SELECT c.connection_id, c.user_id, c.connected_user_id
            FROM connections c
            LEFT JOIN users u1 ON c.user_id = u1.user_id
            LEFT JOIN users u2 ON c.connected_user_id = u2.user_id
            WHERE u1.user_id IS NULL OR u2.user_id IS NULL
        ");
        $stmt->execute();
        $orphanedConnections = $stmt->fetchAll();
        
        $results['orphaned_connections'] = count($orphanedConnections);
        
        foreach ($orphanedConnections as $connection) {
            $results['details'][] = [
                'type' => 'connection',
                'id' => $connection['connection_id'],
                'user_id' => $connection['user_id'],
                'connected_user_id' => $connection['connected_user_id']
            ];
        }
        
        // Delete orphaned connections if not a dry run
        if (!$dryRun && !empty($orphanedConnections)) {
            $connectionIds = array_column($orphanedConnections, 'connection_id');
            $placeholders = implode(',', array_fill(0, count($connectionIds), '?'));
            
            $stmt = $conn->prepare("DELETE FROM connections WHERE connection_id IN ($placeholders)");
            $stmt->execute($connectionIds);
        }
        
        // Find orphaned notifications (recipient)
        $stmt = $conn->prepare("
            SELECT n.notification_id, n.user_id, n.type
            FROM notifications n
            LEFT JOIN users u ON n.user_id = u.user_id
            WHERE u.user_id IS NULL
        ");
        $stmt->execute();
        $orphanedRecipients = $stmt->fetchAll();
        
        // Find orphaned notifications (sender)
        $stmt = $conn->prepare("
            SELECT n.notification_id, n.related_user_id, n.type
            FROM notifications n
            LEFT JOIN users u ON n.related_user_id = u.user_id
            WHERE n.related_user_id IS NOT NULL AND u.user_id IS NULL
        ");
        $stmt->execute();
        $orphanedSenders = $stmt->fetchAll();
        
        $results['orphaned_notifications'] = count($orphanedRecipients) + count($orphanedSenders);
        
        foreach ($orphanedRecipients as $notification) {
            $results['details'][] = [
                'type' => 'notification_recipient',
                'id' => $notification['notification_id'],
                'user_id' => $notification['user_id'],
                'notification_type' => $notification['type']
            ];
        }
        
        foreach ($orphanedSenders as $notification) {
            $results['details'][] = [
                'type' => 'notification_sender',
                'id' => $notification['notification_id'],
                'related_user_id' => $notification['related_user_id'],
                'notification_type' => $notification['type']
            ];
        }
        
        // Delete orphaned notifications if not a dry run
        if (!$dryRun && ($orphanedRecipients || $orphanedSenders)) {
            $notificationIds = array_merge(
                array_column($orphanedRecipients, 'notification_id'),
                array_column($orphanedSenders, 'notification_id')
            );
            
            if (!empty($notificationIds)) {
                $notificationIds = array_unique($notificationIds);
                $placeholders = implode(',', array_fill(0, count($notificationIds), '?'));
                
                $stmt = $conn->prepare("DELETE FROM notifications WHERE notification_id IN ($placeholders)");
                $stmt->execute($notificationIds);
            }
            
            // For notifications where related_user_id no longer exists, set to NULL
            $stmt = $conn->prepare("
                UPDATE notifications n
                LEFT JOIN users u ON n.related_user_id = u.user_id
                SET n.related_user_id = NULL
                WHERE n.related_user_id IS NOT NULL AND u.user_id IS NULL
            ");
            $stmt->execute();
        }
        
        // Commit changes
        $conn->commit();
        
        $results['success'] = true;
        return $results;
    } catch (Exception $e) {
        // Roll back transaction
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        
        $results['success'] = false;
        $results['error'] = $e->getMessage();
        return $results;
    }
}

/**
 * Check for orphaned task records
 * 
 * @param PDO $conn Database connection
 * @param bool $fix Whether to fix the issues
 * @return array Results of the check/fix operation
 */
function checkOrphanedTasks($conn, $fix = false) {
    $results = [
        'orphaned_tasks' => 0,
        'fixed_tasks' => 0,
        'details' => []
    ];
    
    try {
        // Begin transaction if fixing
        if ($fix) {
            $conn->beginTransaction();
        }
        
        // Find orphaned tasks (project no longer exists)
        $stmt = $conn->prepare("
            SELECT t.task_id, t.title, t.project_id
            FROM tasks t
            LEFT JOIN projects p ON t.project_id = p.project_id
            WHERE p.project_id IS NULL
        ");
        $stmt->execute();
        $orphanedTasks = $stmt->fetchAll();
        
        $results['orphaned_tasks'] = count($orphanedTasks);
        
        foreach ($orphanedTasks as $task) {
            $results['details'][] = [
                'type' => 'orphaned_task',
                'id' => $task['task_id'],
                'title' => $task['title'],
                'project_id' => $task['project_id']
            ];
        }
        
        // Delete orphaned tasks if fixing
        if ($fix && !empty($orphanedTasks)) {
            $taskIds = array_column($orphanedTasks, 'task_id');
            $placeholders = implode(',', array_fill(0, count($taskIds), '?'));
            
            $stmt = $conn->prepare("DELETE FROM tasks WHERE task_id IN ($placeholders)");
            $stmt->execute($taskIds);
            
            $results['fixed_tasks'] = $stmt->rowCount();
            
            // Also delete related records (comments, attachments, etc.)
            $stmt = $conn->prepare("DELETE FROM task_comments WHERE task_id IN ($placeholders)");
            $stmt->execute($taskIds);
            
            $stmt = $conn->prepare("DELETE FROM task_attachments WHERE task_id IN ($placeholders)");
            $stmt->execute($taskIds);
        }
        
        // Commit if fixing
        if ($fix) {
            $conn->commit();
        }
        
        $results['success'] = true;
        return $results;
    } catch (Exception $e) {
        // Roll back if fixing
        if ($fix && $conn->inTransaction()) {
            $conn->rollBack();
        }
        
        $results['success'] = false;
        $results['error'] = $e->getMessage();
        return $results;
    }
}
?> 