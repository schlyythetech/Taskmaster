<?php
require_once '../../includes/functions.php';
require_once '../../config/database.php';

// Check if user is logged in
if (!isLoggedIn()) {
    setMessage("You must be logged in to update a project.", "danger");
    redirect('../auth/login.php');
}

// Check if form was submitted
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    setMessage("Invalid request method.", "danger");
    redirect('../projects/projects.php');
}

// Validate CSRF token
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    setMessage("Invalid security token. Please try again.", "danger");
    redirect('../projects/projects.php');
}

// Check if project ID is provided
if (!isset($_POST['project_id']) || !is_numeric($_POST['project_id'])) {
    setMessage("Invalid project ID.", "danger");
    redirect('../projects/projects.php');
}

$project_id = $_POST['project_id'];
$user_id = $_SESSION['user_id'];

try {
    // Check if user has permission to edit this project
    $stmt = $conn->prepare("
        SELECT pm.role, p.owner_id 
        FROM project_members pm
        JOIN projects p ON pm.project_id = p.project_id
        WHERE pm.project_id = ? AND pm.user_id = ?
    ");
    $stmt->execute([$project_id, $user_id]);
    $membership = $stmt->fetch();
    
    if (!$membership) {
        setMessage("You do not have permission to edit this project.", "danger");
        redirect('../projects/projects.php');
    }
    
    // Only allow owner or admin to edit project
    if ($membership['role'] !== 'owner' && $membership['role'] !== 'admin') {
        setMessage("You do not have permission to edit this project.", "danger");
        redirect('view_project.php?id=' . $project_id);
    }
    
    // Get form data
    $name = trim($_POST['name']);
    $description = trim($_POST['description']);
    
    // Validate project name
    if (empty($name)) {
        setMessage("Project name is required.", "danger");
        redirect('view_project.php?id=' . $project_id);
    }
    
    // Begin transaction
    $conn->beginTransaction();
    
    // Handle file upload for project icon
    $icon_path = null;
    if (isset($_FILES['project_icon']) && $_FILES['project_icon']['error'] == 0) {
        $allowed_types = ["image/jpeg", "image/jpg", "image/png", "image/gif"];
        $max_size = 5 * 1024 * 1024; // 5MB
        
        // Validate file type and size
        if (in_array($_FILES['project_icon']['type'], $allowed_types) && $_FILES['project_icon']['size'] <= $max_size) {
            $target_dir = "../../assets/images/project_icons/";
            
            // Create directory if it doesn't exist
            if (!file_exists($target_dir)) {
                mkdir($target_dir, 0777, true);
            }
            
            // Generate unique filename
            $file_extension = pathinfo($_FILES['project_icon']['name'], PATHINFO_EXTENSION);
            $new_filename = uniqid() . "." . $file_extension;
            $target_file = $target_dir . $new_filename;
            
            // Upload file
            if (move_uploaded_file($_FILES['project_icon']['tmp_name'], $target_file)) {
                // Store relative path in the database (without the ../../)
                $icon_path = "assets/images/project_icons/" . $new_filename;
                
                // Update project icon in database
                $stmt = $conn->prepare("UPDATE projects SET icon = ? WHERE project_id = ?");
                $stmt->execute([$icon_path, $project_id]);
            } else {
                setMessage("Failed to upload icon. Please try again.", "danger");
                redirect('view_project.php?id=' . $project_id);
            }
        } else {
            setMessage("Invalid file. Please upload a JPG, PNG or GIF image under 5MB.", "danger");
            redirect('view_project.php?id=' . $project_id);
        }
    }
    
    // Update project in database
    $stmt = $conn->prepare("UPDATE projects SET name = ?, description = ? WHERE project_id = ?");
    $stmt->execute([$name, $description, $project_id]);
    
    // Commit transaction
    $conn->commit();
    
    setMessage("Project updated successfully!", "success");
    redirect('view_project.php?id=' . $project_id);
} catch (PDOException $e) {
    // Roll back transaction on error
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    setMessage("Error updating project: " . $e->getMessage(), "danger");
    redirect('view_project.php?id=' . $project_id);
}
?> 