<?php
/**
 * All Notifications Page
 * 
 * This page displays all notifications for the current user
 */
require_once '../../includes/functions.php';
require_once '../../config/database.php';

// Check if user is logged in
if (!isLoggedIn()) {
    setMessage("You must be logged in to view notifications.", "danger");
    redirect('../auth/login.php');
}

// Get user data
$user_id = $_SESSION['user_id'];

// Page title
$page_title = "All Notifications";
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
                <li>
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
                <li class="active">
                    <a href="../notifications/all.php"><i class="fas fa-bell"></i> Notifications</a>
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
        <div class="top-nav">
            <div>
                <h1 class="page-title">All Notifications</h1>
            </div>
            <!-- Notification system is included in header.php -->
            <div class="top-nav-right-placeholder"></div>
        </div>

        <!-- Notifications Content -->
        <div class="container mt-4">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="mb-0">Your Notifications</h5>
                    </div>
                    <div class="notification-filters">
                        <div class="btn-group" role="group" aria-label="Notification filters">
                            <button type="button" class="btn btn-outline-primary active" data-filter="all">All</button>
                            <button type="button" class="btn btn-outline-primary" data-filter="unread">Unread</button>
                            <button type="button" class="btn btn-outline-primary" data-filter="read">Read</button>
                        </div>
                        <button class="btn btn-outline-secondary ms-2" id="markAllReadBtn">
                            <i class="fas fa-check-double me-1"></i> Mark All Read
                        </button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="notification-list" id="notificationList">
                        <div class="notification-loading text-center py-5">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Logout Confirmation Modal -->
<div class="modal fade" id="logoutModal" tabindex="-1" aria-labelledby="logoutModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="logoutModalLabel">Confirm Logout</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to log out?</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <a href="../auth/logout.php" class="btn btn-primary" role="button">Logout</a>
            </div>
        </div>
    </div>
</div>

<style>
/* Notification styles */
.notification-list {
    max-height: 600px;
    overflow-y: auto;
}

.notification-item {
    padding: 15px 20px;
    border-bottom: 1px solid #f0f0f0;
    display: flex;
    align-items: flex-start;
    gap: 15px;
    transition: background-color 0.2s ease;
}

.notification-item:hover {
    background-color: #f8f9fa;
}

.notification-item.unread {
    background-color: rgba(13, 110, 253, 0.05);
}

.notification-item.unread:hover {
    background-color: rgba(13, 110, 253, 0.08);
}

.notification-icon {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background-color: #e9ecef;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.notification-icon i {
    color: #6c757d;
    font-size: 1rem;
}

.notification-content {
    flex: 1;
}

.notification-message {
    margin: 0 0 5px 0;
    font-size: 0.9rem;
    line-height: 1.4;
}

.notification-time {
    font-size: 0.75rem;
    color: #6c757d;
}

.notification-actions {
    display: flex;
    gap: 10px;
    margin-top: 8px;
}

.notification-actions button {
    padding: 5px 10px;
    font-size: 0.75rem;
    border-radius: 4px;
    border: 1px solid;
    background-color: white;
    cursor: pointer;
    transition: all 0.2s ease;
}

.notification-actions .btn-accept {
    background-color: #198754;
    border-color: #198754;
    color: white;
}

.notification-actions .btn-accept:hover {
    background-color: #157347;
}

.notification-actions .btn-reject {
    background-color: #dc3545;
    border-color: #dc3545;
    color: white;
}

.notification-actions .btn-reject:hover {
    background-color: #bb2d3b;
}

.notification-empty {
    padding: 50px 20px;
    text-align: center;
    color: #6c757d;
}

.notification-empty i {
    font-size: 3rem;
    margin-bottom: 20px;
    opacity: 0.5;
}

.notification-filters {
    display: flex;
    align-items: center;
}

@media (max-width: 768px) {
    .notification-filters {
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
    }
    
    .card-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // DOM Elements
    const notificationList = document.getElementById('notificationList');
    const filterButtons = document.querySelectorAll('.notification-filters button[data-filter]');
    const markAllReadBtn = document.getElementById('markAllReadBtn');
    const logoutBtn = document.getElementById('logout-btn');
    
    // Variables
    let notifications = [];
    let currentFilter = 'all';
    
    // Initialize
    loadNotifications();
    
    // Event Listeners
    filterButtons.forEach(button => {
        button.addEventListener('click', function() {
            const filter = this.dataset.filter;
            currentFilter = filter;
            
            // Update active state
            filterButtons.forEach(btn => btn.classList.remove('active'));
            this.classList.add('active');
            
            // Filter notifications
            renderNotifications();
        });
    });
    
    markAllReadBtn.addEventListener('click', markAllAsRead);
    
    if (logoutBtn) {
        logoutBtn.addEventListener('click', function(e) {
            e.preventDefault();
            $('#logoutModal').modal('show');
        });
    }
    
    // Load notifications from server
    function loadNotifications() {
        notificationList.innerHTML = `
            <div class="notification-loading text-center py-5">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
            </div>
        `;
        
        fetch('../api/notification_api.php?action=get')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    notifications = data.notifications;
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
    
    // Render notifications based on current filter
    function renderNotifications() {
        if (!notifications || notifications.length === 0) {
            showEmptyState();
            return;
        }
        
        // Filter notifications based on current filter
        let filteredNotifications;
        
        switch (currentFilter) {
            case 'unread':
                filteredNotifications = notifications.filter(n => n.is_read === '0');
                break;
            case 'read':
                filteredNotifications = notifications.filter(n => n.is_read === '1');
                break;
            default:
                filteredNotifications = notifications;
        }
        
        if (filteredNotifications.length === 0) {
            showEmptyState(currentFilter === 'unread' ? 'No unread notifications' : 'No notifications');
            return;
        }
        
        // Clear and render
        notificationList.innerHTML = '';
        
        // Track rendered notification IDs to prevent duplicates
        const renderedIds = new Set();
        
        filteredNotifications.forEach(notification => {
            // Skip if this notification ID has already been rendered
            if (renderedIds.has(notification.notification_id)) {
                return;
            }
            
            const notificationItem = createNotificationItem(notification);
            notificationList.appendChild(notificationItem);
            
            // Add to rendered IDs set
            renderedIds.add(notification.notification_id);
        });
    }
    
    // Create notification item element
    function createNotificationItem(notification) {
        const item = document.createElement('div');
        item.className = `notification-item ${notification.is_read === '0' ? 'unread' : ''}`;
        item.dataset.id = notification.notification_id;
        
        // Determine icon based on notification type
        let iconClass = 'fas fa-bell';
        
        switch (notification.type) {
            case 'task_assigned':
                iconClass = 'fas fa-tasks';
                break;
            case 'task_completed':
                iconClass = 'fas fa-check-circle';
                break;
            case 'comment':
                iconClass = 'fas fa-comment';
                break;
            case 'connection_request':
                iconClass = 'fas fa-user-plus';
                break;
            case 'project_invite':
                iconClass = 'fas fa-project-diagram';
                break;
            case 'achievement':
                iconClass = 'fas fa-trophy';
                break;
        }
        
        // Format time
        const timeAgo = formatTimeAgo(notification.created_at);
        
        // Create HTML structure
        item.innerHTML = `
            <div class="notification-icon">
                <i class="${iconClass}"></i>
            </div>
            <div class="notification-content">
                <p class="notification-message">${escapeHtml(notification.message)}</p>
                <div class="notification-time">${timeAgo}</div>
                ${getActionButtonsHtml(notification)}
            </div>
        `;
        
        // Add click event to mark as read
        item.addEventListener('click', function(e) {
            // Don't mark as read if clicking on action buttons
            if (e.target.closest('.notification-actions')) {
                return;
            }
            
            if (notification.is_read === '0') {
                markAsRead(notification.notification_id);
            }
            
            // Handle notification click based on type
            handleNotificationClick(notification);
        });
        
        // Add action button event listeners
        const actionButtons = item.querySelectorAll('.notification-actions button');
        actionButtons.forEach(button => {
            button.addEventListener('click', function(e) {
                e.stopPropagation();
                const action = this.dataset.action;
                handleNotificationAction(action, notification.notification_id);
            });
        });
        
        return item;
    }
    
    // Get HTML for action buttons based on notification type
    function getActionButtonsHtml(notification) {
        console.log("Processing notification:", notification.notification_id, "Type:", notification.type, "Message:", notification.message);
        
        // Check if notification type is undefined or empty but message indicates a join request
        if ((!notification.type || notification.type === '') && 
            (notification.message && (notification.message.includes('requested to join') || notification.message.includes('request to join')))) {
            console.log("Detected join request in message without proper type, adding buttons");
            return `
                <div class="notification-actions">
                    <button class="btn-accept" data-action="accept">Accept</button>
                    <button class="btn-reject" data-action="reject">Deny</button>
                </div>
            `;
        }
        
        // Check if message indicates a leave request regardless of type
        if (notification.message && 
            (notification.message.includes('requested to leave') || 
             notification.message.includes('leave the project'))) {
            console.log("Detected leave request in message, adding buttons");
            return `
                <div class="notification-actions">
                    <button class="btn-accept" data-action="accept">Accept</button>
                    <button class="btn-reject" data-action="reject">Deny</button>
                </div>
            `;
        }
        
        if (notification.type === 'connection_request') {
            return `
                <div class="notification-actions">
                    <button class="btn-accept" data-action="accept">Accept</button>
                    <button class="btn-reject" data-action="reject">Reject</button>
                </div>
            `;
        } else if (notification.type === 'project_invite') {
            return `
                <div class="notification-actions">
                    <button class="btn-accept" data-action="accept">Accept</button>
                    <button class="btn-reject" data-action="reject">Decline</button>
                </div>
            `;
        } else if (notification.type === 'join_request') {
            return `
                <div class="notification-actions">
                    <button class="btn-accept" data-action="accept">Accept</button>
                    <button class="btn-reject" data-action="reject">Deny</button>
                </div>
            `;
        } else if (notification.type === 'leave_project') {
            console.log("Found leave_project notification, adding buttons");
            return `
                <div class="notification-actions">
                    <button class="btn-accept" data-action="accept">Accept</button>
                    <button class="btn-reject" data-action="reject">Deny</button>
                </div>
            `;
        }
        
        return '';
    }
    
    // Handle notification click
    function handleNotificationClick(notification) {
        // Navigate based on notification type
        switch (notification.type) {
            case 'task_assigned':
            case 'task_completed':
                if (notification.related_id) {
                    window.location.href = `../tasks/view_task.php?id=${notification.related_id}`;
                }
                break;
            case 'project_invite':
            case 'join_request':
            case 'leave_project':
                // Don't navigate, let the accept/reject buttons handle it
                break;
            case 'connection_request':
                // Don't navigate, let the accept/reject buttons handle it
                break;
            default:
                // For other types, just mark as read
                break;
        }
    }
    
    // Handle notification action buttons
    function handleNotificationAction(action, notificationId) {
        if (action === 'accept' || action === 'reject') {
            fetch(`../../pages/api/notification_api.php?action=${action}`, {
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
                    
                    // Show success message
                    showToast(action === 'accept' ? 'Accepted successfully' : 'Rejected successfully');
                    
                    // If accepting project invite, redirect to project
                    if (action === 'accept' && data.redirect_url) {
                        window.location.href = data.redirect_url;
                    }
                    
                    // If no more notifications, show empty state
                    if (notifications.length === 0) {
                        showEmptyState();
                    }
                } else {
                    showToast('Failed to process action: ' + (data.message || 'Unknown error'), 'error');
                }
            })
            .catch(error => {
                console.error('Error handling notification action:', error);
                showToast('Error processing action', 'error');
            });
        }
    }
    
    // Mark notification as read
    function markAsRead(notificationId) {
        fetch('../../pages/api/notification_api.php?action=mark_read', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `notification_id=${notificationId}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Update UI
                const notificationItem = document.querySelector(`.notification-item[data-id="${notificationId}"]`);
                if (notificationItem) {
                    notificationItem.classList.remove('unread');
                    const markReadBtn = notificationItem.querySelector('[data-action="mark-read"]');
                    if (markReadBtn) {
                        markReadBtn.innerHTML = '<i class="fas fa-envelope"></i>';
                        markReadBtn.title = 'Mark as unread';
                    }
                }
                
                // Update notifications array
                const notification = notifications.find(n => n.notification_id === notificationId);
                if (notification) {
                    notification.is_read = '1';
                }
            }
        })
        .catch(error => {
            console.error('Error marking notification as read:', error);
        });
    }
    
    // Mark all notifications as read
    function markAllAsRead() {
        fetch('../../pages/api/notification_api.php?action=mark_all_read', {
            method: 'POST'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Update UI
                const unreadItems = document.querySelectorAll('.notification-item.unread');
                unreadItems.forEach(item => {
                    item.classList.remove('unread');
                    const markReadBtn = item.querySelector('[data-action="mark-read"]');
                    if (markReadBtn) {
                        markReadBtn.innerHTML = '<i class="fas fa-envelope"></i>';
                        markReadBtn.title = 'Mark as unread';
                    }
                });
                
                // Update notifications array
                notifications.forEach(notification => {
                    notification.is_read = '1';
                });
                
                // Show success message
                showToast('All notifications marked as read');
                
                // If on unread filter, reload
                if (currentFilter === 'unread') {
                    loadNotifications();
                }
            } else {
                showToast('Failed to mark all as read', 'error');
            }
        })
        .catch(error => {
            console.error('Error marking all as read:', error);
            showToast('Error marking all as read', 'error');
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
                <button class="btn btn-outline-primary mt-3" onclick="loadNotifications()">Try Again</button>
            </div>
        `;
    }
    
    // Format time ago
    function formatTimeAgo(dateString) {
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
    
    // Escape HTML to prevent XSS
    function escapeHtml(unsafe) {
        return unsafe
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }
    
    // Show toast notification
    function showToast(message, type = 'success') {
        // Create toast container if it doesn't exist
        let toastContainer = document.querySelector('.toast-container');
        if (!toastContainer) {
            toastContainer = document.createElement('div');
            toastContainer.className = 'toast-container';
            document.body.appendChild(toastContainer);
            
            // Add styles for toast container
            const style = document.createElement('style');
            style.textContent = `
                .toast-container {
                    position: fixed;
                    bottom: 20px;
                    right: 20px;
                    z-index: 1060;
                    max-width: 300px;
                }
                
                .toast {
                    padding: 12px 15px;
                    border-radius: 4px;
                    margin-bottom: 10px;
                    color: white;
                    font-size: 0.9rem;
                    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
                    animation: fadeIn 0.3s, fadeOut 0.3s 2.7s;
                    opacity: 0;
                    animation-fill-mode: forwards;
                }
                
                .toast.success {
                    background-color: #28a745;
                }
                
                .toast.error {
                    background-color: #dc3545;
                }
                
                @keyframes fadeIn {
                    from { opacity: 0; transform: translateY(20px); }
                    to { opacity: 1; transform: translateY(0); }
                }
                
                @keyframes fadeOut {
                    from { opacity: 1; transform: translateY(0); }
                    to { opacity: 0; transform: translateY(-20px); }
                }
            `;
            document.head.appendChild(style);
        }
        
        // Create toast
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        toast.textContent = message;
        
        // Add to container
        toastContainer.appendChild(toast);
        
        // Remove after animation
        setTimeout(() => {
            toast.remove();
        }, 3000);
    }
});
</script>

<?php include '../../includes/footer.php'; ?> 