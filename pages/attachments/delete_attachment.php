<?php
require_once '../../includes/functions.php';
require_once '../../config/database.php';

// Set content type to JSON
header('Content-Type: application/json');

// Check if user is logged in
if (!isLoggedIn()) {
    echo json_encode([
        'success' => false,
        'message' => 'You must be logged in to delete attachments.'
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

// Check if attachment ID is provided
if (!isset($_POST['attachment_id']) || !is_numeric($_POST['attachment_id'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid attachment ID.'
    ]);
    exit;
}

$attachment_id = $_POST['attachment_id'];
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
        echo json_encode([
            'success' => false,
            'message' => 'Attachment system is not set up yet.'
        ]);
        exit;
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
        echo json_encode([
            'success' => false,
            'message' => 'Attachment not found.'
        ]);
        exit;
    }
    
    // Check if user is a member of the project
    $stmt = $conn->prepare("
        SELECT pm.role FROM project_members pm
        WHERE pm.project_id = ? AND pm.user_id = ?
    ");
    $stmt->execute([$attachment['project_id'], $user_id]);
    $membership = $stmt->fetch();
    
    if (!$membership) {
        echo json_encode([
            'success' => false,
            'message' => 'You do not have access to this attachment.'
        ]);
        exit;
    }
    
    // Check if user is the uploader or has admin/owner privileges
    $isUploader = ($attachment['uploaded_by'] == $user_id);
    $hasAdminRights = in_array($membership['role'], ['admin', 'owner']);
    
    if (!$isUploader && !$hasAdminRights) {
        echo json_encode([
            'success' => false,
            'message' => 'You do not have permission to delete this attachment.'
        ]);
        exit;
    }
    
    // Delete the file from the filesystem - ensure we use the correct path
    $filepath = $attachment['filepath'];
    // If the path doesn't start with '../../', add it (to ensure we're looking in the right place)
    if (strpos($filepath, '../../') !== 0) {
        $filepath = '../../' . $filepath;
    }
    
    if (file_exists($filepath) && is_file($filepath)) {
        unlink($filepath);
    }
    
    // Delete the attachment record from the database
    $stmt = $conn->prepare("
        DELETE FROM task_attachments
        WHERE attachment_id = ?
    ");
    $stmt->execute([$attachment_id]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Attachment deleted successfully.'
    ]);
    
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error deleting attachment: ' . $e->getMessage()
    ]);
}
?> 