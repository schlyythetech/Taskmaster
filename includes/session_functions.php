<?php
/**
 * Session Functions
 * 
 * Functions for tracking user session time and calculating hours spent
 */

/**
 * Record a new user login session
 * 
 * @param PDO $conn Database connection
 * @param int $user_id User ID
 * @return int|bool Session ID or false on failure
 */
function recordUserLogin($conn, $user_id) {
    try {
        // Check if user has an active session
        $stmt = $conn->prepare("SELECT session_id FROM user_sessions WHERE user_id = ? AND is_active = 1");
        $stmt->execute([$user_id]);
        $active_session = $stmt->fetch();
        
        if ($active_session) {
            // User already has an active session, update it
            $stmt = $conn->prepare("
                UPDATE user_sessions 
                SET login_time = CURRENT_TIMESTAMP, 
                    logout_time = NULL, 
                    duration_minutes = NULL 
                WHERE session_id = ?
            ");
            $stmt->execute([$active_session['session_id']]);
            return $active_session['session_id'];
        } else {
            // Create a new session
            $stmt = $conn->prepare("
                INSERT INTO user_sessions (user_id, login_time, is_active) 
                VALUES (?, CURRENT_TIMESTAMP, 1)
            ");
            $stmt->execute([$user_id]);
            return $conn->lastInsertId();
        }
    } catch (PDOException $e) {
        error_log("Error recording user login: " . $e->getMessage());
        return false;
    }
}

/**
 * Record user logout and calculate session duration
 * 
 * @param PDO $conn Database connection
 * @param int $user_id User ID
 * @return bool Success or failure
 */
function recordUserLogout($conn, $user_id) {
    try {
        // Find user's active session
        $stmt = $conn->prepare("SELECT session_id, login_time FROM user_sessions WHERE user_id = ? AND is_active = 1");
        $stmt->execute([$user_id]);
        $session = $stmt->fetch();
        
        if (!$session) {
            // No active session found
            return false;
        }
        
        // Update session with logout time and calculate duration
        $stmt = $conn->prepare("
            UPDATE user_sessions 
            SET logout_time = CURRENT_TIMESTAMP,
                duration_minutes = CASE 
                    WHEN TIMESTAMPDIFF(MINUTE, login_time, CURRENT_TIMESTAMP) > 0 
                    THEN TIMESTAMPDIFF(MINUTE, login_time, CURRENT_TIMESTAMP)
                    ELSE 0
                END,
                is_active = 0
            WHERE session_id = ?
        ");
        $stmt->execute([$session['session_id']]);
        
        return true;
    } catch (PDOException $e) {
        error_log("Error recording user logout: " . $e->getMessage());
        return false;
    }
}

/**
 * Get total hours spent by user
 * 
 * @param PDO $conn Database connection
 * @param int $user_id User ID
 * @return array Total hours and minutes spent
 */
function getUserHoursSpent($conn, $user_id) {
    try {
        // Get total minutes from completed sessions
        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(duration_minutes), 0) as total_minutes 
            FROM user_sessions 
            WHERE user_id = ? AND duration_minutes IS NOT NULL AND duration_minutes > 0
        ");
        $stmt->execute([$user_id]);
        $result = $stmt->fetch();
        $total_minutes = (float)$result['total_minutes'];
        
        // Get minutes from active session if exists
        $stmt = $conn->prepare("
            SELECT login_time 
            FROM user_sessions 
            WHERE user_id = ? AND is_active = 1
        ");
        $stmt->execute([$user_id]);
        $active_session = $stmt->fetch();
        
        if ($active_session) {
            // Calculate minutes from active session
            $login_time = strtotime($active_session['login_time']);
            $current_time = time();
            
            // Ensure login_time is not in the future
            if ($login_time <= $current_time) {
                $active_minutes = max(0, ($current_time - $login_time) / 60);
                $total_minutes += $active_minutes;
            }
        }
        
        // Ensure total_minutes is not negative
        $total_minutes = max(0, $total_minutes);
        
        // Calculate hours and remaining minutes
        $hours = floor($total_minutes / 60);
        $minutes = round($total_minutes % 60);
        
        // If minutes is 60, adjust to next hour
        if ($minutes == 60) {
            $hours++;
            $minutes = 0;
        }
        
        return [
            'hours' => $hours,
            'minutes' => $minutes,
            'total_minutes' => $total_minutes,
            'formatted' => $hours . 'h ' . $minutes . 'm'
        ];
    } catch (PDOException $e) {
        error_log("Error getting user hours spent: " . $e->getMessage());
        return [
            'hours' => 0,
            'minutes' => 0,
            'total_minutes' => 0,
            'formatted' => '0h 0m'
        ];
    }
}

/**
 * Get hours spent by user for each day of the week
 * 
 * @param PDO $conn Database connection
 * @param int $user_id User ID
 * @return array Hours spent by day of week
 */
function getUserHoursSpentByDay($conn, $user_id) {
    try {
        // Initialize array with zeros for each day (0=Sunday to 6=Saturday)
        $hours_by_day = [0, 0, 0, 0, 0, 0, 0];
        
        // Get minutes from completed sessions grouped by day of week
        // DAYOFWEEK in MySQL: 1=Sunday, 2=Monday, etc.
        $stmt = $conn->prepare("
            SELECT 
                DAYOFWEEK(DATE(login_time)) as day_of_week,
                COALESCE(SUM(duration_minutes), 0) as total_minutes
            FROM user_sessions 
            WHERE user_id = ? 
                AND duration_minutes IS NOT NULL 
                AND duration_minutes > 0
                AND login_time >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
            GROUP BY DAYOFWEEK(DATE(login_time))
        ");
        $stmt->execute([$user_id]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Fill in the data
        foreach ($results as $day) {
            $index = $day['day_of_week'] - 1; // Convert to 0-based index
            $hours_by_day[$index] = round($day['total_minutes'] / 60, 1); // Convert minutes to hours with 1 decimal
        }
        
        // Handle active session for today
        $stmt = $conn->prepare("
            SELECT login_time 
            FROM user_sessions 
            WHERE user_id = ? AND is_active = 1
        ");
        $stmt->execute([$user_id]);
        $active_session = $stmt->fetch();
        
        if ($active_session) {
            // Calculate minutes from active session
            $login_time = strtotime($active_session['login_time']);
            $current_time = time();
            
            // Ensure login_time is not in the future
            if ($login_time <= $current_time) {
                $active_minutes = max(0, ($current_time - $login_time) / 60);
                
                // Add active minutes to today's count
                $today_index = date('w'); // 0=Sunday, 6=Saturday
                $hours_by_day[$today_index] += round($active_minutes / 60, 1);
            }
        }
        
        return $hours_by_day;
    } catch (PDOException $e) {
        error_log("Error getting user hours spent by day: " . $e->getMessage());
        return [0, 0, 0, 0, 0, 0, 0];
    }
}

/**
 * Create user_sessions table if it doesn't exist
 * 
 * @param PDO $conn Database connection
 * @return bool Success or failure
 */
function ensureUserSessionsTable($conn) {
    try {
        $conn->exec("
            CREATE TABLE IF NOT EXISTS user_sessions (
                session_id INT NOT NULL AUTO_INCREMENT,
                user_id INT NOT NULL,
                login_time TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                logout_time TIMESTAMP NULL DEFAULT NULL,
                duration_minutes DECIMAL(10,2) DEFAULT NULL,
                is_active TINYINT(1) DEFAULT 1,
                PRIMARY KEY (session_id),
                KEY user_id (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
        
        return true;
    } catch (PDOException $e) {
        error_log("Error creating user_sessions table: " . $e->getMessage());
        return false;
    }
}

/**
 * Fix negative duration values in user_sessions table
 * 
 * @param PDO $conn Database connection
 * @return bool Success or failure
 */
function fixNegativeSessionDurations($conn) {
    try {
        // Update any negative duration values to 0
        $stmt = $conn->prepare("
            UPDATE user_sessions 
            SET duration_minutes = 0
            WHERE duration_minutes < 0
        ");
        $stmt->execute();
        
        return true;
    } catch (PDOException $e) {
        error_log("Error fixing negative session durations: " . $e->getMessage());
        return false;
    }
} 