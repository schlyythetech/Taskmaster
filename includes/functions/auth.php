<?php
/**
 * Authentication Functions
 * 
 * This file contains all functions related to user authentication, 
 * session management, and security.
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}

/**
 * Check if user is logged in
 * @return bool
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

/**
 * Check if logged-in user is an admin
 * @return bool
 */
function isAdmin() {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
}

/**
 * Generate a random token
 * @param int $length
 * @return string
 */
function generateToken($length = 32) {
    return bin2hex(random_bytes($length));
}

/**
 * Validate CSRF token
 * @param string $token
 * @return bool
 */
function validateCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Check if user is banned
 * @param PDO $conn Database connection
 * @param int $user_id User ID
 * @return bool True if user is banned
 */
function isUserBanned($conn, $user_id) {
    try {
        $stmt = $conn->prepare("SELECT is_banned FROM users WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $result = $stmt->fetch();
        
        return ($result && $result['is_banned'] == 1);
    } catch (PDOException $e) {
        error_log("Error checking if user is banned: " . $e->getMessage());
        return false;
    }
}

/**
 * Send project invitation email
 * @param string $email Recipient email
 * @param string $token Invitation token
 * @param string $projectName Project name
 * @param string $inviterName Name of the person sending the invitation
 * @return bool Success status
 */
function sendInvitationEmail($email, $token, $projectName, $inviterName) {
    $subject = "Invitation to join project: $projectName";
    
    $siteUrl = getSiteUrl();
    $inviteUrl = $siteUrl . "/accept_invitation.php?token=" . urlencode($token);
    
    $message = "
    <html>
    <head>
        <title>Project Invitation</title>
    </head>
    <body>
        <div style='max-width: 600px; margin: 0 auto; padding: 20px; font-family: Arial, sans-serif;'>
            <h2>You've been invited to join a project!</h2>
            <p>Hello,</p>
            <p>$inviterName has invited you to join the project: <strong>$projectName</strong> on TaskMaster.</p>
            <p>Click the button below to accept the invitation:</p>
            <p style='text-align: center;'>
                <a href='$inviteUrl' style='display: inline-block; padding: 10px 20px; background-color: #007bff; color: white; text-decoration: none; border-radius: 5px;'>
                    Accept Invitation
                </a>
            </p>
            <p>Or copy and paste this link into your browser:</p>
            <p>$inviteUrl</p>
            <p>This invitation will expire in 30 days.</p>
            <p>If you don't have an account yet, you'll need to create one first.</p>
            <p>Best regards,<br>The TaskMaster Team</p>
        </div>
    </body>
    </html>
    ";
    
    // Set content-type header for sending HTML email
    $headers = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
    $headers .= "From: TaskMaster <noreply@taskmaster.com>" . "\r\n";
    
    // Send email
    return mail($email, $subject, $message, $headers);
}
?> 