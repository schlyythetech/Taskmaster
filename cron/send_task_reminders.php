<?php
/**
 * Daily Task Reminder Cron Job
 * 
 * This script sends daily email reminders for assigned tasks.
 * It should be set up to run once per day via cron.
 * 
 * Example cron entry (runs at 7:00 AM daily):
 * 0 7 * * * php /path/to/taskmaster/cron/send_task_reminders.php
 */

// Set script execution time limit (5 minutes)
set_time_limit(300);

// Load required files
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

// Log file for debugging
$log_file = __DIR__ . '/task_reminder_log.txt';

// Function to log messages
function log_message($message) {
    global $log_file;
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($log_file, "[$timestamp] $message" . PHP_EOL, FILE_APPEND);
}

log_message("Task reminder cron job started");

try {
    // Get all users who have email notifications enabled
    $stmt = $conn->prepare("
        SELECT u.user_id, u.email, u.first_name, u.last_name, u.is_banned
        FROM users u
        JOIN user_settings us ON u.user_id = us.user_id
        WHERE u.is_banned = 0 
        AND JSON_EXTRACT(us.notification_preferences, '$.email_notifications') = true
    ");
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    log_message("Found " . count($users) . " users with email notifications enabled");
    
    // If no users have email notifications enabled, check all users
    if (count($users) === 0) {
        log_message("No users found with explicit email notification settings, checking all users");
        
        // Get all active users
        $stmt = $conn->prepare("
            SELECT user_id, email, first_name, last_name
            FROM users
            WHERE is_banned = 0
        ");
        $stmt->execute();
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    $total_emails_sent = 0;
    
    foreach ($users as $user) {
        // Get assigned tasks for this user
        $stmt = $conn->prepare("
            SELECT t.task_id, t.title, t.description, t.status, t.priority, t.due_date,
                   p.project_id, p.name AS project_name
            FROM tasks t
            JOIN projects p ON t.project_id = p.project_id
            JOIN task_assignees ta ON t.task_id = ta.task_id
            WHERE ta.user_id = ?
            AND t.status != 'completed'
            AND (t.due_date IS NULL OR t.due_date >= CURDATE())
            ORDER BY 
                CASE 
                    WHEN t.due_date IS NULL THEN 1 
                    ELSE 0 
                END,
                t.due_date ASC,
                CASE t.priority
                    WHEN 'high' THEN 1
                    WHEN 'medium' THEN 2
                    WHEN 'low' THEN 3
                    ELSE 4
                END
        ");
        $stmt->execute([$user['user_id']]);
        $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Skip users with no tasks
        if (count($tasks) === 0) {
            log_message("User ID {$user['user_id']} has no active tasks or all tasks have passed their deadline, skipping");
            continue;
        }
        
        // Mark tasks with approaching deadlines (within 2 days)
        foreach ($tasks as &$task) {
            if (!empty($task['due_date'])) {
                $due_date = new DateTime($task['due_date']);
                $today = new DateTime();
                $interval = $today->diff($due_date);
                $days_remaining = $interval->days;
                
                // Add days_remaining to task data
                $task['days_remaining'] = $days_remaining;
                
                // Flag tasks with approaching deadlines
                if ($days_remaining <= 2) {
                    $task['deadline_approaching'] = true;
                } else {
                    $task['deadline_approaching'] = false;
                }
            } else {
                $task['days_remaining'] = null;
                $task['deadline_approaching'] = false;
            }
        }
        unset($task); // Remove reference
        
        log_message("Sending reminder to {$user['email']} for " . count($tasks) . " tasks");
        
        // Send email reminder
        $user_name = $user['first_name'] . ' ' . $user['last_name'];
        $result = sendTaskReminderEmail($user['email'], $user_name, $tasks);
        
        if ($result) {
            log_message("Successfully sent reminder email to {$user['email']}");
            $total_emails_sent++;
        } else {
            log_message("Failed to send reminder email to {$user['email']}");
        }
        
        // Pause briefly to avoid overwhelming the mail server
        usleep(100000); // 100ms pause
    }
    
    log_message("Task reminder cron job completed. Sent $total_emails_sent emails.");
    
} catch (PDOException $e) {
    log_message("Database error: " . $e->getMessage());
} catch (Exception $e) {
    log_message("General error: " . $e->getMessage());
}
?> 