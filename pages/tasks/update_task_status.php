<?php
require_once '../../includes/functions.php';
require_once '../../config/database.php';

// Set content type to JSON
header('Content-Type: application/json');

// Check if user is logged in
if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'You must be logged in to update a task.']);
    exit;
}

// Validate CSRF token
$csrf_token = isset($_SERVER['HTTP_X_CSRF_TOKEN']) ? $_SERVER['HTTP_X_CSRF_TOKEN'] : '';
if (empty($csrf_token) || $csrf_token !== $_SESSION['csrf_token']) {
    echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
    exit;
}

// Check required fields
if (empty($_POST['task_id']) || empty($_POST['status']) || empty($_POST['project_id'])) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields.']);
    exit;
}

// Process form data
$task_id = intval($_POST['task_id']);
$status = $_POST['status'];
$project_id = intval($_POST['project_id']);
$user_id = $_SESSION['user_id'];

// Validate status
$valid_statuses = ['to_do', 'in_progress', 'review', 'completed'];
if (!in_array($status, $valid_statuses)) {
    echo json_encode(['success' => false, 'message' => 'Invalid status value.']);
    exit;
}

try {
    // Check if user is a member of this project
    $stmt = $conn->prepare("
        SELECT 1 FROM project_members
        WHERE project_id = ? AND user_id = ?
    ");
    $stmt->execute([$project_id, $user_id]);
    $is_member = $stmt->fetch();
    
    if (!$is_member) {
        echo json_encode(['success' => false, 'message' => 'You are not a member of this project.']);
        exit;
    }
    
    // Check if task exists and belongs to the project
    $stmt = $conn->prepare("
        SELECT t.*, u.user_id as assignee_id 
        FROM tasks t
        LEFT JOIN users u ON t.assigned_to = u.user_id
        WHERE t.task_id = ? AND t.project_id = ?
    ");
    $stmt->execute([$task_id, $project_id]);
    $task = $stmt->fetch();
    
    if (!$task) {
        echo json_encode(['success' => false, 'message' => 'Task not found or does not belong to this project.']);
        exit;
    }
    
    // Permission check for completed status
    if ($status === 'completed' && $user_id !== $task['created_by']) {
        echo json_encode([
            'success' => false, 
            'message' => 'Only the task creator can mark this task as completed.'
        ]);
        exit;
    }

    // Get current status for history tracking
    $old_status = $task['status'];
    
    // Begin transaction
    $conn->beginTransaction();
    
    // Update task status
    $stmt = $conn->prepare("
        UPDATE tasks 
        SET status = ?, updated_at = NOW() 
        WHERE task_id = ?
    ");
    $stmt->execute([$status, $task_id]);
    
    // Add to task history
    $stmt = $conn->prepare("
        INSERT INTO task_history (
            task_id, 
            user_id, 
            action, 
            old_value, 
            new_value, 
            created_at
        ) VALUES (
            ?, ?, 'status_change', ?, ?, NOW()
        )
    ");
    $stmt->execute([$task_id, $user_id, $old_status, $status]);
    
    // If status is changed to 'completed', create notification for task creator
    if ($status === 'completed' && $old_status !== 'completed' && $task['created_by'] != $user_id) {
        $stmt = $conn->prepare("
            INSERT INTO notifications (
                user_id, 
                type, 
                message, 
                related_id, 
                created_at
            ) VALUES (
                ?, 'task_completed', ?, ?, NOW()
            )
        ");
        $notification_message = "Your task has been marked as completed: " . $task['title'];
        $stmt->execute([$task['created_by'], $notification_message, $task_id]);
    }
    
    // Check if all tasks in the project are completed
    $stmt = $conn->prepare("
        SELECT COUNT(*) as total_tasks,
               SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_tasks
        FROM tasks 
        WHERE project_id = ?
    ");
    $stmt->execute([$project_id]);
    $task_stats = $stmt->fetch();
    
    $project_completed = false;
    
    // If there are tasks and all are completed, update project status
    if ($task_stats['total_tasks'] > 0 && $task_stats['total_tasks'] == $task_stats['completed_tasks']) {
        // Check if projects table has a status column
        $stmt = $conn->prepare("
            SELECT COUNT(*) as column_exists 
            FROM information_schema.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = 'projects' 
            AND COLUMN_NAME = 'status'
        ");
        $stmt->execute();
        $statusColumnExists = (bool)$stmt->fetch()['column_exists'];
        
        // If status column exists, update it
        if ($statusColumnExists) {
            $stmt = $conn->prepare("
                UPDATE projects 
                SET status = 'completed', updated_at = NOW() 
                WHERE project_id = ?
            ");
            $stmt->execute([$project_id]);
        }
        
        $project_completed = true;
        
        // Get project owner for notification
        $stmt = $conn->prepare("
            SELECT owner_id FROM projects WHERE project_id = ?
        ");
        $stmt->execute([$project_id]);
        $project = $stmt->fetch();
        
        // Create notification for project owner if they're not the current user
        if ($project && $project['owner_id'] != $user_id) {
            $stmt = $conn->prepare("
                SELECT name FROM projects WHERE project_id = ?
            ");
            $stmt->execute([$project_id]);
            $project_info = $stmt->fetch();
            
            $stmt = $conn->prepare("
                INSERT INTO notifications (
                    user_id, 
                    type, 
                    message, 
                    related_id, 
                    created_at
                ) VALUES (
                    ?, 'project_completed', ?, ?, NOW()
                )
            ");
            $notification_message = "All tasks in your project have been completed: " . $project_info['name'];
            $stmt->execute([$project['owner_id'], $notification_message, $project_id]);
        }
    }
    
    // Commit transaction
    $conn->commit();
    
    echo json_encode([
        'success' => true, 
        'message' => 'Task status updated successfully.',
        'project_completed' => $project_completed,
        'project_id' => $project_id
    ]);
    
} catch (PDOException $e) {
    // Roll back transaction on error
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log("Error updating task status: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} 