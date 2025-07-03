# TaskMaster

TaskMaster is a PHP-based task management system with project collaboration features.

## Project Structure

The project has been reorganized for better maintainability and follows a logical directory structure:

### Root Directory
- `index.php` - Main entry point that redirects to pages/core/index.php
- `config/` - Configuration files
- `includes/` - Shared includes and functions
- `assets/` - Static assets (CSS, JS, images)
- `pages/` - All PHP pages organized by functionality

### Includes Directory
- `includes/functions.php` - Main functions file that includes all function modules
- `includes/functions/` - Organized function modules:
  - `auth.php` - Authentication functions
  - `utils.php` - Utility functions
  - `db.php` - Database functions
  - `notifications.php` - Notification functions
  - `projects.php` - Project-related functions
  - `tasks.php` - Task-related functions
  - `connections.php` - User connection functions
  - `maintenance.php` - Maintenance functions
  - `attachments.php` - File attachment functions

### Pages Directory
- `pages/auth/` - Authentication pages (login, register, etc.)
- `pages/core/` - Core pages (dashboard, settings, etc.)
- `pages/projects/` - Project management pages
- `pages/tasks/` - Task management pages
- `pages/users/` - User profile and connection pages
- `pages/admin/` - Administration pages
- `pages/maintenance/` - Maintenance pages
- `pages/attachments/` - File attachment pages
- `pages/api/` - API endpoints

## Testing

Two utility scripts are provided to ensure functionality:
- `test_functionality.php` - Tests if the organized PHP files are working correctly
- `fix_paths.php` - Fixes file paths in PHP files to work with the new directory structure

## Development

When adding new functionality:
1. Place PHP files in the appropriate subdirectory under `pages/`
2. Place functions in the appropriate module under `includes/functions/`
3. Update paths to use relative paths (e.g., `../../includes/functions.php` from pages)

## Database

The application supports both MySQL and a mock database for testing purposes.

## Email Configuration Setup

TaskMaster includes functionality to send emails for password resets and other notifications. Follow these steps to set up the email functionality:

### 1. Install Dependencies

The application uses PHPMailer for sending emails. Install it using Composer:

```bash
composer install
```

### 2. Email Configuration

The email configuration is stored in `config/email_config.php`. The application is pre-configured to use Gmail SMTP with the following settings:

- **SMTP Server**: smtp.gmail.com
- **Port**: 587
- **Username**: taskm4030@gmail.com
- **App Password**: pgqm ppel nqik guuq

### 3. Gmail App Password

The application uses a Gmail App Password for authentication. This is a 16-character password that gives the application permission to access your Gmail account.

**Note**: The App Password is already configured in the application. If you need to use a different email account, you'll need to generate a new App Password for that account.

### How to Generate a Gmail App Password

If you need to use a different Gmail account:

1. Go to your Google Account settings at [myaccount.google.com](https://myaccount.google.com)
2. Select "Security"
3. Under "Signing in to Google," select "2-Step Verification" (you must have this enabled)
4. At the bottom of the page, select "App passwords"
5. Select "Mail" as the app and "Other (Custom name)" as the device
6. Enter "TaskMaster" as the name
7. Click "Generate"
8. Copy the 16-character password
9. Update the `EMAIL_PASSWORD` constant in `config/email_config.php`

### Testing Email Functionality

To test if the email functionality is working:

1. Go to the login page
2. Click on "Forgot password?"
3. Enter your email address
4. Check your email for the password reset link

## Security Note

In a production environment, you should:

1. Store sensitive information like email passwords in environment variables
2. Use HTTPS to encrypt data transmission
3. Regularly rotate app passwords

## Additional Configuration

If you need to use a different email provider, update the following constants in `config/email_config.php`:

- `EMAIL_HOST`: The SMTP server address
- `EMAIL_PORT`: The SMTP server port
- `EMAIL_USERNAME`: Your email address
- `EMAIL_PASSWORD`: Your password or app password
- `EMAIL_FROM_NAME`: The name that will appear in the "From" field
- `EMAIL_FROM_ADDRESS`: The email address that will appear in the "From" field
- `EMAIL_REPLY_TO`: The email address for replies
- `EMAIL_USE_SMTP`: Set to true to use SMTP, false to use PHP's mail() function
- `EMAIL_SMTP_AUTH`: Set to true if authentication is required
- `EMAIL_SMTP_SECURE`: Use 'tls' or 'ssl' for secure connections 