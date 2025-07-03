<?php
/**
 * Dashboard Test Script
 * 
 * This script tests the dashboard functionality for tracking hours spent.
 */
require_once 'includes/functions.php';
require_once 'includes/session_functions.php';
require_once 'config/database.php';

// Ensure user_sessions table exists
ensureUserSessionsTable($conn);

echo "<h1>Dashboard Test Results</h1>";

// Test user ID (use an existing user ID from your database)
$test_user_id = 2; // Assuming user ID 2 exists

// 1. Test recording a login
echo "<h2>1. Testing User Login</h2>";
$session_id = recordUserLogin($conn, $test_user_id);
if ($session_id) {
    echo "<p>✅ Successfully recorded user login. Session ID: $session_id</p>";
} else {
    echo "<p>❌ Failed to record user login.</p>";
}

// 2. Check active session
echo "<h2>2. Checking Active Session</h2>";
$stmt = $conn->prepare("SELECT * FROM user_sessions WHERE user_id = ? AND is_active = 1");
$stmt->execute([$test_user_id]);
$active_session = $stmt->fetch();

if ($active_session) {
    echo "<p>✅ Active session found:</p>";
    echo "<pre>";
    print_r($active_session);
    echo "</pre>";
} else {
    echo "<p>❌ No active session found.</p>";
}

// 3. Test getting hours spent (should be near 0 since we just logged in)
echo "<h2>3. Testing Hours Spent Calculation (Initial)</h2>";
$hours_spent = getUserHoursSpent($conn, $test_user_id);
echo "<p>Current hours spent: $hours_spent</p>";

// 4. Simulate time passing (wait 5 seconds)
echo "<h2>4. Simulating Time Passing (5 seconds)</h2>";
echo "<p>Waiting 5 seconds...</p>";
sleep(5);
echo "<p>Done waiting.</p>";

// 5. Test getting hours spent again
echo "<h2>5. Testing Hours Spent Calculation (After Wait)</h2>";
$hours_spent = getUserHoursSpent($conn, $test_user_id);
echo "<p>Updated hours spent: $hours_spent</p>";

// 6. Test recording a logout
echo "<h2>6. Testing User Logout</h2>";
$logout_success = recordUserLogout($conn, $test_user_id);
if ($logout_success) {
    echo "<p>✅ Successfully recorded user logout.</p>";
} else {
    echo "<p>❌ Failed to record user logout.</p>";
}

// 7. Check session after logout
echo "<h2>7. Checking Session After Logout</h2>";
$stmt = $conn->prepare("SELECT * FROM user_sessions WHERE session_id = ?");
$stmt->execute([$session_id]);
$closed_session = $stmt->fetch();

if ($closed_session) {
    echo "<p>✅ Session updated after logout:</p>";
    echo "<pre>";
    print_r($closed_session);
    echo "</pre>";
} else {
    echo "<p>❌ Session not found after logout.</p>";
}

// 8. Test getting total hours spent
echo "<h2>8. Testing Total Hours Spent</h2>";
$total_hours = getUserHoursSpent($conn, $test_user_id);
echo "<p>Total hours spent: $total_hours</p>";

// 9. Show all sessions for the user
echo "<h2>9. All User Sessions</h2>";
$stmt = $conn->prepare("SELECT * FROM user_sessions WHERE user_id = ? ORDER BY login_time DESC");
$stmt->execute([$test_user_id]);
$all_sessions = $stmt->fetchAll();

if (count($all_sessions) > 0) {
    echo "<p>✅ Found " . count($all_sessions) . " sessions:</p>";
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Session ID</th><th>Login Time</th><th>Logout Time</th><th>Duration (min)</th><th>Active</th></tr>";
    
    foreach ($all_sessions as $session) {
        echo "<tr>";
        echo "<td>" . $session['session_id'] . "</td>";
        echo "<td>" . $session['login_time'] . "</td>";
        echo "<td>" . ($session['logout_time'] ? $session['logout_time'] : 'Still active') . "</td>";
        echo "<td>" . ($session['duration_minutes'] ? $session['duration_minutes'] : 'N/A') . "</td>";
        echo "<td>" . ($session['is_active'] ? 'Yes' : 'No') . "</td>";
        echo "</tr>";
    }
    
    echo "</table>";
} else {
    echo "<p>❌ No sessions found for this user.</p>";
}

echo "<p><a href='pages/core/dashboard.php'>Go to Dashboard</a></p>";
?> 