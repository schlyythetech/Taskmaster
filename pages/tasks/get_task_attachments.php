<?php
require_once '../../includes/functions.php';
require_once '../../config/database.php';

// Set content type to JSON
header('Content-Type: application/json');

// Check if user is logged in
if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'You must be logged in to view attachments.']);
    exit;
}

// Validate CSRF token
if (!isset($_GET['csrf_token']) || $_GET['csrf_token'] !== $_SESSION['csrf_token']) {
    echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
    exit;
}

// Check required parameters
if (!isset($_GET['task_id']) || !is_numeric($_GET['task_id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid task ID.']);
    exit;
}

$task_id = $_GET['task_id'];
$user_id = $_SESSION['user_id'];

try {
    // First, check if the attachments table exists
    $stmt = $conn->prepare("
        SELECT COUNT(*) as table_exists 
        FROM information_schema.tables 
        WHERE table_schema = DATABASE() 
        AND table_name = 'task_attachments'
    ");
    $stmt->execute();
    $tableExists = (bool)$stmt->fetch()['table_exists'];
    
    if (!$tableExists) {
        // Create the attachments table
        $conn->exec("
            CREATE TABLE task_attachments (
                attachment_id INT AUTO_INCREMENT PRIMARY KEY,
                task_id INT NOT NULL,
                user_id INT NOT NULL,
                filename VARCHAR(255) NOT NULL,
                original_filename VARCHAR(255) NOT NULL,
                file_path VARCHAR(255) NOT NULL,
                file_type VARCHAR(100),
                filesize INT,
                uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (task_id) REFERENCES tasks(task_id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
        
        echo json_encode(['success' => true, 'attachments' => []]);
        exit;
    }
    
    // Check if user has access to this task
    $stmt = $conn->prepare("
        SELECT t.*, p.name as project_name
        FROM tasks t
        JOIN projects p ON t.project_id = p.project_id
        JOIN project_members pm ON t.project_id = pm.project_id
        WHERE t.task_id = ? AND pm.user_id = ?
    ");
    $stmt->execute([$task_id, $user_id]);
    $task = $stmt->fetch();
    
    if (!$task) {
        echo json_encode(['success' => false, 'message' => 'You do not have access to this task.']);
        exit;
    }
    
    // Get all attachments for this task
    $stmt = $conn->prepare("
        SELECT a.*, u.first_name, u.last_name
        FROM task_attachments a
        LEFT JOIN users u ON a.user_id = u.user_id
        WHERE a.task_id = ?
        ORDER BY a.uploaded_at DESC
    ");
    $stmt->execute([$task_id]);
    $attachments = $stmt->fetchAll();
    
    echo json_encode(['success' => true, 'attachments' => $attachments]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?> 