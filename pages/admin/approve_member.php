<?php
require_once '../../includes/functions.php';
require_once '../../config/database.php';

// Set content type to JSON
header('Content-Type: application/json');

// Check if user is logged in
if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'You must be logged in to approve members.']);
    exit;
}

// Check if request method is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

// Validate CSRF token
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    echo json_encode(['success' => false, 'message' => 'Invalid security token. Please try again.']);
    exit;
}

// Check if required parameters are provided
if (!isset($_POST['project_id']) || !isset($_POST['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Missing required parameters.']);
    exit;
}

$project_id = (int)$_POST['project_id'];
$user_id = (int)$_POST['user_id'];

try {
    // Check if the current user is the project owner or admin
    $stmt = $conn->prepare("
        SELECT pm.role, p.name, p.owner_id
        FROM project_members pm
        JOIN projects p ON pm.project_id = p.project_id
        WHERE pm.project_id = ? AND pm.user_id = ? AND pm.status = 'active'
    ");
    $stmt->execute([$project_id, $_SESSION['user_id']]);
    $current_user = $stmt->fetch();
    
    if (!$current_user) {
        echo json_encode(['success' => false, 'message' => 'You do not have access to this project.']);
        exit;
    }
    
    if ($current_user['role'] !== 'owner' && $current_user['role'] !== 'admin') {
        echo json_encode(['success' => false, 'message' => 'You do not have permission to approve members.']);
        exit;
    }
    
    // Get the member to approve
    $stmt = $conn->prepare("
        SELECT pm.*, u.first_name, u.last_name
        FROM project_members pm
        JOIN users u ON pm.user_id = u.user_id
        WHERE pm.project_id = ? AND pm.user_id = ?
    ");
    $stmt->execute([$project_id, $user_id]);
    $member = $stmt->fetch();
    
    if (!$member) {
        // If the member doesn't exist yet, they might be being approved from a reinvitation
        $stmt = $conn->prepare("SELECT * FROM users WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
        
        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'User not found.']);
            exit;
        }
        
        // Add the user as a new member
        $conn->beginTransaction();
        
        $stmt = $conn->prepare("
            INSERT INTO project_members (project_id, user_id, role, status, joined_at)
            VALUES (?, ?, 'member', 'active', NOW())
        ");
        $stmt->execute([$project_id, $user_id]);
        
        // Create notification for the user
        $stmt = $conn->prepare("
            INSERT INTO notifications (user_id, type, message, related_id, related_user_id, created_at)
            VALUES (?, 'join_approved', ?, ?, ?, NOW())
        ");
        
        $notification_message = "You have been added to the project " . $current_user['name'] . ".";
        $stmt->execute([$user_id, $notification_message, $project_id, $_SESSION['user_id']]);
        
        $conn->commit();
        
        echo json_encode(['success' => true, 'message' => 'Member added successfully.']);
        exit;
    }
    
    // If the member is already active, no need to approve
    if ($member['status'] === 'active') {
        echo json_encode(['success' => false, 'message' => 'Member is already active.']);
        exit;
    }
    
    // Begin transaction
    $conn->beginTransaction();
    
    // Update member status to active
    $stmt = $conn->prepare("
        UPDATE project_members
        SET status = 'active'
        WHERE project_id = ? AND user_id = ?
    ");
    $stmt->execute([$project_id, $user_id]);
    
    // Create notification for the approved user
    $stmt = $conn->prepare("
        INSERT INTO notifications (user_id, type, message, related_id, related_user_id, created_at)
        VALUES (?, 'join_approved', ?, ?, ?, NOW())
    ");
    
    $notification_message = "Your request to join " . $current_user['name'] . " has been approved.";
    $stmt->execute([$user_id, $notification_message, $project_id, $_SESSION['user_id']]);
    
    // Commit transaction
    $conn->commit();
    
    echo json_encode([
        'success' => true, 
        'message' => 'Member approved successfully.',
        'user_name' => $member['first_name'] . ' ' . $member['last_name']
    ]);
    
} catch (PDOException $e) {
    // Roll back transaction on error
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} 