<?php
/**
 * Script to check and fix file paths in PHP files
 * This will ensure all PHP files work with the new directory structure
 */

// Directory to scan
$scanDir = './pages';

// Get all PHP files recursively
function getPHPFiles($dir) {
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

// Fix paths in a file
function fixPaths($file) {
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
    
    // Fix common include paths
    $patterns = [
        '/require_once\s+[\'"]includes\/functions\.php[\'"]/i' => "require_once '{$relativePath}includes/functions.php'",
        '/require_once\s+[\'"]config\/database\.php[\'"]/i' => "require_once '{$relativePath}config/database.php'",
        '/include\s+[\'"]includes\/header\.php[\'"]/i' => "include '{$relativePath}includes/header.php'",
        '/include\s+[\'"]includes\/footer\.php[\'"]/i' => "include '{$relativePath}includes/footer.php'",
        '/src=["\'](assets\/[^"\']+)["\']/' => "src=\"{$relativePath}$1\"",
        '/href=["\'](assets\/[^"\']+)["\']/' => "href=\"{$relativePath}$1\"",
    ];
    
    // Fix redirect paths
    $redirectPatterns = [
        '/redirect\([\'"]dashboard\.php[\'"]\)/i' => "redirect('../core/dashboard.php')",
        '/redirect\([\'"]login\.php[\'"]\)/i' => "redirect('../auth/login.php')",
        '/redirect\([\'"]register\.php[\'"]\)/i' => "redirect('../auth/register.php')",
        '/redirect\([\'"]projects\.php[\'"]\)/i' => "redirect('../projects/projects.php')",
        '/redirect\([\'"]tasks\.php[\'"]\)/i' => "redirect('../tasks/tasks.php')",
        '/redirect\([\'"]profile\.php[\'"]\)/i' => "redirect('../users/profile.php')",
        '/redirect\([\'"]connections\.php[\'"]\)/i' => "redirect('../users/connections.php')",
        '/redirect\([\'"]settings\.php[\'"]\)/i' => "redirect('../core/settings.php')",
    ];
    
    // Fix link paths
    $linkPatterns = [
        '/href=["\'](dashboard\.php)["\']/' => "href=\"../core/dashboard.php\"",
        '/href=["\'](login\.php)["\']/' => "href=\"../auth/login.php\"",
        '/href=["\'](register\.php)["\']/' => "href=\"../auth/register.php\"",
        '/href=["\'](projects\.php)["\']/' => "href=\"../projects/projects.php\"",
        '/href=["\'](tasks\.php)["\']/' => "href=\"../tasks/tasks.php\"",
        '/href=["\'](profile\.php)["\']/' => "href=\"../users/profile.php\"",
        '/href=["\'](connections\.php)["\']/' => "href=\"../users/connections.php\"",
        '/href=["\'](settings\.php)["\']/' => "href=\"../core/settings.php\"",
    ];
    
    // Apply all patterns
    $allPatterns = array_merge($patterns, $redirectPatterns, $linkPatterns);
    $newContent = $content;
    
    foreach ($allPatterns as $pattern => $replacement) {
        $newContent = preg_replace($pattern, $replacement, $newContent);
    }
    
    // Check if content was modified
    if ($newContent !== $content) {
        file_put_contents($file, $newContent);
        return true;
    }
    
    return false;
}

// Main execution
echo "Scanning PHP files in $scanDir...\n";
$phpFiles = getPHPFiles($scanDir);
echo "Found " . count($phpFiles) . " PHP files.\n\n";

$fixedFiles = 0;
foreach ($phpFiles as $file) {
    if (fixPaths($file)) {
        echo "Fixed paths in: $file\n";
        $fixedFiles++;
    }
}

echo "\nFixed paths in $fixedFiles files.\n";
?> 