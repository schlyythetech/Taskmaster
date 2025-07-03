<?php
require_once '../../includes/functions.php';
require_once '../../config/database.php';

// Check if user is logged in
if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'You must be logged in to delete tasks.']);
    exit;
}

// Check for CSRF token
if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
    exit;
}

// Check if task ID is provided
if (!isset($_POST['task_id']) || !is_numeric($_POST['task_id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid task ID.']);
    exit;
}

$task_id = $_POST['task_id'];
$user_id = $_SESSION['user_id'];

try {
    // First check if the user is the creator of the task
    $stmt = $conn->prepare("SELECT created_by, project_id FROM tasks WHERE task_id = ?");
    $stmt->execute([$task_id]);
    $task = $stmt->fetch();
    
    if (!$task) {
        echo json_encode(['success' => false, 'message' => 'Task not found.']);
        exit;
    }
    
    if ($task['created_by'] != $user_id) {
        echo json_encode(['success' => false, 'message' => 'You can only delete tasks that you created.']);
        exit;
    }
    
    // Begin transaction to ensure all related records are deleted
    $conn->beginTransaction();
    
    // Delete task comments if table exists
    if (tableExists($conn, 'task_comments')) {
        $stmt = $conn->prepare("DELETE FROM task_comments WHERE task_id = ?");
        $stmt->execute([$task_id]);
    }
    
    // Delete task assignees if table exists
    if (tableExists($conn, 'task_assignees')) {
        $stmt = $conn->prepare("DELETE FROM task_assignees WHERE task_id = ?");
        $stmt->execute([$task_id]);
    }
    
    // Delete task history if table exists
    if (tableExists($conn, 'task_history')) {
        $stmt = $conn->prepare("DELETE FROM task_history WHERE task_id = ?");
        $stmt->execute([$task_id]);
    }
    
    // Delete task categories if table exists
    if (tableExists($conn, 'task_categories')) {
        $stmt = $conn->prepare("DELETE FROM task_categories WHERE task_id = ?");
        $stmt->execute([$task_id]);
    }
    
    // Delete the task
    $stmt = $conn->prepare("DELETE FROM tasks WHERE task_id = ?");
    $stmt->execute([$task_id]);
    
    // Commit the transaction
    $conn->commit();
    
    echo json_encode(['success' => true, 'message' => 'Task deleted successfully.', 'project_id' => $task['project_id']]);
    
} catch (PDOException $e) {
    // Rollback the transaction if there was an error
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    
    error_log("Error deleting task: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred while deleting the task.']);
}
?> 