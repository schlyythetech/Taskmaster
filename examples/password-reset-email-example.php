<?php
/**
 * This is an example file to demonstrate how the password reset email would look.
 * In a real application, this would be sent via an email service.
 */

// Include the email template
require_once '../pages/auth/reset-password-email.php';

// Example user data
$user_name = "TaskMaster User";
$email = "taskm4030@gmail.com";
$reset_token = "abc123def456ghi789jkl012mno345pqr678stu901vwx234yz";
$reset_link = "https://taskmaster.com/pages/auth/reset-password.php?token=" . $reset_token;

// Generate the email content
$email_body = getPasswordResetEmailTemplate($user_name, $reset_link);

// Display the email (for demonstration purposes)
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Reset Email Preview</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            margin: 0;
            padding: 20px;
            background-color: #f5f7fa;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            background-color: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .header {
            background: linear-gradient(90deg, #4776E6 0%, #8E54E9 100%);
            color: white;
            padding: 15px;
            border-radius: 8px 8px 0 0;
            margin: -20px -20px 20px;
        }
        .header h1 {
            margin: 0;
            font-size: 24px;
        }
        .email-info {
            background-color: #f0f0f0;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 8px;
        }
        .email-info p {
            margin: 5px 0;
        }
        .email-preview {
            border: 1px solid #ddd;
            border-radius: 8px;
            overflow: hidden;
        }
        .note {
            margin-top: 20px;
            padding: 15px;
            background-color: #fffde7;
            border-left: 4px solid #ffd600;
            border-radius: 4px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Password Reset Email Preview</h1>
        </div>
        
        <div class="email-info">
            <p><strong>To:</strong> <?php echo htmlspecialchars($email); ?></p>
            <p><strong>Subject:</strong> Reset Your TaskMaster Password</p>
            <p><strong>From:</strong> TaskMaster &lt;noreply@taskmaster.com&gt;</p>
        </div>
        
        <div class="email-preview">
            <?php echo $email_body; ?>
        </div>
        
        <div class="note">
            <p><strong>Note:</strong> This is a preview of how the email would appear when sent to <?php echo htmlspecialchars($email); ?>. In a production environment, this email would be sent via a proper email service.</p>
        </div>
    </div>
</body>
</html> 