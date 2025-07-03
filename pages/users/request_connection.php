<?php
require_once '../../includes/functions.php';
require_once '../../config/database.php';

// Set content type to JSON if it's an AJAX request
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    header('Content-Type: application/json');
}

// Check if user is logged in
if (!isLoggedIn()) {
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        echo json_encode(['success' => false, 'message' => 'You must be logged in to send connection requests.']);
        exit;
    } else {
        setMessage("You must be logged in to send connection requests.", "danger");
        redirect('../auth/login.php');
    }
}

// Check if form was submitted
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
        exit;
    } else {
        setMessage("Invalid request method.", "danger");
        redirect('../users/connections.php');
    }
}

// Validate CSRF token
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
        exit;
    } else {
        setMessage("Invalid security token. Please try again.", "danger");
        redirect('../users/connections.php');
    }
}

// Check if user ID is provided
if (!isset($_POST['user_id']) || !is_numeric($_POST['user_id'])) {
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid user ID.']);
        exit;
    } else {
        setMessage("Invalid user ID.", "danger");
        redirect('../users/connections.php');
    }
}

$requested_user_id = $_POST['user_id'];
$user_id = $_SESSION['user_id'];

// Prevent self-connection
if ($requested_user_id == $user_id) {
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        echo json_encode(['success' => false, 'message' => 'You cannot connect with yourself.']);
        exit;
    } else {
        setMessage("You cannot connect with yourself.", "danger");
        redirect('../users/connections.php');
    }
}

try {
    // Verify the current logged-in user exists
    $stmt = $conn->prepare("SELECT user_id FROM users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $current_user = $stmt->fetch();
    
    if (!$current_user) {
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            echo json_encode(['success' => false, 'message' => 'Your user account could not be verified.']);
            exit;
        } else {
            setMessage("Your user account could not be verified. Please log in again.", "danger");
            redirect('logout.php');
        }
    }
    
    // Check if requested user exists
    $stmt = $conn->prepare("SELECT user_id, first_name, last_name FROM users WHERE user_id = ?");
    $stmt->execute([$requested_user_id]);
    $requested_user = $stmt->fetch();
    
    if (!$requested_user) {
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            echo json_encode(['success' => false, 'message' => 'User not found.']);
            exit;
        } else {
            setMessage("User not found.", "danger");
            redirect('../users/connections.php');
        }
    }
    
    // Check if connection already exists
    $stmt = $conn->prepare("
        SELECT status FROM connections 
        WHERE (user_id = ? AND connected_user_id = ?) 
        OR (user_id = ? AND connected_user_id = ?)
    ");
    $stmt->execute([$user_id, $requested_user_id, $requested_user_id, $user_id]);
    $existing_connection = $stmt->fetch();
    
    if ($existing_connection) {
        $message = '';
        switch ($existing_connection['status']) {
            case 'pending':
                $message = 'Connection request already sent.';
                break;
            case 'accepted':
                $message = 'You are already connected with this user.';
                break;
            case 'rejected':
                $message = 'This connection has been rejected.';
                break;
        }
        
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            echo json_encode(['success' => false, 'message' => $message]);
            exit;
        } else {
            setMessage($message, "warning");
            redirect('../users/connections.php');
        }
    }
    
    // Get requester's name
    $stmt = $conn->prepare("SELECT first_name, last_name FROM users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $requester = $stmt->fetch();
    $requester_name = $requester['first_name'] . ' ' . $requester['last_name'];
    
    // Begin transaction
    $conn->beginTransaction();
    
    // Double-check both users still exist before creating connection
    $stmt = $conn->prepare("
        SELECT COUNT(*) as count FROM users 
        WHERE user_id IN (?, ?)
    ");
    $stmt->execute([$user_id, $requested_user_id]);
    $result = $stmt->fetch();
    
    if ($result['count'] < 2) {
        throw new Exception("One or both users no longer exist in the database.");
    }
    
    // Create connection request
    $stmt = $conn->prepare("
        INSERT INTO connections (user_id, connected_user_id, status, created_at) 
        VALUES (?, ?, 'pending', NOW())
    ");
    $stmt->execute([$user_id, $requested_user_id]);
    
    // Create notification
    $notification_message = $requester_name . " request to connect.";
    $stmt = $conn->prepare("
        INSERT INTO notifications (user_id, type, message, related_id, created_at) 
        VALUES (?, 'connection_request', ?, ?, NOW())
    ");
    $stmt->execute([$requested_user_id, $notification_message, $user_id]);
    
    // Commit transaction
    $conn->commit();
    
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        echo json_encode(['success' => true, 'message' => 'Connection request sent successfully.']);
    } else {
        setMessage("Connection request sent to " . $requested_user['first_name'] . " " . $requested_user['last_name'] . ".", "success");
        redirect('../users/connections.php');
    }
} catch (PDOException $e) {
    // Roll back transaction on error
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        echo json_encode(['success' => false, 'message' => 'Error sending connection request: ' . $e->getMessage()]);
    } else {
        setMessage("Error sending connection request: " . $e->getMessage(), "danger");
        redirect('../users/connections.php');
    }
} catch (Exception $e) {
    // Roll back transaction on error
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    } else {
        setMessage("Error: " . $e->getMessage(), "danger");
        redirect('../users/connections.php');
    }
} 