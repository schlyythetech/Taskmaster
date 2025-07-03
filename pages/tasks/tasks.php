<?php
require_once '../../includes/functions.php';
require_once '../../config/database.php';

/**
 * TaskMaster - User Tasks Page
 * 
 * This page displays all tasks assigned to the currently logged-in user
 * with filtering and search capabilities.
 */

// Ensure user is authenticated
if (!isLoggedIn()) {
    setMessage("You must be logged in to view tasks.", "danger");
    redirect('../auth/login.php');
}

// Get user information
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];
$first_name = explode(' ', $user_name)[0];

// Process filter parameters
$show_completed = isset($_GET['show_completed']) && $_GET['show_completed'] == 1;
$search_term = isset($_GET['search']) ? trim($_GET['search']) : '';

/**
 * Fetch user tasks from database
 * 
 * @param PDO $conn Database connection
 * @param int $user_id Current user ID
 * @param bool $show_completed Whether to show completed tasks
 * @param string $search_term Search term for filtering tasks
 * @return array Array of tasks
 */
function getUserTasks($conn, $user_id, $show_completed, $search_term) {
    try {
        // Build query parameters
        $params = [$user_id];
        
        // Start building the query
        $query = "
            SELECT 
                t.task_id, 
                t.title, 
                t.description, 
                t.status, 
                t.priority, 
                t.due_date, 
                p.name AS project_name, 
                p.project_id,
                e.title AS epic_title, 
                e.epic_id,
                (SELECT COUNT(DISTINCT pm.user_id) FROM project_members pm WHERE pm.project_id = t.project_id) AS assignee_count
            FROM 
                tasks t
            JOIN 
                projects p ON t.project_id = p.project_id
            LEFT JOIN 
                epics e ON t.epic_id = e.epic_id
            WHERE 
                t.assigned_to = ?";
        
        // Add filter for completed tasks
        if (!$show_completed) {
            $query .= " AND t.status != 'completed'";
        }
        
        // Add search term filter
        if (!empty($search_term)) {
            $query .= " AND (t.title LIKE ? OR t.description LIKE ? OR p.name LIKE ?)";
            $search_param = "%$search_term%";
            array_push($params, $search_param, $search_param, $search_param);
        }
        
        // Add ordering
        $query .= " ORDER BY 
                    CASE 
                        WHEN t.status = 'to_do' THEN 1
                        WHEN t.status = 'in_progress' THEN 2
                        WHEN t.status = 'review' THEN 3
                        WHEN t.status = 'completed' THEN 4
                        ELSE 5
                    END,
                    CASE 
                        WHEN t.priority = 'high' THEN 1
                        WHEN t.priority = 'medium' THEN 2
                        WHEN t.priority = 'low' THEN 3
                        ELSE 4
                    END,
                    t.due_date ASC";
        
        // Execute query
        $stmt = $conn->prepare($query);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Database Error in tasks.php: " . $e->getMessage());
        setMessage("Error fetching tasks: " . $e->getMessage(), "danger");
        return [];
    }
}

/**
 * Format task data for display
 * 
 * @param array $tasks Array of task data from database
 * @return array Formatted task data
 */
function formatTasks($tasks) {
    $formatted_tasks = [];
    
    foreach ($tasks as $task) {
        // Format status for display
        $task['status_display'] = ucwords(str_replace('_', ' ', $task['status']));
        
        // Format due date and check if overdue
        if (!empty($task['due_date'])) {
            $due_date = new DateTime($task['due_date']);
            $task['formatted_due_date'] = $due_date->format('M d, Y');
            
            // Add overdue flag
            $today = new DateTime('today');
            $task['is_overdue'] = ($due_date < $today && $task['status'] != 'completed');
        } else {
            $task['formatted_due_date'] = 'No due date';
            $task['is_overdue'] = false;
        }
        
        // Format priority with proper capitalization
        $task['priority_display'] = ucfirst($task['priority']);
        
        $formatted_tasks[] = $task;
    }
    
    return $formatted_tasks;
}

// Get and format tasks
$tasks = getUserTasks($conn, $user_id, $show_completed, $search_term);
$tasks = formatTasks($tasks);

// Set page title and include header
$page_title = "My Tasks";
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
                <li class="active">
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
            <!-- Notification system is included in header.php -->
            <div class="top-nav-right-placeholder"></div>
        </div>

        <!-- Tasks Content -->
        <div class="tasks-container">
            <div class="tasks-header">
                <div class="back-button">
                    <a href="../core/dashboard.php"><i class="fas fa-arrow-left"></i></a>
                </div>
                <h1><?php echo htmlspecialchars($first_name); ?>'s Assigned Tasks</h1>
                <div class="tasks-search">
                    <form method="get" action="tasks.php" id="search-form">
                        <div class="search-container">
                            <input type="text" name="search" placeholder="Search tasks" class="search-input" value="<?php echo htmlspecialchars($search_term); ?>">
                            <input type="hidden" name="show_completed" value="<?php echo $show_completed ? '1' : '0'; ?>" id="show-completed-param">
                            <button type="submit" class="search-button"><i class="fas fa-search"></i></button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="tasks-filter">
                <div class="show-completed">
                    <span>Show completed tasks</span>
                    <label class="switch">
                        <input type="checkbox" id="show-completed-toggle" <?php echo $show_completed ? 'checked' : ''; ?>>
                        <span class="slider round"></span>
                    </label>
                </div>
            </div>

            <?php if (empty($tasks)): ?>
            <div class="no-tasks-message">
                <p>You don't have any tasks assigned to you. When tasks are assigned to you, they will appear here.</p>
            </div>
            <?php else: ?>
            <div class="tasks-grid">
                <?php foreach ($tasks as $task): ?>
                    <div class="task-card <?php echo strtolower(str_replace(' ', '-', $task['status'])); ?>" data-status="<?php echo $task['status']; ?>">
                        <div class="task-header">
                            <h3><a href="view_task.php?id=<?php echo $task['task_id']; ?>"><?php echo htmlspecialchars($task['title']); ?></a></h3>
                            <button class="task-actions-btn"><i class="fas fa-ellipsis-v"></i></button>
                        </div>
                        <div class="task-details">
                            <div class="task-detail">
                                <span class="project-name">
                                    <a href="../projects/view_project.php?id=<?php echo $task['project_id']; ?>">
                                        <?php echo htmlspecialchars($task['project_name']); ?>
                                    </a>
                                </span>
                                <?php if (!empty($task['epic_title'])): ?>
                                <span class="epic-name"><?php echo htmlspecialchars($task['epic_title']); ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="task-detail">
                                <span class="due-date <?php echo $task['is_overdue'] ? 'overdue' : ''; ?>">
                                    <?php echo htmlspecialchars($task['formatted_due_date']); ?>
                                </span>
                                <span class="priority priority-<?php echo strtolower($task['priority']); ?>">
                                    <?php echo htmlspecialchars($task['priority_display']); ?>
                                </span>
                            </div>
                        </div>
                        <div class="task-footer">
                            <div class="task-status status-<?php echo strtolower($task['status']); ?>">
                                <?php echo htmlspecialchars($task['status_display']); ?>
                            </div>
                            <div class="task-assignees">
                                <?php if ($task['assignee_count'] > 0): ?>
                                    <div class="assignee-count"><?php echo $task['assignee_count']; ?> member<?php echo $task['assignee_count'] > 1 ? 's' : ''; ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
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
.tasks-container {
    padding: 20px;
}

.tasks-header {
    display: flex;
    align-items: center;
    margin-bottom: 20px;
}

.back-button {
    margin-right: 20px;
    font-size: 24px;
}

.tasks-search {
    margin-left: auto;
}

.search-container {
    position: relative;
    width: 300px;
}

.search-input {
    width: 100%;
    padding: 10px 40px 10px 15px;
    border-radius: 20px;
    border: 1px solid #ddd;
}

.search-button {
    position: absolute;
    right: 10px;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    color: #777;
}

.tasks-filter {
    display: flex;
    justify-content: flex-end;
    margin-bottom: 20px;
}

.show-completed {
    display: flex;
    align-items: center;
}

.show-completed span {
    margin-right: 10px;
}

.switch {
    position: relative;
    display: inline-block;
    width: 70px;
    height: 34px;
}

.switch input {
    opacity: 0;
    width: 0;
    height: 0;
}

.slider {
    position: absolute;
    cursor: pointer;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: #ccc;
    transition: .4s;
}

.slider:before {
    position: absolute;
    content: "";
    height: 26px;
    width: 26px;
    left: 4px;
    bottom: 4px;
    background-color: white;
    transition: .4s;
}

input:checked + .slider {
    background-color: #2196F3;
}

input:checked + .slider:before {
    transform: translateX(26px);
}

.slider.round {
    border-radius: 34px;
}

.slider.round:before {
    border-radius: 50%;
}

.tasks-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 20px;
}

.task-card {
    background-color: white;
    border-radius: 8px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    padding: 15px;
    transition: transform 0.2s;
}

.task-card:hover {
    transform: translateY(-5px);
}

.task-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 15px;
}

.task-header h3 {
    margin: 0;
    font-size: 18px;
}

.task-header h3 a {
    color: #333;
    text-decoration: none;
}

.task-header h3 a:hover {
    text-decoration: underline;
}

.task-actions-btn {
    background: none;
    border: none;
    color: #777;
    font-size: 16px;
}

.task-details {
    margin-bottom: 15px;
}

.task-detail {
    display: flex;
    justify-content: space-between;
    margin-bottom: 5px;
}

.project-name, .epic-name {
    font-size: 14px;
    color: #666;
}

.project-name a {
    color: #666;
    text-decoration: none;
}

.project-name a:hover {
    text-decoration: underline;
}

.due-date, .priority {
    font-size: 14px;
    color: #666;
}

.due-date.overdue {
    color: #dc3545;
    font-weight: bold;
}

.priority-high {
    color: #dc3545;
}

.priority-medium {
    color: #fd7e14;
}

.priority-low {
    color: #28a745;
}

.task-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.task-status {
    padding: 5px 10px;
    border-radius: 15px;
    font-size: 12px;
    font-weight: bold;
}

.status-to_do {
    background-color: #e9ecef;
    color: #495057;
}

.status-in_progress {
    background-color: #cff4fc;
    color: #055160;
}

.status-review {
    background-color: #fff3cd;
    color: #664d03;
}

.status-completed {
    background-color: #d1e7dd;
    color: #0f5132;
}

.task-assignees {
    display: flex;
}

.assignee-count {
    font-size: 12px;
    color: #666;
}

.no-tasks-message {
    text-align: center;
    margin-top: 50px;
    color: #666;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Toggle completed tasks
    const showCompletedToggle = document.getElementById('show-completed-toggle');
    const showCompletedParam = document.getElementById('show-completed-param');
    
    if (showCompletedToggle) {
        showCompletedToggle.addEventListener('change', function() {
            showCompletedParam.value = this.checked ? '1' : '0';
            document.getElementById('search-form').submit();
        });
    }

    // Logout confirmation
    const logoutBtn = document.getElementById('logout-btn');
    if (logoutBtn) {
        logoutBtn.addEventListener('click', function(e) {
            e.preventDefault();
            const logoutModal = new bootstrap.Modal(document.getElementById('logoutModal'));
            logoutModal.show();
        });
    }
});
</script>

<?php include '../../includes/footer.php'; ?> 