<?php
// Path to includes and config depends on the calling file's location
$base_path = '';

// Detect if file is being included from pages directory
if (strpos($_SERVER['SCRIPT_FILENAME'], 'pages') !== false) {
    $base_path = '../../';
}

// Include required files
require_once $base_path . 'includes/functions.php';
require_once $base_path . 'config/database.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TaskMaster - Your Task Management Solution</title>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="<?php echo $base_path; ?>assets/css/style.css">
    <!-- Modal Styles -->
    <link rel="stylesheet" href="<?php echo $base_path; ?>assets/css/modals.css">
    <!-- Custom Toast Styles -->
    <style>
        .toast-container {
            z-index: 1060;
        }
        .toast {
            min-width: 300px;
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
            opacity: 1 !important;
        }
        .toast .toast-header {
            padding: 0.5rem 1rem;
        }
        .toast .toast-header.bg-success {
            background-color: #198754 !important;
        }
        .toast .toast-header.bg-danger {
            background-color: #dc3545 !important;
        }
        .toast .toast-header.bg-warning {
            background-color: #ffc107 !important;
        }
        .toast .toast-header.bg-info {
            background-color: #0dcaf0 !important;
        }
        .toast .toast-body {
            padding: 0.75rem 1rem;
            font-size: 0.95rem;
        }
    </style>
</head>
<body>
    <?php include_once $base_path . 'includes/notification_system.php'; ?>
    <div class="container">
        <?php displayMessage(); ?> 