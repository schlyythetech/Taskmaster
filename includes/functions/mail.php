<?php
/**
 * Mail Functions
 * 
 * This file contains functions for sending emails using PHPMailer.
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Send an email using PHPMailer
 * 
 * @param string $to Recipient email address
 * @param string $subject Email subject
 * @param string $message Email message (HTML)
 * @param array $options Additional options (cc, bcc, attachments)
 * @return bool Success or failure
 */
function sendMail($to, $subject, $message, $options = []) {
    global $mail_config;
    
    // Check if PHPMailer is installed
    if (!file_exists(__DIR__ . '/../../vendor/autoload.php')) {
        // PHPMailer not installed, use PHP's mail() function as fallback
        return sendMailFallback($to, $subject, $message, $options);
    }
    
    try {
        // Load Composer's autoloader
        require_once __DIR__ . '/../../vendor/autoload.php';
        
        // Create a new PHPMailer instance
        $mail = new PHPMailer(true);
        
        // Server settings
        $mail->isSMTP();
        $mail->Host = $mail_config['smtp_host'];
        $mail->SMTPAuth = $mail_config['smtp_auth'];
        $mail->Username = $mail_config['smtp_username'];
        $mail->Password = $mail_config['smtp_password'];
        $mail->SMTPSecure = $mail_config['smtp_secure'];
        $mail->Port = $mail_config['smtp_port'];
        
        // Recipients
        $mail->setFrom($mail_config['from_email'], $mail_config['from_name']);
        $mail->addAddress($to);
        $mail->addReplyTo($mail_config['reply_to']);
        
        // Add CC recipients if provided
        if (isset($options['cc']) && is_array($options['cc'])) {
            foreach ($options['cc'] as $cc) {
                $mail->addCC($cc);
            }
        }
        
        // Add BCC recipients if provided
        if (isset($options['bcc']) && is_array($options['bcc'])) {
            foreach ($options['bcc'] as $bcc) {
                $mail->addBCC($bcc);
            }
        }
        
        // Add attachments if provided
        if (isset($options['attachments']) && is_array($options['attachments'])) {
            foreach ($options['attachments'] as $attachment) {
                $mail->addAttachment($attachment);
            }
        }
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $message;
        $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $message));
        
        // Send the email
        $mail->send();
        
        return true;
    } catch (Exception $e) {
        error_log("Error sending email: " . $e->getMessage());
        
        // Try fallback method
        return sendMailFallback($to, $subject, $message, $options);
    }
}

/**
 * Fallback email function using PHP's mail()
 * 
 * @param string $to Recipient email address
 * @param string $subject Email subject
 * @param string $message Email message (HTML)
 * @param array $options Additional options (cc, bcc)
 * @return bool Success or failure
 */
function sendMailFallback($to, $subject, $message, $options = []) {
    global $mail_config;
    
    // Set content-type header for sending HTML email
    $headers = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
    $headers .= "From: {$mail_config['from_name']} <{$mail_config['from_email']}>" . "\r\n";
    $headers .= "Reply-To: {$mail_config['reply_to']}" . "\r\n";
    
    // Add CC recipients if provided
    if (isset($options['cc']) && is_array($options['cc'])) {
        $headers .= "Cc: " . implode(",", $options['cc']) . "\r\n";
    }
    
    // Add BCC recipients if provided
    if (isset($options['bcc']) && is_array($options['bcc'])) {
        $headers .= "Bcc: " . implode(",", $options['bcc']) . "\r\n";
    }
    
    // Send email
    return mail($to, $subject, $message, $headers);
}

/**
 * Send a ban notification email
 * 
 * @param string $to Recipient email address
 * @param string $userName User's name
 * @param string $banReason Reason for the ban
 * @return bool Success or failure
 */
function sendBanNotification($to, $userName, $banReason) {
    $subject = "Your TaskMaster Account Has Been Suspended";
    
    $message = "
    <html>
    <head>
        <title>Account Suspension</title>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 5px; }
            h2 { color: #d9534f; }
            .footer { margin-top: 30px; padding-top: 15px; border-top: 1px solid #eee; font-size: 12px; color: #777; }
        </style>
    </head>
    <body>
        <div class='container'>
            <h2>Account Suspension Notice</h2>
            <p>Hello $userName,</p>
            <p>We regret to inform you that your TaskMaster account has been suspended.</p>
            <p><strong>Reason for suspension:</strong> $banReason</p>
            <p>If you believe this is an error or would like to discuss this further, please contact our support team.</p>
            <div class='footer'>
                <p>Best regards,<br>The TaskMaster Team</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    return sendMail($to, $subject, $message);
}

/**
 * Send an unban notification email
 * 
 * @param string $to Recipient email address
 * @param string $userName User's name
 * @return bool Success or failure
 */
function sendUnbanNotification($to, $userName) {
    $subject = "Your TaskMaster Account Has Been Restored";
    
    $message = "
    <html>
    <head>
        <title>Account Restoration</title>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 5px; }
            h2 { color: #5cb85c; }
            .footer { margin-top: 30px; padding-top: 15px; border-top: 1px solid #eee; font-size: 12px; color: #777; }
        </style>
    </head>
    <body>
        <div class='container'>
            <h2>Account Restoration Notice</h2>
            <p>Hello $userName,</p>
            <p>We are pleased to inform you that your TaskMaster account has been restored.</p>
            <p>You can now log in and access all features of TaskMaster again.</p>
            <p>Thank you for your patience and understanding.</p>
            <div class='footer'>
                <p>Best regards,<br>The TaskMaster Team</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    return sendMail($to, $subject, $message);
}

/**
 * Send a project invitation email
 * 
 * @param string $to Recipient email address
 * @param string $inviteeName Invitee's name
 * @param string $inviterName Inviter's name
 * @param string $projectName Project name
 * @param string $token Invitation token
 * @return bool Success or failure
 */
function sendProjectInvitationEmail($to, $inviteeName, $inviterName, $projectName, $token) {
    $subject = "Invitation to join $projectName on TaskMaster";
    
    // Generate invitation link
    $invitationLink = getBaseUrl() . "/pages/projects/accept_invitation.php?token=" . $token;
    
    $message = "
    <html>
    <head>
        <title>Project Invitation</title>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 5px; }
            h2 { color: #3498db; }
            .btn { display: inline-block; padding: 10px 20px; background-color: #3498db; color: #ffffff; text-decoration: none; border-radius: 5px; }
            .footer { margin-top: 30px; padding-top: 15px; border-top: 1px solid #eee; font-size: 12px; color: #777; }
        </style>
    </head>
    <body>
        <div class='container'>
            <h2>Project Invitation</h2>
            <p>Hello $inviteeName,</p>
            <p>$inviterName has invited you to join <strong>$projectName</strong> on TaskMaster.</p>
            <p>Click the button below to accept the invitation:</p>
            <p style='text-align: center;'>
                <a href='$invitationLink' class='btn'>Accept Invitation</a>
            </p>
            <p>Or copy and paste this link into your browser:</p>
            <p>$invitationLink</p>
            <p>This invitation will expire in 30 days.</p>
            <div class='footer'>
                <p>Best regards,<br>The TaskMaster Team</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    return sendMail($to, $subject, $message);
}

/**
 * Send a task reminder email
 * 
 * @param string $to Recipient email address
 * @param string $userName User's name
 * @param array $tasks Array of task data
 * @return bool Success or failure
 */
function sendTaskReminderEmail($to, $userName, $tasks) {
    $subject = "Your TaskMaster Daily Task Reminder";
    
    // Start building the task list HTML
    $taskListHtml = "";
    foreach ($tasks as $task) {
        $taskUrl = getBaseUrl() . "/pages/tasks/view_task.php?id=" . $task['task_id'];
        $dueDate = !empty($task['due_date']) ? date("M j, Y", strtotime($task['due_date'])) : "No due date";
        $priorityColor = getPriorityColor($task['priority']);
        
        // Add deadline approaching warning
        $deadlineWarning = '';
        if (isset($task['deadline_approaching']) && $task['deadline_approaching']) {
            $daysText = isset($task['days_remaining']) && $task['days_remaining'] == 0 ? 'today' : 
                       (isset($task['days_remaining']) && $task['days_remaining'] == 1 ? 'tomorrow' : 
                       'in ' . $task['days_remaining'] . ' days');
            
            $deadlineWarning = "
            <div style='background-color: #fff3cd; color: #856404; padding: 8px; margin-top: 10px; border-radius: 4px; font-weight: bold;'>
                <i style='margin-right: 5px;'>⚠️</i> Deadline approaching - due $daysText!
            </div>";
        }
        
        $taskListHtml .= "
        <div style='margin-bottom: 15px; padding: 10px; border: 1px solid #ddd; border-radius: 5px; " . (isset($task['deadline_approaching']) && $task['deadline_approaching'] ? "border-left: 4px solid #dc3545;" : "") . "'>
            <h3 style='margin-top: 0;'>{$task['title']}</h3>
            <p style='margin: 5px 0;'><strong>Project:</strong> {$task['project_name']}</p>
            <p style='margin: 5px 0;'><strong>Due:</strong> $dueDate</p>
            <p style='margin: 5px 0;'><strong>Priority:</strong> <span style='color: $priorityColor;'>{$task['priority']}</span></p>
            <p style='margin: 5px 0;'><strong>Status:</strong> {$task['status']}</p>
            $deadlineWarning
        </div>
        ";
    }
    
    $message = "
    <html>
    <head>
        <title>Daily Task Reminder</title>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 5px; }
            h2 { color: #3498db; }
            .btn { display: inline-block; padding: 10px 20px; background-color: #3498db; color: #ffffff; text-decoration: none; border-radius: 5px; }
            .footer { margin-top: 30px; padding-top: 15px; border-top: 1px solid #eee; font-size: 12px; color: #777; }
        </style>
    </head>
    <body>
        <div class='container'>
            <h2>Daily Task Reminder</h2>
            <p>Hello $userName,</p>
            <p>Here's a reminder of your assigned tasks:</p>
            
            $taskListHtml
            
            <div class='footer'>
                <p>Best regards,<br>The TaskMaster Team</p>
                <p>You are receiving this email because you have enabled task reminders in your settings. To change your notification preferences, visit your account settings.</p>
                <p>Log in to your TaskMaster account to view and manage your tasks.</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    return sendMail($to, $subject, $message);
}

/**
 * Send a task assignment email notification
 * 
 * @param string $to Recipient email address
 * @param string $userName User's name
 * @param array $task Task data (title, description, due_date, priority, project_name, etc.)
 * @param array $assignedBy User who assigned the task
 * @param array $attachments Array of attachment file paths (optional)
 * @return bool Success or failure
 */
function sendTaskAssignmentEmail($to, $userName, $task, $assignedBy, $attachments = []) {
    $subject = "New Task Assignment: {$task['title']}";
    
    // Generate task URL
    $taskUrl = getBaseUrl() . "/pages/tasks/view_task.php?id=" . $task['task_id'];
    
    // Format due date
    $dueDate = !empty($task['due_date']) ? date("M j, Y", strtotime($task['due_date'])) : "No due date";
    
    // Get priority color
    $priorityColor = getPriorityColor($task['priority']);
    
    // Build attachments HTML if any
    $attachmentsHtml = "";
    if (!empty($attachments)) {
        $attachmentsHtml .= "<h3>Attachments:</h3><ul>";
        foreach ($attachments as $attachment) {
            $fileName = basename($attachment['file_path']);
            $fileUrl = getBaseUrl() . "/" . $attachment['file_path'];
            $attachmentsHtml .= "<li><a href='$fileUrl'>$fileName</a></li>";
        }
        $attachmentsHtml .= "</ul>";
    }
    
    $message = "
    <html>
    <head>
        <title>New Task Assignment</title>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 5px; }
            h2 { color: #3498db; }
            .task-details { background-color: #f9f9f9; padding: 15px; border-radius: 5px; margin: 15px 0; }
            .btn { display: inline-block; padding: 10px 20px; background-color: #3498db; color: #ffffff; text-decoration: none; border-radius: 5px; }
            .footer { margin-top: 30px; padding-top: 15px; border-top: 1px solid #eee; font-size: 12px; color: #777; }
        </style>
    </head>
    <body>
        <div class='container'>
            <h2>New Task Assignment</h2>
            <p>Hello $userName,</p>
            <p>You have been assigned a new task by {$assignedBy['first_name']} {$assignedBy['last_name']}.</p>
            
            <div class='task-details'>
                <h3 style='margin-top: 0;'>{$task['title']}</h3>
                <p><strong>Project:</strong> {$task['project_name']}</p>
                <p><strong>Due Date:</strong> $dueDate</p>
                <p><strong>Priority:</strong> <span style='color: $priorityColor;'>{$task['priority']}</span></p>
                <p><strong>Description:</strong></p>
                <div style='background-color: #fff; padding: 10px; border-radius: 3px; border: 1px solid #eee;'>
                    " . (empty($task['description']) ? "No description provided" : nl2br(htmlspecialchars($task['description']))) . "
                </div>
                
                $attachmentsHtml
            </div>
            
            <div class='footer'>
                <p>Best regards,<br>The TaskMaster Team</p>
                <p>You will receive daily reminders about this task until it is completed or the deadline is reached.</p>
                <p>Log in to your TaskMaster account to view and manage this task.</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    // Set up options with attachments for the email
    $options = [];
    if (!empty($attachments)) {
        $fileAttachments = [];
        foreach ($attachments as $attachment) {
            $fileAttachments[] = $attachment['file_path'];
        }
        $options['attachments'] = $fileAttachments;
    }
    
    return sendMail($to, $subject, $message, $options);
}

/**
 * Get the base URL of the application
 * 
 * @return string Base URL
 */
function getBaseUrl() {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $script = dirname($_SERVER['SCRIPT_NAME']);
    $base = rtrim(str_replace('\\', '/', $script), '/includes/functions');
    return "$protocol://$host$base";
}

/**
 * Get color for task priority
 * 
 * @param string $priority Task priority
 * @return string CSS color code
 */
function getPriorityColor($priority) {
    switch (strtolower($priority)) {
        case 'high':
            return '#e74c3c';
        case 'medium':
            return '#f39c12';
        case 'low':
            return '#2ecc71';
        default:
            return '#3498db';
    }
}
?> 