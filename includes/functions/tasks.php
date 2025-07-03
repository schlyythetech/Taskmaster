<?php
/**
 * Task Functions
 * 
 * This file contains functions for task management.
 */

/**
 * Get task details
 * 
 * @param PDO $conn Database connection
 * @param int $taskId Task ID
 * @return array|false Task details or false if not found
 */
function getTask($conn, $taskId) {
    $stmt = $conn->prepare("
        SELECT * FROM tasks
        WHERE task_id = ?
    ");
    $stmt->execute([$taskId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Get tasks for a project
 * 
 * @param PDO $conn Database connection
 * @param int $projectId Project ID
 * @param string|null $status Filter by status (optional)
 * @return array Array of tasks
 */
function getProjectTasks($conn, $projectId, $status = null) {
    $query = "
        SELECT t.*, u.first_name, u.last_name, u.profile_image
        FROM tasks t
        LEFT JOIN users u ON t.assigned_to = u.user_id
        WHERE t.project_id = ?
    ";
    
    $params = [$projectId];
    
    if ($status !== null) {
        $query .= " AND t.status = ?";
        $params[] = $status;
    }
    
    $query .= " ORDER BY t.priority DESC, t.due_date ASC";
    
    $stmt = $conn->prepare($query);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Create a new task
 * 
 * @param PDO $conn Database connection
 * @param array $taskData Task data (title, description, project_id, etc.)
 * @return int|false The new task ID or false on failure
 */
function createTask($conn, $taskData) {
    $requiredFields = ['title', 'project_id', 'created_by'];
    foreach ($requiredFields as $field) {
        if (!isset($taskData[$field])) {
            return false;
        }
    }
    
    $fields = [
        'title', 'description', 'project_id', 'status', 'priority', 
        'due_date', 'assigned_to', 'created_by', 'epic_id'
    ];
    
    $columns = [];
    $placeholders = [];
    $values = [];
    
    foreach ($fields as $field) {
        if (isset($taskData[$field])) {
            $columns[] = $field;
            $placeholders[] = '?';
            $values[] = $taskData[$field];
        }
    }
    
    $columns[] = 'created_at';
    $placeholders[] = 'CURRENT_TIMESTAMP';
    
    $query = "
        INSERT INTO tasks (" . implode(', ', $columns) . ")
        VALUES (" . implode(', ', $placeholders) . ")
    ";
    
    $stmt = $conn->prepare($query);
    $success = $stmt->execute($values);
    
    return $success ? $conn->lastInsertId() : false;
}

/**
 * Update task status
 * 
 * @param PDO $conn Database connection
 * @param int $taskId Task ID
 * @param string $status New status
 * @param int $updatedBy User ID who updated the task
 * @return bool Success status
 */
function updateTaskStatus($conn, $taskId, $status, $updatedBy) {
    $validStatuses = ['todo', 'in_progress', 'review', 'done'];
    
    if (!in_array($status, $validStatuses)) {
        return false;
    }
    
    $stmt = $conn->prepare("
        UPDATE tasks
        SET status = ?, updated_at = CURRENT_TIMESTAMP, updated_by = ?
        WHERE task_id = ?
    ");
    $stmt->execute([$status, $updatedBy, $taskId]);
    
    return $stmt->rowCount() > 0;
}

/**
 * Update task details
 * 
 * @param PDO $conn Database connection
 * @param int $taskId Task ID
 * @param array $taskData Task data to update
 * @param int $updatedBy User ID who updated the task
 * @return bool Success status
 */
function updateTask($conn, $taskId, $taskData, $updatedBy) {
    $allowedFields = [
        'title', 'description', 'status', 'priority', 
        'due_date', 'assigned_to', 'epic_id'
    ];
    
    $updates = [];
    $values = [];
    
    foreach ($allowedFields as $field) {
        if (isset($taskData[$field])) {
            $updates[] = "$field = ?";
            $values[] = $taskData[$field];
        }
    }
    
    if (empty($updates)) {
        return false;
    }
    
    $updates[] = "updated_at = CURRENT_TIMESTAMP";
    $updates[] = "updated_by = ?";
    $values[] = $updatedBy;
    
    $values[] = $taskId;
    
    $query = "
        UPDATE tasks
        SET " . implode(', ', $updates) . "
        WHERE task_id = ?
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->execute($values);
    
    return $stmt->rowCount() > 0;
}

/**
 * Delete a task
 * 
 * @param PDO $conn Database connection
 * @param int $taskId Task ID
 * @return bool Success status
 */
function deleteTask($conn, $taskId) {
    $stmt = $conn->prepare("
        DELETE FROM tasks
        WHERE task_id = ?
    ");
    $stmt->execute([$taskId]);
    
    return $stmt->rowCount() > 0;
}

/**
 * Get task comments
 * 
 * @param PDO $conn Database connection
 * @param int $taskId Task ID
 * @return array Array of comments
 */
function getTaskComments($conn, $taskId) {
    $stmt = $conn->prepare("
        SELECT c.*, u.first_name, u.last_name, u.profile_image
        FROM task_comments c
        JOIN users u ON c.user_id = u.user_id
        WHERE c.task_id = ?
        ORDER BY c.created_at ASC
    ");
    $stmt->execute([$taskId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Add comment to task
 * 
 * @param PDO $conn Database connection
 * @param int $taskId Task ID
 * @param int $userId User ID
 * @param string $comment Comment text
 * @return int|false The new comment ID or false on failure
 */
function addTaskComment($conn, $taskId, $userId, $comment) {
    $stmt = $conn->prepare("
        INSERT INTO task_comments (task_id, user_id, comment, created_at)
        VALUES (?, ?, ?, CURRENT_TIMESTAMP)
    ");
    $success = $stmt->execute([$taskId, $userId, $comment]);
    
    return $success ? $conn->lastInsertId() : false;
}
?> 