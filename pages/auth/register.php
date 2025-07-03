<?php
require_once '../../includes/functions.php';
require_once '../../config/database.php';

// Check if user is already logged in
if (isLoggedIn()) {
    redirect('../core/dashboard.php');
}

// Process registration form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = sanitize($_POST['first_name']);
    $last_name = sanitize($_POST['last_name']);
    $email = sanitize($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $errors = [];

    // Validate inputs
    if (empty($first_name)) {
        $errors[] = "First name is required";
    }
    if (empty($last_name)) {
        $errors[] = "Last name is required";
    }
    if (empty($email)) {
        $errors[] = "Email is required";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format";
    }
    if (empty($password)) {
        $errors[] = "Password is required";
    } elseif (strlen($password) < 6) {
        $errors[] = "Password must be at least 6 characters";
    }
    if ($password !== $confirm_password) {
        $errors[] = "Passwords do not match";
    }

    // Check if email already exists
    $stmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $count = $stmt->fetchColumn();
    if ($count > 0) {
        $errors[] = "Email already exists. Please use a different email or login.";
    }

    // If no errors, proceed with registration
    if (empty($errors)) {
        // Hash password
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        
        // Insert user into database
        $stmt = $conn->prepare("INSERT INTO users (first_name, last_name, email, password) VALUES (?, ?, ?, ?)");
        $result = $stmt->execute([$first_name, $last_name, $email, $hashed_password]);
        
        if ($result) {
            // Registration successful
            setMessage("Registration successful! You can now login.", "success");
            redirect('../auth/login.php');
        } else {
            // Registration failed
            setMessage("Registration failed. Please try again later.", "danger");
        }
    } else {
        // Display errors
        setMessage(implode("<br>", $errors), "danger");
    }
}

// Page title
$page_title = "Register";
?>

<?php include '../../includes/header.php'; ?>

<div class="auth-container">
    <img src="../../assets/images/logo.png" alt="TaskMaster Logo" class="auth-logo">
    <h1 class="auth-title">Create Account</h1>
    
    <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" method="post" class="needs-validation" novalidate>
        <div class="row">
            <div class="col-md-6">
                <div class="form-group">
                    <label for="first_name" class="form-label">
                        <i class="fas fa-user"></i> First Name
                    </label>
                    <input type="text" class="form-control" id="first_name" name="first_name" placeholder="Enter your first name" value="<?php echo isset($_POST['first_name']) ? htmlspecialchars($_POST['first_name']) : ''; ?>" required>
                    <div class="invalid-feedback">Please enter your first name</div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="form-group">
                    <label for="last_name" class="form-label">
                        <i class="fas fa-user"></i> Last Name
                    </label>
                    <input type="text" class="form-control" id="last_name" name="last_name" placeholder="Enter your last name" value="<?php echo isset($_POST['last_name']) ? htmlspecialchars($_POST['last_name']) : ''; ?>" required>
                    <div class="invalid-feedback">Please enter your last name</div>
                </div>
            </div>
        </div>
        
        <div class="form-group">
            <label for="email" class="form-label">
                <i class="fas fa-envelope"></i> Email Address
            </label>
            <input type="email" class="form-control" id="email" name="email" placeholder="Enter your email address" value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" required>
            <div class="invalid-feedback">Please enter a valid email address</div>
        </div>
        
        <div class="form-group">
            <label for="password" class="form-label">
                <i class="fas fa-lock"></i> Password
            </label>
            <div class="input-group">
                <input type="password" class="form-control" id="password" name="password" placeholder="Create a password (min. 6 characters)" required>
                <button type="button" class="btn btn-outline-secondary toggle-password" data-toggle="#password">
                    <i class="fas fa-eye"></i>
                </button>
                <div class="invalid-feedback">Password must be at least 6 characters</div>
            </div>
            <small class="text-muted">Password must be at least 6 characters long</small>
        </div>
        
        <div class="form-group">
            <label for="confirm-password" class="form-label">
                <i class="fas fa-lock"></i> Confirm Password
            </label>
            <div class="input-group">
                <input type="password" class="form-control" id="confirm-password" name="confirm_password" placeholder="Confirm your password" required>
                <button type="button" class="btn btn-outline-secondary toggle-password" data-toggle="#confirm-password">
                    <i class="fas fa-eye"></i>
                </button>
                <div class="invalid-feedback">Passwords must match</div>
            </div>
        </div>
        
        <div class="form-group">
            <button type="submit" class="btn btn-primary">Create Account</button>
        </div>
    </form>
    
    <div class="auth-links">
        <a href="../auth/login.php">Already have an account? Sign in</a>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>