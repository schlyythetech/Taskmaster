# TaskMaster Functions Organization

This directory contains organized PHP functions for the TaskMaster application, separated by their purpose and functionality.

## File Structure

- **auth.php**: Authentication and security-related functions
- **utils.php**: General utility functions
- **db.php**: Database helper functions
- **notifications.php**: Notification management functions
- **projects.php**: Project management functions
- **tasks.php**: Task management functions
- **connections.php**: User connection management functions
- **maintenance.php**: Database maintenance and cleanup functions
- **attachments.php**: File attachment handling functions

## Usage

All these function files are automatically included by the main `includes/functions.php` file, so you don't need to include them individually in your PHP files. Simply include the main functions file:

```php
require_once 'includes/functions.php';
```

## Function Categories

### Authentication (auth.php)
- Session management
- Login/logout functionality
- Token generation and validation
- Email invitations

### Utilities (utils.php)
- Input sanitization
- Page redirects
- Flash messages
- URL handling

### Database (db.php)
- Transaction management
- Table existence checks
- User existence checks
- Basic database operations

### Notifications (notifications.php)
- Create notifications
- Retrieve user notifications
- Mark notifications as read
- Count unread notifications

### Projects (projects.php)
- Project member management
- Project access control
- Project details retrieval

### Tasks (tasks.php)
- Task creation and management
- Task status updates
- Task comments
- Task retrieval

### Connections (connections.php)
- User connection requests
- Connection status management
- Connection retrieval

### Maintenance (maintenance.php)
- Orphaned record cleanup
- Database integrity checks
- Data fixing utilities

### Attachments (attachments.php)
- File uploads
- Attachment management
- File retrieval and deletion 