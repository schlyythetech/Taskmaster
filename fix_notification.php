<?php
// Fix notification script
require_once 'config/database.php';

// Update the notification type
$stmt = $conn->prepare("
    UPDATE notifications 
    SET type = 'leave_project' 
    WHERE message LIKE '%requested to leave%' AND (type IS NULL OR type = '')
");
$stmt->execute();
$count = $stmt->rowCount();

echo "Fixed $count leave request notifications<br>";

// Check if the update was successful
$stmt = $conn->prepare("
    SELECT notification_id, user_id, type, message, related_id, related_user_id
    FROM notifications
    WHERE message LIKE '%requested to leave%'
");
$stmt->execute();
$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<pre>";
print_r($notifications);
echo "</pre>";

echo "<p>Done. You can now <a href='pages/notifications/all.php'>view your notifications</a>.</p>"; 