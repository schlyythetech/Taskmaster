<?php
require_once '../../includes/functions.php';
require_once '../../config/database.php';

// Set content type to JSON
header('Content-Type: application/json');

// Check if user is logged in
if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'You must be logged in to favorite a project.']);
    exit;
}

// Check if request method is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

// Validate CSRF token via X-CSRF-Token header
$csrf_token = isset($_SERVER['HTTP_X_CSRF_TOKEN']) ? $_SERVER['HTTP_X_CSRF_TOKEN'] : '';
if (empty($csrf_token) || $csrf_token !== $_SESSION['csrf_token']) {
    echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
    exit;
}

// Check if project ID and action are provided
if (!isset($_POST['project_id']) || !is_numeric($_POST['project_id']) || !isset($_POST['action'])) {
    echo json_encode(['success' => false, 'message' => 'Missing or invalid parameters.']);
    exit;
}

$project_id = intval($_POST['project_id']);
$action = $_POST['action'];
$user_id = $_SESSION['user_id'];

try {
    // Check if the project exists
    $stmt = $conn->prepare("
        SELECT 1 FROM projects WHERE project_id = ?
    ");
    $stmt->execute([$project_id]);
    $project_exists = $stmt->fetch();
    
    if (!$project_exists) {
        echo json_encode(['success' => false, 'message' => 'Project does not exist.']);
        exit;
    }
    
    // Check if the status column exists in project_members
    $stmt = $conn->prepare("
        SELECT COUNT(*) as column_exists 
        FROM information_schema.COLUMNS 
        WHERE TABLE_SCHEMA = DATABASE() 
        AND TABLE_NAME = 'project_members' 
        AND COLUMN_NAME = 'status'
    ");
    $stmt->execute();
    $statusColumnExists = (bool)$stmt->fetch()['column_exists'];
    
    // Check if user is an active member of this project
    $query = "SELECT 1 FROM project_members WHERE project_id = ? AND user_id = ?";
    
    if ($statusColumnExists) {
        $query .= " AND status = 'active'";
    }
    
    $stmt = $conn->prepare($query);
    $stmt->execute([$project_id, $user_id]);
    $is_member = $stmt->fetch();
    
    if (!$is_member) {
        echo json_encode(['success' => false, 'message' => 'You are not an active member of this project.']);
        exit;
    }
    
    // Check if the favorite_projects table exists, if not create it
    $conn->exec("
        CREATE TABLE IF NOT EXISTS favorite_projects (
            user_id INT NOT NULL,
            project_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, project_id),
            FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
            FOREIGN KEY (project_id) REFERENCES projects(project_id) ON DELETE CASCADE
        )
    ");
    
    if ($action === 'add') {
        // Add to favorites
        $stmt = $conn->prepare("
            INSERT INTO favorite_projects (user_id, project_id)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE created_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([$user_id, $project_id]);
        
        echo json_encode(['success' => true, 'message' => 'Project added to favorites.']);
    } else if ($action === 'remove') {
        // Remove from favorites
        $stmt = $conn->prepare("
            DELETE FROM favorite_projects
            WHERE user_id = ? AND project_id = ?
        ");
        $stmt->execute([$user_id, $project_id]);
        
        echo json_encode(['success' => true, 'message' => 'Project removed from favorites.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid action.']);
    }
} catch (PDOException $e) {
    error_log("Error in favorite_project.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?> 