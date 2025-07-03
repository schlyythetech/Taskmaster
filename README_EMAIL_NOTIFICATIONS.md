# TaskMaster Email Notification System

This document explains how the TaskMaster email notification system works, particularly for task assignments and reminders.

## Email Notification Types

TaskMaster supports the following types of email notifications:

1. **Project Invitation Emails** - Sent when a user is invited to join a project
2. **Task Assignment Emails** - Sent when a user is assigned to a task
3. **Daily Task Reminder Emails** - Sent daily to users with pending tasks

## Task Assignment Emails

When a user creates a task and assigns it to another user, an email is automatically sent to the assignee with the following details:

- Task title
- Task description
- Project name
- Due date
- Priority level
- Any attached files
- Link to view the task

The email is sent immediately when the task is created. If multiple users are assigned to a task, each user receives an individual email notification.

### Implementation Details

Task assignment emails are sent from the `pages/tasks/create_task.php` file when a new task is created. The `sendTaskAssignmentEmail()` function in `includes/functions/mail.php` handles the email formatting and delivery.

## Daily Task Reminder Emails

TaskMaster sends daily reminder emails to users with assigned tasks that:

1. Are not yet completed
2. Have not passed their deadline

The reminder emails include:

- List of all pending tasks
- Due dates for each task
- Priority levels
- Project names
- Links to view each task

Tasks with approaching deadlines (within 2 days) are highlighted with a warning message.

### Implementation Details

Daily task reminders are sent by the `cron/send_task_reminders.php` script, which should be configured to run once per day via a cron job. The script:

1. Queries all users with email notifications enabled
2. For each user, finds their incomplete tasks with valid deadlines
3. Sends a consolidated email listing all pending tasks
4. Highlights tasks with approaching deadlines

## Setting Up Email Notifications

To enable email notifications, ensure that:

1. The SMTP settings are correctly configured in your application's configuration file
2. Users have enabled email notifications in their account settings
3. The daily task reminder cron job is properly set up

### Cron Job Setup

To set up the daily task reminder cron job, add the following entry to your server's crontab:

```
# Run task reminders at 7:00 AM every day
0 7 * * * php /path/to/taskmaster/cron/send_task_reminders.php
```

## Customizing Email Templates

Email templates are defined in the `includes/functions/mail.php` file. You can customize the appearance and content of emails by modifying the HTML templates in the following functions:

- `sendProjectInvitationEmail()` - Project invitation emails
- `sendTaskAssignmentEmail()` - Task assignment emails
- `sendTaskReminderEmail()` - Daily task reminder emails

## Testing the Email System

To test the email notification system:

1. **Task Assignment Emails**:
   - Create a new task and assign it to a user
   - Check that the assignee receives an email with the task details
   - Verify that all task information is correctly displayed in the email

2. **Daily Task Reminders**:
   - Run the task reminder script manually:
     ```
     php /path/to/taskmaster/cron/send_task_reminders.php
     ```
   - Check the log file at `cron/task_reminder_log.txt` for results
   - Verify that users with pending tasks receive reminder emails
   - Confirm that tasks with approaching deadlines are highlighted

3. **Project Invitation Emails**:
   - Invite a new user to a project
   - Verify that the invitation email is sent
   - Test the invitation link to ensure it works correctly

## Troubleshooting

If email notifications are not being sent:

1. Check the SMTP configuration in your application settings
2. Verify that the user has email notifications enabled in their account settings
3. Check the task reminder log file at `cron/task_reminder_log.txt` for any errors
4. Ensure the PHP mail function or SMTP server is working correctly
5. Check if PHPMailer is properly installed (if using PHPMailer)

## Security Considerations

1. **Email Content**: Emails contain links to the TaskMaster application. Ensure these links use HTTPS if available.
2. **SMTP Credentials**: Store SMTP credentials securely and never commit them to version control.
3. **User Privacy**: Only send emails to users who have explicitly opted in to receive notifications.
4. **Rate Limiting**: The task reminder script includes a small delay between sending emails to avoid overwhelming the mail server. 