<?php
require_once '../../includes/functions.php';
require_once '../../config/database.php';

// Set content type to JSON
header('Content-Type: application/json');

// Check if user is logged in
if (!isLoggedIn()) {
    echo json_encode([
        'success' => false,
        'message' => 'You must be logged in to update task details.'
    ]);
    exit;
}

// Validate CSRF token
$csrf_header = isset($_SERVER['HTTP_X_CSRF_TOKEN']) ? $_SERVER['HTTP_X_CSRF_TOKEN'] : '';
$csrf_post = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
$csrf_provided = $csrf_header ?: $csrf_post;

if (!isset($_SESSION['csrf_token']) || empty($csrf_provided) || $csrf_provided !== $_SESSION['csrf_token']) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid or missing CSRF token.'
    ]);
    exit;
}

// Check if task ID and description are provided
if (!isset($_POST['task_id']) || !is_numeric($_POST['task_id']) || !isset($_POST['description'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Missing required parameters.'
    ]);
    exit;
}

$task_id = $_POST['task_id'];
$description = trim($_POST['description']);
$user_id = $_SESSION['user_id'];

try {
    // First get the task details to check permissions
    $stmt = $conn->prepare("
        SELECT t.*, p.owner_id
        FROM tasks t
        JOIN projects p ON t.project_id = p.project_id
        WHERE t.task_id = ?
    ");
    $stmt->execute([$task_id]);
    $task = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$task) {
        error_log("Task not found: ID {$task_id}, requested by user {$user_id}");
        echo json_encode([
            'success' => false,
            'message' => 'Task not found or has been deleted.'
        ]);
        exit;
    }
    
    // Check if user is a member of the project
    $stmt = $conn->prepare("
        SELECT pm.role FROM project_members pm
        WHERE pm.project_id = ? AND pm.user_id = ?
    ");
    $stmt->execute([$task['project_id'], $user_id]);
    $membership = $stmt->fetch();
    
    if (!$membership) {
        echo json_encode([
            'success' => false,
            'message' => 'You do not have access to this task.'
        ]);
        exit;
    }
    
    // Check if user has permission to edit the task
    $isTaskCreator = ($task['created_by'] == $user_id);
    
    // Check if user is assigned to the task (single assignee or multiple assignees)
    $isTaskAssignee = false;
    
    // First check the main assigned_to field
    if (isset($task['assigned_to']) && $task['assigned_to'] == $user_id) {
        $isTaskAssignee = true;
    } else {
        // Check in task_assignees table if not found in main assigned_to field
        $stmt = $conn->prepare("
            SELECT 1 FROM task_assignees 
            WHERE task_id = ? AND user_id = ? 
            LIMIT 1
        ");
        $stmt->execute([$task_id, $user_id]);
        if ($stmt->fetchColumn()) {
            $isTaskAssignee = true;
        }
    }
    
    $isProjectAdmin = ($membership['role'] === 'admin');
    $isProjectOwner = ($membership['role'] === 'owner');
    
    if (!$isTaskCreator && !$isTaskAssignee && !$isProjectAdmin && !$isProjectOwner) {
        error_log("Permission denied: User {$user_id} attempted to edit description for task {$task_id}");
        echo json_encode([
            'success' => false,
            'message' => 'You do not have permission to edit this task description. Only the task creator, assigned users, or project administrators can make changes.'
        ]);
        exit;
    }
    
    // Use a transaction for better data integrity
    $conn->beginTransaction();
    
    try {
        // Update the task description
        $stmt = $conn->prepare("
            UPDATE tasks
            SET description = ?, updated_at = NOW()
            WHERE task_id = ?
        ");
        $stmt->execute([$description, $task_id]);
        
        // Check if activity_log table exists before logging
        $tableExists = false;
        try {
            $stmt = $conn->prepare("
                SELECT 1 FROM information_schema.tables 
                WHERE table_schema = DATABASE() 
                AND table_name = 'activity_log'
            ");
            $stmt->execute();
            $tableExists = (bool)$stmt->fetchColumn();
        } catch (PDOException $e) {
            // If there's an error checking for the table, just skip logging
            $tableExists = false;
        }
        
        // Log the update action if the table exists
        if ($tableExists) {
            $stmt = $conn->prepare("
                INSERT INTO activity_log (user_id, activity_type, related_id, details, created_at)
                VALUES (?, 'task_description_update', ?, ?, NOW())
            ");
            $stmt->execute([$user_id, $task_id, json_encode(['project_id' => $task['project_id']])]);
        }
        
        // Commit the transaction
        $conn->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Task description updated successfully.',
            'description' => $description
        ]);
    } catch (PDOException $e) {
        // Rollback the transaction if any error occurs
        $conn->rollBack();
        throw $e; // Re-throw to be caught by the outer catch block
    }
    
} catch (PDOException $e) {
    // Log the error details for troubleshooting
    error_log("Database error in update_task_description.php: " . $e->getMessage());
    
    // For security, don't expose detailed error messages to the client
    echo json_encode([
        'success' => false,
        'message' => 'A database error occurred while updating the task description. Please try again or contact support if the problem persists.'
    ]);
} catch (Exception $e) {
    // Catch any other exceptions
    error_log("General error in update_task_description.php: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => 'An unexpected error occurred. Please try again.'
    ]);
}
?> 