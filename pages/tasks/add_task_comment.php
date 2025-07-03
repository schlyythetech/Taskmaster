<?php
require_once '../../includes/functions.php';
require_once '../../config/database.php';

// Set content type to JSON
header('Content-Type: application/json');

// Check if user is logged in
if (!isLoggedIn()) {
    echo json_encode([
        'success' => false,
        'message' => 'You must be logged in to add comments.'
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

// Check if task ID and comment text are provided
if (!isset($_POST['task_id']) || !is_numeric($_POST['task_id'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Missing or invalid task ID.'
    ]);
    exit;
}

if (!isset($_POST['comment_text']) || empty(trim($_POST['comment_text']))) {
    echo json_encode([
        'success' => false,
        'message' => 'Comment text cannot be empty.'
    ]);
    exit;
}

$task_id = $_POST['task_id'];
$comment_text = trim($_POST['comment_text']);
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
    
    // Check if the task exists
    $stmt = $conn->prepare("SELECT task_id FROM tasks WHERE task_id = ?");
    $stmt->execute([$task_id]);
    if ($stmt->rowCount() === 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Task does not exist.'
        ]);
        exit;
    }
    
    // Check if task_comments table exists using the tableExists function
    $tableExists = tableExists($conn, 'task_comments');
    
    // Create table if it doesn't exist
    if (!$tableExists) {
        try {
            $conn->exec("
                CREATE TABLE task_comments (
                    comment_id INT AUTO_INCREMENT PRIMARY KEY,
                    task_id INT NOT NULL,
                    user_id INT NOT NULL,
                    comment_text TEXT NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (task_id) REFERENCES tasks(task_id) ON DELETE CASCADE,
                    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");
            error_log("Created task_comments table successfully");
            
            // Now insert using comment_text since we just created the table with that column
            $stmt = $conn->prepare("
                INSERT INTO task_comments (task_id, user_id, comment_text)
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$task_id, $user_id, $comment_text]);
            $comment_id = $conn->lastInsertId();
            
            if (!$comment_id) {
                throw new Exception("Failed to insert comment - no ID returned");
            }
            
            // Get the full comment data with user details
            $stmt = $conn->prepare("
                SELECT 
                    tc.comment_id,
                    tc.task_id,
                    tc.user_id,
                    tc.comment_text,
                    tc.created_at,
                    u.first_name,
                    u.last_name,
                    u.profile_image
                FROM task_comments tc
                JOIN users u ON tc.user_id = u.user_id
                WHERE tc.comment_id = ?
            ");
            $stmt->execute([$comment_id]);
            $comment = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$comment) {
                throw new Exception("Failed to retrieve newly created comment");
            }
        } catch (Exception $e) {
            error_log("Error creating task_comments table or inserting comment: " . $e->getMessage());
            echo json_encode([
                'success' => false,
                'message' => 'Error adding comment: ' . $e->getMessage()
            ]);
            exit;
        }
    } else {
        // Table exists, now we need to check if it has the correct column structure
        try {
            // Get column information
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
                // No suitable column found, add comment_text column
                $conn->exec("ALTER TABLE task_comments ADD COLUMN comment_text TEXT NOT NULL AFTER user_id");
                $commentColumn = 'comment_text';
                error_log("Added comment_text column to task_comments table");
            }
            
            // Insert the comment using the detected column
            $stmt = $conn->prepare("
                INSERT INTO task_comments (task_id, user_id, $commentColumn)
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$task_id, $user_id, $comment_text]);
            $comment_id = $conn->lastInsertId();
            
            if (!$comment_id) {
                throw new Exception("Failed to insert comment - no ID returned");
            }
            
            // Get the full comment data with user details
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
                WHERE tc.comment_id = ?
            ");
            $stmt->execute([$comment_id]);
            $comment = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$comment) {
                throw new Exception("Failed to retrieve newly created comment");
            }
        } catch (Exception $e) {
            error_log("Error handling comment insertion: " . $e->getMessage());
            echo json_encode([
                'success' => false,
                'message' => 'Error adding comment: ' . $e->getMessage()
            ]);
            exit;
        }
    }
    
    // Make sure the comment has all required fields
    if (!isset($comment['comment_text']) || empty($comment['comment_text'])) {
        $comment['comment_text'] = $comment_text; // Use the original comment text if not set in result
    }
    
    if (!isset($comment['first_name'])) {
        $comment['first_name'] = $_SESSION['first_name'] ?? 'Unknown';
    }
    
    if (!isset($comment['last_name'])) {
        $comment['last_name'] = $_SESSION['last_name'] ?? 'User';
    }
    
    if (!isset($comment['profile_image'])) {
        $comment['profile_image'] = '';
    }
    
    // Make sure comment structure is consistent with what frontend expects
    // Add default values for any fields the frontend might expect
    $comment['created_at'] = $comment['created_at'] ?? date('Y-m-d H:i:s');
    
    // Explicitly remove the assigned_by field if it exists
    // This is needed because the column doesn't exist in task_comments table
    if (isset($comment['assigned_by'])) {
        unset($comment['assigned_by']);
    }
    
    // Update the task's updated_at timestamp
    try {
        $stmt = $conn->prepare("
            UPDATE tasks
            SET updated_at = NOW()
            WHERE task_id = ?
        ");
        $stmt->execute([$task_id]);
    } catch (PDOException $e) {
        // This is not critical, just log it
        error_log("Error updating task timestamp: " . $e->getMessage());
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Comment added successfully.',
        'comment' => $comment
    ]);
    
} catch (Exception $e) {
    error_log("Error in add_task_comment.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error adding comment: ' . $e->getMessage()
    ]);
}
?> 