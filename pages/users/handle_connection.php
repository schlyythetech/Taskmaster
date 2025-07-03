<?php
require_once '../../includes/functions.php';
require_once '../../config/database.php';

// Check if user is logged in
if (!isLoggedIn()) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'You must be logged in to perform this action.']);
    exit;
}

// Get user ID from session
$user_id = $_SESSION['user_id'];

/**
 * Verify that a user exists in the database
 * 
 * @param PDO $conn Database connection
 * @param int $user_id The user ID to verify
 * @return bool True if user exists, false otherwise
 */
function verifyUserExists($conn, $user_id) {
    $stmt = $conn->prepare("SELECT user_id FROM users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    return $stmt->fetch() !== false;
}

/**
 * Get connection status between two users
 * 
 * @param PDO $conn Database connection
 * @param int $user_id First user ID
 * @param int $target_user_id Second user ID
 * @return array|null Connection details or null if no connection exists
 */
function getConnectionStatus($conn, $user_id, $target_user_id) {
    $stmt = $conn->prepare("
        SELECT * FROM connections 
        WHERE (user_id = ? AND connected_user_id = ?) 
        OR (user_id = ? AND connected_user_id = ?)
    ");
    $stmt->execute([$user_id, $target_user_id, $target_user_id, $user_id]);
    $connection = $stmt->fetch();
    
    if ($connection) {
        // Add a direction field to indicate who sent the request
        $connection['direction'] = ($connection['user_id'] == $user_id) ? 'sent' : 'received';
    }
    
    return $connection;
}

// Initialize response
$response = ['success' => false, 'message' => 'Invalid action.'];

// Check if it's a POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $response = ['success' => false, 'message' => 'Invalid security token.'];
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }
    
    // Get action
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    
    // Add error logging for debugging
    error_log("Connection action requested: $action by user ID: $user_id");
    
    // First verify the current user exists
    if (!verifyUserExists($conn, $user_id)) {
        $response = ['success' => false, 'message' => 'Your user account could not be verified. Please log in again.'];
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }
    
    switch ($action) {
        case 'accept':
            // Accept connection request
            if (!isset($_POST['connection_id'])) {
                $response['message'] = 'Connection ID is required.';
                break;
            }
            
            $connection_id = (int)$_POST['connection_id'];
            
            try {
                // Begin transaction for data consistency
                $conn->beginTransaction();
                
                // Verify the connection request exists and is directed to the current user
                $stmt = $conn->prepare("
                    SELECT * FROM connections 
                    WHERE connection_id = ? 
                    AND connected_user_id = ? 
                    AND status = 'pending'
                ");
                $stmt->execute([$connection_id, $user_id]);
                $connection = $stmt->fetch();
                
                if (!$connection) {
                    $response['message'] = 'Connection request not found or already processed.';
                    break;
                }
                
                // Verify the requesting user still exists in the database
                if (!verifyUserExists($conn, $connection['user_id'])) {
                    $response['message'] = 'The user who sent this request no longer exists.';
                    break;
                }
                
                // Update connection status
                $stmt = $conn->prepare("
                    UPDATE connections 
                    SET status = 'accepted', 
                        updated_at = CURRENT_TIMESTAMP 
                    WHERE connection_id = ?
                ");
                $stmt->execute([$connection_id]);
                
                // Create notification for requester
                $stmt = $conn->prepare("
                    INSERT INTO notifications (user_id, type, message, related_id, related_user_id) 
                    VALUES (?, 'connection_request', ?, ?, ?)
                ");
                
                // Get user name
                $stmt_name = $conn->prepare("SELECT first_name, last_name FROM users WHERE user_id = ?");
                $stmt_name->execute([$user_id]);
                $user = $stmt_name->fetch();
                
                // Verify the user exists before proceeding
                if (!$user) {
                    throw new Exception("User not found when creating notification");
                }
                
                $user_name = $user['first_name'] . ' ' . $user['last_name'];
                
                $message = $user_name . ' accepted your connection request.';
                
                // Verify that the connected user exists before creating the notification
                if (!verifyUserExists($conn, $connection['user_id'])) {
                    throw new Exception("Cannot create notification: target user does not exist");
                }
                
                $stmt->execute([$connection['user_id'], $message, $connection_id, $user_id]);
                
                // Commit the transaction
                $conn->commit();
                
                $response['success'] = true;
                $response['message'] = 'Connection request accepted.';
                
            } catch (PDOException $e) {
                // Roll back the transaction on failure
                $conn->rollBack();
                error_log("PDO Error in accept connection: " . $e->getMessage());
                $response['message'] = 'Database error: ' . $e->getMessage();
            } catch (Exception $e) {
                // Roll back the transaction on failure
                $conn->rollBack();
                error_log("Error in accept connection: " . $e->getMessage());
                $response['message'] = 'Error: ' . $e->getMessage();
            }
            break;
            
        case 'reject':
            // Reject connection request
            if (!isset($_POST['connection_id'])) {
                $response['message'] = 'Connection ID is required.';
                break;
            }
            
            $connection_id = (int)$_POST['connection_id'];
            
            try {
                // Begin transaction
                $conn->beginTransaction();
                
                // Verify the connection request exists and is directed to the current user
                $stmt = $conn->prepare("
                    SELECT * FROM connections 
                    WHERE connection_id = ? 
                    AND connected_user_id = ? 
                    AND status = 'pending'
                ");
                $stmt->execute([$connection_id, $user_id]);
                $connection = $stmt->fetch();
                
                if (!$connection) {
                    $response['message'] = 'Connection request not found or already processed.';
                    break;
                }
                
                // Verify the requesting user still exists
                if (!verifyUserExists($conn, $connection['user_id'])) {
                    // If user doesn't exist, it's still ok to reject
                    error_log("Rejecting connection request from non-existent user ID: " . $connection['user_id']);
                }
                
                // Update connection status
                $stmt = $conn->prepare("
                    UPDATE connections 
                    SET status = 'rejected', 
                        updated_at = CURRENT_TIMESTAMP 
                    WHERE connection_id = ?
                ");
                $stmt->execute([$connection_id]);
                
                // Commit transaction
                $conn->commit();
                
                $response['success'] = true;
                $response['message'] = 'Connection request rejected.';
                
            } catch (PDOException $e) {
                // Roll back transaction on failure
                $conn->rollBack();
                error_log("PDO Error in reject connection: " . $e->getMessage());
                $response['message'] = 'Database error: ' . $e->getMessage();
            } catch (Exception $e) {
                // Roll back transaction on failure
                $conn->rollBack();
                error_log("Error in reject connection: " . $e->getMessage());
                $response['message'] = 'Error: ' . $e->getMessage();
            }
            break;
            
        case 'cancel':
            // Cancel connection request
            if (!isset($_POST['connection_id'])) {
                $response['message'] = 'Connection ID is required.';
                break;
            }
            
            $connection_id = (int)$_POST['connection_id'];
            
            try {
                // Begin transaction
                $conn->beginTransaction();
                
                // Verify the connection request exists and was sent by the current user
                $stmt = $conn->prepare("
                    SELECT * FROM connections 
                    WHERE connection_id = ? 
                    AND user_id = ? 
                    AND status = 'pending'
                ");
                $stmt->execute([$connection_id, $user_id]);
                $connection = $stmt->fetch();
                
                if (!$connection) {
                    $response['message'] = 'Connection request not found or already processed.';
                    break;
                }
                
                // Verify the target user still exists
                if (!verifyUserExists($conn, $connection['connected_user_id'])) {
                    // If target user doesn't exist, it's still ok to cancel
                    error_log("Canceling connection request to non-existent user ID: " . $connection['connected_user_id']);
                }
                
                // Delete the connection request
                $stmt = $conn->prepare("DELETE FROM connections WHERE connection_id = ?");
                $stmt->execute([$connection_id]);
                
                // Commit transaction
                $conn->commit();
                
                $response['success'] = true;
                $response['message'] = 'Connection request canceled.';
                
            } catch (PDOException $e) {
                // Roll back transaction on failure
                $conn->rollBack();
                error_log("PDO Error in cancel connection: " . $e->getMessage());
                $response['message'] = 'Database error: ' . $e->getMessage();
            } catch (Exception $e) {
                // Roll back transaction on failure
                $conn->rollBack();
                error_log("Error in cancel connection: " . $e->getMessage());
                $response['message'] = 'Error: ' . $e->getMessage();
            }
            break;
            
        case 'send_request':
            // Send connection request
            if (!isset($_POST['user_id'])) {
                $response['message'] = 'User ID is required.';
                break;
            }
            
            $target_user_id = (int)$_POST['user_id'];
            
            // Can't send request to yourself
            if ($target_user_id === $user_id) {
                $response['message'] = 'You cannot connect with yourself.';
                break;
            }
            
            try {
                // Begin transaction
                $conn->beginTransaction();
                
                // Check if user exists and is not banned
                $stmt = $conn->prepare("
                    SELECT * FROM users 
                    WHERE user_id = ? AND is_banned = 0
                ");
                $stmt->execute([$target_user_id]);
                $target_user = $stmt->fetch();
                
                if (!$target_user) {
                    $response['message'] = 'User not found or is banned.';
                    break;
                }
                
                // Check if a connection already exists
                $existing_connection = getConnectionStatus($conn, $user_id, $target_user_id);
                
                if ($existing_connection) {
                    if ($existing_connection['status'] === 'accepted') {
                        $response['message'] = 'You are already connected with this user.';
                        break;
                    } else if ($existing_connection['status'] === 'pending') {
                        if ($existing_connection['direction'] === 'sent') {
                            $response['message'] = 'You have already sent a connection request to this user.';
                            break;
                        } else {
                            $response['message'] = 'This user has already sent you a connection request. Check your pending requests.';
                            break;
                        }
                    } else if ($existing_connection['status'] === 'rejected') {
                        // If previously rejected, allow re-sending by updating the existing record
                        // Only if the current user was the original sender or this is a retry_request action
                        if ($existing_connection['direction'] === 'sent' || $action === 'retry_request') {
                            $stmt = $conn->prepare("
                                UPDATE connections 
                                SET status = 'pending', updated_at = CURRENT_TIMESTAMP
                                WHERE connection_id = ?
                            ");
                            $stmt->execute([$existing_connection['connection_id']]);
                            
                            // Create notification for the connection request
                            $stmt = $conn->prepare("
                                INSERT INTO notifications (user_id, type, message, related_id, related_user_id) 
                                VALUES (?, 'connection_request', ?, ?, ?)
                            ");
                            
                            // Get user name
                            $stmt_name = $conn->prepare("SELECT first_name, last_name FROM users WHERE user_id = ?");
                            $stmt_name->execute([$user_id]);
                            $user = $stmt_name->fetch();
                            
                            if (!$user) {
                                throw new Exception("User not found when creating notification");
                            }
                            
                            $user_name = $user['first_name'] . ' ' . $user['last_name'];
                            $message = $user_name . ' sent you a connection request.';
                            
                            // Determine recipient of notification based on direction
                            $recipient_id = ($existing_connection['direction'] === 'sent') 
                                ? $existing_connection['connected_user_id'] 
                                : $existing_connection['user_id'];
                                
                            $stmt->execute([$recipient_id, $message, $existing_connection['connection_id'], $user_id]);
                            
                            $conn->commit();
                            $response['success'] = true;
                            $response['message'] = 'Connection request sent.';
                            break;
                        } else {
                            // If the other user rejected your request, don't allow automatic re-sending
                            $response['message'] = 'A previous connection request was rejected.';
                            break;
                        }
                    }
                }
                
                // Create a new connection request
                $stmt = $conn->prepare("
                    INSERT INTO connections (user_id, connected_user_id, status) 
                    VALUES (?, ?, 'pending')
                ");
                $stmt->execute([$user_id, $target_user_id]);
                
                // Create notification
                $stmt = $conn->prepare("
                    INSERT INTO notifications (user_id, type, message, related_id, related_user_id) 
                    VALUES (?, 'connection_request', ?, ?, ?)
                ");
                
                // Get user name
                $stmt_name = $conn->prepare("SELECT first_name, last_name FROM users WHERE user_id = ?");
                $stmt_name->execute([$user_id]);
                $user = $stmt_name->fetch();
                
                // Verify the user exists before proceeding
                if (!$user) {
                    throw new Exception("User not found when creating notification");
                }
                
                $user_name = $user['first_name'] . ' ' . $user['last_name'];
                
                $message = $user_name . ' sent you a connection request.';
                
                // Ensure that both the sender and target user exist in the users table
                $last_insert_id = $conn->lastInsertId();
                
                // Verify target user still exists
                $stmt_verify = $conn->prepare("SELECT user_id FROM users WHERE user_id = ?");
                $stmt_verify->execute([$target_user_id]);
                if (!$stmt_verify->fetch()) {
                    // If target user doesn't exist, delete the connection we just created
                    $stmt_delete = $conn->prepare("DELETE FROM connections WHERE connection_id = ?");
                    $stmt_delete->execute([$last_insert_id]);
                    throw new Exception("Cannot create notification: target user no longer exists");
                }
                
                // Also verify the sender still exists
                $stmt_verify->execute([$user_id]);
                if (!$stmt_verify->fetch()) {
                    // If sender no longer exists, delete the connection we just created
                    $stmt_delete = $conn->prepare("DELETE FROM connections WHERE connection_id = ?");
                    $stmt_delete->execute([$last_insert_id]);
                    throw new Exception("Cannot create notification: sender user no longer exists");
                }
                
                $stmt->execute([$target_user_id, $message, $last_insert_id, $user_id]);
                
                // Commit transaction
                $conn->commit();
                
                $response['success'] = true;
                $response['message'] = 'Connection request sent.';
                
            } catch (PDOException $e) {
                // Roll back transaction on failure
                $conn->rollBack();
                error_log("PDO Error in send connection request: " . $e->getMessage());
                $response['message'] = 'Database error: ' . $e->getMessage();
            } catch (Exception $e) {
                // Roll back transaction on failure
                $conn->rollBack();
                error_log("Error in send connection request: " . $e->getMessage());
                $response['message'] = 'Error: ' . $e->getMessage();
            }
            break;
            
        case 'retry_request':
            // Retry a previously rejected connection request
            if (!isset($_POST['user_id'])) {
                $response['message'] = 'User ID is required.';
                break;
            }
            
            $target_user_id = (int)$_POST['user_id'];
            
            // Can't connect with yourself
            if ($target_user_id === $user_id) {
                $response['message'] = 'You cannot connect with yourself.';
                break;
            }
            
            try {
                // Begin transaction
                $conn->beginTransaction();
                
                // Check if user exists and is not banned
                $stmt = $conn->prepare("
                    SELECT * FROM users 
                    WHERE user_id = ? AND is_banned = 0
                ");
                $stmt->execute([$target_user_id]);
                $target_user = $stmt->fetch();
                
                if (!$target_user) {
                    $response['message'] = 'User not found or is banned.';
                    break;
                }
                
                // Check if a rejected connection exists
                $stmt = $conn->prepare("
                    SELECT * FROM connections 
                    WHERE ((user_id = ? AND connected_user_id = ?) 
                    OR (user_id = ? AND connected_user_id = ?))
                    AND status = 'rejected'
                ");
                $stmt->execute([$user_id, $target_user_id, $target_user_id, $user_id]);
                $existing_connection = $stmt->fetch();
                
                if (!$existing_connection) {
                    $response['message'] = 'No rejected connection found to retry.';
                    break;
                }
                
                // Update connection status to pending
                $stmt = $conn->prepare("
                    UPDATE connections 
                    SET status = 'pending', updated_at = CURRENT_TIMESTAMP
                    WHERE connection_id = ?
                ");
                $stmt->execute([$existing_connection['connection_id']]);
                
                // Create notification for the connection request
                $stmt = $conn->prepare("
                    INSERT INTO notifications (user_id, type, message, related_id, related_user_id) 
                    VALUES (?, 'connection_request', ?, ?, ?)
                ");
                
                // Get user name
                $stmt_name = $conn->prepare("SELECT first_name, last_name FROM users WHERE user_id = ?");
                $stmt_name->execute([$user_id]);
                $user = $stmt_name->fetch();
                
                if (!$user) {
                    throw new Exception("User not found when creating notification");
                }
                
                $user_name = $user['first_name'] . ' ' . $user['last_name'];
                $message = $user_name . ' sent you a new connection request.';
                
                // Determine recipient based on who was the original requester
                $recipient_id = ($existing_connection['user_id'] == $user_id) 
                    ? $existing_connection['connected_user_id'] 
                    : $existing_connection['user_id'];
                
                $stmt->execute([$recipient_id, $message, $existing_connection['connection_id'], $user_id]);
                
                // Commit transaction
                $conn->commit();
                
                $response['success'] = true;
                $response['message'] = 'Connection request sent.';
                
            } catch (PDOException $e) {
                // Roll back transaction on failure
                $conn->rollBack();
                error_log("PDO Error in retry connection request: " . $e->getMessage());
                $response['message'] = 'Database error: ' . $e->getMessage();
            } catch (Exception $e) {
                // Roll back transaction on failure
                $conn->rollBack();
                error_log("Error in retry connection request: " . $e->getMessage());
                $response['message'] = 'Error: ' . $e->getMessage();
            }
            break;
            
        default:
            $response['message'] = 'Invalid action.';
    }
}

// Return JSON response
header('Content-Type: application/json');
echo json_encode($response);
?> 