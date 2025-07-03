<?php
/**
 * Email Sending Test Script
 * 
 * This script tests email sending functionality and provides detailed error information.
 */

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Get base path
$base_path = dirname(dirname(dirname(__FILE__))) . '/';

// Include required files
require_once $base_path . 'includes/functions.php';
require_once $base_path . 'config/database.php';
require_once $base_path . 'config/email_config.php';

// Check if PHPMailer is available
$phpmailer_available = false;
if (file_exists($base_path . 'vendor/autoload.php')) {
    require_once $base_path . 'vendor/autoload.php';
    $phpmailer_available = class_exists('PHPMailer\PHPMailer\PHPMailer');
}

// Function to check if a port is open
function isPortOpen($host, $port, $timeout = 5) {
    $connection = @fsockopen($host, $port, $errno, $errstr, $timeout);
    if (is_resource($connection)) {
        fclose($connection);
        return true;
    }
    return false;
}

// HTML header
echo '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Sending Test</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { padding: 20px; }
        .container { max-width: 800px; }
        .result-box { 
            padding: 15px; 
            border-radius: 5px; 
            margin-bottom: 20px;
            border-left: 5px solid #ccc;
        }
        .success { 
            background-color: #d4edda; 
            border-left-color: #28a745;
        }
        .warning { 
            background-color: #fff3cd; 
            border-left-color: #ffc107;
        }
        .error { 
            background-color: #f8d7da; 
            border-left-color: #dc3545;
        }
        .info { 
            background-color: #d1ecf1; 
            border-left-color: #17a2b8;
        }
        pre {
            background-color: #f8f9fa;
            padding: 10px;
            border-radius: 5px;
            overflow-x: auto;
        }
        .step {
            margin-bottom: 30px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1 class="mb-4">Email Sending Test</h1>';

// Step 1: Check PHP version and extensions
echo '<div class="step">
        <h3>Step 1: PHP Environment Check</h3>';

$php_version = phpversion();
$php_version_ok = version_compare($php_version, '7.4', '>=');

echo '<div class="result-box ' . ($php_version_ok ? 'success' : 'error') . '">
        <strong>PHP Version:</strong> ' . $php_version . ' ' . 
        ($php_version_ok ? '✅' : '❌ (PHP 7.4 or higher recommended)') . '
      </div>';

$required_extensions = ['openssl', 'mbstring', 'curl'];
$missing_extensions = [];

foreach ($required_extensions as $ext) {
    if (!extension_loaded($ext)) {
        $missing_extensions[] = $ext;
    }
}

if (empty($missing_extensions)) {
    echo '<div class="result-box success">
            <strong>Required PHP Extensions:</strong> All required extensions are installed ✅
          </div>';
} else {
    echo '<div class="result-box error">
            <strong>Required PHP Extensions:</strong> Missing: ' . implode(', ', $missing_extensions) . ' ❌
          </div>';
}

// Step 2: Check PHPMailer
echo '</div><div class="step">
        <h3>Step 2: PHPMailer Check</h3>';

if ($phpmailer_available) {
    echo '<div class="result-box success">
            <strong>PHPMailer:</strong> Installed and available ✅
          </div>';
} else {
    echo '<div class="result-box warning">
            <strong>PHPMailer:</strong> Not installed or not available ⚠️<br>
            <small>Run <code>composer require phpmailer/phpmailer</code> to install.</small>
          </div>';
}

// Step 3: Check SMTP configuration
echo '</div><div class="step">
        <h3>Step 3: SMTP Configuration Check</h3>';

echo '<div class="result-box info">
        <strong>SMTP Host:</strong> ' . EMAIL_HOST . '<br>
        <strong>SMTP Port:</strong> ' . EMAIL_PORT . '<br>
        <strong>SMTP Username:</strong> ' . EMAIL_USERNAME . '<br>
        <strong>SMTP Security:</strong> ' . EMAIL_SMTP_SECURE . '
      </div>';

// Check if SMTP port is open
$port_open = isPortOpen(EMAIL_HOST, EMAIL_PORT);
echo '<div class="result-box ' . ($port_open ? 'success' : 'error') . '">
        <strong>SMTP Port Check:</strong> ' . 
        ($port_open ? 'Port ' . EMAIL_PORT . ' is open ✅' : 'Port ' . EMAIL_PORT . ' is closed or blocked ❌') . '
      </div>';

// Step 4: Test email sending
echo '</div><div class="step">
        <h3>Step 4: Test Email Sending</h3>
        <form method="post" class="mb-3">
            <div class="mb-3">
                <label for="test_email" class="form-label">Test Email Address:</label>
                <input type="email" class="form-control" id="test_email" name="test_email" 
                       value="' . htmlspecialchars($_POST['test_email'] ?? '') . '" required>
            </div>
            <button type="submit" class="btn btn-primary" name="send_test">Send Test Email</button>
        </form>';

// Process test email
if (isset($_POST['send_test']) && isset($_POST['test_email'])) {
    $test_email = filter_var($_POST['test_email'], FILTER_VALIDATE_EMAIL);
    
    if ($test_email) {
        echo '<div class="result-box info">
                <strong>Sending test email to:</strong> ' . htmlspecialchars($test_email) . '
              </div>';
        
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
                <p>Time sent: " . date('Y-m-d H:i:s') . "</p>
                <p style='margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee; font-size: 12px; color: #777;'>
                    This is an automated email from TaskMaster. Please do not reply to this email.
                </p>
            </div>
        </body>
        </html>
        ";
        
        // Start output buffering to capture errors
        ob_start();
        
        try {
            // Try to send email
            if ($phpmailer_available) {
                // Use PHPMailer with debug output
                $mail = new PHPMailer\PHPMailer\PHPMailer(true);
                
                // Debug settings
                $mail->SMTPDebug = 2; // Verbose debug output
                
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
                $mail->addAddress($test_email);
                $mail->addReplyTo(EMAIL_REPLY_TO);
                
                // Content
                $mail->isHTML(true);
                $mail->CharSet = 'UTF-8';
                $mail->Subject = $subject;
                $mail->Body = $body;
                $mail->AltBody = strip_tags(str_replace('<br>', "\n", $body));
                
                // Send email
                $result = $mail->send();
                $debug_output = ob_get_clean();
                
                if ($result) {
                    echo '<div class="result-box success">
                            <strong>Success!</strong> Test email sent successfully using PHPMailer ✅
                          </div>';
                } else {
                    echo '<div class="result-box error">
                            <strong>Error:</strong> Failed to send email using PHPMailer ❌<br>
                            <strong>Error Message:</strong> ' . $mail->ErrorInfo . '
                          </div>';
                }
                
                // Show debug output
                echo '<div class="result-box info">
                        <strong>Debug Output:</strong>
                        <pre>' . htmlspecialchars($debug_output) . '</pre>
                      </div>';
            } else {
                // Use PHP mail function
                $headers = "From: " . EMAIL_FROM_NAME . " <" . EMAIL_FROM_ADDRESS . ">\r\n";
                $headers .= "Reply-To: " . EMAIL_REPLY_TO . "\r\n";
                $headers .= "MIME-Version: 1.0\r\n";
                $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
                
                $result = mail($test_email, $subject, $body, $headers);
                $debug_output = ob_get_clean();
                
                if ($result) {
                    echo '<div class="result-box success">
                            <strong>Success!</strong> Test email sent successfully using PHP mail() function ✅
                          </div>';
                } else {
                    $error = error_get_last();
                    echo '<div class="result-box error">
                            <strong>Error:</strong> Failed to send email using PHP mail() function ❌<br>
                            <strong>Error Message:</strong> ' . ($error ? $error['message'] : 'Unknown error') . '
                          </div>';
                }
            }
        } catch (Exception $e) {
            $debug_output = ob_get_clean();
            echo '<div class="result-box error">
                    <strong>Exception:</strong> ' . $e->getMessage() . ' ❌
                  </div>';
            
            // Show debug output
            echo '<div class="result-box info">
                    <strong>Debug Output:</strong>
                    <pre>' . htmlspecialchars($debug_output) . '</pre>
                  </div>';
        }
    } else {
        echo '<div class="result-box error">
                <strong>Error:</strong> Invalid email address ❌
              </div>';
    }
}

// Step 5: Troubleshooting tips
echo '</div><div class="step">
        <h3>Step 5: Troubleshooting Tips</h3>
        <div class="result-box info">
            <h4>Common Issues and Solutions:</h4>
            <ul>
                <li><strong>Connection refused:</strong> Check if your server allows outgoing connections on port 587 or 465.</li>
                <li><strong>Authentication failure:</strong> Verify your Gmail app password is correct and hasn\'t expired.</li>
                <li><strong>SSL/TLS errors:</strong> Make sure your server has up-to-date SSL certificates.</li>
                <li><strong>Rate limiting:</strong> Gmail has sending limits. Try not to send too many emails at once.</li>
                <li><strong>Firewall issues:</strong> Your hosting provider might be blocking SMTP connections.</li>
            </ul>
            
            <h4>Gmail Specific Tips:</h4>
            <ul>
                <li>Make sure 2-Step Verification is enabled on your Google account.</li>
                <li>Verify the app password is specifically generated for this application.</li>
                <li>Check if your Google account has any security alerts or restrictions.</li>
                <li>Try generating a new app password if the current one isn\'t working.</li>
            </ul>
        </div>
      </div>';

// HTML footer
echo '</div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>';
?> 