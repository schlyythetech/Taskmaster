<?php
require_once '../../includes/functions.php';
require_once '../../config/database.php';

// Check if task_comments table has the correct structure
try {
    // Function to check if a column exists in a table
    function columnExistsInTable($conn, $tableName, $columnName) {
        try {
            $stmt = $conn->prepare("SHOW COLUMNS FROM {$tableName} LIKE ?");
            $stmt->execute([$columnName]);
            return $stmt->rowCount() > 0;
        } catch (Exception $e) {
            return false;
        }
    }
    
    // Function to update project status based on task statuses
    function updateProjectStatus($conn, $project_id, $tasks) {
        // Check if projects table has a status column
        $stmt = $conn->prepare("
            SELECT COUNT(*) as column_exists 
            FROM information_schema.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = 'projects' 
            AND COLUMN_NAME = 'status'
        ");
        $stmt->execute();
        $statusColumnExists = (bool)$stmt->fetch()['column_exists'];
        
        if (!$statusColumnExists) {
            return; // Can't update status if column doesn't exist
        }
        
        // Count tasks by status
        $total_tasks = count($tasks['to_do']) + count($tasks['in_progress']) + 
                      count($tasks['review']) + count($tasks['completed']);
        $completed_tasks = count($tasks['completed']);
        $review_tasks = count($tasks['review']);
        $active_tasks = count($tasks['to_do']) + count($tasks['in_progress']) + count($tasks['review']);
        
        // Determine project status based on task statuses
        $new_status = 'in_progress'; // Default status
        
        if ($total_tasks > 0) {
            if ($completed_tasks == $total_tasks) {
                // All tasks are completed
                $new_status = 'completed';
            } else if ($active_tasks > 0) {
                // If any task has status of "To Do", "In Progress", or "Needs Reviewing"
                $new_status = 'in_progress';
                
                // If ALL remaining tasks are in "Needs Reviewing" status
                if ($review_tasks > 0 && $review_tasks == $active_tasks) {
                    $new_status = 'review';
                }
            }
        }
        
        // Update the project status in the database
        $stmt = $conn->prepare("
            UPDATE projects 
            SET status = ? 
            WHERE project_id = ?
        ");
        $stmt->execute([$new_status, $project_id]);
        
        return $new_status;
    }
    
    // Check if task_comments table exists
    $stmt = $conn->prepare("
        SELECT COUNT(*) as table_exists 
        FROM information_schema.tables 
        WHERE table_schema = DATABASE() 
        AND table_name = 'task_comments'
    ");
    $stmt->execute();
    $taskCommentsExists = (bool)$stmt->fetch()['table_exists'];
    
    // If table exists, check if it has the assigned_by column (which it shouldn't)
    if ($taskCommentsExists && columnExistsInTable($conn, 'task_comments', 'assigned_by')) {
        // If it has the column, remove it
        $conn->exec("ALTER TABLE task_comments DROP COLUMN assigned_by");
    }
} catch (Exception $e) {
    // Log error but continue
    error_log("Error checking task_comments table: " . $e->getMessage());
}

// Check if user is logged in
if (!isLoggedIn()) {
    setMessage("You must be logged in to view projects.", "danger");
    redirect('../auth/login.php');
}

// Check if project ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    setMessage("Invalid project ID.", "danger");
    redirect('../projects/projects.php');
}

$project_id = $_GET['id'];
$user_id = $_SESSION['user_id'];

// Generate CSRF token if it doesn't exist
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Fetch project details
try {
    // Check if user is a member of this project
    $stmt = $conn->prepare("
        SELECT pm.* FROM project_members pm
        WHERE pm.project_id = ? AND pm.user_id = ?
    ");
    $stmt->execute([$project_id, $user_id]);
    $membership = $stmt->fetch();
    
    if (!$membership) {
        setMessage("You do not have access to this project.", "danger");
        redirect('../projects/projects.php');
    }
    
    // Get project details
    $stmt = $conn->prepare("
        SELECT p.*, u.first_name, u.last_name
        FROM projects p
        JOIN users u ON p.owner_id = u.user_id
        WHERE p.project_id = ?
    ");
    $stmt->execute([$project_id]);
    $project = $stmt->fetch();
    
    if (!$project) {
        setMessage("Project not found.", "danger");
        redirect('../projects/projects.php');
    }
    
    // Get project members
    $stmt = $conn->prepare("
        SELECT pm.role, pm.status, u.user_id, u.first_name, u.last_name, u.profile_image 
        FROM project_members pm
        JOIN users u ON pm.user_id = u.user_id
        WHERE pm.project_id = ?
        ORDER BY 
            CASE pm.role 
                WHEN 'owner' THEN 1 
                WHEN 'admin' THEN 2 
                WHEN 'member' THEN 3 
            END,
            u.first_name
    ");
    $stmt->execute([$project_id]);
    $project_members = $stmt->fetchAll();
    
    // Separate active, pending, and rejected members
    $active_members = [];
    $pending_members = [];
    $rejected_members = [];

    foreach ($project_members as $member) {
        if (!isset($member['status']) || $member['status'] === 'active') {
            $active_members[] = $member;
        } else if ($member['status'] === 'pending') {
            $pending_members[] = $member;
        } else if ($member['status'] === 'rejected') {
            $rejected_members[] = $member;
        }
    }
    
    // Count total members
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM project_members WHERE project_id = ?");
    $stmt->execute([$project_id]);
    $result = $stmt->fetch();
    $total_members = $result['count'];
    
    // Get tasks grouped by status
    $task_statuses = ['to_do', 'in_progress', 'review', 'completed'];
    $tasks = [];
    
    // Check if tasks table exists
    $stmt = $conn->prepare("
        SELECT COUNT(*) as table_exists 
        FROM information_schema.tables 
        WHERE table_schema = DATABASE() 
        AND table_name = 'tasks'
    ");
    $stmt->execute();
    $tasksTableExists = (bool)$stmt->fetch()['table_exists'];
    
    // Check if task_assignees table exists
    $stmt = $conn->prepare("
        SELECT COUNT(*) as table_exists 
        FROM information_schema.tables 
        WHERE table_schema = DATABASE() 
        AND table_name = 'task_assignees'
    ");
    $stmt->execute();
    $taskAssigneesTableExists = (bool)$stmt->fetch()['table_exists'];
    
    if ($tasksTableExists) {
        foreach ($task_statuses as $status) {
            $stmt = $conn->prepare("
                SELECT t.*, e.title as epic_title, 
                       u_creator.first_name as creator_first_name, u_creator.last_name as creator_last_name,
                       u_assigned.first_name as assigned_first_name, u_assigned.last_name as assigned_last_name,
                       u_assigned.profile_image as assigned_profile_image
                FROM tasks t
                LEFT JOIN epics e ON t.epic_id = e.epic_id
                LEFT JOIN users u_creator ON t.created_by = u_creator.user_id
                LEFT JOIN users u_assigned ON t.assigned_to = u_assigned.user_id
                WHERE t.project_id = ? AND t.status = ?
                ORDER BY t.due_date ASC, t.priority DESC
            ");
            $stmt->execute([$project_id, $status]);
            $tasks[$status] = $stmt->fetchAll();
            
            // Load task assignees for each task
            if ($taskAssigneesTableExists) {
                foreach ($tasks[$status] as &$task) {
                    $assigneesStmt = $conn->prepare("
                        SELECT ta.*, u.first_name, u.last_name, u.profile_image
                        FROM task_assignees ta
                        JOIN users u ON ta.user_id = u.user_id
                        WHERE ta.task_id = ?
                    ");
                    $assigneesStmt->execute([$task['task_id']]);
                    $task['assignees'] = $assigneesStmt->fetchAll();
                }
                unset($task); // Unset the reference
            }
        }
    } else {
        // Initialize empty arrays for each status if table doesn't exist
        foreach ($task_statuses as $status) {
            $tasks[$status] = [];
        }
    }
    
    // Check if epics table exists
    $stmt = $conn->prepare("
        SELECT COUNT(*) as table_exists 
        FROM information_schema.tables 
        WHERE table_schema = DATABASE() 
        AND table_name = 'epics'
    ");
    $stmt->execute();
    $epicsTableExists = (bool)$stmt->fetch()['table_exists'];
    
    // Get epics for this project
    $epics = [];
    if ($epicsTableExists) {
        $stmt = $conn->prepare("
            SELECT * FROM epics
            WHERE project_id = ?
            ORDER BY title ASC
        ");
        $stmt->execute([$project_id]);
        $epics = $stmt->fetchAll();
    }
    
    // Update project status based on task statuses
    if ($tasksTableExists) {
        $project_status = updateProjectStatus($conn, $project_id, $tasks);
        // Update the project array with the new status
        if ($project_status) {
            $project['status'] = $project_status;
        }
    }
    
} catch (PDOException $e) {
    setMessage("Error loading project: " . $e->getMessage(), "danger");
    redirect('../projects/projects.php');
}

// Set page title
$page_title = $project['name'] . " - Task Board";
include '../../includes/header.php';

// Script to open edit modal if edit=true parameter is present
if (isset($_GET['edit']) && $_GET['edit'] === 'true') {
    echo '<script>
    document.addEventListener("DOMContentLoaded", function() {
        const editProjectModal = new bootstrap.Modal(document.getElementById("editProjectModal"));
        editProjectModal.show();
    });
    </script>';
}
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
            <a href="../projects/projects.php" class="back-link" aria-label="Go back to projects"><i class="fas fa-arrow-left"></i></a>
            <!-- Notification system is included in header.php -->
            <div class="top-nav-actions">
                <button class="btn btn-primary btn-sm invite-btn me-2" aria-label="Invite people to project">Invite</button>
                <div class="dropdown d-inline-block">
                    <button class="btn btn-sm project-menu-btn" type="button" id="projectActionsDropdown" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Project actions">
                        <i class="fas fa-ellipsis-v"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end project-dropdown-menu shadow" aria-labelledby="projectActionsDropdown">
                        <li><a class="dropdown-item edit-project-btn" href="#" aria-label="Edit project"><i class="fas fa-edit me-2"></i>Edit Project</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item leave-project-btn" href="#" aria-label="Leave project"><i class="fas fa-sign-out-alt me-2"></i>Leave Project</a></li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Project Header -->
        <div class="container-fluid mt-3">
            <div class="project-header mb-4">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <h1 class="project-title">
                            <?php echo htmlspecialchars($project['name']); ?>
                            <?php if (isset($project['status'])): ?>
                                <span class="badge <?php 
                                    if ($project['status'] === 'completed') {
                                        echo 'bg-success';
                                    } elseif ($project['status'] === 'review') {
                                        echo 'bg-warning';
                                    } else {
                                        echo 'bg-primary';
                                    }
                                ?> ms-2">
                                    <?php 
                                        if ($project['status'] === 'review') {
                                            echo 'NEEDS REVIEW';
                                        } else {
                                            echo strtoupper($project['status']);
                                        }
                                    ?>
                                </span>
                            <?php endif; ?>
                        </h1>
                        <p class="text-muted"><?php echo htmlspecialchars($project['description']); ?></p>
                    </div>
                    <div class="d-flex align-items-center">
                        <div class="member-avatars me-3">
                            <?php foreach (array_slice($active_members, 0, 3) as $member): ?>
                                <div class="member-avatar" title="<?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?>">
                                                                    <?php if (!empty($member['profile_image']) && file_exists('../../' . $member['profile_image'])): ?>
                                    <img src="../../<?php echo htmlspecialchars($member['profile_image']); ?>" alt="<?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?>">
                                <?php else: ?>
                                        <div class="default-avatar"><?php echo substr($member['first_name'], 0, 1); ?></div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                            
                            <?php if ($total_members > 3): ?>
                                <div class="member-avatar more-members" title="<?php echo $total_members - 3; ?> more members">
                                    +<?php echo $total_members - 3; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <!-- Project actions moved to top navigation -->
                    </div>
                </div>
                
                <!-- Project Navigation -->
                <div class="project-nav mt-4">
                    <ul class="nav nav-tabs">
                        <li class="nav-item">
                            <a class="nav-link active" href="#">Task Board</a>
                        </li>
                        
                    </ul>
                </div>
            </div>
            
            <!-- Task Board Controls -->
            <div class="task-board-controls d-flex justify-content-between align-items-center mb-4">
                <div>
                    <button class="btn btn-success" id="createTaskBtn">Create</button>
                </div>
                <div class="d-flex">
                    <div class="dropdown me-3">
                        <button class="btn btn-outline-secondary dropdown-toggle" type="button" id="epicFilterBtn" data-bs-toggle="dropdown" aria-expanded="false">
                            Epic <i class="fas fa-chevron-down"></i>
                        </button>
                        <ul class="dropdown-menu" aria-labelledby="epicFilterBtn">
                            <li><a class="dropdown-item" href="#">All Epics</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <?php foreach ($epics as $epic): ?>
                                <li><a class="dropdown-item" href="#"><?php echo htmlspecialchars($epic['title']); ?></a></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <div class="dropdown">
                        <button class="btn btn-outline-secondary dropdown-toggle" type="button" id="groupByBtn" data-bs-toggle="dropdown" aria-expanded="false">
                            Group By: None <i class="fas fa-chevron-down"></i>
                        </button>
                        <ul class="dropdown-menu" aria-labelledby="groupByBtn">
                            <li><a class="dropdown-item" href="#">None</a></li>
                            <li><a class="dropdown-item" href="#">Epic</a></li>
                            <li><a class="dropdown-item" href="#">Assignee</a></li>
                            <li><a class="dropdown-item" href="#">Priority</a></li>
                        </ul>
                    </div>
                </div>
            </div>
            
            <!-- Task Board -->
            <div class="task-board">
                <div class="row">
                    <!-- To Do Column -->
                    <div class="col-md-3 col-xl-3">
                        <div class="task-column">
                            <div class="task-column-header">
                                <h5>TO DO - <?php echo count($tasks['to_do']); ?> TASKS</h5>
                            </div>
                                                        <div class="task-list" id="todo-list" data-status="to_do">
                                <?php foreach ($tasks['to_do'] as $task): ?>
                                    <?php include '../../includes/task_card_template.php'; ?>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- In Progress Column -->
                    <div class="col-md-3 col-xl-3">
                        <div class="task-column">
                            <div class="task-column-header">
                                <h5>IN PROGRESS - <?php echo count($tasks['in_progress']); ?> TASKS</h5>
                            </div>
                                                        <div class="task-list" id="inprogress-list" data-status="in_progress">
                                <?php foreach ($tasks['in_progress'] as $task): ?>
                                    <?php include '../../includes/task_card_template.php'; ?>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Needs Reviewing Column -->
                    <div class="col-md-3 col-xl-3">
                        <div class="task-column">
                            <div class="task-column-header">
                                <h5>NEEDS REVIEWING - <?php echo count($tasks['review']); ?> TASKS</h5>
                            </div>
                                                        <div class="task-list" id="review-list" data-status="review">
                                <?php foreach ($tasks['review'] as $task): ?>
                                    <?php include '../../includes/task_card_template.php'; ?>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Completed Column -->
                    <div class="col-md-3 col-xl-3">
                        <div class="task-column">
                            <div class="task-column-header">
                                <h5>COMPLETED - <?php echo count($tasks['completed']); ?> TASKS</h5>
                            </div>
                                                        <div class="task-list" id="completed-list" data-status="completed">
                                <?php foreach ($tasks['completed'] as $task): ?>
                                    <?php include '../../includes/task_card_template.php'; ?>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Removing Team Members Section as requested -->

<!-- Create Task Modal -->
<div class="modal fade" id="createTaskModal" tabindex="-1" aria-labelledby="createTaskModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="createTaskModalLabel">Create Task</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="createTaskForm">
                    <input type="hidden" name="project_id" value="<?php echo $project_id; ?>">
                    <input type="hidden" name="created_by" value="<?php echo $user_id; ?>">
                    
                    <div class="mb-3">
                        <label for="taskTitle" class="form-label">Title<span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="taskTitle" name="title" required>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="taskDescription" class="form-label">Description<span class="text-danger">*</span></label>
                            <textarea class="form-control" id="taskDescription" name="description" rows="5" required></textarea>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="taskDueDate" class="form-label">Due Date<span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="taskDueDate" name="due_date" required>
                            </div>
                            
                            <div class="mb-3">
                                <label for="taskPriority" class="form-label">Priority<span class="text-danger">*</span></label>
                                <select class="form-control" id="taskPriority" name="priority" required>
                                    <option value="low">Low</option>
                                    <option value="medium" selected>Medium</option>
                                    <option value="high">High</option>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label for="taskStatus" class="form-label">Status<span class="text-danger">*</span></label>
                                <select class="form-control" id="taskStatus" name="status" required>
                                    <option value="to_do" selected>To Do</option>
                                    <option value="in_progress">In Progress</option>
                                    <option value="review">Review</option>
                                    <option value="completed">Completed</option>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label for="taskAssignees" class="form-label">Assignees<span class="text-danger">*</span></label>
                                <select class="form-control" id="taskAssignees" name="assigned_to[]" multiple required>
                                    <?php foreach ($active_members as $member): ?>
                                        <?php if ($member['user_id'] != $user_id): // Exclude current user ?>
                                            <option value="<?php echo $member['user_id']; ?>"><?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?></option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </select>
                                <small class="form-text text-muted">Hold Ctrl/Cmd to select multiple assignees</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="taskEpic" class="form-label">Epic<span class="text-danger">*</span></label>
                        <div class="dropdown">
                            <button class="btn btn-outline-secondary dropdown-toggle text-start w-25" type="button" id="epicDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                                Select Epic
                            </button>
                            <ul class="dropdown-menu" aria-labelledby="epicDropdown">
                                <?php foreach ($epics as $epic): ?>
                                    <li><a class="dropdown-item" href="#" data-value="<?php echo $epic['epic_id']; ?>"><?php echo htmlspecialchars($epic['title']); ?></a></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <input type="hidden" id="taskEpic" name="epic_id" value="" required>
                        <div id="epicError" class="invalid-feedback" style="display: none;">Please select an Epic</div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-link text-dark" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="saveTaskBtn">Create</button>
            </div>
        </div>
    </div>
</div>

<!-- Create Epic Modal -->
<div class="modal fade" id="createEpicModal" tabindex="-1" aria-labelledby="createEpicModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="createEpicModalLabel">Create Epic</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="createEpicForm">
                    <input type="hidden" name="project_id" value="<?php echo $project_id; ?>">
                    <input type="hidden" name="created_by" value="<?php echo $user_id; ?>">
                    
                    <div class="mb-3">
                        <label for="epicName" class="form-label">Epic Name</label>
                        <input type="text" class="form-control" id="epicName" name="title" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="epicDescription" class="form-label">Description</label>
                        <textarea class="form-control" id="epicDescription" name="description" rows="3"></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label for="epicStatus" class="form-label">Status</label>
                        <select class="form-control" id="epicStatus" name="status">
                            <option value="not_started" selected>Not Started</option>
                            <option value="in_progress">In Progress</option>
                            <option value="completed">Completed</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label for="epicColor" class="form-label">Epic Colour</label>
                        <div class="dropdown">
                            <button class="btn btn-outline-secondary dropdown-toggle color-dropdown" type="button" id="colorDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                                <span class="color-preview" style="background-color: #ff6b6b;"></span>
                            </button>
                            <ul class="dropdown-menu color-dropdown-menu" aria-labelledby="colorDropdown">
                                <li><a class="dropdown-item" href="#" data-color="#ff6b6b"><span class="color-option" style="background-color: #ff6b6b;"></span></a></li>
                                <li><a class="dropdown-item" href="#" data-color="#51cf66"><span class="color-option" style="background-color: #51cf66;"></span></a></li>
                                <li><a class="dropdown-item" href="#" data-color="#339af0"><span class="color-option" style="background-color: #339af0;"></span></a></li>
                                <li><a class="dropdown-item" href="#" data-color="#fcc419"><span class="color-option" style="background-color: #fcc419;"></span></a></li>
                                <li><a class="dropdown-item" href="#" data-color="#be4bdb"><span class="color-option" style="background-color: #be4bdb;"></span></a></li>
                            </ul>
                        </div>
                        <input type="hidden" id="epicColor" name="color" value="#ff6b6b">
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-link text-dark" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="saveEpicBtn">Create</button>
            </div>
        </div>
    </div>
</div>

<!-- Invite People Modal -->
<div class="modal fade" id="inviteModal" tabindex="-1" aria-labelledby="inviteModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="inviteModalLabel">Invite People</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="inviteForm">
                    <div class="mb-3">
                        <div class="input-group">
                            <input type="text" class="form-control rounded-pill" id="inviteEmail" name="email" placeholder="Type a name or email address">
                            <span class="input-group-text search-icon border-0 bg-transparent"><i class="fas fa-search"></i></span>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-link text-dark" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="sendInviteBtn">Invite</button>
            </div>
        </div>
    </div>
</div>

<!-- Task or Epic Selection Modal -->
<div class="modal fade" id="createOptionsModal" tabindex="-1" aria-labelledby="createOptionsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-body p-0">
                <div class="d-grid">
                    <button class="btn btn-lg p-3 text-start create-option" id="createTaskOption">
                        <div class="d-flex align-items-center">
                            <div class="option-icon task-icon me-2">
                                <i class="fas fa-check-square"></i>
                            </div>
                            <div>Task</div>
                        </div>
                    </button>
                    <button class="btn btn-lg p-3 text-start create-option" id="createEpicOption">
                        <div class="d-flex align-items-center">
                            <div class="option-icon epic-icon me-2">
                                <i class="fas fa-layer-group"></i>
                            </div>
                            <div>Epic</div>
                        </div>
                    </button>
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
                <a href="logout.php" class="btn btn-primary">Logout</a>
            </div>
        </div>
    </div>
</div>

<!-- Edit Project Modal -->
<div class="modal fade" id="editProjectModal" tabindex="-1" aria-labelledby="editProjectModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editProjectModalLabel">Edit <?php echo htmlspecialchars($project['name']); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="editProjectForm" method="post" action="update_project.php" enctype="multipart/form-data">
                    <input type="hidden" name="project_id" value="<?php echo $project_id; ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    
                    <div class="text-center mb-3">
                        <div class="project-icon-large mx-auto">
                            <?php if (!empty($project['icon']) && file_exists('../../' . $project['icon'])): ?>
                                <img src="../../<?php echo htmlspecialchars($project['icon']); ?>" alt="Project Icon" id="projectIconPreview">
                            <?php else: ?>
                                <div class="default-icon" id="projectIconPreview">
                                    <i class="fas fa-cube"></i>
                                </div>
                            <?php endif; ?>
                        </div>
                        <label for="projectIcon" class="btn btn-info mt-2">Change Icon</label>
                        <input type="file" id="projectIcon" name="project_icon" accept="image/*" style="display: none;">
                    </div>
                    
                    <div class="mb-3">
                        <label for="projectName" class="form-label">Project Name</label>
                        <input type="text" class="form-control" id="projectName" name="name" value="<?php echo htmlspecialchars($project['name']); ?>" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="projectType" class="form-label">Project Type</label>
                        <input type="text" class="form-control" id="projectType" name="description" value="<?php echo htmlspecialchars($project['description']); ?>">
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-link text-dark" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="saveProjectBtn">Save</button>
            </div>
        </div>
    </div>
</div>

<!-- Leave Project Modal -->
<div class="modal fade" id="leaveProjectModal" tabindex="-1" aria-labelledby="leaveProjectModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="leaveProjectModalLabel">Request to Leave <?php echo htmlspecialchars($project['name']); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>To leave <strong><?php echo htmlspecialchars($project['name']); ?></strong>, a request must be sent to the project owner.</p>
                <p>Send a request to leave?</p>
            </div>
            <div class="modal-footer justify-content-end">
                <button type="button" class="btn btn-link text-dark" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirmLeaveBtn">Send</button>
            </div>
        </div>
    </div>
</div>

<!-- View Task Modal -->
<div class="modal fade" id="viewTaskModal" tabindex="-1" aria-labelledby="viewTaskModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div class="d-flex align-items-center">
                    <button class="icon-button star-button me-2">
                        <i class="far fa-star"></i>
                    </button>
                    <h5 class="modal-title" id="taskOverlayTitle">&lt;TaskName&gt;</h5>
                </div>
                <div class="d-flex align-items-center">
                    <button class="icon-button edit-button me-2" id="editTaskButton" style="display: none;">
                        <i class="fas fa-edit"></i>
                    </button>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
            </div>
            <div class="modal-body">
                <div class="task-details-container">
                    <div class="row">
                        <div class="col-md-8">
                            <div class="detail-section">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <h3>Description</h3>
                                    <button id="editDescriptionBtn" class="btn btn-sm btn-outline-secondary" style="display: none;">
                                        <i class="fas fa-pencil-alt"></i> Edit
                                    </button>
                                </div>
                                <div class="description-container">
                                    <div id="descriptionDisplay" class="description-display"></div>
                                    <div id="descriptionEditor" class="description-editor" style="display: none;">
                                        <textarea id="descriptionTextarea" class="form-control" rows="5" placeholder="Enter task description here..."></textarea>
                                        <div class="editor-actions mt-2">
                                            <button id="saveDescriptionBtn" class="btn btn-sm btn-primary">
                                                <i class="fas fa-save"></i> Save
                                            </button>
                                            <button id="cancelDescriptionBtn" class="btn btn-sm btn-secondary">
                                                <i class="fas fa-times"></i> Cancel
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="detail-section">
                                <h3>Attachments</h3>
                                <div class="attachments-container" id="taskAttachments">
                                    <!-- Attachments will be dynamically loaded here -->
                                </div>
                                <form id="attachmentUploadForm" enctype="multipart/form-data">
                                    <div class="d-none">
                                        <input type="file" id="attachmentInput" name="attachment" class="form-control">
                                        <input type="hidden" id="attachmentTaskId" name="task_id">
                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    </div>
                                </form>
                                <div class="mt-2">
                                    <button class="btn btn-sm btn-outline-primary" id="attachFileBtn" style="display: none;">
                                        <i class="fas fa-paperclip"></i> Attach File
                                    </button>
                                </div>
                            </div>
                            
                            <div class="detail-section">
                                <h3>Comments</h3>
                                <div class="comments-container">
                                    <div class="comment-form">
                                        <div class="commenter-avatar">
                                            <?php if (!empty($_SESSION['profile_image']) && file_exists('../../' . $_SESSION['profile_image'])): ?>
                                                <img src="../../<?php echo htmlspecialchars($_SESSION['profile_image']); ?>" alt="Your avatar">
                                            <?php else: ?>
                                                <div class="default-avatar">
                                                    <?php echo !empty($_SESSION['first_name']) ? substr($_SESSION['first_name'], 0, 1) : '<i class="fas fa-user"></i>'; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="comment-input-wrapper">
                                            <input type="text" class="comment-input" id="commentInput" placeholder="Add a comment...">
                                            <button class="btn btn-primary comment-submit" id="postCommentBtn">
                                                <i class="fas fa-paper-plane"></i>
                                            </button>
                                            <div class="comment-loading-indicator">
                                                <i class="fas fa-circle-notch fa-spin"></i>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="comments-section">
                                        <div class="comments-count">
                                            <i class="fas fa-comments"></i>
                                            <span id="commentsCounter">0 Comments</span>
                                        </div>
                                        <div class="comments-list" id="commentsList">
                                            <!-- Comments will be loaded dynamically -->
                                        </div>
                                        <div class="no-comments-message" style="display: none;">
                                            <div class="empty-comments-icon">
                                                <i class="far fa-comments"></i>
                                            </div>
                                            <p>No comments yet</p>
                                            <span>Be the first to share your thoughts!</span>
                                        </div>
                                        <div class="comments-loading">
                                            <div class="spinner">
                                                <i class="fas fa-circle-notch fa-spin"></i>
                                            </div>
                                            <span>Loading comments...</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-4">
                            <div class="task-details-right">
                                <div class="detail-item">
                                    <span class="detail-label">Assignee(s)</span>
                                    <div class="detail-value assignees-container">
                                        <div class="assignee-avatar">
                                            <div class="default-avatar">
                                                <i class="fas fa-user"></i>
                                            </div>
                                        </div>
                                        <div class="assignee-avatar">
                                            <div class="default-avatar">
                                                <i class="fas fa-user"></i>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="detail-item">
                                    <span class="detail-label">Task Owner</span>
                                    <div class="detail-value task-owner-container d-flex align-items-center">
                                        <div class="owner-avatar me-2">
                                            <div class="default-avatar">
                                                <i class="fas fa-user"></i>
                                            </div>
                                        </div>
                                        <span id="taskOwnerName">&lt;Owner&gt;</span>
                                    </div>
                                </div>
                                
                                <div class="detail-item">
                                    <span class="detail-label">Priority</span>
                                    <span class="detail-value priority-badge" id="taskPriorityBadge">&lt;Priority&gt;</span>
                                </div>
                                
                                <div class="detail-item">
                                    <span class="detail-label">Epic</span>
                                    <span class="detail-value" id="taskEpicName">&lt;Epic&gt;</span>
                                </div>
                                
                                <div class="detail-item">
                                    <span class="detail-label">Created</span>
                                    <span class="detail-value" id="taskCreationDate">&lt;CreationDate&gt;</span>
                                </div>
                                
                                <div class="detail-item">
                                    <span class="detail-label">Updated</span>
                                    <span class="detail-value" id="taskUpdatedDate">&lt;UpdatedDate&gt;</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.top-nav {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0 10px;
}

.back-link {
    font-size: 1.2rem;
    color: #333;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    border-radius: 50%;
    transition: background-color 0.2s;
}

.back-link:hover {
    background-color: rgba(0,0,0,0.05);
    color: #007bff;
}

.top-nav-actions {
    display: flex;
    align-items: center;
    gap: 5px;
}

.top-nav .invite-btn {
    font-size: 0.9rem;
    padding: 6px 12px;
    border-radius: 6px;
}

.project-header {
    padding-bottom: 15px;
    border-bottom: 1px solid #e9ecef;
}

.project-title {
    font-size: 1.8rem;
    margin-bottom: 5px;
}

.member-avatars {
    display: flex;
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
    color: #666;
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
    background: linear-gradient(135deg, #6a11cb 0%, #2575fc 100%);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 500;
    text-shadow: 0 1px 2px rgba(0,0,0,0.2);
    border-radius: 50%;
}

.more-members {
    background-color: #e9ecef;
    font-size: 12px;
}

.project-nav .nav-link {
    color: #6c757d;
    font-weight: 500;
    padding: 10px 15px;
}

.project-nav .nav-link.active {
    color: #007bff;
    border-bottom: 2px solid #007bff;
}

.task-board {
    margin-top: 20px;
}

.task-column {
    background-color: #f8f9fa;
    border-radius: 6px;
    padding: 15px;
    height: 100%;
}

.task-column-header {
    margin-bottom: 15px;
}

.task-column-header h5 {
    font-size: 0.9rem;
    font-weight: 600;
    color: #495057;
    margin: 0;
}

.task-list {
    min-height: 50px;
}

.task-card {
    background-color: #fff;
    border-radius: 10px;
    margin-bottom: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    cursor: pointer;
    transition: transform 0.2s, box-shadow 0.2s;
    border: 1px solid #f0f0f0;
    overflow: hidden;
    position: relative;
}

.task-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.12);
}

.task-card-content {
    padding: 14px;
    padding-bottom: 40px; /* Make room for status indicator */
}

.epic-tag {
    display: inline-flex;
    align-items: center;
    background-color: #f0f7ff;
    color: #0066cc;
    font-size: 0.7rem;
    padding: 3px 10px;
    border-radius: 20px;
    margin-bottom: 10px;
    font-weight: 500;
    border: 1px solid rgba(0,102,204,0.2);
}

.task-title {
    font-size: 0.95rem;
    margin-bottom: 12px;
    font-weight: 600;
    color: #333;
    line-height: 1.4;
}

.task-meta {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
    font-size: 0.8rem;
    padding-bottom: 10px;
}

.due-date {
    color: #6c757d;
    display: flex;
    align-items: center;
    background-color: #f8f9fa;
    padding: 3px 8px;
    border-radius: 4px;
    font-size: 0.75rem;
}

.priority-badge {
    padding: 3px 10px;
    border-radius: 20px;
    font-size: 0.7rem;
    font-weight: 500;
    display: flex;
    align-items: center;
}

.priority-low {
    background-color: #e8f5e9;
    color: #2e7d32;
    border: 1px solid rgba(46,125,50,0.2);
}

.priority-medium {
    background-color: #fff8e1;
    color: #ff8f00;
    border: 1px solid rgba(255,143,0,0.2);
}

.priority-high {
    background-color: #ffebee;
    color: #c62828;
    border: 1px solid rgba(198,40,40,0.2);
}

.task-assignees {
    display: flex;
    justify-content: flex-end;
    align-items: center;
}

.assignee-avatar {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    margin-left: -6px;
    overflow: hidden;
    position: relative;
    border: 2px solid #fff;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    transition: transform 0.2s;
}

.assignee-avatar:first-child {
    margin-left: 0;
}

.assignee-avatar:hover {
    transform: translateY(-2px);
    z-index: 2;
}

.assignee-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: 50%;
}

.more-assignees {
    background-color: #e9ecef;
    color: #495057;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.7rem;
    font-weight: bold;
}

/* Create Options Modal */
.create-option {
    transition: background-color 0.2s;
}

.create-option:hover {
    background-color: #f8f9fa;
}

.option-icon {
    width: 40px;
    height: 40px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
}

.task-icon {
    background-color: #a5d8ff;
    color: #1971c2;
}

.epic-icon {
    background-color: #b197fc;
    color: #6741d9;
}

/* Color picker */
.color-dropdown {
    padding: 8px 12px;
}

.color-preview {
    display: inline-block;
    width: 24px;
    height: 24px;
    border-radius: 4px;
}

.color-option {
    display: inline-block;
    width: 24px;
    height: 24px;
    border-radius: 4px;
}

.search-icon {
    position: absolute;
    right: 10px;
    top: 50%;
    transform: translateY(-50%);
    z-index: 10;
    color: #aaa;
}

#inviteEmail {
    padding-right: 40px;
}

.project-icon-large {
    width: 100px;
    height: 100px;
    border-radius: 50%;
    background-color: #f0f0f0;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
}

.project-icon-large img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

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

.project-actions .dropdown-toggle::after {
    display: none;
}

/* Owner Warning Tooltip Styles */
.owner-warning-tooltip {
    position: absolute;
    z-index: 2000;
    width: 320px;
    background-color: white;
    border-radius: 8px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.15);
    opacity: 0;
    transform: translateY(10px);
    transition: opacity 0.3s ease, transform 0.3s ease;
    overflow: hidden;
    pointer-events: auto;
    border: 1px solid rgba(0,0,0,0.1);
}

.owner-warning-tooltip.show {
    opacity: 1;
    transform: translateY(0);
}

.owner-warning-header {
    background-color: #dc3545;
    color: white;
    padding: 12px 15px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-weight: 600;
}

.warning-icon {
    display: flex;
    align-items: center;
    gap: 8px;
}

.warning-icon i {
    font-size: 18px;
}

.close-tooltip {
    background: none;
    border: none;
    color: white;
    font-size: 16px;
    cursor: pointer;
    padding: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 24px;
    height: 24px;
    border-radius: 50%;
    transition: background-color 0.2s;
}

.close-tooltip:hover {
    background-color: rgba(255,255,255,0.2);
}

.owner-warning-body {
    padding: 15px;
    color: #343a40;
    line-height: 1.5;
    font-size: 0.95rem;
}

.task-list {
    min-height: 50px;
    padding: 5px;
}

.task-column .task-list.sortable-ghost {
    background-color: rgba(0,0,0,0.05);
    border-radius: 6px;
}

.task-column .task-list.sortable-drag {
    opacity: 0.9;
}

/* Task Modal Styles */
.icon-button {
    background: none;
    border: none;
    cursor: pointer;
    font-size: 1.2rem;
    color: #6c757d;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 5px;
    border-radius: 4px;
    transition: background-color 0.2s, color 0.2s;
}

.icon-button:hover {
    background-color: #f8f9fa;
    color: #495057;
}

.star-button {
    color: #adb5bd;
}

.star-button.active {
    color: #ffc107;
}

#taskOverlayTitle {
    font-size: 1.5rem;
    margin: 0;
    font-weight: 600;
}

.delete-task-btn {
    width: 28px;
    height: 28px;
    padding: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 4px;
    transition: background-color 0.2s;
}

.task-details-container {
    width: 100%;
}

.task-details-right {
    border-left: 1px solid #e9ecef;
    padding-left: 20px;
}

#viewTaskModal .modal-dialog {
    max-width: 90%;
}

#viewTaskModal .modal-content {
    min-height: 80vh;
}

/* Loading state for the body */
body.loading {
    position: relative;
}

body.loading::after {
    content: "";
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: rgba(255, 255, 255, 0.5);
    z-index: 9999;
}

body.loading::before {
    content: "";
    position: fixed;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    width: 50px;
    height: 50px;
    border: 5px solid #f3f3f3;
    border-top: 5px solid #007bff;
    border-radius: 50%;
    animation: spin 1s linear infinite;
    z-index: 10000;
}

@keyframes spin {
    0% { transform: translate(-50%, -50%) rotate(0deg); }
    100% { transform: translate(-50%, -50%) rotate(360deg); }
}

.detail-section {
    margin-bottom: 30px;
}

.detail-section h3 {
    font-size: 1rem;
    font-weight: 600;
    margin-bottom: 10px;
    color: #343a40;
}

/* Task description is now handled by .description-display */

.attachments-container {
    display: flex;
    gap: 10px;
    margin-bottom: 10px;
}

.attachment-item {
    width: 100px;
    height: 80px;
    background-color: #f8f9fa;
    border: 1px dashed #ced4da;
    border-radius: 4px;
}

.action-button {
    background: none;
    border: 1px solid #ced4da;
    border-radius: 4px;
    padding: 6px 12px;
    font-size: 0.9rem;
    color: #495057;
    cursor: pointer;
    transition: background-color 0.2s;
}

.action-button:hover {
    background-color: #f8f9fa;
}

.comments-container {
    display: flex;
    flex-direction: column;
    gap: 15px;
}

.comment-form {
    display: flex;
    gap: 10px;
    align-items: flex-start;
    width: 100%;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 1px solid #e9ecef;
}

.commenter-avatar {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    overflow: hidden;
    flex-shrink: 0;
    background-color: #f8f9fa;
    border: 1px solid #e9ecef;
}

.commenter-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.comment-input {
    flex: 1;
    border: 1px solid #ced4da;
    border-radius: 4px;
    padding: 8px 12px;
    font-size: 0.9rem;
    margin-right: 8px;
    transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
}

.comment-input:focus {
    border-color: #80bdff;
    box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
    outline: 0;
}

.comment-submit {
    align-self: center;
    flex-shrink: 0;
    min-width: 60px;
}

.comments-list {
    display: flex;
    flex-direction: column;
    gap: 15px;
    max-height: 350px;
    overflow-y: auto;
    padding-right: 5px;
}

.comments-list::-webkit-scrollbar {
    width: 5px;
}

.comments-list::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 10px;
}

.comments-list::-webkit-scrollbar-thumb {
    background: #c1c1c1;
    border-radius: 10px;
}

.comments-list::-webkit-scrollbar-thumb:hover {
    background: #a8a8a8;
}

.comment-item {
    display: flex;
    gap: 10px;
    align-items: flex-start;
    padding: 10px;
    border-radius: 8px;
    transition: background-color 0.2s;
}

.comment-item:hover {
    background-color: #f8f9fa;
}

.comment-content {
    flex: 1;
}

.comment-header {
    display: flex;
    justify-content: space-between;
    margin-bottom: 5px;
}

.commenter-name {
    font-weight: 600;
    font-size: 0.9rem;
    color: #343a40;
}

.comment-time {
    color: #6c757d;
    font-size: 0.8rem;
}

.comment-text {
    margin: 0;
    font-size: 0.9rem;
    color: #495057;
    line-height: 1.5;
    word-break: break-word;
}

.no-comments-message {
    text-align: center;
    padding: 20px;
    font-size: 0.9rem;
    font-style: italic;
    color: #6c757d;
    background-color: #f8f9fa;
    border-radius: 8px;
}

.detail-item {
    margin-bottom: 15px;
    display: flex;
    flex-direction: column;
}

.detail-label {
    font-size: 0.85rem;
    color: #6c757d;
    margin-bottom: 5px;
}

.detail-value {
    font-size: 0.95rem;
    color: #212529;
}

.assignees-container {
    display: flex;
    gap: 5px;
    position: relative;
}

.assignee-count {
    position: absolute;
    right: -10px;
    top: -10px;
    background-color: #007bff;
    color: white;
    border-radius: 50%;
    width: 20px;
    height: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.75rem;
    font-weight: bold;
}

.task-overlay.show {
    opacity: 1;
    visibility: visible;
}

.task-overlay.show .task-overlay-content {
    transform: translateY(0);
}

/* Priority badge inside task overlay */
.task-details-right .priority-badge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 12px;
    font-size: 0.8rem;
    font-weight: 500;
}

/* Task footer with actions */
.task-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 10px;
}

.task-actions {
    display: flex;
    align-items: center;
}

.task-actions .btn {
    border-radius: 6px;
    transition: all 0.2s;
}

.task-actions .btn-danger {
    background-color: rgba(220, 53, 69, 0.1);
    color: #dc3545;
    border: none;
}

.task-actions .btn-danger:hover {
    background-color: rgba(220, 53, 69, 0.2);
    color: #dc3545;
}

.task-actions .dropdown-toggle {
    padding: 0.25rem 0.5rem;
    font-size: 0.75rem;
    background-color: #f8f9fa;
    border-color: #f0f0f0;
}

.task-actions .dropdown-toggle:hover {
    background-color: #e9ecef;
}

.task-actions .dropdown-toggle::after {
    display: none;
}

.status-dropdown {
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    border: none;
    border-radius: 8px;
    overflow: hidden;
    padding: 4px;
}

.status-dropdown .dropdown-item {
    font-size: 0.85rem;
    padding: 0.5rem 1rem;
    border-radius: 4px;
    margin-bottom: 2px;
}

.status-dropdown .dropdown-item:last-child {
    margin-bottom: 0;
}

.status-dropdown .dropdown-item:hover {
    background-color: #f8f9fa;
}

.status-dropdown .dropdown-item i {
    font-size: 0.7rem;
}

.attachments-container {
    max-height: 250px;
    overflow-y: auto;
    margin-bottom: 10px;
    padding: 10px;
    background-color: #f9f9f9;
    border-radius: 5px;
}

.attachment-item {
    width: 100%;
    height: auto;
    background-color: #f8f9fa;
    border: 1px solid #ced4da;
    border-radius: 4px;
    margin-bottom: 8px;
    padding: 10px;
    display: flex;
    align-items: center;
    transition: transform 0.2s, box-shadow 0.2s;
}

.attachment-item:hover {
    transform: translateY(-2px);
    box-shadow: 0 3px 6px rgba(0,0,0,0.08);
}

.attachment-item.error {
    background-color: #fff8f8;
    border-color: #ffcdd2;
}

.attachment-item.error .attachment-name {
    color: #dc3545;
}

.attachment-item.uploading {
    background-color: #e9f4ff;
    border-color: #b8daff;
}

.attachment-icon {
    font-size: 24px;
    color: #6c757d;
    margin-right: 12px;
    flex-shrink: 0;
    width: 40px;
    text-align: center;
}

.attachment-details {
    flex: 1;
    overflow: hidden;
}

.attachment-name {
    font-weight: 500;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    margin-bottom: 3px;
}

.attachment-meta {
    font-size: 0.8rem;
    color: #6c757d;
    display: flex;
    gap: 10px;
}

.attachment-actions {
    display: flex;
    gap: 10px;
    margin-left: 10px;
}

.attachment-download, .attachment-delete {
    background: none;
    border: none;
    color: #6c757d;
    cursor: pointer;
    padding: 5px;
    transition: color 0.2s;
}

.attachment-download:hover {
    color: #007bff;
}

.attachment-download.disabled {
    cursor: not-allowed;
    opacity: 0.5;
}

.attachment-download.disabled:hover {
    color: #6c757d;
}

.attachment-delete:hover {
    color: #dc3545;
}

.attachment-item.uploading {
    background-color: #e9f4ff;
    border-color: #b8daff;
}

.loading-spinner {
    text-align: center;
    padding: 10px;
    color: #6c757d;
}

/* Task description styling */
.description-container {
    position: relative;
    margin-bottom: 20px;
}

.description-display {
    min-height: 100px;
    white-space: pre-wrap;
    word-break: break-word;
    padding: 16px;
    border-radius: 8px;
    background-color: #f8f9fa;
    border: 1px solid #e9ecef;
    transition: all 0.3s ease;
    line-height: 1.6;
    color: #212529;
}

.description-display:empty::before {
    content: "No description provided";
    color: #adb5bd;
    font-style: italic;
}

#editDescriptionBtn {
    transition: all 0.2s ease;
}

#editDescriptionBtn:hover {
    background-color: #e9ecef;
}

.description-editor {
    background-color: #fff;
    border-radius: 8px;
    padding: 15px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    border: 1px solid #dee2e6;
    transition: all 0.3s ease;
    transform-origin: top;
}

.editor-active {
    animation: fadeIn 0.3s ease;
}

@keyframes fadeIn {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

#descriptionTextarea {
    min-height: 150px;
    resize: vertical;
    padding: 12px;
    border: 1px solid #ced4da;
    border-radius: 4px;
    font-family: inherit;
    font-size: inherit;
    line-height: 1.6;
    transition: border-color 0.2s, box-shadow 0.2s;
}

#descriptionTextarea:focus {
    outline: none;
    border-color: #80bdff;
    box-shadow: 0 0 0 0.25rem rgba(0, 123, 255, 0.25);
}

.editor-actions {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    margin-top: 15px;
}

.comment-form {
    display: flex;
    gap: 10px;
    align-items: flex-start;
    margin-bottom: 15px;
}

/* Empty state is now handled by .description-display:empty::before */

/* Comment section styling */
.comments-container {
    display: flex;
    flex-direction: column;
    gap: 15px;
}

.comment-form {
    display: flex;
    gap: 10px;
    align-items: flex-start;
    width: 100%;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 1px solid #e9ecef;
}

.commenter-avatar {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    overflow: hidden;
    flex-shrink: 0;
    background-color: #f8f9fa;
    border: 1px solid #e9ecef;
}

.commenter-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.comment-input {
    flex: 1;
    border: 1px solid #ced4da;
    border-radius: 4px;
    padding: 8px 12px;
    font-size: 0.9rem;
    margin-right: 8px;
    transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
}

.comment-input:focus {
    border-color: #80bdff;
    box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
    outline: 0;
}

.comment-submit {
    align-self: center;
    flex-shrink: 0;
    min-width: 60px;
}

.comments-list {
    display: flex;
    flex-direction: column;
    gap: 15px;
    max-height: 350px;
    overflow-y: auto;
    padding-right: 5px;
}

.comments-list::-webkit-scrollbar {
    width: 5px;
}

.comments-list::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 10px;
}

.comments-list::-webkit-scrollbar-thumb {
    background: #c1c1c1;
    border-radius: 10px;
}

.comments-list::-webkit-scrollbar-thumb:hover {
    background: #a8a8a8;
}

.comment-item {
    display: flex;
    gap: 10px;
    align-items: flex-start;
    padding: 10px;
    border-radius: 8px;
    transition: background-color 0.2s;
}

.comment-item:hover {
    background-color: #f8f9fa;
}

.comment-content {
    flex: 1;
}

.comment-header {
    display: flex;
    justify-content: space-between;
    margin-bottom: 5px;
}

.commenter-name {
    font-weight: 600;
    font-size: 0.9rem;
    color: #343a40;
}

.comment-time {
    color: #6c757d;
    font-size: 0.8rem;
}

.comment-text {
    margin: 0;
    font-size: 0.9rem;
    color: #495057;
    line-height: 1.5;
    word-break: break-word;
}

.no-comments-message {
    text-align: center;
    padding: 20px;
    font-size: 0.9rem;
    font-style: italic;
    color: #6c757d;
    background-color: #f8f9fa;
    border-radius: 8px;
}

/* New Comment section styling */
.comments-container {
    display: flex;
    flex-direction: column;
    gap: 20px;
    background-color: #f8f9fa;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}

.comment-form {
    display: flex;
    gap: 12px;
    align-items: flex-start;
    width: 100%;
    margin-bottom: 5px;
}

.commenter-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    overflow: hidden;
    flex-shrink: 0;
    background-color: #fff;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    border: 2px solid #fff;
}

.commenter-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.commenter-avatar .default-avatar {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    background-color: #007bff;
    color: white;
    font-weight: 500;
}

.comment-input-wrapper {
    position: relative;
    flex: 1;
    display: flex;
    align-items: center;
    background-color: #fff;
    border-radius: 24px;
    padding: 4px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    transition: box-shadow 0.2s;
}

.comment-input-wrapper:focus-within {
    box-shadow: 0 0 0 2px rgba(0,123,255,0.25);
}

.comment-input {
    flex: 1;
    border: none;
    border-radius: 24px;
    padding: 10px 16px;
    font-size: 0.95rem;
    background: transparent;
    outline: none !important;
}

.comment-submit {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    padding: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 4px;
    transition: background-color 0.2s;
}

.comment-submit i {
    font-size: 0.9rem;
}

.comment-submit:disabled {
    background-color: #e9ecef;
    color: #adb5bd;
    cursor: not-allowed;
}

.comment-loading-indicator {
    position: absolute;
    right: 14px;
    color: #007bff;
    display: none;
}

.comments-section {
    position: relative;
}

.comments-count {
    display: flex;
    align-items: center;
    gap: 8px;
    color: #495057;
    font-size: 0.95rem;
    font-weight: 500;
    margin-bottom: 12px;
    padding-bottom: 12px;
    border-bottom: 1px solid #e9ecef;
}

.comments-count i {
    color: #6c757d;
}

.comments-list {
    display: flex;
    flex-direction: column;
    gap: 16px;
    max-height: 350px;
    overflow-y: auto;
    padding-right: 8px;
}

.comments-list::-webkit-scrollbar {
    width: 4px;
}

.comments-list::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 10px;
}

.comments-list::-webkit-scrollbar-thumb {
    background: #c1c1c1;
    border-radius: 10px;
}

.comments-list::-webkit-scrollbar-thumb:hover {
    background: #a8a8a8;
}

.comment-item {
    display: flex;
    position: relative;
    overflow: hidden;
    transition: opacity 0.3s, transform 0.3s;
}

.comment-item.deleting {
    opacity: 0;
    transform: translateX(100%);
}

.swipe-container {
    display: flex;
    width: 100%;
    position: relative;
    overflow: hidden;
    gap: 12px;
    padding: 12px;
    border-radius: 12px;
    background-color: #fff;
    box-shadow: 0 1px 2px rgba(0,0,0,0.05);
    transition: transform 0.2s, box-shadow 0.2s;
}

.comment-wrapper {
    display: flex;
    width: 100%;
    gap: 12px;
    transition: transform 0.3s ease;
    z-index: 2;
    background-color: #fff;
}

.delete-action {
    position: absolute;
    right: 0;
    top: 0;
    bottom: 0;
    width: 80px;
    background-color: #dc3545;
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
    opacity: 0;
    transition: opacity 0.3s;
    cursor: pointer;
    z-index: 1;
}

.delete-action:hover {
    background-color: #c82333;
}

.swipe-container:hover {
    transform: translateY(-2px);
    box-shadow: 0 3px 8px rgba(0,0,0,0.08);
}

.comment-item.error {
    background-color: #fff8f8;
    border-left: 3px solid #dc3545;
}

.comment-item.error .default-avatar {
    background-color: #dc3545;
}

.comment-item.error .commenter-name {
    color: #dc3545;
}

.comment-content {
    flex: 1;
}

.comment-header {
    display: flex;
    justify-content: space-between;
    margin-bottom: 8px;
}

.commenter-name {
    font-weight: 600;
    font-size: 0.95rem;
    color: #343a40;
}

.comment-time {
    color: #6c757d;
    font-size: 0.8rem;
    display: flex;
    align-items: center;
    gap: 4px;
}

.comment-time i {
    font-size: 0.75rem;
}

.comment-text {
    margin: 0;
    font-size: 0.95rem;
    color: #495057;
    line-height: 1.5;
    word-break: break-word;
}

.no-comments-message {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 30px 15px;
    text-align: center;
}

.empty-comments-icon {
    font-size: 3rem;
    color: #dee2e6;
    margin-bottom: 15px;
}

.no-comments-message span {
    font-size: 0.9rem;
    color: #6c757d;
}

.comments-loading {
    display: none;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 20px;
    text-align: center;
    color: #6c757d;
}

.spinner {
    font-size: 1.5rem;
    margin-bottom: 10px;
    color: #007bff;
}

/* Task Status Indicator at bottom of card */
.task-status-indicator {
    display: flex;
    align-items: center;
    padding: 8px 14px;
    background-color: #f8f9fa;
    border-top: 1px solid #f0f0f0;
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
}

.status-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    margin-right: 8px;
}

.status-dot.text-secondary {
    background-color: #6c757d;
}

.status-dot.text-primary {
    background-color: #0d6efd;
}

.status-dot.text-warning {
    background-color: #ffc107;
}

.status-dot.text-success {
    background-color: #198754;
}

.status-text {
    font-size: 0.75rem;
    color: #495057;
    font-weight: 500;
}

.owner-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    overflow: hidden;
    background-color: #f8f9fa;
    border: 1px solid #e9ecef;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.owner-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.task-owner-container {
    display: flex;
    align-items: center;
    gap: 8px;
}

#taskOwnerName {
    font-weight: 500;
    color: #495057;
}

.assignee-avatar {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    margin-left: -6px;
    overflow: hidden;
    position: relative;
    border: 2px solid #fff;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    transition: transform 0.2s;
}
</style>

<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Variable to track current task permissions
    let currentTaskPermissions = {
        can_edit: false,
        can_upload_attachments: false,
        can_comment: true
    };
    
    // Variable to track current task data
    let currentTaskData = null;
    
    // Epic filter functionality
    const epicFilterItems = document.querySelectorAll('#epicFilterBtn + .dropdown-menu .dropdown-item');
    epicFilterItems.forEach(item => {
        item.addEventListener('click', function(e) {
            e.preventDefault();
            
            // Get the selected epic title
            const epicTitle = this.textContent.trim();
            
            // Update the dropdown button text
            const epicFilterBtn = document.getElementById('epicFilterBtn');
            epicFilterBtn.innerHTML = epicTitle + ' <i class="fas fa-chevron-down"></i>';
            
            // Show/hide tasks based on epic selection
            const taskCards = document.querySelectorAll('.task-card');
            
            if (epicTitle === 'All Epics') {
                // Show all tasks
                taskCards.forEach(card => {
                    card.style.display = 'block';
                });
            } else {
                // Filter tasks by epic
                taskCards.forEach(card => {
                    const taskEpicElement = card.querySelector('.epic-tag');
                    
                    if (taskEpicElement) {
                        const taskEpic = taskEpicElement.textContent.trim();
                        if (taskEpic === epicTitle) {
                            card.style.display = 'block';
                        } else {
                            card.style.display = 'none';
                        }
                    } else {
                        // Task has no epic
                        card.style.display = 'none';
                    }
                });
            }
            
            // Update task counts in column headers
            updateColumnTaskCounts();
        });
    });
    
    // Function to update task counts in column headers
    function updateColumnTaskCounts() {
        const columns = ['todo', 'inprogress', 'review', 'completed'];
        const statusNames = ['TO DO', 'IN PROGRESS', 'NEEDS REVIEWING', 'COMPLETED'];
        
        columns.forEach((column, index) => {
            const columnElement = document.getElementById(`${column}-list`);
            if (columnElement) {
                const visibleTasks = columnElement.querySelectorAll('.task-card[style="display: block;"], .task-card:not([style*="display"])').length;
                const columnHeader = columnElement.closest('.task-column').querySelector('.task-column-header h5');
                if (columnHeader) {
                    columnHeader.textContent = `${statusNames[index]} - ${visibleTasks} TASKS`;
                }
            }
        });
    }
    
    // Check if task_assignees table exists
    fetch('../tasks/check_task_assignees_table.php', {
        method: 'GET'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            console.log('Task assignees check successful:', data.message);
        } else {
            console.error('Task assignees check failed:', data.message);
        }
    })
    .catch(error => {
        console.error('Error checking task assignees table:', error);
    });
    
    // Logout confirmation
    document.getElementById('logout-btn').addEventListener('click', function(e) {
        e.preventDefault();
        var logoutModal = new bootstrap.Modal(document.getElementById('logoutModal'));
        logoutModal.show();
    });
    
    // Create options modal
    const createTaskBtn = document.getElementById('createTaskBtn');
    const createOptionsModal = new bootstrap.Modal(document.getElementById('createOptionsModal'));
    const createTaskModal = new bootstrap.Modal(document.getElementById('createTaskModal'));
    const createEpicModal = new bootstrap.Modal(document.getElementById('createEpicModal'));
    const inviteModal = new bootstrap.Modal(document.getElementById('inviteModal'));
    
    // Show options modal when Create button is clicked
    createTaskBtn.addEventListener('click', function() {
        createOptionsModal.show();
    });
    
    // Task option clicked
    document.getElementById('createTaskOption').addEventListener('click', function() {
        createOptionsModal.hide();
        setTimeout(() => {
            createTaskModal.show();
        }, 300);
    });
    
    // Epic option clicked
    document.getElementById('createEpicOption').addEventListener('click', function() {
        createOptionsModal.hide();
        setTimeout(() => {
            createEpicModal.show();
        }, 300);
    });
    
    // Invite button clicked
    document.querySelector('.invite-btn').addEventListener('click', function() {
        inviteModal.show();
    });
    
    // Send invite button clicked
    document.getElementById('sendInviteBtn').addEventListener('click', function() {
        const email = document.getElementById('inviteEmail').value.trim();
        
        if (!email) {
            alert('Please enter an email address');
            return;
        }
        
        // Create form data
        const formData = new FormData();
        formData.append('project_id', '<?php echo $project_id; ?>');
        formData.append('email', email);
        formData.append('role', 'member');
        
        // Show loading state
        this.disabled = true;
        this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
        
        // Send invitation
        fetch('invite_user.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            // Reset button
            this.disabled = false;
            this.innerHTML = 'Invite';
            
            if (data.success) {
                // Show success message
                const alertDiv = document.createElement('div');
                alertDiv.className = 'alert alert-success alert-dismissible fade show';
                alertDiv.role = 'alert';
                alertDiv.innerHTML = `
                    <strong>Success!</strong> ${data.message}
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                `;
                
                // Insert alert at the top of the main content
                const mainContent = document.querySelector('.main-content');
                const topNav = document.querySelector('.top-nav');
                mainContent.insertBefore(alertDiv, topNav.nextSibling);
                
                // Clear the input and close the modal
                document.getElementById('inviteEmail').value = '';
                inviteModal.hide();
            } else {
                // Show error message
                alert(data.message || 'Error sending invitation');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            
            // Reset button
            this.disabled = false;
            this.innerHTML = 'Invite';
            
            // Show error message
            alert('An error occurred while sending the invitation. Please try again.');
        });
    });
    
    // Save task button clicked
    document.getElementById('saveTaskBtn').addEventListener('click', function() {
        const form = document.getElementById('createTaskForm');
        
        // Validate Epic field
        const epicId = document.getElementById('taskEpic').value;
        if (!epicId) {
            document.getElementById('epicError').style.display = 'block';
            return;
        } else {
            document.getElementById('epicError').style.display = 'none';
        }
        
        // Check form validity
        if (!form.checkValidity()) {
            // Trigger browser's native validation UI
            form.reportValidity();
            return;
        }
        
        const formData = new FormData(form);
        
        // Add CSRF token
        formData.append('csrf_token', '<?php echo $_SESSION['csrf_token']; ?>');
        
        // Send data to server via fetch API
        fetch('../tasks/create_task.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Create success message
                const alertDiv = document.createElement('div');
                alertDiv.className = 'alert alert-success alert-dismissible fade show';
                alertDiv.role = 'alert';
                alertDiv.innerHTML = `
                    <strong>Success!</strong> Task created successfully.
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                `;
                
                // Insert alert at the top of the main content
                const mainContent = document.querySelector('.main-content');
                const topNav = document.querySelector('.top-nav');
                mainContent.insertBefore(alertDiv, topNav.nextSibling);
                
                // Reload the page to show the new task
                setTimeout(() => {
                    window.location.reload();
                }, 1000);
            } else {
                // Create error message
                const alertDiv = document.createElement('div');
                alertDiv.className = 'alert alert-danger alert-dismissible fade show';
                alertDiv.role = 'alert';
                alertDiv.innerHTML = `
                    <strong>Error!</strong> ${data.message}
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                `;
                
                // Insert alert at the top of the main content
                const mainContent = document.querySelector('.main-content');
                const topNav = document.querySelector('.top-nav');
                mainContent.insertBefore(alertDiv, topNav.nextSibling);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            
            // Create error message
            const alertDiv = document.createElement('div');
            alertDiv.className = 'alert alert-danger alert-dismissible fade show';
            alertDiv.role = 'alert';
            alertDiv.innerHTML = `
                <strong>Error!</strong> An error occurred while creating the task.
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            `;
            
            // Insert alert at the top of the main content
            const mainContent = document.querySelector('.main-content');
            const topNav = document.querySelector('.top-nav');
            mainContent.insertBefore(alertDiv, topNav.nextSibling);
        });
        
        // Close modal
        createTaskModal.hide();
    });
    
    // Save epic button clicked
    document.getElementById('saveEpicBtn').addEventListener('click', function() {
        const form = document.getElementById('createEpicForm');
        const formData = new FormData(form);
        
        // Add CSRF token
        formData.append('csrf_token', '<?php echo $_SESSION['csrf_token']; ?>');
        
        // Send data to server via fetch API
        fetch('../tasks/create_epic.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Epic created successfully!');
                // Reload the page to show the new epic
                window.location.reload();
            } else {
                alert('Error creating epic: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred while creating the epic.');
        });
        
        // Close modal
        createEpicModal.hide();
    });
    
    // Epic dropdown selection
    document.querySelectorAll('#epicDropdown + .dropdown-menu .dropdown-item').forEach(item => {
        item.addEventListener('click', function(e) {
            e.preventDefault();
            const value = this.getAttribute('data-value');
            const text = this.textContent;
            document.getElementById('epicDropdown').textContent = text;
            document.getElementById('taskEpic').value = value;
        });
    });
    
    // Color dropdown selection
    document.querySelectorAll('.color-dropdown-menu .dropdown-item').forEach(item => {
        item.addEventListener('click', function(e) {
            e.preventDefault();
            const color = this.getAttribute('data-color');
            document.querySelector('.color-preview').style.backgroundColor = color;
            document.getElementById('epicColor').value = color;
        });
    });
    
    // Edit project modal
    const editProjectModal = new bootstrap.Modal(document.getElementById('editProjectModal'));
    const leaveProjectModal = new bootstrap.Modal(document.getElementById('leaveProjectModal'));
    
    // Handle edit project button click
    document.querySelectorAll('.edit-project-btn').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            editProjectModal.show();
        });
    });
    
    // Handle leave project button click
    document.querySelectorAll('.leave-project-btn').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            // Check if user is project owner
            const isProjectOwner = <?php echo ($project['owner_id'] == $user_id) ? 'true' : 'false'; ?>;
            
            if (isProjectOwner) {
                // Show owner warning tooltip instead of modal
                showOwnerWarningTooltip(this);
            } else {
                // Show leave project modal for non-owners
                leaveProjectModal.show();
            }
        });
    });
    
    // Function to show owner warning tooltip
    function showOwnerWarningTooltip(element) {
        // Create tooltip container if it doesn't exist
        let tooltip = document.querySelector('.owner-warning-tooltip');
        if (tooltip) {
            // Remove existing tooltip if it exists
            tooltip.remove();
        }
        
        // Create new tooltip
        tooltip = document.createElement('div');
        tooltip.className = 'owner-warning-tooltip';
        
        // Create tooltip content
        tooltip.innerHTML = `
            <div class="owner-warning-header">
                <div class="warning-icon"><i class="fas fa-exclamation-circle"></i> Danger Notification</div>
                <button class="close-tooltip"><i class="fas fa-times"></i></button>
            </div>
            <div class="owner-warning-body">
                As the owner, you cannot leave the project. Transfer ownership first or delete the project.
            </div>
        `;
        
        // Append tooltip to body
        document.body.appendChild(tooltip);
        
        // Position tooltip relative to the clicked element
        const rect = element.getBoundingClientRect();
        tooltip.style.top = (rect.top + window.scrollY - tooltip.offsetHeight - 10) + 'px';
        tooltip.style.left = (rect.left + window.scrollX - (tooltip.offsetWidth / 2) + (rect.width / 2)) + 'px';
        
        // Show tooltip with animation
        setTimeout(() => {
            tooltip.classList.add('show');
        }, 10);
        
        // Add close button event listener
        tooltip.querySelector('.close-tooltip').addEventListener('click', function() {
            tooltip.classList.remove('show');
            setTimeout(() => {
                tooltip.remove();
            }, 300);
        });
        
        // Auto-hide tooltip after 5 seconds
        setTimeout(() => {
            if (tooltip.parentNode) {
                tooltip.classList.remove('show');
                setTimeout(() => {
                    if (tooltip.parentNode) {
                        tooltip.remove();
                    }
                }, 300);
            }
        }, 5000);
    }
    
    // Handle project icon upload
    document.getElementById('projectIcon').addEventListener('change', function() {
        const file = this.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function(e) {
                const preview = document.getElementById('projectIconPreview');
                if (preview.tagName === 'IMG') {
                    preview.src = e.target.result;
                } else {
                    // Replace div with img
                    const img = document.createElement('img');
                    img.src = e.target.result;
                    img.id = 'projectIconPreview';
                    img.alt = 'Project Icon';
                    preview.parentNode.replaceChild(img, preview);
                }
            }
            reader.readAsDataURL(file);
        }
    });
    
    // Handle save project button click
    document.getElementById('saveProjectBtn').addEventListener('click', function() {
        document.getElementById('editProjectForm').submit();
    });
    
    // Handle confirm leave button click
    document.getElementById('confirmLeaveBtn').addEventListener('click', function() {
        // Create a form and submit it
        const form = document.createElement('form');
        form.method = 'post';
        form.action = 'leave_project.php';
        
        const projectIdInput = document.createElement('input');
        projectIdInput.type = 'hidden';
        projectIdInput.name = 'project_id';
        projectIdInput.value = '<?php echo $project_id; ?>';
        
        const csrfInput = document.createElement('input');
        csrfInput.type = 'hidden';
        csrfInput.name = 'csrf_token';
        csrfInput.value = '<?php echo isset($_SESSION["csrf_token"]) ? $_SESSION["csrf_token"] : ""; ?>';
        
        form.appendChild(projectIdInput);
        form.appendChild(csrfInput);
        document.body.appendChild(form);
        form.submit();
    });

    // Initialize task lists - draggable functionality has been removed
    const taskLists = document.querySelectorAll('.task-list');
    // No Sortable initialization - we're using logic-based status updates instead of drag and drop
    
    // Function to update task count in column header
    function updateTaskCount(header, count, status) {
        header.textContent = `${status} - ${count} TASKS`;
    }
    
    // Function to update the status indicator at the bottom of the task card
    function updateTaskStatusIndicator(taskCard, newStatus) {
        const statusIndicator = taskCard.querySelector('.task-status-indicator');
        if (!statusIndicator) return;
        
        const statusDot = statusIndicator.querySelector('.status-dot');
        const statusText = statusIndicator.querySelector('.status-text');
        
        // Remove all existing status classes
        statusDot.classList.remove('text-secondary', 'text-primary', 'text-warning', 'text-success');
        
        // Add the appropriate class based on the new status
        let statusClass = 'text-secondary';
        let displayText = 'To Do';
        
        if (newStatus === 'in_progress') {
            statusClass = 'text-primary';
            displayText = 'In Progress';
        } else if (newStatus === 'review') {
            statusClass = 'text-warning';
            displayText = 'Review';
        } else if (newStatus === 'completed') {
            statusClass = 'text-success';
            displayText = 'Completed';
        }
        
        statusDot.classList.add(statusClass);
        statusText.textContent = displayText;
    }
    
    // Function to update task status via AJAX
    function updateTaskStatus(taskId, newStatus, skipPermissionCheck = false) {
        fetch('../tasks/update_task_status.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-Token': '<?php echo $_SESSION['csrf_token']; ?>'
            },
            body: `task_id=${taskId}&status=${newStatus}&project_id=<?php echo $project_id; ?>`
        })
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                console.error('Error updating task:', data.message);
                
                // Show error message to user
                const alertDiv = document.createElement('div');
                alertDiv.className = 'alert alert-danger alert-dismissible fade show';
                alertDiv.role = 'alert';
                alertDiv.innerHTML = `
                    <strong>Error!</strong> ${data.message}
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                `;
                
                // Insert alert at the top of the main content
                const mainContent = document.querySelector('.main-content');
                const topNav = document.querySelector('.top-nav');
                mainContent.insertBefore(alertDiv, topNav.nextSibling);
                
                // Reset the UI by reloading the page
                setTimeout(() => {
                    window.location.reload();
                }, 3000);
            } else {
                // Check if project was completed with this update
                if (data.project_completed) {
                    showProjectCompletedMessage();
                } else {
                    // Update project status badge based on current task counts
                    updateProjectStatusBadge();
                }
                
                // Check if we should automatically progress the task status
                if (!skipPermissionCheck) {
                    handleAutomaticStatusProgress(taskId, newStatus);
                }
            }
        })
        .catch(error => {
            console.error('Error:', error);
        });
    }
    
    // Function to update project status badge based on task counts
    function updateProjectStatusBadge() {
        // Count tasks in each status
        const todoCount = document.querySelector('#todo-list').querySelectorAll('.task-card').length;
        const inProgressCount = document.querySelector('#inprogress-list').querySelectorAll('.task-card').length;
        const reviewCount = document.querySelector('#review-list').querySelectorAll('.task-card').length;
        const completedCount = document.querySelector('#completed-list').querySelectorAll('.task-card').length;
        
        const totalTasks = todoCount + inProgressCount + reviewCount + completedCount;
        const activeTasks = todoCount + inProgressCount + reviewCount;
        
        // Get or create status badge
        const projectTitle = document.querySelector('.project-title');
        let statusBadge = projectTitle.querySelector('.badge');
        
        if (!statusBadge) {
            // Create new badge if it doesn't exist
            statusBadge = document.createElement('span');
            statusBadge.className = 'badge ms-2';
            projectTitle.appendChild(statusBadge);
        }
        
        // Determine project status based on task counts
        if (totalTasks === 0 || completedCount === totalTasks) {
            // All tasks completed or no tasks
            statusBadge.className = 'badge bg-success ms-2';
            statusBadge.textContent = 'COMPLETED';
        } else if (activeTasks > 0 && reviewCount === activeTasks) {
            // All active tasks are in review status
            statusBadge.className = 'badge bg-warning ms-2';
            statusBadge.textContent = 'NEEDS REVIEW';
        } else {
            // At least one task is to_do, in_progress, or review
            statusBadge.className = 'badge bg-primary ms-2';
            statusBadge.textContent = 'IN PROGRESS';
        }
    }
    
    // Function to handle automatic task status progression based on logic
    function handleAutomaticStatusProgress(taskId, currentStatus) {
        // Get task details
        fetch(`get_task.php?task_id=${taskId}&project_id=<?php echo $project_id; ?>`, {
            method: 'GET',
            headers: {
                'X-CSRF-Token': '<?php echo $_SESSION['csrf_token']; ?>'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const task = data.task;
                const currentUserId = <?php echo $_SESSION['user_id']; ?>;
                const taskCreatorId = task.created_by;
                const taskAssigneeId = task.assigned_to;
                
                // Logic for automatic status progression:
                // 1. If a task is in 'to_do' status for more than X days, move to 'in_progress'
                // 2. If task is in 'in_progress' and is high priority with approaching due date, move to 'review'
                
                // Example logic based on priority only (due date has been removed)
                if (currentStatus === 'in_progress' && task.priority === 'high') {
                    // For high priority tasks in progress, we could implement alternative logic here
                    // For now, we'll just leave this section as a placeholder
                }
                
                // Example logic for tasks in "to_do" status for too long
                if (currentStatus === 'to_do') {
                    const createdDate = new Date(task.created_at);
                    const today = new Date();
                    const daysSinceCreation = Math.floor((today - createdDate) / (1000 * 60 * 60 * 24));
                    
                    // If task has been in to_do for more than 5 days and has an assignee
                    if (daysSinceCreation > 5 && task.assigned_to) {
                        // Move to in_progress status automatically
                        updateTaskStatus(taskId, 'in_progress', true);
                        
                        // Show notification to user
                        const alertDiv = document.createElement('div');
                        alertDiv.className = 'alert alert-info alert-dismissible fade show';
                        alertDiv.role = 'alert';
                        alertDiv.innerHTML = `
                            <strong>Task Updated!</strong> Task "${task.title}" has been in To Do for over 5 days and has been automatically moved to In Progress.
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        `;
                        
                        // Insert alert at the top of the main content
                        const mainContent = document.querySelector('.main-content');
                        const topNav = document.querySelector('.top-nav');
                        mainContent.insertBefore(alertDiv, topNav.nextSibling);
                        
                        // Update UI without refreshing
                        setTimeout(() => {
                            const inProgressList = document.getElementById('inprogress-list');
                            const taskCard = document.querySelector(`.task-card[data-task-id="${taskId}"]`);
                            if (inProgressList && taskCard) {
                                inProgressList.appendChild(taskCard);
                                
                                // Update task counts
                                const todoHeader = document.querySelector('#todo-list').closest('.task-column').querySelector('.task-column-header h5');
                                const inProgressHeader = inProgressList.closest('.task-column').querySelector('.task-column-header h5');
                                
                                const todoCount = document.querySelector('#todo-list').querySelectorAll('.task-card').length;
                                const inProgressCount = inProgressList.querySelectorAll('.task-card').length;
                                
                                updateTaskCount(todoHeader, todoCount, 'TO DO');
                                updateTaskCount(inProgressHeader, inProgressCount, 'IN PROGRESS');
                            }
                        }, 100);
                    }
                }
                
                // Logic for moving tasks in review to completed (only for task creator)
                if (currentStatus === 'review' && currentUserId === taskCreatorId) {
                    const reviewDate = new Date(); // We don't have actual review date, using current time
                    const today = new Date();
                    const hoursInReview = Math.floor((today - reviewDate) / (1000 * 60 * 60));
                    
                    // If in review for more than 24 hours and creator is viewing it
                    // This is just an example - in real implementation you'd track when it entered review status
                    if (hoursInReview > 24) {
                        // Show a prompt to ask if they want to complete the task
                        if (confirm(`Task "${task.title}" has been in review for more than 24 hours. Would you like to mark it as completed?`)) {
                            updateTaskStatus(taskId, 'completed', true);
                            
                            // Update UI without refreshing
                            setTimeout(() => {
                                const completedList = document.getElementById('completed-list');
                                const taskCard = document.querySelector(`.task-card[data-task-id="${taskId}"]`);
                                if (completedList && taskCard) {
                                    completedList.appendChild(taskCard);
                                    
                                    // Update task counts
                                    const reviewHeader = document.querySelector('#review-list').closest('.task-column').querySelector('.task-column-header h5');
                                    const completedHeader = completedList.closest('.task-column').querySelector('.task-column-header h5');
                                    
                                    const reviewCount = document.querySelector('#review-list').querySelectorAll('.task-card').length;
                                    const completedCount = completedList.querySelectorAll('.task-card').length;
                                    
                                    updateTaskCount(reviewHeader, reviewCount, 'NEEDS REVIEWING');
                                    updateTaskCount(completedHeader, completedCount, 'COMPLETED');
                                }
                            }, 100);
                        }
                    }
                }
            }
        })
        .catch(error => {
            console.error('Error:', error);
        });
    }
    
    // Function to show a success message when project is completed
    function showProjectCompletedMessage() {
        // Create the alert element
        const alertDiv = document.createElement('div');
        alertDiv.className = 'alert alert-success alert-dismissible fade show';
        alertDiv.role = 'alert';
        alertDiv.innerHTML = `
            <strong>Congratulations!</strong> All tasks in this project have been completed!
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        `;
        
        // Insert alert at the top of the main content
        const mainContent = document.querySelector('.main-content');
        const topNav = document.querySelector('.top-nav');
        mainContent.insertBefore(alertDiv, topNav.nextSibling);
        
        // Update project header with a completed badge
        const projectTitle = document.querySelector('.project-title');
        // Check if badge already exists
        let statusBadge = projectTitle.querySelector('.badge');
        if (statusBadge) {
            // Update existing badge
            statusBadge.className = 'badge bg-success ms-2';
            statusBadge.textContent = 'COMPLETED';
        } else {
            // Create new badge
            const completedBadge = document.createElement('span');
            completedBadge.className = 'badge bg-success ms-2';
            completedBadge.textContent = 'COMPLETED';
            projectTitle.appendChild(completedBadge);
        }
        
        // Set a timeout to automatically dismiss the alert after 10 seconds
        setTimeout(() => {
            const bsAlert = new bootstrap.Alert(alertDiv);
            bsAlert.close();
        }, 10000);
    }
    
    // Function to scan all tasks and update statuses automatically
    function autoScanAndUpdateTasks() {
        // Get all task cards from the page
        const taskCards = document.querySelectorAll('.task-card');
        const processedTasks = [];
        
        // Process tasks in batches to avoid overwhelming the server
        const processBatch = (startIndex, batchSize) => {
            const endIndex = Math.min(startIndex + batchSize, taskCards.length);
            
            for (let i = startIndex; i < endIndex; i++) {
                const taskCard = taskCards[i];
                const taskId = taskCard.getAttribute('data-task-id');
                const currentColumn = taskCard.closest('.task-list');
                const currentStatus = currentColumn ? currentColumn.getAttribute('data-status') : null;
                
                if (taskId && currentStatus && !processedTasks.includes(taskId)) {
                    processedTasks.push(taskId);
                    handleAutomaticStatusProgress(taskId, currentStatus);
                }
            }
            
            // Process next batch if there are more tasks
            if (endIndex < taskCards.length) {
                setTimeout(() => {
                    processBatch(endIndex, batchSize);
                }, 2000); // Wait 2 seconds between batches
            }
        };
        
        // Start processing first batch
        if (taskCards.length > 0) {
            processBatch(0, 5); // Process 5 tasks at a time
        }
    }
    
    // Run auto-scan when page loads
    setTimeout(autoScanAndUpdateTasks, 5000); // Wait 5 seconds after page load
    
    // Set up periodic auto-scanning (every 5 minutes)
    setInterval(autoScanAndUpdateTasks, 5 * 60 * 1000);
    
    // Update project status badge when page loads
    updateProjectStatusBadge();

    // Add event listeners for status change dropdown options
    document.querySelectorAll('.status-option').forEach(option => {
        option.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation(); // Prevent task card click event
            
            // Also prevent parent clicks
            const dropdownToggle = this.closest('.dropdown-menu').previousElementSibling;
            if (dropdownToggle) {
                const clickEvent = e.currentTarget;
                setTimeout(() => {
                    dropdownToggle.click(); // Close dropdown after status change
                }, 100);
            }
            
            const taskId = this.getAttribute('data-task-id');
            const newStatus = this.getAttribute('data-status');
            const currentStatus = this.closest('.task-card').closest('.task-list').getAttribute('data-status');
            
            // Only proceed if status is actually changing
            if (newStatus !== currentStatus) {
                // Find source and destination lists
                const sourceList = document.querySelector(`.task-list[data-status="${currentStatus}"]`);
                const destList = document.querySelector(`.task-list[data-status="${newStatus}"]`);
                const taskCard = this.closest('.task-card');
                
                // Update task status via API
                updateTaskStatus(taskId, newStatus);
                
                // Move task card to the new column in the UI
                if (sourceList && destList && taskCard) {
                    // Update the status indicator at the bottom of the card
                    updateTaskStatusIndicator(taskCard, newStatus);
                    
                    // Move the card
                    destList.appendChild(taskCard);
                    
                    // Update task counts
                    const sourceHeader = sourceList.closest('.task-column').querySelector('.task-column-header h5');
                    const destHeader = destList.closest('.task-column').querySelector('.task-column-header h5');
                    
                    const sourceCount = sourceList.querySelectorAll('.task-card').length;
                    const destCount = destList.querySelectorAll('.task-card').length;
                    
                    updateTaskCount(sourceHeader, sourceCount, currentStatus.toUpperCase().replace('_', ' '));
                    updateTaskCount(destHeader, destCount, newStatus.toUpperCase().replace('_', ' '));
                }
            }
        });
    });

    // Add event listeners for task delete buttons
    document.querySelectorAll('.delete-task-btn').forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation(); // Prevent task card click event
            
            const taskId = this.getAttribute('data-task-id');
            const taskCard = this.closest('.task-card');
            const taskTitle = taskCard.querySelector('.task-title').textContent;
            
            if (confirm(`Are you sure you want to delete the task "${taskTitle}"? This action cannot be undone.`)) {
                deleteTask(taskId, taskCard);
            }
        });
    });
    
    // Function to handle task deletion
    function deleteTask(taskId, taskCard) {
        const csrfToken = '<?php echo $_SESSION['csrf_token']; ?>';
        const formData = new FormData();
        formData.append('task_id', taskId);
        formData.append('csrf_token', csrfToken);
        
                    fetch('../tasks/delete_task.php', {
                method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Remove the task card from the UI
                const taskList = taskCard.closest('.task-list');
                taskCard.remove();
                
                // Update task count in column header
                if (taskList) {
                    const status = taskList.getAttribute('data-status');
                    const header = taskList.closest('.task-column').querySelector('.task-column-header h5');
                    const count = taskList.querySelectorAll('.task-card').length;
                    updateTaskCount(header, count, status.toUpperCase().replace('_', ' '));
                }
                
                // Check if task modal is open for this task and close it if it is
                if (currentTaskId == taskId) {
                    taskModal.hide();
                    showToast('Task has been deleted', 'info');
                } else {
                    // Show success message
                    showToast('Task deleted successfully', 'success');
                    
                    // Update project status badge based on current task counts
                    updateProjectStatusBadge();
                }
            } else {
                showToast(data.message || 'Failed to delete task', 'danger');
            }
        })
        .catch(error => {
            console.error('Error deleting task:', error);
            showToast('An error occurred while deleting the task', 'danger');
        });
    }

    // Add event listeners for dropdown toggles to prevent task overlay from opening
    document.querySelectorAll('.task-card .dropdown-toggle').forEach(toggle => {
        toggle.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation(); // Prevent task card click event
        });
    });

    // Task View Modal
    const taskModal = new bootstrap.Modal(document.getElementById('viewTaskModal'));
    const taskCards = document.querySelectorAll('.task-card');

    // Add click event to all task cards to show modal
    taskCards.forEach(card => {
        card.addEventListener('click', function(e) {
            // Don't open modal if clicking on dropdown or status options
            if (e.target.closest('.dropdown') || e.target.closest('.status-option') || e.target.closest('.dropdown-toggle')) {
                e.stopPropagation();
                return;
            }
            
            const taskId = this.getAttribute('data-task-id');
            
            // Check if we need to fix the comments table first (run once per session)
            if (!sessionStorage.getItem('comments_table_checked')) {
                // Run the fix script first
                fetch('../tasks/fix_task_comments.php')
                .then(response => response.json())
                .then(data => {
                    console.log('Comments table check:', data.message);
                    sessionStorage.setItem('comments_table_checked', 'true');
                    // Now show the task modal
                    showTaskModal(taskId);
                })
                .catch(error => {
                    console.error('Error checking comments table:', error);
                    // Still show the task modal even if check fails
                    showTaskModal(taskId);
                });
            } else {
                // Table already checked, show modal directly
                showTaskModal(taskId);
            }
        });
    });
    
    // Handle modal close event
    document.getElementById('viewTaskModal').addEventListener('hidden.bs.modal', function() {
        // Reset attachment related state
        currentTaskId = null;
        const attachmentsContainer = document.getElementById('taskAttachments');
        if (attachmentsContainer) {
            attachmentsContainer.innerHTML = '';
        }
        
        // Reset description editing state
        const descriptionDisplay = document.getElementById('descriptionDisplay');
        const descriptionEditor = document.getElementById('descriptionEditor');
        if (descriptionDisplay && descriptionEditor) {
            descriptionDisplay.style.display = 'block';
            descriptionEditor.style.display = 'none';
            
            // Clear description content
            descriptionDisplay.textContent = '';
            document.getElementById('descriptionTextarea').value = '';
        }
        
        // Hide edit button
        const editDescriptionBtn = document.getElementById('editDescriptionBtn');
        if (editDescriptionBtn) {
            editDescriptionBtn.style.display = 'none';
        }
        
        // Reset permissions
        currentTaskPermissions = {};
    });
    
    // Function to show task modal and fetch task details
    function showTaskModal(taskId) {
        // Show loading state
        document.body.style.cursor = 'wait';
        
        // Prepare the URL with CSRF token
        const csrfToken = '<?php echo $_SESSION['csrf_token']; ?>';
        const url = `../tasks/get_task.php?task_id=${taskId}&project_id=<?php echo $project_id; ?>&csrf_token=${csrfToken}`;
        
        // Get task details via AJAX
        fetchTaskWithRetry(url, csrfToken, 3); // Try up to 3 times
    }
    
    // Function to fetch task with retry capability
    function fetchTaskWithRetry(url, csrfToken, retriesLeft) {
        // Show loading spinner
        document.body.classList.add('loading');
        
        // Get modal elements
        const modalBody = document.querySelector('#viewTaskModal .modal-body');
        
        fetch(url, {
            method: 'GET',
            headers: {
                'X-CSRF-Token': csrfToken,
                'Content-Type': 'application/json'
            },
            // Add cache control to prevent stale responses
            cache: 'no-store'
        })
        .then(response => {
            if (!response.ok) {
                if (response.status >= 500) {
                    // Server error, might be temporary
                    throw new Error(`Server error: ${response.status}`);
                } else if (response.status === 404) {
                    throw new Error(`Task not found`);
                } else {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
            }
            return response.json();
        })
        .then(data => {
            // Reset cursor and loading state
            document.body.style.cursor = 'default';
            document.body.classList.remove('loading');
            
            // Log response data for debugging
            logResponseData(data);
            
            if (data.success && data.task) {
                            // Store task data globally
            currentTaskData = data.task;
            
            // Populate the modal with task data
            populateTaskModal(data.task);
                
                // Show the modal
                taskModal.show();
                
                // Load comments separately
                loadTaskComments(data.task.task_id);
            } else {
                console.error('Error fetching task:', data.message || 'Unknown error');
                // Show error message to user
                showToast(`Error: ${data.message || 'Could not load task details'}`, 'danger');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            
            // Check if we should retry
            if (retriesLeft > 0 && (error.message.includes('Server error') || error.message.includes('Failed to fetch'))) {
                console.log(`Retrying... ${retriesLeft} attempts left`);
                // Wait a bit before retrying (500ms)
                setTimeout(() => {
                    fetchTaskWithRetry(url, csrfToken, retriesLeft - 1);
                }, 500);
                return;
            }
            
            // Reset cursor and loading state
            document.body.style.cursor = 'default';
            document.body.classList.remove('loading');
            
            // Show a more specific error message
            if (error.message.includes('Failed to fetch')) {
                showToast('Network error. Please check your connection and try again.', 'danger');
            } else if (error.message.includes('Task not found')) {
                showToast('Task not found. It may have been deleted.', 'danger');
            } else {
                showToast('Error loading task details. Please try again.', 'danger');
            }
        });
    }
    
    // Function to populate the task modal with data
    function populateTaskModal(task) {
        try {
            // Defensive check - ensure we have valid task data
            if (!task || typeof task !== 'object') {
                console.error('Invalid task data received');
                throw new Error('Invalid task data');
            }
            
            // Set basic task details with safe access
            const safeGet = (obj, path, defaultValue) => {
                // Handle null/undefined objects immediately
                if (obj == null) {
                    return defaultValue;
                }
                
                try {
                    // Handle simple property access
                    if (typeof path === 'string' && !path.includes('.')) {
                        return obj[path] != null ? obj[path] : defaultValue;
                    }
                    
                    // Handle nested property access
                    if (typeof path === 'string') {
                        const keys = path.split('.');
                        let value = obj;
                        
                        for (let i = 0; i < keys.length; i++) {
                            const key = keys[i];
                            
                            if (value == null || typeof value !== 'object') {
                                return defaultValue;
                            }
                            
                            value = value[key];
                            
                            if (i === keys.length - 1) {
                                return value != null ? value : defaultValue;
                            }
                        }
                    }
                    
                    return defaultValue;
                } catch (e) {
                    return defaultValue;
                }
            };
            
            // Get the task ID first to load attachments
            const taskId = safeGet(task, 'task_id', null);
            if (taskId) {
                // Load task attachments
                loadTaskAttachments(taskId);
            }
            
            // Get task permissions
            const permissions = safeGet(task, 'permissions', {});
            const canEdit = safeGet(permissions, 'can_edit', false);
            const canUploadAttachments = safeGet(permissions, 'can_upload_attachments', false);
            const canComment = safeGet(permissions, 'can_comment', false);
            
            // Store current task permissions globally
            currentTaskPermissions = {
                can_edit: canEdit,
                can_upload_attachments: canUploadAttachments,
                can_comment: canComment
            };
            
            // Show/hide edit button based on task ownership
            const editButton = document.getElementById('editTaskButton');
            if (editButton) {
                const isTaskOwner = safeGet(task, 'created_by', null) == <?php echo $_SESSION['user_id']; ?>;
                editButton.style.display = isTaskOwner ? 'flex' : 'none';
            }
            
            // Show/hide attachment button based on permissions
            const attachButton = document.getElementById('attachFileBtn');
            if (attachButton) {
                attachButton.style.display = canUploadAttachments ? 'inline-block' : 'none';
            }
            
            // Show/hide comment form based on permissions
            const commentForm = document.querySelector('.comment-form');
            if (commentForm) {
                commentForm.style.display = canComment ? 'flex' : 'none';
            }
            
            // Safely set all task fields
            document.getElementById('taskOverlayTitle').textContent = safeGet(task, 'title', '<TaskName>');
            
            // Handle description - always show the exact description from database
            const descriptionDisplay = document.getElementById('descriptionDisplay');
            const editDescriptionBtn = document.getElementById('editDescriptionBtn');
            
            // Get description with default empty string (not null or undefined)
            const description = safeGet(task, 'description', '');
            
            // Set the description directly from the database
            if (descriptionDisplay) {
                descriptionDisplay.textContent = description;
            }
            
            // Handle edit button visibility based on permissions
            if (editDescriptionBtn) {
                // Only show edit button if user is the task owner
                const isTaskOwner = safeGet(task, 'created_by', null) == <?php echo $_SESSION['user_id']; ?>;
                editDescriptionBtn.style.display = isTaskOwner ? 'block' : 'none';
            }
            
            // Make sure the description is in view mode initially
            const descriptionEditor = document.getElementById('descriptionEditor');
            if (descriptionDisplay && descriptionEditor) {
                descriptionDisplay.style.display = 'block';
                descriptionEditor.style.display = 'none';
            }
            
            // Due date has been removed from the view task modal
            
            // Set priority with appropriate class
            const priorityBadge = document.getElementById('taskPriorityBadge');
            const priority = safeGet(task, 'priority', '');
            priorityBadge.textContent = priority ? capitalizeFirstLetter(priority) : '<Priority>';
            priorityBadge.className = 'detail-value priority-badge';
            if (priority) {
                priorityBadge.classList.add('priority-' + priority);
            }
            
            // Set epic
            const epicNameElem = document.getElementById('taskEpicName');
            const epicTitle = safeGet(task, 'epic_title', null);
            if (epicTitle) {
                epicNameElem.textContent = epicTitle;
            } else {
                epicNameElem.textContent = 'None';
                epicNameElem.classList.add('text-muted');
            }
            
            // Set dates
            document.getElementById('taskCreationDate').textContent = safeGet(task, 'created_at', '<CreationDate>') ? 
                formatDate(task.created_at) : 'Unknown';
            document.getElementById('taskUpdatedDate').textContent = safeGet(task, 'updated_at', '<UpdatedDate>') ? 
                formatDate(task.updated_at) : 'N/A';
            
            // Set task owner information
            const ownerContainer = document.querySelector('.task-owner-container');
            const ownerNameElement = document.getElementById('taskOwnerName');
            
            if (ownerContainer && ownerNameElement) {
                const ownerFirstName = safeGet(task, 'creator_first_name', '');
                const ownerLastName = safeGet(task, 'creator_last_name', '');
                const ownerAvatar = ownerContainer.querySelector('.owner-avatar');
                
                // Set owner name
                if (ownerFirstName && ownerLastName) {
                    ownerNameElement.textContent = `${ownerFirstName} ${ownerLastName}`;
                    
                    // Update owner avatar if available
                    if (ownerAvatar) {
                        // Check if profile image is available
                        const profileImage = safeGet(task, 'creator_profile_image', '');
                        if (profileImage) {
                            // Check if profile image path needs '../../' prefix
                            const profileImagePath = profileImage.startsWith('http') ? 
                                profileImage : 
                                '../../' + profileImage;
                            
                            ownerAvatar.innerHTML = `<img src="${profileImagePath}" alt="${ownerFirstName} ${ownerLastName}" class="img-fluid rounded-circle" onerror="this.onerror=null; this.parentNode.innerHTML='<div class=\\'default-avatar\\'>${ownerFirstName.charAt(0)}</div>'">`;
                        } else {
                            ownerAvatar.innerHTML = `<div class="default-avatar">${ownerFirstName.charAt(0)}</div>`;
                        }
                    }
                } else {
                    ownerNameElement.textContent = 'Unknown';
                }
            }
            
            // Handle assignees (if available)
            const assigneesContainer = document.querySelector('.assignees-container');
            if (assigneesContainer) {
                assigneesContainer.innerHTML = '';
                
                // Get all assignees from the new structure
                const assignees = safeGet(task, 'assignees', []);
                
                if (assignees.length > 0) {
                    // Display all assignees
                    assignees.forEach(assignee => {
                        const assigneeEl = document.createElement('div');
                        assigneeEl.className = 'assignee-avatar';
                        assigneeEl.title = `${assignee.first_name} ${assignee.last_name}`;
                        
                                    if (assignee.profile_image) {
                // Check if profile image path needs '../../' prefix
                const profileImagePath = assignee.profile_image.startsWith('http') ? 
                    assignee.profile_image : 
                    '../../' + assignee.profile_image;
                assigneeEl.innerHTML = `<img src="${profileImagePath}" alt="${assignee.first_name} ${assignee.last_name}">`;
            } else {
                            assigneeEl.innerHTML = `<div class="default-avatar">${assignee.first_name.charAt(0)}</div>`;
                        }
                        
                        assigneesContainer.appendChild(assigneeEl);
                    });
                } else {
                    // Fallback to legacy single assignee if no multiple assignees found
                    const firstName = safeGet(task, 'assigned_first_name', '');
                    const lastName = safeGet(task, 'assigned_last_name', '');
                    
                    if (firstName && lastName) {
                        const assigneeEl = document.createElement('div');
                        assigneeEl.className = 'assignee-avatar';
                        
                        const profileImage = safeGet(task, 'assigned_profile_image', '');
                        if (profileImage) {
                            // Check if profile image path needs '../../' prefix
                            const profileImagePath = profileImage.startsWith('http') ? 
                                profileImage : 
                                '../../' + profileImage;
                            assigneeEl.innerHTML = `<img src="${profileImagePath}" alt="${firstName} ${lastName}">`;
                        } else {
                            assigneeEl.innerHTML = `<div class="default-avatar">${firstName.charAt(0)}</div>`;
                        }
                        
                        assigneesContainer.appendChild(assigneeEl);
                    } else {
                        // Default placeholder for unassigned
                        assigneesContainer.innerHTML = `
                            <div class="assignee-avatar">
                                <div class="default-avatar">
                                    <i class="fas fa-user"></i>
                                </div>
                            </div>
                        `;
                    }
                }
                
                // Add a count badge if there are multiple assignees
                if (assignees.length > 1) {
                    const countBadge = document.createElement('div');
                    countBadge.className = 'assignee-count';
                    countBadge.textContent = assignees.length;
                    assigneesContainer.appendChild(countBadge);
                }
            }
            
            // Handle comments (if available)
            const commentsContainer = document.querySelector('.comments-container');
            if (commentsContainer) {
                const commentsList = commentsContainer.querySelector('#commentsList');
                const noCommentsMessage = commentsContainer.querySelector('.no-comments-message');
                
                // Clear comments list but keep the structure
                if (commentsList) {
                    commentsList.innerHTML = '';
                    
                    // Add no comments message
                    if (noCommentsMessage) {
                        commentsList.appendChild(noCommentsMessage);
                    }
                    
                    const comments = safeGet(task, 'comments', []);
                    if (Array.isArray(comments) && comments.length > 0) {
                        // Hide the no comments message
                        if (noCommentsMessage) {
                            noCommentsMessage.style.display = 'none';
                        }
                        
                        // Sort comments by creation date (newest first)
                        comments.sort((a, b) => {
                            return new Date(b.created_at) - new Date(a.created_at);
                        });
                        
                        comments.forEach(comment => {
                            if (comment) {
                                const commentEl = createCommentElement(comment);
                                commentsList.appendChild(commentEl);
                            }
                        });
                    } else {
                        // Show the no comments message
                        if (noCommentsMessage) {
                            noCommentsMessage.style.display = 'block';
                        }
                    }
                }
                
                // Set up the comment form
                const postCommentBtn = document.getElementById('postCommentBtn');
                const commentInput = document.getElementById('commentInput');
                
                if (postCommentBtn && commentInput) {
                    // Store the task ID for the comment submission
                    currentTaskId = taskId;
                    
                    // Remove any existing event listeners
                    const newPostCommentBtn = postCommentBtn.cloneNode(true);
                    postCommentBtn.parentNode.replaceChild(newPostCommentBtn, postCommentBtn);
                    
                    const newCommentInput = commentInput.cloneNode(true);
                    commentInput.parentNode.replaceChild(newCommentInput, commentInput);
                    
                    // Add event listeners
                    newPostCommentBtn.addEventListener('click', postComment);
                    newCommentInput.addEventListener('keypress', function(e) {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            postComment();
                        }
                    });
                }
            }
            
            // Modal is shown by the caller
            
        } catch (error) {
            console.error('Error populating task modal:', error);
            // If there's an error, display a simplified version with actual values
            document.getElementById('taskOverlayTitle').textContent = task?.title || 'Task Details';
            // For description, show exactly what's in the database (even if empty)
            // Description is handled in the main populateTaskModal function
            
            // Show the modal with limited info
            taskModal.show();
        }
    }
    
    // Helper function to format dates
    function formatDate(dateString) {
        const date = new Date(dateString);
        return date.toLocaleDateString('en-US', { 
            year: 'numeric', 
            month: 'short', 
            day: 'numeric' 
        });
    }
    
    // Helper function to capitalize first letter
    function capitalizeFirstLetter(string) {
        return string.charAt(0).toUpperCase() + string.slice(1);
    }
    
    // Helper function for debugging response issues
    function logResponseData(data) {
        if (!data) {
            console.error('No data received from server');
            return;
        }
        
        console.log('Response data:', {
            success: data.success,
            message: data.message || 'No message',
            taskDataPresent: !!data.task,
            taskProperties: data.task ? Object.keys(data.task) : []
        });
        
        // Check for common issues
        if (data.task) {
            const requiredProps = ['title', 'description', 'status', 'priority'];
            const missingProps = requiredProps.filter(prop => !data.task.hasOwnProperty(prop));
            
            if (missingProps.length > 0) {
                console.warn('Missing required task properties:', missingProps);
            }
        }
    }

    // Initialize description editor components
    let descriptionDisplay, descriptionEditor, descriptionTextarea, editDescriptionBtn;
    
    function initDescriptionEditor() {
        descriptionDisplay = document.getElementById('descriptionDisplay');
        descriptionEditor = document.getElementById('descriptionEditor');
        descriptionTextarea = document.getElementById('descriptionTextarea');
        editDescriptionBtn = document.getElementById('editDescriptionBtn');
        
        if (!descriptionDisplay || !descriptionEditor || !descriptionTextarea || !editDescriptionBtn) {
            console.error('Description editor elements not found');
            return;
        }
        
        // Add event listeners
        editDescriptionBtn.addEventListener('click', enterEditMode);
        document.getElementById('saveDescriptionBtn').addEventListener('click', saveDescription);
        document.getElementById('cancelDescriptionBtn').addEventListener('click', cancelEdit);
        
        // Add event listener for the main edit button in the task header
        const editTaskButton = document.getElementById('editTaskButton');
        if (editTaskButton) {
            editTaskButton.addEventListener('click', enterEditMode);
        }
    }
    
    // Call init function to set up editor
    initDescriptionEditor();
    
    // Function to enter description edit mode
    function enterEditMode() {
        // Check if user is the task owner
        if (!currentTaskData || currentTaskData.created_by != <?php echo $_SESSION['user_id']; ?>) {
            showToast('Only the task owner can edit the description', 'warning');
            return;
        }
        
        // Get the current description text
        const currentDescription = descriptionDisplay.textContent || '';
        
        // Set the textarea value to the current description
        descriptionTextarea.value = currentDescription;
        
        // Hide display and show editor
        descriptionDisplay.style.display = 'none';
        descriptionEditor.style.display = 'block';
        
        // Focus the textarea and place cursor at the end
        descriptionTextarea.focus();
        descriptionTextarea.setSelectionRange(
            descriptionTextarea.value.length, 
            descriptionTextarea.value.length
        );
        
        // Add transition class for animation
        descriptionEditor.classList.add('editor-active');
    }
    
    // Function to save the description
    function saveDescription() {
        const taskId = currentTaskId;
        
        // Validate task ID
        if (!taskId) {
            showToast('No task selected', 'danger');
            return;
        }
        
        // Check if user is the task owner for security
        if (!currentTaskData || currentTaskData.created_by != <?php echo $_SESSION['user_id']; ?>) {
            showToast('Only the task owner can edit the description', 'warning');
            cancelEdit();
            return;
        }
        
        // Get the new description text
        const newDescription = descriptionTextarea.value.trim();
        
        // Show saving indicator
        const saveBtn = document.getElementById('saveDescriptionBtn');
        if (saveBtn) {
            const originalText = saveBtn.innerHTML;
            saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            saveBtn.disabled = true;
            
            // Get CSRF token
            const csrfToken = '<?php echo $_SESSION['csrf_token']; ?>';
            
            // Send update to server
            fetch('../tasks/update_task_description.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-CSRF-Token': csrfToken
                },
                body: `task_id=${taskId}&description=${encodeURIComponent(newDescription)}&csrf_token=${csrfToken}`
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! Status: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                // Reset save button
                saveBtn.innerHTML = originalText;
                saveBtn.disabled = false;
                
                if (data.success) {
                    // Update the description display with the new content
                    descriptionDisplay.textContent = newDescription;
                    
                    // Exit edit mode
                    exitEditMode();
                    
                    // Show success message
                    showToast('Description updated successfully', 'success');
                } else {
                    // Show error message
                    showToast('Error saving description: ' + data.message, 'danger');
                }
            })
            .catch(error => {
                console.error('Error saving description:', error);
                
                // Reset save button
                saveBtn.innerHTML = originalText;
                saveBtn.disabled = false;
                
                // Show error message
                showToast('An error occurred while saving the description', 'danger');
            });
        }
    }
    
    // Function to cancel editing
    function cancelEdit() {
        exitEditMode();
    }
    
    // Function to exit edit mode
    function exitEditMode() {
        // Hide editor and show display
        descriptionEditor.style.display = 'none';
        descriptionDisplay.style.display = 'block';
        
        // Remove transition class
        descriptionEditor.classList.remove('editor-active');
    }
    
    // Function to create a comment element
    function createCommentElement(comment) {
        try {
            // Create the basic structure manually (more reliable than innerHTML)
            const commentEl = document.createElement('div');
            commentEl.className = 'comment-item';
            
            // Validate comment data
            if (!comment || typeof comment !== 'object') {
                console.warn('Invalid comment data received:', comment);
                return createErrorCommentElement();
            }
            
            // Store comment ID as a data attribute
            if (comment.comment_id) {
                commentEl.dataset.commentId = comment.comment_id;
            }
            
            // Store user ID as a data attribute to check permissions
            if (comment.user_id) {
                commentEl.dataset.userId = comment.user_id;
            }
            
            // Create swipe container wrapper
            const swipeContainer = document.createElement('div');
            swipeContainer.className = 'swipe-container';
            
            // Create comment content wrapper
            const commentContent = document.createElement('div');
            commentContent.className = 'comment-wrapper';
            
            // Create avatar div
            const avatarDiv = document.createElement('div');
            avatarDiv.className = 'commenter-avatar';
            
            // Get user information with fallbacks
            const firstName = comment.first_name || '';
            const lastName = comment.last_name || '';
            const profileImg = comment.profile_image || '';
            
            // Add avatar content (profile image or default)
            if (profileImg && profileImg.trim() !== '') {
                const img = document.createElement('img');
                // Check if profile image path needs '../../' prefix
                let imgSrc = profileImg;
                
                // Handle various path formats
                if (profileImg.startsWith('http')) {
                    imgSrc = profileImg;
                } else if (profileImg.startsWith('../../')) {
                    imgSrc = profileImg;
                } else if (profileImg.startsWith('/')) {
                    imgSrc = '../../' + profileImg.substring(1);
                } else {
                    imgSrc = '../../' + profileImg;
                }
                
                img.src = imgSrc;
                img.alt = firstName + ' ' + lastName;
                img.onerror = function() {
                    // If image fails to load, replace with default avatar
                    this.parentNode.removeChild(this);
                    const defaultAvatar = document.createElement('div');
                    defaultAvatar.className = 'default-avatar';
                    defaultAvatar.textContent = firstName ? firstName.charAt(0) : 'U';
                    avatarDiv.appendChild(defaultAvatar);
                };
                avatarDiv.appendChild(img);
            } else {
                const defaultAvatar = document.createElement('div');
                defaultAvatar.className = 'default-avatar';
                defaultAvatar.textContent = firstName ? firstName.charAt(0) : 'U';
                avatarDiv.appendChild(defaultAvatar);
            }
            
            // Create content div
            const contentDiv = document.createElement('div');
            contentDiv.className = 'comment-content';
            
            // Create header div
            const headerDiv = document.createElement('div');
            headerDiv.className = 'comment-header';
            
            // Create name span
            const nameSpan = document.createElement('span');
            nameSpan.className = 'commenter-name';
            nameSpan.textContent = (firstName + ' ' + lastName).trim() || 'Unknown User';
            
            // Create time span
            const timeSpan = document.createElement('span');
            timeSpan.className = 'comment-time';
            
            // Create time icon
            const timeIcon = document.createElement('i');
            timeIcon.className = 'far fa-clock';
            timeSpan.appendChild(timeIcon);
            
            // Add formatted time
            const createdAt = comment.created_at || '';
            const timeText = document.createTextNode(' ' + (createdAt ? formatTimeAgo(createdAt) : 'Just now'));
            timeSpan.appendChild(timeText);
            
            // Add name and time to header
            headerDiv.appendChild(nameSpan);
            headerDiv.appendChild(timeSpan);
            
            // Create comment text paragraph
            const commentTextP = document.createElement('p');
            commentTextP.className = 'comment-text';
            
            // Extract comment text from various possible fields
            let commentText = '';
            if (comment.comment_text !== undefined) {
                commentText = comment.comment_text;
            } else if (comment.comment !== undefined) {
                commentText = comment.comment;
            } else if (comment.content !== undefined) {
                commentText = comment.content;
            }
            
            commentTextP.textContent = commentText || '(No comment text)';
            
            // Assemble the comment content
            contentDiv.appendChild(headerDiv);
            contentDiv.appendChild(commentTextP);
            
            // Add avatar and content to the comment wrapper
            commentContent.appendChild(avatarDiv);
            commentContent.appendChild(contentDiv);
            
            // Create delete action div
            const deleteAction = document.createElement('div');
            deleteAction.className = 'delete-action';
            
            const deleteIcon = document.createElement('i');
            deleteIcon.className = 'fas fa-trash-alt';
            deleteAction.appendChild(deleteIcon);
            
            // Add delete action to swipe container
            swipeContainer.appendChild(commentContent);
            swipeContainer.appendChild(deleteAction);
            
            // Add swipe container to comment element
            commentEl.appendChild(swipeContainer);
            
            // Set up swipe functionality
            setupSwipeToDelete(commentEl, comment);
            
            return commentEl;
        } catch (error) {
            console.error('Error creating comment element:', error);
            return createErrorCommentElement();
        }
    }
    
    // Function to check if the current user has permission to delete a comment
    function checkCommentDeletePermission(comment) {
        if (!comment) return false;
        
        // Get the current user ID from PHP session
        const currentUserId = <?php echo $_SESSION['user_id']; ?>;
        
        // Check if the user is the comment author
        const isCommentAuthor = comment.user_id == currentUserId;
        
        // Check if the user is the task owner or assignee
        let isTaskOwnerOrAssignee = false;
        
        if (currentTaskData) {
            // Check if user is task creator
            const isTaskCreator = currentTaskData.created_by == currentUserId;
            
            // Check if user is task assignee
            const isTaskAssignee = currentTaskData.assigned_to == currentUserId;
            
            // Check if user is in assignees list
            let isInAssigneesList = false;
            if (currentTaskData.assignees && Array.isArray(currentTaskData.assignees)) {
                isInAssigneesList = currentTaskData.assignees.some(assignee => assignee.user_id == currentUserId);
            }
            
            isTaskOwnerOrAssignee = isTaskCreator || isTaskAssignee || isInAssigneesList;
        }
        
        // Check if user is project admin based on current permissions
        const isProjectAdmin = currentTaskPermissions && 
            (currentTaskPermissions.is_project_admin === true);
        
        // User can delete if they are the comment author, task owner/assignee, or project admin
        return isCommentAuthor || isTaskOwnerOrAssignee || isProjectAdmin;
    }
    
    // Function to delete a comment
    function deleteComment(commentId, commentElement) {
        if (!commentId || !commentElement) return;
        
        // Show loading state
        document.body.classList.add('loading');
        
        // Get CSRF token
        const csrfToken = '<?php echo $_SESSION['csrf_token']; ?>';
        
        // Create form data
        const formData = new FormData();
        formData.append('comment_id', commentId);
        formData.append('csrf_token', csrfToken);
        
        // Send delete request
        fetch('../tasks/delete_comment.php', {
            method: 'POST',
            body: formData,
            headers: {
                'X-CSRF-Token': csrfToken
            }
        })
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! Status: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            // Remove loading state
            document.body.classList.remove('loading');
            
            if (data.success) {
                // Show success message
                showToast('Comment deleted successfully', 'success');
                
                // Remove the comment element with animation
                commentElement.classList.add('deleting');
                
                // Wait for animation to complete then remove the element
                setTimeout(() => {
                    commentElement.remove();
                    
                    // Update comment counter
                    const commentsList = document.getElementById('commentsList');
                    const commentsCounter = document.getElementById('commentsCounter');
                    
                    if (commentsList && commentsCounter) {
                        const remainingComments = commentsList.querySelectorAll('.comment-item:not(.error)').length;
                        commentsCounter.textContent = `${remainingComments} ${remainingComments === 1 ? 'Comment' : 'Comments'}`;
                        
                        // Show no comments message if no comments left
                        if (remainingComments === 0) {
                            const noCommentsMessage = document.querySelector('.no-comments-message');
                            if (noCommentsMessage) {
                                noCommentsMessage.style.display = 'flex';
                            }
                        }
                    }
                }, 300);
            } else {
                // Show error message
                showToast('Error deleting comment: ' + data.message, 'danger');
                
                // Reset comment element
                const commentWrapper = commentElement.querySelector('.comment-wrapper');
                const deleteAction = commentElement.querySelector('.delete-action');
                
                if (commentWrapper && deleteAction) {
                    commentWrapper.style.transform = '';
                    deleteAction.style.opacity = 0;
                }
            }
        })
        .catch(error => {
            // Remove loading state
            document.body.classList.remove('loading');
            
            console.error('Error deleting comment:', error);
            showToast('An error occurred while deleting the comment', 'danger');
            
            // Reset comment element
            const commentWrapper = commentElement.querySelector('.comment-wrapper');
            const deleteAction = commentElement.querySelector('.delete-action');
            
            if (commentWrapper && deleteAction) {
                commentWrapper.style.transform = '';
                deleteAction.style.opacity = 0;
            }
        });
    }
    
    // Function to set up swipe-to-delete functionality
    function setupSwipeToDelete(commentEl, comment) {
        if (!commentEl || !comment || !comment.comment_id) return;
        
        const swipeContainer = commentEl.querySelector('.swipe-container');
        const commentWrapper = commentEl.querySelector('.comment-wrapper');
        const deleteAction = commentEl.querySelector('.delete-action');
        
        if (!swipeContainer || !commentWrapper || !deleteAction) return;
        
        let startX = 0;
        let currentX = 0;
        let isSwiping = false;
        let threshold = 100; // Minimum distance to trigger delete action
        
        // Check if current user has permission to delete this comment
        const canDelete = checkCommentDeletePermission(comment);
        
        // Only set up swipe if user has permission
        if (!canDelete) return;
        
        // Touch events for mobile
        swipeContainer.addEventListener('touchstart', function(e) {
            startX = e.touches[0].clientX;
            isSwiping = true;
            this.classList.add('swiping');
        });
        
        swipeContainer.addEventListener('touchmove', function(e) {
            if (!isSwiping) return;
            
            currentX = e.touches[0].clientX;
            let diff = currentX - startX;
            
            // Only allow right swipe (positive diff)
            if (diff > 0) {
                // Limit the swipe to threshold
                if (diff > threshold * 1.5) {
                    diff = threshold * 1.5;
                }
                
                commentWrapper.style.transform = `translateX(${diff}px)`;
                
                // Show delete action based on swipe progress
                const opacity = diff / threshold;
                deleteAction.style.opacity = opacity > 1 ? 1 : opacity;
            }
        });
        
        function handleSwipeEnd() {
            if (!isSwiping) return;
            
            isSwiping = false;
            swipeContainer.classList.remove('swiping');
            
            const diff = currentX - startX;
            
            if (diff >= threshold) {
                // Show confirmation with the delete action visible
                commentWrapper.style.transform = `translateX(${threshold}px)`;
                deleteAction.style.opacity = 1;
                
                // Ask for confirmation
                if (confirm('Are you sure you want to delete this comment?')) {
                    deleteComment(comment.comment_id, commentEl);
                } else {
                    // Reset position if canceled
                    commentWrapper.style.transform = '';
                    deleteAction.style.opacity = 0;
                }
            } else {
                // Reset position
                commentWrapper.style.transform = '';
                deleteAction.style.opacity = 0;
            }
        }
        
        swipeContainer.addEventListener('touchend', handleSwipeEnd);
        swipeContainer.addEventListener('touchcancel', handleSwipeEnd);
        
        // Mouse events for desktop
        swipeContainer.addEventListener('mousedown', function(e) {
            startX = e.clientX;
            isSwiping = true;
            this.classList.add('swiping');
            e.preventDefault(); // Prevent text selection
        });
        
        document.addEventListener('mousemove', function(e) {
            if (!isSwiping) return;
            
            currentX = e.clientX;
            let diff = currentX - startX;
            
            // Only allow right swipe (positive diff)
            if (diff > 0) {
                // Limit the swipe to threshold
                if (diff > threshold * 1.5) {
                    diff = threshold * 1.5;
                }
                
                commentWrapper.style.transform = `translateX(${diff}px)`;
                
                // Show delete action based on swipe progress
                const opacity = diff / threshold;
                deleteAction.style.opacity = opacity > 1 ? 1 : opacity;
            }
        });
        
        document.addEventListener('mouseup', function() {
            handleSwipeEnd();
        });
        
        // Add click handler to delete action
        deleteAction.addEventListener('click', function() {
            if (confirm('Are you sure you want to delete this comment?')) {
                deleteComment(comment.comment_id, commentEl);
            } else {
                // Reset position if canceled
                commentWrapper.style.transform = '';
                deleteAction.style.opacity = 0;
            }
        });
    }
    
    // Create a fallback comment element in case of error
    function createErrorCommentElement() {
        const errorEl = document.createElement('div');
        errorEl.className = 'comment-item error';
        
        // Create avatar
        const avatarDiv = document.createElement('div');
        avatarDiv.className = 'commenter-avatar';
        
        const defaultAvatar = document.createElement('div');
        defaultAvatar.className = 'default-avatar';
        
        const iconElement = document.createElement('i');
        iconElement.className = 'fas fa-exclamation-triangle';
        defaultAvatar.appendChild(iconElement);
        
        avatarDiv.appendChild(defaultAvatar);
        
        // Create content
        const contentDiv = document.createElement('div');
        contentDiv.className = 'comment-content';
        
        // Create header
        const headerDiv = document.createElement('div');
        headerDiv.className = 'comment-header';
        
        const nameSpan = document.createElement('span');
        nameSpan.className = 'commenter-name';
        nameSpan.textContent = 'Error';
        
        headerDiv.appendChild(nameSpan);
        
        // Create text
        const textP = document.createElement('p');
        textP.className = 'comment-text';
        textP.textContent = 'Error displaying comment';
        
        // Assemble all
        contentDiv.appendChild(headerDiv);
        contentDiv.appendChild(textP);
        
        errorEl.appendChild(avatarDiv);
        errorEl.appendChild(contentDiv);
        
        return errorEl;
    }
    
    // Format relative time (time ago)
    function formatTimeAgo(dateString) {
        const date = new Date(dateString);
        const now = new Date();
        const diffInSeconds = Math.floor((now - date) / 1000);
        
        if (diffInSeconds < 60) {
            return 'Just now';
        } else if (diffInSeconds < 3600) {
            const minutes = Math.floor(diffInSeconds / 60);
            return `${minutes} ${minutes === 1 ? 'minute' : 'minutes'} ago`;
        } else if (diffInSeconds < 86400) {
            const hours = Math.floor(diffInSeconds / 3600);
            return `${hours} ${hours === 1 ? 'hour' : 'hours'} ago`;
        } else if (diffInSeconds < 2592000) {
            const days = Math.floor(diffInSeconds / 86400);
            return `${days} ${days === 1 ? 'day' : 'days'} ago`;
        } else {
            return formatDate(dateString);
        }
    }
    
    // Function to post a comment
    function postComment() {
        const commentInput = document.getElementById('commentInput');
        const commentText = commentInput.value.trim();
        
        if (!commentText || !currentTaskId) {
            return;
        }
        
        // Show loading state
        const postButton = document.getElementById('postCommentBtn');
        const loadingIndicator = document.querySelector('.comment-loading-indicator');
        
        postButton.style.display = 'none';
        loadingIndicator.style.display = 'block';
        commentInput.disabled = true;
        
        // Store comment text for retry
        const originalComment = commentText;
        
        // Create form data
        const formData = new FormData();
        formData.append('task_id', currentTaskId);
        formData.append('comment_text', commentText);
        formData.append('csrf_token', '<?php echo $_SESSION['csrf_token']; ?>');
        
        // Post comment with retry capability
        postCommentWithRetry(formData, originalComment, 2); // Try up to 2 times
    }
    
    // Function to post a comment with retry capability
    function postCommentWithRetry(formData, commentText, retriesLeft) {
        // Get UI elements
        const csrfToken = '<?php echo $_SESSION['csrf_token']; ?>';
        const postButton = document.getElementById('postCommentBtn');
        const loadingIndicator = document.querySelector('.comment-loading-indicator');
        const commentInput = document.getElementById('commentInput');
        
        // Validate that we have the needed form data
        if (!formData.get('task_id') || !formData.get('comment_text')) {
            // Reset UI state
            if (postButton) postButton.style.display = 'flex';
            if (loadingIndicator) loadingIndicator.style.display = 'none';
            if (commentInput) commentInput.disabled = false;
            
            showToast('Unable to post comment: missing required data', 'danger');
            return;
        }
        
        try {
            // Send request to server
            fetch('../tasks/add_task_comment.php', {
                method: 'POST',
                headers: {
                    'X-CSRF-Token': csrfToken
                },
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                // Reset UI state
                if (postButton) postButton.style.display = 'flex';
                if (loadingIndicator) loadingIndicator.style.display = 'none';
                if (commentInput) commentInput.disabled = false;
                
                if (data && data.success) {
                    // Clear input
                    if (commentInput) commentInput.value = '';
                    
                    // Add new comment to the list
                    const commentsList = document.getElementById('commentsList');
                    const noCommentsMessage = document.querySelector('.no-comments-message');
                    const commentsLoading = document.querySelector('.comments-loading');
                    
                    if (commentsList) {
                        // Hide loading and no comments message
                        if (commentsLoading) commentsLoading.style.display = 'none';
                        if (noCommentsMessage) noCommentsMessage.style.display = 'none';
                        
                        // Ensure we have valid comment data
                        if (!data.comment || typeof data.comment !== 'object') {
                            // Refresh the comments instead of trying to display invalid data
                            setTimeout(() => loadTaskComments(currentTaskId), 500);
                            return;
                        }
                        
                        try {
                            // Create comment element
                            const commentEl = createCommentElement(data.comment);
                            
                            // Insert at the beginning of the list
                            if (commentsList.firstChild) {
                                commentsList.insertBefore(commentEl, commentsList.firstChild);
                            } else {
                                commentsList.appendChild(commentEl);
                            }
                            
                            // Update comment counter
                            updateCommentCounter();
                            
                            // Scroll to the new comment
                            commentsList.scrollTop = 0;
                        } catch (displayError) {
                            // If displaying the comment fails, refresh all comments
                            setTimeout(() => loadTaskComments(currentTaskId), 500);
                        }
                    }
                } else {
                    // Handle error from server
                    const errorMsg = data && data.message ? data.message : 'Unknown server error';
                    showToast(`Error: ${errorMsg}`, 'danger');
                }
            })
            .catch(error => {
                // Check if we should retry
                if (retriesLeft > 0 && (error.message.includes('Failed to fetch') || error.message.includes('HTTP error'))) {
                    // Wait before retrying
                    setTimeout(() => {
                        postCommentWithRetry(formData, commentText, retriesLeft - 1);
                    }, 1000); // Longer retry delay
                    return;
                }
                
                // Reset UI state after final failure
                if (postButton) postButton.style.display = 'flex';
                if (loadingIndicator) loadingIndicator.style.display = 'none';
                if (commentInput) {
                    commentInput.disabled = false;
                    // Keep the original comment text so user doesn't lose their input
                    commentInput.value = commentText;
                    // Focus the input for easy editing
                    commentInput.focus();
                }
                
                // Show specific error message
                if (error.message.includes('Failed to fetch')) {
                    showToast('Network error. Your comment was not posted. Please try again.', 'danger');
                } else {
                    showToast('Unable to post your comment. Please try again.', 'danger');
                }
            });
        } catch (error) {
            // Reset UI state
            if (postButton) postButton.style.display = 'flex';
            if (loadingIndicator) loadingIndicator.style.display = 'none';
            if (commentInput) commentInput.disabled = false;
            
            showToast('An unexpected error occurred. Please try again.', 'danger');
        }
    }
    
    // Function to show toast notifications
    function showToast(message, type = 'info') {
        // Create toast container if it doesn't exist
        let toastContainer = document.querySelector('.toast-container');
        if (!toastContainer) {
            toastContainer = document.createElement('div');
            toastContainer.className = 'toast-container';
            document.body.appendChild(toastContainer);
        }
        
        // Create toast element
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        
        // Choose the appropriate icon based on message type
        let icon = 'fa-info-circle';
        if (type === 'danger') {
            icon = 'fa-exclamation-circle';
        } else if (type === 'success') {
            icon = 'fa-check-circle';
        }
        
        toast.innerHTML = `
            <div class="toast-content">
                <i class="fas ${icon}"></i>
                <span>${message}</span>
            </div>
            <button class="toast-close"><i class="fas fa-times"></i></button>
        `;
        
        // Add to container
        toastContainer.appendChild(toast);
        
        // Show toast with animation
        setTimeout(() => {
            toast.classList.add('show');
        }, 10);
        
        // Auto dismiss after 5 seconds
        setTimeout(() => {
            dismissToast(toast);
        }, 5000);
        
        // Add close button event
        toast.querySelector('.toast-close').addEventListener('click', () => {
            dismissToast(toast);
        });
    }
    
    // Function to dismiss toast
    function dismissToast(toast) {
        toast.classList.add('hiding');
        setTimeout(() => {
            if (toast.parentNode) {
                toast.parentNode.removeChild(toast);
            }
        }, 300);
    }
    
    // Function to update comment counter
    function updateCommentCounter() {
        const commentsList = document.getElementById('commentsList');
        const commentsCounter = document.getElementById('commentsCounter');
        
        if (commentsList && commentsCounter) {
            const count = commentsList.querySelectorAll('.comment-item').length;
            commentsCounter.textContent = `${count} ${count === 1 ? 'Comment' : 'Comments'}`;
        }
    }
    
    // Add styles for toast notifications
    const toastStyle = document.createElement('style');
    toastStyle.textContent = `
        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 10px;
            max-width: 320px;
        }
        
        .toast {
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            padding: 12px 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            transform: translateX(100%);
            opacity: 0;
            transition: transform 0.3s ease, opacity 0.3s ease;
            overflow: hidden;
        }
        
        .toast.show {
            transform: translateX(0);
            opacity: 1;
        }
        
        .toast.hiding {
            transform: translateX(100%);
            opacity: 0;
        }
        
        .toast-content {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .toast-content i {
            font-size: 18px;
        }
        
        .toast-info i {
    color: #007bff;
}

.toast-success i {
    color: #28a745;
}

.toast-danger i {
    color: #dc3545;
}
        
        .toast-close {
            background: none;
            border: none;
            color: #6c757d;
            cursor: pointer;
            font-size: 16px;
            padding: 0;
        }
        
        .toast-close:hover {
            color: #343a40;
        }
    `;
    document.head.appendChild(toastStyle);
    
    // Function to load task comments
    function loadTaskComments(taskId) {
        if (!taskId) return;
        
        const commentsList = document.getElementById('commentsList');
        const noCommentsMessage = document.querySelector('.no-comments-message');
        const commentsLoading = document.querySelector('.comments-loading');
        
        // Show loading state
        if (commentsList && commentsLoading) {
            commentsList.innerHTML = '';
            noCommentsMessage.style.display = 'none';
            commentsLoading.style.display = 'flex';
        }
        
        // Set the current task ID for the comment submission
        currentTaskId = taskId;
        
        // Fetch comments with retry capability
        fetchCommentsWithRetry(taskId, 2); // Try up to 2 times
    }
    
    // Function to fetch comments with retry capability
    function fetchCommentsWithRetry(taskId, retriesLeft) {
        // Validate input
        if (!taskId) {
            console.error('Invalid task ID for comments fetch');
            return;
        }
        
        // Get UI elements
        const commentsList = document.getElementById('commentsList');
        const noCommentsMessage = document.querySelector('.no-comments-message');
        const commentsLoading = document.querySelector('.comments-loading');
        
        // Show loading state
        if (commentsList && commentsLoading) {
            commentsList.innerHTML = '';
            if (noCommentsMessage) noCommentsMessage.style.display = 'none';
            commentsLoading.style.display = 'flex';
        }
        
        // Get CSRF token
        const csrfToken = '<?php echo $_SESSION['csrf_token']; ?>';
        
        try {
            // Make the fetch request with proper error handling
            fetch(`../tasks/get_task_comments.php?task_id=${taskId}&csrf_token=${csrfToken}`, {
                method: 'GET',
                headers: {
                    'X-CSRF-Token': csrfToken
                },
                cache: 'no-store' // Prevent caching
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                // Hide loading state
                if (commentsLoading) {
                    commentsLoading.style.display = 'none';
                }
                
                // Check if we have valid data
                if (data && data.success && Array.isArray(data.comments)) {
                    // Check if comments array is empty
                    if (data.comments.length === 0) {
                        if (noCommentsMessage) {
                            noCommentsMessage.style.display = 'flex';
                            const commentsCounter = document.getElementById('commentsCounter');
                            if (commentsCounter) {
                                commentsCounter.textContent = '0 Comments';
                            }
                        }
                        return;
                    }
                    
                    // We have comments, sort them by creation date (newest first)
                    const comments = data.comments.sort((a, b) => {
                        const dateA = a.created_at ? new Date(a.created_at) : new Date(0);
                        const dateB = b.created_at ? new Date(b.created_at) : new Date(0);
                        return dateB - dateA;
                    });
                    
                    // Check if we have comments list to display them
                    if (commentsList) {
                        // Clear any existing comments
                        commentsList.innerHTML = '';
                        
                        // Update counter first
                        const commentsCounter = document.getElementById('commentsCounter');
                        if (commentsCounter) {
                            const count = comments.length;
                            commentsCounter.textContent = `${count} ${count === 1 ? 'Comment' : 'Comments'}`;
                        }
                        
                        // Add comments one by one without animation delay
                        comments.forEach(comment => {
                            try {
                                // Skip invalid comments
                                if (!comment || typeof comment !== 'object') {
                                    return;
                                }
                                
                                // Create the comment element
                                const commentEl = createCommentElement(comment);
                                if (commentEl) {
                                    commentsList.appendChild(commentEl);
                                }
                            } catch (err) {
                                console.error('Error processing comment:', err);
                            }
                        });
                        
                        // Hide no comments message
                        if (noCommentsMessage) {
                            noCommentsMessage.style.display = 'none';
                        }
                    }
                } else {
                    // No valid data or empty comments array
                    if (noCommentsMessage) {
                        noCommentsMessage.style.display = 'flex';
                        const commentsCounter = document.getElementById('commentsCounter');
                        if (commentsCounter) {
                            commentsCounter.textContent = '0 Comments';
                        }
                    }
                }
            })
            .catch(error => {
                console.error('Error loading comments:', error);
                
                // Check if we should retry
                if (retriesLeft > 0 && (error.message.includes('Failed to fetch') || error.message.includes('HTTP error'))) {
                    console.log(`Retrying comments load... ${retriesLeft} attempts left`);
                    // Wait a bit before retrying (500ms)
                    setTimeout(() => {
                        fetchCommentsWithRetry(taskId, retriesLeft - 1);
                    }, 500);
                    return;
                }
                
                // Hide loading state
                if (commentsLoading) {
                    commentsLoading.style.display = 'none';
                }
                
                // Create error message elements
                if (noCommentsMessage) {
                    noCommentsMessage.style.display = 'flex';
                    
                    // Create error message elements manually
                    noCommentsMessage.innerHTML = '';
                    
                    const iconDiv = document.createElement('div');
                    iconDiv.className = 'empty-comments-icon';
                    
                    const icon = document.createElement('i');
                    icon.className = 'fas fa-exclamation-circle';
                    iconDiv.appendChild(icon);
                    
                    const text = document.createElement('p');
                    text.textContent = 'Unable to load comments';
                    
                    const retryBtn = document.createElement('button');
                    retryBtn.className = 'btn btn-sm btn-outline-primary mt-2';
                    retryBtn.innerHTML = '<i class="fas fa-sync-alt"></i> Try again';
                    retryBtn.addEventListener('click', function() {
                        loadTaskComments(taskId);
                    });
                    
                    noCommentsMessage.appendChild(iconDiv);
                    noCommentsMessage.appendChild(text);
                    noCommentsMessage.appendChild(retryBtn);
                }
            });
        } catch (error) {
            console.error('Critical error in fetchCommentsWithRetry:', error);
            
            // Hide loading state
            if (commentsLoading) {
                commentsLoading.style.display = 'none';
            }
            
            // Show simple error message without HTML
            if (noCommentsMessage) {
                noCommentsMessage.style.display = 'flex';
                noCommentsMessage.textContent = 'Error loading comments. Please try again later.';
            }
        }
    }

    // Add event listener for the star button in the overlay
    const starButton = document.querySelector('.star-button');
    starButton.addEventListener('click', function() {
        this.classList.toggle('active');
        if (this.classList.contains('active')) {
            this.querySelector('i').classList.remove('far');
            this.querySelector('i').classList.add('fas');
        } else {
            this.querySelector('i').classList.remove('fas');
            this.querySelector('i').classList.add('far');
        }
    });
    
    // Attachment handling
    let currentTaskId = null;
    
    // Function to load attachments for a task
    function loadTaskAttachments(taskId) {
        if (!taskId) {
            console.error('No task ID provided for loading attachments');
            return;
        }
        
        console.log('Loading attachments for task ID:', taskId);
        
        currentTaskId = taskId;
        const attachmentsContainer = document.getElementById('taskAttachments');
        
        if (!attachmentsContainer) {
            console.error('Attachments container not found');
            return;
        }
        
        attachmentsContainer.innerHTML = '<div class="loading-spinner"><i class="fas fa-spinner fa-spin"></i> Loading attachments...</div>';
        
        // Set the task ID for the upload form
        const attachmentTaskIdInput = document.getElementById('attachmentTaskId');
        if (attachmentTaskIdInput) {
            attachmentTaskIdInput.value = taskId;
        }
        
        // Fetch attachments with retry capability
        fetchAttachmentsWithRetry(taskId, attachmentsContainer, 2); // Try up to 2 times
    }
    
    // Function to fetch attachments with retry capability
    function fetchAttachmentsWithRetry(taskId, attachmentsContainer, retriesLeft) {
        // Fetch attachments for this task
        const csrfToken = '<?php echo $_SESSION['csrf_token']; ?>';
        
        try {
            fetch(`../attachments/get_task_attachments.php?task_id=${taskId}&csrf_token=${csrfToken}`, {
                method: 'GET',
                headers: {
                    'X-CSRF-Token': csrfToken,
                    'Content-Type': 'application/json'
                },
                cache: 'no-store' // Prevent caching
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                // Check if container still exists (might have been removed if overlay was closed)
                if (!attachmentsContainer || !document.body.contains(attachmentsContainer)) {
                    console.log('Attachment container no longer in DOM, aborting render');
                    return;
                }
                
                attachmentsContainer.innerHTML = ''; // Clear the container
                
                if (data.success && data.attachments && Array.isArray(data.attachments) && data.attachments.length > 0) {
                    console.log(`Found ${data.attachments.length} attachments for task ${taskId}`);
                    
                    // Count of successful attachments rendered
                    let successCount = 0;
                    
                    // Render each attachment
                    data.attachments.forEach(attachment => {
                        if (!attachment || typeof attachment !== 'object') {
                            console.warn('Invalid attachment data:', attachment);
                            return;
                        }
                        
                        try {
                            const attachmentEl = createAttachmentElement(attachment);
                            attachmentsContainer.appendChild(attachmentEl);
                            successCount++;
                        } catch (attachmentError) {
                            console.error('Error rendering attachment:', attachmentError);
                        }
                    });
                    
                    // If all attachments failed to render
                    if (successCount === 0 && data.attachments.length > 0) {
                        attachmentsContainer.innerHTML = '<div class="alert alert-warning"><i class="fas fa-exclamation-triangle"></i> There was an error displaying attachments</div>';
                    }
                } else {
                    // No attachments found
                    console.log(`No attachments found for task ${taskId}`);
                    attachmentsContainer.innerHTML = '<p class="text-muted">No attachments yet.</p>';
                }
            })
            .catch(error => {
                console.error('Error loading attachments:', error);
                
                // Check if we should retry
                if (retriesLeft > 0 && (error.message.includes('Failed to fetch') || error.message.includes('HTTP error'))) {
                    console.log(`Retrying attachments load... ${retriesLeft} attempts left`);
                    // Wait a bit before retrying (300ms)
                    setTimeout(() => {
                        fetchAttachmentsWithRetry(taskId, attachmentsContainer, retriesLeft - 1);
                    }, 300);
                    return;
                }
                
                if (attachmentsContainer && document.body.contains(attachmentsContainer)) {
                    const alertDiv = document.createElement('div');
                    alertDiv.className = 'alert alert-danger';
                    alertDiv.innerHTML = `
                        <i class="fas fa-exclamation-circle"></i> Unable to load attachments
                        <button class="btn btn-sm btn-outline-danger ms-2 retry-btn">
                            <i class="fas fa-sync-alt"></i> Try again
                        </button>
                    `;
                    
                    attachmentsContainer.innerHTML = '';
                    attachmentsContainer.appendChild(alertDiv);
                    
                    // Add event listener to the retry button
                    const retryBtn = attachmentsContainer.querySelector('.retry-btn');
                    if (retryBtn) {
                        retryBtn.addEventListener('click', function() {
                            loadTaskAttachments(taskId);
                        });
                    }
                }
            });
        } catch (error) {
            console.error('Critical error in fetchAttachmentsWithRetry:', error);
            if (attachmentsContainer && document.body.contains(attachmentsContainer)) {
                attachmentsContainer.innerHTML = '<div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> Unable to load attachments</div>';
            }
        }
    }
    
    // Function to create an attachment element
    function createAttachmentElement(attachment) {
        if (!attachment || typeof attachment !== 'object') {
            console.error('Invalid attachment data', attachment);
            return createErrorAttachmentElement();
        }
        
        try {
            // Validate required fields
            if (!attachment.filename || !attachment.attachment_id) {
                console.warn('Attachment missing required fields', attachment);
                return createErrorAttachmentElement();
            }
            
            const attachmentEl = document.createElement('div');
            attachmentEl.className = 'attachment-item';
            
            // Escape HTML to prevent XSS
            const escapeHTML = (str) => {
                if (str === null || str === undefined) return '';
                return String(str)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            };
            
            // Get filename safely
            const filename = escapeHTML(attachment.filename || 'Unknown file');
            
            // Safely get file extension
            let fileExt = '';
            if (attachment.filename && typeof attachment.filename === 'string') {
                const parts = attachment.filename.split('.');
                if (parts.length > 1) {
                    fileExt = parts.pop().toLowerCase();
                }
            }
            
            // Determine the file type icon based on the file extension
            let fileIcon = 'fas fa-file'; // Default icon
            
            if (['jpg', 'jpeg', 'png', 'gif', 'bmp', 'svg'].includes(fileExt)) {
                fileIcon = 'fas fa-file-image';
            } else if (['pdf'].includes(fileExt)) {
                fileIcon = 'fas fa-file-pdf';
            } else if (['doc', 'docx'].includes(fileExt)) {
                fileIcon = 'fas fa-file-word';
            } else if (['xls', 'xlsx'].includes(fileExt)) {
                fileIcon = 'fas fa-file-excel';
            } else if (['zip', 'rar', '7z'].includes(fileExt)) {
                fileIcon = 'fas fa-file-archive';
            }
            
            // Get filesize safely
            const filesize = (attachment.filesize !== undefined && attachment.filesize !== null) 
                ? formatFileSize(attachment.filesize) 
                : 'Unknown size';
                
            // Get uploaded date safely
            const uploadedDate = (attachment.uploaded_at && typeof attachment.uploaded_at === 'string')
                ? formatDate(attachment.uploaded_at)
                : '';
            
            // Get attachment ID safely
            const attachmentId = attachment.attachment_id;
            if (!attachmentId) {
                throw new Error('Attachment missing ID');
            }
            
            // Create the element with safe values
            attachmentEl.innerHTML = `
                <div class="attachment-icon">
                    <i class="${fileIcon}"></i>
                </div>
                <div class="attachment-details">
                    <div class="attachment-name">${filename}</div>
                    <div class="attachment-meta">
                        <span class="attachment-size">${filesize}</span>
                        <span class="attachment-date">${uploadedDate}</span>
                    </div>
                </div>
                <div class="attachment-actions">
                    ${currentTaskPermissions.can_upload_attachments ? 
                        `<a href="../attachments/download_attachment.php?id=${attachmentId}&csrf_token=<?php echo $_SESSION['csrf_token']; ?>" 
                           class="attachment-download" title="Download" target="_blank">
                            <i class="fas fa-download"></i>
                        </a>` : 
                        `<span class="attachment-download disabled" title="You don't have permission to download this file">
                            <i class="fas fa-download text-muted"></i>
                        </span>`
                    }
                    ${currentTaskPermissions.can_upload_attachments ? 
                        `<button class="attachment-delete" title="Delete" data-attachment-id="${attachmentId}">
                            <i class="fas fa-trash-alt"></i>
                        </button>` : ''}
                </div>
            `;
            
            // Add event listener for delete button
            const deleteBtn = attachmentEl.querySelector('.attachment-delete');
            if (deleteBtn) {
                deleteBtn.addEventListener('click', function() {
                    const attachmentId = this.getAttribute('data-attachment-id');
                    if (confirm('Are you sure you want to delete this attachment?')) {
                        deleteAttachment(attachmentId);
                    }
                });
            }
            
            return attachmentEl;
        } catch (error) {
            console.error('Error creating attachment element:', error);
            return createErrorAttachmentElement();
        }
    }
    
    // Create a fallback error attachment element
    function createErrorAttachmentElement() {
        const errorEl = document.createElement('div');
        errorEl.className = 'attachment-item error';
        errorEl.innerHTML = `
            <div class="attachment-icon">
                <i class="fas fa-exclamation-triangle text-danger"></i>
            </div>
            <div class="attachment-details">
                <div class="attachment-name">Error loading attachment</div>
                <div class="attachment-meta">
                    <span class="attachment-size">Unknown</span>
                </div>
            </div>
        `;
        return errorEl;
    }
    
    // Format file size in a human-readable format
    function formatFileSize(bytes) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }
    
    // Function to delete an attachment
    function deleteAttachment(attachmentId) {
        // Check if the user has permission to manage attachments
        if (!currentTaskPermissions.can_upload_attachments) {
            showToast('You don\'t have permission to delete attachments from this task', 'warning');
            return;
        }
        
        // Show loading state
        document.body.classList.add('loading');
        
        fetch('../attachments/delete_attachment.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-Token': '<?php echo $_SESSION['csrf_token']; ?>'
            },
            body: `attachment_id=${attachmentId}&csrf_token=<?php echo $_SESSION['csrf_token']; ?>`
        })
        .then(response => response.json())
        .then(data => {
            // Remove loading state
            document.body.classList.remove('loading');
            
            if (data.success) {
                // Show success message
                showToast('Attachment deleted successfully', 'success');
                
                // Reload attachments after successful deletion
                loadTaskAttachments(currentTaskId);
            } else {
                // Show error message
                showToast('Failed to delete attachment: ' + data.message, 'danger');
            }
        })
        .catch(error => {
            // Remove loading state
            document.body.classList.remove('loading');
            
            console.error('Error deleting attachment:', error);
            showToast('An error occurred while deleting the attachment', 'danger');
        });
    }
    
    // Handle attachment upload button click
    document.getElementById('attachFileBtn').addEventListener('click', function() {
        // Check if the user has permission to upload attachments
        if (!currentTaskPermissions.can_upload_attachments) {
            showToast('You don\'t have permission to upload attachments to this task', 'warning');
            return;
        }
        
        const attachmentInput = document.getElementById('attachmentInput');
        if (attachmentInput) {
            // Reset the input to allow selecting the same file multiple times
            attachmentInput.value = '';
            attachmentInput.click();
        } else {
            showToast('Error: File input element not found', 'danger');
        }
    });
    
    // Handle file selection
    document.getElementById('attachmentInput').addEventListener('change', function() {
        if (this.files && this.files.length > 0) {
            uploadAttachment(this.files[0]);
        }
    });
    
    // Function to upload an attachment
    function uploadAttachment(file) {
        // Check if the user has permission to upload attachments
        if (!currentTaskPermissions.can_upload_attachments) {
            showToast('You don\'t have permission to upload attachments to this task', 'warning');
            return;
        }
        
        // Validate file size (10MB limit)
        const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10MB in bytes
        if (file.size > MAX_FILE_SIZE) {
            showToast('File size exceeds 10MB limit. Please choose a smaller file.', 'danger');
            return;
        }
        
        // Create form data for the upload
        const formData = new FormData();
        formData.append('attachment', file);
        formData.append('task_id', currentTaskId);
        formData.append('csrf_token', '<?php echo $_SESSION['csrf_token']; ?>');
        
        // Disable the attach button during upload
        const attachButton = document.getElementById('attachFileBtn');
        if (attachButton) {
            attachButton.disabled = true;
            attachButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading...';
        }
        
        // Show loading indicator
        const attachmentsContainer = document.getElementById('taskAttachments');
        const loadingEl = document.createElement('div');
        loadingEl.className = 'attachment-item uploading';
        loadingEl.innerHTML = `
            <div class="attachment-icon">
                <i class="fas fa-cloud-upload-alt"></i>
            </div>
            <div class="attachment-details">
                <div class="attachment-name">${file.name}</div>
                <div class="attachment-meta">
                    <span class="attachment-status">Uploading...</span>
                </div>
            </div>
        `;
        attachmentsContainer.appendChild(loadingEl);
        
        // Upload the file
        fetch('../attachments/upload_attachment.php', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! Status: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            // Remove loading indicator
            loadingEl.remove();
            
            // Reset attach button
            if (attachButton) {
                attachButton.disabled = false;
                attachButton.innerHTML = '<i class="fas fa-paperclip"></i> Attach File';
            }
            
            if (data.success) {
                // Show success message
                showToast('File uploaded successfully', 'success');
                
                // Reload attachments after successful upload
                loadTaskAttachments(currentTaskId);
            } else {
                // Show specific error message from server
                const errorMsg = data.message || 'Unknown error occurred';
                console.error('Server error during upload:', errorMsg);
                showToast('Failed to upload attachment: ' + errorMsg, 'danger');
            }
        })
        .catch(error => {
            // Remove loading indicator
            loadingEl.remove();
            
            // Reset attach button
            if (attachButton) {
                attachButton.disabled = false;
                attachButton.innerHTML = '<i class="fas fa-paperclip"></i> Attach File';
            }
            
            // Remove loading state from body
            document.body.classList.remove('loading');
            
            console.error('Error uploading attachment:', error);
            
            // Show more specific error message based on error type
            if (error.name === 'TypeError' && error.message.includes('Failed to fetch')) {
                showToast('Network error: Could not connect to server. Please check your internet connection.', 'danger');
            } else if (error.message.includes('HTTP error')) {
                showToast('Server error: ' + error.message, 'danger');
            } else {
                showToast('An error occurred while uploading the attachment: ' + error.message, 'danger');
            }
        });
    }
});
</script>

<?php include '../../includes/footer.php'; ?> 