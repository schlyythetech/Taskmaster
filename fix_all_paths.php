<?php
/**
 * Script to fix all path issues in PHP files
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

// Function to fix paths in a file
function fixPaths($file) {
    echo "Processing file: $file\n";
    
    $content = file_get_contents($file);
    $original = $content;
    
    // Determine if this is a file in the pages directory
    if (strpos($file, 'pages') === false) {
        echo "  Skipping - not in pages directory\n";
        return false;
    }
    
    // Determine relative path based on file location
    $relativePath = '../../';
    
    // Fix require/include paths
    $patterns = [
        '/(require_once|require|include_once|include)\s+[\'"]includes\/([^"\']+)[\'"]/i' => "$1 '{$relativePath}includes/$2'",
        '/(require_once|require|include_once|include)\s+[\'"]config\/([^"\']+)[\'"]/i' => "$1 '{$relativePath}config/$2'",
    ];
    
    // Fix asset paths
    $assetPatterns = [
        '/src=["\'](assets\/[^"\']+)["\']/' => "src=\"{$relativePath}assets/$1\"",
        '/href=["\'](assets\/[^"\']+)["\']/' => "href=\"{$relativePath}assets/$1\"",
    ];
    
    // Apply patterns
    foreach ($patterns as $pattern => $replacement) {
        $content = preg_replace($pattern, $replacement, $content);
    }
    
    foreach ($assetPatterns as $pattern => $replacement) {
        $content = preg_replace($pattern, $replacement, $content);
    }
    
    // Check if content was modified
    if ($content !== $original) {
        file_put_contents($file, $content);
        echo "  Fixed paths in file\n";
        return true;
    }
    
    echo "  No changes needed\n";
    return false;
}

// Main execution
echo "Fixing paths in PHP files...\n";

// Fix paths in pages directory
$phpFiles = getAllPHPFiles('./pages');
echo "Found " . count($phpFiles) . " PHP files in pages directory.\n\n";

$fixedFiles = 0;
foreach ($phpFiles as $file) {
    if (fixPaths($file)) {
        $fixedFiles++;
    }
}

echo "\nFixed paths in $fixedFiles files.\n";
?> 