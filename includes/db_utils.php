<?php
/**
 * Database Utilities Class
 * 
 * This class is deprecated. Use the functions in includes/functions/db.php, 
 * includes/functions/maintenance.php, and other function files instead.
 * 
 * This file is kept for backward compatibility.
 */

// Include the new function files if they haven't been included already
require_once 'functions.php';

/**
 * @deprecated Use functions in includes/functions/db.php instead
 */
class DbUtils {
    /**
     * @deprecated Use executeTransaction() function instead
     */
    public static function executeTransaction($conn, $callback, $logErrors = true) {
        return executeTransaction($conn, $callback, $logErrors);
    }
    
    /**
     * @deprecated Use userExists() function instead
     */
    public static function userExists($conn, $userId) {
        return userExists($conn, $userId);
    }
    
    /**
     * @deprecated Use allUsersExist() function instead
     */
    public static function allUsersExist($conn, $userIds) {
        return allUsersExist($conn, $userIds);
    }
    
    /**
     * @deprecated Use getConnectionStatusData() function instead
     */
    public static function getConnectionStatus($conn, $userId, $targetUserId) {
        return getConnectionStatusData($conn, $userId, $targetUserId);
    }
    
    /**
     * @deprecated Use createNotification() function instead
     */
    public static function createNotification($conn, $userId, $type, $message, $relatedId = null, $relatedUserId = null) {
        return createNotification($conn, $userId, $type, $message, $relatedId, $relatedUserId);
    }
    
    /**
     * @deprecated Use getUserFullName() function instead
     */
    public static function getUserFullName($conn, $userId) {
        return getUserFullName($conn, $userId);
    }
    
    /**
     * @deprecated Use cleanupOrphanedRecords() function instead
     */
    public static function cleanupOrphanedRecords($conn, $dryRun = false) {
        return cleanupOrphanedRecords($conn, $dryRun);
    }
}
?> 