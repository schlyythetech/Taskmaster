<?php
/**
 * Projects Page
 * 
 * This page displays all projects the user is a member of,
 * as well as projects they can join.
 */
require_once '../../includes/functions.php';
require_once '../../config/database.php';

// Authentication check
if (!isLoggedIn()) {
    setMessage("You must be logged in to access the projects page.", "danger");
    redirect('../auth/login.php');
}

// Get user data
$user_id = $_SESSION['user_id'];

// Generate CSRF token if it doesn't exist
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Get show completed flag
$show_completed = isset($_GET['show_completed']) && $_GET['show_completed'] == 1;

// Check database connection
if (!isset($conn) || !$conn) {
    setMessage("Database connection is not available. Please try again later or contact support.", "danger");
    include '../../includes/header.php';
    include '../../includes/footer.php';
    exit;
}

/**
 * Ensures the database schema has all required columns and tables
 * 
 * @param PDO $conn Database connection
 * @return array Schema information
 */
function ensureRequiredDatabaseSchema($conn) {
    // Check if the status column exists in project_members
    $stmt = $conn->prepare("
        SELECT COUNT(*) as column_exists 
        FROM information_schema.COLUMNS 
        WHERE TABLE_SCHEMA = DATABASE() 
        AND TABLE_NAME = 'project_members' 
        AND COLUMN_NAME = 'status'
    ");
    $stmt->execute();
    $statusColumnExists = (bool)$stmt->fetch()['column_exists'];
    
    if (!$statusColumnExists) {
        // Add status column if it doesn't exist
        $conn->exec("
            ALTER TABLE project_members
            ADD COLUMN status ENUM('pending', 'active', 'rejected') DEFAULT 'active' AFTER role
        ");
        
        // Update all existing members to 'active'
        $conn->exec("
            UPDATE project_members
            SET status = 'active'
        ");
    }
    
    // Check if projects table has status column
    $stmt = $conn->prepare("
        SELECT COUNT(*) as column_exists 
        FROM information_schema.COLUMNS 
        WHERE TABLE_SCHEMA = DATABASE() 
        AND TABLE_NAME = 'projects' 
        AND COLUMN_NAME = 'status'
    ");
    $stmt->execute();
    $projectStatusColumnExists = (bool)$stmt->fetch()['column_exists'];
    
    // Add status column to projects table if it doesn't exist
    if (!$projectStatusColumnExists) {
        $conn->exec("
            ALTER TABLE projects
            ADD COLUMN status ENUM('planning', 'in_progress', 'review', 'completed', 'on_hold') DEFAULT 'in_progress' AFTER description
        ");
        
        // Update all existing projects to 'in_progress'
        $conn->exec("
            UPDATE projects
            SET status = 'in_progress'
        ");
    }
    
    // Ensure favorite_projects table exists
    $conn->exec("
        CREATE TABLE IF NOT EXISTS favorite_projects (
            user_id INT NOT NULL,
            project_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, project_id),
            FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
            FOREIGN KEY (project_id) REFERENCES projects(project_id) ON DELETE CASCADE
        )
    ");
    
    // Check if tasks table exists
    $stmt = $conn->prepare("
        SELECT COUNT(*) as table_exists 
        FROM information_schema.tables 
        WHERE table_schema = DATABASE() 
        AND table_name = 'tasks'
    ");
    $stmt->execute();
    $tasksTableExists = (bool)$stmt->fetch()['table_exists'];
    
    return [
        'statusColumnExists' => $statusColumnExists,
        'projectStatusColumnExists' => $projectStatusColumnExists,
        'tasksTableExists' => $tasksTableExists
    ];
}

/**
 * Process project status based on tasks or existing status
 * 
 * @param PDO $conn Database connection
 * @param array &$projects Projects array (passed by reference)
 * @param int $key Current project key
 * @param array $project Current project
 * @param bool $projectStatusColumnExists Whether projects table has status column
 * @param bool $tasksTableExists Whether tasks table exists
 */
function processProjectStatus($conn, &$projects, $key, $project, $projectStatusColumnExists, $tasksTableExists) {
    // If project already has a status column, use it
    if ($projectStatusColumnExists && isset($project['status'])) {
        // Nothing to do, the status is already set
        // Add a display-friendly status label
        if ($project['status'] === 'in_progress') {
            $projects[$key]['status_display'] = 'ongoing';
        } else {
            $projects[$key]['status_display'] = $project['status'];
        }
    } 
    // Otherwise calculate status based on tasks
    else if ($tasksTableExists) {
        // Count tasks by status
        $stmt = $conn->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status IN ('to_do', 'in_progress', 'review') THEN 1 ELSE 0 END) as active_tasks
            FROM tasks 
            WHERE project_id = ?
        ");
        $stmt->execute([$project['project_id']]);
        $task_stats = $stmt->fetch();
        
        // Determine project status based on task status
        if ($task_stats && $task_stats['total'] > 0) {
            if ($task_stats['completed'] == $task_stats['total']) {
                // All tasks are completed
                $projects[$key]['status'] = 'completed';
                $projects[$key]['status_display'] = 'completed';
            } else if ($task_stats['active_tasks'] > 0) {
                // At least one task is to_do, in_progress, or review
                $projects[$key]['status'] = 'in_progress';
                $projects[$key]['status_display'] = 'ongoing';
            } else {
                // Default to in_progress for any other case
                $projects[$key]['status'] = 'in_progress';
                $projects[$key]['status_display'] = 'ongoing';
            }
        } else {
            // No tasks, default to in_progress
            $projects[$key]['status'] = 'in_progress';
            $projects[$key]['status_display'] = 'ongoing';
        }
        
        // Update the project status in the database if it has changed
        if ($projectStatusColumnExists) {
            $stmt = $conn->prepare("
                UPDATE projects 
                SET status = ? 
                WHERE project_id = ? AND status != ?
            ");
            $stmt->execute([$projects[$key]['status'], $project['project_id'], $projects[$key]['status']]);
        }
    } else {
        // If tasks table doesn't exist, default to in_progress
        $projects[$key]['status'] = 'in_progress';
        $projects[$key]['status_display'] = 'ongoing';
    }
}

/**
 * Enriches project data with additional information
 * 
 * @param PDO $conn Database connection
 * @param array &$projects Projects array (passed by reference)
 * @param int $key Current project key
 * @param array $project Current project
 * @param int $user_id User ID
 * @param bool $statusColumnExists Whether project_members table has status column
 */
function enrichProjectData($conn, &$projects, $key, $project, $user_id, $statusColumnExists) {
    // Normalize the icon path if it exists
    if (!empty($projects[$key]['icon'])) {
        // Ensure the icon exists, if not set to empty to use default icon
        if (!file_exists('../../' . $projects[$key]['icon'])) {
            $projects[$key]['icon'] = '';
        }
    }
    
    // Check if project is favorited by user
    $stmt = $conn->prepare("
        SELECT 1 FROM favorite_projects
        WHERE project_id = ? AND user_id = ?
    ");
    $stmt->execute([$project['project_id'], $user_id]);
    $projects[$key]['is_favorite'] = (bool)$stmt->fetch();
    
    // Get project members
    $stmt = $conn->prepare("
        SELECT u.user_id, u.first_name, u.last_name, u.profile_image 
        FROM project_members pm
        JOIN users u ON pm.user_id = u.user_id
        WHERE pm.project_id = ? " . 
        ($statusColumnExists ? "AND pm.status = 'active'" : "") . "
        LIMIT 5
    ");
    $stmt->execute([$project['project_id']]);
    $projects[$key]['members'] = $stmt->fetchAll();
    
    // Count total active members
    $stmt = $conn->prepare("
        SELECT COUNT(*) as count 
        FROM project_members 
        WHERE project_id = ? " . 
        ($statusColumnExists ? "AND status = 'active'" : "") . "
    ");
    $stmt->execute([$project['project_id']]);
    $result = $stmt->fetch();
    $projects[$key]['total_members'] = $result ? $result['count'] : 0;
}

/**
 * Fetches all projects the user is a member of
 * 
 * @param PDO $conn Database connection
 * @param int $user_id User ID
 * @param bool $show_completed Whether to show completed projects
 * @return array Array of projects
 */
function fetchUserProjects($conn, $user_id, $show_completed) {
    // Get schema info
    $schema = ensureRequiredDatabaseSchema($conn);
    $statusColumnExists = $schema['statusColumnExists'];
    $projectStatusColumnExists = $schema['projectStatusColumnExists'];
    $tasksTableExists = $schema['tasksTableExists'];
    
    // Query to get projects the user is a member of
    $query = "SELECT p.*, pm.role
            FROM projects p 
            JOIN project_members pm ON p.project_id = pm.project_id 
            WHERE pm.user_id = ?";
    
    if ($statusColumnExists) {
        $query .= " AND pm.status = 'active'";
    }
    
    $query .= " ORDER BY p.created_at DESC";
    
    $stmt = $conn->prepare($query);
    $stmt->execute([$user_id]);
    $projects = $stmt->fetchAll();
    
    // For each project, get the members and add a custom status field if needed
    foreach ($projects as $key => $project) {
        try {
            // Process project status
            processProjectStatus($conn, $projects, $key, $project, $projectStatusColumnExists, $tasksTableExists);
            
            // If show_completed is false and project is completed, skip this project
            if (!$show_completed && $projects[$key]['status'] === 'completed') {
                unset($projects[$key]);
                continue;
            }
            
            // Get project members and additional info
            enrichProjectData($conn, $projects, $key, $project, $user_id, $statusColumnExists);
            
        } catch (PDOException $e) {
            // Log the error but continue with other projects
            error_log("Error processing project {$project['project_id']}: " . $e->getMessage());
            // Set default values
            $projects[$key]['status'] = 'ongoing';
            $projects[$key]['is_favorite'] = false;
            $projects[$key]['members'] = [];
            $projects[$key]['total_members'] = 0;
        }
    }
    
    // Reindex array after potentially removing items
    if (!$show_completed) {
        $projects = array_values($projects);
    }
    
    return $projects;
}

/**
 * Fetches projects the user can join
 * 
 * @param PDO $conn Database connection
 * @param int $user_id User ID
 * @return array Array of joinable projects
 */
function fetchJoinableProjects($conn, $user_id) {
    // Get schema info
    $schema = ensureRequiredDatabaseSchema($conn);
    $statusColumnExists = $schema['statusColumnExists'];
    $projectStatusColumnExists = $schema['projectStatusColumnExists'];
    
    // Get all projects the user is not a member of (for the "Join Project" feature)
    $query = "SELECT p.*, u.first_name as owner_first_name, u.last_name as owner_last_name
            FROM projects p 
            JOIN users u ON p.owner_id = u.user_id
            WHERE p.project_id NOT IN (
                SELECT project_id FROM project_members WHERE user_id = ?";
    
    if ($statusColumnExists) {
        $query .= " AND (status = 'active' OR status = 'pending')";
    }
    
    $query .= ")
            ORDER BY p.created_at DESC
            LIMIT 5"; // Limit to 5 for now
    
    $stmt = $conn->prepare($query);
    $stmt->execute([$user_id]);
    $joinable_projects = $stmt->fetchAll();
    
    // Get members and basic info for joinable projects
    foreach ($joinable_projects as $key => $project) {
        try {
            // Normalize the icon path if it exists
            if (!empty($joinable_projects[$key]['icon'])) {
                // Ensure the icon exists, if not set to empty to use default icon
                if (!file_exists('../../' . $joinable_projects[$key]['icon'])) {
                    $joinable_projects[$key]['icon'] = '';
                }
            }
            
            // Get project members
            $stmt = $conn->prepare("
                SELECT u.user_id, u.first_name, u.last_name, u.profile_image 
                FROM project_members pm
                JOIN users u ON pm.user_id = u.user_id
                WHERE pm.project_id = ? " . 
                ($statusColumnExists ? "AND pm.status = 'active'" : "") . "
                LIMIT 5
            ");
            $stmt->execute([$project['project_id']]);
            $joinable_projects[$key]['members'] = $stmt->fetchAll();
            
            // Count total active members
            $stmt = $conn->prepare("
                SELECT COUNT(*) as count 
                FROM project_members 
                WHERE project_id = ? " . 
                ($statusColumnExists ? "AND status = 'active'" : "") . "
            ");
            $stmt->execute([$project['project_id']]);
            $result = $stmt->fetch();
            $joinable_projects[$key]['total_members'] = $result ? $result['count'] : 0;
            
            // Check if project is favorited by user
            $joinable_projects[$key]['is_favorite'] = false;
            
            // Use existing status column or default to ongoing
            if ($projectStatusColumnExists && isset($project['status'])) {
                // Add a display-friendly status label
                if ($project['status'] === 'in_progress') {
                    $joinable_projects[$key]['status_display'] = 'ongoing';
                } else {
                    $joinable_projects[$key]['status_display'] = $project['status'];
                }
            } else {
                $joinable_projects[$key]['status'] = 'ongoing';
                $joinable_projects[$key]['status_display'] = 'ongoing';
            }
            
            // Check if the user has a pending join request
            if ($statusColumnExists) {
                $stmt = $conn->prepare("
                    SELECT status FROM project_members
                    WHERE project_id = ? AND user_id = ?
                ");
                $stmt->execute([$project['project_id'], $user_id]);
                $membership = $stmt->fetch();
                $joinable_projects[$key]['membership_status'] = $membership ? $membership['status'] : null;
            } else {
                // If status column doesn't exist, assume no pending requests
                $joinable_projects[$key]['membership_status'] = null;
            }
        } catch (PDOException $e) {
            // Log the error but continue with other projects
            error_log("Error processing joinable project {$project['project_id']}: " . $e->getMessage());
            // Set default values
            $joinable_projects[$key]['members'] = [];
            $joinable_projects[$key]['total_members'] = 0;
            $joinable_projects[$key]['membership_status'] = null;
        }
    }
    
    return $joinable_projects;
}

// Main database operations
try {
    // Fetch user's projects
    $projects = fetchUserProjects($conn, $user_id, $show_completed);
    
    // Fetch joinable projects
    $joinable_projects = fetchJoinableProjects($conn, $user_id);
    
} catch (PDOException $e) {
    error_log("Database Error in projects.php: " . $e->getMessage());
    setMessage("Error fetching projects: " . $e->getMessage(), "danger");
    $projects = [];
    $joinable_projects = [];
}

// Page title
$page_title = "Projects";
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
                <li class="active">
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
            <a href="../core/settings.php"><i class="fas fa-cog"></i> Settings</a>
            <a href="#" id="logout-btn"><i class="fas fa-sign-out-alt"></i> Log Out</a>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Top Navigation -->
        <div class="top-nav">
            <div>
                <a href="create_project.php" class="btn btn-success rounded-pill px-4">Start a new project</a>
            </div>
            <!-- Notification system is included in header.php -->
            <div class="top-nav-right-placeholder"></div>
        </div>

        <!-- Projects Content -->
        <div class="container mt-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div class="search-container">
                    <input type="text" class="form-control rounded-pill" id="searchProjects" placeholder="Search projects" aria-label="Search projects">
                    <button class="search-btn" aria-label="Search"><i class="fas fa-search"></i></button>
                </div>
                <div class="form-check form-switch">
                    <label class="form-check-label" for="showCompleted">Show completed projects</label>
                    <input class="form-check-input" type="checkbox" id="showCompleted" <?php echo $show_completed ? 'checked' : ''; ?> aria-checked="<?php echo $show_completed ? 'true' : 'false'; ?>">
                </div>
            </div>

            <!-- Projects Grid -->
            <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-4">
                <?php if (empty($projects)): ?>
                    <div class="col-12">
                        <div class="no-projects text-center p-5">
                            <p>You don't have any projects yet. Click "Start a new project" to create one.</p>
                        </div>
                    </div>
                <?php else: ?>
                    <?php foreach ($projects as $project): ?>
                        <div class="col">
                            <div class="card project-card h-100" role="region" aria-label="Project: <?php echo htmlspecialchars($project['name']); ?>">
                                <div class="card-body">
                                    <!-- Card Header with Actions -->
                                    <div class="d-flex justify-content-between mb-3">
                                        <div class="dropdown">
                                            <button class="btn btn-sm project-menu-btn" type="button" 
                                                   id="projectMenu<?php echo htmlspecialchars($project['project_id']); ?>" 
                                                   data-bs-toggle="dropdown" 
                                                   aria-expanded="false"
                                                   aria-label="Project options">
                                                <i class="fas fa-ellipsis-v"></i>
                                            </button>
                                            <ul class="dropdown-menu project-dropdown-menu shadow" aria-labelledby="projectMenu<?php echo htmlspecialchars($project['project_id']); ?>">
                                                <li><a class="dropdown-item" href="view_project.php?id=<?php echo htmlspecialchars($project['project_id']); ?>">
                                                    <i class="fas fa-eye text-primary"></i> <span>View</span>
                                                </a></li>
                                                <?php if ($project['owner_id'] == $user_id || $project['role'] == 'owner'): ?>
                                                <li><a class="dropdown-item text-danger" href="#" data-bs-toggle="modal" data-bs-target="#deleteProjectModal<?php echo htmlspecialchars($project['project_id']); ?>">
                                                    <i class="fas fa-trash-alt"></i> <span>Delete</span>
                                                </a></li>
                                                <?php else: ?>
                                                <li><a class="dropdown-item text-danger" href="#" data-bs-toggle="modal" data-bs-target="#leaveProjectModal<?php echo htmlspecialchars($project['project_id']); ?>">
                                                    <i class="fas fa-sign-out-alt"></i> <span>Leave</span>
                                                </a></li>
                                                <?php endif; ?>
                                            </ul>
                                        </div>
                                        <button class="btn btn-sm btn-outline-secondary favorite-btn <?php echo $project['is_favorite'] ? 'active' : ''; ?>" 
                                               data-project-id="<?php echo htmlspecialchars($project['project_id']); ?>"
                                               aria-label="<?php echo $project['is_favorite'] ? 'Remove from favorites' : 'Add to favorites'; ?>"
                                               aria-pressed="<?php echo $project['is_favorite'] ? 'true' : 'false'; ?>">
                                            <i class="<?php echo $project['is_favorite'] ? 'fas' : 'far'; ?> fa-star"></i>
                                        </button>
                                    </div>
                                    
                                    <!-- Project Info -->
                                    <div class="text-center mb-3">
                                        <div class="project-icon">
                                            <?php if (!empty($project['icon']) && file_exists('../../' . $project['icon'])): ?>
                                                <img src="../../<?php echo htmlspecialchars($project['icon']); ?>" alt="Project Icon for <?php echo htmlspecialchars($project['name']); ?>">
                                            <?php else: ?>
                                                <div class="default-icon">
                                                    <i class="fas fa-cube"></i>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <h5 class="card-title mt-3"><?php echo htmlspecialchars($project['name']); ?></h5>
                                        <p class="card-subtitle text-muted"><?php echo htmlspecialchars($project['description']); ?></p>
                                    </div>
                                    
                                    <!-- Project Members -->
                                    <div class="project-members">
                                        <div class="member-avatars">
                                            <?php foreach ($project['members'] as $member): ?>
                                                <div class="member-avatar" title="<?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name'] ?? ''); ?>">
                                                    <?php if (!empty($member['profile_image']) && file_exists('../../' . $member['profile_image'])): ?>
                                                        <img src="../../<?php echo htmlspecialchars($member['profile_image']); ?>" alt="<?php echo htmlspecialchars($member['first_name']); ?>">
                                                    <?php else: ?>
                                                        <div class="default-avatar"><?php echo substr($member['first_name'], 0, 1); ?></div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                            
                                            <?php if ($project['total_members'] > count($project['members'])): ?>
                                                <div class="member-avatar more-members" title="<?php echo $project['total_members'] - count($project['members']); ?> more members">
                                                    +<?php echo $project['total_members'] - count($project['members']); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <!-- Project Status -->
                                    <div class="project-status mt-3">
                                        <span class="badge <?php echo $project['status_display'] === 'completed' ? 'bg-success' : 'bg-primary'; ?>" role="status" aria-label="Project status: <?php echo $project['status_display']; ?>">
                                            <?php echo strtoupper($project['status_display']); ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <?php if (count($joinable_projects) > 0): ?>
            <div class="projects-section mb-5 mt-5">
                <div class="section-header">
                    <h2>Projects You Can Join</h2>
                    <a href="#" class="view-all">View All</a>
                </div>
                <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-4">
                    <?php foreach ($joinable_projects as $project): ?>
                        <div class="col">
                            <div class="card project-card h-100" role="region" aria-label="Joinable project: <?php echo htmlspecialchars($project['name']); ?>">
                                <!-- Project Header/Image -->
                                <?php if (!empty($project['icon']) && file_exists('../../' . $project['icon'])): ?>
                                    <div class="project-header" style="background-image: url('../../<?php echo htmlspecialchars($project['icon']); ?>')"></div>
                                <?php else: ?>
                                    <div class="project-header default-project-header">
                                        <i class="fas fa-project-diagram"></i>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="card-body">
                                    <!-- Project Info -->
                                    <h5 class="card-title"><?php echo htmlspecialchars($project['name']); ?></h5>
                                    <p class="text-muted small">Owner: <?php echo htmlspecialchars($project['owner_first_name'] . ' ' . $project['owner_last_name']); ?></p>
                                    <p class="card-text text-truncate"><?php echo htmlspecialchars($project['description'] ?? 'No description provided.'); ?></p>
                                    
                                    <!-- Project Members -->
                                    <?php if (!empty($project['members'])): ?>
                                        <div class="project-members">
                                            <div class="member-avatars">
                                                <?php foreach ($project['members'] as $member): ?>
                                                    <div class="member-avatar" title="<?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?>">
                                                        <?php if (!empty($member['profile_image']) && file_exists('../../' . $member['profile_image'])): ?>
                                                            <img src="../../<?php echo htmlspecialchars($member['profile_image']); ?>" alt="<?php echo htmlspecialchars($member['first_name']); ?>">
                                                        <?php else: ?>
                                                            <div class="default-avatar"><?php echo htmlspecialchars(substr($member['first_name'], 0, 1)); ?></div>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endforeach; ?>
                                                <?php if ($project['total_members'] > count($project['members'])): ?>
                                                    <div class="member-avatar more-members" title="<?php echo $project['total_members'] - count($project['members']); ?> more members">
                                                        +<?php echo $project['total_members'] - count($project['members']); ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <!-- Project Status -->
                                    <div class="project-status mb-3">
                                        <span class="badge <?php echo ($project['status_display'] ?? '') === 'completed' ? 'bg-success' : 'bg-primary'; ?>" role="status" aria-label="Project status: <?php echo $project['status_display'] ?? 'ongoing'; ?>">
                                            <?php echo strtoupper($project['status_display'] ?? 'ONGOING'); ?>
                                        </span>
                                    </div>
                                    
                                    <!-- Join Actions -->
                                    <div class="d-grid gap-2 mt-3">
                                        <?php if (isset($project['membership_status']) && $project['membership_status'] === 'pending'): ?>
                                            <button class="btn btn-outline-info btn-sm w-100" disabled>Join Request Pending</button>
                                        <?php elseif (isset($project['membership_status']) && $project['membership_status'] === 'rejected'): ?>
                                            <button class="btn btn-outline-danger btn-sm w-100" disabled>Join Request Rejected</button>
                                        <?php else: ?>
                                            <form method="post" action="join_project.php" class="d-grid">
                                                <input type="hidden" name="project_id" value="<?php echo htmlspecialchars($project['project_id']); ?>">
                                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                <button type="submit" class="btn btn-primary btn-sm" aria-label="Request to join <?php echo htmlspecialchars($project['name']); ?>">Request to Join</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Logout Confirmation Modal -->
<div class="modal fade" id="logoutModal" tabindex="-1" aria-labelledby="logoutModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="logoutModalLabel">Confirm Logout</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to log out?</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <a href="logout.php" class="btn btn-primary" role="button">Logout</a>
            </div>
        </div>
    </div>
</div>

<!-- Leave Project Modals -->
<?php foreach ($projects as $project): ?>
<div class="modal fade" id="leaveProjectModal<?php echo htmlspecialchars($project['project_id']); ?>" tabindex="-1" aria-labelledby="leaveProjectModalLabel<?php echo htmlspecialchars($project['project_id']); ?>" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="leaveProjectModalLabel<?php echo htmlspecialchars($project['project_id']); ?>">Request to Leave <?php echo htmlspecialchars($project['name']); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>To leave <strong><?php echo htmlspecialchars($project['name']); ?></strong>, a request must be sent to the project owner.</p>
                <p>Send a request to leave?</p>
            </div>
            <div class="modal-footer justify-content-end">
                <button type="button" class="btn btn-link text-dark" data-bs-dismiss="modal">Cancel</button>
                <form method="post" action="leave_project.php">
                    <input type="hidden" name="project_id" value="<?php echo htmlspecialchars($project['project_id']); ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <button type="submit" class="btn btn-danger">Send Request</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php if ($project['owner_id'] == $user_id || $project['role'] == 'owner'): ?>
<!-- Delete Project Modal -->
<div class="modal fade" id="deleteProjectModal<?php echo htmlspecialchars($project['project_id']); ?>" tabindex="-1" aria-labelledby="deleteProjectModalLabel<?php echo htmlspecialchars($project['project_id']); ?>" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="deleteProjectModalLabel<?php echo htmlspecialchars($project['project_id']); ?>">Delete Project</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-danger" role="alert">
                    <i class="fas fa-exclamation-triangle me-2" aria-hidden="true"></i> Warning: This action cannot be undone.
                </div>
                <p>Are you sure you want to delete <strong><?php echo htmlspecialchars($project['name']); ?></strong>?</p>
                <p>All project data, tasks, and member associations will be permanently removed.</p>
            </div>
            <div class="modal-footer justify-content-end">
                <button type="button" class="btn btn-link text-dark" data-bs-dismiss="modal">Cancel</button>
                <form method="post" action="delete_project.php">
                    <input type="hidden" name="project_id" value="<?php echo htmlspecialchars($project['project_id']); ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <button type="submit" class="btn btn-danger">Delete Project</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
<?php endforeach; ?>

<style>
/* Search functionality */
.search-container {
    position: relative;
    max-width: 400px;
    width: 100%;
}

.search-container input {
    padding-right: 40px;
}

.search-btn {
    position: absolute;
    right: 10px;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    color: #666;
}

/* Project cards */
.project-card {
    border-radius: 10px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    transition: transform 0.2s, box-shadow 0.2s;
    height: 100%;
    display: flex;
    flex-direction: column;
}

.project-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
}

.project-card .card-body {
    display: flex;
    flex-direction: column;
    flex-grow: 1;
}

/* Project header and content */
.project-icon {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    background-color: #f0f0f0;
    margin: 0 auto;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
}

.project-icon img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.default-icon {
    width: 100%;
    height: 100%;
    background-color: #e9ecef;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #6c757d;
    font-size: 2rem;
}

.card-subtitle {
    overflow: hidden;
    text-overflow: ellipsis;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    max-height: 3em;
    margin-bottom: 1rem;
    color: #555;
}

/* Project members section */
.project-members {
    display: flex;
    justify-content: center;
    margin-top: auto;
    padding-top: 15px;
}

.member-avatars {
    display: flex;
    margin-right: -10px;
}

.member-avatar {
    width: 35px;
    height: 35px;
    border-radius: 50%;
    background-color: #f0f0f0;
    border: 2px solid #fff;
    margin-right: -10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    font-weight: bold;
    color: #444;
    overflow: hidden;
}

.member-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.default-avatar {
    width: 100%;
    height: 100%;
    background-color: #5a6268;
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
}

.more-members {
    background-color: #d9dde0;
    font-size: 12px;
    color: #333;
}

/* Project status and actions */
.project-status {
    text-align: center;
    margin-top: 15px;
}

.favorite-btn {
    border: none;
    background: none;
    transition: color 0.3s;
}

.favorite-btn:hover {
    color: #e0a800;
}

.favorite-btn.active {
    color: #e0a800;
}

/* Section headers */
.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1rem;
}

.section-header h2 {
    font-size: 1.5rem;
    margin: 0;
}

.section-header .view-all {
    color: #0056b3;
    text-decoration: none;
}

/* Project header for joinable projects */
.project-header {
    height: 120px;
    background-size: cover;
    background-position: center;
    border-top-left-radius: 10px;
    border-top-right-radius: 10px;
}

.default-project-header {
    background-color: #e9ecef;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #495057;
    font-size: 2rem;
}

/* Project dropdown menu styling */
.project-menu-btn {
    border: none;
    background: transparent;
    color: #6c757d;
    padding: 5px 8px;
    border-radius: 50%;
    transition: all 0.2s ease;
}

.project-menu-btn:hover, .project-menu-btn:focus {
    background-color: rgba(108, 117, 125, 0.1);
    color: #495057;
}

.project-dropdown-menu {
    min-width: 120px;
    border-radius: 8px;
    border: none;
    padding: 0;
    overflow: hidden;
    z-index: 1050;
    position: absolute;
}

.project-dropdown-menu .dropdown-item {
    padding: 12px 16px;
    font-size: 0.9rem;
    display: flex;
    align-items: center;
    transition: background-color 0.2s;
    border-radius: 0;
}

.project-dropdown-menu .dropdown-item:hover {
    background-color: rgba(13, 110, 253, 0.05);
}

.project-dropdown-menu .dropdown-item i {
    width: 24px;
    text-align: center;
    margin-right: 8px;
}

.project-dropdown-menu .dropdown-item span {
    font-weight: 400;
}

.project-dropdown-menu .dropdown-item.text-danger {
    color: #dc3545 !important;
}

.project-dropdown-menu .dropdown-item.text-danger i {
    color: #dc3545;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .project-icon {
        width: 60px;
        height: 60px;
    }
    
    .member-avatar {
        width: 30px;
        height: 30px;
        font-size: 12px;
    }
}
</style>

<script>
/**
 * TaskMaster Projects Page JavaScript
 * Handles project interactions, search, filtering, and favorite toggling
 */
document.addEventListener('DOMContentLoaded', function() {
    // ===== UI Interaction Handlers =====
    
    // Logout confirmation
    initLogoutConfirmation();
    
    // Show/hide completed projects toggle
    initCompletedProjectsToggle();
    
    // Project search functionality
    initProjectSearch();
    
    // Favorite button toggle
    initFavoriteButtons();
    
    // ===== Function Definitions =====
    
    /**
     * Initialize logout confirmation modal
     */
    function initLogoutConfirmation() {
        const logoutBtn = document.getElementById('logout-btn');
        if (logoutBtn) {
            logoutBtn.addEventListener('click', function(e) {
                e.preventDefault();
                const logoutModal = new bootstrap.Modal(document.getElementById('logoutModal'));
                logoutModal.show();
            });
        }
    }
    
    /**
     * Initialize completed projects toggle
     */
    function initCompletedProjectsToggle() {
        const showCompletedToggle = document.getElementById('showCompleted');
        if (showCompletedToggle) {
            showCompletedToggle.addEventListener('change', function() {
                window.location.href = 'projects.php' + (this.checked ? '?show_completed=1' : '');
            });
        }
    }
    
    /**
     * Initialize project search functionality
     */
    function initProjectSearch() {
        const searchInput = document.getElementById('searchProjects');
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                const searchTerm = this.value.toLowerCase().trim();
                const projectCards = document.querySelectorAll('.project-card');
                
                projectCards.forEach(card => {
                    const projectTitle = card.querySelector('.card-title');
                    const projectDescription = card.querySelector('.card-subtitle, .card-text');
                    
                    if (!projectTitle) return; // Skip if no title element found
                    
                    const titleText = projectTitle.textContent.toLowerCase();
                    const descriptionText = projectDescription ? projectDescription.textContent.toLowerCase() : '';
                    
                    const parentCol = card.closest('.col');
                    if (!parentCol) return; // Skip if no parent column found
                    
                    if (titleText.includes(searchTerm) || descriptionText.includes(searchTerm)) {
                        parentCol.style.display = '';
                    } else {
                        parentCol.style.display = 'none';
                    }
                });
            });
        }
    }
    
    /**
     * Initialize favorite button functionality
     */
    function initFavoriteButtons() {
        const favoriteButtons = document.querySelectorAll('.favorite-btn');
        if (favoriteButtons.length) {
            favoriteButtons.forEach(btn => {
                btn.addEventListener('click', function() {
                    const icon = this.querySelector('i');
                    const projectId = this.getAttribute('data-project-id');
                    
                    if (!projectId) {
                        console.error('No project ID found for favorite button');
                        return;
                    }
                    
                    // Store original state to revert if needed
                    const originalClass = icon.className;
                    const isCurrentlyFavorite = icon.classList.contains('fas');
                    
                    // Toggle UI optimistically
                    if (icon.classList.contains('far')) {
                        icon.classList.replace('far', 'fas');
                        this.classList.add('active');
                    } else {
                        icon.classList.replace('fas', 'far');
                        this.classList.remove('active');
                    }
                    
                    // Save to database via fetch API
                    updateFavoriteStatus(projectId, isCurrentlyFavorite, icon, originalClass, this);
                });
            });
        }
    }
    
    /**
     * Update favorite status in the database
     * @param {string} projectId - The project ID
     * @param {boolean} isCurrentlyFavorite - Whether the project is currently favorited
     * @param {Element} icon - The star icon element
     * @param {string} originalClass - The original class of the icon
     * @param {Element} button - The favorite button element
     */
    function updateFavoriteStatus(projectId, isCurrentlyFavorite, icon, originalClass, button) {
        fetch('favorite_project.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-Token': '<?php echo $_SESSION['csrf_token']; ?>'
            },
            body: 'project_id=' + encodeURIComponent(projectId) + '&action=' + (isCurrentlyFavorite ? 'remove' : 'add')
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.json();
        })
        .then(data => {
            if (!data.success) {
                // Revert UI if there was an error
                revertFavoriteUI(icon, originalClass, isCurrentlyFavorite, button);
                
                // Show error message
                alert('Error: ' + (data.message || 'Failed to update favorite status'));
                console.error('Error updating favorite status:', data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            // Revert UI on error
            revertFavoriteUI(icon, originalClass, isCurrentlyFavorite, button);
            
            // Show generic error message
            alert('Error updating favorite status. Please try again.');
        });
    }
    
    /**
     * Revert the favorite UI to its original state
     * @param {Element} icon - The star icon element
     * @param {string} originalClass - The original class of the icon
     * @param {boolean} isCurrentlyFavorite - Whether the project was originally favorited
     * @param {Element} button - The favorite button element
     */
    function revertFavoriteUI(icon, originalClass, isCurrentlyFavorite, button) {
        icon.className = originalClass;
        if (isCurrentlyFavorite) {
            button.classList.add('active');
        } else {
            button.classList.remove('active');
        }
    }
});
</script>

<?php include '../../includes/footer.php'; ?> 