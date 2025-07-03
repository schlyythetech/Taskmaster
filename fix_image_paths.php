<?php
/**
 * Script to fix duplicate 'assets/' paths in image tags
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

// Function to fix image paths in a file
function fixImagePaths($file) {
    $content = file_get_contents($file);
    $original = $content;
    
    // Fix duplicate assets paths
    $patterns = [
        '/src=["\'](\.\.\/)*assets\/assets\/assets\/([^"\']+)["\']/' => 'src="../../assets/$2"',
        '/href=["\'](\.\.\/)*assets\/assets\/assets\/([^"\']+)["\']/' => 'href="../../assets/$2"',
        '/src=["\'](\.\.\/)*assets\/assets\/([^"\']+)["\']/' => 'src="../../assets/$2"',
        '/href=["\'](\.\.\/)*assets\/assets\/([^"\']+)["\']/' => 'href="../../assets/$2"',
    ];
    
    // Apply patterns
    foreach ($patterns as $pattern => $replacement) {
        $content = preg_replace($pattern, $replacement, $content);
    }
    
    // Check if content was modified
    if ($content !== $original) {
        file_put_contents($file, $content);
        return true;
    }
    
    return false;
}

// Function to check all image paths in a file
function checkImagePaths($file) {
    $content = file_get_contents($file);
    
    // Find all image paths
    preg_match_all('/src=["\'](.*?)["\']/', $content, $matches);
    
    $imagePaths = [];
    foreach ($matches[1] as $path) {
        if (strpos($path, 'assets') !== false) {
            $imagePaths[] = $path;
        }
    }
    
    return $imagePaths;
}

// Main execution
echo "Fixing image paths in PHP files...\n";

// Fix paths in pages directory
$phpFiles = getAllPHPFiles('./pages');
echo "Found " . count($phpFiles) . " PHP files in pages directory.\n\n";

$fixedFiles = 0;
foreach ($phpFiles as $file) {
    if (fixImagePaths($file)) {
        echo "Fixed image paths in: $file\n";
        $fixedFiles++;
    }
}

echo "\nFixed image paths in $fixedFiles files.\n";

// Check remaining image paths
echo "\nChecking remaining image paths...\n";
$problemFiles = [];

foreach ($phpFiles as $file) {
    $imagePaths = checkImagePaths($file);
    
    if (!empty($imagePaths)) {
        foreach ($imagePaths as $path) {
            if (strpos($path, 'assets/assets') !== false) {
                $problemFiles[$file][] = $path;
            }
        }
    }
}

if (!empty($problemFiles)) {
    echo "\nFiles with potential path issues:\n";
    foreach ($problemFiles as $file => $paths) {
        echo "$file:\n";
        foreach ($paths as $path) {
            echo "  - $path\n";
        }
    }
} else {
    echo "\nNo remaining path issues found.\n";
}
?> 