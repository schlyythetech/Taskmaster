<?php
session_start();

// Get message if set
$message = "";
$message_type = "info";

if (isset($_SESSION['message']) && isset($_SESSION['message_type'])) {
    $message = $_SESSION['message'];
    $message_type = $_SESSION['message_type'];
    
    // Clear message after displaying
    unset($_SESSION['message']);
    unset($_SESSION['message_type']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Maintenance - TaskMaster</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .maintenance-container {
            max-width: 600px;
            text-align: center;
            padding: 40px;
            background-color: white;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        .maintenance-icon {
            font-size: 60px;
            color: #007bff;
            margin-bottom: 20px;
        }
        h1 {
            font-size: 32px;
            margin-bottom: 20px;
            color: #333;
        }
        p {
            font-size: 18px;
            color: #6c757d;
            margin-bottom: 30px;
        }
        .btn-primary {
            background-color: #007bff;
            border: none;
            padding: 12px 25px;
            font-size: 16px;
            border-radius: 5px;
            transition: all 0.3s;
        }
        .btn-primary:hover {
            background-color: #0069d9;
            transform: translateY(-2px);
        }
        .alert {
            margin-bottom: 30px;
        }
    </style>
</head>
<body>
    <div class="maintenance-container">
        <div class="maintenance-icon">
            <i class="fas fa-tools"></i>
        </div>
        <h1>System Maintenance</h1>
        
        <?php if (!empty($message)): ?>
        <div class="alert alert-<?php echo $message_type; ?>">
            <?php echo htmlspecialchars($message); ?>
        </div>
        <?php endif; ?>
        
        <p>We're currently performing maintenance on our systems. Please check back soon.</p>
        <p>If this issue persists, please contact our support team.</p>
        
        <a href="index.php" class="btn btn-primary">Try Again</a>
    </div>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 