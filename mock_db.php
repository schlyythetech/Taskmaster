<?php
/**
 * Mock database for testing when real database connection fails
 */

// Define a simple mock database connection class
class MockConnection {
    public function prepare($query) {
        return new MockStatement();
    }
    
    public function query($query) {
        return new MockStatement();
    }
    
    public function exec($query) {
        return 1;
    }
    
    public function lastInsertId($name = null) {
        return 1;
    }
    
    public function setAttribute($attribute, $value) {
        return true;
    }
    
    public function beginTransaction() {
        return true;
    }
    
    public function commit() {
        return true;
    }
    
    public function rollBack() {
        return true;
    }
}

// Define a simple mock statement class
class MockStatement {
    public function execute($params = null) {
        return true;
    }
    
    public function fetch($fetch_style = null) {
        return ['id' => 1, 'name' => 'Mock Data', 'status' => 'active'];
    }
    
    public function fetchAll($fetch_style = null) {
        return [
            ['id' => 1, 'name' => 'Mock Data 1', 'status' => 'active'],
            ['id' => 2, 'name' => 'Mock Data 2', 'status' => 'pending']
        ];
    }
    
    public function fetchColumn() {
        return 1;
    }
    
    public function rowCount() {
        return 2;
    }
}

// Create a mock connection
$conn = new MockConnection();

// Set mock database connection status in the session
if (session_status() === PHP_SESSION_ACTIVE) {
    $_SESSION['db_connected'] = true;
    $_SESSION['db_driver'] = 'mock_db';
}

// Log that we're using the mock database
error_log("Using mock database for testing");
?> 