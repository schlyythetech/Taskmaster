<?php // Mail Settings Page ?>

<?php
require_once '../includes/functions.php';
require_once '../includes/session_functions.php';
require_once '../config/database.php';
require_once '../config/mail_config.php';

// Check if user is logged in and is an admin
if (!isLoggedIn()) {
    setMessage("You must be logged in to access this page.", "danger");
    redirect('../pages/auth/login.php');
}

if (!isAdmin()) {
    setMessage("You do not have permission to access the admin settings.", "danger");
    redirect('../pages/core/dashboard.php');
}

// Get admin data
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];
$first_name = explode(' ', $user_name)[0];

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle mail settings update
    if (isset($_POST['action']) && $_POST['action'] === 'update_mail_settings') {
        $smtp_username = $_POST['smtp_username'];
        $smtp_password = $_POST['smtp_password'];
        $from_name = $_POST['from_name'];
        
        // Update mail_config.php
        $config_file = '../config/mail_config.php';
        $config_content = file_get_contents($config_file);
        
        // Update SMTP username
        $config_content = preg_replace(
            "/('smtp_username' => ').*?(')/",
            "$1{$smtp_username}$2",
            $config_content
        );
        
        // Update SMTP password
        $config_content = preg_replace(
            "/('smtp_password' => ').*?(')/",
            "$1{$smtp_password}$2",
            $config_content
        );
        
        // Update From name
        $config_content = preg_replace(
            "/('from_name' => ').*?(')/",
            "$1{$from_name}$2",
            $config_content
        );
        
        // Update From email and Reply-to
        $config_content = preg_replace(
            "/('from_email' => ').*?(')/",
            "$1{$smtp_username}$2",
            $config_content
        );
        
        $config_content = preg_replace(
            "/('reply_to' => ').*?(')/",
            "$1{$smtp_username}$2",
            $config_content
        );
        
        // Save the updated config
        file_put_contents($config_file, $config_content);
        
        // Test the email configuration
        if (isset($_POST['send_test']) && $_POST['send_test'] === '1') {
            $test_email = $_POST['test_email'];
            
            // Reload mail config
            require_once '../config/mail_config.php';
            
            $subject = "TaskMaster Email Test";
            $message = "
            <html>
            <head>
                <title>Email Test</title>
                <style>
                    body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 5px; }
                    h2 { color: #4285f4; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <h2>TaskMaster Email Test</h2>
                    <p>This is a test email from TaskMaster.</p>
                    <p>If you received this email, your email configuration is working correctly!</p>
                    <p>Best regards,<br>The TaskMaster Team</p>
                </div>
            </body>
            </html>
            ";
            
            $email_sent = sendMail($test_email, $subject, $message);
            
            if ($email_sent) {
                setMessage("Mail settings updated and test email sent successfully.", "success");
            } else {
                setMessage("Mail settings updated, but there was an issue sending the test email.", "warning");
            }
        } else {
            setMessage("Mail settings updated successfully.", "success");
        }
        
        redirect('mail_settings.php');
    }
}

// Page title
$page_title = "Mail Settings";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> | TaskMaster</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body class="admin-body">
    <div class="d-flex">
        <!-- Admin Sidebar -->
        <div class="admin-sidebar">
            <div class="logo-container">
                <img src="../assets/images/logo.png" alt="TaskMaster Logo" class="logo">
                <h1>TaskMaster</h1>
            </div>
            <nav>
                <ul>
                    <li>
                        <a href="dashboard.php"><i class="fas fa-home"></i> Home</a>
                    </li>
                    <li>
                        <a href="settings.php"><i class="fas fa-cog"></i> Settings</a>
                    </li>
                    <li class="active">
                        <a href="mail_settings.php"><i class="fas fa-envelope"></i> Mail Settings</a>
                    </li>
                </ul>
            </nav>
            <div class="sidebar-footer">
                <a href="../pages/auth/logout.php" id="logout-btn"><i class="fas fa-sign-out-alt"></i> Log Out</a>
            </div>
        </div>

        <!-- Main Content -->
        <div class="admin-content">
            <!-- Top Navigation -->
            <div class="admin-top-nav">
                <div class="admin-title">
                    <h1>Mail Settings</h1>
                </div>
                <div class="admin-user">
                    <span>Welcome, <?php echo htmlspecialchars($first_name); ?></span>
                </div>
            </div>

            <!-- Settings Content -->
            <div class="admin-dashboard-container">
                <!-- Display Messages -->
                <?php displayMessage(); ?>

                <div class="admin-section">
                    <h2>Gmail SMTP Configuration</h2>
                    <div class="admin-card">
                        <form action="mail_settings.php" method="post">
                            <input type="hidden" name="action" value="update_mail_settings">
                            
                            <div class="mb-3">
                                <label for="smtp_username" class="form-label">Gmail Email Address</label>
                                <input type="email" class="form-control" id="smtp_username" name="smtp_username" value="<?php echo htmlspecialchars($mail_config['smtp_username']); ?>" required>
                                <div class="form-text">Enter your Gmail address</div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="smtp_password" class="form-label">Gmail App Password</label>
                                <input type="password" class="form-control" id="smtp_password" name="smtp_password" value="<?php echo htmlspecialchars($mail_config['smtp_password']); ?>" required>
                                <div class="form-text">
                                    <a href="https://myaccount.google.com/apppasswords" target="_blank">Generate an App Password</a> for your Gmail account
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="from_name" class="form-label">From Name</label>
                                <input type="text" class="form-control" id="from_name" name="from_name" value="<?php echo htmlspecialchars($mail_config['from_name']); ?>" required>
                                <div class="form-text">Name that will appear as the sender</div>
                            </div>
                            
                            <hr>
                            
                            <div class="mb-3 form-check">
                                <input type="checkbox" class="form-check-input" id="send_test" name="send_test" value="1">
                                <label class="form-check-label" for="send_test">Send a test email</label>
                            </div>
                            
                            <div class="mb-3" id="test_email_group" style="display: none;">
                                <label for="test_email" class="form-label">Test Email Address</label>
                                <input type="email" class="form-control" id="test_email" name="test_email">
                                <div class="form-text">Enter an email address to send a test message to</div>
                            </div>
                            
                            <button type="submit" class="btn btn-primary">Save Settings</button>
                        </form>
                    </div>
                </div>

                <div class="admin-section">
                    <h2>Email Notification Settings</h2>
                    <div class="admin-card">
                        <p>The following email notifications are currently enabled:</p>
                        <ul>
                            <li><strong>Ban Notification:</strong> Sent when an admin bans a user</li>
                            <li><strong>Unban Notification:</strong> Sent when an admin unbans a user</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Logout Confirmation Modal -->
    <div class="modal fade" id="logoutModal" tabindex="-1" aria-labelledby="logoutModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="logoutModalLabel">Confirm Logout</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    Are you sure you want to log out?
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <a href="../pages/auth/logout.php" class="btn btn-primary">Logout</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <!-- Custom JS -->
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Logout confirmation
        document.getElementById('logout-btn').addEventListener('click', function(e) {
            e.preventDefault();
            const logoutModal = new bootstrap.Modal(document.getElementById('logoutModal'));
            logoutModal.show();
        });
        
        // Show/hide test email field
        document.getElementById('send_test').addEventListener('change', function() {
            const testEmailGroup = document.getElementById('test_email_group');
            const testEmailInput = document.getElementById('test_email');
            
            if (this.checked) {
                testEmailGroup.style.display = 'block';
                testEmailInput.required = true;
            } else {
                testEmailGroup.style.display = 'none';
                testEmailInput.required = false;
            }
        });
    });
    </script>
</body>
</html>
