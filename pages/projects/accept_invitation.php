<?php
require_once '../../includes/functions.php';
require_once '../../config/database.php';

// Check if token is provided
if (!isset($_GET['token']) || empty($_GET['token'])) {
    setMessage("Invalid invitation link.", "danger");
    redirect('../auth/login.php');
}

$token = $_GET['token'];

try {
    // Get invitation details
    $stmt = $conn->prepare("
        SELECT pi.*, p.name AS project_name, u.first_name AS inviter_first_name, u.last_name AS inviter_last_name
        FROM project_invitations pi
        JOIN projects p ON pi.project_id = p.project_id
        JOIN users u ON pi.inviter_id = u.user_id
        WHERE pi.token = ? AND pi.status = 'pending' AND pi.expires_at > NOW()
    ");
    $stmt->execute([$token]);
    $invitation = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$invitation) {
        setMessage("Invalid or expired invitation link.", "danger");
        redirect('../auth/login.php');
    }
    
    // Check if user is logged in
    if (!isLoggedIn()) {
        // Store invitation token in session to process after login
        $_SESSION['pending_invitation_token'] = $token;
        setMessage("Please log in to accept the invitation.", "info");
        redirect('../auth/login.php');
    }
    
    // Get current user
    $user_id = $_SESSION['user_id'];
    $user_email = $_SESSION['user_email'];
    
    // Check if the invitation is for this user
    if (strtolower($invitation['invitee_email']) !== strtolower($user_email)) {
        setMessage("This invitation is for another email address.", "danger");
        redirect('../core/dashboard.php');
    }
    
    // Begin transaction
    $conn->beginTransaction();
    
    // Update invitation status
    $stmt = $conn->prepare("
        UPDATE project_invitations
        SET status = 'accepted'
        WHERE token = ?
    ");
    $stmt->execute([$token]);
    
    // Check if user is already a member
    $stmt = $conn->prepare("
        SELECT * FROM project_members
        WHERE project_id = ? AND user_id = ?
    ");
    $stmt->execute([$invitation['project_id'], $user_id]);
    $membership = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($membership) {
        // Update existing membership to active
        $stmt = $conn->prepare("
            UPDATE project_members
            SET status = 'active'
            WHERE project_id = ? AND user_id = ?
        ");
        $stmt->execute([$invitation['project_id'], $user_id]);
    } else {
        // Create new membership
        $stmt = $conn->prepare("
            INSERT INTO project_members (project_id, user_id, role, status, joined_at)
            VALUES (?, ?, 'member', 'active', NOW())
        ");
        $stmt->execute([$invitation['project_id'], $user_id]);
    }
    
    // Get user details for notification
    $stmt = $conn->prepare("
        SELECT first_name, last_name FROM users WHERE user_id = ?
    ");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    $user_name = $user['first_name'] . ' ' . $user['last_name'];
    
    // Create notification for project owner
    $notification_message = $user_name . " has accepted your invitation to join " . $invitation['project_name'] . ".";
    $stmt = $conn->prepare("
        INSERT INTO notifications (user_id, type, message, related_id, related_user_id, created_at)
        VALUES (?, 'invitation_accepted', ?, ?, ?, NOW())
    ");
    $stmt->execute([$invitation['inviter_id'], $notification_message, $invitation['project_id'], $user_id]);
    
    // Commit transaction
    $conn->commit();
    
    setMessage("You have successfully joined the project.", "success");
    redirect('../projects/view_project.php?id=' . $invitation['project_id']);
    
} catch (PDOException $e) {
    // Roll back transaction on error
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    
    setMessage("Error processing invitation: " . $e->getMessage(), "danger");
    redirect('../core/dashboard.php');
}
?> 