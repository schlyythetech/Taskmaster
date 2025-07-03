<?php
require_once '../../includes/functions.php';
require_once '../../config/database.php';

// Set content type to JSON
header('Content-Type: application/json');

// Check if user is logged in
if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'You must be logged in to create an epic.']);
    exit;
}

// Validate CSRF token
$csrf_token = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
if (empty($csrf_token) || $csrf_token !== $_SESSION['csrf_token']) {
    echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
    exit;
}

// Check required fields
if (empty($_POST['project_id']) || empty($_POST['title']) || empty($_POST['created_by'])) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields.']);
    exit;
}

// Process form data
$project_id = $_POST['project_id'];
$title = trim($_POST['title']);
$description = isset($_POST['description']) ? trim($_POST['description']) : '';
$created_by = $_POST['created_by'];
$status = isset($_POST['status']) ? $_POST['status'] : 'not_started';
$color = isset($_POST['color']) ? $_POST['color'] : '#ff6b6b';

try {
    // Check if user is a member of this project
    $stmt = $conn->prepare("
        SELECT 1 FROM project_members
        WHERE project_id = ? AND user_id = ?
    ");
    $stmt->execute([$project_id, $_SESSION['user_id']]);
    $is_member = $stmt->fetch();
    
    if (!$is_member) {
        echo json_encode(['success' => false, 'message' => 'You are not a member of this project.']);
        exit;
    }
    
    // Insert epic
    $stmt = $conn->prepare("
        INSERT INTO epics (
            project_id,
            title,
            description,
            status,
            created_by,
            created_at,
            updated_at
        ) VALUES (
            ?, ?, ?, ?, ?, NOW(), NOW()
        )
    ");
    $stmt->execute([
        $project_id,
        $title,
        $description,
        $status,
        $created_by
    ]);
    $epic_id = $conn->lastInsertId();
    
    echo json_encode(['success' => true, 'message' => 'Epic created successfully.', 'epic_id' => $epic_id]);
} catch (PDOException $e) {
    error_log("Error creating epic: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} 