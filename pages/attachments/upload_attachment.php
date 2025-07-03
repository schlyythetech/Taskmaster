<?php
require_once '../../includes/functions.php';
require_once '../../config/database.php';

// Set content type to JSON
header('Content-Type: application/json');

// Check if user is logged in
if (!isLoggedIn()) {
    echo json_encode([
        'success' => false,
        'message' => 'You must be logged in to upload attachments.'
    ]);
    exit;
}

// Validate CSRF token
$csrf_token = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';

if (!isset($_SESSION['csrf_token']) || empty($csrf_token) || $csrf_token !== $_SESSION['csrf_token']) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid or missing CSRF token.'
    ]);
    exit;
}

// Check if task ID is provided
if (!isset($_POST['task_id']) || !is_numeric($_POST['task_id'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid task ID.'
    ]);
    exit;
}

// Check if file was uploaded
if (!isset($_FILES['attachment']) || $_FILES['attachment']['error'] !== UPLOAD_ERR_OK) {
    $error_message = 'No file uploaded.';
    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] !== UPLOAD_ERR_OK) {
        switch ($_FILES['attachment']['error']) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                $error_message = 'The uploaded file exceeds the maximum file size limit.';
                break;
            case UPLOAD_ERR_PARTIAL:
                $error_message = 'The file was only partially uploaded.';
                break;
            case UPLOAD_ERR_NO_FILE:
                $error_message = 'No file was uploaded.';
                break;
            case UPLOAD_ERR_NO_TMP_DIR:
                $error_message = 'Missing a temporary folder.';
                break;
            case UPLOAD_ERR_CANT_WRITE:
                $error_message = 'Failed to write file to disk.';
                break;
            case UPLOAD_ERR_EXTENSION:
                $error_message = 'A PHP extension stopped the file upload.';
                break;
            default:
                $error_message = 'Unknown upload error.';
        }
    }
    
    echo json_encode([
        'success' => false,
        'message' => $error_message
    ]);
    exit;
}

$task_id = $_POST['task_id'];
$user_id = $_SESSION['user_id'];
$file = $_FILES['attachment'];

try {
    // Check if user has access to this task
    $stmt = $conn->prepare("
        SELECT t.*, p.owner_id
        FROM tasks t
        JOIN projects p ON t.project_id = p.project_id
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
    
    // Check if user has permission to upload attachments
    $isTaskCreator = ($task['created_by'] == $user_id);
    $isTaskAssignee = ($task['assigned_to'] == $user_id);
    $isProjectAdmin = ($membership['role'] === 'admin');
    $isProjectOwner = ($membership['role'] === 'owner');
    
    if (!$isTaskCreator && !$isTaskAssignee && !$isProjectAdmin && !$isProjectOwner) {
        echo json_encode([
            'success' => false,
            'message' => 'You do not have permission to upload attachments to this task.'
        ]);
        exit;
    }
    
    // Check if task_attachments table exists, create it if it doesn't
    $stmt = $conn->prepare("
        SELECT COUNT(*) as table_exists 
        FROM information_schema.tables 
        WHERE table_schema = DATABASE() 
        AND table_name = 'task_attachments'
    ");
    $stmt->execute();
    $tableExists = (bool)$stmt->fetch()['table_exists'];
    
    if (!$tableExists) {
        // Create the task_attachments table
        $conn->exec("
            CREATE TABLE task_attachments (
                attachment_id INT AUTO_INCREMENT PRIMARY KEY,
                task_id INT NOT NULL,
                filename VARCHAR(255) NOT NULL,
                filepath VARCHAR(255) NOT NULL,
                filesize INT NOT NULL,
                filetype VARCHAR(100) NOT NULL,
                uploaded_by INT NOT NULL,
                uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (task_id) REFERENCES tasks(task_id) ON DELETE CASCADE,
                FOREIGN KEY (uploaded_by) REFERENCES users(user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    }
    
    // Create uploads directory if it doesn't exist
    $upload_dir = '../../uploads/attachments/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    // Generate a unique filename to prevent overwriting
    $original_filename = basename($file['name']);
    $file_extension = pathinfo($original_filename, PATHINFO_EXTENSION);
    $filename = uniqid('attachment_') . '_' . time() . '.' . $file_extension;
    $filepath = $upload_dir . $filename;
    
    // Move the uploaded file to the target directory
    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        // Insert attachment record in the database
        $stmt = $conn->prepare("
            INSERT INTO task_attachments (task_id, filename, filepath, filesize, filetype, uploaded_by)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $task_id,
            $original_filename,
            $filepath,
            $file['size'],
            $file['type'],
            $user_id
        ]);
        
        $attachment_id = $conn->lastInsertId();
        
        echo json_encode([
            'success' => true,
            'message' => 'Attachment uploaded successfully.',
            'attachment' => [
                'attachment_id' => $attachment_id,
                'filename' => $original_filename,
                'filepath' => $filepath,
                'filesize' => $file['size'],
                'filetype' => $file['type'],
                'uploaded_by' => $user_id,
                'uploaded_at' => date('Y-m-d H:i:s')
            ]
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Failed to upload file. Please try again.'
        ]);
    }
    
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error uploading attachment: ' . $e->getMessage()
    ]);
}
?> 