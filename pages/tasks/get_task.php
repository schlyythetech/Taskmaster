<?php
require_once '../../includes/functions.php';
require_once '../../config/database.php';

// Set content type to JSON
header('Content-Type: application/json');

// Check if user is logged in
if (!isLoggedIn()) {
    echo json_encode([
        'success' => false,
        'message' => 'You must be logged in to view task details.'
    ]);
    exit;
}

// Validate CSRF token from header, GET, or POST
$csrf_header = isset($_SERVER['HTTP_X_CSRF_TOKEN']) ? $_SERVER['HTTP_X_CSRF_TOKEN'] : '';
$csrf_get = isset($_GET['csrf_token']) ? $_GET['csrf_token'] : '';
$csrf_post = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';

$csrf_provided = $csrf_header ?: $csrf_get ?: $csrf_post;

if (!isset($_SESSION['csrf_token']) || empty($csrf_provided) || $csrf_provided !== $_SESSION['csrf_token']) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid or missing CSRF token.'
    ]);
    exit;
}

// Check if task ID and project ID are provided
if (!isset($_GET['task_id']) || !is_numeric($_GET['task_id']) || !isset($_GET['project_id']) || !is_numeric($_GET['project_id'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid task or project ID.'
    ]);
    exit;
}

$task_id = $_GET['task_id'];
$project_id = $_GET['project_id'];
$user_id = $_SESSION['user_id'];

try {
    // Check if user is a member of this project
    $stmt = $conn->prepare("
        SELECT pm.* FROM project_members pm
        WHERE pm.project_id = ? AND pm.user_id = ?
    ");
    $stmt->execute([$project_id, $user_id]);
    $membership = $stmt->fetch();
    
    if (!$membership) {
        echo json_encode([
            'success' => false,
            'message' => 'You do not have access to this project.'
        ]);
        exit;
    }
    
    // Determine user's permissions for the task
    $isProjectOwner = ($membership['role'] === 'owner');
    $isProjectAdmin = ($membership['role'] === 'admin' || $isProjectOwner);
    
    // Get task details
    $stmt = $conn->prepare("
        SELECT t.*, e.title as epic_title,
               u_creator.first_name as creator_first_name, u_creator.last_name as creator_last_name,
               u_creator.profile_image as creator_profile_image,
               u_assigned.first_name as assigned_first_name, u_assigned.last_name as assigned_last_name,
               u_assigned.profile_image as assigned_profile_image
        FROM tasks t
        LEFT JOIN epics e ON t.epic_id = e.epic_id
        LEFT JOIN users u_creator ON t.created_by = u_creator.user_id
        LEFT JOIN users u_assigned ON t.assigned_to = u_assigned.user_id
        WHERE t.task_id = ? AND t.project_id = ?
    ");
    $stmt->execute([$task_id, $project_id]);
    $task = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Check for multiple assignees
    $assignees = [];
    try {
        // Use the tableExists function
        $tableExists = tableExists($conn, 'task_assignees');
        
        if ($tableExists) {
            $stmt = $conn->prepare("
                SELECT ta.*, u.user_id, u.first_name, u.last_name, u.profile_image
                FROM task_assignees ta
                JOIN users u ON ta.user_id = u.user_id
                WHERE ta.task_id = ?
            ");
            $stmt->execute([$task_id]);
            $assignees = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        error_log("Error fetching assignees: " . $e->getMessage());
        // Silently fail and just return an empty assignees array
        $assignees = [];
    }
    
    // Add assignees to task data
    $task['assignees'] = $assignees;
    
    if (!$task) {
        echo json_encode([
            'success' => false,
            'message' => 'Task not found.'
        ]);
        exit;
    }
    
    // Get task comments if they exist in the database
    $comments = [];
    try {
        // Use the tableExists function
        $tableExists = tableExists($conn, 'task_comments');
        
        if ($tableExists) {
            // Check which column exists in the table
            $stmt = $conn->prepare("SHOW COLUMNS FROM task_comments");
            $stmt->execute();
            $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            // Determine which column to use for comment text
            $commentColumn = 'comment_text'; // Default to new column name
            if (!in_array('comment_text', $columns) && in_array('comment', $columns)) {
                $commentColumn = 'comment';
            }
            
            // Use the appropriate column in the query
            $stmt = $conn->prepare("
                SELECT tc.*, tc.$commentColumn as comment_text, u.first_name, u.last_name, u.profile_image
                FROM task_comments tc
                JOIN users u ON tc.user_id = u.user_id
                WHERE tc.task_id = ?
                ORDER BY tc.created_at DESC
            ");
            $stmt->execute([$task_id]);
            $comments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        // Silently fail and just return an empty comments array
        $comments = [];
    }
    
    // Add comments to task data
    $task['comments'] = $comments;
    
    // Determine if user has edit permissions for this task
    $isTaskCreator = ($task['created_by'] == $user_id);
    $isTaskAssignee = ($task['assigned_to'] == $user_id);
    
    // Check if user is in the assignees list (if task_assignees table exists)
    $isInAssigneesList = false;
    if (!empty($assignees)) {
        foreach ($assignees as $assignee) {
            if ($assignee['user_id'] == $user_id) {
                $isInAssigneesList = true;
                break;
            }
        }
    }
    
    // User can edit if they created the task, are assigned to it, or are a project admin
    $canEditTask = ($isTaskCreator || $isTaskAssignee || $isInAssigneesList || $isProjectAdmin);
    $canUploadAttachments = $canEditTask;
    
    // Add permissions information to task data
    $task['permissions'] = [
        'can_edit' => $canEditTask,
        'can_upload_attachments' => $canUploadAttachments,
        'can_comment' => true, // Everyone in the project can comment
        'is_task_creator' => $isTaskCreator,
        'is_task_assignee' => $isTaskAssignee || $isInAssigneesList,
        'is_project_admin' => $isProjectAdmin
    ];
    
    // Return task data as JSON
    echo json_encode([
        'success' => true,
        'task' => $task
    ]);
    
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching task details: ' . $e->getMessage()
    ]);
}
?> 