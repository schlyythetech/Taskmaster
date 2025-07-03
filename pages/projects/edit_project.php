<?php
require_once '../../includes/functions.php';

// Check if user is logged in
if (!isLoggedIn()) {
    setMessage("You must be logged in to edit a project.", "danger");
    redirect('../auth/login.php');
}

// Check if project ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    setMessage("Invalid project ID.", "danger");
    redirect('../projects/projects.php');
}

$project_id = $_GET['id'];

// Redirect to project view page with edit=true parameter
redirect('view_project.php?id=' . $project_id . '&edit=true');
?> 