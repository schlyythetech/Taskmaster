<?php
/**
 * Project Functions
 * 
 * This file contains functions for project management.
 */

/**
 * Get project details
 * 
 * @param PDO $conn Database connection
 * @param int $projectId Project ID
 * @return array|false Project details or false if not found
 */
function getProject($conn, $projectId) {
    $stmt = $conn->prepare("
        SELECT * FROM projects
        WHERE project_id = ?
    ");
    $stmt->execute([$projectId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Check if user is a member of a project
 * 
 * @param PDO $conn Database connection
 * @param int $projectId Project ID
 * @param int $userId User ID
 * @return bool True if user is a member, false otherwise
 */
function isProjectMember($conn, $projectId, $userId) {
    $stmt = $conn->prepare("
        SELECT 1 FROM project_members
        WHERE project_id = ? AND user_id = ?
    ");
    $stmt->execute([$projectId, $userId]);
    return $stmt->fetch() !== false;
}

/**
 * Check if user is a project admin
 * 
 * @param PDO $conn Database connection
 * @param int $projectId Project ID
 * @param int $userId User ID
 * @return bool True if user is an admin, false otherwise
 */
function isProjectAdmin($conn, $projectId, $userId) {
    $stmt = $conn->prepare("
        SELECT 1 FROM project_members
        WHERE project_id = ? AND user_id = ? AND role = 'admin'
    ");
    $stmt->execute([$projectId, $userId]);
    return $stmt->fetch() !== false;
}

/**
 * Get project members
 * 
 * @param PDO $conn Database connection
 * @param int $projectId Project ID
 * @return array Array of project members
 */
function getProjectMembers($conn, $projectId) {
    $stmt = $conn->prepare("
        SELECT pm.*, u.first_name, u.last_name, u.email, u.profile_image
        FROM project_members pm
        JOIN users u ON pm.user_id = u.user_id
        WHERE pm.project_id = ?
        ORDER BY pm.role = 'admin' DESC, u.first_name, u.last_name
    ");
    $stmt->execute([$projectId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Add user to project
 * 
 * @param PDO $conn Database connection
 * @param int $projectId Project ID
 * @param int $userId User ID
 * @param string $role User role in project (default: 'member')
 * @return bool Success status
 */
function addProjectMember($conn, $projectId, $userId, $role = 'member') {
    // Check if user is already a member
    if (isProjectMember($conn, $projectId, $userId)) {
        return false;
    }
    
    $stmt = $conn->prepare("
        INSERT INTO project_members (project_id, user_id, role, joined_at)
        VALUES (?, ?, ?, CURRENT_TIMESTAMP)
    ");
    $stmt->execute([$projectId, $userId, $role]);
    
    return $stmt->rowCount() > 0;
}

/**
 * Remove user from project
 * 
 * @param PDO $conn Database connection
 * @param int $projectId Project ID
 * @param int $userId User ID
 * @return bool Success status
 */
function removeProjectMember($conn, $projectId, $userId) {
    $stmt = $conn->prepare("
        DELETE FROM project_members
        WHERE project_id = ? AND user_id = ?
    ");
    $stmt->execute([$projectId, $userId]);
    
    return $stmt->rowCount() > 0;
}

/**
 * Update project member role
 * 
 * @param PDO $conn Database connection
 * @param int $projectId Project ID
 * @param int $userId User ID
 * @param string $newRole New role ('admin' or 'member')
 * @return bool Success status
 */
function updateProjectMemberRole($conn, $projectId, $userId, $newRole) {
    if (!in_array($newRole, ['admin', 'member'])) {
        return false;
    }
    
    $stmt = $conn->prepare("
        UPDATE project_members
        SET role = ?
        WHERE project_id = ? AND user_id = ?
    ");
    $stmt->execute([$newRole, $projectId, $userId]);
    
    return $stmt->rowCount() > 0;
}
?> 