<?php
/**
 * Password Reset Email Template
 * 
 * This file contains the HTML email template sent to users when they request a password reset.
 * The template includes variables that will be replaced with actual values when sending the email.
 * 
 * Variables:
 * - {{reset_link}} - The password reset link
 * - {{user_name}} - The user's first name
 * - {{expiry_time}} - How long the reset link is valid (e.g., "24 hours")
 */

function getPasswordResetEmailTemplate($user_name, $reset_link, $expiry_time = '24 hours') {
    // Replace placeholders with actual values
    $email_body = '
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Your TaskMaster Password</title>
    <style>
        /* Base styles */
        body {
            font-family: Arial, Helvetica, sans-serif;
            line-height: 1.6;
            color: #333333;
            margin: 0;
            padding: 0;
            background-color: #f5f7fa;
        }
        .email-container {
            max-width: 600px;
            margin: 0 auto;
            background-color: #ffffff;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        }
        .email-header {
            background: linear-gradient(90deg, #4776E6 0%, #8E54E9 100%);
            padding: 30px 0;
            text-align: center;
        }
        .email-header img {
            width: 120px;
            height: auto;
        }
        .email-content {
            padding: 30px;
            background-color: #ffffff;
        }
        h1 {
            color: #333333;
            font-size: 24px;
            margin-top: 0;
            margin-bottom: 20px;
        }
        p {
            margin-bottom: 20px;
            font-size: 16px;
        }
        .reset-button {
            display: inline-block;
            padding: 12px 24px;
            background: linear-gradient(90deg, #4776E6 0%, #8E54E9 100%);
            color: #ffffff !important;
            text-decoration: none;
            border-radius: 6px;
            font-weight: bold;
            margin: 20px 0;
            text-align: center;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        }
        .reset-link {
            word-break: break-all;
            background-color: #f5f7fa;
            padding: 15px;
            border-radius: 6px;
            border: 1px solid #e0e0e0;
            font-size: 14px;
        }
        .email-footer {
            background-color: #f5f7fa;
            padding: 20px;
            text-align: center;
            color: #777777;
            font-size: 14px;
            border-top: 1px solid #e0e0e0;
        }
        .help-text {
            font-size: 14px;
            color: #777777;
        }
        @media only screen and (max-width: 600px) {
            .email-container {
                width: 100%;
                border-radius: 0;
            }
            .email-content {
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="email-container">
        <div class="email-header">
            <img src="https://taskmaster.com/assets/images/logo-white.png" alt="TaskMaster Logo">
        </div>
        
        <div class="email-content">
            <h1>Reset Your Password</h1>
            
            <p>Hello ' . $user_name . ',</p>
            
            <p>We received a request to reset your password for your TaskMaster account. Click the button below to create a new password. This link is valid for ' . $expiry_time . '.</p>
            
            <div style="text-align: center;">
                <a href="' . $reset_link . '" class="reset-button">Reset Password</a>
            </div>
            
            <p>If you didn\'t request a password reset, you can safely ignore this email. Your account is secure.</p>
            
            <p>If the button above doesn\'t work, copy and paste the following link into your browser:</p>
            
            <div class="reset-link">' . $reset_link . '</div>
            
            <p class="help-text">If you need further assistance, please contact our support team at <a href="mailto:support@taskmaster.com">support@taskmaster.com</a>.</p>
        </div>
        
        <div class="email-footer">
            <p>&copy; ' . date("Y") . ' TaskMaster. All rights reserved.</p>
            <p>TaskMaster Inc., 123 Productivity Street, Workflow City, WF 12345</p>
        </div>
    </div>
</body>
</html>
    ';
    
    return $email_body;
}

/**
 * Example usage:
 * 
 * $user_name = "John";
 * $reset_link = "https://taskmaster.com/reset-password?token=abc123xyz789";
 * $email_body = getPasswordResetEmailTemplate($user_name, $reset_link);
 * 
 * // Then use a mail library/function to send the email
 * // mail($to, $subject, $email_body, $headers);
 */
?> 