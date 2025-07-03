<?php
/**
 * Enhanced Floating Notification System
 * 
 * This file provides a floating notification system for the TaskMaster application
 * with a design that matches the provided mockup.
 */

// Get the current user ID if logged in
$user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0;

// Only show notifications for logged in users
if ($user_id):
?>

<!-- Floating Notification System -->
<div class="notification-system">
    <!-- Notification Bell with Badge -->
    <div class="notification-bell-wrapper">
        <button class="notification-bell-btn" id="notificationBellBtn" aria-label="Notifications">
            <i class="fas fa-bell"></i>
            <span class="notification-count" id="notificationCount">0</span>
        </button>
    </div>
    
    <!-- Notification Panel -->
    <div class="notification-panel" id="notificationPanel">
        <div class="notification-header">
            <h3>Notifications</h3>
            <button class="btn-close-panel" id="closeNotificationBtn" title="Close">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="notification-list" id="notificationList">
            <div class="notification-loading">
                <div class="spinner-border" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* Notification System Styles */
.notification-system {
    position: fixed;
    top: 15px;
    right: 20px;
    z-index: 1050;
}

/* Bell Button Styles */
.notification-bell-wrapper {
    position: relative;
}

.notification-bell-btn {
    background: none;
    border: none;
    color: #495057;
    font-size: 1.2rem;
    cursor: pointer;
    padding: 8px;
    border-radius: 50%;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
}

.notification-bell-btn:hover {
    background-color: rgba(0, 0, 0, 0.05);
    color: #007bff;
}

.notification-count {
    position: absolute;
    top: 0;
    right: 0;
    background-color: #dc3545;
    color: white;
    border-radius: 50%;
    min-width: 18px;
    height: 18px;
    font-size: 0.7rem;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0 4px;
    transform: translate(25%, -25%);
}

/* Notification Panel Styles */
.notification-panel {
    position: fixed;
    top: 60px;
    right: 20px;
    width: 350px;
    max-height: 500px;
    background-color: white;
    border-radius: 8px;
    box-shadow: 0 5px 20px rgba(0, 0, 0, 0.15);
    display: flex;
    flex-direction: column;
    transform: translateY(-20px);
    opacity: 0;
    visibility: hidden;
    transition: all 0.3s cubic-bezier(0.68, -0.55, 0.27, 1.55);
    z-index: 1050;
    overflow: hidden;
}

.notification-panel.show {
    transform: translateY(0);
    opacity: 1;
    visibility: visible;
}

.notification-header {
    padding: 15px;
    border-bottom: 1px solid #e9ecef;
    display: flex;
    justify-content: space-between;
    align-items: center;
    background-color: #f8f9fa;
}

.notification-header h3 {
    margin: 0;
    font-size: 1.1rem;
    font-weight: 600;
}

.btn-close-panel {
    background: none;
    border: none;
    font-size: 0.9rem;
    color: #6c757d;
    cursor: pointer;
    padding: 5px;
    border-radius: 4px;
    transition: all 0.2s ease;
}

.btn-close-panel:hover {
    background-color: rgba(0, 0, 0, 0.05);
    color: #007bff;
}

.notification-list {
    flex: 1;
    overflow-y: auto;
    max-height: 400px;
    padding: 0;
    background-color: #f5f5f5;
}

.notification-loading {
    display: flex;
    justify-content: center;
    align-items: center;
    height: 100px;
}

.notification-item {
    padding: 15px;
    background-color: white;
    margin: 10px;
    border-radius: 8px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    position: relative;
}

.notification-avatar {
    width: 50px;
    height: 50px;
    border-radius: 50%;
    background-color: #e9ecef;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 15px;
    float: left;
    overflow: hidden;
}

.notification-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.notification-avatar i {
    color: #adb5bd;
    font-size: 1.5rem;
}

.notification-content {
    margin-left: 65px;
}

.notification-message {
    margin: 0 0 5px 0;
    font-size: 0.9rem;
    line-height: 1.4;
}

.notification-time {
    font-size: 0.75rem;
    color: #6c757d;
    margin-bottom: 10px;
}

.notification-actions {
    display: flex;
    gap: 5px;
}

.notification-actions button {
    padding: 6px 12px;
    font-size: 0.8rem;
    border-radius: 4px;
    border: none;
    color: white;
    cursor: pointer;
    transition: all 0.2s ease;
}

.btn-accept {
    background-color: #198754;
    color: white;
}

.btn-accept:hover {
    background-color: #157347;
}

.btn-deny {
    background-color: #dc3545;
    color: white;
}

.btn-deny:hover {
    background-color: #bb2d3b;
}

.btn-remind {
    background-color: #e9ecef;
    color: #495057 !important;
}

.btn-remind:hover {
    background-color: #dee2e6;
}

.btn-delete {
    position: absolute;
    top: 5px;
    right: 5px;
    background: none;
    border: none;
    color: #6c757d;
    cursor: pointer;
    padding: 5px;
    font-size: 0.8rem;
    opacity: 0.5;
    transition: opacity 0.2s;
}

.btn-delete:hover {
    opacity: 1;
}



.notification-empty {
    padding: 30px 20px;
    text-align: center;
    color: #6c757d;
}

.notification-empty i {
    font-size: 2rem;
    margin-bottom: 10px;
    opacity: 0.5;
}

/* Responsive Styles */
@media (max-width: 576px) {
    .notification-panel {
        width: calc(100% - 40px);
        right: 20px;
        left: 20px;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // DOM Elements
    const notificationBellBtn = document.getElementById('notificationBellBtn');
    const notificationPanel = document.getElementById('notificationPanel');
    const closeNotificationBtn = document.getElementById('closeNotificationBtn');
    const notificationList = document.getElementById('notificationList');
    const notificationCount = document.getElementById('notificationCount');
    
    // Variables
    let notifications = [];
    
    // Initialize
    updateNotificationCount();
    
    // Event Listeners
    notificationBellBtn.addEventListener('click', toggleNotificationPanel);
    closeNotificationBtn.addEventListener('click', closeNotificationPanel);
    
    // Close panel when clicking outside
    document.addEventListener('click', function(event) {
        if (notificationPanel.classList.contains('show') && 
            !notificationPanel.contains(event.target) && 
            !notificationBellBtn.contains(event.target)) {
            closeNotificationPanel();
        }
    });
    
    // Toggle notification panel
    function toggleNotificationPanel() {
        if (notificationPanel.classList.contains('show')) {
            closeNotificationPanel();
        } else {
            openNotificationPanel();
        }
    }
    
    // Open notification panel
    function openNotificationPanel() {
        notificationPanel.classList.add('show');
        loadNotifications();
    }
    
    // Close notification panel
    function closeNotificationPanel() {
        notificationPanel.classList.remove('show');
    }
    
    // Load notifications from server
    function loadNotifications() {
        notificationList.innerHTML = `
            <div class="notification-loading">
                <div class="spinner-border" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
            </div>
        `;
        
        fetch('../api/notification_api.php?action=get')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Ensure profile images are valid
                    notifications = data.notifications.map(notification => {
                        // Check if profile image exists
                        if (notification.profile_image) {
                            // Verify image exists by preloading
                            verifyImage(notification.profile_image, (exists) => {
                                if (!exists) {
                                    console.log("Image failed to load, using default:", notification.profile_image);
                                    notification.profile_image = '../../assets/images/default-avatar.svg';
                                }
                            });
                        }
                        return notification;
                    });
                    
                    renderNotifications();
                } else {
                    showError('Failed to load notifications');
                }
            })
            .catch(error => {
                console.error('Error loading notifications:', error);
                showError('Error loading notifications');
            });
    }
    
    // Verify if an image exists and is accessible
    function verifyImage(url, callback) {
        const img = new Image();
        img.onload = function() {
            callback(true);
        };
        img.onerror = function() {
            callback(false);
        };
        img.src = url;
    }
    
    // Update notification count
    function updateNotificationCount() {
        fetch('../api/notification_api.php?action=count')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const count = data.count;
                    notificationCount.textContent = count > 99 ? '99+' : count;
                    
                    if (count > 0) {
                        notificationCount.style.display = 'flex';
                    } else {
                        notificationCount.style.display = 'none';
                    }
                }
            })
            .catch(error => {
                console.error('Error updating notification count:', error);
            });
    }
    
    // Render notifications
    function renderNotifications() {
        if (!notifications || notifications.length === 0) {
            showEmptyState();
            return;
        }
        
        // Clear and render
        notificationList.innerHTML = '';
        
        // Only show the most recent 5 notifications in the overlay
        const recentNotifications = notifications.slice(0, 5);
        
        recentNotifications.forEach(notification => {
            const notificationItem = createNotificationItem(notification);
            notificationList.appendChild(notificationItem);
        });
    }
    
    // Create notification item element
    function createNotificationItem(notification) {
        const item = document.createElement('div');
        item.className = 'notification-item';
        item.dataset.id = notification.notification_id;
        
        // Debug info to console
        console.log("Creating notification item:", notification);
        console.log("Notification type:", notification.type);
        
        // Avatar placeholder - ensure images work correctly
        let avatarContent = '<i class="fas fa-bell"></i>';
        
        if (notification.related_user_id) {
            if (notification.profile_image) {
                // Check and fix profile image path if needed
                let profileImage = notification.profile_image;
                
                // Log to console for debugging
                console.log("Original profile image path:", profileImage);
                
                // Check if the path needs adjustment
                if (!profileImage.startsWith('http') && !profileImage.startsWith('../../') && !profileImage.startsWith('/')) {
                    profileImage = '../../' + profileImage;
                }
                
                // Log adjusted path
                console.log("Adjusted profile image path:", profileImage);
                
                // Add image with error handling
                avatarContent = `<img src="${profileImage}" alt="${notification.first_name || 'User'}" 
                    onerror="this.onerror=null; this.src='../../assets/images/default-avatar.svg'; console.log('Image failed to load, using default');">`;
            } else {
                avatarContent = `<i class="fas fa-user"></i>`;
            }
        }
        
        // Get user name or default
        const userName = notification.first_name && notification.last_name ? 
            `${notification.first_name} ${notification.last_name}` : 
            '<FullName>';
        
        // Get project name if applicable
        const projectName = notification.project_name || '<ProjectName>';
        
        // Format time
        const timeAgo = formatTimeAgo(notification.created_at) || '<NotificationTime>';
        
        // Customize message based on notification type
        let message = notification.message;
        let actionButtons = '';
        
        // Check if notification type is undefined or empty
        if (!notification.type) {
            console.log("WARNING: Notification type is empty/undefined for ID:", notification.notification_id);
            console.log("Message:", notification.message);
            
            // Try to determine type based on message content
            if (notification.message.includes('requested to join') || notification.message.includes('request to join')) {
                console.log("Detected join request in message, setting type to join_request");
                notification.type = 'join_request';
            }
        }
        
        if (notification.type === 'connection_request') {
            message = `${userName} request to connect.`;
            actionButtons = `
                <button class="btn-accept" data-action="accept">Accept</button>
                <button class="btn-deny" data-action="reject">Deny</button>
                <button class="btn-remind">Remind Later</button>
            `;
        } else if (notification.type === 'project_invite') {
            message = `${userName} has invited you to join ${projectName}.`;
            actionButtons = `
                <button class="btn-accept" data-action="accept" data-handler="project">Accept</button>
                <button class="btn-deny" data-action="reject" data-handler="project">Deny</button>
            `;
            
            // Log for debugging
            console.log("Processing project invite notification:", notification);
        } else if (notification.type === 'leave_project') {
            message = `${userName} has requested to leave ${projectName}.`;
            actionButtons = `
                <button class="btn-accept" data-action="accept">Accept</button>
                <button class="btn-deny" data-action="reject">Deny</button>
            `;
        } else if (notification.type === 'leave_request') {
            message = `${userName} has requested to leave ${projectName}.`;
            actionButtons = `
                <button class="btn-accept" data-action="accept">Accept</button>
                <button class="btn-deny" data-action="reject">Deny</button>
            `;
        } else if (notification.type === 'join_request') {
            message = `${userName} has requested to join ${projectName}.`;
            actionButtons = `
                <button class="btn-accept" data-action="accept">Accept</button>
                <button class="btn-deny" data-action="reject">Deny</button>
            `;
        } else if (notification.message && notification.message.includes('requested to join')) {
            // Fallback for join requests with no type
            console.log("Detected join request without proper type", notification);
            message = notification.message;
            actionButtons = `
                <button class="btn-accept" data-action="accept">Accept</button>
                <button class="btn-deny" data-action="reject">Deny</button>
            `;
        }
        
        // Create HTML structure
        item.innerHTML = `
            <button class="btn-delete" data-action="delete" title="Delete notification">
                <i class="fas fa-times"></i>
            </button>
            <div class="notification-avatar">
                ${avatarContent}
            </div>
            <div class="notification-content">
                <p class="notification-message">${message}</p>
                <p class="notification-time">${timeAgo}</p>
                <div class="notification-actions">
                    ${actionButtons}
                </div>
            </div>
        `;
        
        // Add action button event listeners
        const actionBtns = item.querySelectorAll('.notification-actions button');
        actionBtns.forEach(button => {
            button.addEventListener('click', function(e) {
                e.stopPropagation();
                const action = this.dataset.action;
                const handler = this.dataset.handler || null;
                
                if (action === 'accept' || action === 'reject') {
                    handleNotificationAction(action, notification.notification_id, handler);
                }
            });
        });
        
        // Add delete button event listener
        const deleteBtn = item.querySelector('.btn-delete');
        if (deleteBtn) {
            deleteBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                deleteNotification(notification.notification_id);
            });
        }
        
        return item;
    }
    
    // Handle notification action buttons
    function handleNotificationAction(action, notificationId, handler = null) {
        // Determine the appropriate endpoint based on the handler type
        let endpoint = '../api/notification_api.php?action=' + action;
        
        // If this is a project invitation, use the dedicated handler
        if (handler === 'project') {
            endpoint = '../projects/handle_project_invite.php';
        }
        
        fetch(endpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `notification_id=${notificationId}&action=${action}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Remove the notification from the list
                const notificationItem = document.querySelector(`.notification-item[data-id="${notificationId}"]`);
                if (notificationItem) {
                    notificationItem.remove();
                }
                
                // Remove from notifications array
                notifications = notifications.filter(n => n.notification_id !== notificationId);
                
                // Update count
                updateNotificationCount();
                
                // If accepting project invite, redirect to project
                if (action === 'accept' && data.redirect_url) {
                    window.location.href = data.redirect_url;
                }
                
                // If no more notifications, show empty state
                if (notifications.length === 0) {
                    showEmptyState();
                }
            }
        })
        .catch(error => {
            console.error('Error handling notification action:', error);
        });
    }
    
    // Delete notification
    function deleteNotification(notificationId) {
        fetch('../api/notification_api.php?action=delete', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `notification_id=${notificationId}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Remove the notification from the list
                const notificationItem = document.querySelector(`.notification-item[data-id="${notificationId}"]`);
                if (notificationItem) {
                    notificationItem.remove();
                }
                
                // Remove from notifications array
                notifications = notifications.filter(n => n.notification_id !== notificationId);
                
                // Update count
                updateNotificationCount();
                
                // If no more notifications, show empty state
                if (notifications.length === 0) {
                    showEmptyState();
                }
            }
        })
        .catch(error => {
            console.error('Error deleting notification:', error);
        });
    }
    
    // Show empty state
    function showEmptyState(message = 'No notifications') {
        notificationList.innerHTML = `
            <div class="notification-empty">
                <i class="far fa-bell-slash"></i>
                <p>${message}</p>
            </div>
        `;
    }
    
    // Show error message
    function showError(message) {
        notificationList.innerHTML = `
            <div class="notification-empty">
                <i class="fas fa-exclamation-circle"></i>
                <p>${message}</p>
                <button class="btn btn-sm btn-outline-primary mt-2" onclick="loadNotifications()">Try Again</button>
            </div>
        `;
    }
    
    // Format time ago
    function formatTimeAgo(dateString) {
        if (!dateString) return '';
        
        const date = new Date(dateString);
        const now = new Date();
        const diffInSeconds = Math.floor((now - date) / 1000);
        
        if (diffInSeconds < 60) {
            return 'Just now';
        }
        
        const diffInMinutes = Math.floor(diffInSeconds / 60);
        if (diffInMinutes < 60) {
            return `${diffInMinutes} ${diffInMinutes === 1 ? 'minute' : 'minutes'} ago`;
        }
        
        const diffInHours = Math.floor(diffInMinutes / 60);
        if (diffInHours < 24) {
            return `${diffInHours} ${diffInHours === 1 ? 'hour' : 'hours'} ago`;
        }
        
        const diffInDays = Math.floor(diffInHours / 24);
        if (diffInDays < 30) {
            return `${diffInDays} ${diffInDays === 1 ? 'day' : 'days'} ago`;
        }
        
        const diffInMonths = Math.floor(diffInDays / 30);
        if (diffInMonths < 12) {
            return `${diffInMonths} ${diffInMonths === 1 ? 'month' : 'months'} ago`;
        }
        
        const diffInYears = Math.floor(diffInMonths / 12);
        return `${diffInYears} ${diffInYears === 1 ? 'year' : 'years'} ago`;
    }
    
    // Set up refresh interval for notification count
    setInterval(updateNotificationCount, 60000); // Every minute
});
</script>

<?php endif; ?> 