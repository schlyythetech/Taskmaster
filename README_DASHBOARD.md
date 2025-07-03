# TaskMaster Dashboard & Session Tracking

This document explains how the TaskMaster dashboard works, particularly the "Hours Spent" feature that tracks user activity time.

## Hours Spent Feature

The "Hours Spent" feature tracks how much time users spend logged into the system. This is useful for:

- Monitoring productivity
- Tracking billable hours
- Understanding user engagement

## How It Works

1. **Session Tracking**: The system records when a user logs in and logs out.
2. **Time Calculation**: 
   - When a user logs in, a new session record is created
   - When a user logs out, the session is closed and duration is calculated
   - For active sessions, time is calculated in real-time

## Database Structure

The system uses a `user_sessions` table with the following structure:

```sql
CREATE TABLE user_sessions (
  session_id INT NOT NULL AUTO_INCREMENT,
  user_id INT NOT NULL,
  login_time TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  logout_time TIMESTAMP NULL DEFAULT NULL,
  duration_minutes DECIMAL(10,2) DEFAULT NULL,
  is_active TINYINT(1) DEFAULT 1,
  PRIMARY KEY (session_id),
  KEY user_id (user_id)
);
```

## Files and Functions

### Session Functions (`includes/session_functions.php`)

- `recordUserLogin($conn, $user_id)`: Records a new login session
- `recordUserLogout($conn, $user_id)`: Records a logout and calculates session duration
- `getUserHoursSpent($conn, $user_id)`: Calculates total hours spent by a user
- `ensureUserSessionsTable($conn)`: Creates the user_sessions table if it doesn't exist

### Login/Logout Integration

- `pages/auth/login.php`: Records a new session when user logs in
- `pages/auth/logout.php`: Closes the active session when user logs out

### Dashboard Display

- `pages/core/dashboard.php`: Displays the hours spent in the dashboard

## Testing

You can test the functionality using:

1. `test_dashboard.php`: Tests the session tracking functionality
2. `admin/manage_sessions.php`: Admin tool to view and manage user sessions

## Debug Mode

To view detailed session information on the dashboard, add `?debug=1` to the URL:

```
http://yourdomain.com/pages/core/dashboard.php?debug=1
```

## Implementation Notes

- The system handles both normal logout and browser/session timeouts
- Hours are displayed rounded to 1 decimal place
- Active sessions are included in the hours calculation

## Maintenance

Regular maintenance tasks:

1. Close stale sessions (sessions that were not properly closed)
2. Archive old session data for performance
3. Monitor for unusual session patterns

## Future Improvements

Potential enhancements:

1. Activity tracking within sessions
2. Idle time detection
3. Task-specific time tracking
4. Visual reports and analytics 