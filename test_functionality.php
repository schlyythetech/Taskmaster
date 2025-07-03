<?php
/**
 * Script to test if the organized PHP files are working correctly
 */

// Check if includes/functions.php exists and is readable
echo "Testing includes/functions.php... ";
if (file_exists('includes/functions.php') && is_readable('includes/functions.php')) {
    echo "OK\n";
} else {
    echo "FAIL - File not found or not readable\n";
}

// Check if includes/functions/ directory exists and contains all required files
echo "Testing includes/functions/ directory... ";
$requiredFunctionFiles = [
    'auth.php',
    'utils.php',
    'db.php',
    'notifications.php',
    'projects.php',
    'tasks.php',
    'connections.php',
    'maintenance.php',
    'attachments.php'
];

$missingFiles = [];
foreach ($requiredFunctionFiles as $file) {
    $path = "includes/functions/$file";
    if (!file_exists($path) || !is_readable($path)) {
        $missingFiles[] = $file;
    }
}

if (empty($missingFiles)) {
    echo "OK\n";
} else {
    echo "FAIL - Missing files: " . implode(', ', $missingFiles) . "\n";
}

// Check if pages directory exists and contains all required subdirectories
echo "Testing pages/ directory structure... ";
$requiredDirectories = [
    'auth',
    'core',
    'projects',
    'tasks',
    'users',
    'admin',
    'maintenance',
    'attachments',
    'api'
];

$missingDirs = [];
foreach ($requiredDirectories as $dir) {
    $path = "pages/$dir";
    if (!is_dir($path)) {
        $missingDirs[] = $dir;
    }
}

if (empty($missingDirs)) {
    echo "OK\n";
} else {
    echo "FAIL - Missing directories: " . implode(', ', $missingDirs) . "\n";
}

// Test loading functions
echo "Testing function loading... ";
try {
    require_once 'includes/functions.php';
    echo "OK\n";
} catch (Exception $e) {
    echo "FAIL - Error: " . $e->getMessage() . "\n";
}

// Test if functions are available
echo "Testing function availability... ";
$requiredFunctions = [
    'isLoggedIn',
    'redirect',
    'sanitize',
    'setMessage',
    'displayMessage',
    'generateToken',
    'validateCSRFToken',
    'tableExists',
    'sendInvitationEmail',
    'getSiteUrl'
];

$missingFunctions = [];
foreach ($requiredFunctions as $function) {
    if (!function_exists($function)) {
        $missingFunctions[] = $function;
    }
}

if (empty($missingFunctions)) {
    echo "OK\n";
} else {
    echo "FAIL - Missing functions: " . implode(', ', $missingFunctions) . "\n";
}

// Test database connection if config exists
echo "Testing database connection... ";
if (file_exists('config/database.php')) {
    try {
        require_once 'config/database.php';
        if (isset($conn)) {
            // Check if it's a PDO connection or our MockConnection
            if ($conn instanceof PDO || $conn instanceof MockConnection) {
                echo "OK\n";
            } else {
                echo "FAIL - Database connection not established\n";
            }
        } else {
            echo "FAIL - Database connection not established\n";
        }
    } catch (Exception $e) {
        echo "FAIL - Error: " . $e->getMessage() . "\n";
    }
} else {
    echo "SKIP - config/database.php not found\n";
}

// Check if index.php exists and redirects correctly
echo "Testing index.php... ";
if (file_exists('index.php') && is_readable('index.php')) {
    $content = file_get_contents('index.php');
    if (strpos($content, "Location: pages/core/index.php") !== false) {
        echo "OK\n";
    } else {
        echo "FAIL - index.php does not redirect to pages/core/index.php\n";
    }
} else {
    echo "FAIL - index.php not found or not readable\n";
}

// Check if core/index.php exists and redirects correctly
echo "Testing pages/core/index.php... ";
if (file_exists('pages/core/index.php') && is_readable('pages/core/index.php')) {
    $content = file_get_contents('pages/core/index.php');
    if (strpos($content, "redirect('../core/dashboard.php')") !== false && 
        strpos($content, "redirect('../auth/login.php')") !== false) {
        echo "OK\n";
    } else {
        echo "FAIL - pages/core/index.php does not have correct redirects\n";
    }
} else {
    echo "FAIL - pages/core/index.php not found or not readable\n";
}

echo "\nFunctionality test completed.\n";
?> 