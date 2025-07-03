<?php
require_once '../../includes/functions.php';
require_once '../../config/database.php';

// Set content type to JSON
header('Content-Type: application/json');

try {
    // Check if task_comments table exists
    $stmt = $conn->prepare("
        SELECT COUNT(*) as table_exists 
        FROM information_schema.tables 
        WHERE table_schema = DATABASE() 
        AND table_name = 'task_comments'
    ");
    $stmt->execute();
    $tableExists = (bool)$stmt->fetch()['table_exists'];
    
    if (!$tableExists) {
        // Create task_comments table
        $conn->exec("
            CREATE TABLE task_comments (
                comment_id INT AUTO_INCREMENT PRIMARY KEY,
                task_id INT NOT NULL,
                user_id INT NOT NULL,
                comment_text TEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (task_id) REFERENCES tasks(task_id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
        
        echo json_encode([
            'success' => true, 
            'message' => 'Task comments table created successfully.'
        ]);
    } else {
        // Check if assigned_by column exists (which it shouldn't)
        $stmt = $conn->prepare("
            SELECT COUNT(*) as column_exists 
            FROM information_schema.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = 'task_comments' 
            AND COLUMN_NAME = 'assigned_by'
        ");
        $stmt->execute();
        $columnExists = (bool)$stmt->fetch()['column_exists'];
        
        if ($columnExists) {
            // Remove the assigned_by column
            $conn->exec("ALTER TABLE task_comments DROP COLUMN assigned_by");
            
            echo json_encode([
                'success' => true, 
                'message' => 'Removed assigned_by column from task_comments table.'
            ]);
        } else {
            echo json_encode([
                'success' => true, 
                'message' => 'Task comments table already exists and is correctly configured.'
            ]);
        }
    }
} catch (PDOException $e) {
    echo json_encode([
        'success' => false, 
        'message' => 'Error fixing task_comments table: ' . $e->getMessage()
    ]);
}
?> 