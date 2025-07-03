<?php
require_once '../../includes/functions.php';
require_once '../../config/database.php';

// Set content type to JSON
header('Content-Type: application/json');

// Check if user is logged in
if (!isLoggedIn()) {
    echo json_encode([
        'success' => false,
        'message' => 'You must be logged in to view comments.'
    ]);
    exit;
}

// Validate CSRF token
$csrf_get = isset($_GET['csrf_token']) ? $_GET['csrf_token'] : '';
$csrf_header = isset($_SERVER['HTTP_X_CSRF_TOKEN']) ? $_SERVER['HTTP_X_CSRF_TOKEN'] : '';
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
        'message' => 'Missing or invalid task ID.'
    ]);
    exit;
}

$task_id = $_GET['task_id'];
$user_id = $_SESSION['user_id'];

try {
    // Check if user is a member of the project
    $stmt = $conn->prepare("
        SELECT pm.*, t.project_id
        FROM tasks t
        JOIN project_members pm ON t.project_id = pm.project_id
        WHERE t.task_id = ? AND pm.user_id = ?
    ");
    $stmt->execute([$task_id, $user_id]);
    $membership = $stmt->fetch();
    
    if (!$membership) {
        echo json_encode([
            'success' => false,
            'message' => 'You do not have access to this task.'
        ]);
        exit;
    }
    
    // Check if task_comments table exists using the tableExists function
    $tableExists = tableExists($conn, 'task_comments');
    
    if (!$tableExists) {
        // Return empty comments array if table doesn't exist
        echo json_encode([
            'success' => true,
            'comments' => []
        ]);
        exit;
    }
    
    // Get column information to determine which column contains the comment text
    try {
        $stmt = $conn->prepare("DESCRIBE task_comments");
        $stmt->execute();
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Check which column to use for comment text
        $commentColumn = '';
        if (in_array('comment_text', $columns)) {
            $commentColumn = 'comment_text';
        } elseif (in_array('content', $columns)) {
            $commentColumn = 'content';
        } elseif (in_array('comment', $columns)) {
            $commentColumn = 'comment';
        } else {
            // No suitable column found, return empty result
            echo json_encode([
                'success' => true,
                'comments' => []
            ]);
            exit;
        }
        
        // Get comments for the task with user details using the detected column
        $stmt = $conn->prepare("
            SELECT 
                tc.comment_id,
                tc.task_id,
                tc.user_id,
                tc.$commentColumn as comment_text,
                tc.created_at,
                u.first_name,
                u.last_name,
                u.profile_image
            FROM task_comments tc
            JOIN users u ON tc.user_id = u.user_id
            WHERE tc.task_id = ?
            ORDER BY tc.created_at DESC
        ");
        $stmt->execute([$task_id]);
        $comments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Process each comment to ensure consistent fields
        $processedComments = [];
        foreach ($comments as $comment) {
            // Ensure comment_text field is always present
            if (!isset($comment['comment_text']) && isset($comment[$commentColumn])) {
                $comment['comment_text'] = $comment[$commentColumn];
            }
            
            // Ensure we have default values for required fields
            if (!isset($comment['first_name'])) {
                $comment['first_name'] = 'Unknown';
            }
            if (!isset($comment['last_name'])) {
                $comment['last_name'] = 'User';
            }
            if (!isset($comment['profile_image'])) {
                $comment['profile_image'] = '';
            }
            
            // Set a default creation date if missing
            if (!isset($comment['created_at'])) {
                $comment['created_at'] = date('Y-m-d H:i:s');
            }
            
            // Explicitly remove the assigned_by field if it exists
            // This is needed because the column doesn't exist in task_comments table
            if (isset($comment['assigned_by'])) {
                unset($comment['assigned_by']);
            }
            
            $processedComments[] = $comment;
        }
        
        echo json_encode([
            'success' => true,
            'comments' => $processedComments
        ]);
    } catch (PDOException $e) {
        error_log("Error fetching comments: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'Error fetching comments: ' . $e->getMessage()
        ]);
    }
} catch (PDOException $e) {
    error_log("Error in get_task_comments.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching comments: ' . $e->getMessage()
    ]);
}
?> 