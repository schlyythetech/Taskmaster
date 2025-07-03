<?php
require_once '../includes/functions.php';
require_once '../includes/session_functions.php';
require_once '../config/database.php';

// Check if user is logged in and is an admin
if (!isLoggedIn()) {
    setMessage("You must be logged in to access this page.", "danger");
    redirect('../pages/auth/login.php');
}

if (!isAdmin()) {
    setMessage("You do not have permission to access the admin dashboard.", "danger");
    redirect('../pages/core/dashboard.php');
}

// Get admin data
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];
$first_name = explode(' ', $user_name)[0];

// Fetch system statistics
try {
    // Total users
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM users");
    $stmt->execute();
    $result = $stmt->fetch();
    $total_users = $result['total'];

    // Active users (users who have logged in within the last 7 days)
    $stmt = $conn->prepare("
        SELECT COUNT(DISTINCT user_id) as active 
        FROM user_sessions 
        WHERE login_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    ");
    $stmt->execute();
    $result = $stmt->fetch();
    $active_users = $result['active'];

    // Total projects
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM projects");
    $stmt->execute();
    $result = $stmt->fetch();
    $total_projects = $result['total'];

    // Total tasks
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM tasks");
    $stmt->execute();
    $result = $stmt->fetch();
    $total_tasks = $result['total'];

    // Completed tasks
    $stmt = $conn->prepare("SELECT COUNT(*) as completed FROM tasks WHERE status = 'completed'");
    $stmt->execute();
    $result = $stmt->fetch();
    $completed_tasks = $result['completed'];

    // Tasks in progress
    $stmt = $conn->prepare("SELECT COUNT(*) as in_progress FROM tasks WHERE status IN ('backlog', 'to_do', 'in_progress', 'review')");
    $stmt->execute();
    $result = $stmt->fetch();
    $in_progress_tasks = $result['in_progress'];
    
} catch (PDOException $e) {
    // Log error and set default values
    error_log("Admin dashboard statistics error: " . $e->getMessage());
    $total_users = 0;
    $active_users = 0;
    $total_projects = 0;
    $total_tasks = 0;
    $completed_tasks = 0;
    $in_progress_tasks = 0;
}

// Page title
$page_title = "Admin Dashboard";
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
                    <li class="active">
                        <a href="dashboard.php"><i class="fas fa-home"></i> Home</a>
                    </li>
                    <li>
                        <a href="settings.php"><i class="fas fa-cog"></i> Settings</a>
                    </li>
                    <li>
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
                    <h1>Admin Dashboard</h1>
                </div>
                <div class="admin-user">
                    <span>Welcome, <?php echo htmlspecialchars($first_name); ?></span>
                </div>
            </div>

            <!-- Dashboard Content -->
            <div class="admin-dashboard-container">
                <div class="welcome-section">
                    <h2>System Overview</h2>
                    <p>Here is a summary of your system activity and statistics.</p>
                </div>

                <!-- Stats Cards -->
                <div class="stats-container">
                    <div class="stats-row">
                        <!-- Users Stats -->
                        <div class="stats-card">
                            <div class="stats-info">
                                <div>
                                    <h3>Total Users</h3>
                                    <h2 class="stats-number"><?php echo $total_users; ?></h2>
                                </div>
                            </div>
                        </div>
                        <div class="stats-card">
                            <div class="stats-info">
                                <div>
                                    <h3>Active Users</h3>
                                    <h2 class="stats-number"><?php echo $active_users; ?></h2>
                                </div>
                            </div>
                        </div>
                        <div class="stats-card">
                            <div class="stats-info">
                                <div>
                                    <h3>Total Projects</h3>
                                    <h2 class="stats-number"><?php echo $total_projects; ?></h2>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="stats-row">
                        <!-- Tasks Stats -->
                        <div class="stats-card">
                            <div class="stats-info">
                                <div>
                                    <h3>Total Tasks</h3>
                                    <h2 class="stats-number"><?php echo $total_tasks; ?></h2>
                                </div>
                            </div>
                        </div>
                        <div class="stats-card">
                            <div class="stats-info">
                                <div>
                                    <h3>Completed Tasks</h3>
                                    <h2 class="stats-number"><?php echo $completed_tasks; ?></h2>
                                </div>
                            </div>
                        </div>
                        <div class="stats-card">
                            <div class="stats-info">
                                <div>
                                    <h3>In Progress Tasks</h3>
                                    <h2 class="stats-number"><?php echo $in_progress_tasks; ?></h2>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recent Activity Section -->
                <div class="admin-section">
                    <h2>Recent User Activity</h2>
                    <div class="admin-card">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Action</th>
                                    <th>Time</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                // Get recent user sessions
                                $stmt = $conn->prepare("
                                    SELECT us.*, u.first_name, u.last_name 
                                    FROM user_sessions us
                                    JOIN users u ON us.user_id = u.user_id
                                    ORDER BY login_time DESC
                                    LIMIT 10
                                ");
                                $stmt->execute();
                                $sessions = $stmt->fetchAll();
                                
                                foreach ($sessions as $session):
                                    $fullName = htmlspecialchars($session['first_name'] . ' ' . $session['last_name']);
                                    $loginTime = date('M j, Y g:i A', strtotime($session['login_time']));
                                    $action = $session['is_active'] ? 'Logged In' : 'Logged Out';
                                    $actionClass = $session['is_active'] ? 'text-success' : 'text-secondary';
                                ?>
                                <tr>
                                    <td><?php echo $fullName; ?></td>
                                    <td><span class="<?php echo $actionClass; ?>"><?php echo $action; ?></span></td>
                                    <td><?php echo $loginTime; ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
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
    });
    </script>
</body>
</html>
