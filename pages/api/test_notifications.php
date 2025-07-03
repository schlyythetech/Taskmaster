<?php
require_once '../../includes/functions.php';
require_once '../../config/database.php';

// Check if user is logged in
if (!isLoggedIn()) {
    setMessage("You must be logged in to test notifications.", "danger");
    redirect('../auth/login.php');
}

// Only admin can run this
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    setMessage("You must be an admin to run this test.", "danger");
    redirect('../core/dashboard.php');
}

$user_id = $_SESSION['user_id'];
$action = isset($_GET['action']) ? $_GET['action'] : '';

// Add test notifications
if ($action === 'add') {
    try {
        // Delete existing notifications for this user
        $stmt = $conn->prepare("DELETE FROM notifications WHERE user_id = ?");
        $stmt->execute([$user_id]);
        
        // Sample notifications data
        $sampleNotifications = [
            [
                'type' => 'connection_request',
                'message' => 'John Doe request to connect.',
                'related_id' => 1, // Dummy ID
                'created_at' => date('Y-m-d H:i:s', strtotime('-1 hour'))
            ],
            [
                'type' => 'project_invite',
                'message' => 'Jane Smith request to you to join Project X.',
                'related_id' => 1, // Dummy ID
                'created_at' => date('Y-m-d H:i:s', strtotime('-2 hours'))
            ],
            [
                'type' => 'leave_request',
                'message' => 'Robert Johnson request to leave ProjectName.',
                'related_id' => 2, // Dummy ID
                'created_at' => date('Y-m-d H:i:s', strtotime('-3 hours'))
            ],
            [
                'type' => 'invitation_accepted',
                'message' => 'Alice Brown has accepted your invitation to join Project Z.',
                'related_id' => 3, // Dummy ID
                'created_at' => date('Y-m-d H:i:s', strtotime('-4 hours'))
            ],
            [
                'type' => 'invitation_declined',
                'message' => 'Tom Wilson has declined your invitation to join Project Y.',
                'related_id' => 4, // Dummy ID
                'created_at' => date('Y-m-d H:i:s', strtotime('-5 hours'))
            ],
            [
                'type' => 'task_assigned',
                'message' => 'You have been assigned a new task: Fix the bug in login page.',
                'related_id' => 1, // Sample task ID
                'created_at' => date('Y-m-d H:i:s', strtotime('-6 hours'))
            ]
        ];
        
        // Insert sample notifications
        $stmt = $conn->prepare("
            INSERT INTO notifications (user_id, type, message, related_id, created_at, is_read)
            VALUES (?, ?, ?, ?, ?, FALSE)
        ");
        
        foreach ($sampleNotifications as $notification) {
            $stmt->execute([
                $user_id,
                $notification['type'],
                $notification['message'],
                $notification['related_id'],
                $notification['created_at']
            ]);
        }
        
        setMessage("Test notifications added successfully.", "success");
    } catch (PDOException $e) {
        setMessage("Error adding test notifications: " . $e->getMessage(), "danger");
    }
    
    redirect('../core/dashboard.php');
    exit;
}

// Clear all notifications
if ($action === 'clear') {
    try {
        $stmt = $conn->prepare("DELETE FROM notifications WHERE user_id = ?");
        $stmt->execute([$user_id]);
        
        setMessage("All notifications cleared.", "success");
    } catch (PDOException $e) {
        setMessage("Error clearing notifications: " . $e->getMessage(), "danger");
    }
    
    redirect('../core/dashboard.php');
    exit;
}

// Display test interface
$page_title = "Test Notifications";
include '../../includes/header.php';
?>

<div class="container mt-5">
    <div class="row">
        <div class="col-md-8 offset-md-2">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h3>Notification System Test</h3>
                </div>
                <div class="card-body">
                    <p>This page allows you to test the notification system. You can add sample notifications or clear all notifications.</p>
                    
                    <div class="d-flex gap-3 mt-4">
                        <a href="test_notifications.php?action=add" class="btn btn-success">Add Test Notifications</a>
                        <a href="test_notifications.php?action=clear" class="btn btn-danger">Clear All Notifications</a>
                        <a href="../core/dashboard.php" class="btn btn-secondary">Back to Dashboard</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?> 