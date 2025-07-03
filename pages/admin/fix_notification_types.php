<?php
// Connect to database
require_once 'config/database.php';
require_once 'includes/functions.php';

// Check if user is logged in
if (!isLoggedIn()) {
    setMessage("You must be logged in to access this page.", "danger");
    redirect('pages/core/dashboard.php');
    exit;
}

try {
    // First, check the current enum values
    $stmt = $conn->prepare("
        SELECT COLUMN_TYPE 
        FROM information_schema.COLUMNS 
        WHERE TABLE_SCHEMA = DATABASE() 
        AND TABLE_NAME = 'notifications' 
        AND COLUMN_NAME = 'type'
    ");
    $stmt->execute();
    $result = $stmt->fetch();
    $typeEnum = $result['COLUMN_TYPE'];
    
    echo "Current notification type enum: " . $typeEnum . "<br>";
    
    // Find notifications with empty type that are join requests
    $stmt = $conn->prepare("
        SELECT notification_id, message 
        FROM notifications 
        WHERE (type = '' OR type IS NULL) 
        AND (message LIKE '%requested to join%' OR message LIKE '%request to join%')
    ");
    $stmt->execute();
    $emptyTypeNotifications = $stmt->fetchAll();
    
    if (count($emptyTypeNotifications) > 0) {
        echo "Found " . count($emptyTypeNotifications) . " join request notifications with empty type.<br>";
        
        // Fix them by setting the type to 'join_request'
        $conn->beginTransaction();
        
        $stmt = $conn->prepare("
            UPDATE notifications 
            SET type = 'join_request' 
            WHERE notification_id = ?
        ");
        
        foreach ($emptyTypeNotifications as $notification) {
            $stmt->execute([$notification['notification_id']]);
            echo "Fixed notification #" . $notification['notification_id'] . ": " . $notification['message'] . "<br>";
        }
        
        $conn->commit();
        echo "All join request notifications fixed!<br>";
    } else {
        echo "No join request notifications with empty type found.<br>";
    }
    
    // Find notifications with empty type
    $stmt = $conn->prepare("
        SELECT COUNT(*) as count
        FROM notifications 
        WHERE type = '' OR type IS NULL
    ");
    $stmt->execute();
    $emptyTypeCount = $stmt->fetch()['count'];
    
    if ($emptyTypeCount > 0) {
        echo "Found " . $emptyTypeCount . " notifications with empty type (not join requests).<br>";
    } else {
        echo "No other notifications with empty type found.<br>";
    }
    
    echo "<p>Database check completed.</p>";
    echo "<a href='pages/core/dashboard.php' class='btn btn-primary'>Return to Dashboard</a>";
    
} catch (PDOException $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    echo "Error: " . $e->getMessage();
}
?> 