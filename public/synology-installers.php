<?php
// installers.php — lists installers in this folder as JSON for AssetMost.
// Treats .app/.pkg/.dmg/.exe etc as single installers (macOS bundles are folders).
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

function is_installer($name) {
    return (bool) preg_match('/\.(app|pkg|mpkg|dmg|bundle|exe|msi|appx|msix|zip|iso|rtfd)$/i', $name);
}
function walk($dir, $baseLen, &$out, &$seen, $depth) {
    $entries = @scandir($dir);
    if (!$entries) return;
    foreach ($entries as $name) {
        if ($name === '' || $name[0] === '.' || $name[0] === '@') continue;
        if ($name === 'installers.php' || $name === 'installers.json') continue;
        $full = $dir . '/' . $name;
        if (strpos($full, '@eaDir') !== false || strpos($full, '#recycle') !== false) continue;
        $rel = str_replace('\\', '/', ltrim(substr($full, $baseLen), '/\\'));
        $isDir = is_dir($full);
        if ($isDir && !is_installer($name) && $depth < 2) {
            walk($full, $baseLen, $out, $seen, $depth + 1);          // real subfolder
        } else {
            if (isset($seen[$rel])) continue; $seen[$rel] = true;    // installer (file or bundle)
            $out[] = ['name' => $name, 'relative_path' => $rel, 'size' => is_file($full) ? filesize($full) : null];
        }
    }
}
$base = __DIR__; $out = []; $seen = [];
walk($base, strlen($base), $out, $seen, 0);
echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
