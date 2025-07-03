<?php
require_once '../../includes/functions.php';
require_once '../../config/database.php';

// Force start the session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Clear ANY session messages to ensure no error messages persist
if (isset($_SESSION['message'])) {
    unset($_SESSION['message']);
    unset($_SESSION['message_type']);
}

// Check if user is logged in
if (!isLoggedIn()) {
    setMessage("You must be logged in to view profiles.", "danger");
    redirect('../auth/login.php');
}

// Get current user's ID
$current_user_id = $_SESSION['user_id'];

// Check if a user ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    setMessage("No user specified.", "danger");
    redirect('../users/connections.php');
}

// Get the requested user's ID
$user_id = (int)$_GET['id'];

// Force integer type for IDs to ensure consistent comparisons
$user_id = (int)$user_id;
$current_user_id = (int)$current_user_id;

// Log the IDs for debugging
error_log("View profile - Viewing user ID: $user_id, Current user ID: $current_user_id");

// Redirect to profile.php if trying to view own profile
if ($user_id === $current_user_id) {
    error_log("User redirected to profile.php: Attempted to view own profile through others_profile.php");
    setMessage("You can view your profile through the Profile menu.", "info");
    redirect('../users/profile.php');
    exit;
}

/**
 * Helper function to get user's basic profile data
 * 
 * @param PDO $conn Database connection
 * @param int $user_id User ID to fetch data for
 * @return array|false User data or false if not found
 */
function getOtherUserProfileData($conn, $user_id) {
    $stmt = $conn->prepare("
        SELECT 
            u.user_id, u.first_name, u.last_name, u.email, u.bio, u.profile_image, u.role,
            (SELECT COUNT(*) FROM connections 
             WHERE ((user_id = u.user_id OR connected_user_id = u.user_id)) 
             AND status = 'accepted') as connection_count
        FROM users u
        WHERE u.user_id = ? AND u.is_banned = 0
    ");
    $stmt->execute([$user_id]);
    $user_data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user_data) {
        $user_data['full_name'] = $user_data['first_name'] . ' ' . $user_data['last_name'];
        
        // Get user's workload data (tasks by day of week)
        $stmt = $conn->prepare("
            SELECT 
                DAYOFWEEK(due_date) as day_of_week,
                COUNT(*) as task_count
            FROM tasks
            WHERE assigned_to = ?
            AND status != 'completed'
            AND due_date IS NOT NULL
            AND due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
            GROUP BY DAYOFWEEK(due_date)
            ORDER BY DAYOFWEEK(due_date)
        ");
        $stmt->execute([$user_id]);
        $workload_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Initialize workload array with zeros
        $user_data['workload'] = [
            'labels' => ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'],
            'hours' => [0, 0, 0, 0, 0, 0, 0],
            'tasks' => [0, 0, 0, 0, 0, 0, 0]
        ];
        
        // Fill in the actual data (MySQL DAYOFWEEK: 1=Sunday, 2=Monday, etc.)
        foreach ($workload_data as $day) {
            $index = $day['day_of_week'] - 1; // Convert to 0-based index
            $user_data['workload']['tasks'][$index] = (int)$day['task_count'];
        }
        
        // Set hours data (in a real app, this might come from user_sessions table)
        // For now, we'll use example data
        $user_data['workload']['hours'] = [0, 5, 6, 0, 6, 4, 0];
        
        // Fetch user's completed tasks data for the overview
        $stmt = $conn->prepare("
            SELECT 
                DATE_FORMAT(updated_at, '%Y-%m') as month,
                COUNT(*) as completed_count
            FROM tasks
            WHERE assigned_to = ?
            AND status = 'completed'
            AND updated_at IS NOT NULL
            AND updated_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
            GROUP BY DATE_FORMAT(updated_at, '%Y-%m')
            ORDER BY month
        ");
        $stmt->execute([$user_id]);
        $completed_tasks_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Initialize completed tasks array with the last 6 months
        $user_data['completed_tasks'] = [];
        $current_month = new DateTime();
        
        for ($i = 5; $i >= 0; $i--) {
            $month_date = clone $current_month;
            $month_date->modify("-$i month");
            $month_key = $month_date->format('Y-m');
            $month_label = $month_date->format('M Y');
            
            $user_data['completed_tasks'][$month_key] = [
                'label' => $month_label,
                'count' => 0
            ];
        }
        
        // Fill in the actual data
        foreach ($completed_tasks_data as $item) {
            if (isset($user_data['completed_tasks'][$item['month']])) {
                $user_data['completed_tasks'][$item['month']]['count'] = (int)$item['completed_count'];
            }
        }
    }
    
    return $user_data;
}

/**
 * Helper function to get connection status between two users
 * 
 * @param PDO $conn Database connection
 * @param int $current_user_id Current logged in user
 * @param int $user_id User being viewed
 * @return array Connection data
 */
function getOtherUserConnectionStatus($conn, $current_user_id, $user_id) {
    $stmt = $conn->prepare("
        SELECT 
            connection_id, status, 
            CASE WHEN user_id = ? THEN 'sent' ELSE 'received' END as direction
        FROM connections 
        WHERE (user_id = ? AND connected_user_id = ?) 
           OR (user_id = ? AND connected_user_id = ?)
        LIMIT 1
    ");
    $stmt->execute([$current_user_id, $current_user_id, $user_id, $user_id, $current_user_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Helper function to get user's assigned tasks
 * 
 * @param PDO $conn Database connection
 * @param int $current_user_id Current logged in user
 * @param int $user_id User being viewed
 * @return array List of assigned tasks
 */
function getOtherUserAssignedTasks($conn, $current_user_id, $user_id) {
    $stmt = $conn->prepare("
        SELECT t.task_id, t.title as name, p.name as project, p.project_id, t.due_date
        FROM tasks t
        JOIN projects p ON t.project_id = p.project_id
        JOIN project_members pm1 ON t.project_id = pm1.project_id AND pm1.user_id = ?
        JOIN project_members pm2 ON t.project_id = pm2.project_id AND pm2.user_id = ?
        WHERE t.assigned_to = ?
        AND t.status != 'completed'
        ORDER BY t.due_date ASC
        LIMIT 5
    ");
    $stmt->execute([$current_user_id, $user_id, $user_id]);
    $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format due dates
    foreach ($tasks as &$task) {
        if (!empty($task['due_date'])) {
            $due_date = new DateTime($task['due_date']);
            $task['due_date'] = $due_date->format('M d, Y');
        } else {
            $task['due_date'] = 'No due date';
        }
    }
    
    return $tasks;
}

/**
 * Helper function to get mutual connections between users
 * 
 * @param PDO $conn Database connection
 * @param int $current_user_id Current logged in user
 * @param int $user_id User being viewed
 * @return int Number of mutual connections
 */
function getOtherUserMutualConnections($conn, $current_user_id, $user_id) {
    $stmt = $conn->prepare("
        SELECT COUNT(*) as mutual_connections
        FROM connections c1
        JOIN connections c2 ON 
            (c1.connected_user_id = c2.connected_user_id AND c1.connected_user_id != ? AND c2.connected_user_id != ?)
            OR (c1.connected_user_id = c2.user_id AND c1.connected_user_id != ? AND c2.user_id != ?)
            OR (c1.user_id = c2.connected_user_id AND c1.user_id != ? AND c2.connected_user_id != ?)
            OR (c1.user_id = c2.user_id AND c1.user_id != ? AND c2.user_id != ?)
        WHERE 
            ((c1.user_id = ? AND c1.status = 'accepted') AND (c2.user_id = ? AND c2.status = 'accepted'))
            OR ((c1.user_id = ? AND c1.status = 'accepted') AND (c2.connected_user_id = ? AND c2.status = 'accepted'))
            OR ((c1.connected_user_id = ? AND c1.status = 'accepted') AND (c2.user_id = ? AND c2.status = 'accepted'))
            OR ((c1.connected_user_id = ? AND c1.status = 'accepted') AND (c2.connected_user_id = ? AND c2.status = 'accepted'))
    ");
    $stmt->execute([
        $user_id, $current_user_id, 
        $user_id, $current_user_id, 
        $user_id, $current_user_id, 
        $user_id, $current_user_id,
        $current_user_id, $user_id,
        $current_user_id, $user_id,
        $current_user_id, $user_id,
        $current_user_id, $user_id
    ]);
    $mutual = $stmt->fetch(PDO::FETCH_ASSOC);
    return $mutual ? $mutual['mutual_connections'] : 0;
}

/**
 * Helper function to get user achievements
 * 
 * @param PDO $conn Database connection
 * @param int $user_id User to get achievements for
 * @return array List of achievements
 */
function getOtherUserAchievements($conn, $user_id) {
    $stmt = $conn->prepare("
        SELECT a.achievement_id, a.name, a.icon, a.description, ua.earned_at,
               CASE WHEN ua.user_id IS NOT NULL THEN 1 ELSE 0 END as earned
        FROM achievements a
        LEFT JOIN user_achievements ua ON a.achievement_id = ua.achievement_id AND ua.user_id = ?
        WHERE ua.user_id IS NOT NULL
        ORDER BY ua.earned_at DESC
        LIMIT 3
    ");
    $stmt->execute([$user_id]);
    $achievements = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format achievements with colors
    $colors = ['#8ED1B7', '#E6A6B9', '#B6C3E6', '#D8CEE4', '#F0D9A7'];
    $formatted_achievements = [];
    
    foreach ($achievements as $index => $achievement) {
        $color_index = $index % count($colors);
        $formatted_achievements[] = [
            'id' => $achievement['achievement_id'],
            'name' => $achievement['name'],
            'description' => $achievement['description'],
            'earned' => (bool)$achievement['earned'],
            'earned_at' => $achievement['earned_at'],
            'color' => $colors[$color_index],
            'icon' => $achievement['icon']
        ];
    }
    
    return $formatted_achievements;
}

// Fetch user data
try {
    // Get user data
    $user_data = getOtherUserProfileData($conn, $user_id);

    if (!$user_data) {
        setMessage("User not found.", "danger");
        redirect('../users/connections.php');
    }

    // Don't allow viewing admin profiles
    if ($user_data['role'] === 'admin') {
        setMessage("This profile is not accessible.", "danger");
        redirect('../users/connections.php');
    }
    
    // Check connection status
    $connection = getOtherUserConnectionStatus($conn, $current_user_id, $user_id);
    
    // Set connection status
    if ($connection) {
        $user_data['connection_status'] = $connection['status'];
        $user_data['connection_direction'] = $connection['direction'];
        $user_data['connection_id'] = $connection['connection_id'];
    } else {
        $user_data['connection_status'] = 'none';
    }
    
    // Fetch user's assigned tasks
    $user_data['assigned_tasks'] = getOtherUserAssignedTasks($conn, $current_user_id, $user_id);
    
    // Get mutual connections
    $user_data['mutual_connections'] = getOtherUserMutualConnections($conn, $current_user_id, $user_id);
    
    // Fetch user achievements
    $user_data['achievements'] = getOtherUserAchievements($conn, $user_id);
    
} catch (Exception $e) {
    error_log("Error fetching profile data: " . $e->getMessage());
    setMessage("Error loading profile data: " . $e->getMessage(), "danger");
    redirect('../users/connections.php');
}

// Generate CSRF token if it doesn't exist
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Page title
$page_title = $user_data['full_name'] . "'s Profile";
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
                <li class="active">
                    <a href="../users/connections.php"><i class="fas fa-users"></i> Connections</a>
                </li>
            </ul>
        </nav>
        <div class="sidebar-footer">
            <a href="../core/settings.php"><i class="fas fa-cog"></i> Settings</a>
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

        <!-- User Profile Content -->
        <div class="profile-container">
            <div class="back-button mb-3">
                <a href="../users/connections.php"><i class="fas fa-arrow-left"></i> Back to Connections</a>
            </div>

            <!-- User Profile Card -->
            <div class="profile-card">
                <div class="profile-header">
                    <div class="profile-avatar">
                        <?php if (!empty($user_data['profile_image'])): ?>
                            <img src="../../<?php echo htmlspecialchars($user_data['profile_image']); ?>" alt="Profile Photo">
                        <?php else: ?>
                            <div class="default-avatar"><?php echo substr($user_data['first_name'], 0, 1); ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="profile-info">
                        <h2><?php echo htmlspecialchars($user_data['full_name']); ?></h2>
                        <p class="profile-role"><?php echo htmlspecialchars($user_data['bio'] ? substr($user_data['bio'], 0, 100) . (strlen($user_data['bio']) > 100 ? '...' : '') : 'TaskMaster User'); ?></p>
                        <div class="profile-stats">
                            <div class="profile-connections">
                                <span><?php echo htmlspecialchars($user_data['connection_count']); ?> connections</span>
                            </div>
                            <div class="mutual-connections">
                                <span><?php echo htmlspecialchars($user_data['mutual_connections']); ?> mutual connections</span>
                            </div>
                        </div>
                    </div>
                    <div class="connection-action">
                        <?php if ($user_data['connection_status'] === 'accepted'): ?>
                            <button class="btn btn-outline-primary" disabled>Connected</button>
                        <?php elseif ($user_data['connection_status'] === 'pending'): ?>
                            <?php if ($user_data['connection_direction'] === 'sent'): ?>
                                <button class="btn btn-outline-secondary" disabled>Request Sent</button>
                                <button class="btn btn-sm btn-outline-danger cancel-request mt-2" data-connection-id="<?php echo $user_data['connection_id']; ?>">Cancel</button>
                            <?php else: ?>
                                <button class="btn btn-primary accept-connection" data-connection-id="<?php echo $user_data['connection_id']; ?>">Accept Request</button>
                                <button class="btn btn-outline-secondary reject-connection mt-2" data-connection-id="<?php echo $user_data['connection_id']; ?>">Decline</button>
                            <?php endif; ?>
                        <?php elseif ($user_data['connection_status'] === 'rejected'): ?>
                            <?php if ($user_data['connection_direction'] === 'sent'): ?>
                                <!-- User sent request that was rejected -->
                                <button class="btn btn-outline-primary retry-connection" data-connection-id="<?php echo $user_data['connection_id']; ?>" data-user-id="<?php echo $user_id; ?>">Connect Again</button>
                            <?php else: ?>
                                <!-- User rejected the request from the other user -->
                                <button class="btn btn-primary connect-btn" data-user-id="<?php echo $user_id; ?>">Connect</button>
                            <?php endif; ?>
                        <?php else: ?>
                            <button class="btn btn-primary connect-btn" data-user-id="<?php echo $user_id; ?>">Connect</button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="profile-content">
                <!-- Assigned Tasks Section -->
                <div class="profile-section assigned-tasks-section">
                    <div class="section-header">
                        <div class="section-icon"><i class="fas fa-clipboard-list"></i></div>
                        <h3>Assigned Tasks</h3>
                    </div>
                    <?php if (empty($user_data['assigned_tasks'])): ?>
                        <div class="section-content">
                            <div class="no-data-message">No mutual tasks assigned currently.</div>
                        </div>
                    <?php else: ?>
                        <div class="section-content scrollable">
                            <div class="task-items-container">
                                <?php foreach ($user_data['assigned_tasks'] as $task): ?>
                                    <div class="task-item">
                                        <h4><a href="view_task.php?id=<?php echo $task['task_id']; ?>"><?php echo htmlspecialchars($task['name']); ?></a></h4>
                                        <div class="task-details">
                                            <span class="task-project"><a href="view_project.php?id=<?php echo $task['project_id']; ?>"><?php echo htmlspecialchars($task['project']); ?></a></span>
                                            <span class="task-due"><?php echo htmlspecialchars($task['due_date']); ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                        </div>
                    <?php endif; ?>
                </div>

                <!-- Workload Section -->
                <div class="profile-section workload-section">
                    <div class="section-header">
                        <div class="section-icon" style="background: linear-gradient(135deg, #7C3AED, #6366F1);"><i class="fas fa-chart-bar"></i></div>
                        <h3>Workload</h3>
                    </div>
                    <div class="section-content">
                        <div class="workload-chart-container">
                            <div class="chart-title">
                                <h4>Daily Workload Overview</h4>
                            </div>
                            <div class="chart-legend">
                                <span class="legend-item"><span class="legend-dot hours-spent"></span> Hours Spent</span>
                                <span class="legend-item"><span class="legend-dot tasks-due"></span> Tasks Due</span>
                            </div>
                            <div class="workload-chart">
                                <canvas id="workloadChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Completed Tasks Section -->
                <div class="profile-section completed-tasks-section">
                    <div class="section-header">
                        <div class="section-icon"><i class="fas fa-check-circle"></i></div>
                        <h3>Completed Tasks Overview</h3>
                    </div>
                    <div class="section-content">
                        <div class="completed-tasks-chart">
                            <canvas id="completedTasksChart"></canvas>
                        </div>
                    </div>
                </div>
                
                <!-- Feedback section removed -->

                <!-- Achievements Section -->
                <div class="profile-section achievements-section">
                    <div class="section-header">
                        <div class="section-icon"><i class="fas fa-trophy"></i></div>
                        <h3>Achievements</h3>
                    </div>
                    <div class="section-content">
                        <?php if (empty($user_data['achievements'])): ?>
                            <div class="no-data-message">No achievements yet.</div>
                            <?php if ($user_data['connection_status'] === 'accepted'): ?>
                                <div class="mt-3 text-center">
                                    <p class="text-muted small">Endorse <?php echo htmlspecialchars($user_data['first_name']); ?> when they complete tasks successfully.</p>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="achievements-row">
                                <?php foreach ($user_data['achievements'] as $achievement): ?>
                                    <div class="achievement-item">
                                        <div class="achievement-badge" style="background-color: <?php echo $achievement['color']; ?>">
                                            <?php if (!empty($achievement['icon'])): ?>
                                                <img src="<?php echo htmlspecialchars($achievement['icon']); ?>" alt="Achievement Icon">
                                            <?php else: ?>
                                                <i class="fas fa-award"></i>
                                            <?php endif; ?>
                                        </div>
                                        <div class="achievement-name"><?php echo htmlspecialchars($achievement['name']); ?></div>
                                        <div class="achievement-date small text-muted"><?php echo date('M d', strtotime($achievement['earned_at'])); ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <?php if ($user_data['connection_status'] === 'accepted'): ?>
                                <div class="mt-3 text-center">
                                    <a href="view_all_achievements.php?user_id=<?php echo $user_id; ?>" class="btn btn-sm btn-link">View all achievements</a>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Review Modal removed -->

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
                <a href="logout.php" class="btn btn-primary">Logout</a>
            </div>
        </div>
    </div>
</div>

<style>
/* Profile Container Styles */
.profile-container {
    padding: 20px;
    max-width: 1200px;
    margin: 0 auto;
}

/* Profile Card Styles */
.profile-card {
    background: linear-gradient(145deg, #ffffff, #f8f9fa);
    border-radius: 20px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.05);
    padding: 40px;
    margin-bottom: 40px;
    transition: transform 0.3s ease, box-shadow 0.3s ease;
    position: relative;
    overflow: hidden;
}

.profile-card:before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 5px;
    background: linear-gradient(90deg, #0d6efd, #0dcaf0);
}

.profile-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 15px 35px rgba(0,0,0,0.1);
}

.profile-header {
    display: flex;
    align-items: flex-start;
    gap: 40px;
    flex-wrap: wrap;
}

.profile-avatar {
    width: 140px;
    height: 140px;
    border-radius: 50%;
    overflow: hidden;
    box-shadow: 0 10px 20px rgba(0,0,0,0.1);
    border: 5px solid white;
    position: relative;
    flex-shrink: 0;
}

.profile-avatar:after {
    content: '';
    position: absolute;
    top: -8px;
    left: -8px;
    right: -8px;
    bottom: -8px;
    border-radius: 50%;
    border: 2px solid rgba(13, 110, 253, 0.2);
    z-index: -1;
}

.profile-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.5s ease;
}

.profile-avatar img:hover {
    transform: scale(1.05);
}

.default-avatar {
    width: 100%;
    height: 100%;
    background: linear-gradient(145deg, #0d6efd, #0b5ed7);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 56px;
    font-weight: bold;
    text-shadow: 1px 1px 3px rgba(0,0,0,0.3);
}

.profile-info {
    flex-grow: 1;
    padding-top: 10px;
}

.profile-info h2 {
    margin: 0 0 10px 0;
    font-size: 32px;
    font-weight: 700;
    color: #212529;
    letter-spacing: -0.5px;
    line-height: 1.2;
}

.profile-role {
    color: #495057;
    margin: 0 0 20px 0;
    font-size: 18px;
    line-height: 1.5;
    max-width: 600px;
    position: relative;
    padding-left: 20px;
}

.profile-role:before {
    content: '';
    position: absolute;
    left: 0;
    top: 50%;
    transform: translateY(-50%);
    width: 10px;
    height: 10px;
    background-color: #0d6efd;
    border-radius: 50%;
}

.profile-stats {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 20px;
    margin-top: 5px;
}

.profile-rating, .profile-connections, .mutual-connections {
    display: flex;
    align-items: center;
    padding: 10px 20px;
    background-color: rgba(13, 110, 253, 0.1);
    border-radius: 30px;
    transition: all 0.3s ease;
    font-weight: 500;
    color: #495057;
}

.profile-rating:hover, .profile-connections:hover, .mutual-connections:hover {
    background-color: rgba(13, 110, 253, 0.2);
    transform: translateY(-3px);
    box-shadow: 0 5px 10px rgba(0,0,0,0.05);
}

.profile-connections:before, .mutual-connections:before {
    font-family: 'Font Awesome 5 Free';
    font-weight: 900;
    margin-right: 10px;
    color: #0d6efd;
    font-size: 16px;
}

.profile-connections:before {
    content: '\f0c0';
}

.mutual-connections:before {
    content: '\f4fc';
}

.rating-number {
    font-size: 18px;
    font-weight: bold;
    margin-right: 8px;
    color: #0d6efd;
}

.rating-star {
    color: #ffc107;
}

.connection-action {
    margin-left: auto;
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 12px;
    align-self: flex-start;
}

.connection-action .btn {
    min-width: 160px;
    padding: 12px 24px;
    font-weight: 600;
    border-radius: 30px;
    transition: all 0.3s ease;
    font-size: 15px;
    letter-spacing: 0.3px;
}

.connection-action .btn-primary {
    background: linear-gradient(145deg, #0d6efd, #0b5ed7);
    border: none;
    box-shadow: 0 8px 15px rgba(13, 110, 253, 0.3);
}

.connection-action .btn-primary:hover {
    transform: translateY(-3px);
    box-shadow: 0 12px 20px rgba(13, 110, 253, 0.4);
}

.connection-action .btn-outline-primary {
    border: 2px solid #0d6efd;
    background: transparent;
    color: #0d6efd;
}

.connection-action .btn-outline-primary:hover {
    background: rgba(13, 110, 253, 0.1);
    transform: translateY(-3px);
}

.connection-action .btn-outline-secondary {
    border: 2px solid #6c757d;
    background: transparent;
    color: #6c757d;
}

.connection-action .btn-outline-secondary:hover {
    background: rgba(108, 117, 125, 0.1);
    transform: translateY(-3px);
}

.connection-action .btn-outline-danger {
    border: 2px solid #dc3545;
    background: transparent;
    color: #dc3545;
}

.connection-action .btn-outline-danger:hover {
    background: rgba(220, 53, 69, 0.1);
    transform: translateY(-3px);
}

/* Profile Content Styles */
.profile-content {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
    gap: 30px;
}

.profile-section {
    background: linear-gradient(145deg, #ffffff, #f8f9fa);
    border-radius: 20px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.05);
    padding: 30px;
    transition: transform 0.3s ease, box-shadow 0.3s ease;
    height: 100%;
    display: flex;
    flex-direction: column;
    position: relative;
    overflow: hidden;
}

.profile-section:before {
    content: '';
    position: absolute;
    top: 0;
    right: 0;
    width: 100px;
    height: 100px;
    background: linear-gradient(135deg, transparent 70%, rgba(13, 110, 253, 0.05) 100%);
    border-radius: 0 20px 0 100px;
}

.profile-section:hover {
    transform: translateY(-5px);
    box-shadow: 0 15px 35px rgba(0,0,0,0.08);
}

.section-header {
    display: flex;
    align-items: center;
    margin-bottom: 25px;
    position: relative;
    padding-bottom: 15px;
    border-bottom: 1px solid rgba(0,0,0,0.05);
}

.section-icon {
    width: 50px;
    height: 50px;
    border-radius: 15px;
    background: linear-gradient(145deg, #0d6efd, #0b5ed7);
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 18px;
    box-shadow: 0 5px 15px rgba(13, 110, 253, 0.3);
    transform: rotate(-5deg);
    transition: transform 0.3s ease;
}

.profile-section:hover .section-icon {
    transform: rotate(0deg);
}

.section-icon i {
    font-size: 20px;
    color: white;
}

.section-header h3 {
    margin: 0;
    font-size: 22px;
    font-weight: 700;
    color: #212529;
    letter-spacing: -0.3px;
}

/* Assigned Tasks Styles */
.section-content {
    overflow: hidden;
    position: relative;
    flex-grow: 1;
    display: flex;
    flex-direction: column;
    min-height: 250px;
}

.section-content.scrollable {
    padding: 5px 0;
}

.task-items-container {
    display: flex;
    overflow-x: auto;
    scroll-behavior: smooth;
    scrollbar-width: thin;
    gap: 15px;
    padding-bottom: 10px;
}

.task-items-container::-webkit-scrollbar {
    height: 6px;
}

.task-items-container::-webkit-scrollbar-thumb {
    background-color: rgba(13, 110, 253, 0.3);
    border-radius: 3px;
}

.task-items-container::-webkit-scrollbar-track {
    background-color: rgba(0,0,0,0.05);
    border-radius: 3px;
}

.task-item {
    flex: 0 0 calc(50% - 10px);
    min-width: 250px;
    border: none;
    border-radius: 15px;
    padding: 20px;
    margin-bottom: 5px;
    background: linear-gradient(145deg, #ffffff, #f8f9fa);
    transition: all 0.3s ease;
    box-shadow: 0 5px 15px rgba(0,0,0,0.05);
    position: relative;
    overflow: hidden;
}

.task-item:before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 4px;
    height: 100%;
    background: linear-gradient(to bottom, #0d6efd, #0dcaf0);
    opacity: 0.7;
}

.task-item:hover {
    transform: translateY(-5px) scale(1.02);
    box-shadow: 0 12px 25px rgba(0,0,0,0.1);
}

.task-item h4 {
    margin: 0 0 12px 0;
    font-size: 17px;
    font-weight: 700;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    padding-left: 10px;
}

.task-item h4 a {
    color: #212529;
    text-decoration: none;
    transition: all 0.3s;
    position: relative;
    display: inline-block;
}

.task-item h4 a:before {
    content: '';
    position: absolute;
    bottom: -2px;
    left: 0;
    width: 0;
    height: 2px;
    background: linear-gradient(to right, #0d6efd, #0dcaf0);
    transition: width 0.3s ease;
}

.task-item h4 a:hover {
    color: #0d6efd;
}

.task-item h4 a:hover:before {
    width: 100%;
}

.task-details {
    display: flex;
    justify-content: space-between;
    font-size: 14px;
    color: #6c757d;
    align-items: center;
    padding-left: 10px;
    margin-top: 5px;
}

.task-project a {
    color: #495057;
    text-decoration: none;
    transition: all 0.3s;
    font-weight: 500;
    display: inline-flex;
    align-items: center;
    padding: 5px 10px;
    border-radius: 20px;
    background-color: rgba(13, 110, 253, 0.05);
}

.task-project a:before {
    content: '\f07b';
    font-family: 'Font Awesome 5 Free';
    font-weight: 900;
    margin-right: 6px;
    font-size: 13px;
    color: #0d6efd;
    opacity: 1;
}

.task-project a:hover {
    color: #0d6efd;
    background-color: rgba(13, 110, 253, 0.1);
    transform: translateY(-2px);
}

.task-due {
    white-space: nowrap;
    font-weight: 500;
    display: inline-flex;
    align-items: center;
    padding: 5px 10px;
    border-radius: 20px;
    background-color: rgba(108, 117, 125, 0.05);
}

.task-due:before {
    content: '\f073';
    font-family: 'Font Awesome 5 Free';
    font-weight: 900;
    margin-right: 6px;
    font-size: 13px;
    color: #6c757d;
    opacity: 1;
}

/* Workload Chart Styles */
.workload-chart-container {
    background-color: #ffffff;
    border-radius: 15px;
    padding: 20px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.05);
    margin-top: 10px;
}

.chart-title {
    margin-bottom: 15px;
}

.chart-title h4 {
    font-size: 18px;
    font-weight: 600;
    color: #333;
    margin: 0;
}

.workload-chart {
    height: 300px;
    flex-grow: 1;
    padding: 10px;
    background-color: #ffffff;
    border-radius: 10px;
}

.chart-legend {
    display: flex;
    justify-content: center;
    margin-bottom: 20px;
}

.legend-item {
    display: flex;
    align-items: center;
    margin: 0 15px;
    font-size: 14px;
    color: #495057;
    font-weight: 500;
}

.legend-dot {
    width: 12px;
    height: 12px;
    border-radius: 50%;
    margin-right: 8px;
    display: inline-block;
}

.legend-dot.hours-spent {
    background-color: #6C9BFF;
}

.legend-dot.tasks-due {
    background-color: #FF8A8A;
}

.workload-section .section-icon {
    background: linear-gradient(135deg, #7C3AED, #6366F1);
    transform: none;
    border-radius: 12px;
}

/* Completed Tasks Styles */
.completed-tasks-section {
    background-color: white;
    border-radius: 15px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.08);
    padding: 25px;
}

.completed-tasks-chart {
    height: 250px;
    flex-grow: 1;
}

/* Back Button Styles */
.back-button {
    margin-bottom: 20px;
}

.back-button a {
    color: #6c757d;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    padding: 8px 15px;
    background-color: rgba(108, 117, 125, 0.1);
    border-radius: 8px;
    transition: all 0.3s;
}

.back-button a i {
    margin-right: 8px;
}

.back-button a:hover {
    color: #495057;
    background-color: rgba(108, 117, 125, 0.2);
    transform: translateX(-3px);
}

/* Achievements Styles */
.achievements-grid, .achievements-row {
    display: flex;
    flex-direction: row;
    gap: 15px;
    padding: 5px 0;
}

/* Scrollable achievements grid for the full achievements view */
.achievements-grid {
    overflow-x: auto;
    scroll-behavior: smooth;
    scrollbar-width: thin;
}

.achievements-grid::-webkit-scrollbar {
    height: 6px;
}

.achievements-grid::-webkit-scrollbar-thumb {
    background-color: rgba(13, 110, 253, 0.3);
    border-radius: 3px;
}

.achievements-grid::-webkit-scrollbar-track {
    background-color: rgba(0,0,0,0.05);
    border-radius: 3px;
}

/* For main profile page - horizontal row */
.achievements-row {
    justify-content: space-around;
    align-items: center;
    flex-grow: 1;
}

.achievement-item {
    text-align: center;
    flex: 1 1 0;
    padding: 15px 10px;
    border-radius: 12px;
    transition: transform 0.3s, background-color 0.3s;
}

.achievement-item:hover {
    transform: translateY(-5px);
    background-color: rgba(13, 110, 253, 0.05);
}

.achievement-badge {
    width: 70px;
    height: 70px;
    border-radius: 50%;
    margin: 0 auto 15px;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
    transition: transform 0.3s;
}

.achievement-badge:hover {
    transform: rotate(10deg);
}

.achievement-badge img {
    max-width: 70%;
    max-height: 70%;
}

.achievement-badge i {
    font-size: 28px;
    color: white;
}

.achievement-name {
    font-size: 15px;
    font-weight: 600;
    color: #343a40;
    margin-bottom: 5px;
}

.achievement-date {
    font-size: 12px;
    color: #6c757d;
}

.no-data-message {
    text-align: center;
    color: #6c757d;
    padding: 25px;
    background-color: rgba(108, 117, 125, 0.05);
    border-radius: 10px;
    margin: 10px 0;
    font-size: 15px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    flex-grow: 1;
    min-height: 180px;
}

.no-data-message:before {
    content: '\f06a';
    font-family: 'Font Awesome 5 Free';
    font-weight: 900;
    font-size: 24px;
    margin-bottom: 10px;
    opacity: 0.5;
}

/* Responsive adjustments */
@media (max-width: 992px) {
    .profile-content {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 768px) {
    .profile-header {
        flex-direction: column;
        text-align: center;
    }
    
    .profile-avatar {
        margin: 0 auto 20px;
    }
    
    .profile-stats {
        justify-content: center;
    }
    
    .connection-action {
        margin: 20px auto 0;
        align-items: center;
    }
    
    .task-item {
        flex: 0 0 calc(100% - 10px);
    }
}

@media (max-width: 576px) {
    .profile-stats {
        flex-direction: column;
        gap: 10px;
    }
    
    .achievement-item {
        padding: 10px 5px;
    }
    
    .achievement-badge {
        width: 50px;
        height: 50px;
    }
}
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Workload Chart
    const workloadCtx = document.getElementById('workloadChart');
    if (workloadCtx) {
        const workloadChart = new Chart(workloadCtx.getContext('2d'), {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($user_data['workload']['labels']); ?>,
                datasets: [
                    {
                        label: 'Hours Spent',
                        data: <?php echo json_encode($user_data['workload']['hours']); ?>,
                        backgroundColor: '#6C9BFF',
                        borderColor: '#6C9BFF',
                        borderWidth: 0,
                        barPercentage: 0.5,
                        categoryPercentage: 0.7,
                        borderRadius: 4
                    },
                    {
                        label: 'Tasks Due',
                        data: <?php echo json_encode($user_data['workload']['tasks']); ?>,
                        backgroundColor: '#FF8A8A',
                        borderColor: '#FF8A8A',
                        borderWidth: 0,
                        barPercentage: 0.5,
                        categoryPercentage: 0.7,
                        borderRadius: 4
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 20,
                        grid: {
                            color: '#f0f0f0',
                            drawBorder: false,
                            drawTicks: false
                        },
                        ticks: {
                            stepSize: 5,
                            padding: 10,
                            font: {
                                size: 12
                            },
                            color: '#9CA3AF'
                        },
                        border: {
                            display: false
                        }
                    },
                    x: {
                        grid: {
                            display: false,
                            drawBorder: false,
                            drawTicks: false
                        },
                        ticks: {
                            padding: 10,
                            font: {
                                size: 12
                            },
                            color: '#9CA3AF'
                        },
                        border: {
                            display: false
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        backgroundColor: 'rgba(255, 255, 255, 0.9)',
                        titleColor: '#333',
                        bodyColor: '#333',
                        borderColor: '#ddd',
                        borderWidth: 1,
                        padding: 10,
                        displayColors: true,
                        callbacks: {
                            title: function(tooltipItems) {
                                return tooltipItems[0].label;
                            },
                            label: function(context) {
                                let label = context.dataset.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                if (context.parsed.y !== null) {
                                    label += context.parsed.y;
                                    if (context.dataset.label === 'Hours Spent') {
                                        label += ' hours';
                                    } else {
                                        label += ' tasks';
                                    }
                                }
                                return label;
                            }
                        }
                    }
                }
            }
        });
    }
    
    // Completed Tasks Chart
    const completedTasksCtx = document.getElementById('completedTasksChart');
    if (completedTasksCtx) {
        const labels = [];
        const data = [];
        
        <?php foreach ($user_data['completed_tasks'] as $month => $item): ?>
            labels.push('<?php echo $item['label']; ?>');
            data.push(<?php echo $item['count']; ?>);
        <?php endforeach; ?>
        
        const completedTasksChart = new Chart(completedTasksCtx.getContext('2d'), {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Completed Tasks',
                    data: data,
                    backgroundColor: 'rgba(40, 167, 69, 0.2)',
                    borderColor: '#28a745',
                    borderWidth: 2,
                    tension: 0.3,
                    fill: true,
                    pointBackgroundColor: '#28a745',
                    pointRadius: 4,
                    pointHoverRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: '#e5e5e5',
                            borderDash: [5, 5],
                        },
                        ticks: {
                            precision: 0
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        }
                    }
                },
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                if (context.parsed.y !== null) {
                                    label += context.parsed.y + ' tasks';
                                }
                                return label;
                            }
                        }
                    }
                }
            }
        });
    }
    
    // Enable touch scrolling for horizontal scroll containers
    const scrollContainers = document.querySelectorAll('.task-items-container, .achievements-row');
    scrollContainers.forEach(container => {
        let isDown = false;
        let startX;
        let scrollLeft;

        container.addEventListener('mousedown', (e) => {
            isDown = true;
            container.style.cursor = 'grabbing';
            startX = e.pageX - container.offsetLeft;
            scrollLeft = container.scrollLeft;
        });

        container.addEventListener('mouseleave', () => {
            isDown = false;
            container.style.cursor = 'grab';
        });

        container.addEventListener('mouseup', () => {
            isDown = false;
            container.style.cursor = 'grab';
        });

        container.addEventListener('mousemove', (e) => {
            if (!isDown) return;
            e.preventDefault();
            const x = e.pageX - container.offsetLeft;
            const walk = (x - startX) * 2; // Scroll speed
            container.scrollLeft = scrollLeft - walk;
        });
    });

    // Connect with user
    const connectBtn = document.querySelector('.connect-btn');
    if (connectBtn) {
        connectBtn.addEventListener('click', function() {
            const userId = this.dataset.userId;
            
            fetch('handle_connection.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=send_request&user_id=${userId}&csrf_token=<?php echo $_SESSION['csrf_token']; ?>`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Update button to show pending
                    this.textContent = 'Request Sent';
                    this.disabled = true;
                    this.className = 'btn btn-outline-secondary connect-btn';
                    
                    // Show success notification
                    showNotification('Connection request sent successfully!', 'success');
                } else {
                    showNotification('Failed to send connection request: ' + data.message, 'danger');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('An error occurred. Please try again.', 'danger');
            });
        });
    }

    // Accept connection request
    const acceptBtn = document.querySelector('.accept-connection');
    if (acceptBtn) {
        acceptBtn.addEventListener('click', function() {
            const connectionId = this.dataset.connectionId;
            
            fetch('handle_connection.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=accept&connection_id=${connectionId}&csrf_token=<?php echo $_SESSION['csrf_token']; ?>`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Refresh the page to show updated connection status
                    showNotification('Connection accepted!', 'success');
                    setTimeout(() => window.location.reload(), 1000);
                } else {
                    showNotification('Failed to accept connection: ' + data.message, 'danger');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('An error occurred. Please try again.', 'danger');
            });
        });
    }

    // Reject connection request
    const rejectBtn = document.querySelector('.reject-connection');
    if (rejectBtn) {
        rejectBtn.addEventListener('click', function() {
            const connectionId = this.dataset.connectionId;
            
            fetch('handle_connection.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=reject&connection_id=${connectionId}&csrf_token=<?php echo $_SESSION['csrf_token']; ?>`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Refresh the page to show updated connection status
                    showNotification('Connection request rejected', 'info');
                    setTimeout(() => window.location.reload(), 1000);
                } else {
                    showNotification('Failed to reject connection: ' + data.message, 'danger');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('An error occurred. Please try again.', 'danger');
            });
        });
    }

    // Cancel connection request
    const cancelBtn = document.querySelector('.cancel-request');
    if (cancelBtn) {
        cancelBtn.addEventListener('click', function() {
            const connectionId = this.dataset.connectionId;
            
            fetch('handle_connection.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=cancel&connection_id=${connectionId}&csrf_token=<?php echo $_SESSION['csrf_token']; ?>`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Refresh the page to show updated connection status
                    showNotification('Connection request cancelled', 'info');
                    setTimeout(() => window.location.reload(), 1000);
                } else {
                    showNotification('Failed to cancel request: ' + data.message, 'danger');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('An error occurred. Please try again.', 'danger');
            });
        });
    }

    // Assign task button functionality
    document.querySelectorAll('a[href^="assign_task.php"]').forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            
            // Check if connected before allowing task assignment
            if ('<?php echo $user_data['connection_status']; ?>' !== 'accepted') {
                showNotification('You need to be connected to assign tasks to this user.', 'warning');
                return;
            }
            
            // Redirect to assign task page
            window.location.href = this.getAttribute('href');
        });
    });

    // Logout confirmation
    document.getElementById('logout-btn').addEventListener('click', function(e) {
        e.preventDefault();
        var logoutModal = new bootstrap.Modal(document.getElementById('logoutModal'));
        logoutModal.show();
    });

    // Add event listener for the Retry Connection button
    document.querySelector('.retry-connection')?.addEventListener('click', function() {
        const userId = this.dataset.userId;
        const connectionId = this.dataset.connectionId;
        
        fetch('handle_connection.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `action=retry_request&user_id=${userId}&connection_id=${connectionId}&csrf_token=<?php echo $_SESSION['csrf_token']; ?>`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Refresh the page to show updated connection status
                showNotification('Connection request sent!', 'success');
                setTimeout(() => window.location.reload(), 1000);
            } else {
                showNotification('Failed to send connection request: ' + data.message, 'danger');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showNotification('An error occurred. Please try again.', 'danger');
        });
    });
    
    // Helper function to show notifications
    function showNotification(message, type) {
        // Check if notification container exists
        let container = document.querySelector('.notification-container');
        
        // If not, create one
        if (!container) {
            container = document.createElement('div');
            container.className = 'notification-container';
            container.style.position = 'fixed';
            container.style.top = '20px';
            container.style.right = '20px';
            container.style.zIndex = '9999';
            document.body.appendChild(container);
        }
        
        // Create notification
        const notification = document.createElement('div');
        notification.className = `alert alert-${type} notification`;
        notification.innerHTML = message;
        notification.style.minWidth = '250px';
        notification.style.marginBottom = '10px';
        notification.style.boxShadow = '0 4px 8px rgba(0,0,0,0.1)';
        notification.style.animation = 'fadeInRight 0.5s forwards';
        
        // Add to container
        container.appendChild(notification);
        
        // Auto-remove after 5 seconds
        setTimeout(() => {
            notification.style.animation = 'fadeOutRight 0.5s forwards';
            setTimeout(() => {
                notification.remove();
            }, 500);
        }, 5000);
    }
    
    // Add animations to CSS
    const style = document.createElement('style');
    style.textContent = `
        @keyframes fadeInRight {
            from {
                opacity: 0;
                transform: translateX(50px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
        
        @keyframes fadeOutRight {
            from {
                opacity: 1;
                transform: translateX(0);
            }
            to {
                opacity: 0;
                transform: translateX(50px);
            }
        }
    `;
    document.head.appendChild(style);
});
</script>

<?php include '../../includes/footer.php'; ?>
?> 