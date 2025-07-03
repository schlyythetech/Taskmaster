<?php
/**
 * Database Connection Diagnostics
 * 
 * This script checks the database connection and PHP configuration
 * to help diagnose connection issues.
 */

echo "PHP Database Connection Diagnostics\n";
echo "==================================\n\n";

// Check PHP version
echo "PHP Version: " . phpversion() . "\n\n";

// Check if PDO is available
echo "PDO Extension: " . (extension_loaded('pdo') ? 'Enabled' : 'DISABLED') . "\n";

// Check if MySQL PDO driver is available
echo "PDO MySQL Driver: " . (extension_loaded('pdo_mysql') ? 'Enabled' : 'DISABLED') . "\n\n";

// List all available PDO drivers
echo "Available PDO Drivers:\n";
$drivers = PDO::getAvailableDrivers();
if (empty($drivers)) {
    echo "- No PDO drivers available\n";
} else {
    foreach ($drivers as $driver) {
        echo "- $driver\n";
    }
}
echo "\n";

// Database configuration from config file
$host = 'localhost';
$db_name = 'taskmaster';
$username = 'root';
$password = '';
$charset = 'utf8mb4';

echo "Database Configuration:\n";
echo "- Host: $host\n";
echo "- Database: $db_name\n";
echo "- Username: $username\n";
echo "- Password: " . (empty($password) ? 'Not set' : 'Set (hidden)') . "\n";
echo "- Charset: $charset\n\n";

// Try database connection
echo "Testing Database Connection...\n";
try {
    // Test if the MySQL server is accessible
    $mysqli = new mysqli($host, $username, $password);
    if ($mysqli->connect_error) {
        throw new Exception("MySQL Connection Error: " . $mysqli->connect_error);
    }
    echo "- MySQL Server Connection: SUCCESS\n";
    
    // Test if the database exists
    $result = $mysqli->query("SHOW DATABASES LIKE '$db_name'");
    if ($result->num_rows === 0) {
        echo "- Database '$db_name': NOT FOUND\n";
        throw new Exception("Database does not exist");
    }
    echo "- Database '$db_name': EXISTS\n";
    $mysqli->select_db($db_name);
    
    // Test if connections table exists
    $result = $mysqli->query("SHOW TABLES LIKE 'connections'");
    if ($result->num_rows === 0) {
        echo "- Table 'connections': NOT FOUND\n";
        throw new Exception("Connections table does not exist");
    }
    echo "- Table 'connections': EXISTS\n";
    
    // Check if there are any orphaned connections
    $result = $mysqli->query("
        SELECT COUNT(*) as count
        FROM connections c
        LEFT JOIN users u1 ON c.user_id = u1.user_id
        LEFT JOIN users u2 ON c.connected_user_id = u2.user_id
        WHERE u1.user_id IS NULL OR u2.user_id IS NULL
    ");
    $row = $result->fetch_assoc();
    $orphaned_count = $row['count'];
    echo "- Orphaned Connections: " . ($orphaned_count > 0 ? "$orphaned_count FOUND" : "None") . "\n";
    
    // Now try with PDO
    echo "\nTesting PDO Connection...\n";
    
    if (!extension_loaded('pdo') || !extension_loaded('pdo_mysql')) {
        throw new Exception("PDO or PDO_MySQL extension not available");
    }
    
    try {
        $conn = new PDO("mysql:host=$host;dbname=$db_name;charset=$charset", $username, $password);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        echo "- PDO Connection: SUCCESS\n";
        
        // Test a simple query
        $stmt = $conn->query("SELECT 1");
        echo "- PDO Query: SUCCESS\n";
        
    } catch (PDOException $e) {
        echo "- PDO Connection: FAILED\n";
        echo "- PDO Error: " . $e->getMessage() . "\n";
    }
    
    $mysqli->close();
    echo "\nDiagnostics completed successfully.\n";
    
} catch (Exception $e) {
    echo "\nError: " . $e->getMessage() . "\n";
    echo "Diagnostics failed.\n";
}

// Provide recommendation based on findings
echo "\nRecommendations:\n";
if (!extension_loaded('pdo')) {
    echo "- Install PDO extension (php_pdo.dll)\n";
}
if (!extension_loaded('pdo_mysql')) {
    echo "- Install PDO_MySQL extension (php_pdo_mysql.dll)\n";
    echo "- Check php.ini and ensure extension=pdo_mysql is uncommented\n";
}
if (extension_loaded('pdo') && extension_loaded('pdo_mysql')) {
    echo "- PHP configuration looks good for database connections\n";
    if (isset($orphaned_count) && $orphaned_count > 0) {
        echo "- Run fix_connections.php script to repair orphaned connections\n";
    }
}
echo "- If using XAMPP, ensure MySQL service is running\n";
echo "- Check database credentials in config/database.php\n";
?> 