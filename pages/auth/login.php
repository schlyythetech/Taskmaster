<?php
require_once '../../includes/functions.php';
require_once '../../includes/session_functions.php';
require_once '../../config/database.php';

// Check if user is already logged in
if (isLoggedIn()) {
    redirect('../core/dashboard.php');
}

// Ensure user_sessions table exists
ensureUserSessionsTable($conn);

// Process login form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email_or_username = sanitize($_POST['email']);
    $password = $_POST['password'];
    $errors = [];

    // Validate inputs
    if (empty($email_or_username)) {
        $errors[] = "Email or username is required";
    }
    if (empty($password)) {
        $errors[] = "Password is required";
    }

    // If no errors, proceed with login
    if (empty($errors)) {
        // Check if user exists by email
        $stmt = $conn->prepare("SELECT user_id, first_name, last_name, email, password, role, is_banned FROM users WHERE email = ?");
        $stmt->execute([$email_or_username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            // Check if user is banned
            if ($user['is_banned']) {
                setMessage("Your account has been suspended. If you believe this is an error, please contact the administrator for assistance.", "danger");
                redirect('login.php');
                exit;
            }
            
            // Login successful
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['user_name'] = $user['first_name'] . ' ' . $user['last_name'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['first_name'] = $user['first_name'];
            $_SESSION['last_name'] = $user['last_name'];
            $_SESSION['user_role'] = $user['role'];
            
            // Record user login session
            recordUserLogin($conn, $user['user_id']);
            
            // Check if there's a pending invitation token
            if (isset($_SESSION['pending_invitation_token'])) {
                $invitation_token = $_SESSION['pending_invitation_token'];
                unset($_SESSION['pending_invitation_token']);
                
                setMessage("Login successful! Welcome back, {$user['first_name']}.", "success");
                redirect('../projects/accept_invitation.php?token=' . $invitation_token);
            } else {
                setMessage("Login successful! Welcome back, {$user['first_name']}.", "success");
                
                // Redirect based on user role
                if ($user['role'] === 'admin') {
                    redirect('../../admin/dashboard.php');
                } else {
                    redirect('../core/dashboard.php');
                }
            }
        } else {
            // Login failed
            setMessage("Invalid email or password. Please try again.", "danger");
        }
    } else {
        // Display errors
        setMessage(implode("<br>", $errors), "danger");
    }
}

// Page title
$page_title = "Login";
?>

<?php include '../../includes/header.php'; ?>

<div class="auth-container">
    <img src="../../assets/images/logo.png" alt="TaskMaster Logo" class="auth-logo">
    <h1 class="auth-title">Welcome Back</h1>
    
    <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" method="post" class="needs-validation" novalidate>
        <div class="form-group">
            <label for="email" class="form-label">
                <i class="fas fa-envelope"></i> Email
            </label>
            <input type="email" class="form-control" id="email" name="email" placeholder="Enter your email" required>
            <div class="invalid-feedback">Please enter your email</div>
        </div>
        
        <div class="form-group">
            <label for="password" class="form-label">
                <i class="fas fa-lock"></i> Password
            </label>
            <div class="input-group">
                <input type="password" class="form-control" id="password" name="password" placeholder="Enter your password" required>
                <button type="button" class="btn btn-outline-secondary toggle-password" data-toggle="#password">
                    <i class="fas fa-eye"></i>
                </button>
                <div class="invalid-feedback">Please enter your password</div>
            </div>
        </div>
        
        <div class="form-group">
            <button type="submit" class="btn btn-primary">Sign In</button>
        </div>
    </form>
    
    <div class="auth-links">
        <a href="#" id="forgot-password-link">Forgot password?</a>
        <span class="mx-2">|</span>
        <a href="../auth/register.php">Create an account</a>
    </div>
</div>

<!-- Forgot Password Overlay -->
<div id="forgot-password-overlay" class="overlay" style="display: none;">
    <div class="overlay-content">
        <button type="button" id="close-overlay" class="close-overlay">
            <i class="fas fa-times"></i>
        </button>
        
        <h2 class="overlay-title">Forgot Password</h2>
        <p class="text-muted text-center mb-4">Enter your email address and we'll send you a link to reset your password</p>
        <form action="reset-password.php" method="post" class="needs-validation" novalidate>
            <div class="form-group">
                <label for="reset-email" class="form-label">
                    <i class="fas fa-envelope"></i> Email Address
                </label>
                <input type="email" class="form-control" id="reset-email" name="email" placeholder="Enter your email address" required>
                <div class="invalid-feedback">Please enter a valid email address</div>
            </div>
            <div class="overlay-actions">
                <button type="button" id="cancel-forgot-password" class="btn btn-secondary">Cancel</button>
                <button type="submit" class="btn btn-primary" style="width: auto;">Send Reset Link</button>
            </div>
        </form>
    </div>
</div>

<?php include '../../includes/footer.php'; ?> 