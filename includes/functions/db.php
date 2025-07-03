<?php
/**
 * Database Functions
 * 
 * This file contains functions for database operations.
 */

/**
 * Check if a table exists in the database
 * @param PDO $conn
 * @param string $tableName
 * @return bool
 */
function tableExists($conn, $tableName) {
    try {
        $result = $conn->query("SHOW TABLES LIKE '$tableName'");
        return $result->rowCount() > 0;
    } catch (PDOException $e) {
        error_log("Error checking if table exists: " . $e->getMessage());
        return false;
    }
}

/**
 * Execute a database transaction with proper error handling
 * 
 * @param PDO $conn Database connection
 * @param callable $callback Function to execute within transaction
 * @param bool $logErrors Whether to log errors (default: true)
 * @return array Result array with success status and message
 */
function executeTransaction($conn, $callback, $logErrors = true) {
    // Start a transaction if one is not already active
    $wasInTransaction = $conn->inTransaction();
    if (!$wasInTransaction) {
        $conn->beginTransaction();
    }
    
    try {
        // Execute the callback
        $result = $callback($conn);
        
        // Commit if we started the transaction
        if (!$wasInTransaction) {
            $conn->commit();
        }
        
        return $result ?? ['success' => true, 'message' => 'Operation completed successfully'];
    } catch (PDOException $e) {
        // Roll back only if we started the transaction
        if (!$wasInTransaction && $conn->inTransaction()) {
            $conn->rollBack();
        }
        
        // Log the error
        if ($logErrors) {
            error_log("Database error: " . $e->getMessage());
        }
        
        return [
            'success' => false, 
            'message' => (defined('ENVIRONMENT') && ENVIRONMENT === 'development') ? 
                'Database error: ' . $e->getMessage() : 
                'A database error occurred. Please try again.'
        ];
    } catch (Exception $e) {
        // Roll back only if we started the transaction
        if (!$wasInTransaction && $conn->inTransaction()) {
            $conn->rollBack();
        }
        
        // Log the error
        if ($logErrors) {
            error_log("Error: " . $e->getMessage());
        }
        
        return [
            'success' => false, 
            'message' => (defined('ENVIRONMENT') && ENVIRONMENT === 'development') ? 
                'Error: ' . $e->getMessage() : 
                'An error occurred. Please try again.'
        ];
    }
}

/**
 * Check if a user exists in the database
 * 
 * @param PDO $conn Database connection
 * @param int $userId User ID to check
 * @return bool True if user exists, false otherwise
 */
function userExists($conn, $userId) {
    $stmt = $conn->prepare("SELECT user_id FROM users WHERE user_id = ?");
    $stmt->execute([$userId]);
    return $stmt->fetch() !== false;
}

/**
 * Check if multiple users exist in the database
 * 
 * @param PDO $conn Database connection
 * @param array $userIds Array of user IDs to check
 * @return bool True if all users exist, false otherwise
 */
function allUsersExist($conn, $userIds) {
    if (empty($userIds)) {
        return true;
    }
    
    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    $stmt = $conn->prepare("
        SELECT COUNT(*) as count, COUNT(DISTINCT user_id) as unique_count 
        FROM users 
        WHERE user_id IN ($placeholders)
    ");
    $stmt->execute($userIds);
    $result = $stmt->fetch();
    
    return $result['count'] === count($userIds) && $result['unique_count'] === count($userIds);
}

/**
 * Get user's full name
 * 
 * @param PDO $conn Database connection
 * @param int $userId User ID
 * @return string|null User's full name or null if user not found
 */
function getUserFullName($conn, $userId) {
    $stmt = $conn->prepare("SELECT first_name, last_name FROM users WHERE user_id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    
    if ($user) {
        return $user['first_name'] . ' ' . $user['last_name'];
    }
    
    return null;
}
?> 