<?php
require_once '../../includes/functions.php';
require_once '../../config/database.php';
require_once '../../includes/functions/mail.php';

// Set content type to JSON
header('Content-Type: application/json');

// Check if user is logged in
if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'You must be logged in to create a task.']);
    exit;
}

// Validate CSRF token
$csrf_token = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
if (empty($csrf_token) || $csrf_token !== $_SESSION['csrf_token']) {
    echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
    exit;
}

// Check required fields
if (empty($_POST['project_id']) || empty($_POST['title']) || empty($_POST['created_by'])) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields.']);
    exit;
}

// Process form data
$project_id = $_POST['project_id'];
$title = trim($_POST['title']);
$description = isset($_POST['description']) ? trim($_POST['description']) : '';
$created_by = $_POST['created_by'];
// Multiple assignees handling
$assignees = isset($_POST['assigned_to']) && is_array($_POST['assigned_to']) ? $_POST['assigned_to'] : [];
// Filter out empty values and the task creator
$assignees = array_filter($assignees, function($value) use ($created_by) {
    return !empty($value) && $value != $created_by;
});
// Keep assigned_to as null for the main tasks table (will be deprecated later)
$assigned_to = !empty($assignees) ? $assignees[0] : null;
$epic_id = isset($_POST['epic_id']) && !empty($_POST['epic_id']) ? $_POST['epic_id'] : null;
$status = isset($_POST['status']) ? $_POST['status'] : 'backlog';
$priority = isset($_POST['priority']) ? $_POST['priority'] : 'medium';
$due_date = isset($_POST['due_date']) && !empty($_POST['due_date']) ? $_POST['due_date'] : null;

try {
    // Check if user is a member of this project
    $stmt = $conn->prepare("
        SELECT 1 FROM project_members
        WHERE project_id = ? AND user_id = ?
    ");
    $stmt->execute([$project_id, $_SESSION['user_id']]);
    $is_member = $stmt->fetch();
    
    if (!$is_member) {
        echo json_encode(['success' => false, 'message' => 'You are not a member of this project.']);
        exit;
    }
    
    // Get project name for email notifications
    $stmt = $conn->prepare("SELECT name FROM projects WHERE project_id = ?");
    $stmt->execute([$project_id]);
    $project = $stmt->fetch();
    $project_name = $project ? $project['name'] : 'Unknown Project';
    
    // Get task creator details for email notifications
    $stmt = $conn->prepare("SELECT first_name, last_name, email FROM users WHERE user_id = ?");
    $stmt->execute([$created_by]);
    $creator = $stmt->fetch();
    
    // Begin transaction
    $conn->beginTransaction();
    
    // Insert task
    $stmt = $conn->prepare("
        INSERT INTO tasks (
            project_id, 
            title, 
            description, 
            created_by, 
            assigned_to, 
            epic_id, 
            status, 
            priority, 
            due_date, 
            created_at, 
            updated_at
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW()
        )
    ");
    $stmt->execute([
        $project_id,
        $title,
        $description,
        $created_by,
        $assigned_to,
        $epic_id,
        $status,
        $priority,
        $due_date
    ]);
    $task_id = $conn->lastInsertId();
    
    // Insert into task_assignees table for multiple assignees
    if (!empty($assignees)) {
        // Check if the task_assignees table exists
        $tableExists = tableExists($conn, 'task_assignees');
        
        // Create the table if it doesn't exist
        if (!$tableExists) {
            $conn->exec("
                CREATE TABLE task_assignees (
                    assignee_id INT AUTO_INCREMENT PRIMARY KEY,
                    task_id INT NOT NULL,
                    user_id INT NOT NULL,
                    assigned_by INT NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (task_id) REFERENCES tasks(task_id) ON DELETE CASCADE,
                    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
                    FOREIGN KEY (assigned_by) REFERENCES users(user_id) ON DELETE CASCADE,
                    UNIQUE KEY unique_task_user (task_id, user_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");
        }
        
        // Insert each assignee
        $insertStmt = $conn->prepare("
            INSERT INTO task_assignees (task_id, user_id, assigned_by)
            VALUES (?, ?, ?)
        ");
        
        $notifyStmt = $conn->prepare("
            INSERT INTO notifications (
                user_id, 
                type, 
                message, 
                related_id, 
                created_at
            ) VALUES (
                ?, 'task_assigned', ?, ?, NOW()
            )
        ");
        
        // Get task attachments if any
        $attachments = [];
        if (tableExists($conn, 'task_attachments')) {
            $attachStmt = $conn->prepare("
                SELECT * FROM task_attachments 
                WHERE task_id = ?
            ");
            $attachStmt->execute([$task_id]);
            $attachments = $attachStmt->fetchAll();
        }
        
        // Prepare task data for email
        $task_data = [
            'task_id' => $task_id,
            'title' => $title,
            'description' => $description,
            'due_date' => $due_date,
            'priority' => $priority,
            'status' => $status,
            'project_name' => $project_name
        ];
        
        // Send email to each assignee
        foreach ($assignees as $assignee_id) {
            if ($assignee_id) {
                // Add to task_assignees table
                $insertStmt->execute([$task_id, $assignee_id, $created_by]);
                
                // Create notification for assignee
                $notification_message = "You've been assigned a new task: " . $title;
                $notifyStmt->execute([$assignee_id, $notification_message, $task_id]);
                
                // Get assignee details for email
                $userStmt = $conn->prepare("SELECT first_name, last_name, email FROM users WHERE user_id = ?");
                $userStmt->execute([$assignee_id]);
                $assignee = $userStmt->fetch();
                
                // Send email notification to assignee
                if ($assignee && !empty($assignee['email'])) {
                    sendTaskAssignmentEmail(
                        $assignee['email'],
                        $assignee['first_name'],
                        $task_data,
                        $creator,
                        $attachments
                    );
                }
            }
        }
    }
    
    // Commit transaction
    $conn->commit();
    
    echo json_encode(['success' => true, 'message' => 'Task created successfully.', 'task_id' => $task_id]);
} catch (PDOException $e) {
    // Roll back transaction on error
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log("Error creating task: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} 