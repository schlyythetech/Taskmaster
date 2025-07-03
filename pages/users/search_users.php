<?php
require_once '../../includes/functions.php';
require_once '../../config/database.php';

// Check if user is logged in
if (!isLoggedIn()) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'You must be logged in to search users.']);
    exit;
}

// Get user ID from session
$user_id = $_SESSION['user_id'];

// Initialize response
$response = ['success' => false, 'message' => 'No search term provided.', 'users' => []];

// Check if search term is provided
if (isset($_GET['term']) && !empty($_GET['term'])) {
    $search_term = '%' . trim($_GET['term']) . '%';
    
    try {
        // Search for users by name or email
        $stmt = $conn->prepare("
            SELECT 
                u.user_id, u.first_name, u.last_name, u.email, u.profile_image, u.bio,
                (SELECT COUNT(*) FROM connections 
                 WHERE ((user_id = u.user_id AND connected_user_id IN 
                        (SELECT connected_user_id FROM connections WHERE user_id = ? AND status = 'accepted'))
                        OR
                        (connected_user_id = u.user_id AND user_id IN 
                        (SELECT connected_user_id FROM connections WHERE user_id = ? AND status = 'accepted')))
                 AND status = 'accepted') as mutual_connections,
                CASE 
                    WHEN EXISTS (
                        SELECT 1 FROM connections 
                        WHERE (user_id = ? AND connected_user_id = u.user_id)
                        OR (user_id = u.user_id AND connected_user_id = ?)
                    ) THEN TRUE 
                    ELSE FALSE 
                END as has_connection,
                (SELECT status FROM connections 
                 WHERE ((user_id = ? AND connected_user_id = u.user_id) 
                 OR (user_id = u.user_id AND connected_user_id = ?)) 
                 LIMIT 1) as connection_status,
                (SELECT user_id FROM connections 
                 WHERE ((user_id = ? AND connected_user_id = u.user_id) 
                 OR (user_id = u.user_id AND connected_user_id = ?)) 
                 LIMIT 1) as connection_requester_id
            FROM users u
            WHERE u.user_id != ? 
              AND u.role != 'admin' 
              AND u.is_banned = 0
              AND (
                  u.first_name LIKE ? 
                  OR u.last_name LIKE ? 
                  OR u.email LIKE ?
                  OR CONCAT(u.first_name, ' ', u.last_name) LIKE ?
              )
            ORDER BY mutual_connections DESC, u.first_name ASC
            LIMIT 10
        ");
        
        $stmt->execute([
            $user_id, $user_id, 
            $user_id, $user_id, 
            $user_id, $user_id, 
            $user_id, $user_id, 
            $user_id, 
            $search_term, $search_term, $search_term, $search_term
        ]);
        
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Process results
        foreach ($users as &$user) {
            // Determine connection button state
            if ($user['has_connection']) {
                if ($user['connection_status'] === 'accepted') {
                    $user['connection_state'] = 'connected';
                    $user['connection_text'] = 'Connected';
                } else if ($user['connection_status'] === 'pending') {
                    if ($user['connection_requester_id'] == $user_id) {
                        $user['connection_state'] = 'pending_sent';
                        $user['connection_text'] = 'Request Sent';
                    } else {
                        $user['connection_state'] = 'pending_received';
                        $user['connection_text'] = 'Accept Request';
                    }
                } else if ($user['connection_status'] === 'rejected') {
                    // For rejected connections, allow reconnecting but use a different button style
                    // and action depending on who rejected whom
                    if ($user['connection_requester_id'] == $user_id) {
                        // Current user sent the original request that was rejected
                        $user['connection_state'] = 'rejected_sent';
                        $user['connection_text'] = 'Connect Again';
                        // Store original connection id for possible retry
                        $user['connection_id'] = $user['connection_id'] ?? null;
                    } else {
                        // Current user rejected the request from the other user
                        $user['connection_state'] = 'rejected_received';
                        $user['connection_text'] = 'Connect';
                        // Store original connection id for possible retry
                        $user['connection_id'] = $user['connection_id'] ?? null;
                    }
                } else {
                    $user['connection_state'] = 'none';
                    $user['connection_text'] = 'Connect';
                }
            } else {
                $user['connection_state'] = 'none';
                $user['connection_text'] = 'Connect';
            }
            
            // Keep connection_id if it exists for potential retry actions
            if (isset($user['connection_id'])) {
                $user['connection_id'] = $user['connection_id'];
            }
            
            // Remove sensitive fields that are no longer needed
            unset($user['has_connection']);
            unset($user['connection_status']);
            unset($user['connection_requester_id']);
        }
        
        $response['success'] = true;
        $response['message'] = 'Users found';
        $response['users'] = $users;
    } catch (PDOException $e) {
        $response['message'] = 'Database error: ' . $e->getMessage();
    }
} else {
    $response['message'] = 'No search term provided.';
}

// Return JSON response
header('Content-Type: application/json');
echo json_encode($response);
?> 