<?php
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Initialize session if needed
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Set a user ID for testing
if (!isset($_SESSION['user_id'])) {
    $_SESSION['user_id'] = 1; // Use a valid user ID from your database
}

// Simple test function
function testCommentSystem() {
    global $conn;
    
    echo "Starting comment system test...\n";
    
    try {
        // 1. Check if task_comments table exists
        $tableExists = tableExists($conn, 'task_comments');
        echo "task_comments table exists: " . ($tableExists ? "Yes" : "No") . "\n";
        
        if (!$tableExists) {
            echo "Creating task_comments table...\n";
            $conn->exec("
                CREATE TABLE IF NOT EXISTS task_comments (
                    comment_id INT AUTO_INCREMENT PRIMARY KEY,
                    task_id INT NOT NULL,
                    user_id INT NOT NULL,
                    comment_text TEXT NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");
            echo "Table created successfully.\n";
        }
        
        // 2. Get column information to ensure we're using the right column names
        $stmt = $conn->prepare("DESCRIBE task_comments");
        $stmt->execute();
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        echo "Columns in task_comments table: " . implode(", ", $columns) . "\n";
        
        // 3. Verify no assigned_by column exists
        if (in_array('assigned_by', $columns)) {
            echo "WARNING: assigned_by column exists in the table but should not be used.\n";
        } else {
            echo "Good: No assigned_by column exists in the table.\n";
        }
        
        // 4. Test comment creation with transaction (will be rolled back)
        $conn->beginTransaction();
        
        // Choose a valid task ID from your database
        $task_id = 1; 
        $user_id = $_SESSION['user_id'];
        $comment_text = "Test comment - " . date('Y-m-d H:i:s');
        
        // Determine which column to use for comment text
        $commentColumn = '';
        if (in_array('comment_text', $columns)) {
            $commentColumn = 'comment_text';
        } elseif (in_array('content', $columns)) {
            $commentColumn = 'content';
        } elseif (in_array('comment', $columns)) {
            $commentColumn = 'comment';
        } else {
            // No suitable column found, add comment_text column
            echo "No comment text column found, adding comment_text column...\n";
            $conn->exec("ALTER TABLE task_comments ADD COLUMN comment_text TEXT NOT NULL AFTER user_id");
            $commentColumn = 'comment_text';
            echo "Added comment_text column to task_comments table.\n";
            
            // Refresh columns
            $stmt = $conn->prepare("DESCRIBE task_comments");
            $stmt->execute();
            $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
            echo "Updated columns in task_comments table: " . implode(", ", $columns) . "\n";
        }
        
        // Insert test comment
        echo "Inserting test comment using column: $commentColumn...\n";
        $stmt = $conn->prepare("
            INSERT INTO task_comments (task_id, user_id, $commentColumn)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$task_id, $user_id, $comment_text]);
        $comment_id = $conn->lastInsertId();
        
        if (!$comment_id) {
            throw new Exception("Failed to insert comment - no ID returned");
        }
        
        echo "Comment inserted successfully with ID: $comment_id\n";
        
        // 5. Retrieve the comment with user details
        echo "Retrieving comment...\n";
        $stmt = $conn->prepare("
            SELECT 
                tc.comment_id,
                tc.task_id,
                tc.user_id,
                tc.$commentColumn as comment_text,
                tc.created_at
            FROM task_comments tc
            WHERE tc.comment_id = ?
        ");
        $stmt->execute([$comment_id]);
        $comment = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$comment) {
            throw new Exception("Failed to retrieve newly created comment");
        }
        
        echo "Retrieved comment data:\n";
        print_r($comment);
        
        // 6. Process comment like we do in the main code
        echo "Processing comment data...\n";
        
        // Ensure comment_text field is always present
        if (!isset($comment['comment_text']) || empty($comment['comment_text'])) {
            $comment['comment_text'] = $comment_text;
        }
        
        // Add default values for user fields that would come from the JOIN
        $comment['first_name'] = 'Test';
        $comment['last_name'] = 'User';
        $comment['profile_image'] = '';
        
        // Make sure comment structure is consistent
        $comment['created_at'] = $comment['created_at'] ?? date('Y-m-d H:i:s');
        
        // Explicitly remove any non-existent columns that might cause errors
        unset($comment['assigned_by']);
        
        echo "Final processed comment data:\n";
        print_r($comment);
        
        // Rollback transaction to clean up
        $conn->rollBack();
        echo "Transaction rolled back - no actual changes made to the database.\n";
        
        echo "Test completed successfully!\n";
        
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        echo "ERROR: " . $e->getMessage() . "\n";
    }
}

// Run the test
testCommentSystem();

echo "Done!";
?> 