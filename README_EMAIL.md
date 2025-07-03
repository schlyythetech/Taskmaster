# TaskMaster Email Configuration

This document provides instructions for setting up email functionality in the TaskMaster application, specifically for sending ban/unban notifications from the Admin Dashboard.

## Prerequisites

1. A Gmail account
2. PHP with mail functionality enabled
3. Optional: Composer (for installing PHPMailer)

## Setup Instructions

### 1. Generate a Gmail App Password

For security reasons, Gmail requires an App Password for SMTP access from applications:

1. Go to your [Google Account](https://myaccount.google.com/)
2. Select "Security" from the left menu
3. Under "Signing in to Google," select "2-Step Verification" (enable it if not already enabled)
4. At the bottom of the page, select "App passwords"
5. Select "Mail" as the app and "Other" as the device
6. Enter "TaskMaster" as the name
7. Click "Generate"
8. Copy the 16-character password that appears

### 2. Configure TaskMaster Email Settings

1. Log in to TaskMaster as an admin user
2. Navigate to Admin Dashboard
3. Click on "Mail Settings" in the sidebar
4. Enter your Gmail address in the "Gmail Email Address" field
5. Enter the App Password you generated in the "Gmail App Password" field
6. Enter "TaskMaster Admin" (or your preferred name) in the "From Name" field
7. Click "Save Settings"
8. Optionally, send a test email to verify the configuration

### 3. Manual Configuration (If Admin Dashboard is not accessible)

If you cannot access the Admin Dashboard, you can manually edit the configuration file:

1. Open `config/mail_config.php`
2. Update the following values:
   ```php
   $mail_config = [
       'smtp_host' => 'smtp.gmail.com',
       'smtp_port' => 587,
       'smtp_secure' => 'tls',
       'smtp_auth' => true,
       'smtp_username' => 'your.email@gmail.com', // Your Gmail address
       'smtp_password' => 'your-app-password', // Your Gmail app password
       'from_email' => 'your.email@gmail.com',
       'from_name' => 'TaskMaster Admin',
       'reply_to' => 'your.email@gmail.com'
   ];
   ```

### 4. Installing PHPMailer (If not using Composer)

If you don't have Composer installed, you can manually install PHPMailer:

1. Run the installation script:
   ```
   php install_phpmailer.php
   ```

2. This will download and set up PHPMailer in the `vendor` directory

## Troubleshooting

If emails are not being sent:

1. Check that your Gmail App Password is correct
2. Verify that your Gmail account doesn't have additional security restrictions
3. Check your server's PHP mail logs for errors
4. Try sending a test email from the Mail Settings page
5. Ensure that outbound SMTP connections are allowed by your server/hosting provider

## Email Notifications

The following email notifications are currently implemented:

1. **Ban Notification**: Sent when an admin bans a user
2. **Unban Notification**: Sent when an admin unbans a user

Each notification includes the reason for the action and appropriate instructions for the user.

## Security Considerations

- The Gmail App Password is stored in the `mail_config.php` file. Ensure this file has appropriate permissions (readable only by the web server).
- Consider using environment variables for sensitive information in production environments.
- Regularly rotate your App Password for enhanced security. 