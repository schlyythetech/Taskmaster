<?php
require_once '../../includes/functions.php';
require_once '../../config/database.php';

// Set content type to JSON
header('Content-Type: application/json');

// Check if user is logged in
if (!isLoggedIn()) {
    echo json_encode([
        'success' => false,
        'message' => 'You must be logged in to delete comments.'
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

// Check if comment ID is provided
if (!isset($_POST['comment_id']) || !is_numeric($_POST['comment_id'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid comment ID.'
    ]);
    exit;
}

$comment_id = $_POST['comment_id'];
$user_id = $_SESSION['user_id'];

try {
    // First check if task_comments table exists
    $stmt = $conn->prepare("
        SELECT COUNT(*) as table_exists 
        FROM information_schema.tables 
        WHERE table_schema = DATABASE() 
        AND table_name = 'task_comments'
    ");
    $stmt->execute();
    $tableExists = (bool)$stmt->fetch()['table_exists'];
    
    if (!$tableExists) {
        echo json_encode([
            'success' => false,
            'message' => 'Comments system is not set up yet.'
        ]);
        exit;
    }
    
    // Get comment details to check permissions
    $stmt = $conn->prepare("
        SELECT tc.*, t.created_by as task_creator, t.assigned_to as task_assignee, t.project_id
        FROM task_comments tc
        JOIN tasks t ON tc.task_id = t.task_id
        WHERE tc.comment_id = ?
    ");
    $stmt->execute([$comment_id]);
    $comment = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$comment) {
        echo json_encode([
            'success' => false,
            'message' => 'Comment not found.'
        ]);
        exit;
    }
    
    // Check if user is a member of the project
    $stmt = $conn->prepare("
        SELECT pm.role FROM project_members pm
        WHERE pm.project_id = ? AND pm.user_id = ?
    ");
    $stmt->execute([$comment['project_id'], $user_id]);
    $membership = $stmt->fetch();
    
    if (!$membership) {
        echo json_encode([
            'success' => false,
            'message' => 'You do not have access to this project.'
        ]);
        exit;
    }
    
    // Check if user is the comment author, task creator, task assignee, or project admin
    $isCommentAuthor = ($comment['user_id'] == $user_id);
    $isTaskCreator = ($comment['task_creator'] == $user_id);
    $isTaskAssignee = ($comment['task_assignee'] == $user_id);
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
            $stmt->execute([$comment['task_id'], $user_id]);
            $isInAssigneesList = (bool)$stmt->fetch()['is_assignee'];
        }
    } catch (PDOException $e) {
        // Silently fail and assume user is not in assignees list
        $isInAssigneesList = false;
    }
    
    // Determine if user can delete the comment
    $canDeleteComment = $isCommentAuthor || $isTaskCreator || $isTaskAssignee || $isInAssigneesList || $isProjectAdmin;
    
    if (!$canDeleteComment) {
        echo json_encode([
            'success' => false,
            'message' => 'You do not have permission to delete this comment.'
        ]);
        exit;
    }
    
    // Delete the comment
    $stmt = $conn->prepare("
        DELETE FROM task_comments
        WHERE comment_id = ?
    ");
    $stmt->execute([$comment_id]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Comment deleted successfully.'
    ]);
    
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error deleting comment: ' . $e->getMessage()
    ]);
}
?> 