<?php // Admin Settings Page ?>

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
    setMessage("You do not have permission to access the admin settings.", "danger");
    redirect('../pages/core/dashboard.php');
}

// Get admin data
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];
$first_name = explode(' ', $user_name)[0];

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle user role update
    if (isset($_POST['action']) && $_POST['action'] === 'update_role') {
        $target_user_id = $_POST['user_id'];
        $new_role = $_POST['role'];
        
        try {
            // Check if target user is an admin
            $stmt = $conn->prepare("SELECT role FROM users WHERE user_id = ?");
            $stmt->execute([$target_user_id]);
            $user = $stmt->fetch();
            
            // Don't allow changing admin roles
            if ($user && $user['role'] === 'admin') {
                setMessage("Admin roles cannot be modified.", "danger");
            } else {
                // Update user role
                $stmt = $conn->prepare("UPDATE users SET role = ? WHERE user_id = ?");
                $stmt->execute([$new_role, $target_user_id]);
                
                setMessage("User role updated successfully.", "success");
            }
        } catch (PDOException $e) {
            setMessage("Error updating user role: " . $e->getMessage(), "danger");
        }
        
        redirect('settings.php');
    }
    
    // Handle user ban/unban
    if (isset($_POST['action']) && $_POST['action'] === 'ban_user') {
        $target_user_id = $_POST['user_id'];
        $ban_reason = $_POST['ban_reason'];
        $is_banned = 1;
        
        try {
            // Check if target user is an admin
            $stmt = $conn->prepare("SELECT role, email, first_name, last_name FROM users WHERE user_id = ?");
            $stmt->execute([$target_user_id]);
            $user = $stmt->fetch();
            
            // Don't allow banning admins
            if ($user && $user['role'] === 'admin') {
                setMessage("Admin users cannot be banned.", "danger");
            } else {
                // Ban user
                $stmt = $conn->prepare("UPDATE users SET is_banned = ? WHERE user_id = ?");
                $stmt->execute([$is_banned, $target_user_id]);
                
                // Send email notification
                $user_name = $user['first_name'] . ' ' . $user['last_name'];
                $email_sent = sendBanNotification($user['email'], $user_name, $ban_reason);
                
                if ($email_sent) {
                    setMessage("User has been banned and notified via email.", "success");
                } else {
                    setMessage("User has been banned, but there was an issue sending the email notification.", "warning");
                }
            }
        } catch (PDOException $e) {
            setMessage("Error banning user: " . $e->getMessage(), "danger");
        }
        
        redirect('settings.php');
    }
    
    // Handle user unban
    if (isset($_POST['action']) && $_POST['action'] === 'unban_user') {
        $target_user_id = $_POST['user_id'];
        $is_banned = 0;
        
        try {
            // Get user details
            $stmt = $conn->prepare("SELECT email, first_name, last_name FROM users WHERE user_id = ?");
            $stmt->execute([$target_user_id]);
            $user = $stmt->fetch();
            
            // Unban user
            $stmt = $conn->prepare("UPDATE users SET is_banned = ? WHERE user_id = ?");
            $stmt->execute([$is_banned, $target_user_id]);
            
            // Send email notification
            $user_name = $user['first_name'] . ' ' . $user['last_name'];
            $email_sent = sendUnbanNotification($user['email'], $user_name);
            
            if ($email_sent) {
                setMessage("User has been unbanned and notified via email.", "success");
            } else {
                setMessage("User has been unbanned, but there was an issue sending the email notification.", "warning");
            }
        } catch (PDOException $e) {
            setMessage("Error unbanning user: " . $e->getMessage(), "danger");
        }
        
        redirect('settings.php');
    }
}

// Page title
$page_title = "Admin Settings";
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
                    <li class="active">
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
                    <h1>System Settings</h1>
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
                    <h2>User Management</h2>
                    <div class="admin-card">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                // Get users
                                $stmt = $conn->prepare("SELECT * FROM users ORDER BY user_id DESC");
                                $stmt->execute();
                                $users = $stmt->fetchAll();
                                
                                foreach ($users as $user):
                                    $fullName = htmlspecialchars($user['first_name'] . ' ' . $user['last_name']);
                                    $email = htmlspecialchars($user['email']);
                                    $role = $user['role'];
                                    $status = $user['is_banned'] ? 'Banned' : 'Active';
                                    $statusClass = $user['is_banned'] ? 'text-danger' : 'text-success';
                                ?>
                                <tr>
                                    <td><?php echo $user['user_id']; ?></td>
                                    <td><?php echo $fullName; ?></td>
                                    <td><?php echo $email; ?></td>
                                    <td>
                                        <?php if ($role === 'admin'): ?>
                                            <span class="badge bg-primary">Admin</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary"><?php echo ucfirst($role); ?></span>
                                            <button type="button" class="btn btn-sm btn-link edit-role-btn" 
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#editRoleModal" 
                                                    data-user-id="<?php echo $user['user_id']; ?>"
                                                    data-user-name="<?php echo $fullName; ?>"
                                                    data-user-role="<?php echo $role; ?>">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="<?php echo $statusClass; ?>"><?php echo $status; ?></span></td>
                                    <td>
                                        <?php if ($role !== 'admin'): ?>
                                            <?php if ($user['is_banned']): ?>
                                                <button type="button" class="btn btn-sm btn-outline-success unban-btn"
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#unbanUserModal"
                                                        data-user-id="<?php echo $user['user_id']; ?>"
                                                        data-user-name="<?php echo $fullName; ?>">
                                                    Unban
                                                </button>
                                            <?php else: ?>
                                                <button type="button" class="btn btn-sm btn-outline-danger ban-btn"
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#banUserModal"
                                                        data-user-id="<?php echo $user['user_id']; ?>"
                                                        data-user-name="<?php echo $fullName; ?>">
                                                    Ban
                                                </button>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                
            </div>
        </div>
    </div>

    <!-- Edit Role Modal -->
    <div class="modal fade" id="editRoleModal" tabindex="-1" aria-labelledby="editRoleModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editRoleModalLabel">Edit User Role</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="settings.php" method="post">
                    <input type="hidden" name="action" value="update_role">
                    <input type="hidden" name="user_id" id="edit-user-id">
                    <div class="modal-body">
                        <p>Change role for: <strong id="edit-user-name"></strong></p>
                        <div class="mb-3">
                            <label for="role" class="form-label">Role</label>
                            <select class="form-select" id="role" name="role">
                                <option value="user">User</option>
                                <option value="admin">Admin</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Ban User Modal -->
    <div class="modal fade" id="banUserModal" tabindex="-1" aria-labelledby="banUserModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="banUserModalLabel">Ban User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="settings.php" method="post">
                    <input type="hidden" name="action" value="ban_user">
                    <input type="hidden" name="user_id" id="ban-user-id">
                    <div class="modal-body">
                        <p>Are you sure you want to ban: <strong id="ban-user-name"></strong>?</p>
                        <div class="mb-3">
                            <label for="ban_reason" class="form-label">Reason for Ban</label>
                            <textarea class="form-control" id="ban_reason" name="ban_reason" rows="3" required></textarea>
                            <div class="form-text">This reason will be included in the email notification to the user.</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Ban User</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Unban User Modal -->
    <div class="modal fade" id="unbanUserModal" tabindex="-1" aria-labelledby="unbanUserModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="unbanUserModalLabel">Unban User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="settings.php" method="post">
                    <input type="hidden" name="action" value="unban_user">
                    <input type="hidden" name="user_id" id="unban-user-id">
                    <div class="modal-body">
                        <p>Are you sure you want to unban: <strong id="unban-user-name"></strong>?</p>
                        <p>An email notification will be sent to inform the user that their account has been restored.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">Unban User</button>
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
        
        // Edit role modal
        const editRoleButtons = document.querySelectorAll('.edit-role-btn');
        editRoleButtons.forEach(button => {
            button.addEventListener('click', function() {
                const userId = this.getAttribute('data-user-id');
                const userName = this.getAttribute('data-user-name');
                const userRole = this.getAttribute('data-user-role');
                
                document.getElementById('edit-user-id').value = userId;
                document.getElementById('edit-user-name').textContent = userName;
                document.getElementById('role').value = userRole;
            });
        });
        
        // Ban user modal
        const banButtons = document.querySelectorAll('.ban-btn');
        banButtons.forEach(button => {
            button.addEventListener('click', function() {
                const userId = this.getAttribute('data-user-id');
                const userName = this.getAttribute('data-user-name');
                
                document.getElementById('ban-user-id').value = userId;
                document.getElementById('ban-user-name').textContent = userName;
            });
        });
        
        // Unban user modal
        const unbanButtons = document.querySelectorAll('.unban-btn');
        unbanButtons.forEach(button => {
            button.addEventListener('click', function() {
                const userId = this.getAttribute('data-user-id');
                const userName = this.getAttribute('data-user-name');
                
                document.getElementById('unban-user-id').value = userId;
                document.getElementById('unban-user-name').textContent = userName;
            });
        });
    });
    </script>
</body>
</html>
