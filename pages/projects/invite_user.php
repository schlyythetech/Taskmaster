<?php
require_once '../../includes/functions.php';
require_once '../../config/database.php';

// Set content type to JSON
header('Content-Type: application/json');

// Check if user is logged in
if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'You must be logged in to invite users.']);
    exit;
}

// Check if request method is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

// Validate form data
$project_id = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
$email = isset($_POST['email']) ? trim($_POST['email']) : '';
$role = isset($_POST['role']) ? trim($_POST['role']) : 'member';

// Validate email
if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Please provide a valid email address.']);
    exit;
}

// Validate role
if (!in_array($role, ['member', 'admin'])) {
    $role = 'member'; // Default to member if invalid role
}

// Check if project exists and user has permission
try {
    // Check if user is a member with appropriate permissions
    $stmt = $conn->prepare("
        SELECT p.*, pm.role as user_role
        FROM projects p
        JOIN project_members pm ON p.project_id = pm.project_id
        WHERE p.project_id = ? AND pm.user_id = ? AND pm.status = 'active'
    ");
    $stmt->execute([$project_id, $_SESSION['user_id']]);
    $project = $stmt->fetch();
    
    if (!$project) {
        echo json_encode(['success' => false, 'message' => 'Project not found or you do not have access.']);
        exit;
    }
    
    // Check if user has permission to invite
    if ($project['user_role'] !== 'owner' && $project['user_role'] !== 'admin') {
        echo json_encode(['success' => false, 'message' => 'You do not have permission to invite users to this project.']);
        exit;
    }
    
    // Only owners can invite admins
    if ($role === 'admin' && $project['user_role'] !== 'owner') {
        echo json_encode(['success' => false, 'message' => 'Only project owners can invite administrators.']);
        exit;
    }
    
    // Check if the invited email exists as a user
    $stmt = $conn->prepare("
        SELECT user_id, first_name, last_name 
        FROM users 
        WHERE email = ?
    ");
    $stmt->execute([$email]);
    $invited_user = $stmt->fetch();
    
    if (!$invited_user) {
        echo json_encode(['success' => false, 'message' => 'User with this email not found. They must register first.']);
        exit;
    }
    
    // Check if user is already a member
    $stmt = $conn->prepare("
        SELECT role, status FROM project_members
        WHERE project_id = ? AND user_id = ?
    ");
    $stmt->execute([$project_id, $invited_user['user_id']]);
    $membership = $stmt->fetch();
    
    if ($membership) {
        if ($membership['status'] === 'active') {
            echo json_encode(['success' => false, 'message' => 'User is already a member of this project.']);
            exit;
        } else if ($membership['status'] === 'pending') {
            echo json_encode(['success' => false, 'message' => 'This user already has a pending invitation.']);
            exit;
        } else if ($membership['status'] === 'rejected') {
            // Remove rejected status to allow re-invitation
            $stmt = $conn->prepare("DELETE FROM project_members WHERE project_id = ? AND user_id = ?");
            $stmt->execute([$project_id, $invited_user['user_id']]);
        }
    }
    
    // Check if there's already an invitation in the project_invitations table
    $stmt = $conn->prepare("
        SELECT invitation_id, status 
        FROM project_invitations 
        WHERE project_id = ? AND invitee_email = ? AND status = 'pending'
    ");
    $stmt->execute([$project_id, $email]);
    $existing_invitation = $stmt->fetch();
    
    if ($existing_invitation) {
        echo json_encode(['success' => false, 'message' => 'An invitation has already been sent to this email address.']);
        exit;
    }
    
    // Begin transaction
    $conn->beginTransaction();
    
    // Generate a unique token for this invitation
    $token = bin2hex(random_bytes(32));
    
    // Set expiration date (30 days from now)
    $expires_at = date('Y-m-d H:i:s', strtotime('+30 days'));
    
    // Create invitation record
    $stmt = $conn->prepare("
        INSERT INTO project_invitations (project_id, inviter_id, invitee_email, token, status, created_at, expires_at)
        VALUES (?, ?, ?, ?, 'pending', NOW(), ?)
    ");
    $stmt->execute([$project_id, $_SESSION['user_id'], $email, $token, $expires_at]);
    
    // Get inviter's name
    $stmt_user = $conn->prepare("SELECT first_name, last_name FROM users WHERE user_id = ?");
    $stmt_user->execute([$_SESSION['user_id']]);
    $inviter = $stmt_user->fetch();
    $inviter_name = $inviter['first_name'] . ' ' . $inviter['last_name'];
    
    // Send invitation email
    $invitee_name = $invited_user['first_name'] . ' ' . $invited_user['last_name'];
    sendProjectInvitationEmail($email, $invitee_name, $inviter_name, $project['name'], $token);
    
    // Add user to project with pending status (for backward compatibility)
    $stmt = $conn->prepare("
        INSERT INTO project_members (project_id, user_id, role, status, joined_at)
        VALUES (?, ?, ?, 'pending', NOW())
    ");
    $stmt->execute([$project_id, $invited_user['user_id'], $role]);
    
    // Create notification for invited user
    $stmt = $conn->prepare("
        INSERT INTO notifications (user_id, type, message, related_id, related_user_id, created_at)
        VALUES (?, 'project_invite', ?, ?, ?, NOW())
    ");
    
    $notification_message = $inviter_name . " has invited you to join " . $project['name'] . ".";
    $stmt->execute([$invited_user['user_id'], $notification_message, $project_id, $_SESSION['user_id']]);
    
    // Commit transaction
    $conn->commit();
    
    echo json_encode([
        'success' => true, 
        'message' => 'Invitation sent successfully to ' . $invited_user['first_name'] . ' ' . $invited_user['last_name']
    ]);
    
} catch (PDOException $e) {
    // Roll back transaction on error
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    
    echo json_encode(['success' => false, 'message' => 'Error sending invitation: ' . $e->getMessage()]);
} 