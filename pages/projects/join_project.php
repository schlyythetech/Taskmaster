<?php
require_once '../../includes/functions.php';
require_once '../../config/database.php';

// Check if user is logged in
if (!isLoggedIn()) {
    setMessage("You must be logged in to request to join a project.", "danger");
    redirect('../auth/login.php');
}

// Check if request method is POST
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
    // First, check if the status column exists in project_members
    $stmt = $conn->prepare("
        SELECT COUNT(*) as column_exists 
        FROM information_schema.COLUMNS 
        WHERE TABLE_SCHEMA = DATABASE() 
        AND TABLE_NAME = 'project_members' 
        AND COLUMN_NAME = 'status'
    ");
    $stmt->execute();
    $statusColumnExists = (bool)$stmt->fetch()['column_exists'];
    
    // If status column doesn't exist, add it
    if (!$statusColumnExists) {
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

    // Check if the project exists
    $stmt = $conn->prepare("
        SELECT p.*, u.first_name, u.last_name, u.user_id as owner_user_id
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
    
    // Verify that the user is not the project owner
    if ($project['owner_user_id'] == $user_id) {
        setMessage("You cannot join your own project as you are already the owner.", "info");
        redirect('../projects/projects.php');
    }
    
    // Check if user is already a member of this project
    $stmt = $conn->prepare("
        SELECT role, status 
        FROM project_members 
        WHERE project_id = ? AND user_id = ?
    ");
    $stmt->execute([$project_id, $user_id]);
    $membership = $stmt->fetch();
    
    if ($membership) {
        // Check membership status
        if (isset($membership['status'])) {
            if ($membership['status'] === 'pending') {
                setMessage("Your request to join this project is already pending approval.", "info");
            } else if ($membership['status'] === 'rejected') {
                setMessage("Your previous join request was declined. You may try again later.", "warning");
                
                // Allow rejected users to request again by removing the rejected status
                $stmt = $conn->prepare("DELETE FROM project_members WHERE project_id = ? AND user_id = ?");
                $stmt->execute([$project_id, $user_id]);
            } else if ($membership['status'] === 'active') {
                setMessage("You are already a member of this project.", "info");
                redirect('view_project.php?id=' . $project_id);
            }
        } else {
            setMessage("You are already a member of this project.", "info");
        }
        redirect('../projects/projects.php');
    }
    
    // Get user details for notification
    $stmt = $conn->prepare("SELECT first_name, last_name FROM users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
    $user_name = $user['first_name'] . ' ' . $user['last_name'];
    
    // Begin transaction
    $conn->beginTransaction();
    
    // Create a pending membership
    $stmt = $conn->prepare("
        INSERT INTO project_members (project_id, user_id, role, status, joined_at) 
        VALUES (?, ?, 'member', 'pending', NOW())
    ");
    $stmt->execute([$project_id, $user_id]);
    
    // Create a notification for the project owner
    $notification_message = $user_name . " has requested to join " . $project['name'] . ".";
    
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
    
    // Insert notification with related_user_id
    $stmt = $conn->prepare("
        INSERT INTO notifications (user_id, type, message, related_id, related_user_id, created_at) 
        VALUES (?, 'join_request', ?, ?, ?, NOW())
    ");
    $stmt->execute([$project['owner_user_id'], $notification_message, $project_id, $user_id]);
    
    // Commit transaction
    $conn->commit();
    
    setMessage("Your request to join the project has been sent to " . $project['first_name'] . " " . $project['last_name'] . ".", "success");
    redirect('../projects/projects.php');
} catch (PDOException $e) {
    // Roll back transaction on error
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    
    setMessage("Error requesting to join project: " . $e->getMessage(), "danger");
    redirect('../projects/projects.php');
} 