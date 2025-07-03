<?php
/**
 * Main Functions File
 * 
 * This file includes all function files from the functions directory.
 */

// Include mail configuration
require_once __DIR__ . '/../config/mail_config.php';

// Include all function files
require_once 'functions/auth.php';
require_once 'functions/utils.php';
require_once 'functions/db.php';
require_once 'functions/notification_functions.php';
require_once 'functions/projects.php';
require_once 'functions/tasks.php';
require_once 'functions/connections.php';
require_once 'functions/maintenance.php';
require_once 'functions/attachments.php';
require_once 'functions/mail.php';

// Check if user is banned (only if database connection is available)
if (isset($conn)) {
    require_once 'check_ban.php';
}
?> 