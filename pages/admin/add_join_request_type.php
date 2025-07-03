<?php
// Connect to database
require_once 'config/database.php';

try {
    // Check the current enum values
    $stmt = $conn->prepare("
        SELECT COLUMN_TYPE 
        FROM information_schema.COLUMNS 
        WHERE TABLE_SCHEMA = DATABASE() 
        AND TABLE_NAME = 'notifications' 
        AND COLUMN_NAME = 'type'
    ");
    $stmt->execute();
    $result = $stmt->fetch();
    
    if ($result) {
        $currentEnumValues = $result['COLUMN_TYPE'];
        
        // Check if 'join_request' is missing
        if (strpos($currentEnumValues, 'join_request') === false) {
            // Parse the current ENUM values
            preg_match("/^enum\((.*)\)$/", $currentEnumValues, $matches);
            if (isset($matches[1])) {
                $values = $matches[1];
                // Add 'join_request' to the enum
                $newEnumValues = str_replace(')', ",'join_request')", $currentEnumValues);
                
                // Modify the enum type to include 'join_request'
                $conn->exec("
                    ALTER TABLE notifications 
                    MODIFY COLUMN type $newEnumValues NOT NULL
                ");
                
                echo "Added 'join_request' to notification types.<br>";
            }
        } else {
            echo "'join_request' notification type already exists.<br>";
        }
        
        // Check if 'join_approved' is missing
        if (strpos($currentEnumValues, 'join_approved') === false) {
            // Parse the current ENUM values
            preg_match("/^enum\((.*)\)$/", $currentEnumValues, $matches);
            if (isset($matches[1])) {
                $values = $matches[1];
                // Add 'join_approved' to the enum
                $newEnumValues = str_replace(')', ",'join_approved')", $currentEnumValues);
                
                // Modify the enum type to include 'join_approved'
                $conn->exec("
                    ALTER TABLE notifications 
                    MODIFY COLUMN type $newEnumValues NOT NULL
                ");
                
                echo "Added 'join_approved' to notification types.<br>";
            }
        } else {
            echo "'join_approved' notification type already exists.<br>";
        }
        
        // Check if 'join_rejected' is missing
        if (strpos($currentEnumValues, 'join_rejected') === false) {
            // Get the current enum values again (in case they were modified)
            $stmt = $conn->prepare("
                SELECT COLUMN_TYPE 
                FROM information_schema.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'notifications' 
                AND COLUMN_NAME = 'type'
            ");
            $stmt->execute();
            $result = $stmt->fetch();
            $currentEnumValues = $result['COLUMN_TYPE'];
            
            preg_match("/^enum\((.*)\)$/", $currentEnumValues, $matches);
            if (isset($matches[1])) {
                $values = $matches[1];
                // Add 'join_rejected' to the enum
                $newEnumValues = str_replace(')', ",'join_rejected')", $currentEnumValues);
                
                // Modify the enum type to include 'join_rejected'
                $conn->exec("
                    ALTER TABLE notifications 
                    MODIFY COLUMN type $newEnumValues NOT NULL
                ");
                
                echo "Added 'join_rejected' to notification types.<br>";
            }
        } else {
            echo "'join_rejected' notification type already exists.<br>";
        }
    }
    
    // Check if related_user_id column exists
    $stmt = $conn->prepare("
        SELECT COUNT(*) as column_exists 
        FROM information_schema.COLUMNS 
        WHERE TABLE_SCHEMA = DATABASE() 
        AND TABLE_NAME = 'notifications' 
        AND COLUMN_NAME = 'related_user_id'
    ");
    $stmt->execute();
    $columnExists = (bool)$stmt->fetch()['column_exists'];
    
    if (!$columnExists) {
        // Add related_user_id column
        $conn->exec("
            ALTER TABLE notifications
            ADD COLUMN related_user_id INT DEFAULT NULL AFTER related_id,
            ADD CONSTRAINT fk_notifications_related_user_id
            FOREIGN KEY (related_user_id) REFERENCES users(user_id) ON DELETE SET NULL
        ");
        
        echo "Added related_user_id column to notifications table.<br>";
    } else {
        echo "related_user_id column already exists in notifications table.<br>";
    }
    
    echo "<p>Database updated successfully!</p>";
    echo "<a href='pages/core/dashboard.php' class='btn btn-primary'>Return to Dashboard</a>";
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?> 