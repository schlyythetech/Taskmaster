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

// Create a test join request notification
try {
    // Get current user ID
    $user_id = $_SESSION['user_id'];
    
    // First, check if the type column accepts 'join_request'
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
    
    // Check if join_request is in the enum
    if (strpos($typeEnum, 'join_request') === false) {
        echo "Error: 'join_request' is not in the notification type enum. Please run add_join_request_type.php first.<br>";
        echo "Current enum values: " . $typeEnum;
        exit;
    }
    
    // Find a valid project for testing
    $stmt = $conn->prepare("
        SELECT p.project_id, p.name, u.user_id, u.first_name, u.last_name
        FROM projects p
        JOIN users u ON p.owner_id = u.user_id
        WHERE p.owner_id = ?
        LIMIT 1
    ");
    $stmt->execute([$user_id]);
    $project = $stmt->fetch();
    
    if (!$project) {
        echo "Error: No projects found where you are the owner. Please create a project first.";
        exit;
    }
    
    // Find another user who is not the owner
    $stmt = $conn->prepare("
        SELECT user_id, first_name, last_name
        FROM users
        WHERE user_id != ?
        LIMIT 1
    ");
    $stmt->execute([$user_id]);
    $otherUser = $stmt->fetch();
    
    if (!$otherUser) {
        echo "Error: No other users found in the system.";
        exit;
    }
    
    // Delete any existing test notifications
    $stmt = $conn->prepare("
        DELETE FROM notifications
        WHERE type = 'join_request' AND related_id = ? AND related_user_id = ?
    ");
    $stmt->execute([$project['project_id'], $otherUser['user_id']]);
    
    // Create the notification
    $message = $otherUser['first_name'] . ' ' . $otherUser['last_name'] . " has requested to join " . $project['name'] . ".";
    $stmt = $conn->prepare("
        INSERT INTO notifications (user_id, type, message, related_id, related_user_id, created_at)
        VALUES (?, 'join_request', ?, ?, ?, NOW())
    ");
    $stmt->execute([$user_id, $message, $project['project_id'], $otherUser['user_id']]);
    
    $notificationId = $conn->lastInsertId();
    
    echo "Test join request notification created successfully!<br>";
    echo "Notification ID: " . $notificationId . "<br>";
    echo "Message: " . $message . "<br>";
    echo "<p>You should now see this notification when you click the bell icon.</p>";
    echo "<a href='pages/core/dashboard.php' class='btn btn-primary'>Go to Dashboard</a>";
    
} catch (PDOException $e) {
    echo "Error creating test notification: " . $e->getMessage();
}
?> 