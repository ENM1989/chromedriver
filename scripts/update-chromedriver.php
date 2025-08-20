<?php

$jsonUrl = 'https://googlechromelabs.github.io/chrome-for-testing/last-known-good-versions-with-downloads.json';
$binDir = __DIR__ . '/../bin';
$tmpDir = sys_get_temp_dir();

echo "Fetching latest chromedriver info...\n";
$jsonData = file_get_contents($jsonUrl);
if ($jsonData === false) {
    echo "Error: Could not fetch JSON data from $jsonUrl\n";
    exit(1);
}

$data = json_decode($jsonData, true);
if ($data === null) {
    echo "Error: Could not decode JSON data.\n";
    exit(1);
}

// Determine platform
$os = strtolower(PHP_OS);
$arch = strtolower(php_uname('m'));
$platform = '';

if (strpos($os, 'linux') !== false) {
    $platform = 'linux64';
} elseif (strpos($os, 'darwin') !== false) {
    if ($arch === 'arm64' || $arch === 'aarch64') {
        $platform = 'mac-arm64';
    } else {
        $platform = 'mac-x64';
    }
} elseif (strpos($os, 'win') !== false) {
    // Check if it's 64-bit PHP
    if (PHP_INT_SIZE === 8) {
        $platform = 'win64';
    } else {
        $platform = 'win32';
    }
} else {
    echo "Error: Unsupported operating system: $os\n";
    exit(1);
}

echo "Detected platform: $platform\n";

// Find chromedriver download URL
$chromedriverUrl = '';
if (isset($data['channels']['Stable']['downloads']['chromedriver'])) {
    foreach ($data['channels']['Stable']['downloads']['chromedriver'] as $download) {
        if ($download['platform'] === $platform) {
            $chromedriverUrl = $download['url'];
            break;
        }
    }
}

if (empty($chromedriverUrl)) {
    echo "Error: Could not find a download URL for platform '$platform'.\n";
    exit(1);
}

echo "Found download URL: $chromedriverUrl\n";

// Download the zip file
$zipFileName = basename($chromedriverUrl);
$zipFilePath = $tmpDir . '/' . $zipFileName;

echo "Downloading $zipFileName to $zipFilePath...\n";
$zipContent = file_get_contents($chromedriverUrl);
if ($zipContent === false) {
    echo "Error: Could not download the chromedriver zip file.\n";
    exit(1);
}
file_put_contents($zipFilePath, $zipContent);

// Unzip the file
$zip = new ZipArchive();
if ($zip->open($zipFilePath) === TRUE) {
    $extractPath = $tmpDir . '/chromedriver_extracted';
    if (!is_dir($extractPath)) {
        mkdir($extractPath, 0777, true);
    }
    $zip->extractTo($extractPath);
    $zip->close();
    echo "Successfully extracted to $extractPath\n";
} else {
    echo "Error: Could not open the zip archive.\n";
    exit(1);
}

// Find chromedriver executable
$executableName = (strpos($platform, 'win') !== false) ? 'chromedriver.exe' : 'chromedriver';
$executablePath = '';

// The executable is inside a directory like 'chromedriver-linux64'
$extractedFiles = scandir($extractPath);
foreach ($extractedFiles as $file) {
    $fullPath = $extractPath . '/' . $file;
    if (is_dir($fullPath) && $file !== '.' && $file !== '..') {
        $potentialPath = $fullPath . '/' . $executableName;
        if (file_exists($potentialPath)) {
            $executablePath = $potentialPath;
            break;
        }
    }
}


if (empty($executablePath)) {
    echo "Error: Could not find '$executableName' in the extracted files.\n";
    // Let's list the extracted files for debugging
    echo "Extracted files:\n";
    print_r(scandir($extractPath));
    exit(1);
}

// Move the executable to the bin directory
// The file needs to be named 'chromedriver' to match the entry in composer.json
$newExecutablePath = $binDir . '/chromedriver';

// Remove old binary if it exists
if (file_exists($newExecutablePath)) {
    unlink($newExecutablePath);
}


echo "Moving $executablePath to $newExecutablePath...\n";
if (!is_dir($binDir)) {
    mkdir($binDir, 0777, true);
}
if (!rename($executablePath, $newExecutablePath)) {
     echo "Error: Could not move the executable. Trying to copy instead.\n";
     if (!copy($executablePath, $newExecutablePath)) {
        echo "Error: Could not copy the executable.\n";
        exit(1);
     }
}


// Set permissions
if (strpos($platform, 'win') === false) {
    echo "Setting executable permissions...\n";
    chmod($newExecutablePath, 0755);
}

// Cleanup
echo "Cleaning up temporary files...\n";
unlink($zipFilePath);
// A recursive delete for the extracted directory
function rrmdir($dir) {
   if (is_dir($dir)) {
     $objects = scandir($dir);
     foreach ($objects as $object) {
       if ($object != "." && $object != "..") {
         if (is_dir($dir. DIRECTORY_SEPARATOR .$object) && !is_link($dir. DIRECTORY_SEPARATOR .$object))
           rrmdir($dir. DIRECTORY_SEPARATOR .$object);
         else
           unlink($dir. DIRECTORY_SEPARATOR .$object);
       }
     }
     rmdir($dir);
   }
}
rrmdir($extractPath);


echo "Chromedriver updated successfully!\n";
exit(0);
