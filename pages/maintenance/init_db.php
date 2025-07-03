<?php
/**
 * Database Initialization Script
 * This script creates all necessary tables for the TaskMaster application
 */

// Include configuration
require_once '../../config/database.php';

echo "TaskMaster Database Initialization\n";
echo "=================================\n\n";

try {
    // Check PDO drivers and connection
    $available_drivers = PDO::getAvailableDrivers();
    echo "Available PDO drivers: " . implode(', ', $available_drivers) . "\n";
    
    if (empty($available_drivers)) {
        echo "ERROR: No PDO drivers available. Please enable at least one PDO driver in PHP.\n";
        exit(1);
    }
    
    if (!in_array('mysql', $available_drivers)) {
        echo "WARNING: PDO MySQL driver not available. Using alternative connection method.\n";
    }
    
    // If we made it here, we should have a connection
    if (!isset($conn)) {
        echo "ERROR: Database connection failed. Please check your config/database.php file.\n";
        exit(1);
    }
    
    echo "Connected to database: $db_name\n\n";
    
    // Create tables if they don't exist
    
    // 1. Create users table
    if (!tableExists($conn, 'users')) {
        echo "Creating users table... ";
        $conn->exec("
            CREATE TABLE users (
                user_id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(50) NOT NULL UNIQUE,
                email VARCHAR(100) NOT NULL UNIQUE,
                password VARCHAR(255) NOT NULL,
                first_name VARCHAR(50) NOT NULL,
                last_name VARCHAR(50) NOT NULL,
                profile_image VARCHAR(255) DEFAULT NULL,
                bio TEXT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        echo "Done\n";
        
        // Add a default admin user
        echo "Adding default admin user... ";
        $hashedPassword = password_hash('admin123', PASSWORD_DEFAULT);
        $stmt = $conn->prepare("
            INSERT INTO users (username, email, password, first_name, last_name)
            VALUES ('admin', 'admin@taskmaster.com', ?, 'Admin', 'User')
        ");
        $stmt->execute([$hashedPassword]);
        echo "Done\n";
    } else {
        echo "Users table already exists.\n";
    }
    
    // 2. Create projects table
    if (!tableExists($conn, 'projects')) {
        echo "Creating projects table... ";
        $conn->exec("
            CREATE TABLE projects (
                project_id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                description TEXT DEFAULT NULL,
                owner_id INT NOT NULL,
                icon VARCHAR(255) DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (owner_id) REFERENCES users(user_id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        echo "Done\n";
        
        // Add a sample project
        echo "Adding sample project... ";
        $stmt = $conn->prepare("
            INSERT INTO projects (name, description, owner_id)
            VALUES ('Sample Project', 'This is a sample project', 1)
        ");
        $stmt->execute();
        echo "Done\n";
    } else {
        echo "Projects table already exists.\n";
    }
    
    // 3. Create project_members table
    if (!tableExists($conn, 'project_members')) {
        echo "Creating project_members table... ";
        $conn->exec("
            CREATE TABLE project_members (
                project_id INT NOT NULL,
                user_id INT NOT NULL,
                role ENUM('owner', 'admin', 'member') DEFAULT 'member',
                status ENUM('active', 'pending', 'rejected') DEFAULT 'active',
                joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (project_id, user_id),
                FOREIGN KEY (project_id) REFERENCES projects(project_id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        echo "Done\n";
        
        // Add admin as member of sample project
        echo "Adding admin as project owner... ";
        $stmt = $conn->prepare("
            INSERT INTO project_members (project_id, user_id, role)
            VALUES (1, 1, 'owner')
        ");
        $stmt->execute();
        echo "Done\n";
    } else {
        echo "Project members table already exists.\n";
    }
    
    // 4. Create tasks table
    if (!tableExists($conn, 'tasks')) {
        echo "Creating tasks table... ";
        $conn->exec("
            CREATE TABLE tasks (
                task_id INT AUTO_INCREMENT PRIMARY KEY,
                project_id INT NOT NULL,
                title VARCHAR(100) NOT NULL,
                description TEXT DEFAULT NULL,
                status ENUM('to_do', 'in_progress', 'review', 'completed') DEFAULT 'to_do',
                priority ENUM('low', 'medium', 'high') DEFAULT 'medium',
                due_date DATE DEFAULT NULL,
                created_by INT NOT NULL,
                assigned_to INT DEFAULT NULL,
                epic_id INT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (project_id) REFERENCES projects(project_id) ON DELETE CASCADE,
                FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE CASCADE,
                FOREIGN KEY (assigned_to) REFERENCES users(user_id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        echo "Done\n";
        
        // Add a sample task
        echo "Adding sample task... ";
        $stmt = $conn->prepare("
            INSERT INTO tasks (project_id, title, description, status, priority, created_by, assigned_to)
            VALUES (1, 'Sample Task', 'This is a sample task description', 'to_do', 'medium', 1, 1)
        ");
        $stmt->execute();
        echo "Done\n";
    } else {
        echo "Tasks table already exists.\n";
    }
    
    // 5. Create task_assignees table (for multiple assignees)
    if (!tableExists($conn, 'task_assignees')) {
        echo "Creating task_assignees table... ";
        $conn->exec("
            CREATE TABLE task_assignees (
                task_id INT NOT NULL,
                user_id INT NOT NULL,
                assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (task_id, user_id),
                FOREIGN KEY (task_id) REFERENCES tasks(task_id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        echo "Done\n";
        
        // Add sample task assignee
        echo "Adding sample task assignee... ";
        $stmt = $conn->prepare("
            INSERT INTO task_assignees (task_id, user_id)
            VALUES (1, 1)
        ");
        $stmt->execute();
        echo "Done\n";
    } else {
        echo "Task assignees table already exists.\n";
    }
    
    // 6. Create task_comments table
    if (!tableExists($conn, 'task_comments')) {
        echo "Creating task_comments table... ";
        $conn->exec("
            CREATE TABLE task_comments (
                comment_id INT AUTO_INCREMENT PRIMARY KEY,
                task_id INT NOT NULL,
                user_id INT NOT NULL,
                comment_text TEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (task_id) REFERENCES tasks(task_id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        echo "Done\n";
        
        // Add a sample comment
        echo "Adding sample comment... ";
        $stmt = $conn->prepare("
            INSERT INTO task_comments (task_id, user_id, comment_text)
            VALUES (1, 1, 'This is a sample comment on the task')
        ");
        $stmt->execute();
        echo "Done\n";
    } else {
        echo "Task comments table already exists.\n";
        
        // Check if comment_text column exists
        $stmt = $conn->prepare("SHOW COLUMNS FROM task_comments LIKE 'comment_text'");
        $stmt->execute();
        if ($stmt->rowCount() === 0) {
            // Add comment_text column if it doesn't exist
            echo "Adding comment_text column to task_comments table... ";
            try {
                $conn->exec("ALTER TABLE task_comments ADD COLUMN comment_text TEXT NOT NULL AFTER user_id");
                echo "Done\n";
            } catch (PDOException $e) {
                echo "Failed: " . $e->getMessage() . "\n";
            }
        }
    }
    
    // Create project_invitations table
    if (!tableExists($conn, 'project_invitations')) {
        echo "Creating project_invitations table... ";
        $conn->exec("
            CREATE TABLE project_invitations (
                invitation_id INT AUTO_INCREMENT PRIMARY KEY,
                project_id INT NOT NULL,
                inviter_id INT NOT NULL,
                invitee_email VARCHAR(100) NOT NULL,
                token VARCHAR(100) NOT NULL,
                status ENUM('pending', 'accepted', 'rejected') DEFAULT 'pending',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                expires_at DATETIME NOT NULL,
                FOREIGN KEY (project_id) REFERENCES projects(project_id) ON DELETE CASCADE,
                FOREIGN KEY (inviter_id) REFERENCES users(user_id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        echo "Done\n";
    } else {
        echo "Project invitations table already exists.\n";
    }
    
    echo "\nDatabase initialization complete!\n";
    echo "You can now use the TaskMaster application.\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
?> 