<?php
require_once '../../includes/functions.php';
require_once '../../config/database.php';
require_once '../../includes/functions/notification_functions.php';

// Set content type to JSON for API responses
header('Content-Type: application/json');

// Check if user is logged in
if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'You must be logged in to handle project invitations.']);
    exit;
}

// Check request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

// Get current user
$user_id = $_SESSION['user_id'];

// Get notification ID from request
$notification_id = isset($_POST['notification_id']) ? $_POST['notification_id'] : 0;
$action = isset($_POST['action']) ? $_POST['action'] : '';

// Validate inputs
if (!$notification_id || !in_array($action, ['accept', 'reject'])) {
    echo json_encode(['success' => false, 'message' => 'Missing or invalid parameters.']);
    exit;
}

try {
    // Begin transaction
    $conn->beginTransaction();
    
    // Get notification details
    $stmt = $conn->prepare("
        SELECT n.*, 
               p.name AS project_name, 
               u.first_name AS inviter_first_name, 
               u.last_name AS inviter_last_name
        FROM notifications n
        LEFT JOIN projects p ON n.related_id = p.project_id
        LEFT JOIN users u ON n.related_user_id = u.user_id
        WHERE n.notification_id = ? AND n.user_id = ?
    ");
    $stmt->execute([$notification_id, $user_id]);
    $notification = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$notification) {
        echo json_encode(['success' => false, 'message' => 'Notification not found.']);
        $conn->rollBack();
        exit;
    }
    
    // Make sure this is a project invitation
    if ($notification['type'] !== 'project_invite') {
        echo json_encode(['success' => false, 'message' => 'This is not a project invitation notification.']);
        $conn->rollBack();
        exit;
    }
    
    $project_id = $notification['related_id'];
    
    // Get project details
    $stmt = $conn->prepare("
        SELECT * FROM projects WHERE project_id = ?
    ");
    $stmt->execute([$project_id]);
    $project = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$project) {
        echo json_encode(['success' => false, 'message' => 'Project not found.']);
        $conn->rollBack();
        exit;
    }
    
    // Check if user is already a member
    $stmt = $conn->prepare("
        SELECT * FROM project_members
        WHERE project_id = ? AND user_id = ?
    ");
    $stmt->execute([$project_id, $user_id]);
    $membership = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get user details for notification
    $stmt = $conn->prepare("
        SELECT first_name, last_name FROM users WHERE user_id = ?
    ");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    $user_name = $user['first_name'] . ' ' . $user['last_name'];
    
    // Process based on action
    if ($action === 'accept') {
        if ($membership) {
            // Update existing membership to active
            $stmt = $conn->prepare("
                UPDATE project_members
                SET status = 'active'
                WHERE project_id = ? AND user_id = ?
            ");
            $stmt->execute([$project_id, $user_id]);
        } else {
            // Create new membership
            $stmt = $conn->prepare("
                INSERT INTO project_members (project_id, user_id, role, status, joined_at)
                VALUES (?, ?, 'member', 'active', NOW())
            ");
            $stmt->execute([$project_id, $user_id]);
        }
        
        // Create notification for project owner
        $notification_message = $user_name . " has accepted your invitation to join " . $project['name'] . ".";
        $stmt = $conn->prepare("
            INSERT INTO notifications (user_id, type, message, related_id, related_user_id, created_at)
            VALUES (?, 'invitation_accepted', ?, ?, ?, NOW())
        ");
        $stmt->execute([$project['owner_id'], $notification_message, $project_id, $user_id]);
        
        // Return response with redirect URL
        $redirect_url = '../projects/view_project.php?id=' . $project_id;
        
        // Mark notification as read
        $stmt = $conn->prepare("
            DELETE FROM notifications 
            WHERE notification_id = ?
        ");
        $stmt->execute([$notification_id]);
        
        $conn->commit();
        
        echo json_encode([
            'success' => true, 
            'message' => 'You have successfully joined the project.',
            'redirect_url' => $redirect_url
        ]);
    } 
    else if ($action === 'reject') {
        // If there's a pending membership, update to rejected
        if ($membership) {
            $stmt = $conn->prepare("
                UPDATE project_members
                SET status = 'rejected'
                WHERE project_id = ? AND user_id = ?
            ");
            $stmt->execute([$project_id, $user_id]);
        }
        
        // Create notification for project owner
        $notification_message = $user_name . " has declined your invitation to join " . $project['name'] . ".";
        $stmt = $conn->prepare("
            INSERT INTO notifications (user_id, type, message, related_id, related_user_id, created_at)
            VALUES (?, 'invitation_rejected', ?, ?, ?, NOW())
        ");
        $stmt->execute([$project['owner_id'], $notification_message, $project_id, $user_id]);
        
        // Mark notification as read
        $stmt = $conn->prepare("
            DELETE FROM notifications 
            WHERE notification_id = ?
        ");
        $stmt->execute([$notification_id]);
        
        $conn->commit();
        
        echo json_encode([
            'success' => true, 
            'message' => 'You have declined the project invitation.'
        ]);
    }
} catch (PDOException $e) {
    // Roll back transaction on error
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    
    echo json_encode(['success' => false, 'message' => 'Error processing invitation: ' . $e->getMessage()]);
}
?> 