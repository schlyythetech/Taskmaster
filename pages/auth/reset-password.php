<?php
require_once '../../includes/functions.php';
require_once '../../config/database.php';
require_once 'reset-password-email.php';

// Check if user is already logged in
if (isLoggedIn()) {
    redirect('../core/dashboard.php');
}

// Initialize variables
$token = '';
$email = '';
$message = '';
$message_type = '';

// Process form submission for requesting password reset
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['email']) && !isset($_POST['new_password'])) {
    $email = sanitize($_POST['email']);
    
    // Validate email
    if (empty($email)) {
        setMessage("Please enter your email address.", "danger");
        redirect("../auth/login.php");
    }
    
    // Check if user exists
    $stmt = $conn->prepare("SELECT user_id, first_name FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    
    if ($user) {
        // Generate a unique token
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', strtotime('+24 hours'));
        
        // Store token in database
        $stmt = $conn->prepare("INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, ?)");
        $stmt->execute([$email, $token, $expires]);
        
        // Generate reset link
        $reset_link = "http://{$_SERVER['HTTP_HOST']}/taskmaster/pages/auth/reset-password.php?token=$token";
        
        // Generate email content
        $email_body = getPasswordResetEmailTemplate($user['first_name'], $reset_link);
        
        // Include email configuration
        require_once '../../config/email_config.php';
        
        // Send email using the configured email settings
        $email_sent = false;
        $error_message = '';
        
        try {
            // Check if PHPMailer is available
            if (file_exists('../../vendor/autoload.php')) {
                require_once '../../vendor/autoload.php';
                
                // Use PHPMailer for sending email
                $mail = new PHPMailer\PHPMailer\PHPMailer(true);
                
                // Debug settings (for error logging only)
                $mail->SMTPDebug = 0; // No output for production
                $mail->Debugoutput = function($str, $level) use (&$error_message) {
                    $error_message .= "$str\n";
                };
                
                // Server settings
                $mail->isSMTP();
                $mail->Host = EMAIL_HOST;
                $mail->Port = EMAIL_PORT;
                $mail->SMTPAuth = EMAIL_SMTP_AUTH;
                $mail->Username = EMAIL_USERNAME;
                $mail->Password = EMAIL_PASSWORD;
                $mail->SMTPSecure = EMAIL_SMTP_SECURE;
                
                // Set additional SMTP options for better compatibility
                $mail->SMTPOptions = unserialize(EMAIL_SMTP_OPTIONS);
                
                // Recipients
                $mail->setFrom(EMAIL_FROM_ADDRESS, EMAIL_FROM_NAME);
                $mail->addAddress($email);
                $mail->addReplyTo(EMAIL_REPLY_TO);
                
                // Content
                $mail->isHTML(true);
                $mail->CharSet = 'UTF-8';
                $mail->Subject = "Reset Your TaskMaster Password";
                $mail->Body = $email_body;
                $mail->AltBody = strip_tags(str_replace('<br>', "\n", $email_body));
                
                // Send email
                $email_sent = $mail->send();
                
                if (!$email_sent) {
                    $error_message = $mail->ErrorInfo;
                }
            } else {
                // Fallback to basic PHP mail function
                $headers = "From: " . EMAIL_FROM_NAME . " <" . EMAIL_FROM_ADDRESS . ">\r\n";
                $headers .= "Reply-To: " . EMAIL_REPLY_TO . "\r\n";
                $headers .= "MIME-Version: 1.0\r\n";
                $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
                
                $email_sent = mail($email, "Reset Your TaskMaster Password", $email_body, $headers);
                
                if (!$email_sent) {
                    $error_message = error_get_last()['message'] ?? 'Unknown mail error';
                }
            }
            
            if ($email_sent) {
                // For demonstration purposes, we'll show the reset link (in a real app, you'd only send it via email)
                if ($email === "taskm4030@gmail.com") {
                    setMessage("Password reset instructions have been sent to your email address. For demonstration purposes, you can also <a href='$reset_link'>click here</a> to reset your password.", "success");
                } else {
                    setMessage("Password reset instructions have been sent to your email address.", "success");
                }
                redirect("../auth/login.php");
            } else {
                // Log the error
                error_log("Failed to send password reset email to $email: $error_message");
                
                // Provide a more helpful error message
                setMessage("Unable to send password reset email. Please try again later or contact support.", "danger");
                redirect("../auth/login.php");
            }
        } catch (Exception $e) {
            error_log("Exception sending password reset email: " . $e->getMessage());
            setMessage("An error occurred while sending the password reset email. Please try again later.", "danger");
            redirect("../auth/login.php");
        }
    } else {
        // Don't reveal that the email doesn't exist for security reasons
        setMessage("If your email exists in our system, you will receive password reset instructions.", "info");
        redirect("../auth/login.php");
    }
}

// Process token validation and password reset
if (isset($_GET['token'])) {
    $token = sanitize($_GET['token']);
    
    // Verify token
    $stmt = $conn->prepare("SELECT email, expires_at FROM password_resets WHERE token = ? AND expires_at > NOW()");
    $stmt->execute([$token]);
    $reset = $stmt->fetch();
    
    if (!$reset) {
        setMessage("Invalid or expired password reset link. Please request a new one.", "danger");
        redirect("../auth/login.php");
    }
    
    $email = $reset['email'];
}

// Process form submission for setting new password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_password']) && isset($_POST['token'])) {
    $token = sanitize($_POST['token']);
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    // Validate inputs
    if (empty($new_password)) {
        $message = "Password is required";
        $message_type = "danger";
    } elseif (strlen($new_password) < 6) {
        $message = "Password must be at least 6 characters";
        $message_type = "danger";
    } elseif ($new_password !== $confirm_password) {
        $message = "Passwords do not match";
        $message_type = "danger";
    } else {
        // Verify token again
        $stmt = $conn->prepare("SELECT email FROM password_resets WHERE token = ? AND expires_at > NOW()");
        $stmt->execute([$token]);
        $reset = $stmt->fetch();
        
        if ($reset) {
            $email = $reset['email'];
            
            // Update user's password
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password = ? WHERE email = ?");
            $result = $stmt->execute([$hashed_password, $email]);
            
            if ($result) {
                // Delete used token
                $stmt = $conn->prepare("DELETE FROM password_resets WHERE token = ?");
                $stmt->execute([$token]);
                
                setMessage("Your password has been reset successfully. You can now login with your new password.", "success");
                redirect("../auth/login.php");
            } else {
                $message = "Failed to update password. Please try again.";
                $message_type = "danger";
            }
        } else {
            setMessage("Invalid or expired password reset link. Please request a new one.", "danger");
            redirect("../auth/login.php");
        }
    }
}

// Page title
$page_title = "Reset Password";
?>

<?php include '../../includes/header.php'; ?>

<div class="auth-container">
    <img src="../../assets/images/logo.png" alt="TaskMaster Logo" class="auth-logo">
    <h1 class="auth-title">Reset Password</h1>
    
    <?php if (!empty($message)): ?>
        <div class="alert alert-<?php echo $message_type; ?>"><?php echo $message; ?></div>
    <?php endif; ?>
    
    <?php if (isset($_GET['token']) && !empty($email)): ?>
        <p class="text-center mb-4">Enter your new password below.</p>
        <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" method="post" class="needs-validation" novalidate>
            <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
            
            <div class="form-group">
                <label for="new_password" class="form-label">
                    <i class="fas fa-lock"></i> New Password
                </label>
                <div class="input-group">
                    <input type="password" class="form-control" id="new_password" name="new_password" placeholder="Enter new password" required>
                    <button type="button" class="btn btn-outline-secondary toggle-password" data-toggle="#new_password">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
                <small class="text-muted">Password must be at least 6 characters long</small>
            </div>
            
            <div class="form-group">
                <label for="confirm_password" class="form-label">
                    <i class="fas fa-lock"></i> Confirm Password
                </label>
                <div class="input-group">
                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" placeholder="Confirm new password" required>
                    <button type="button" class="btn btn-outline-secondary toggle-password" data-toggle="#confirm_password">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
            </div>
            
            <div class="form-group">
                <button type="submit" class="btn btn-primary">Reset Password</button>
            </div>
        </form>
    <?php endif; ?>
    
    <div class="auth-links">
        <a href="../auth/login.php">Back to Login</a>
    </div>
</div>

<?php include '../../includes/footer.php'; ?> 