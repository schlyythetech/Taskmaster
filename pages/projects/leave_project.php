<?php
require_once '../../includes/functions.php';
require_once '../../config/database.php';

// Check if user is logged in
if (!isLoggedIn()) {
    setMessage("You must be logged in to leave a project.", "danger");
    redirect('../auth/login.php');
}

// Check if form was submitted
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    setMessage("Invalid request method.", "danger");
    redirect('../projects/projects.php');
}

// Validate CSRF token
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    setMessage("Invalid security token. Please try again.", "danger");
    redirect('../projects/projects.php');
}

// Check if project ID is provided
if (!isset($_POST['project_id']) || !is_numeric($_POST['project_id'])) {
    setMessage("Invalid project ID.", "danger");
    redirect('../projects/projects.php');
}

$project_id = $_POST['project_id'];
$user_id = $_SESSION['user_id'];

try {
    // Check if user is a member of this project
    $stmt = $conn->prepare("
        SELECT pm.role, pm.status, p.name, p.owner_id, u.first_name, u.last_name 
        FROM project_members pm
        JOIN projects p ON pm.project_id = p.project_id
        JOIN users u ON p.owner_id = u.user_id
        WHERE pm.project_id = ? AND pm.user_id = ?
    ");
    $stmt->execute([$project_id, $user_id]);
    $membership = $stmt->fetch();
    
    if (!$membership) {
        setMessage("You are not a member of this project.", "danger");
        redirect('../projects/projects.php');
    }
    
    // If user is the owner, they can't leave the project
    if ($membership['role'] === 'owner') {
        setMessage("As the owner, you cannot leave the project. Transfer ownership first or delete the project.", "danger");
        redirect('view_project.php?id=' . $project_id);
    }
    
    // Get user name for notification
    $stmt = $conn->prepare("SELECT first_name, last_name FROM users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
    $user_name = $user['first_name'] . ' ' . $user['last_name'];
    
    // Begin transaction
    $conn->beginTransaction();
    
    // Check if related_user_id column exists in notifications table
    $stmt = $conn->prepare("
        SELECT COUNT(*) as column_exists 
        FROM information_schema.COLUMNS 
        WHERE TABLE_SCHEMA = DATABASE() 
        AND TABLE_NAME = 'notifications' 
        AND COLUMN_NAME = 'related_user_id'
    ");
    $stmt->execute();
    $relatedUserIdColumnExists = (bool)$stmt->fetch()['column_exists'];
    
    // If related_user_id column doesn't exist, add it
    if (!$relatedUserIdColumnExists) {
        $conn->exec("
            ALTER TABLE notifications
            ADD COLUMN related_user_id INT DEFAULT NULL AFTER related_id,
            ADD CONSTRAINT fk_notifications_related_user_id
            FOREIGN KEY (related_user_id) REFERENCES users(user_id) ON DELETE SET NULL
        ");
    }
    
    // Create a notification for the project owner
    $notification_message = $user_name . " has requested to leave the project " . $membership['name'] . ".";
    
    if ($relatedUserIdColumnExists) {
        $stmt = $conn->prepare("
            INSERT INTO notifications (user_id, type, message, related_id, related_user_id, created_at) 
            VALUES (?, 'leave_project', ?, ?, ?, NOW())
        ");
        $stmt->execute([$membership['owner_id'], $notification_message, $project_id, $user_id]);
    } else {
        $stmt = $conn->prepare("
            INSERT INTO notifications (user_id, type, message, related_id, created_at) 
            VALUES (?, 'leave_project', ?, ?, NOW())
        ");
        $stmt->execute([$membership['owner_id'], $notification_message, $project_id]);
    }
    
    // Commit transaction
    $conn->commit();
    
    setMessage("Your request to leave the project has been sent to the owner.", "success");
    redirect('../projects/projects.php');
} catch (PDOException $e) {
    // Roll back transaction on error
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    setMessage("Error leaving project: " . $e->getMessage(), "danger");
    redirect('view_project.php?id=' . $project_id);
}
?> 