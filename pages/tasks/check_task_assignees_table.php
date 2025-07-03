<?php
require_once '../../includes/functions.php';
require_once '../../config/database.php';

// Set content type to JSON
header('Content-Type: application/json');

try {
    // Check if the task_assignees table exists
    $stmt = $conn->prepare("
        SELECT COUNT(*) as table_exists 
        FROM information_schema.tables 
        WHERE table_schema = DATABASE() 
        AND table_name = 'task_assignees'
    ");
    $stmt->execute();
    $tableExists = (bool)$stmt->fetch()['table_exists'];
    
    if (!$tableExists) {
        // Create the task_assignees table
        $conn->exec("
            CREATE TABLE task_assignees (
                assignee_id INT AUTO_INCREMENT PRIMARY KEY,
                task_id INT NOT NULL,
                user_id INT NOT NULL,
                assigned_by INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (task_id) REFERENCES tasks(task_id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
                FOREIGN KEY (assigned_by) REFERENCES users(user_id) ON DELETE CASCADE,
                UNIQUE KEY unique_task_user (task_id, user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
        
        // Return success message
        echo json_encode([
            'success' => true, 
            'message' => 'Task assignees table created successfully.'
        ]);
    } else {
        // Table already exists
        echo json_encode([
            'success' => true, 
            'message' => 'Task assignees table already exists.'
        ]);
    }
} catch (PDOException $e) {
    // Return error message
    echo json_encode([
        'success' => false, 
        'message' => 'Error checking/creating task_assignees table: ' . $e->getMessage()
    ]);
}
?> 