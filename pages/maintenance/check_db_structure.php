<?php
require_once '../../config/database.php';

try {
    // Check tasks table structure
    $stmt = $conn->prepare("DESCRIBE tasks");
    $stmt->execute();
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Tasks Table Structure:\n";
    echo "====================\n";
    foreach ($columns as $column) {
        echo $column['Field'] . " - " . $column['Type'] . " - " . $column['Null'] . " - " . $column['Key'] . "\n";
    }
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?> 