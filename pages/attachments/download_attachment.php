<?php
require_once '../../includes/functions.php';
require_once '../../config/database.php';

// Check if user is logged in
if (!isLoggedIn()) {
    setMessage("You must be logged in to download attachments.", "danger");
    redirect('../auth/login.php');
}

// Validate CSRF token
$csrf_token = isset($_GET['csrf_token']) ? $_GET['csrf_token'] : '';

if (!isset($_SESSION['csrf_token']) || empty($csrf_token) || $csrf_token !== $_SESSION['csrf_token']) {
    setMessage("Invalid or missing security token.", "danger");
    redirect('../projects/projects.php');
}

// Check if attachment ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    setMessage("Invalid attachment ID.", "danger");
    redirect('../projects/projects.php');
}

$attachment_id = $_GET['id'];
$user_id = $_SESSION['user_id'];

try {
    // Check if task_attachments table exists
    $stmt = $conn->prepare("
        SELECT COUNT(*) as table_exists 
        FROM information_schema.tables 
        WHERE table_schema = DATABASE() 
        AND table_name = 'task_attachments'
    ");
    $stmt->execute();
    $tableExists = (bool)$stmt->fetch()['table_exists'];
    
    if (!$tableExists) {
        setMessage("Attachment system is not set up yet.", "danger");
        redirect('../projects/projects.php');
    }
    
    // Get attachment details
    $stmt = $conn->prepare("
        SELECT a.*, t.project_id
        FROM task_attachments a
        JOIN tasks t ON a.task_id = t.task_id
        WHERE a.attachment_id = ?
    ");
    $stmt->execute([$attachment_id]);
    $attachment = $stmt->fetch();
    
    if (!$attachment) {
        setMessage("Attachment not found.", "danger");
        redirect('../projects/projects.php');
    }
    
    // Check if user is a member of the project
    $stmt = $conn->prepare("
        SELECT pm.role FROM project_members pm
        WHERE pm.project_id = ? AND pm.user_id = ?
    ");
    $stmt->execute([$attachment['project_id'], $user_id]);
    $membership = $stmt->fetch();
    
    if (!$membership) {
        setMessage("You do not have access to this attachment.", "danger");
        redirect('../projects/projects.php');
    }
    
    // Check if user has permission to download this attachment
    // Get task details to check if user is task creator or assignee
    $stmt = $conn->prepare("
        SELECT t.* FROM tasks t
        WHERE t.task_id = ?
    ");
    $stmt->execute([$attachment['task_id']]);
    $task = $stmt->fetch();
    
    // Check if user is task creator or assignee
    $isTaskCreator = ($task['created_by'] == $user_id);
    $isTaskAssignee = ($task['assigned_to'] == $user_id);
    $isProjectAdmin = ($membership['role'] === 'admin' || $membership['role'] === 'owner');
    
    // Check if user is in the assignees list (if task_assignees table exists)
    $isInAssigneesList = false;
    try {
        // Check if task_assignees table exists
        $stmt = $conn->prepare("
            SELECT COUNT(*) as table_exists 
            FROM information_schema.tables 
            WHERE table_schema = DATABASE() 
            AND table_name = 'task_assignees'
        ");
        $stmt->execute();
        $taskAssigneesTableExists = (bool)$stmt->fetch()['table_exists'];
        
        if ($taskAssigneesTableExists) {
            $stmt = $conn->prepare("
                SELECT COUNT(*) as is_assignee
                FROM task_assignees
                WHERE task_id = ? AND user_id = ?
            ");
            $stmt->execute([$attachment['task_id'], $user_id]);
            $isInAssigneesList = (bool)$stmt->fetch()['is_assignee'];
        }
    } catch (PDOException $e) {
        // Silently fail and assume user is not in assignees list
        $isInAssigneesList = false;
    }
    
    // User can download if they created the task, are assigned to it, or are a project admin
    $canDownload = ($isTaskCreator || $isTaskAssignee || $isInAssigneesList || $isProjectAdmin);
    
    if (!$canDownload) {
        setMessage("You don't have permission to download this attachment.", "danger");
        redirect('../projects/projects.php');
    }
    
    // Get file path and ensure it's correct
    $file_path = $attachment['filepath'];
    // If the path doesn't start with '../../', add it (to ensure we're looking in the right place)
    if (strpos($file_path, '../../') !== 0) {
        $file_path = '../../' . $file_path;
    }
    
    // Check if the file exists
    if (!file_exists($file_path) || !is_file($file_path)) {
        setMessage("The attachment file could not be found on the server.", "danger");
        redirect('../projects/projects.php');
    }
    
    // Get file information
    $file_name = $attachment['filename'];
    $file_size = filesize($file_path);
    $file_type = $attachment['filetype'];
    
    // Set appropriate headers for download
    header('Content-Description: File Transfer');
    header('Content-Type: ' . $file_type);
    header('Content-Disposition: attachment; filename="' . $file_name . '"');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . $file_size);
    
    // Clear output buffer
    ob_clean();
    flush();
    
    // Read and output file
    readfile($file_path);
    exit;
    
} catch (PDOException $e) {
    setMessage("Error downloading attachment: " . $e->getMessage(), "danger");
    redirect('../projects/projects.php');
}
?> 