<?php
/**
 * Script to update paths in all PHP files in the project
 * This will ensure all PHP files work with the new directory structure
 */

// Function to get all PHP files recursively
function getAllPHPFiles($dir) {
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }
    
    return $files;
}

// Function to update paths in a file
function updatePaths($file) {
    $content = file_get_contents($file);
    $modified = false;
    $relativePath = '';
    
    // Determine relative path based on file location
    if (strpos($file, 'pages/') !== false) {
        $parts = explode('pages/', $file);
        if (isset($parts[1])) {
            $subdir = explode('/', $parts[1])[0];
            $relativePath = '../../';
        }
    }
    
    // Skip if this is a root file or not in pages directory
    if (empty($relativePath)) {
        return false;
    }
    
    // Update include/require paths
    $patterns = [
        '/require_once\s+[\'"]includes\/([^"\']+)[\'"]/i' => "require_once '{$relativePath}includes/$1'",
        '/require\s+[\'"]includes\/([^"\']+)[\'"]/i' => "require '{$relativePath}includes/$1'",
        '/include_once\s+[\'"]includes\/([^"\']+)[\'"]/i' => "include_once '{$relativePath}includes/$1'",
        '/include\s+[\'"]includes\/([^"\']+)[\'"]/i' => "include '{$relativePath}includes/$1'",
        
        '/require_once\s+[\'"]config\/([^"\']+)[\'"]/i' => "require_once '{$relativePath}config/$1'",
        '/require\s+[\'"]config\/([^"\']+)[\'"]/i' => "require '{$relativePath}config/$1'",
        
        '/src=["\'](assets\/[^"\']+)["\']/' => "src=\"{$relativePath}$1\"",
        '/href=["\'](assets\/[^"\']+)["\']/' => "href=\"{$relativePath}$1\"",
    ];
    
    // Update redirect paths
    $redirectPatterns = [
        '/redirect\([\'"]([^\/\'"]+)\.php[\'"]\)/i' => function($matches) use ($file) {
            $target = $matches[1];
            
            // Determine which directory the target should be in
            $targetDir = '';
            if (in_array($target, ['login', 'register', 'logout', 'reset-password'])) {
                $targetDir = '../auth/';
            } elseif (in_array($target, ['dashboard', 'settings', 'index'])) {
                $targetDir = '../core/';
            } elseif (in_array($target, ['projects', 'create_project', 'view_project', 'edit_project', 'accept_invitation'])) {
                $targetDir = '../projects/';
            } elseif (in_array($target, ['tasks', 'create_task', 'view_task', 'edit_task'])) {
                $targetDir = '../tasks/';
            } elseif (in_array($target, ['profile', 'connections'])) {
                $targetDir = '../users/';
            } elseif (in_array($target, ['admin'])) {
                $targetDir = '../admin/';
            } else {
                // Default to core for unknown pages
                $targetDir = '../core/';
            }
            
            return "redirect('{$targetDir}{$target}.php')";
        }
    ];
    
    // Update link paths
    $linkPatterns = [
        '/href=["\']((?!http|\/\/|#|\?)[^\/\'"]+\.php)["\']/' => function($matches) use ($file) {
            $target = $matches[1];
            $targetName = str_replace('.php', '', $target);
            
            // Determine which directory the target should be in
            $targetDir = '';
            if (in_array($targetName, ['login', 'register', 'logout', 'reset-password'])) {
                $targetDir = '../auth/';
            } elseif (in_array($targetName, ['dashboard', 'settings', 'index'])) {
                $targetDir = '../core/';
            } elseif (in_array($targetName, ['projects', 'create_project', 'view_project', 'edit_project', 'accept_invitation'])) {
                $targetDir = '../projects/';
            } elseif (in_array($targetName, ['tasks', 'create_task', 'view_task', 'edit_task'])) {
                $targetDir = '../tasks/';
            } elseif (in_array($targetName, ['profile', 'connections'])) {
                $targetDir = '../users/';
            } elseif (in_array($targetName, ['admin'])) {
                $targetDir = '../admin/';
            } else {
                // Default to core for unknown pages
                $targetDir = '../core/';
            }
            
            return "href=\"{$targetDir}{$target}\"";
        }
    ];
    
    // Apply standard patterns
    $newContent = $content;
    foreach ($patterns as $pattern => $replacement) {
        $newContent = preg_replace($pattern, $replacement, $newContent);
    }
    
    // Apply callback patterns
    foreach ($redirectPatterns as $pattern => $callback) {
        $newContent = preg_replace_callback($pattern, $callback, $newContent);
    }
    
    foreach ($linkPatterns as $pattern => $callback) {
        $newContent = preg_replace_callback($pattern, $callback, $newContent);
    }
    
    // Check if content was modified
    if ($newContent !== $content) {
        file_put_contents($file, $newContent);
        return true;
    }
    
    return false;
}

// Main execution
echo "Updating paths in PHP files...\n";

// Update paths in pages directory
$phpFiles = getAllPHPFiles('./pages');
echo "Found " . count($phpFiles) . " PHP files in pages directory.\n\n";

$updatedFiles = 0;
foreach ($phpFiles as $file) {
    if (updatePaths($file)) {
        echo "Updated paths in: $file\n";
        $updatedFiles++;
    }
}

echo "\nUpdated paths in $updatedFiles files.\n";
echo "Path update completed.\n";
?> 