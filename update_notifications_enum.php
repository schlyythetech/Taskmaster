<?php
// Script to add leave_project to notifications table enum
require_once 'config/database.php';

try {
    // SQL to modify the enum values
    $sql = "ALTER TABLE notifications 
            MODIFY COLUMN type ENUM(
                'task_assigned',
                'task_completed',
                'comment',
                'connection_request',
                'project_invite',
                'achievement',
                'leave_project',
                'join_request'
            ) NOT NULL";
    
    // Execute the query
    $conn->exec($sql);
    
    echo "<h3>Success!</h3>";
    echo "<p>Successfully added 'leave_project' to notifications table enum values.</p>";
    
    // Check the current table structure
    $stmt = $conn->query("SHOW COLUMNS FROM notifications LIKE 'type'");
    $column = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "<h4>Updated Column Information:</h4>";
    echo "<pre>";
    print_r($column);
    echo "</pre>";
    
} catch (PDOException $e) {
    echo "<h3>Error:</h3>";
    echo "<p>" . $e->getMessage() . "</p>";
}

echo "<p><a href='pages/notifications/all.php'>Go to notifications page</a></p>";
?> 