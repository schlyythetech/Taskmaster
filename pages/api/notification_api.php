<?php
require_once '../../includes/functions.php';
require_once '../../config/database.php';

// Set content type to JSON
header('Content-Type: application/json');

// Check if user is logged in
if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'You must be logged in to access notifications.']);
    exit;
}

$user_id = $_SESSION['user_id'];
$action = isset($_GET['action']) ? $_GET['action'] : '';

// Get notifications
if ($action === 'get') {
    try {
        // First, find and fix join request notifications with empty type
        $stmt = $conn->prepare("
            UPDATE notifications 
            SET type = 'join_request' 
            WHERE (type = '' OR type IS NULL) 
            AND (message LIKE '%requested to join%' OR message LIKE '%request to join%')
        ");
        $stmt->execute();
        $fixedCount = $stmt->rowCount();
        
        if ($fixedCount > 0) {
            error_log("Fixed $fixedCount join request notifications with empty type");
        }
        
        // Also fix leave request notifications with empty or incorrect type
        $stmt = $conn->prepare("
            UPDATE notifications 
            SET type = 'leave_project' 
            WHERE (type = '' OR type IS NULL OR type = 'leave_request') 
            AND (message LIKE '%requested to leave%' OR message LIKE '%leave the project%')
        ");
        $stmt->execute();
        $fixedLeaveCount = $stmt->rowCount();
        
        if ($fixedLeaveCount > 0) {
            error_log("Fixed $fixedLeaveCount leave request notifications with empty or incorrect type");
        }
        
        $stmt = $conn->prepare("
            SELECT n.*, 
                   u.first_name, u.last_name, u.profile_image,
                   p.name as project_name,
                   t.title as task_title
            FROM notifications n
            LEFT JOIN users u ON n.related_user_id = u.user_id
            LEFT JOIN projects p ON (n.type IN ('project_invite', 'leave_project', 'join_request') AND n.related_id = p.project_id)
            LEFT JOIN tasks t ON (n.type IN ('task_assigned', 'task_completed') AND n.related_id = t.task_id)
            WHERE n.user_id = ?
            ORDER BY n.created_at DESC
            LIMIT 10
        ");
        $stmt->execute([$user_id]);
        $notifications = $stmt->fetchAll();
        
        // Process notifications for better display
        foreach ($notifications as &$notification) {
            // Format the profile image path if it exists
            if (!empty($notification['profile_image'])) {
                // Add the path prefix if it doesn't already have one
                if (!str_starts_with($notification['profile_image'], 'http') && 
                    !str_starts_with($notification['profile_image'], '/') &&
                    !str_starts_with($notification['profile_image'], '../../')) {
                    
                    // Check if the file exists
                    $img_path = '../../' . $notification['profile_image'];
                    if (file_exists($img_path)) {
                        $notification['profile_image'] = $img_path;
                    } else {
                        // If file doesn't exist, use default avatar
                        $notification['profile_image'] = '../../assets/images/default-avatar.svg';
                    }
                }
            } else {
                // Set default avatar for users without profile images
                $notification['profile_image'] = '../../assets/images/default-avatar.svg';
            }
            
            // Add additional context based on notification type
            switch ($notification['type']) {
                case 'task_assigned':
                case 'task_completed':
                    $notification['context'] = [
                        'task_title' => $notification['task_title'] ?? 'Unknown Task'
                    ];
                    break;
                case 'project_invite':
                case 'join_request':
                case 'leave_project':
                    $notification['context'] = [
                        'project_name' => $notification['project_name'] ?? 'Unknown Project'
                    ];
                    break;
            }
        }
        
        echo json_encode(['success' => true, 'notifications' => $notifications]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Error fetching notifications: ' . $e->getMessage()]);
    }
}
// Get notification count for the badge
else if ($action === 'count') {
    try {
        $stmt = $conn->prepare("
            SELECT COUNT(*) as count 
            FROM notifications 
            WHERE user_id = ? AND is_read = 0
        ");
        $stmt->execute([$user_id]);
        $result = $stmt->fetch();
        
        echo json_encode(['success' => true, 'count' => (int)$result['count']]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Error counting notifications: ' . $e->getMessage()]);
    }
}
// Mark notification as read
else if ($action === 'mark_read') {
    $notification_id = isset($_POST['notification_id']) ? $_POST['notification_id'] : 0;
    
    if (!$notification_id) {
        echo json_encode(['success' => false, 'message' => 'Missing notification ID.']);
        exit;
    }
    
    try {
        $stmt = $conn->prepare("
            UPDATE notifications 
            SET is_read = TRUE 
            WHERE notification_id = ? AND user_id = ?
        ");
        $stmt->execute([$notification_id, $user_id]);
        
        echo json_encode(['success' => true, 'message' => 'Notification marked as read.']);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Error marking notification as read: ' . $e->getMessage()]);
    }
}
// Mark all notifications as read
else if ($action === 'mark_all_read') {
    try {
        $stmt = $conn->prepare("
            UPDATE notifications 
            SET is_read = TRUE 
            WHERE user_id = ? AND is_read = FALSE
        ");
        $stmt->execute([$user_id]);
        
        echo json_encode(['success' => true, 'message' => 'All notifications marked as read.']);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Error marking all notifications as read: ' . $e->getMessage()]);
    }
}
// Delete notification
else if ($action === 'delete') {
    $notification_id = isset($_POST['notification_id']) ? $_POST['notification_id'] : 0;
    
    if (!$notification_id) {
        echo json_encode(['success' => false, 'message' => 'Missing notification ID.']);
        exit;
    }
    
    try {
        // No transaction needed for simple delete
        $stmt = $conn->prepare("
            DELETE FROM notifications 
            WHERE notification_id = ? AND user_id = ?
        ");
        $stmt->execute([$notification_id, $user_id]);
        
        echo json_encode(['success' => true, 'message' => 'Notification deleted.']);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Error deleting notification: ' . $e->getMessage()]);
    }
}
// Accept a notification action
else if ($action === 'accept') {
    $notification_id = isset($_POST['notification_id']) ? $_POST['notification_id'] : 0;
    
    if (!$notification_id) {
        echo json_encode(['success' => false, 'message' => 'Missing notification ID.']);
        exit;
    }
    
    try {
        // Begin transaction
        $conn->beginTransaction();
        
        // Get notification details
        $stmt = $conn->prepare("SELECT * FROM notifications WHERE notification_id = ? AND user_id = ?");
        $stmt->execute([$notification_id, $user_id]);
        $notification = $stmt->fetch();
        
        if (!$notification) {
            echo json_encode(['success' => false, 'message' => 'Notification not found.']);
            $conn->rollBack();
            exit;
        }
        
        // Fix empty notification type for join requests
        if (empty($notification['type']) && 
            (strpos($notification['message'], 'requested to join') !== false || 
             strpos($notification['message'], 'request to join') !== false)) {
            $notification['type'] = 'join_request';
            
            // Update the notification type in the database
            $updateStmt = $conn->prepare("
                UPDATE notifications 
                SET type = 'join_request' 
                WHERE notification_id = ?
            ");
            $updateStmt->execute([$notification_id]);
            error_log("Fixed join request notification type for ID: $notification_id");
        }
        
        // Initialize response data
        $response_data = [];
        $redirect_url = null;
        
        // Process based on notification type
        if ($notification['type'] === 'connection_request') {
            // Accept connection request
            $requester_id = $notification['related_user_id'];
            
            // Verify both users exist before proceeding
            $stmt = $conn->prepare("
                SELECT COUNT(*) as count FROM users 
                WHERE user_id IN (?, ?)
            ");
            $stmt->execute([$requester_id, $user_id]);
            $result = $stmt->fetch();
            
            if ($result['count'] < 2) {
                echo json_encode(['success' => false, 'message' => 'Cannot accept connection: one or both users no longer exist.']);
                $conn->rollBack();
                exit;
            }
            
            // Check if the connection still exists
            $stmt = $conn->prepare("
                SELECT connection_id FROM connections
                WHERE user_id = ? AND connected_user_id = ? AND status = 'pending'
            ");
            $stmt->execute([$requester_id, $user_id]);
            $connection = $stmt->fetch();
            
            if (!$connection) {
                echo json_encode(['success' => false, 'message' => 'Connection request no longer exists or has already been processed.']);
                $conn->rollBack();
                exit;
            }
            
            // Update connection status
            $stmt = $conn->prepare("
                UPDATE connections
                SET status = 'accepted', updated_at = CURRENT_TIMESTAMP
                WHERE connection_id = ?
            ");
            $stmt->execute([$connection['connection_id']]);
            
            // Create notification for the requester
            $stmt_name = $conn->prepare("SELECT first_name, last_name FROM users WHERE user_id = ?");
            $stmt_name->execute([$user_id]);
            $user = $stmt_name->fetch();
            
            if (!$user) {
                echo json_encode(['success' => false, 'message' => 'Your user account could not be found.']);
                $conn->rollBack();
                exit;
            }
            
            $user_name = $user['first_name'] . ' ' . $user['last_name'];
            $message = $user_name . ' accepted your connection request.';
            
            $stmt = $conn->prepare("
                INSERT INTO notifications (user_id, type, message, related_id, related_user_id, created_at)
                VALUES (?, 'connection_accepted', ?, ?, ?, CURRENT_TIMESTAMP)
            ");
            $stmt->execute([$requester_id, $message, $connection['connection_id'], $user_id]);
            
            // Set redirect URL
            $redirect_url = '../users/connections.php';
        }
        else if ($notification['type'] === 'join_request') {
            // Accept project join request
            $project_id = $notification['related_id'];
            $requester_id = $notification['related_user_id'];
            
            if (!$project_id || !$requester_id) {
                echo json_encode(['success' => false, 'message' => 'Invalid join request data.']);
                $conn->rollBack();
                exit;
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
                echo json_encode(['success' => false, 'message' => 'Project not found.']);
                $conn->rollBack();
                exit;
            }
            
            // Check if user has pending membership
            $stmt = $conn->prepare("
                SELECT * FROM project_members
                WHERE project_id = ? AND user_id = ? AND status = 'pending'
            ");
            $stmt->execute([$project_id, $requester_id]);
            $membership = $stmt->fetch();
            
            if ($membership) {
                // Update membership status to active
                $stmt = $conn->prepare("
                    UPDATE project_members
                    SET status = 'active'
                    WHERE project_id = ? AND user_id = ?
                ");
                $stmt->execute([$project_id, $requester_id]);
                
                // Get requester details for notification
                $stmt = $conn->prepare("SELECT first_name, last_name FROM users WHERE user_id = ?");
                $stmt->execute([$requester_id]);
                $requester = $stmt->fetch();
                
                if ($requester) {
                    // Get current user (who approved the request)
                    $stmt_name = $conn->prepare("SELECT first_name, last_name FROM users WHERE user_id = ?");
                    $stmt_name->execute([$user_id]);
                    $approver = $stmt_name->fetch();
                    
                    if ($approver) {
                        $approver_name = $approver['first_name'] . ' ' . $approver['last_name'];
                        $message = $approver_name . ' has approved your request to join ' . $project['name'] . '.';
                        
                        // Send notification to requester
                        $stmt = $conn->prepare("
                            INSERT INTO notifications (user_id, type, message, related_id, related_user_id, created_at)
                            VALUES (?, 'join_approved', ?, ?, ?, CURRENT_TIMESTAMP)
                        ");
                        $stmt->execute([$requester_id, $message, $project_id, $user_id]);
                    }
                }
                
                // Set redirect URL to project page
                $redirect_url = '../projects/view_project.php?id=' . $project_id;
            } else {
                echo json_encode(['success' => false, 'message' => 'Join request no longer exists or has already been processed.']);
                $conn->rollBack();
                exit;
            }
        }
        else if ($notification['type'] === 'project_invite') {
            // Accept project invitation
            $project_id = $notification['related_id'];
            
            // Get project details to redirect the user
            $stmt = $conn->prepare("
                SELECT p.*, u.first_name, u.last_name 
                FROM projects p
                JOIN users u ON p.owner_id = u.user_id
                WHERE p.project_id = ?
            ");
            $stmt->execute([$project_id]);
            $project = $stmt->fetch();
            
            if (!$project) {
                echo json_encode(['success' => false, 'message' => 'Project not found.']);
                $conn->rollBack();
                exit;
            }
            
            // Get user details for notification
            $stmt = $conn->prepare("
                SELECT first_name, last_name FROM users WHERE user_id = ?
            ");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch();
            $user_name = $user['first_name'] . ' ' . $user['last_name'];
            
            // Check if already a member
            $stmt = $conn->prepare("
                SELECT 1 FROM project_members
                WHERE project_id = ? AND user_id = ?
            ");
            $stmt->execute([$project_id, $user_id]);
            
            if (!$stmt->fetch()) {
                // Add user to project
                $stmt = $conn->prepare("
                    INSERT INTO project_members (project_id, user_id, role, status, joined_at)
                    VALUES (?, ?, 'member', 'active', NOW())
                ");
                $stmt->execute([$project_id, $user_id]);
                
                // Notify project owner that user accepted the invitation
                $notification_message = $user_name . " has accepted your invitation to join " . $project['name'] . ".";
                $stmt = $conn->prepare("
                    INSERT INTO notifications (user_id, type, message, related_id, related_user_id, created_at)
                    VALUES (?, 'invitation_accepted', ?, ?, ?, NOW())
                ");
                $stmt->execute([$project['owner_id'], $notification_message, $project_id, $user_id]);
            }
            
            // Set redirect URL
            $redirect_url = '../projects/view_project.php?id=' . $project_id;
            
            // Return project data
            $response_data = [
                'project_id' => $project_id,
                'project_name' => $project['name']
            ];
        }
        else if ($notification['type'] === 'leave_project') {
            // Accept user's request to leave project
            $project_id = $notification['related_id'];
            $leaving_user_id = $notification['related_user_id'];
            
            if (!$project_id || !$leaving_user_id) {
                echo json_encode(['success' => false, 'message' => 'Invalid leave request data.']);
                $conn->rollBack();
                exit;
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
                echo json_encode(['success' => false, 'message' => 'Project not found.']);
                $conn->rollBack();
                exit;
            }
            
            // Verify this user is the project owner
            if ($project['owner_id'] != $user_id) {
                echo json_encode(['success' => false, 'message' => 'Only the project owner can approve leave requests.']);
                $conn->rollBack();
                exit;
            }
            
            // Get leaving user details
            $stmt = $conn->prepare("
                SELECT first_name, last_name FROM users WHERE user_id = ?
            ");
            $stmt->execute([$leaving_user_id]);
            $leaving_user = $stmt->fetch();
            
            if (!$leaving_user) {
                echo json_encode(['success' => false, 'message' => 'User not found.']);
                $conn->rollBack();
                exit;
            }
            
            $leaving_user_name = $leaving_user['first_name'] . ' ' . $leaving_user['last_name'];
            
            // Check if user is still a member of the project
            $stmt = $conn->prepare("
                SELECT * FROM project_members
                WHERE project_id = ? AND user_id = ?
            ");
            $stmt->execute([$project_id, $leaving_user_id]);
            $membership = $stmt->fetch();
            
            if ($membership) {
                // Remove user from project
                $stmt = $conn->prepare("
                    DELETE FROM project_members 
                    WHERE project_id = ? AND user_id = ?
                ");
                $stmt->execute([$project_id, $leaving_user_id]);
                
                // Also remove from favorite_projects if exists
                $stmt = $conn->prepare("
                    DELETE FROM favorite_projects 
                    WHERE project_id = ? AND user_id = ?
                ");
                $stmt->execute([$project_id, $leaving_user_id]);
                
                // Notify the leaving user that their request was approved
                $notification_message = "Your request to leave " . $project['name'] . " has been approved.";
                $stmt = $conn->prepare("
                    INSERT INTO notifications (user_id, type, message, related_id, related_user_id, created_at)
                    VALUES (?, 'leave_approved', ?, ?, ?, NOW())
                ");
                $stmt->execute([$leaving_user_id, $notification_message, $project_id, $user_id]);
            }
            
            // Set redirect URL to project page
            $redirect_url = '../projects/view_project.php?id=' . $project_id;
        }
        
        // Mark notification as read and delete it
        $stmt = $conn->prepare("
            DELETE FROM notifications 
            WHERE notification_id = ?
        ");
        $stmt->execute([$notification_id]);
        
        // Commit transaction
        $conn->commit();
        
        // Return success response with redirect URL if applicable
        $response = [
            'success' => true, 
            'message' => 'Action processed successfully.'
        ];
        
        if ($redirect_url) {
            $response['redirect_url'] = $redirect_url;
        }
        
        if (!empty($response_data)) {
            $response['data'] = $response_data;
        }
        
        echo json_encode($response);
        
    } catch (PDOException $e) {
        // Rollback transaction on error
        $conn->rollBack();
        echo json_encode(['success' => false, 'message' => 'Error processing action: ' . $e->getMessage()]);
    }
}
// Reject a notification action
else if ($action === 'reject') {
    $notification_id = isset($_POST['notification_id']) ? $_POST['notification_id'] : 0;
    
    if (!$notification_id) {
        echo json_encode(['success' => false, 'message' => 'Missing notification ID.']);
        exit;
    }
    
    try {
        // Begin transaction
        $conn->beginTransaction();
        
        // Get notification details
        $stmt = $conn->prepare("SELECT * FROM notifications WHERE notification_id = ? AND user_id = ?");
        $stmt->execute([$notification_id, $user_id]);
        $notification = $stmt->fetch();
        
        if (!$notification) {
            echo json_encode(['success' => false, 'message' => 'Notification not found.']);
            $conn->rollBack();
            exit;
        }
        
        // Fix empty notification type for join requests
        if (empty($notification['type']) && 
            (strpos($notification['message'], 'requested to join') !== false || 
             strpos($notification['message'], 'request to join') !== false)) {
            $notification['type'] = 'join_request';
            
            // Update the notification type in the database
            $updateStmt = $conn->prepare("
                UPDATE notifications 
                SET type = 'join_request' 
                WHERE notification_id = ?
            ");
            $updateStmt->execute([$notification_id]);
            error_log("Fixed join request notification type for ID: $notification_id");
        }
        
        // Process based on notification type
        if ($notification['type'] === 'connection_request') {
            // Reject connection request
            $requester_id = $notification['related_user_id'];
            
            // Check if the connection still exists
            $stmt = $conn->prepare("
                SELECT connection_id FROM connections
                WHERE user_id = ? AND connected_user_id = ? AND status = 'pending'
            ");
            $stmt->execute([$requester_id, $user_id]);
            $connection = $stmt->fetch();
            
            if ($connection) {
                // Update connection status to rejected
                $stmt = $conn->prepare("
                    UPDATE connections
                    SET status = 'rejected', updated_at = CURRENT_TIMESTAMP
                    WHERE connection_id = ?
                ");
                $stmt->execute([$connection['connection_id']]);
                
                // Create notification for the requester
                $stmt_name = $conn->prepare("SELECT first_name, last_name FROM users WHERE user_id = ?");
                $stmt_name->execute([$user_id]);
                $user = $stmt_name->fetch();
                
                if ($user) {
                    $user_name = $user['first_name'] . ' ' . $user['last_name'];
                    $message = $user_name . ' declined your connection request.';
                    
                    $stmt = $conn->prepare("
                        INSERT INTO notifications (user_id, type, message, related_id, related_user_id, created_at)
                        VALUES (?, 'connection_rejected', ?, ?, ?, CURRENT_TIMESTAMP)
                    ");
                    $stmt->execute([$requester_id, $message, $connection['connection_id'], $user_id]);
                }
            }
        }
        else if ($notification['type'] === 'join_request') {
            // Reject project join request
            $project_id = $notification['related_id'];
            $requester_id = $notification['related_user_id'];
            
            if (!$project_id || !$requester_id) {
                echo json_encode(['success' => false, 'message' => 'Invalid join request data.']);
                $conn->rollBack();
                exit;
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
                echo json_encode(['success' => false, 'message' => 'Project not found.']);
                $conn->rollBack();
                exit;
            }
            
            // Check if user has pending membership
            $stmt = $conn->prepare("
                SELECT * FROM project_members
                WHERE project_id = ? AND user_id = ? AND status = 'pending'
            ");
            $stmt->execute([$project_id, $requester_id]);
            $membership = $stmt->fetch();
            
            if ($membership) {
                // Update membership status to rejected
                $stmt = $conn->prepare("
                    UPDATE project_members
                    SET status = 'rejected'
                    WHERE project_id = ? AND user_id = ?
                ");
                $stmt->execute([$project_id, $requester_id]);
                
                // Get requester details for notification
                $stmt = $conn->prepare("SELECT first_name, last_name FROM users WHERE user_id = ?");
                $stmt->execute([$requester_id]);
                $requester = $stmt->fetch();
                
                if ($requester) {
                    // Get current user (who rejected the request)
                    $stmt_name = $conn->prepare("SELECT first_name, last_name FROM users WHERE user_id = ?");
                    $stmt_name->execute([$user_id]);
                    $rejector = $stmt_name->fetch();
                    
                    if ($rejector) {
                        $rejector_name = $rejector['first_name'] . ' ' . $rejector['last_name'];
                        $message = $rejector_name . ' has declined your request to join ' . $project['name'] . '.';
                        
                        // Send notification to requester
                        $stmt = $conn->prepare("
                            INSERT INTO notifications (user_id, type, message, related_id, related_user_id, created_at)
                            VALUES (?, 'join_rejected', ?, ?, ?, CURRENT_TIMESTAMP)
                        ");
                        $stmt->execute([$requester_id, $message, $project_id, $user_id]);
                    }
                }
            }
        }
        else if ($notification['type'] === 'project_invite') {
            // Reject project invitation
            $project_id = $notification['related_id'];
            
            // Get project details
            $stmt = $conn->prepare("
                SELECT p.*, u.first_name, u.last_name 
                FROM projects p
                JOIN users u ON p.owner_id = u.user_id
                WHERE p.project_id = ?
            ");
            $stmt->execute([$project_id]);
            $project = $stmt->fetch();
            
            if ($project) {
                // Get user details for notification
                $stmt = $conn->prepare("
                    SELECT first_name, last_name FROM users WHERE user_id = ?
                ");
                $stmt->execute([$user_id]);
                $user = $stmt->fetch();
                
                if ($user) {
                    $user_name = $user['first_name'] . ' ' . $user['last_name'];
                    
                    // Notify project owner that user declined the invitation
                    $notification_message = $user_name . " has declined your invitation to join " . $project['name'] . ".";
                    $stmt = $conn->prepare("
                        INSERT INTO notifications (user_id, type, message, related_id, related_user_id, created_at)
                        VALUES (?, 'invitation_rejected', ?, ?, ?, NOW())
                    ");
                    $stmt->execute([$project['owner_id'], $notification_message, $project_id, $user_id]);
                }
            }
        }
        else if ($notification['type'] === 'leave_project') {
            // Reject user's request to leave project
            $project_id = $notification['related_id'];
            $leaving_user_id = $notification['related_user_id'];
            
            if (!$project_id || !$leaving_user_id) {
                echo json_encode(['success' => false, 'message' => 'Invalid leave request data.']);
                $conn->rollBack();
                exit;
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
                echo json_encode(['success' => false, 'message' => 'Project not found.']);
                $conn->rollBack();
                exit;
            }
            
            // Verify this user is the project owner
            if ($project['owner_id'] != $user_id) {
                echo json_encode(['success' => false, 'message' => 'Only the project owner can deny leave requests.']);
                $conn->rollBack();
                exit;
            }
            
            // Get leaving user details
            $stmt = $conn->prepare("
                SELECT first_name, last_name FROM users WHERE user_id = ?
            ");
            $stmt->execute([$leaving_user_id]);
            $leaving_user = $stmt->fetch();
            
            if ($leaving_user) {
                // Notify the user that their request was denied
                $notification_message = "Your request to leave " . $project['name'] . " has been denied.";
                $stmt = $conn->prepare("
                    INSERT INTO notifications (user_id, type, message, related_id, related_user_id, created_at)
                    VALUES (?, 'leave_denied', ?, ?, ?, NOW())
                ");
                $stmt->execute([$leaving_user_id, $notification_message, $project_id, $user_id]);
            }
        }
        
        // Mark notification as read and delete it
        $stmt = $conn->prepare("
            DELETE FROM notifications 
            WHERE notification_id = ?
        ");
        $stmt->execute([$notification_id]);
        
        // Commit transaction
        $conn->commit();
        
        echo json_encode(['success' => true, 'message' => 'Action processed successfully.']);
        
    } catch (PDOException $e) {
        // Rollback transaction on error
        $conn->rollBack();
        echo json_encode(['success' => false, 'message' => 'Error processing action: ' . $e->getMessage()]);
    }
}
// Get all notifications (paginated)
else if ($action === 'get_all') {
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
    $offset = ($page - 1) * $limit;
    $filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';
    
    try {
        // First, find and fix join request notifications with empty type
        $stmt = $conn->prepare("
            UPDATE notifications 
            SET type = 'join_request' 
            WHERE (type = '' OR type IS NULL) 
            AND (message LIKE '%requested to join%' OR message LIKE '%request to join%')
        ");
        $stmt->execute();
        $fixedCount = $stmt->rowCount();
        
        if ($fixedCount > 0) {
            error_log("Fixed $fixedCount join request notifications with empty type");
        }
        // Also fix leave request notifications with empty or incorrect type
        $stmt = $conn->prepare("
            UPDATE notifications 
            SET type = 'leave_project' 
            WHERE (type = '' OR type IS NULL OR type = 'leave_request') 
            AND (message LIKE '%requested to leave%' OR message LIKE '%leave the project%')
        ");
        $stmt->execute();
        $fixedLeaveCount = $stmt->rowCount();
        
        if ($fixedLeaveCount > 0) {
            error_log("Fixed $fixedLeaveCount leave request notifications with empty or incorrect type");
        }
        // Build the query based on filter
        $whereClause = "WHERE n.user_id = ?";
        $params = [$user_id];
        
        if ($filter === 'unread') {
            $whereClause .= " AND n.is_read = 0";
        } else if ($filter === 'read') {
            $whereClause .= " AND n.is_read = 1";
        }
        
        // Get total count for pagination
        $countStmt = $conn->prepare("
            SELECT COUNT(*) as total 
            FROM notifications n 
            $whereClause
        ");
        $countStmt->execute($params);
        $totalCount = $countStmt->fetch()['total'];
        
        // Get notifications
        $stmt = $conn->prepare("
            SELECT n.*, 
                   u.first_name, u.last_name, u.profile_image,
                   p.name as project_name,
                   t.title as task_title
            FROM notifications n
            LEFT JOIN users u ON n.related_user_id = u.user_id
            LEFT JOIN projects p ON (n.type IN ('project_invite', 'leave_project', 'join_request') AND n.related_id = p.project_id)
            LEFT JOIN tasks t ON (n.type IN ('task_assigned', 'task_completed') AND n.related_id = t.task_id)
            $whereClause
            ORDER BY n.created_at DESC
            LIMIT ? OFFSET ?
        ");
        
        // Add limit and offset to params
        $params[] = $limit;
        $params[] = $offset;
        $stmt->execute($params);
        $notifications = $stmt->fetchAll();
        
        // Process notifications for better display
        foreach ($notifications as &$notification) {
            // Format the profile image path if it exists
            if (!empty($notification['profile_image'])) {
                // Add the path prefix if it doesn't already have one
                if (!str_starts_with($notification['profile_image'], 'http') && 
                    !str_starts_with($notification['profile_image'], '/') &&
                    !str_starts_with($notification['profile_image'], '../../')) {
                    
                    // Check if the file exists
                    $img_path = '../../' . $notification['profile_image'];
                    if (file_exists($img_path)) {
                        $notification['profile_image'] = $img_path;
                    } else {
                        // If file doesn't exist, use default avatar
                        $notification['profile_image'] = '../../assets/images/default-avatar.svg';
                    }
                }
            } else {
                // Set default avatar for users without profile images
                $notification['profile_image'] = '../../assets/images/default-avatar.svg';
            }
            
            // Add additional context based on notification type
            switch ($notification['type']) {
                case 'task_assigned':
                case 'task_completed':
                    $notification['context'] = [
                        'task_title' => $notification['task_title'] ?? 'Unknown Task'
                    ];
                    break;
                case 'project_invite':
                case 'join_request':
                case 'leave_project':
                    $notification['context'] = [
                        'project_name' => $notification['project_name'] ?? 'Unknown Project'
                    ];
                    break;
            }
        }
        
        // Calculate if there are more notifications
        $hasMore = ($offset + $limit) < $totalCount;
        
        echo json_encode([
            'success' => true, 
            'notifications' => $notifications,
            'total' => $totalCount,
            'page' => $page,
            'has_more' => $hasMore
        ]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Error fetching notifications: ' . $e->getMessage()]);
    }
}
// Mark notification as unread
else if ($action === 'mark_unread') {
    $notification_id = isset($_POST['notification_id']) ? $_POST['notification_id'] : 0;
    
    if (!$notification_id) {
        echo json_encode(['success' => false, 'message' => 'Missing notification ID.']);
        exit;
    }
    
    try {
        $stmt = $conn->prepare("
            UPDATE notifications 
            SET is_read = FALSE 
            WHERE notification_id = ? AND user_id = ?
        ");
        $stmt->execute([$notification_id, $user_id]);
        
        echo json_encode(['success' => true, 'message' => 'Notification marked as unread.']);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Error marking notification as unread: ' . $e->getMessage()]);
    }
}
// Invalid action
else {
    echo json_encode(['success' => false, 'message' => 'Invalid action.']);
} 