<?php
require_once '../../includes/functions.php';
require_once '../../config/database.php';

// Check if user is logged in
if (!isLoggedIn()) {
    setMessage("You must be logged in to delete a project.", "danger");
    redirect('../auth/login.php');
}

// Check if form was submitted and CSRF token is valid
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['csrf_token']) && validateCSRFToken($_POST['csrf_token'])) {
    // Get project ID
    $project_id = isset($_POST['project_id']) ? intval($_POST['project_id']) : 0;
    $user_id = $_SESSION['user_id'];
    
    if ($project_id <= 0) {
        setMessage("Invalid project ID.", "danger");
        redirect('../projects/projects.php');
    }
    
    try {
        // Begin transaction
        $conn->beginTransaction();
        
        // Check if user is the project owner
        $stmt = $conn->prepare("
            SELECT p.*, pm.role
            FROM projects p
            JOIN project_members pm ON p.project_id = pm.project_id
            WHERE p.project_id = ? AND pm.user_id = ?
        ");
        $stmt->execute([$project_id, $user_id]);
        $project = $stmt->fetch();
        
        if (!$project) {
            setMessage("Project not found.", "danger");
            redirect('../projects/projects.php');
        }
        
        // Verify that the user is the project owner
        if ($project['owner_id'] != $user_id && $project['role'] !== 'owner') {
            setMessage("You do not have permission to delete this project.", "danger");
            redirect('../projects/projects.php');
        }
        
        // Get project name for confirmation message
        $project_name = $project['name'];
        
        // Delete all related records in the database
        
        // 1. Delete tasks (if tasks table exists)
        $stmt = $conn->prepare("
            SELECT COUNT(*) as table_exists 
            FROM information_schema.tables 
            WHERE table_schema = DATABASE() 
            AND table_name = 'tasks'
        ");
        $stmt->execute();
        $tasksTableExists = (bool)$stmt->fetch()['table_exists'];
        
        if ($tasksTableExists) {
            // Delete task comments if they exist
            $stmt = $conn->prepare("
                SELECT COUNT(*) as table_exists 
                FROM information_schema.tables 
                WHERE table_schema = DATABASE() 
                AND table_name = 'task_comments'
            ");
            $stmt->execute();
            $taskCommentsTableExists = (bool)$stmt->fetch()['table_exists'];
            
            if ($taskCommentsTableExists) {
                $stmt = $conn->prepare("
                    DELETE tc FROM task_comments tc
                    JOIN tasks t ON tc.task_id = t.task_id
                    WHERE t.project_id = ?
                ");
                $stmt->execute([$project_id]);
            }
            
            // Delete task attachments if they exist
            $stmt = $conn->prepare("
                SELECT COUNT(*) as table_exists 
                FROM information_schema.tables 
                WHERE table_schema = DATABASE() 
                AND table_name = 'task_attachments'
            ");
            $stmt->execute();
            $taskAttachmentsTableExists = (bool)$stmt->fetch()['table_exists'];
            
            if ($taskAttachmentsTableExists) {
                $stmt = $conn->prepare("
                    DELETE ta FROM task_attachments ta
                    JOIN tasks t ON ta.task_id = t.task_id
                    WHERE t.project_id = ?
                ");
                $stmt->execute([$project_id]);
            }
            
            // Delete tasks
            $stmt = $conn->prepare("DELETE FROM tasks WHERE project_id = ?");
            $stmt->execute([$project_id]);
        }
        
        // 2. Delete project members
        $stmt = $conn->prepare("DELETE FROM project_members WHERE project_id = ?");
        $stmt->execute([$project_id]);
        
        // 3. Delete from favorite projects
        $stmt = $conn->prepare("
            SELECT COUNT(*) as table_exists 
            FROM information_schema.tables 
            WHERE table_schema = DATABASE() 
            AND table_name = 'favorite_projects'
        ");
        $stmt->execute();
        $favoriteProjectsTableExists = (bool)$stmt->fetch()['table_exists'];
        
        if ($favoriteProjectsTableExists) {
            $stmt = $conn->prepare("DELETE FROM favorite_projects WHERE project_id = ?");
            $stmt->execute([$project_id]);
        }
        
        // 4. Delete related notifications
        $stmt = $conn->prepare("
            SELECT COUNT(*) as column_exists 
            FROM information_schema.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = 'notifications' 
            AND COLUMN_NAME = 'related_id'
        ");
        $stmt->execute();
        $relatedIdColumnExists = (bool)$stmt->fetch()['column_exists'];
        
        if ($relatedIdColumnExists) {
            $stmt = $conn->prepare("DELETE FROM notifications WHERE related_id = ?");
            $stmt->execute([$project_id]);
        }
        
        // 5. Finally, delete the project
        $stmt = $conn->prepare("DELETE FROM projects WHERE project_id = ?");
        $stmt->execute([$project_id]);
        
        // Commit transaction
        $conn->commit();
        
        setMessage("Project \"" . htmlspecialchars($project_name) . "\" has been deleted successfully.", "success");
    } catch (PDOException $e) {
        // Roll back transaction on error
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        
        error_log("Error deleting project: " . $e->getMessage());
        setMessage("Error deleting project: " . $e->getMessage(), "danger");
    }
} else {
    setMessage("Invalid request or CSRF token.", "danger");
}

redirect('../projects/projects.php'); 