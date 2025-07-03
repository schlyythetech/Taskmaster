<?php
// Database configuration
$host = 'sql303.infinityfree.com';
$db_name = 'if0_39364471_taskmaster';
$username = 'if0_39364471';
$password = 'S5hKKKO1eKwjG';
$charset = 'utf8mb4';

// Define environment if not already defined - set to 'development' to see detailed errors
if (!defined('ENVIRONMENT')) {
    define('ENVIRONMENT', 'development'); // Set to 'development' during development, 'production' for live
}

// Get available PDO drivers
$available_drivers = PDO::getAvailableDrivers();
$mysql_available = in_array('mysql', $available_drivers);

// Create connection
try {
    if ($mysql_available) {
        // PDO MySQL is available
        try {
            // First try to connect without specifying the database to check if server is accessible
            $base_conn = new PDO("mysql:host=$host;charset=$charset", $username, $password);
            $base_conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Check if database exists, create it if it doesn't
            $stmt = $base_conn->query("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = '$db_name'");
            $db_exists = $stmt->fetchColumn();
            
            if (!$db_exists) {
                // Database doesn't exist, create it
                $base_conn->exec("CREATE DATABASE IF NOT EXISTS `$db_name` DEFAULT CHARACTER SET $charset COLLATE {$charset}_general_ci");
                error_log("Database '$db_name' created successfully");
            }
            
            // Now connect to the specific database
            $conn = new PDO("mysql:host=$host;dbname=$db_name;charset=$charset", $username, $password);
            
            // Set the PDO error mode to exception
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            // Set default fetch mode to associative array
            $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            // Disable emulation of prepared statements
            $conn->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
            
            // Set database connection status in the session
            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION['db_connected'] = true;
                $_SESSION['db_driver'] = 'pdo_mysql';
            }
        } catch (PDOException $e) {
            // If PDO connection fails, use mock database
            error_log("PDO connection failed: " . $e->getMessage() . ". Using mock database.");
            include_once __DIR__ . '/../mock_db.php';
            return; // Exit this script as mock_db.php will handle everything
        }
    } else {
        // PDO MySQL not available, use mock database
        error_log("PDO MySQL driver not available. Using mock database.");
        include_once __DIR__ . '/../mock_db.php';
        return; // Exit this script as mock_db.php will handle everything
    }
} catch (Exception $e) {
    // Log the error with detailed information
    error_log("Database connection failed: " . $e->getMessage());
    
    // Try to use mock database as a last resort
    try {
        include_once __DIR__ . '/../mock_db.php';
        return; // Exit this script as mock_db.php will handle everything
    } catch (Exception $mockException) {
        // Mock database also failed
        error_log("Mock database also failed: " . $mockException->getMessage());
        
        // Only show detailed message in development environment
        if (ENVIRONMENT === 'development') {
            $error_message = "Database connection failed: " . $e->getMessage() . 
                             "<br>Mock database also failed: " . $mockException->getMessage();
        } else {
            $error_message = "System is currently unavailable. Please try again later or contact support.";
        }
        
        // Store error message in session if session has started
        if (session_status() === PHP_SESSION_ACTIVE && function_exists('setMessage')) {
            setMessage($error_message, "danger");
            
            // Only redirect if we're not in a specific API request that needs JSON response
            if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || $_SERVER['HTTP_X_REQUESTED_WITH'] !== 'XMLHttpRequest') {
                header("Location: maintenance.php");
                exit;
            }
        }
        
        // For AJAX/API requests, return JSON error
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $error_message]);
            exit;
        }
        
        // Otherwise just show the error
        echo $error_message;
        exit;
    }
}

// Removed duplicate tableExists() function since it's already defined in includes/functions.php
?> 