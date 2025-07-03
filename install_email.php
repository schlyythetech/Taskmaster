<?php
/**
 * Email Configuration Installation Script
 * 
 * This script helps users set up the email functionality by:
 * 1. Checking if Composer is installed
 * 2. Installing PHPMailer if needed
 * 3. Testing the email configuration
 */

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "=================================================\n";
echo "TaskMaster Email Configuration Installation Script\n";
echo "=================================================\n\n";

// Step 1: Check if Composer is installed
echo "Step 1: Checking if Composer is installed...\n";
$composerExists = file_exists('composer.phar') || (shell_exec('which composer') !== null);

if (!$composerExists) {
    echo "❌ Composer not found. Please install Composer first.\n";
    echo "   Visit https://getcomposer.org/download/ for installation instructions.\n\n";
    exit(1);
} else {
    echo "✅ Composer is installed.\n\n";
}

// Step 2: Check if vendor directory exists
echo "Step 2: Checking if dependencies are installed...\n";
$vendorExists = is_dir('vendor') && file_exists('vendor/autoload.php');

if (!$vendorExists) {
    echo "   Dependencies not found. Installing now...\n";
    
    // Run composer install
    $output = shell_exec('composer install 2>&1');
    echo $output . "\n";
    
    // Check if installation was successful
    if (is_dir('vendor') && file_exists('vendor/autoload.php')) {
        echo "✅ Dependencies installed successfully.\n\n";
    } else {
        echo "❌ Failed to install dependencies. Please run 'composer install' manually.\n\n";
        exit(1);
    }
} else {
    echo "✅ Dependencies are already installed.\n\n";
}

// Step 3: Check if email configuration file exists
echo "Step 3: Checking email configuration...\n";
$configExists = file_exists('config/email_config.php');

if (!$configExists) {
    echo "❌ Email configuration file not found at 'config/email_config.php'.\n";
    echo "   Please make sure the file exists and contains the correct settings.\n\n";
    exit(1);
} else {
    echo "✅ Email configuration file found.\n\n";
}

// Step 4: Load email configuration
require_once 'config/email_config.php';

echo "Step 4: Verifying email configuration...\n";
echo "   SMTP Server: " . EMAIL_HOST . "\n";
echo "   Port: " . EMAIL_PORT . "\n";
echo "   Username: " . EMAIL_USERNAME . "\n";
echo "   From Name: " . EMAIL_FROM_NAME . "\n";
echo "   From Email: " . EMAIL_FROM_ADDRESS . "\n";
echo "   Reply-To: " . EMAIL_REPLY_TO . "\n";
echo "   Use SMTP: " . (EMAIL_USE_SMTP ? 'Yes' : 'No') . "\n";
echo "   SMTP Auth: " . (EMAIL_SMTP_AUTH ? 'Yes' : 'No') . "\n";
echo "   SMTP Secure: " . EMAIL_SMTP_SECURE . "\n\n";

// Step 5: Test email functionality
echo "Step 5: Would you like to test the email functionality? (y/n): ";
$handle = fopen("php://stdin", "r");
$line = trim(fgets($handle));

if (strtolower($line) === 'y') {
    echo "   Enter an email address to send a test email to: ";
    $testEmail = trim(fgets($handle));
    
    if (filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
        echo "   Sending test email to " . $testEmail . "...\n";
        
        // Create test email content
        $subject = "TaskMaster Email Test";
        $body = "
        <html>
        <head>
            <title>TaskMaster Email Test</title>
        </head>
        <body>
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 5px;'>
                <h2 style='color: #4776E6;'>TaskMaster Email Test</h2>
                <p>This is a test email to verify that the email functionality is working correctly.</p>
                <p>If you received this email, it means that the email configuration is set up correctly!</p>
                <p style='margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee; font-size: 12px; color: #777;'>
                    This is an automated email from TaskMaster. Please do not reply to this email.
                </p>
            </div>
        </body>
        </html>
        ";
        
        // Try to send email
        $emailSent = false;
        
        // Include PHPMailer if available
        if (file_exists('vendor/autoload.php')) {
            require_once 'vendor/autoload.php';
            $emailSent = sendEmail($testEmail, $subject, $body);
        } else {
            // Fallback to basic PHP mail function
            $headers = "From: " . EMAIL_FROM_NAME . " <" . EMAIL_FROM_ADDRESS . ">\r\n";
            $headers .= "Reply-To: " . EMAIL_REPLY_TO . "\r\n";
            $headers .= "MIME-Version: 1.0\r\n";
            $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
            
            $emailSent = mail($testEmail, $subject, $body, $headers);
        }
        
        if ($emailSent) {
            echo "✅ Test email sent successfully! Please check your inbox.\n\n";
        } else {
            echo "❌ Failed to send test email. Please check your email configuration.\n\n";
        }
    } else {
        echo "❌ Invalid email address. Skipping test.\n\n";
    }
} else {
    echo "   Skipping email test.\n\n";
}

// Step 6: Final instructions
echo "Step 6: Final instructions\n";
echo "   The email functionality is now set up and ready to use.\n";
echo "   To use the 'Forgot Password' feature:\n";
echo "   1. Go to the login page\n";
echo "   2. Click on 'Forgot password?'\n";
echo "   3. Enter your email address\n";
echo "   4. Check your email for the password reset link\n\n";

echo "=================================================\n";
echo "Installation complete!\n";
echo "=================================================\n";

fclose($handle);
?> 