<?php
/**
 * installers.php — AssetMost installer listing for Synology Web Station.
 *
 * WHAT IT DOES: lists every file in this folder (and subfolders) as JSON, so
 * AssetMost can build the /install catalog with a plain unauthenticated GET.
 * The Web Station portal already serves the files themselves for the bench to
 * curl at download time — this page just tells AssetMost what's there.
 *
 * INSTALL:
 *   1. Save this file as  installers.php  in the SAME folder Web Station serves
 *      as your Installers portal (the one that answers on :8080).
 *   2. In Web Station, make sure that service has a PHP backend assigned
 *      (Web Station > Web Service > your service > PHP 8.x). PHP is already
 *      installed; it just has to be enabled for this service.
 *   3. Test in a browser:  http://files.example.com:8080/installers.php
 *      You should see a JSON list of your installers.
 *   4. In AssetMost > Settings > Installers, set the URL to the portal base
 *      (http://files.example.com:8080) and hit "Scan now".
 *
 * No authentication, no database, read-only. Safe to leave in place.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$base = __DIR__;
$self = basename(__FILE__);
$out = [];

try {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    $it->setMaxDepth(3);
    foreach ($it as $f) {
        $name = $f->getFilename();
        if ($name === '' || $name[0] === '.' || $name === $self) {
            continue;
        }
        // .app / .rtfd are bundles (directories) on macOS — list them, skip their guts.
        $rel = ltrim(str_replace($base, '', $f->getPathname()), '/\\');
        $rel = str_replace('\\', '/', $rel);
        $out[] = [
            'name' => $name,
            'relative_path' => $rel,
            'size' => $f->isFile() ? $f->getSize() : null,
        ];
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

// De-dupe by path and return.
$seen = [];
$files = [];
foreach ($out as $row) {
    if (isset($seen[$row['relative_path']])) {
        continue;
    }
    $seen[$row['relative_path']] = true;
    $files[] = $row;
}
echo json_encode($files, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
