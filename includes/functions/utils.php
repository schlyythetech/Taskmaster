<?php
/**
 * Utility Functions
 * 
 * This file contains general utility functions used throughout the application.
 */

/**
 * Sanitize user input
 * @param string $data
 * @return string
 */
function sanitize($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

/**
 * Redirect to a specific page
 * @param string $location
 * @return void
 */
function redirect($location) {
    header("Location: $location");
    exit;
}

/**
 * Display flash message as a toast notification
 * @return void
 */
function displayMessage() {
    if (isset($_SESSION['message'])) {
        // Get appropriate icon based on message type
        $icon = 'info-circle';
        switch($_SESSION['message_type']) {
            case 'success':
                $icon = 'check-circle';
                break;
            case 'danger':
                $icon = 'exclamation-circle';
                break;
            case 'warning':
                $icon = 'exclamation-triangle';
                break;
        }
        
        echo '<div class="toast-container position-fixed top-0 end-0 p-3">
            <div id="liveToast" class="toast show" role="alert" aria-live="assertive" aria-atomic="true">
                <div class="toast-header bg-' . $_SESSION['message_type'] . ' text-white">
                    <i class="fas fa-' . $icon . ' me-2"></i>
                    <strong class="me-auto">' . ucfirst($_SESSION['message_type']) . ' Notification</strong>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast" aria-label="Close"></button>
                </div>
                <div class="toast-body">
                    ' . $_SESSION['message'] . '
                </div>
            </div>
        </div>';
        
        // Add JavaScript to auto-dismiss the toast after 5 seconds
        echo '<script>
            document.addEventListener("DOMContentLoaded", function() {
                var toast = document.getElementById("liveToast");
                var bsToast = new bootstrap.Toast(toast, {
                    autohide: true,
                    delay: 5000
                });
                bsToast.show();
            });
        </script>';
        
        unset($_SESSION['message']);
        unset($_SESSION['message_type']);
    }
}

/**
 * Set flash message
 * @param string $message
 * @param string $type
 * @return void
 */
function setMessage($message, $type = 'info') {
    $_SESSION['message'] = $message;
    $_SESSION['message_type'] = $type;
}

/**
 * Get the site URL
 * @return string Site URL
 */
function getSiteUrl() {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
    $domainName = $_SERVER['HTTP_HOST'];
    $path = dirname($_SERVER['PHP_SELF']);
    $path = str_replace('\\', '/', $path);
    $path = rtrim($path, '/');
    
    return $protocol . $domainName . $path;
}
?> 