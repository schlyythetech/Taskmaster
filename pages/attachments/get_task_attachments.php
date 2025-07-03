<?php
require_once '../../includes/functions.php';
require_once '../../config/database.php';

// Set content type to JSON
header('Content-Type: application/json');

// Check if user is logged in
if (!isLoggedIn()) {
    echo json_encode([
        'success' => false,
        'message' => 'You must be logged in to view attachments.'
    ]);
    exit;
}

// Validate CSRF token
$csrf_header = isset($_SERVER['HTTP_X_CSRF_TOKEN']) ? $_SERVER['HTTP_X_CSRF_TOKEN'] : '';
$csrf_get = isset($_GET['csrf_token']) ? $_GET['csrf_token'] : '';
$csrf_provided = $csrf_header ?: $csrf_get;

if (!isset($_SESSION['csrf_token']) || empty($csrf_provided) || $csrf_provided !== $_SESSION['csrf_token']) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid or missing CSRF token.'
    ]);
    exit;
}

// Check if task ID is provided
if (!isset($_GET['task_id']) || !is_numeric($_GET['task_id'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid task ID.'
    ]);
    exit;
}

$task_id = $_GET['task_id'];
$user_id = $_SESSION['user_id'];

try {
    // Check if the task_attachments table exists
    $stmt = $conn->prepare("
        SELECT COUNT(*) as table_exists 
        FROM information_schema.tables 
        WHERE table_schema = DATABASE() 
        AND table_name = 'task_attachments'
    ");
    $stmt->execute();
    $tableExists = (bool)$stmt->fetch()['table_exists'];
    
    if (!$tableExists) {
        // Return empty array if table doesn't exist yet
        echo json_encode([
            'success' => true,
            'attachments' => []
        ]);
        exit;
    }
    
    // Check if user has access to this task
    $stmt = $conn->prepare("
        SELECT t.project_id 
        FROM tasks t 
        WHERE t.task_id = ?
    ");
    $stmt->execute([$task_id]);
    $task = $stmt->fetch();
    
    if (!$task) {
        echo json_encode([
            'success' => false,
            'message' => 'Task not found.'
        ]);
        exit;
    }
    
    // Check if user is a member of the project
    $stmt = $conn->prepare("
        SELECT pm.* FROM project_members pm
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
    
    // Get attachments for the task
    $stmt = $conn->prepare("
        SELECT a.*, u.first_name, u.last_name
        FROM task_attachments a
        LEFT JOIN users u ON a.uploaded_by = u.user_id
        WHERE a.task_id = ?
        ORDER BY a.uploaded_at DESC
    ");
    $stmt->execute([$task_id]);
    $attachments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Return the attachments as JSON
    echo json_encode([
        'success' => true,
        'attachments' => $attachments
    ]);
    
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching attachments: ' . $e->getMessage()
    ]);
}
?> 