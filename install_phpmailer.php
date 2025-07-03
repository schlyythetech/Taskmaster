<?php
/**
 * PHPMailer Manual Installation Script
 * 
 * This script will manually download and install PHPMailer if Composer is not available.
 */

// Define the PHPMailer version to download
$phpmailer_version = '6.8.1';
$phpmailer_url = "https://github.com/PHPMailer/PHPMailer/archive/v{$phpmailer_version}.zip";
$download_path = __DIR__ . '/phpmailer.zip';
$extract_path = __DIR__ . '/vendor';

// Create vendor directory if it doesn't exist
if (!is_dir($extract_path)) {
    mkdir($extract_path, 0755, true);
}

echo "Installing PHPMailer v{$phpmailer_version}...\n";

// Download PHPMailer
echo "Downloading PHPMailer...\n";
$file_content = file_get_contents($phpmailer_url);
if ($file_content === false) {
    die("Failed to download PHPMailer from {$phpmailer_url}\n");
}

// Save the downloaded file
file_put_contents($download_path, $file_content);
echo "Download complete.\n";

// Extract the ZIP file
echo "Extracting files...\n";
$zip = new ZipArchive();
if ($zip->open($download_path) === true) {
    $zip->extractTo($extract_path);
    $zip->close();
    echo "Extraction complete.\n";
} else {
    die("Failed to extract PHPMailer archive.\n");
}

// Rename the extracted directory
$extracted_dir = $extract_path . "/PHPMailer-{$phpmailer_version}";
$phpmailer_dir = $extract_path . "/phpmailer";

if (is_dir($phpmailer_dir)) {
    echo "Removing old PHPMailer directory...\n";
    removeDirectory($phpmailer_dir);
}

rename($extracted_dir, $phpmailer_dir);

// Create autoload.php
echo "Creating autoloader...\n";
$autoload_content = <<<'EOT'
<?php
// PHPMailer autoloader
require_once __DIR__ . '/phpmailer/src/Exception.php';
require_once __DIR__ . '/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/phpmailer/src/SMTP.php';
EOT;

file_put_contents($extract_path . '/autoload.php', $autoload_content);

// Clean up
echo "Cleaning up...\n";
unlink($download_path);

echo "PHPMailer installation complete!\n";
echo "You can now use PHPMailer in your application.\n";

/**
 * Recursively remove a directory and its contents
 * 
 * @param string $dir Directory path
 * @return bool Success or failure
 */
function removeDirectory($dir) {
    if (!is_dir($dir)) {
        return false;
    }
    
    $files = array_diff(scandir($dir), array('.', '..'));
    
    foreach ($files as $file) {
        $path = $dir . '/' . $file;
        
        if (is_dir($path)) {
            removeDirectory($path);
        } else {
            unlink($path);
        }
    }
    
    return rmdir($dir);
}
?> 