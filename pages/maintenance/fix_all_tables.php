<?php
require_once '../../config/database.php';

// Set content type to JSON for API response
header('Content-Type: application/json');

// Function to check if a table exists
function tableExists($conn, $tableName) {
    try {
        $result = $conn->query("SHOW TABLES LIKE '{$tableName}'");
        return $result->rowCount() > 0;
    } catch (Exception $e) {
        return false;
    }
}

// Function to check if a column exists in a table
function columnExists($conn, $tableName, $columnName) {
    try {
        $stmt = $conn->prepare("SHOW COLUMNS FROM {$tableName} LIKE ?");
        $stmt->execute([$columnName]);
        return $stmt->rowCount() > 0;
    } catch (Exception $e) {
        return false;
    }
}

// Function to add a column if it doesn't exist
function addColumnIfNotExists($conn, $tableName, $columnName, $definition) {
    if (!columnExists($conn, $tableName, $columnName)) {
        try {
            $conn->exec("ALTER TABLE {$tableName} ADD COLUMN {$columnName} {$definition}");
            return true;
        } catch (Exception $e) {
            error_log("Error adding column {$columnName} to {$tableName}: " . $e->getMessage());
            return false;
        }
    }
    return false; // Column already exists
}

// Function to drop a column if it exists
function dropColumnIfExists($conn, $tableName, $columnName) {
    if (columnExists($conn, $tableName, $columnName)) {
        try {
            $conn->exec("ALTER TABLE {$tableName} DROP COLUMN {$columnName}");
            return true;
        } catch (Exception $e) {
            error_log("Error dropping column {$columnName} from {$tableName}: " . $e->getMessage());
            return false;
        }
    }
    return false; // Column doesn't exist
}

try {
    $results = [];
    
    // 1. Fix task_comments table
    if (!tableExists($conn, 'task_comments')) {
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
        $results[] = "Created task_comments table";
    } else {
        // Make sure comment_text column exists
        if (addColumnIfNotExists($conn, 'task_comments', 'comment_text', 'TEXT NOT NULL AFTER user_id')) {
            $results[] = "Added comment_text column to task_comments table";
        }
        
        // Make sure updated_at column exists
        if (addColumnIfNotExists($conn, 'task_comments', 'updated_at', 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP')) {
            $results[] = "Added updated_at column to task_comments table";
        }
        
        // Drop assigned_by column if it exists (it shouldn't be in task_comments)
        if (dropColumnIfExists($conn, 'task_comments', 'assigned_by')) {
            $results[] = "Removed incorrect assigned_by column from task_comments table";
        }
    }
    
    // 2. Fix task_assignees table
    if (!tableExists($conn, 'task_assignees')) {
        $conn->exec("
            CREATE TABLE task_assignees (
                assignee_id INT AUTO_INCREMENT PRIMARY KEY,
                task_id INT NOT NULL,
                user_id INT NOT NULL,
                assigned_by INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (task_id) REFERENCES tasks(task_id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
                FOREIGN KEY (assigned_by) REFERENCES users(user_id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
        $results[] = "Created task_assignees table";
    } else {
        // Make sure assigned_by column exists
        if (addColumnIfNotExists($conn, 'task_assignees', 'assigned_by', 'INT NOT NULL AFTER user_id')) {
            // Add foreign key if possible
            try {
                $conn->exec("ALTER TABLE task_assignees ADD CONSTRAINT task_assignees_assigned_by_fk FOREIGN KEY (assigned_by) REFERENCES users(user_id) ON DELETE CASCADE");
                $results[] = "Added assigned_by column with foreign key to task_assignees table";
            } catch (Exception $e) {
                $results[] = "Added assigned_by column to task_assignees table (without foreign key)";
            }
        }
    }
    
    // 3. Verify tasks table has all required columns
    if (tableExists($conn, 'tasks')) {
        $columns = [
            'task_id' => 'INT AUTO_INCREMENT PRIMARY KEY',
            'project_id' => 'INT NOT NULL',
            'epic_id' => 'INT NULL',
            'created_by' => 'INT NOT NULL',
            'assigned_to' => 'INT NULL',
            'title' => 'VARCHAR(255) NOT NULL',
            'description' => 'TEXT NULL',
            'status' => "ENUM('to_do', 'in_progress', 'review', 'completed') NOT NULL DEFAULT 'to_do'",
            'priority' => "ENUM('low', 'medium', 'high') NOT NULL DEFAULT 'medium'",
            'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
        ];
        
        foreach ($columns as $column => $definition) {
            if (addColumnIfNotExists($conn, 'tasks', $column, $definition)) {
                $results[] = "Added {$column} column to tasks table";
            }
        }
    }
    
    // 4. Populate default values for any NULL columns in tasks that should have values
    try {
        $conn->exec("
            UPDATE tasks 
            SET status = 'to_do' 
            WHERE status IS NULL OR status = ''
        ");
        
        $conn->exec("
            UPDATE tasks 
            SET priority = 'medium' 
            WHERE priority IS NULL OR priority = ''
        ");
        
        $conn->exec("
            UPDATE tasks 
            SET created_at = NOW() 
            WHERE created_at IS NULL
        ");
        
        $conn->exec("
            UPDATE tasks 
            SET updated_at = NOW() 
            WHERE updated_at IS NULL
        ");
        
        $results[] = "Updated NULL values in tasks table with defaults";
    } catch (Exception $e) {
        $results[] = "Error updating NULL values: " . $e->getMessage();
    }
    
    // Return success with list of changes made
    echo json_encode([
        'success' => true,
        'message' => 'Database structure verification complete',
        'changes' => $results
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error fixing database structure: ' . $e->getMessage()
    ]);
}
?> 