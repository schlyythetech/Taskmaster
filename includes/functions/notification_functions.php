<?php
/**
 * Notification Functions
 * 
 * This file contains functions for creating and managing notifications.
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
    try {
        $stmt = $conn->prepare("
            INSERT INTO notifications (user_id, type, message, related_id, related_user_id, created_at) 
            VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([$userId, $type, $message, $relatedId, $relatedUserId]);
        
        return $conn->lastInsertId();
    } catch (PDOException $e) {
        error_log("Error creating notification: " . $e->getMessage());
        return false;
    }
}

/**
 * Create a task assignment notification
 * 
 * @param PDO $conn Database connection
 * @param int $assigneeId User ID of the assignee
 * @param int $taskId Task ID
 * @param string $taskTitle Task title
 * @param int $assignerId User ID of the assigner
 * @return int|bool The new notification ID or false on failure
 */
function createTaskAssignmentNotification($conn, $assigneeId, $taskId, $taskTitle, $assignerId) {
    $message = "You've been assigned a new task: " . $taskTitle;
    return createNotification($conn, $assigneeId, 'task_assigned', $message, $taskId, $assignerId);
}

/**
 * Create a task completion notification
 * 
 * @param PDO $conn Database connection
 * @param int $creatorId User ID of the task creator
 * @param int $taskId Task ID
 * @param string $taskTitle Task title
 * @param int $completerId User ID of the completer
 * @return int|bool The new notification ID or false on failure
 */
function createTaskCompletionNotification($conn, $creatorId, $taskId, $taskTitle, $completerId) {
    $message = "Your task has been marked as completed: " . $taskTitle;
    return createNotification($conn, $creatorId, 'task_completed', $message, $taskId, $completerId);
}

/**
 * Create a connection request notification
 * 
 * @param PDO $conn Database connection
 * @param int $requestedUserId User ID of the requested user
 * @param int $requesterId User ID of the requester
 * @param string $requesterName Name of the requester
 * @return int|bool The new notification ID or false on failure
 */
function createConnectionRequestNotification($conn, $requestedUserId, $requesterId, $requesterName) {
    $message = $requesterName . " sent you a connection request.";
    return createNotification($conn, $requestedUserId, 'connection_request', $message, null, $requesterId);
}

/**
 * Create a project invitation notification
 * 
 * @param PDO $conn Database connection
 * @param int $invitedUserId User ID of the invited user
 * @param int $projectId Project ID
 * @param string $projectName Project name
 * @param int $inviterId User ID of the inviter
 * @param string $inviterName Name of the inviter
 * @return int|bool The new notification ID or false on failure
 */
function createProjectInvitationNotification($conn, $invitedUserId, $projectId, $projectName, $inviterId, $inviterName) {
    $message = $inviterName . " has invited you to join " . $projectName . ".";
    return createNotification($conn, $invitedUserId, 'project_invite', $message, $projectId, $inviterId);
}

/**
 * Create a project join request notification
 * 
 * @param PDO $conn Database connection
 * @param int $projectOwnerId User ID of the project owner
 * @param int $projectId Project ID
 * @param string $projectName Project name
 * @param int $requesterId User ID of the requester
 * @param string $requesterName Name of the requester
 * @return int|bool The new notification ID or false on failure
 */
function createProjectJoinRequestNotification($conn, $projectOwnerId, $projectId, $projectName, $requesterId, $requesterName) {
    $message = $requesterName . " has requested to join " . $projectName . ".";
    return createNotification($conn, $projectOwnerId, 'join_request', $message, $projectId, $requesterId);
}

/**
 * Create a comment notification
 * 
 * @param PDO $conn Database connection
 * @param int $taskOwnerId User ID of the task owner
 * @param int $taskId Task ID
 * @param string $taskTitle Task title
 * @param int $commenterId User ID of the commenter
 * @param string $commenterName Name of the commenter
 * @return int|bool The new notification ID or false on failure
 */
function createCommentNotification($conn, $taskOwnerId, $taskId, $taskTitle, $commenterId, $commenterName) {
    $message = $commenterName . " commented on your task: " . $taskTitle;
    return createNotification($conn, $taskOwnerId, 'comment', $message, $taskId, $commenterId);
}

/**
 * Get unread notification count for a user
 * 
 * @param PDO $conn Database connection
 * @param int $userId User ID
 * @return int Number of unread notifications
 */
function getUnreadNotificationCount($conn, $userId) {
    try {
        $stmt = $conn->prepare("
            SELECT COUNT(*) as count 
            FROM notifications 
            WHERE user_id = ? AND is_read = 0
        ");
        $stmt->execute([$userId]);
        $result = $stmt->fetch();
        
        return (int)$result['count'];
    } catch (PDOException $e) {
        error_log("Error counting unread notifications: " . $e->getMessage());
        return 0;
    }
}

/**
 * Mark all notifications as read for a user
 * 
 * @param PDO $conn Database connection
 * @param int $userId User ID
 * @return bool Success status
 */
function markAllNotificationsAsRead($conn, $userId) {
    try {
        $stmt = $conn->prepare("
            UPDATE notifications 
            SET is_read = TRUE 
            WHERE user_id = ? AND is_read = FALSE
        ");
        $stmt->execute([$userId]);
        
        return true;
    } catch (PDOException $e) {
        error_log("Error marking all notifications as read: " . $e->getMessage());
        return false;
    }
}

/**
 * Delete old notifications for a user
 * 
 * @param PDO $conn Database connection
 * @param int $userId User ID
 * @param int $daysOld Number of days old to delete
 * @return bool Success status
 */
function deleteOldNotifications($conn, $userId, $daysOld = 30) {
    try {
        $stmt = $conn->prepare("
            DELETE FROM notifications 
            WHERE user_id = ? AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY) AND is_read = TRUE
        ");
        $stmt->execute([$userId, $daysOld]);
        
        return true;
    } catch (PDOException $e) {
        error_log("Error deleting old notifications: " . $e->getMessage());
        return false;
    }
}
?> 