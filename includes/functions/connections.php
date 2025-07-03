<?php
/**
 * Connection Functions
 * 
 * This file contains functions for managing user connections.
 */

/**
 * Get connection status between two users
 * 
 * @param PDO $conn Database connection
 * @param int $userId First user ID
 * @param int $targetUserId Second user ID
 * @return array|null Connection details or null if no connection exists
 */
function getConnectionStatusData($conn, $userId, $targetUserId) {
    $stmt = $conn->prepare("
        SELECT * FROM connections 
        WHERE (user_id = ? AND connected_user_id = ?) 
        OR (user_id = ? AND connected_user_id = ?)
    ");
    $stmt->execute([$userId, $targetUserId, $targetUserId, $userId]);
    $connection = $stmt->fetch();
    
    if ($connection) {
        // Add a direction field to indicate who sent the request
        $connection['direction'] = ($connection['user_id'] == $userId) ? 'sent' : 'received';
    }
    
    return $connection;
}

/**
 * Request a connection with another user
 * 
 * @param PDO $conn Database connection
 * @param int $userId User ID requesting connection
 * @param int $targetUserId Target user ID
 * @return int|bool The new connection ID or false on failure
 */
function requestConnection($conn, $userId, $targetUserId) {
    // Check if connection already exists
    $existingConnection = getConnectionStatusData($conn, $userId, $targetUserId);
    if ($existingConnection) {
        return false;
    }
    
    $stmt = $conn->prepare("
        INSERT INTO connections (user_id, connected_user_id, status, created_at)
        VALUES (?, ?, 'pending', CURRENT_TIMESTAMP)
    ");
    $success = $stmt->execute([$userId, $targetUserId]);
    
    return $success ? $conn->lastInsertId() : false;
}

/**
 * Accept a connection request
 * 
 * @param PDO $conn Database connection
 * @param int $connectionId Connection ID
 * @param int $userId User ID accepting the request (for security check)
 * @return bool Success status
 */
function acceptConnection($conn, $connectionId, $userId) {
    $stmt = $conn->prepare("
        UPDATE connections
        SET status = 'accepted', updated_at = CURRENT_TIMESTAMP
        WHERE connection_id = ? AND connected_user_id = ? AND status = 'pending'
    ");
    $stmt->execute([$connectionId, $userId]);
    
    return $stmt->rowCount() > 0;
}

/**
 * Reject a connection request
 * 
 * @param PDO $conn Database connection
 * @param int $connectionId Connection ID
 * @param int $userId User ID rejecting the request (for security check)
 * @return bool Success status
 */
function rejectConnection($conn, $connectionId, $userId) {
    $stmt = $conn->prepare("
        UPDATE connections
        SET status = 'rejected', updated_at = CURRENT_TIMESTAMP
        WHERE connection_id = ? AND connected_user_id = ? AND status = 'pending'
    ");
    $stmt->execute([$connectionId, $userId]);
    
    return $stmt->rowCount() > 0;
}

/**
 * Remove a connection
 * 
 * @param PDO $conn Database connection
 * @param int $userId User ID
 * @param int $targetUserId Target user ID
 * @return bool Success status
 */
function removeConnection($conn, $userId, $targetUserId) {
    $stmt = $conn->prepare("
        DELETE FROM connections
        WHERE (user_id = ? AND connected_user_id = ?) 
        OR (user_id = ? AND connected_user_id = ?)
    ");
    $stmt->execute([$userId, $targetUserId, $targetUserId, $userId]);
    
    return $stmt->rowCount() > 0;
}

/**
 * Get user's connections
 * 
 * @param PDO $conn Database connection
 * @param int $userId User ID
 * @param string $status Filter by status ('pending', 'accepted', 'rejected')
 * @param string $direction Filter by direction ('sent', 'received', 'all')
 * @return array Array of connections
 */
function getUserConnections($conn, $userId, $status = 'accepted', $direction = 'all') {
    $query = "
        SELECT c.*, 
               u.user_id, u.first_name, u.last_name, u.email, u.profile_image,
               CASE 
                   WHEN c.user_id = ? THEN 'sent'
                   ELSE 'received'
               END as direction
        FROM connections c
    ";
    
    if ($direction === 'sent') {
        $query .= " JOIN users u ON c.connected_user_id = u.user_id WHERE c.user_id = ?";
        $params = [$userId, $userId];
    } elseif ($direction === 'received') {
        $query .= " JOIN users u ON c.user_id = u.user_id WHERE c.connected_user_id = ?";
        $params = [$userId, $userId];
    } else {
        $query .= "
            LEFT JOIN users u ON 
                CASE 
                    WHEN c.user_id = ? THEN c.connected_user_id = u.user_id
                    ELSE c.user_id = u.user_id
                END
            WHERE (c.user_id = ? OR c.connected_user_id = ?)
        ";
        $params = [$userId, $userId, $userId, $userId];
    }
    
    if ($status !== 'all') {
        $query .= " AND c.status = ?";
        $params[] = $status;
    }
    
    $query .= " ORDER BY c.created_at DESC";
    
    $stmt = $conn->prepare($query);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?> 