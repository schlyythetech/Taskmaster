<?php
/**
 * Simple Email Test Script
 * 
 * This is a standalone script to test email functionality without dependencies.
 */

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Email settings - copied from config for standalone testing
$email_host = 'smtp.gmail.com';
$email_port = 587;
$email_username = 'taskm4030@gmail.com';
$email_password = 'pgqm ppel nqik guuq'; // App password
$email_from_name = 'TaskMaster';
$email_from_address = 'taskm4030@gmail.com';
$email_reply_to = 'support@taskmaster.com';

// Check if PHPMailer is available
if (file_exists('vendor/autoload.php')) {
    require_once 'vendor/autoload.php';
    
    // Test recipient
    $to = isset($_GET['email']) ? $_GET['email'] : 'taskm4030@gmail.com';
    
    // Create simple test email
    $subject = "TaskMaster Test Email - " . date('Y-m-d H:i:s');
    $body = "
    <html>
    <head>
        <title>Test Email</title>
    </head>
    <body>
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 5px;'>
            <h2 style='color: #4776E6;'>Email Test</h2>
            <p>This is a test email sent at " . date('Y-m-d H:i:s') . "</p>
            <p>If you received this email, the configuration is working correctly!</p>
        </div>
    </body>
    </html>
    ";
    
    try {
        // Create PHPMailer instance
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        
        // Debug settings
        $mail->SMTPDebug = 2; // Verbose debug output
        $mail->Debugoutput = 'html';
        
        // Server settings
        $mail->isSMTP();
        $mail->Host = $email_host;
        $mail->Port = $email_port;
        $mail->SMTPAuth = true;
        $mail->Username = $email_username;
        $mail->Password = $email_password;
        $mail->SMTPSecure = 'tls';
        
        // TLS/SSL options to fix common issues
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            ]
        ];
        
        // Recipients
        $mail->setFrom($email_from_address, $email_from_name);
        $mail->addAddress($to);
        $mail->addReplyTo($email_reply_to);
        
        // Content
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->AltBody = strip_tags(str_replace('<br>', "\n", $body));
        
        // Send email
        echo "<h1>Sending Test Email to: $to</h1>";
        echo "<h2>Debug Output:</h2>";
        echo "<pre>";
        $result = $mail->send();
        echo "</pre>";
        
        if ($result) {
            echo "<div style='background-color: #d4edda; color: #155724; padding: 15px; border-radius: 5px; margin-top: 20px;'>";
            echo "<strong>Success!</strong> Email sent successfully.";
            echo "</div>";
        } else {
            echo "<div style='background-color: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; margin-top: 20px;'>";
            echo "<strong>Error:</strong> " . $mail->ErrorInfo;
            echo "</div>";
        }
    } catch (Exception $e) {
        echo "<div style='background-color: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; margin-top: 20px;'>";
        echo "<strong>Exception:</strong> " . $e->getMessage();
        echo "</div>";
    }
} else {
    echo "<div style='background-color: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; margin-top: 20px;'>";
    echo "<strong>Error:</strong> PHPMailer not found. Please run 'composer require phpmailer/phpmailer'.";
    echo "</div>";
}
?> 