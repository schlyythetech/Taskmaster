<?php
require_once '../../includes/functions.php';
require_once '../../config/database.php';

// Check if user is logged in
if (!isLoggedIn()) {
    setMessage("You must be logged in to view your profile.", "danger");
    redirect('../auth/login.php');
}

// Get user data
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];
$first_name = explode(' ', $user_name)[0];

// Get the current view (default is main profile)
$view = isset($_GET['view']) ? $_GET['view'] : 'main';

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        setMessage("Invalid security token. Please try again.", "danger");
        redirect('../users/profile.php');
    }

    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $role = trim($_POST['role']);
    $bio = trim($_POST['bio']);

    // Basic validation
    if (empty($first_name) || empty($last_name)) {
        setMessage("First name and last name are required.", "danger");
    } else {
        try {
            // Update user data in database
            $stmt = $conn->prepare("
                UPDATE users SET 
                first_name = ?, 
                last_name = ?,
                bio = ?
                WHERE user_id = ?
            ");
            $stmt->execute([$first_name, $last_name, $bio, $user_id]);

            // Handle profile image upload if a file was submitted
            if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
                $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
                $file_type = $_FILES['profile_image']['type'];
                $file_size = $_FILES['profile_image']['size'];
                $max_size = 5 * 1024 * 1024; // 5MB

                if (!in_array($file_type, $allowed_types)) {
                    setMessage("Only JPG, PNG, and GIF images are allowed.", "danger");
                } else if ($file_size > $max_size) {
                    setMessage("Image size should be less than 5MB.", "danger");
                } else {
                    // Create upload directory if it doesn't exist
                    $upload_dir = '../../assets/images/profile_images';
                    if (!file_exists($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }

                    // Generate unique filename
                    $file_extension = pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION);
                    $new_filename = 'profile_' . $user_id . '_' . time() . '.' . $file_extension;
                    $target_file = $upload_dir . '/' . $new_filename;
                    $db_file_path = 'assets/images/profile_images/' . $new_filename;

                    // Move uploaded file
                    if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $target_file)) {
                        // Update profile image in database
                        $stmt = $conn->prepare("UPDATE users SET profile_image = ? WHERE user_id = ?");
                        $stmt->execute([$db_file_path, $user_id]);
                        
                        // Update session data
                        $_SESSION['profile_image'] = $db_file_path;
                    } else {
                        setMessage("Failed to upload image. Please try again.", "danger");
                    }
                }
            }

            // Update session data
            $_SESSION['user_name'] = $first_name . ' ' . $last_name;
            setMessage("Profile updated successfully!", "success");
        } catch (PDOException $e) {
            error_log("Profile update error: " . $e->getMessage());
            setMessage("Error updating profile: " . $e->getMessage(), "danger");
        }
    }
    
    redirect('../users/profile.php');
}

// Fetch user data from database
try {
    // Get user data including connection count
    $stmt = $conn->prepare("
        SELECT 
            u.first_name, u.last_name, u.email, u.bio, u.profile_image,
            (SELECT COUNT(*) FROM connections WHERE (user_id = ? OR connected_user_id = ?) AND status = 'accepted') as connection_count
        FROM users u
        WHERE u.user_id = ?
    ");
    $stmt->execute([$user_id, $user_id, $user_id]);
    $user_data = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user_data) {
        throw new Exception("User not found");
    }

    // Format user data
    $user_data['full_name'] = $user_data['first_name'] . ' ' . $user_data['last_name'];
    
    // Fetch user's assigned tasks
    $stmt = $conn->prepare("
        SELECT t.task_id, t.title as name, p.name as project, p.project_id, t.due_date
        FROM tasks t
        JOIN projects p ON t.project_id = p.project_id
        WHERE t.assigned_to = ?
        AND t.status != 'completed'
        ORDER BY t.due_date ASC
        LIMIT 5
    ");
    $stmt->execute([$user_id]);
    $user_data['assigned_tasks'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format due dates
    foreach ($user_data['assigned_tasks'] as &$task) {
        if (!empty($task['due_date'])) {
            $due_date = new DateTime($task['due_date']);
            $task['due_date'] = $due_date->format('M d, Y');
        } else {
            $task['due_date'] = 'No due date';
        }
    }
    
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
        'tasks' => [0, 0, 0, 0, 0, 0, 0],
        'hours' => [0, 0, 0, 0, 0, 0, 0]
    ];
    
    // Fill in the task data (MySQL DAYOFWEEK: 1=Sunday, 2=Monday, etc.)
    foreach ($workload_data as $day) {
        $index = $day['day_of_week'] - 1; // Convert to 0-based index
        $user_data['workload']['tasks'][$index] = (int)$day['task_count'];
    }
    
    // Get hours spent per day
    require_once '../../includes/session_functions.php';
    $user_data['workload']['hours'] = getUserHoursSpentByDay($conn, $user_id);
    
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
    
    // Reputation feature has been removed
    
    // Fetch user achievements
    $stmt = $conn->prepare("
        SELECT a.achievement_id, a.name, a.icon, a.description, ua.earned_at,
               CASE WHEN ua.user_id IS NOT NULL THEN 1 ELSE 0 END as earned
        FROM achievements a
        LEFT JOIN user_achievements ua ON a.achievement_id = ua.achievement_id AND ua.user_id = ?
        ORDER BY earned DESC, a.name ASC
    ");
    $stmt->execute([$user_id]);
    $achievements = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format achievements with colors
    $colors = ['#8ED1B7', '#E6A6B9', '#B6C3E6', '#D8CEE4', '#F0D9A7'];
    $user_data['achievements'] = [];
    
    foreach ($achievements as $index => $achievement) {
        $color_index = $index % count($colors);
        
        // Create achievement array with safe handling of earned_at
        $achievement_data = [
            'id' => $achievement['achievement_id'],
            'name' => $achievement['name'],
            'description' => $achievement['description'],
            'earned' => (bool)$achievement['earned'],
            'color' => $colors[$color_index],
            'icon' => $achievement['icon']
        ];
        
        // Only add earned_at if it exists and is valid
        if (isset($achievement['earned_at']) && !empty($achievement['earned_at'])) {
            $achievement_data['earned_at'] = $achievement['earned_at'];
        }
        
        $user_data['achievements'][] = $achievement_data;
    }
    
    // If no achievements, add placeholders
    if (empty($user_data['achievements'])) {
        $user_data['achievements'] = [
            [
                'id' => 1,
                'name' => 'Fast Learner',
                'description' => 'Completed 5 tasks before their due dates',
                'earned' => true,
                'earned_at' => date('Y-m-d H:i:s', strtotime('-30 days')),
                'color' => '#8ED1B7',
                'icon' => null
            ],
            [
                'id' => 2,
                'name' => 'Team Player',
                'description' => 'Collaborated on 10 projects',
                'earned' => true,
                'earned_at' => date('Y-m-d H:i:s', strtotime('-14 days')),
                'color' => '#E6A6B9',
                'icon' => null
            ],
            [
                'id' => 3,
                'name' => 'Problem Solver',
                'description' => 'Resolved 15 issues',
                'earned' => false,
                'color' => '#B6C3E6',
                'icon' => null
            ]
        ];
    }
    
    // Set default role if not provided
    if (!isset($user_data['role'])) {
        $user_data['role'] = 'Team Member';
    }
    
} catch (Exception $e) {
    error_log("Error fetching profile data: " . $e->getMessage());
    setMessage("Error loading profile data: " . $e->getMessage(), "danger");
    $user_data = [
        'full_name' => $user_name,
        'role' => 'Team Member',
        'connection_count' => 0,
        'assigned_tasks' => [],
        'achievements' => []
    ];
}

// Generate CSRF token if it doesn't exist
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Page title
$page_title = "Profile";
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
                <li class="active">
                    <a href="../users/profile.php"><i class="fas fa-user"></i> Profile</a>
                </li>
                <li>
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

        <!-- Profile Content -->
        <div class="profile-container">
            <!-- User Profile Card -->
            <div class="profile-card">
                <div class="profile-header">
                    <div class="profile-avatar">
                        <?php if (!empty($user_data['profile_image']) && file_exists('../../' . $user_data['profile_image'])): ?>
                            <img src="../../<?php echo htmlspecialchars($user_data['profile_image']); ?>" alt="Profile Photo">
                        <?php else: ?>
                            <div class="default-avatar"><?php echo substr($user_data['first_name'], 0, 1); ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="profile-info">
                        <h2><?php echo htmlspecialchars($user_data['full_name']); ?></h2>
                        <p class="profile-role"><?php echo htmlspecialchars($user_data['role']); ?></p>
                        <div class="profile-stats">
                            <div class="profile-connections">
                                <i class="fas fa-users"></i>
                                <span><?php echo htmlspecialchars($user_data['connection_count'] ?? 0); ?> connections</span>
                            </div>
                        </div>
                    </div>
                    <div class="profile-edit">
                        <button class="edit-button" id="edit-profile-btn"><i class="fas fa-pencil-alt"></i> Edit</button>
                    </div>
                </div>
                <?php if (!empty($user_data['bio'])): ?>
                <div class="profile-bio">
                    <p><?php echo htmlspecialchars($user_data['bio']); ?></p>
                </div>
                <?php endif; ?>
            </div>

            <?php if ($view === 'main'): ?>
            <!-- Main Profile View -->
            <div class="profile-content">
                <!-- Assigned Tasks Section -->
                <div class="profile-section assigned-tasks-section">
                    <div class="section-header">
                        <div class="section-icon"><i class="fas fa-clipboard-list"></i></div>
                        <h3>Assigned Tasks</h3>
                        <a href="../tasks/tasks.php" class="section-view-all">View All</a>
                    </div>
                    <div class="section-content scrollable">
                        <?php if (empty($user_data['assigned_tasks'])): ?>
                            <div class="no-data-message">
                                <i class="fas fa-tasks no-data-icon"></i>
                                <p>No tasks assigned currently.</p>
                            </div>
                        <?php else: ?>
                        <div class="task-items-container">
                            <?php foreach ($user_data['assigned_tasks'] as $task): ?>
                                <div class="task-item">
                                    <h4><a href="../tasks/view_task.php?id=<?php echo $task['task_id']; ?>"><?php echo htmlspecialchars($task['name']); ?></a></h4>
                                    <div class="task-details">
                                        <span class="task-project"><a href="../projects/view_project.php?id=<?php echo $task['project_id']; ?>"><i class="fas fa-project-diagram"></i> <?php echo htmlspecialchars($task['project']); ?></a></span>
                                        <span class="task-due"><i class="far fa-calendar-alt"></i> <?php echo htmlspecialchars($task['due_date']); ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Workload Section -->
                <div class="profile-section workload-section">
                    <div class="section-header">
                        <div class="section-icon" style="background: linear-gradient(135deg, #7C3AED, #6366F1);"><i class="fas fa-chart-bar"></i></div>
                        <h3>Workload</h3>
                        <a href="#" class="section-view-all">View Charts <i class="fas fa-chevron-right"></i></a>
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
                        <a href="../tasks/tasks.php?status=completed" class="section-view-all">View All</a>
                    </div>
                    <div class="section-content">
                        <div class="completed-tasks-chart">
                            <canvas id="completedTasksChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Achievements Section -->
                <div class="profile-section achievements-section">
                    <div class="section-header">
                        <div class="section-icon"><i class="fas fa-trophy"></i></div>
                        <h3>Achievements</h3>
                        <a href="profile.php?view=achievements" class="section-view-all">View All</a>
                    </div>
                    <div class="section-content">
                        <div class="achievements-row">
                            <?php 
                            $displayed_achievements = array_slice($user_data['achievements'], 0, 3);
                            foreach ($displayed_achievements as $achievement): 
                            ?>
                                <div class="achievement-item <?php echo $achievement['earned'] ? 'earned' : 'locked'; ?>">
                                    <div class="achievement-badge" style="background-color: <?php echo $achievement['color']; ?>">
                                        <?php if (!empty($achievement['icon'])): ?>
                                            <img src="<?php echo htmlspecialchars($achievement['icon']); ?>" alt="Achievement Icon">
                                        <?php else: ?>
                                            <i class="fas fa-award"></i>
                                        <?php endif; ?>
                                        <?php if (!$achievement['earned']): ?>
                                            <div class="achievement-lock"><i class="fas fa-lock"></i></div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="achievement-name"><?php echo htmlspecialchars($achievement['name']); ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <?php elseif ($view === 'achievements'): ?>
            <!-- Achievements View -->
            <div class="profile-content achievements-view">
                <div class="back-button">
                    <a href="../users/profile.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left"></i> Back to Profile</a>
                </div>
                
                <div class="achievements-header">
                    <h2><i class="fas fa-trophy text-warning"></i> <?php echo htmlspecialchars($first_name); ?>'s Achievements</h2>
                    <div class="achievements-summary">
                        <div class="achievement-stats">
                            <?php 
                            // Count earned achievements
                            $earned_count = 0;
                            foreach ($user_data['achievements'] as $achievement) {
                                if ($achievement['earned']) {
                                    $earned_count++;
                                }
                            }
                            $total_count = count($user_data['achievements']);
                            $completion_percentage = $total_count > 0 ? round(($earned_count / $total_count) * 100) : 0;
                            ?>
                            <div class="stat-item">
                                <div class="stat-value"><?php echo $earned_count; ?></div>
                                <div class="stat-label">Earned</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-value"><?php echo $total_count - $earned_count; ?></div>
                                <div class="stat-label">Remaining</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-value"><?php echo $completion_percentage; ?>%</div>
                                <div class="stat-label">Completion</div>
                            </div>
                        </div>
                        <div class="completion-progress">
                            <div class="progress">
                                <div class="progress-bar bg-success" role="progressbar" 
                                     style="width: <?php echo $completion_percentage; ?>%" 
                                     aria-valuenow="<?php echo $completion_percentage; ?>" 
                                     aria-valuemin="0" 
                                     aria-valuemax="100"></div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="achievements-container">
                    <?php if (empty($user_data['achievements'])): ?>
                    <div class="no-achievements-message">
                        <img src="../../assets/images/no_achievements.svg" alt="No Achievements" class="no-data-img">
                        <p>No achievements yet.</p>
                        <p class="text-muted">Complete tasks and collaborate with others to earn achievements.</p>
                    </div>
                    <?php else: ?>
                    <div class="achievements-grid">
                        <?php foreach ($user_data['achievements'] as $achievement): ?>
                            <div class="achievement-card <?php echo $achievement['earned'] ? 'earned' : 'unearned'; ?>">
                                <div class="achievement-status">
                                    <?php if ($achievement['earned']): ?>
                                        <span class="status-badge earned"><i class="fas fa-check"></i></span>
                                    <?php else: ?>
                                        <span class="status-badge locked"><i class="fas fa-lock"></i></span>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="achievement-icon" style="background-color: <?php echo $achievement['color']; ?>">
                                    <?php if (!empty($achievement['icon'])): ?>
                                        <img src="<?php echo htmlspecialchars($achievement['icon']); ?>" alt="Achievement Icon">
                                    <?php else: ?>
                                        <i class="fas fa-award"></i>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="achievement-details">
                                    <h3><?php echo htmlspecialchars($achievement['name']); ?></h3>
                                    <p class="achievement-description"><?php echo htmlspecialchars($achievement['description']); ?></p>
                                    
                                    <?php if ($achievement['earned']): ?>
                                        <div class="achievement-earned">
                                            <span class="badge bg-success">Completed</span>
                                            <span class="earned-date">
                                                <?php if (isset($achievement['earned_at']) && !empty($achievement['earned_at'])): ?>
                                                    Earned on <?php echo date('M d, Y', strtotime($achievement['earned_at'])); ?>
                                                <?php else: ?>
                                                    Date not available
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                    <?php else: ?>
                                        <div class="achievement-progress">
                                            <div class="progress">
                                                <div class="progress-bar bg-info" role="progressbar" style="width: 75%" aria-valuenow="75" aria-valuemin="0" aria-valuemax="100"></div>
                                            </div>
                                            <div class="progress-text">75% Complete</div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Edit Profile Modal -->
        <div class="modal fade" id="editProfileModal" tabindex="-1" aria-labelledby="editProfileModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="editProfileModalLabel">Edit Profile</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <form id="edit-profile-form" action="profile.php" method="post" enctype="multipart/form-data">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <input type="hidden" name="update_profile" value="1">
                            
                            <div class="row mb-3">
                                <div class="col-md-3">
                                    <div class="profile-image-upload">
                                        <div class="current-image">
                                                                        <?php if (!empty($user_data['profile_image']) && file_exists('../../' . $user_data['profile_image'])): ?>
                                <img src="../../<?php echo htmlspecialchars($user_data['profile_image']); ?>" alt="Profile Photo" id="profile-preview">
                            <?php else: ?>
                                                <div class="default-avatar" id="profile-preview-default"><?php echo substr($user_data['first_name'], 0, 1); ?></div>
                                            <?php endif; ?>
                                        </div>
                                        <label for="profile_image" class="btn btn-outline-primary mt-2">Change Photo</label>
                                        <input type="file" id="profile_image" name="profile_image" class="d-none" accept="image/*">
                                    </div>
                                </div>
                                <div class="col-md-9">
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <label for="first_name" class="form-label">First Name</label>
                                            <input type="text" class="form-control" id="first_name" name="first_name" value="<?php echo htmlspecialchars($user_data['first_name']); ?>" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label for="last_name" class="form-label">Last Name</label>
                                            <input type="text" class="form-control" id="last_name" name="last_name" value="<?php echo htmlspecialchars($user_data['last_name']); ?>" required>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="email" class="form-label">Email</label>
                                        <input type="email" class="form-control" id="email" value="<?php echo htmlspecialchars($user_data['email']); ?>" disabled>
                                        <div class="form-text">Email cannot be changed. Contact support if needed.</div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="role" class="form-label">Role</label>
                                        <input type="text" class="form-control" id="role" name="role" value="<?php echo htmlspecialchars($user_data['role']); ?>">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="bio" class="form-label">Bio</label>
                                <textarea class="form-control" id="bio" name="bio" rows="4"><?php echo htmlspecialchars($user_data['bio'] ?? ''); ?></textarea>
                            </div>
                            
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-primary">Save Changes</button>
                            </div>
                        </form>
                    </div>
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
                <a href="../auth/logout.php" class="btn btn-primary">Logout</a>
            </div>
        </div>
    </div>
</div>

<style>
/* Profile Container Styles */
.profile-container {
    padding: 20px;
    max-width: 1400px;
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
    background: linear-gradient(90deg, #6a11cb, #2575fc);
}

.profile-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 15px 35px rgba(0,0,0,0.1);
}

.profile-header {
    display: flex;
    align-items: flex-start;
    gap: 40px;
}

.profile-avatar {
    width: 140px;
    height: 140px;
    border-radius: 50%;
    overflow: hidden;
    background-color: #f0f0f0;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 5px solid #ffffff;
    box-shadow: 0 10px 20px rgba(0,0,0,0.1);
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
    border: 2px solid rgba(37, 117, 252, 0.2);
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
    background: linear-gradient(135deg, #6a11cb 0%, #2575fc 100%);
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
    font-weight: 500;
    display: flex;
    align-items: center;
}

.profile-role:before {
    content: '';
    display: inline-block;
    width: 10px;
    height: 10px;
    background-color: #2575fc;
    border-radius: 50%;
    margin-right: 10px;
}

.profile-stats {
    display: flex;
    align-items: center;
    margin-top: 5px;
}

.profile-connections {
    display: flex;
    align-items: center;
    color: #495057;
    font-size: 16px;
    font-weight: 500;
    padding: 8px 16px;
    background-color: rgba(37, 117, 252, 0.1);
    border-radius: 30px;
    transition: all 0.3s ease;
}

.profile-connections:hover {
    background-color: rgba(37, 117, 252, 0.2);
    transform: translateY(-2px);
}

.profile-connections i {
    margin-right: 10px;
    color: #2575fc;
    font-size: 18px;
}

.profile-edit {
    margin-left: auto;
    align-self: flex-start;
}

.edit-button {
    background: linear-gradient(145deg, #f8f9fa, #e9ecef);
    border: none;
    border-radius: 30px;
    padding: 12px 24px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #495057;
    cursor: pointer;
    transition: all 0.3s;
    font-size: 15px;
    font-weight: 600;
    box-shadow: 0 4px 10px rgba(0,0,0,0.05);
}

.edit-button i {
    margin-right: 10px;
    font-size: 16px;
    color: #2575fc;
}

.edit-button:hover {
    background: linear-gradient(145deg, #e9ecef, #dee2e6);
    color: #212529;
    transform: translateY(-3px);
    box-shadow: 0 6px 15px rgba(0,0,0,0.1);
}

.profile-bio {
    margin-top: 30px;
    padding: 25px;
    border-radius: 15px;
    background-color: rgba(248, 249, 250, 0.7);
    color: #495057;
    font-style: italic;
    line-height: 1.8;
    position: relative;
    box-shadow: inset 0 0 10px rgba(0,0,0,0.03);
}

.profile-bio:before {
    content: '\f10d';
    font-family: 'Font Awesome 5 Free';
    font-weight: 900;
    position: absolute;
    top: 10px;
    left: 15px;
    color: rgba(37, 117, 252, 0.1);
    font-size: 24px;
}

.profile-bio:after {
    content: '\f10e';
    font-family: 'Font Awesome 5 Free';
    font-weight: 900;
    position: absolute;
    bottom: 10px;
    right: 15px;
    color: rgba(37, 117, 252, 0.1);
    font-size: 24px;
}

/* Profile Content Styles */
.profile-content {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
    gap: 25px;
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
    background: linear-gradient(135deg, transparent 70%, rgba(106, 17, 203, 0.05) 100%);
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
    background: linear-gradient(135deg, #6a11cb 0%, #2575fc 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 18px;
    color: white;
    font-size: 20px;
    box-shadow: 0 5px 15px rgba(106, 17, 203, 0.3);
    transform: rotate(-5deg);
    transition: transform 0.3s ease;
}

.workload-section .section-icon {
    background: linear-gradient(135deg, #7C3AED, #6366F1);
    transform: none;
    border-radius: 12px;
}

.section-header h3 {
    margin: 0;
    font-size: 22px;
    font-weight: 700;
    color: #212529;
    letter-spacing: -0.3px;
}

.section-view-all {
    margin-left: auto;
    color: #6366F1;
    text-decoration: none;
    font-size: 15px;
    font-weight: 600;
    transition: all 0.3s;
    padding: 8px 15px;
    border-radius: 20px;
    background-color: rgba(99, 102, 241, 0.1);
    display: flex;
    align-items: center;
}

.section-view-all i {
    margin-left: 5px;
    font-size: 12px;
}

.section-view-all:hover {
    color: white;
    background-color: #6366F1;
    text-decoration: none;
    box-shadow: 0 5px 15px rgba(99, 102, 241, 0.3);
    transform: translateY(-2px);
}

.section-content {
    overflow: hidden;
    position: relative;
    min-height: 250px;
    display: flex;
    flex-direction: column;
}

.section-content.scrollable {
    padding: 5px 0;
}

.no-data-message {
    text-align: center;
    padding: 30px 20px;
    color: #6c757d;
    background-color: #f8f9fa;
    border-radius: 10px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    flex-grow: 1;
    margin: 10px 0;
}

.no-data-icon {
    font-size: 40px;
    margin-bottom: 15px;
    color: #adb5bd;
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
    background-color: rgba(0,0,0,0.2);
    border-radius: 3px;
}

.task-items-container::-webkit-scrollbar-track {
    background-color: rgba(0,0,0,0.05);
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
    background: linear-gradient(to bottom, #6a11cb, #2575fc);
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
    background: linear-gradient(to right, #6a11cb, #2575fc);
    transition: width 0.3s ease;
}

.task-item h4 a:hover {
    color: #2575fc;
}

.task-item h4 a:hover:before {
    width: 100%;
}

.task-details {
    display: flex;
    justify-content: space-between;
    font-size: 14px;
    color: #6c757d;
    padding-left: 10px;
    margin-top: 5px;
}

.task-project a {
    color: #495057;
    text-decoration: none;
    transition: all 0.3s;
    display: flex;
    align-items: center;
    font-weight: 500;
    padding: 5px 10px;
    border-radius: 20px;
    background-color: rgba(37, 117, 252, 0.05);
}

.task-project a i {
    margin-right: 6px;
    font-size: 13px;
    color: #2575fc;
}

.task-project a:hover {
    color: #2575fc;
    background-color: rgba(37, 117, 252, 0.1);
    transform: translateY(-2px);
}

.task-due {
    white-space: nowrap;
    display: flex;
    align-items: center;
    font-weight: 500;
    padding: 5px 10px;
    border-radius: 20px;
    background-color: rgba(108, 117, 125, 0.05);
}

.task-due i {
    margin-right: 6px;
    font-size: 13px;
    color: #6c757d;
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

/* Achievements Styles */
.achievements-row {
    display: flex;
    justify-content: space-between;
    gap: 20px;
    padding: 10px 0;
    flex-grow: 1;
}

.achievement-item {
    text-align: center;
    flex: 1 1 0;
    position: relative;
    transition: transform 0.3s;
}

.achievement-item:hover {
    transform: translateY(-5px);
}

.achievement-item.locked {
    opacity: 0.7;
}

.achievement-badge {
    width: 70px;
    height: 70px;
    border-radius: 50%;
    margin: 0 auto 15px;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 4px 10px rgba(0,0,0,0.1);
    position: relative;
    overflow: hidden;
}

.achievement-badge img {
    max-width: 70%;
    max-height: 70%;
}

.achievement-badge i {
    font-size: 28px;
    color: white;
}

.achievement-lock {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0,0,0,0.4);
    display: flex;
    align-items: center;
    justify-content: center;
}

.achievement-lock i {
    font-size: 20px;
    color: white;
}

.achievement-name {
    font-size: 14px;
    color: #333;
    font-weight: 500;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* Back Button Styles */
.back-button {
    margin-bottom: 25px;
}

.back-button a {
    color: #6c757d;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    padding: 8px 16px;
    transition: all 0.3s;
    border-radius: 20px;
}

.back-button a i {
    margin-right: 8px;
}

.back-button a:hover {
    color: #fff;
    background-color: #6c757d;
    text-decoration: none;
}

/* Achievements View Styles */
.achievements-view {
    display: flex;
    flex-direction: column;
    gap: 25px;
}

.achievements-header {
    background-color: white;
    border-radius: 15px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.08);
    padding: 25px;
}

.achievements-header h2 {
    margin: 0 0 25px 0;
    font-size: 24px;
    font-weight: 700;
    display: flex;
    align-items: center;
    border-bottom: 1px solid #e9ecef;
    padding-bottom: 20px;
    color: #333;
}

.achievements-header h2 i {
    margin-right: 15px;
}

.achievements-summary {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.achievement-stats {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 20px;
}

.stat-item {
    text-align: center;
    padding: 20px;
    background-color: #f8f9fa;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    transition: transform 0.3s;
}

.stat-item:hover {
    transform: translateY(-5px);
    box-shadow: 0 8px 15px rgba(0,0,0,0.1);
}

.stat-value {
    font-size: 28px;
    font-weight: bold;
    margin-bottom: 8px;
    color: #333;
}

.stat-label {
    font-size: 14px;
    color: #6c757d;
    font-weight: 500;
}

.completion-progress {
    margin-top: 15px;
}

.progress {
    height: 10px;
    border-radius: 5px;
    background-color: #e9ecef;
    overflow: hidden;
}

.progress-bar {
    transition: width 1s ease;
}

.achievements-container {
    background-color: white;
    border-radius: 15px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.08);
    padding: 25px;
}

.achievements-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 25px;
}

.achievement-card {
    position: relative;
    background-color: #f8f9fa;
    border-radius: 12px;
    padding: 25px;
    display: flex;
    align-items: center;
    transition: transform 0.3s, box-shadow 0.3s;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}

.achievement-card.earned {
    border-left: 4px solid #28a745;
}

.achievement-card.unearned {
    border-left: 4px solid #6c757d;
    opacity: 0.8;
}

.achievement-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 8px 20px rgba(0,0,0,0.1);
}

.achievement-status {
    position: absolute;
    top: 15px;
    right: 15px;
}

.status-badge {
    width: 30px;
    height: 30px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    box-shadow: 0 2px 5px rgba(0,0,0,0.2);
}

.status-badge.earned {
    background-color: #28a745;
}

.status-badge.locked {
    background-color: #6c757d;
}

.achievement-icon {
    width: 70px;
    height: 70px;
    border-radius: 50%;
    overflow: hidden;
    margin-right: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 28px;
    box-shadow: 0 4px 10px rgba(0,0,0,0.15);
    flex-shrink: 0;
}

.achievement-icon img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.achievement-details {
    flex-grow: 1;
}

.achievement-details h3 {
    margin: 0 0 8px 0;
    font-size: 18px;
    font-weight: 600;
    color: #333;
}

.achievement-description {
    font-size: 14px;
    color: #6c757d;
    margin-bottom: 15px;
    line-height: 1.5;
}

.achievement-earned {
    display: flex;
    align-items: center;
    gap: 12px;
}

.achievement-earned .badge {
    font-size: 12px;
    padding: 5px 10px;
    font-weight: 500;
}

.earned-date {
    font-size: 14px;
    color: #6c757d;
}

.achievement-progress {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.progress-text {
    font-size: 12px;
    color: #6c757d;
    text-align: right;
    font-weight: 500;
}

.no-achievements-message {
    text-align: center;
    padding: 50px 20px;
    color: #6c757d;
}

.no-data-img {
    width: 120px;
    height: 120px;
    margin-bottom: 25px;
    opacity: 0.7;
}

/* Responsive Adjustments */
@media (max-width: 992px) {
    .profile-content {
        grid-template-columns: 1fr;
    }
    
    .achievements-grid {
        grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
    }
}

@media (max-width: 768px) {
    .profile-header {
        flex-direction: column;
        text-align: center;
    }
    
    .profile-avatar {
        margin-right: 0;
        margin-bottom: 20px;
    }
    
    .profile-edit {
        margin-left: 0;
        margin-top: 20px;
    }
    
    .profile-stats {
        justify-content: center;
    }
    
    .reputation-summary,
    .achievement-stats {
        grid-template-columns: 1fr;
    }
    
    .achievement-card {
        flex-direction: column;
        text-align: center;
    }
    
    .achievement-icon {
        margin-right: 0;
        margin-bottom: 15px;
    }
    
    .achievement-status {
        position: static;
        margin-bottom: 15px;
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
    
    // Profile edit modal
    const editProfileBtn = document.getElementById('edit-profile-btn');
    if (editProfileBtn) {
        editProfileBtn.addEventListener('click', function() {
            const editProfileModal = new bootstrap.Modal(document.getElementById('editProfileModal'));
            editProfileModal.show();
        });
    }
    
    // Profile image preview
    const profileImageInput = document.getElementById('profile_image');
    if (profileImageInput) {
        profileImageInput.addEventListener('change', function() {
            const file = this.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const previewImg = document.getElementById('profile-preview');
                    const previewDefault = document.getElementById('profile-preview-default');
                    
                    if (previewImg) {
                        previewImg.src = e.target.result;
                    } else if (previewDefault) {
                        // Replace default avatar with image
                        const img = document.createElement('img');
                        img.src = e.target.result;
                        img.id = 'profile-preview';
                        img.alt = 'Profile Preview';
                        previewDefault.parentNode.replaceChild(img, previewDefault);
                    }
                };
                reader.readAsDataURL(file);
            }
        });
    }

    // Logout confirmation
    document.getElementById('logout-btn').addEventListener('click', function(e) {
        e.preventDefault();
        var logoutModal = new bootstrap.Modal(document.getElementById('logoutModal'));
        logoutModal.show();
    });
    
    // Enable touch scrolling for horizontal scroll containers
    const scrollContainers = document.querySelectorAll('.task-items-container, .achievements-grid');
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
});
</script>

<?php include '../../includes/footer.php'; ?> 