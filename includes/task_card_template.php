<?php
/**
 * Task Card Template
 * 
 * This template displays a task card with support for multiple assignees
 * Enhanced UI design with modern styling while maintaining the same format
 */
?>
<div class="task-card" data-task-id="<?php echo $task['task_id']; ?>">
    <div class="task-card-content">
        <?php if (!empty($task['epic_title'])): ?>
            <div class="epic-tag"><?php echo htmlspecialchars($task['epic_title']); ?></div>
        <?php endif; ?>
        
        <h6 class="task-title"><?php echo htmlspecialchars($task['title']); ?></h6>
        
        <div class="task-meta">
            <?php if (!empty($task['due_date'])): ?>
                <span class="due-date">
                    <i class="far fa-calendar-alt me-1"></i>
                    <?php echo date('M j', strtotime($task['due_date'])); ?>
                </span>
            <?php endif; ?>
            <span class="priority-badge priority-<?php echo $task['priority']; ?>">
                <?php 
                    $priorityIcon = 'circle';
                    if ($task['priority'] === 'high') $priorityIcon = 'arrow-up';
                    elseif ($task['priority'] === 'low') $priorityIcon = 'arrow-down';
                ?>
                <i class="fas fa-<?php echo $priorityIcon; ?> me-1"></i>
                <?php echo ucfirst($task['priority']); ?>
            </span>
        </div>
        
        <div class="task-footer">
            <div class="task-assignees">
                <?php 
                // Check if task has multiple assignees
                if (!empty($task['assignees'])): 
                    $assigneeCount = count($task['assignees']);
                    $displayCount = min(2, $assigneeCount); // Display max 2 avatars
                    
                    // Display avatars
                    for ($i = 0; $i < $displayCount; $i++): 
                        $assignee = $task['assignees'][$i];
                ?>
                    <div class="assignee-avatar" title="<?php echo htmlspecialchars($assignee['first_name'] . ' ' . $assignee['last_name']); ?>">
                        <?php if (!empty($assignee['profile_image'])): ?>
                            <?php 
                            // Ensure the profile image path is correct
                            $profileImagePath = $assignee['profile_image'];
                            // Add ../../ prefix if the path doesn't start with http or ../../
                            if (!preg_match('/^(http|https):\/\//', $profileImagePath) && !preg_match('/^\.\.\/\.\.\//', $profileImagePath)) {
                                $profileImagePath = '../../' . $profileImagePath;
                            }
                            ?>
                            <img src="<?php echo htmlspecialchars($profileImagePath); ?>" alt="<?php echo htmlspecialchars($assignee['first_name']); ?>" onerror="this.onerror=null; this.parentNode.innerHTML='<div class=\'default-avatar\'><?php echo substr($assignee['first_name'], 0, 1); ?></div>'">
                        <?php else: ?>
                            <div class="default-avatar"><?php echo substr($assignee['first_name'], 0, 1); ?></div>
                        <?php endif; ?>
                    </div>
                <?php 
                    endfor;
                    
                    // Show +X if more than 2 assignees
                    if ($assigneeCount > 2): 
                ?>
                    <div class="assignee-avatar more-assignees" title="<?php echo $assigneeCount - 2; ?> more">+<?php echo $assigneeCount - 2; ?></div>
                <?php 
                    endif;
                elseif (!empty($task['assigned_to'])): // Fallback to legacy assignee
                ?>
                    <div class="assignee-avatar">
                        <?php if (!empty($task['assigned_profile_image'])): ?>
                            <?php 
                            // Ensure the profile image path is correct
                            $profileImagePath = $task['assigned_profile_image'];
                            // Add ../../ prefix if the path doesn't start with http or ../../
                            if (!preg_match('/^(http|https):\/\//', $profileImagePath) && !preg_match('/^\.\.\/\.\.\//', $profileImagePath)) {
                                $profileImagePath = '../../' . $profileImagePath;
                            }
                            ?>
                            <img src="<?php echo htmlspecialchars($profileImagePath); ?>" alt="Assignee" onerror="this.onerror=null; this.parentNode.innerHTML='<div class=\'default-avatar\'><?php echo substr($task['assigned_first_name'], 0, 1); ?></div>'">
                        <?php else: ?>
                            <div class="default-avatar"><?php echo substr($task['assigned_first_name'], 0, 1); ?></div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="task-actions">
                <?php if (isset($task['created_by']) && isset($_SESSION['user_id']) && $task['created_by'] == $_SESSION['user_id']): ?>
                <button class="btn btn-sm btn-danger delete-task-btn me-1" data-task-id="<?php echo $task['task_id']; ?>" title="Delete task">
                    <i class="fas fa-trash-alt"></i>
                </button>
                <?php endif; ?>
                <div class="dropdown">
                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Change status">
                        <i class="fas fa-ellipsis-v"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end status-dropdown">
                        <li><a class="dropdown-item status-option" href="#" data-status="to_do" data-task-id="<?php echo $task['task_id']; ?>"><i class="fas fa-circle text-secondary me-2"></i>To Do</a></li>
                        <li><a class="dropdown-item status-option" href="#" data-status="in_progress" data-task-id="<?php echo $task['task_id']; ?>"><i class="fas fa-circle text-primary me-2"></i>In Progress</a></li>
                        <li><a class="dropdown-item status-option" href="#" data-status="review" data-task-id="<?php echo $task['task_id']; ?>"><i class="fas fa-circle text-warning me-2"></i>Review</a></li>
                        <li><a class="dropdown-item status-option" href="#" data-status="completed" data-task-id="<?php echo $task['task_id']; ?>"><i class="fas fa-circle text-success me-2"></i>Completed</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Status indicator at bottom of card -->
    <div class="task-status-indicator">
        <?php
        $statusIcon = 'circle';
        $statusClass = 'text-secondary';
        $statusText = 'To Do';
        
        if ($task['status'] === 'in_progress') {
            $statusClass = 'text-primary';
            $statusText = 'In Progress';
        } elseif ($task['status'] === 'review') {
            $statusClass = 'text-warning';
            $statusText = 'Review';
        } elseif ($task['status'] === 'completed') {
            $statusClass = 'text-success';
            $statusText = 'Completed';
        }
        ?>
        <div class="status-dot <?php echo $statusClass; ?>"></div>
        <span class="status-text"><?php echo $statusText; ?></span>
    </div>
</div>