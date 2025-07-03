<?php
/**
 * Attachment Functions
 * 
 * This file contains functions for handling file attachments.
 */

/**
 * Upload a file attachment
 * 
 * @param array $file File data from $_FILES
 * @param int $taskId Task ID
 * @param int $userId User ID who uploaded the file
 * @param string $uploadDir Directory to store uploads
 * @return array Result array with success status and message/data
 */
function uploadAttachment($file, $taskId, $userId, $uploadDir = 'uploads/') {
    // Check for upload errors
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errorMessages = [
            UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the upload_max_filesize directive in php.ini',
            UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the MAX_FILE_SIZE directive in the HTML form',
            UPLOAD_ERR_PARTIAL => 'The uploaded file was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload'
        ];
        
        $errorMessage = isset($errorMessages[$file['error']]) ? 
            $errorMessages[$file['error']] : 'Unknown upload error';
        
        return [
            'success' => false,
            'message' => $errorMessage
        ];
    }
    
    // Create upload directory if it doesn't exist
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    // Generate a unique filename
    $originalName = basename($file['name']);
    $extension = pathinfo($originalName, PATHINFO_EXTENSION);
    $filename = uniqid() . '_' . time() . '.' . $extension;
    $filepath = $uploadDir . $filename;
    
    // Move the uploaded file
    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        return [
            'success' => false,
            'message' => 'Failed to move uploaded file'
        ];
    }
    
    // Get file size and type
    $filesize = filesize($filepath);
    $filetype = mime_content_type($filepath);
    
    return [
        'success' => true,
        'data' => [
            'filename' => $filename,
            'original_name' => $originalName,
            'filepath' => $filepath,
            'filesize' => $filesize,
            'filetype' => $filetype,
            'task_id' => $taskId,
            'user_id' => $userId
        ]
    ];
}

/**
 * Save attachment record to database
 * 
 * @param PDO $conn Database connection
 * @param array $attachmentData Attachment data
 * @return int|false The new attachment ID or false on failure
 */
function saveAttachment($conn, $attachmentData) {
    $stmt = $conn->prepare("
        INSERT INTO task_attachments (
            task_id, user_id, filename, original_name, 
            filepath, filesize, filetype, uploaded_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
    ");
    
    $success = $stmt->execute([
        $attachmentData['task_id'],
        $attachmentData['user_id'],
        $attachmentData['filename'],
        $attachmentData['original_name'],
        $attachmentData['filepath'],
        $attachmentData['filesize'],
        $attachmentData['filetype']
    ]);
    
    return $success ? $conn->lastInsertId() : false;
}

/**
 * Get attachments for a task
 * 
 * @param PDO $conn Database connection
 * @param int $taskId Task ID
 * @return array Array of attachments
 */
function getTaskAttachments($conn, $taskId) {
    $stmt = $conn->prepare("
        SELECT a.*, u.first_name, u.last_name
        FROM task_attachments a
        JOIN users u ON a.user_id = u.user_id
        WHERE a.task_id = ?
        ORDER BY a.uploaded_at DESC
    ");
    $stmt->execute([$taskId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Delete an attachment
 * 
 * @param PDO $conn Database connection
 * @param int $attachmentId Attachment ID
 * @param int $userId User ID (for security check)
 * @return bool Success status
 */
function deleteAttachment($conn, $attachmentId, $userId) {
    // First get the attachment details
    $stmt = $conn->prepare("
        SELECT * FROM task_attachments
        WHERE attachment_id = ?
    ");
    $stmt->execute([$attachmentId]);
    $attachment = $stmt->fetch();
    
    if (!$attachment) {
        return false;
    }
    
    // Check if user has permission to delete (either the uploader or project admin)
    if ($attachment['user_id'] != $userId) {
        // Check if user is project admin
        $stmt = $conn->prepare("
            SELECT pm.role
            FROM task_attachments a
            JOIN tasks t ON a.task_id = t.task_id
            JOIN project_members pm ON t.project_id = pm.project_id AND pm.user_id = ?
            WHERE a.attachment_id = ?
        ");
        $stmt->execute([$userId, $attachmentId]);
        $membership = $stmt->fetch();
        
        if (!$membership || $membership['role'] !== 'admin') {
            return false;
        }
    }
    
    // Delete the file
    if (file_exists($attachment['filepath'])) {
        unlink($attachment['filepath']);
    }
    
    // Delete the database record
    $stmt = $conn->prepare("
        DELETE FROM task_attachments
        WHERE attachment_id = ?
    ");
    $stmt->execute([$attachmentId]);
    
    return $stmt->rowCount() > 0;
}
?> 