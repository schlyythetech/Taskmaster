<?php
require_once '../../config/database.php';

try {
    $result = $conn->query('SHOW TABLES');
    $tables = $result->fetchAll(PDO::FETCH_COLUMN);
    echo 'Tables in database: ' . implode(', ', $tables) . PHP_EOL;
    
    // Check if task_comments table exists
    if (in_array('task_comments', $tables)) {
        echo "task_comments table exists" . PHP_EOL;
        
        // Show table structure
        $result = $conn->query('DESCRIBE task_comments');
        $columns = $result->fetchAll(PDO::FETCH_ASSOC);
        
        echo "Columns in task_comments table:" . PHP_EOL;
        foreach ($columns as $column) {
            echo "- " . $column['Field'] . " (" . $column['Type'] . ")" . PHP_EOL;
        }
    } else {
        echo "task_comments table does not exist" . PHP_EOL;
    }
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
}
?> 