<?php
/**
 * Notification Functions
 * 
 * This file contains functions for handling notifications.
 */

/**
 * Create a notification
 * 
 * @param PDO $conn Database connection
 * @param int $userId User ID to receive the notification
 * @param string $type Notification type
 * @param string $message Notification message
 * @param int|null $relatedId Related entity ID (optional)
 * @param int|null $relatedUserId Related user ID (optional)
 * @return int|bool The new notification ID or false on failure
 */
function createNotification($conn, $userId, $type, $message, $relatedId = null, $relatedUserId = null) {
    $stmt = $conn->prepare("
        INSERT INTO notifications (user_id, type, message, related_id, related_user_id, created_at) 
        VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
    ");
    $success = $stmt->execute([$userId, $type, $message, $relatedId, $relatedUserId]);
    
    return $success ? $conn->lastInsertId() : false;
}

/**
 * Get notifications for a user
 * 
 * @param PDO $conn Database connection
 * @param int $userId User ID
 * @param int $limit Maximum number of notifications to retrieve (default: 10)
 * @param int $offset Offset for pagination (default: 0)
 * @return array Array of notification objects
 */
function getUserNotifications($conn, $userId, $limit = 10, $offset = 0) {
    $stmt = $conn->prepare("
        SELECT * FROM notifications 
        WHERE user_id = ? 
        ORDER BY created_at DESC 
        LIMIT ? OFFSET ?
    ");
    $stmt->bindParam(1, $userId, PDO::PARAM_INT);
    $stmt->bindParam(2, $limit, PDO::PARAM_INT);
    $stmt->bindParam(3, $offset, PDO::PARAM_INT);
    $stmt->execute();
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Mark notification as read
 * 
 * @param PDO $conn Database connection
 * @param int $notificationId Notification ID
 * @param int $userId User ID (for security check)
 * @return bool Success status
 */
function markNotificationAsRead($conn, $notificationId, $userId) {
    $stmt = $conn->prepare("
        UPDATE notifications 
        SET is_read = 1 
        WHERE notification_id = ? AND user_id = ?
    ");
    $stmt->execute([$notificationId, $userId]);
    
    return $stmt->rowCount() > 0;
}

/**
 * Count unread notifications for a user
 * 
 * @param PDO $conn Database connection
 * @param int $userId User ID
 * @return int Number of unread notifications
 */
function countUnreadNotifications($conn, $userId) {
    $stmt = $conn->prepare("
        SELECT COUNT(*) as count 
        FROM notifications 
        WHERE user_id = ? AND is_read = 0
    ");
    $stmt->execute([$userId]);
    $result = $stmt->fetch();
    
    return (int)$result['count'];
}

/**
 * Delete notification
 * 
 * @param PDO $conn Database connection
 * @param int $notificationId Notification ID
 * @param int $userId User ID (for security check)
 * @return bool Success status
 */
function deleteNotification($conn, $notificationId, $userId) {
    $stmt = $conn->prepare("
        DELETE FROM notifications 
        WHERE notification_id = ? AND user_id = ?
    ");
    $stmt->execute([$notificationId, $userId]);
    
    return $stmt->rowCount() > 0;
}
?> 