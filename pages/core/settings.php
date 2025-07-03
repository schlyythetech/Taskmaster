<?php
require_once '../../includes/functions.php';
require_once '../../config/database.php';

// Check if user is logged in
if (!isLoggedIn()) {
    setMessage("You must be logged in to access settings.", "danger");
    redirect('../auth/login.php');
}

// Get user data
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];
$first_name = explode(' ', $user_name)[0];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Process settings changes
    if (isset($_POST['save_settings'])) {
        // Get notification preferences
        $email_notifications = isset($_POST['email_notifications']) ? 1 : 0;
        $push_notifications = isset($_POST['push_notifications']) ? 1 : 0;
        $dark_mode = isset($_POST['dark_mode']) ? 1 : 0;
        $minimalist_mode = isset($_POST['minimalist_mode']) ? 1 : 0;
        
        try {
            // Check if user has settings record
            $stmt = $conn->prepare("SELECT user_id FROM user_settings WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $has_settings = $stmt->fetch();
            
            // Prepare notification preferences JSON
            $notification_preferences = json_encode([
                'email_notifications' => (bool)$email_notifications,
                'push_notifications' => (bool)$push_notifications
            ]);
            
            if ($has_settings) {
                // Update existing settings
                $stmt = $conn->prepare("
                    UPDATE user_settings 
                    SET notification_preferences = ?, theme = ?, updated_at = NOW() 
                    WHERE user_id = ?
                ");
                $theme = $dark_mode ? 'dark' : 'light';
                $stmt->execute([$notification_preferences, $theme, $user_id]);
            } else {
                // Insert new settings
                $stmt = $conn->prepare("
                    INSERT INTO user_settings (user_id, notification_preferences, theme, language, created_at, updated_at)
                    VALUES (?, ?, ?, 'en', NOW(), NOW())
                ");
                $theme = $dark_mode ? 'dark' : 'light';
                $stmt->execute([$user_id, $notification_preferences, $theme]);
            }
            
            setMessage("Settings have been saved successfully.", "success");
        } catch (PDOException $e) {
            setMessage("Error saving settings: " . $e->getMessage(), "danger");
        }
        
        redirect('../core/settings.php');
    }
    
    // Process password change
    if (isset($_POST['change_password'])) {
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        $errors = [];
        
        // Validate inputs
        if (empty($current_password)) {
            $errors[] = "Current password is required";
        }
        
        if (empty($new_password)) {
            $errors[] = "New password is required";
        } elseif (strlen($new_password) < 8) {
            $errors[] = "New password must be at least 8 characters long";
        }
        
        if ($new_password !== $confirm_password) {
            $errors[] = "New passwords do not match";
        }
        
        // If no errors, proceed with password change
        if (empty($errors)) {
            // Get current password from database
            $stmt = $conn->prepare("SELECT password FROM users WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch();
            
            // Verify current password
            if ($user && password_verify($current_password, $user['password'])) {
                // Hash new password
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                
                // Update password in database
                $stmt = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
                $result = $stmt->execute([$hashed_password, $user_id]);
                
                if ($result) {
                    setMessage("Password has been updated successfully.", "success");
                } else {
                    setMessage("Failed to update password. Please try again.", "danger");
                }
            } else {
                setMessage("Current password is incorrect.", "danger");
            }
        } else {
            setMessage(implode("<br>", $errors), "danger");
        }
        
        redirect('../core/settings.php');
    }
    
    // Process account deletion
    if (isset($_POST['delete_account'])) {
        $delete_confirm = $_POST['delete_confirm'];
        
        if ($delete_confirm === 'DELETE') {
            try {
                // Begin transaction
                $conn->beginTransaction();
                
                // Delete user settings
                $stmt = $conn->prepare("DELETE FROM user_settings WHERE user_id = ?");
                $stmt->execute([$user_id]);
                
                // Delete user sessions
                $stmt = $conn->prepare("DELETE FROM user_sessions WHERE user_id = ?");
                $stmt->execute([$user_id]);
                
                // Delete user's project memberships
                $stmt = $conn->prepare("DELETE FROM project_members WHERE user_id = ?");
                $stmt->execute([$user_id]);
                
                // Update tasks assigned to user (set assigned_to to NULL)
                $stmt = $conn->prepare("UPDATE tasks SET assigned_to = NULL WHERE assigned_to = ?");
                $stmt->execute([$user_id]);
                
                // Delete user account
                $stmt = $conn->prepare("DELETE FROM users WHERE user_id = ?");
                $stmt->execute([$user_id]);
                
                // Commit transaction
                $conn->commit();
                
                // Clear session and redirect to login page
                session_destroy();
                setMessage("Your account has been deleted successfully.", "success");
                redirect('../auth/login.php');
                
            } catch (PDOException $e) {
                // Rollback transaction on error
                $conn->rollBack();
                setMessage("Error deleting account: " . $e->getMessage(), "danger");
                redirect('../core/settings.php');
            }
        } else {
            setMessage("Account deletion confirmation failed. Please type 'DELETE' to confirm.", "danger");
            redirect('../core/settings.php');
        }
    }
}

// Default settings (in a real app, these would come from the database)
$settings = [
    'email_notifications' => true,
    'push_notifications' => true,
    'dark_mode' => false,
    'minimalist_mode' => false
];

// Load user settings from database
try {
    $stmt = $conn->prepare("
        SELECT notification_preferences, theme 
        FROM user_settings 
        WHERE user_id = ?
    ");
    $stmt->execute([$user_id]);
    $user_settings = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user_settings) {
        // Parse notification preferences
        $notification_prefs = json_decode($user_settings['notification_preferences'], true);
        if ($notification_prefs) {
            $settings['email_notifications'] = isset($notification_prefs['email_notifications']) ? 
                $notification_prefs['email_notifications'] : true;
            $settings['push_notifications'] = isset($notification_prefs['push_notifications']) ? 
                $notification_prefs['push_notifications'] : true;
        }
        
        // Set theme
        $settings['dark_mode'] = ($user_settings['theme'] === 'dark');
    }
} catch (PDOException $e) {
    // If there's an error, use default settings
    error_log("Error loading user settings: " . $e->getMessage());
}

// Page title
$page_title = "Settings";
include '../../includes/header.php';
?>

<div class="d-flex">
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="logo-container">
            <img src="../../assets/images/logo.png" alt="TaskMaster Logo" class="logo">
            <h1>TaskMaster</h1>
        </div>
        <nav>
            <ul>
                <li>
                    <a href="../core/dashboard.php"><i class="fas fa-home"></i> Home</a>
                </li>
                <li>
                    <a href="../projects/projects.php"><i class="fas fa-cube"></i> Projects</a>
                </li>
                <li>
                    <a href="../tasks/tasks.php"><i class="fas fa-clipboard-list"></i> Tasks</a>
                </li>
                <li>
                    <a href="../users/profile.php"><i class="fas fa-user"></i> Profile</a>
                </li>
                <li>
                    <a href="../users/connections.php"><i class="fas fa-users"></i> Connections</a>
                </li>
            </ul>
        </nav>
        <div class="sidebar-footer">
            <a href="../core/settings.php" class="active"><i class="fas fa-cog"></i> Settings</a>
            <a href="#" id="logout-btn"><i class="fas fa-sign-out-alt"></i> Log Out</a>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Top Navigation -->
        <div class="top-nav">
            <!-- Notification system is included in header.php -->
            <div class="top-nav-right-placeholder"></div>
        </div>

        <!-- Settings Content -->
        <div class="settings-container">
            <h1>Settings</h1>
            <p class="settings-description">Customize your TaskMaster experience</p>

            <form action="settings.php" method="post">
                <!-- Notifications Section -->
                <div class="settings-section">
                    <h2>Notifications</h2>
                    
                    <div class="setting-item">
                        <div class="setting-info">
                            <h3>Email Notifications</h3>
                            <p>Receive notifications via email</p>
                        </div>
                        <div class="setting-control">
                            <label class="switch">
                                <input type="checkbox" name="email_notifications" <?php echo $settings['email_notifications'] ? 'checked' : ''; ?>>
                                <span class="slider round"></span>
                            </label>
                        </div>
                    </div>
                    
                    <div class="setting-item">
                        <div class="setting-info">
                            <h3>Push Notifications</h3>
                            <p>Receive notifications in your browser</p>
                        </div>
                        <div class="setting-control">
                            <label class="switch">
                                <input type="checkbox" name="push_notifications" <?php echo $settings['push_notifications'] ? 'checked' : ''; ?>>
                                <span class="slider round"></span>
                            </label>
                        </div>
                    </div>
                </div>
                
                <!-- Hidden minimalist mode for functionality -->
                <input type="hidden" name="minimalist_mode" value="0">
                <input type="hidden" name="dark_mode" value="0">
                
                <!-- Account Section -->
                <div class="settings-section">
                    <h2>Account</h2>
                    
                    <div class="setting-item">
                        <div class="setting-info">
                            <h3>Change Password</h3>
                            <p>Update your account password</p>
                        </div>
                        <div class="setting-control">
                            <button type="button" class="btn btn-outline-primary" id="change-password-btn">Change</button>
                        </div>
                    </div>
                    
                    <div class="setting-item">
                        <div class="setting-info">
                            <h3>Delete Account</h3>
                            <p>Permanently delete your account</p>
                        </div>
                        <div class="setting-control">
                            <button type="button" class="btn btn-outline-danger" id="delete-account-btn">Delete</button>
                        </div>
                    </div>
                </div>
                
                <div class="settings-actions">
                    <button type="submit" name="save_settings" class="btn btn-primary">Save Changes</button>
                    <button type="reset" class="btn btn-secondary">Reset</button>
                </div>
            </form>
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
                <a href="../auth/logout.php" class="btn btn-primary">Logout</a>
            </div>
        </div>
    </div>
</div>

<!-- Change Password Modal -->
<div class="modal fade" id="changePasswordModal" tabindex="-1" aria-labelledby="changePasswordModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="changePasswordModalLabel">Change Password</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="password-form" action="settings.php" method="post">
                    <input type="hidden" name="change_password" value="1">
                    <div class="mb-3">
                        <label for="current-password" class="form-label">Current Password</label>
                        <input type="password" class="form-control" id="current-password" name="current_password" required>
                        <div class="invalid-feedback">Please enter your current password</div>
                    </div>
                    <div class="mb-3">
                        <label for="new-password" class="form-label">New Password</label>
                        <input type="password" class="form-control" id="new-password" name="new_password" required minlength="8">
                        <div class="invalid-feedback">Password must be at least 8 characters long</div>
                        <div class="form-text">Password must be at least 8 characters long</div>
                    </div>
                    <div class="mb-3">
                        <label for="confirm-password" class="form-label">Confirm New Password</label>
                        <input type="password" class="form-control" id="confirm-password" name="confirm_password" required>
                        <div class="invalid-feedback">Passwords do not match</div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="save-password">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Delete Account Modal -->
<div class="modal fade" id="deleteAccountModal" tabindex="-1" aria-labelledby="deleteAccountModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="deleteAccountModalLabel">Delete Account</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="settings.php" method="post">
                <input type="hidden" name="delete_account" value="1">
                <div class="modal-body">
                    <p>Are you sure you want to delete your account? This action cannot be undone.</p>
                    <div class="mb-3">
                        <label for="delete-confirm" class="form-label">Type "DELETE" to confirm</label>
                        <input type="text" class="form-control" id="delete-confirm" name="delete_confirm" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger" id="confirm-delete" disabled>Delete Account</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Logout confirmation
    document.getElementById('logout-btn').addEventListener('click', function(e) {
        e.preventDefault();
        var logoutModal = new bootstrap.Modal(document.getElementById('logoutModal'));
        logoutModal.show();
    });
    
    // Change password modal
    document.getElementById('change-password-btn').addEventListener('click', function() {
        var changePasswordModal = new bootstrap.Modal(document.getElementById('changePasswordModal'));
        changePasswordModal.show();
    });
    
    // Password form validation
    const newPasswordInput = document.getElementById('new-password');
    const confirmPasswordInput = document.getElementById('confirm-password');
    
    // Validate password match
    confirmPasswordInput.addEventListener('input', function() {
        if (newPasswordInput.value !== this.value) {
            this.setCustomValidity('Passwords do not match');
            this.classList.add('is-invalid');
        } else {
            this.setCustomValidity('');
            this.classList.remove('is-invalid');
        }
    });
    
    // Validate password strength
    newPasswordInput.addEventListener('input', function() {
        if (this.value.length < 8) {
            this.setCustomValidity('Password must be at least 8 characters long');
            this.classList.add('is-invalid');
        } else {
            this.setCustomValidity('');
            this.classList.remove('is-invalid');
            
            // Re-validate confirm password
            if (confirmPasswordInput.value) {
                if (confirmPasswordInput.value !== this.value) {
                    confirmPasswordInput.setCustomValidity('Passwords do not match');
                    confirmPasswordInput.classList.add('is-invalid');
                } else {
                    confirmPasswordInput.setCustomValidity('');
                    confirmPasswordInput.classList.remove('is-invalid');
                }
            }
        }
    });
    
    // Delete account modal
    document.getElementById('delete-account-btn').addEventListener('click', function() {
        var deleteAccountModal = new bootstrap.Modal(document.getElementById('deleteAccountModal'));
        deleteAccountModal.show();
    });
    
    // Enable delete button when "DELETE" is typed
    document.getElementById('delete-confirm').addEventListener('input', function() {
        document.getElementById('confirm-delete').disabled = this.value !== 'DELETE';
    });
    
    // Initialize minimalist mode (now controlled via backend only)
    if (<?php echo $settings['minimalist_mode'] ? 'true' : 'false'; ?>) {
        document.body.classList.add('minimalist-mode');
    }
});
</script>

<?php include '../../includes/footer.php'; ?> 