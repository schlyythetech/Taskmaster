<?php
// List of files to update
$files = [
    'dashboard.php',
    'projects.php',
    'tasks.php',
    'profile.php',
    'connections.php',
    'settings.php',
    'view_project.php'
];

// Regular expression pattern to find the notification link
$pattern = '/<div class="notifications">\s*<a href="#"(.*?)><i class="fas fa-bell"><\/i><\/a>\s*<\/div>/s';

// Replacement HTML with the notification-bell class
$replacement = '<div class="notifications">
            <div class="notification-bell-container">
                <a href="#" class="notification-bell"$1><i class="fas fa-bell"></i></a>
            </div>
        </div>';

// Loop through files and update them
foreach ($files as $file) {
    if (file_exists($file)) {
        $content = file_get_contents($file);
        
        // Check if the pattern exists in the file
        if (preg_match($pattern, $content)) {
            // Replace the pattern
            $updated_content = preg_replace($pattern, $replacement, $content);
            
            // Write the updated content back to the file
            file_put_contents($file, $updated_content);
            
            echo "Updated notification bell in $file<br>";
        } else {
            echo "Pattern not found in $file<br>";
        }
    } else {
        echo "File not found: $file<br>";
    }
}

echo "All files processed!";
?> 