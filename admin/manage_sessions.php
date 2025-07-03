<?php
/**
 * User Session Management
 * 
 * Admin tool to view and manage user sessions
 */
require_once '../includes/functions.php';
require_once '../includes/session_functions.php';
require_once '../config/database.php';

// Check if user is logged in and is admin
if (!isLoggedIn() || !isset($_SESSION['is_admin']) || $_SESSION['is_admin'] != 1) {
    setMessage("You must be an admin to access this page.", "danger");
    redirect('../pages/auth/login.php');
}

// Process actions
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    
    // Close a session
    if ($action === 'close' && isset($_GET['id'])) {
        $session_id = (int)$_GET['id'];
        
        try {
            $stmt = $conn->prepare("
                UPDATE user_sessions 
                SET logout_time = CURRENT_TIMESTAMP,
                    duration_minutes = TIMESTAMPDIFF(MINUTE, login_time, CURRENT_TIMESTAMP),
                    is_active = 0
                WHERE session_id = ?
            ");
            $stmt->execute([$session_id]);
            
            setMessage("Session #$session_id closed successfully.", "success");
        } catch (PDOException $e) {
            setMessage("Error closing session: " . $e->getMessage(), "danger");
        }
    }
    
    // Delete a session
    if ($action === 'delete' && isset($_GET['id'])) {
        $session_id = (int)$_GET['id'];
        
        try {
            $stmt = $conn->prepare("DELETE FROM user_sessions WHERE session_id = ?");
            $stmt->execute([$session_id]);
            
            setMessage("Session #$session_id deleted successfully.", "success");
        } catch (PDOException $e) {
            setMessage("Error deleting session: " . $e->getMessage(), "danger");
        }
    }
    
    // Clear all sessions for a user
    if ($action === 'clear_user' && isset($_GET['user_id'])) {
        $user_id = (int)$_GET['user_id'];
        
        try {
            $stmt = $conn->prepare("DELETE FROM user_sessions WHERE user_id = ?");
            $stmt->execute([$user_id]);
            
            setMessage("All sessions for user #$user_id cleared successfully.", "success");
        } catch (PDOException $e) {
            setMessage("Error clearing sessions: " . $e->getMessage(), "danger");
        }
    }
}

// Get all sessions
try {
    $stmt = $conn->prepare("
        SELECT us.*, u.first_name, u.last_name, u.email
        FROM user_sessions us
        JOIN users u ON us.user_id = u.user_id
        ORDER BY us.login_time DESC
        LIMIT 100
    ");
    $stmt->execute();
    $sessions = $stmt->fetchAll();
    
    // Get active session count
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM user_sessions WHERE is_active = 1");
    $stmt->execute();
    $active_count = $stmt->fetch()['count'];
    
    // Get total users with sessions
    $stmt = $conn->prepare("SELECT COUNT(DISTINCT user_id) as count FROM user_sessions");
    $stmt->execute();
    $user_count = $stmt->fetch()['count'];
} catch (PDOException $e) {
    $sessions = [];
    $active_count = 0;
    $user_count = 0;
    setMessage("Error fetching sessions: " . $e->getMessage(), "danger");
}

// Page title
$page_title = "Manage Sessions";
include '../includes/header.php';
?>

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1>User Session Management</h1>
        <a href="../pages/core/dashboard.php" class="btn btn-secondary">Back to Dashboard</a>
    </div>
    
    <?php displayMessage(); ?>
    
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">Active Sessions</h5>
                    <h2 class="card-text"><?php echo $active_count; ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">Total Users</h5>
                    <h2 class="card-text"><?php echo $user_count; ?></h2>
                </div>
            </div>
        </div>
    </div>
    
    <div class="card">
        <div class="card-header">
            <h5>Session History (Last 100)</h5>
        </div>
        <div class="card-body">
            <?php if (count($sessions) > 0): ?>
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>User</th>
                            <th>Login Time</th>
                            <th>Logout Time</th>
                            <th>Duration (min)</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sessions as $session): ?>
                        <tr>
                            <td><?php echo $session['session_id']; ?></td>
                            <td>
                                <?php echo htmlspecialchars($session['first_name'] . ' ' . $session['last_name']); ?>
                                <br>
                                <small class="text-muted"><?php echo htmlspecialchars($session['email']); ?></small>
                            </td>
                            <td><?php echo $session['login_time']; ?></td>
                            <td><?php echo $session['logout_time'] ? $session['logout_time'] : '<span class="text-warning">Still active</span>'; ?></td>
                            <td>
                                <?php 
                                if ($session['duration_minutes']) {
                                    echo $session['duration_minutes'];
                                } else if ($session['is_active']) {
                                    $login_time = strtotime($session['login_time']);
                                    $current_time = time();
                                    $minutes = round(($current_time - $login_time) / 60, 2);
                                    echo '<span class="text-info">' . $minutes . ' (ongoing)</span>';
                                } else {
                                    echo 'N/A';
                                }
                                ?>
                            </td>
                            <td>
                                <?php if ($session['is_active']): ?>
                                <span class="badge bg-success">Active</span>
                                <?php else: ?>
                                <span class="badge bg-secondary">Closed</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($session['is_active']): ?>
                                <a href="?action=close&id=<?php echo $session['session_id']; ?>" class="btn btn-sm btn-warning" onclick="return confirm('Are you sure you want to close this session?')">Close</a>
                                <?php endif; ?>
                                <a href="?action=delete&id=<?php echo $session['session_id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to delete this session?')">Delete</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <p>No sessions found.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?> 