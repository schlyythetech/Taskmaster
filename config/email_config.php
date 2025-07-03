<?php
/**
 * Email Configuration Settings
 * 
 * This file contains configuration settings for sending emails from the application.
 */

// Email credentials
define('EMAIL_HOST', 'smtp.gmail.com');
define('EMAIL_PORT', 587);
define('EMAIL_USERNAME', 'taskm4030@gmail.com');
define('EMAIL_PASSWORD', 'pgqm ppel nqik guuq'); // App password for Gmail
define('EMAIL_FROM_NAME', 'TaskMaster');
define('EMAIL_FROM_ADDRESS', 'taskm4030@gmail.com');
define('EMAIL_REPLY_TO', 'support@taskmaster.com');

// Email settings
define('EMAIL_USE_SMTP', true); // Set to false to use PHP mail() function instead of SMTP
define('EMAIL_SMTP_AUTH', true);
define('EMAIL_SMTP_SECURE', 'tls'); // tls or ssl
define('EMAIL_SMTP_DEBUG', 2); // Debug level: 0=off, 1=client, 2=client/server
define('EMAIL_SMTP_OPTIONS', serialize([
    'ssl' => [
        'verify_peer' => false,
        'verify_peer_name' => false,
        'allow_self_signed' => true
    ]
]));

/**
 * Function to send emails using PHPMailer
 * 
 * @param string $to Recipient email address
 * @param string $subject Email subject
 * @param string $body Email body (HTML)
 * @param string $altBody Plain text alternative (optional)
 * @param array $attachments Array of file paths to attach (optional)
 * @return bool Whether the email was sent successfully
 */
function sendEmail($to, $subject, $body, $altBody = '', $attachments = []) {
    // Check if PHPMailer is available
    if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        // If PHPMailer is not available, fall back to PHP's mail() function
        $headers = "MIME-Version: 1.0\r\n";
        $headers .= "Content-type: text/html; charset=UTF-8\r\n";
        $headers .= "From: " . EMAIL_FROM_NAME . " <" . EMAIL_FROM_ADDRESS . ">\r\n";
        $headers .= "Reply-To: " . EMAIL_REPLY_TO . "\r\n";
        
        return mail($to, $subject, $body, $headers);
    }
    
    // Use PHPMailer if available
    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        
        // Debug settings
        $mail->SMTPDebug = EMAIL_SMTP_DEBUG;
        $mail->Debugoutput = function($str, $level) {
            error_log("PHPMailer [$level]: $str");
        };
        
        // Server settings
        if (EMAIL_USE_SMTP) {
            $mail->isSMTP();
            $mail->Host = EMAIL_HOST;
            $mail->Port = EMAIL_PORT;
            
            if (EMAIL_SMTP_AUTH) {
                $mail->SMTPAuth = true;
                $mail->Username = EMAIL_USERNAME;
                $mail->Password = EMAIL_PASSWORD;
            }
            
            if (EMAIL_SMTP_SECURE) {
                $mail->SMTPSecure = EMAIL_SMTP_SECURE;
            }
            
            // Set additional SMTP options for better compatibility
            $mail->SMTPOptions = unserialize(EMAIL_SMTP_OPTIONS);
        }
        
        // Recipients
        $mail->setFrom(EMAIL_FROM_ADDRESS, EMAIL_FROM_NAME);
        $mail->addAddress($to);
        $mail->addReplyTo(EMAIL_REPLY_TO);
        
        // Content
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = $subject;
        $mail->Body = $body;
        
        if (!empty($altBody)) {
            $mail->AltBody = $altBody;
        } else {
            // Generate plain text version from HTML if not provided
            $mail->AltBody = strip_tags(str_replace('<br>', "\n", $body));
        }
        
        // Attachments
        if (!empty($attachments) && is_array($attachments)) {
            foreach ($attachments as $attachment) {
                if (file_exists($attachment)) {
                    $mail->addAttachment($attachment);
                }
            }
        }
        
        // Send email
        return $mail->send();
    } catch (Exception $e) {
        // Log error
        error_log('Email sending failed: ' . $e->getMessage());
        return false;
    }
}
?> 