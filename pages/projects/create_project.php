<?php
require_once '../../includes/functions.php';
require_once '../../config/database.php';

// Check if user is logged in
if (!isLoggedIn()) {
    setMessage("You must be logged in to create a project.", "danger");
    redirect('../auth/login.php');
}

// Get user data
$user_id = $_SESSION['user_id'];
$user_name = (isset($_SESSION['first_name']) ? $_SESSION['first_name'] : '') . (isset($_SESSION['last_name']) ? ' ' . $_SESSION['last_name'] : '');

// Generate CSRF token if it doesn't exist
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Initialize variables
$project_name = '';
$project_type = '';
$invited_users = [];
$project_icon = '';
$upload_error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        setMessage("Invalid security token. Please try again.", "danger");
        redirect('create_project.php');
    }
    
    // Validate and sanitize input
    $project_name = sanitize($_POST['project_name']);
    $project_type = sanitize($_POST['project_type']);
    $invited_emails = isset($_POST['invite_people']) ? explode(',', $_POST['invite_people']) : [];

    // Validate project name
    if (empty($project_name)) {
        setMessage("Project name is required", "danger");
    } else {
        try {
            // Begin transaction
            $conn->beginTransaction();
            
            // Handle file upload for project icon
            $target_dir = "../../assets/images/project_icons/";
            
            // Create directory if it doesn't exist
            if (!file_exists($target_dir)) {
                mkdir($target_dir, 0777, true);
            }
            
            // Process file upload if present
            if (isset($_FILES["project_icon"]) && $_FILES["project_icon"]["error"] == 0) {
                $allowed_types = ["image/jpeg", "image/jpg", "image/png", "image/gif"];
                $max_size = 5 * 1024 * 1024; // 5MB
                
                // Validate file type and size
                if (in_array($_FILES["project_icon"]["type"], $allowed_types) && $_FILES["project_icon"]["size"] <= $max_size) {
                    // Generate unique filename
                    $file_extension = pathinfo($_FILES["project_icon"]["name"], PATHINFO_EXTENSION);
                    $new_filename = uniqid() . "." . $file_extension;
                    $target_file = $target_dir . $new_filename;
                    
                    // Upload file
                    if (move_uploaded_file($_FILES["project_icon"]["tmp_name"], $target_file)) {
                        // Store the relative path in the database (without the ../../)
                        $project_icon = "assets/images/project_icons/" . $new_filename;
                    } else {
                        $upload_error = "Failed to upload icon. Please try again.";
                    }
                } else {
                    $upload_error = "Invalid file. Please upload a JPG, PNG or GIF image under 5MB.";
                }
            }
            
            // Insert project into database
            $stmt = $conn->prepare("INSERT INTO projects (name, description, owner_id, icon, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())");
            $stmt->execute([$project_name, $project_type, $user_id, $project_icon]);
            $project_id = $conn->lastInsertId();
            
            // Add owner as project member with owner role
            $stmt = $conn->prepare("INSERT INTO project_members (project_id, user_id, role, joined_at) VALUES (?, ?, 'owner', NOW())");
            $stmt->execute([$project_id, $user_id]);
            
            // Process invitations
            foreach ($invited_emails as $email) {
                $email = trim(sanitize($email));
                if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    // Check if user exists
                    $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
                    $stmt->execute([$email]);
                    $invited_user = $stmt->fetch();
                    
                    if ($invited_user) {
                        // User exists, add as project member
                        $stmt = $conn->prepare("INSERT INTO project_members (project_id, user_id, role, joined_at) VALUES (?, ?, 'member', NOW())");
                        $stmt->execute([$project_id, $invited_user['user_id']]);
                        
                        // Create notification for invited user
                        $notification_message = $user_name . " added you to the project: " . $project_name;
                        $stmt = $conn->prepare("INSERT INTO notifications (user_id, type, message, related_id, created_at) VALUES (?, 'project_invite', ?, ?, NOW())");
                        $stmt->execute([$invited_user['user_id'], $notification_message, $project_id]);
                    } else {
                        // User doesn't exist, create invitation
                        $token = generateToken();
                        $expires = date('Y-m-d H:i:s', strtotime('+7 days'));
                        
                        $stmt = $conn->prepare("INSERT INTO project_invitations (project_id, inviter_id, invitee_email, token, status, created_at, expires_at) VALUES (?, ?, ?, ?, 'pending', NOW(), ?)");
                        $stmt->execute([$project_id, $user_id, $email, $token, $expires]);
                        
                        // In a real application, you would send an email invitation here
                        // sendInvitationEmail($email, $user_name, $project_name, $token);
                    }
                }
            }
            
            // Commit transaction
            $conn->commit();
            
            setMessage("Project created successfully!", "success");
            redirect('../projects/projects.php');
        } catch (PDOException $e) {
            // Roll back transaction on error
            $conn->rollBack();
            setMessage("Error creating project: " . $e->getMessage(), "danger");
        }
    }
}

// Page title
$page_title = "Create Project";
include '../../includes/header.php';
?>

<div class="d-flex">
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="logo-container">
            <img src="../../assets/images/logo.png" alt="TaskMaster Logo" class="logo">
            <h1>TaskMaster</h1>
        </div>
        <nav>
            <ul>
                <li>
                    <a href="../core/dashboard.php"><i class="fas fa-home"></i> Home</a>
                </li>
                <li class="active">
                    <a href="../projects/projects.php"><i class="fas fa-cube"></i> Projects</a>
                </li>
                <li>
                    <a href="../tasks/tasks.php"><i class="fas fa-clipboard-list"></i> Tasks</a>
                </li>
                <li>
                    <a href="../users/profile.php"><i class="fas fa-user"></i> Profile</a>
                </li>
                <li>
                    <a href="../users/connections.php"><i class="fas fa-users"></i> Connections</a>
                </li>
            </ul>
        </nav>
        <div class="sidebar-footer">
            <a href="../core/settings.php"><i class="fas fa-cog"></i> Settings</a>
            <a href="#" id="logout-btn"><i class="fas fa-sign-out-alt"></i> Log Out</a>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Top Navigation -->
       

        <!-- Create Project Content -->
        <div class="dashboard-container">
            <h1 class="project-title">Project Information</h1>

            <?php 
            // Display any messages
            displayMessage(); 
            
            // Display upload errors
            if (!empty($upload_error)) {
                echo '<div class="alert alert-danger">' . $upload_error . '</div>';
            }
            ?>

            <!-- Create Project Form -->
            <form class="create-project-form" method="POST" action="create_project.php" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                
                <!-- Project Icon Upload -->
                <div class="project-icon-section">
                    <div class="project-icon-container">
                        <div class="project-icon-preview" id="icon-preview">
                            <i class="far fa-image"></i>
                        </div>
                    </div>
                    <label for="project_icon" class="btn btn-light upload-icon-btn">Upload icon</label>
                    <input type="file" id="project_icon" name="project_icon" accept="image/*" style="display: none;">
                </div>

                <!-- Project Details -->
                <div class="form-group mb-4">
                    <label for="project_name" class="form-label">Project Name</label>
                    <input type="text" class="form-control" id="project_name" name="project_name" value="<?php echo htmlspecialchars($project_name); ?>" required>
                </div>

                <div class="form-group mb-4">
                    <label for="project_type" class="form-label">Project Type</label>
                    <input type="text" class="form-control" id="project_type" name="project_type" placeholder="eg. Software Project" value="<?php echo htmlspecialchars($project_type); ?>">
                </div>

                <div class="form-group mb-4">
                    <label for="invite_people" class="form-label">Invite People to Project</label>
                    <div class="input-group">
                        <input type="text" class="form-control" id="invite_people" name="invite_people" placeholder="Type a name or email address">
                        <span class="input-group-text search-icon"><i class="fas fa-search"></i></span>
                    </div>
                    <div id="invite-list" class="mt-2"></div>
                </div>

                <!-- Form Actions -->
                <div class="form-actions">
                    <a href="../projects/projects.php" class="btn btn-link cancel-btn">Cancel</a>
                    <button type="submit" class="btn btn-primary create-btn">Create</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Logout Confirmation Modal -->
<div class="modal fade" id="logoutModal" tabindex="-1" aria-labelledby="logoutModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="logoutModalLabel">Confirm Logout</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                Are you sure you want to log out?
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <a href="logout.php" class="btn btn-primary">Logout</a>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // File preview functionality
        const projectIcon = document.getElementById('project_icon');
        const iconPreview = document.getElementById('icon-preview');
        
        projectIcon.addEventListener('change', function() {
            const file = this.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    iconPreview.innerHTML = `<img src="${e.target.result}" alt="Project Icon" class="preview-image">`;
                }
                reader.readAsDataURL(file);
            } else {
                iconPreview.innerHTML = '<i class="far fa-image"></i>';
            }
        });
        
        // User invitation functionality
        const inviteInput = document.getElementById('invite_people');
        const inviteList = document.getElementById('invite-list');
        const invitedUsers = [];
        
        inviteInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ',') {
                e.preventDefault();
                
                const email = this.value.trim();
                if (email && !invitedUsers.includes(email)) {
                    // Add to invited users list
                    invitedUsers.push(email);
                    
                    // Create chip for user
                    const chip = document.createElement('div');
                    chip.className = 'user-chip';
                    chip.innerHTML = `
                        <span>${email}</span>
                        <button type="button" class="remove-user" data-email="${email}">&times;</button>
                    `;
                    inviteList.appendChild(chip);
                    
                    // Update hidden input with all emails
                    document.getElementById('invite_people').value = invitedUsers.join(',');
                    
                    // Clear input
                    this.value = '';
                }
            }
        });
        
        // Remove invited user
        inviteList.addEventListener('click', function(e) {
            if (e.target.classList.contains('remove-user')) {
                const email = e.target.dataset.email;
                
                // Remove from array
                const index = invitedUsers.indexOf(email);
                if (index !== -1) {
                    invitedUsers.splice(index, 1);
                }
                
                // Remove chip
                e.target.parentElement.remove();
                
                // Update hidden input
                document.getElementById('invite_people').value = invitedUsers.join(',');
            }
        });

        // Logout confirmation
        document.getElementById('logout-btn').addEventListener('click', function(e) {
            e.preventDefault();
            var logoutModal = new bootstrap.Modal(document.getElementById('logoutModal'));
            logoutModal.show();
        });
    });
</script>

<style>
    .project-icon-preview {
        width: 100px;
        height: 100px;
        border-radius: 50%;
        background-color: #f0f0f0;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 40px;
        color: #aaa;
        margin-bottom: 10px;
        overflow: hidden;
    }
    
    .preview-image {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    
    .user-chip {
        display: inline-flex;
        align-items: center;
        background-color: #e9ecef;
        border-radius: 16px;
        padding: 5px 10px;
        margin-right: 8px;
        margin-bottom: 8px;
        font-size: 14px;
    }
    
    .remove-user {
        background: none;
        border: none;
        color: #666;
        cursor: pointer;
        font-size: 16px;
        margin-left: 5px;
        padding: 0 5px;
    }
    
    .form-actions {
        display: flex;
        justify-content: flex-end;
        margin-top: 30px;
    }
    
    .cancel-btn {
        margin-right: 10px;
    }
    
    .create-btn {
        min-width: 100px;
    }
</style>

<?php include '../../includes/footer.php'; ?> 