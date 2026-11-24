<?php

declare(strict_types=1);

/**
 * Shared helpers for the chromedriver update scripts.
 *
 * This file contains functions only - no side effects, no top-level code,
 * so it can be safely require()d from multiple scripts.
 *
 * Used by:
 * - scripts/update-chromedriver (the single update script / composer
 *   post-install updater: automatic one-version-only mode + --version pin mode)
 */

const CFT_KNOWN_GOOD_URL = 'https://googlechromelabs.github.io/chrome-for-testing/known-good-versions-with-downloads.json';
const CFT_LAST_KNOWN_GOOD_URL = 'https://googlechromelabs.github.io/chrome-for-testing/last-known-good-versions-with-downloads.json';
const VERSION_HISTORY_URL_TMPL = 'https://versionhistory.googleapis.com/v1/chrome/platforms/{platform}/channels/stable/versions';
const MANIFEST_NAME = 'chromedriver-manifest.json';

/** Map the OS/PHP build of the current host to its Chrome-for-Testing platform identifier. */
function detectPlatform(): string
{
    $os = strtolower(PHP_OS);
    $arch = strtolower(php_uname('m'));

    if (strpos($os, 'linux') !== false) {
        if (in_array($arch, ['arm64', 'aarch64'], true)) {
            return 'linux-arm64';
        }
        return 'linux64';
    }

    if (strpos($os, 'darwin') !== false) {
        if ($arch === 'arm64' || $arch === 'aarch64') {
            return 'mac-arm64';
        }
        return 'mac-x64';
    }

    if (strpos($os, 'win') !== false) {
        return (PHP_INT_SIZE === 8) ? 'win64' : 'win32';
    }

    fwrite(STDERR, 'Error: unsupported operating system: ' . PHP_OS . "\n");
    exit(1);
}

/** Run the installed binary and parse its version, e.g. "ChromeDriver 123.4.5.6". */
function getInstalledVersion(string $binaryPath): ?string
{
    $output = [];
    $exitCode = 1;
    exec(escapeshellarg($binaryPath) . ' --version 2>/dev/null', $output, $exitCode);
    if ($exitCode !== 0 || $output === []) {
        $output = [];
        exec(escapeshellarg($binaryPath) . ' --version', $output, $exitCode);
        if ($exitCode !== 0 || $output === []) {
            return null;
        }
    }
    preg_match('/(\d+\.\d+\.\d+\.\d+)/', implode(' ', $output), $m);
    return $m[1] ?? null;
}

/**
 * Find the smallest stable-channel version strictly greater than $installedVersion
 * (the "immediate next stable version"), using the official Google version history
 * API. Returns null when no newer stable version exists.
 *
 * Returns [version, versionDrift]; versionDrift is always 0 here (exact match).
 */
function findNextStableVersion(string $installedVersion, string $platform): ?array
{
    $urlTemplate = VERSION_HISTORY_URL_TMPL;
    $baseUrl = str_replace('{platform}', versionHistoryPlatform($platform), $urlTemplate);
    // Server-side filter to all stable versions strictly greater than the
    // installed one. The list is returned newest-first, so the LAST entry
    // across all pages is the "immediate next" stable version.
    $filter = '&filter=' . rawurlencode('version>' . $installedVersion);
    $pageSize = 200;
    $pageToken = '';
    $best = null;

    do {
        $url = $baseUrl . '?pageSize=' . $pageSize . $filter
            . ($pageToken !== '' ? '&pageToken=' . rawurlencode($pageToken) : '');
        $data = fetchJson($url);
        if ($data === null || !isset($data['versions'])) {
            fwrite(STDERR, "Error: could not fetch the stable version history.\n");
            exit(1);
        }

        foreach ($data['versions'] as $v) {
            $version = $v['version'] ?? null;
            if ($version !== null) {
                $best = $version; // list descends: this keeps shrinking
            }
        }

        $pageToken = $data['nextPageToken'] ?? '';
    } while ($pageToken !== '');

    return ($best !== null) ? [$best, 0] : null;
}

/** Maps a CfT platform id to the platform slug used by versionhistory.googleapis.com. */
function versionHistoryPlatform(string $platform): string
{
    if (strpos($platform, 'win') === 0) {
        return 'win';
    }
    if (strpos($platform, 'mac') === 0) {
        return 'mac';
    }
    return 'linux';
}

/**
 * Compare two dotted version strings component by component.
 * Returns -1, 0 or 1 like strcmp().
 */
function compareVersions(string $a, string $b): int
{
    $aParts = array_map('intval', explode('.', $a));
    $bParts = array_map('intval', explode('.', $b));
    $len = max(count($aParts), count($bParts));
    for ($i = 0; $i < $len; $i++) {
        $av = $aParts[$i] ?? 0;
        $bv = $bParts[$i] ?? 0;
        if ($av < $bv) {
            return -1;
        }
        if ($av > $bv) {
            return 1;
        }
    }
    return 0;
}

/**
 * Return [version, url] for the current stable chromedriver head version on
 * $platform from the last-known-good data, or null.
 */
function getCftStableVersion(string $platform): ?array
{
    $known = fetchJson(CFT_LAST_KNOWN_GOOD_URL);
    if ($known === null || !isset($known['channels']['Stable'])) {
        return null;
    }
    $stable = $known['channels']['Stable'];
    foreach ($stable['downloads']['chromedriver'] ?? [] as $dl) {
        if (($dl['platform'] ?? '') === $platform) {
            return [$stable['version'], $dl['url']];
        }
    }
    return null;
}

/**
 * Resolve the chromedriver download URL for a version + platform from the
 * Chrome-for-Testing known-good data. Falls back to the nearest available
 * version in the same milestone when the exact version has no chromedriver
 * download. Returns [versionWithDownload, url] or null.
 */
function resolveDownloadUrl(string $version, string $platform): ?array
{
    $known = fetchJson(CFT_KNOWN_GOOD_URL);
    if ($known === null || !isset($known['versions'])) {
        echo "Error: could not fetch the known-good versions list.\n";
        return null;
    }

    $exact = null;
    $nearest = null; // same milestone, closest >= requested version

    foreach ($known['versions'] as $entry) {
        $v = $entry['version'] ?? null;
        if ($v === null) {
            continue;
        }
        $url = null;
        foreach ($entry['downloads']['chromedriver'] ?? [] as $dl) {
            if (($dl['platform'] ?? '') === $platform) {
                $url = $dl['url'] ?? null;
                break;
            }
        }
        if ($url === null) {
            continue;
        }

        if ($v === $version) {
            $exact = [$v, $url];
            break;
        }

        $milestone = explode('.', $v, 2)[0];
        if ($milestone === explode('.', $version, 2)[0] && compareVersions($v, $version) >= 0) {
            // same milestone, version >= requested: keep the smallest one
            if ($nearest === null || compareVersions($v, $nearest[0]) < 0) {
                $nearest = [$v, $url];
            }
        }
    }

    return $exact ?? $nearest;
}

/**
 * Resolve a user-requested version. Accepts a full version ("115.0.5790.170")
 * or a bare milestone ("115"); for a bare milestone it returns the highest
 * version of that milestone that has a chromedriver download for $platform.
 * A full version is returned unchanged (validated later by resolveDownloadUrl).
 * Returns null when the input is malformed or (for a milestone) unresolvable.
 */
function resolveRequestedVersion(string $requested, string $platform): ?string
{
    if ($requested === '' || !preg_match('/^\d+(\.\d+)*$/', $requested)) {
        return null;
    }

    if (strpos($requested, '.') !== false) {
        return $requested;
    }

    // Bare milestone: pick the highest version of that milestone with a download.
    $known = fetchJson(CFT_KNOWN_GOOD_URL);
    if ($known === null || !isset($known['versions'])) {
        echo "Error: could not fetch the known-good versions list.\n";
        return null;
    }

    $best = null;
    foreach ($known['versions'] as $entry) {
        $v = $entry['version'] ?? null;
        if ($v === null) {
            continue;
        }
        if (explode('.', $v, 2)[0] !== $requested) {
            continue;
        }
        foreach ($entry['downloads']['chromedriver'] ?? [] as $dl) {
            if (($dl['platform'] ?? '') === $platform) {
                if ($best === null || compareVersions($v, $best) > 0) {
                    $best = $v;
                }
                break;
            }
        }
    }
    return $best;
}

/**
 * Download the zip, hash-verify it, extract, install the binary and record
 * hashes in the manifest. Returns the process exit code.
 */
function downloadAndInstall(
    string $downloadUrl,
    string $version,
    string $platform,
    ?array $manifest,
    string $binaryPath,
    string $manifestPath
): int {
    $tmpZip = sys_get_temp_dir() . '/chromedriver-' . $version . '-' . $platform . '.zip';

    echo "\nDownloading $downloadUrl\n";
    $zipContent = file_get_contents($downloadUrl);
    if ($zipContent === false) {
        echo "Error: could not download the chromedriver zip file.\n";
        return 1;
    }
    $statusLine = $http_response_header[0] ?? '';
    if (strpos($statusLine, '200') === false) {
        echo "Error: unexpected HTTP response while downloading: $statusLine\n";
        return 1;
    }
    if (file_put_contents($tmpZip, $zipContent) === false) {
        echo "Error: could not write the downloaded zip to $tmpZip\n";
        return 1;
    }

    // --- Hash check (part 2): verify download integrity -----------------
    $zipSha256 = hash_file('sha256', $tmpZip);
    echo "Zip sha256  : $zipSha256\n";

    if ($manifest !== null
        && ($manifest['version'] ?? null) === $version
        && isset($manifest['zip_sha256'])
        && $manifest['zip_sha256'] !== $zipSha256
    ) {
        echo "Error: downloaded zip hash does not match the previously recorded hash for this version (expected {$manifest['zip_sha256']}).\n";
        echo "The download may be corrupted or tampered with. Aborting - existing installation left untouched.\n";
        @unlink($tmpZip);
        return 1;
    }

    // --- Extract to a fresh temporary directory ------------------------
    $extractPath = sys_get_temp_dir() . '/chromedriver_extracted_' . uniqid();
    if (!mkdir($extractPath, 0777, true)) {
        echo "Error: could not create the temporary extraction directory.\n";
        @unlink($tmpZip);
        return 1;
    }

    $isWindows = (strpos($platform, 'win') !== false);
    $executableName = $isWindows ? 'chromedriver.exe' : 'chromedriver';

    $zip = new ZipArchive();
    if ($zip->open($tmpZip) !== true) {
        echo "Error: could not open the zip archive.\n";
        @unlink($tmpZip);
        rrmdir($extractPath);
        return 1;
    }
    if (!$zip->extractTo($extractPath)) {
        echo "Error: could not extract the zip archive.\n";
        $zip->close();
        @unlink($tmpZip);
        rrmdir($extractPath);
        return 1;
    }
    $zip->close();

    $executablePath = findExecutable($extractPath, $executableName);
    if ($executablePath === null) {
        echo "Error: could not find '$executableName' in the extracted files.\n";
        echo "Extracted files:\n";
        print_r(scandir($extractPath));
        @unlink($tmpZip);
        rrmdir($extractPath);
        return 1;
    }

    $binarySha256 = hash_file('sha256', $executablePath);
    echo "Binary sha256: $binarySha256\n";

    // --- Install into bin/ ------------------------------------------------
    $binDir = dirname($binaryPath);
    if (!is_dir($binDir)) {
        mkdir($binDir, 0777, true);
    }

    if (file_exists($binaryPath) && !unlink($binaryPath)) {
        echo "Error: could not remove the existing binary at $binaryPath\n";
        @unlink($tmpZip);
        rrmdir($extractPath);
        return 1;
    }

    if (!rename($executablePath, $binaryPath) && !copy($executablePath, $binaryPath)) {
        echo "Error: could not move the executable into place.\n";
        @unlink($tmpZip);
        rrmdir($extractPath);
        return 1;
    }

    if (!$isWindows) {
        chmod($binaryPath, 0755);
    }

    // --- Record hashes in the manifest ------------------------------------
    $newManifest = [
        'version' => $version,
        'platform' => $platform,
        'url' => $downloadUrl,
        'zip_sha256' => $zipSha256,
        'binary_sha256' => $binarySha256,
        'installed_at' => date('c'),
    ];
    if (file_put_contents($manifestPath, json_encode($newManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n") === false) {
        echo "Error: chromedriver installed, but the manifest could not be written at $manifestPath\n";
        echo "Without the manifest, future integrity checks cannot verify the binary.\n";
    }

    // --- Cleanup ----------------------------------------------------------
    @unlink($tmpZip);
    rrmdir($extractPath);

    echo "Chromedriver $version installed successfully.\n";
    return 0;
}

/** Recursively locate $name inside $dir (searches the versioned subdirectory). */
function findExecutable(string $dir, string $name): ?string
{
    $items = scandir($dir);
    if ($items === false) {
        return null;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        if (is_dir($path) && !is_link($path)) {
            $found = findExecutable($path, $name);
            if ($found !== null) {
                return $found;
            }
        } elseif ($item === $name) {
            return $path;
        }
    }
    return null;
}

/** Recursively delete a directory and its contents. */
function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        if (is_dir($path) && !is_link($path)) {
            rrmdir($path);
        } else {
            unlink($path);
        }
    }
    rmdir($dir);
}

/** Load the local hash manifest, or null when it does not exist / is invalid. */
function loadManifest(string $manifestPath): ?array
{
    if (!is_file($manifestPath)) {
        return null;
    }
    $raw = file_get_contents($manifestPath);
    if ($raw === false) {
        return null;
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['binary_sha256'], $data['version'])) {
        echo "Warning: manifest at $manifestPath is invalid - ignoring it.\n";
        return null;
    }
    return $data;
}

/** GET a URL and decode its JSON body; null on failure. */
function fetchJson(string $url): ?array
{
    $raw = file_get_contents($url);
    if ($raw === false) {
        return null;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}
