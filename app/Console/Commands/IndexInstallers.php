<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Index the installers by reading the Synology Web Station directory listing
 * over plain HTTP — unauthenticated, no mount, no API session.
 *
 * companies.installers_path holds the base URL (or host/path — http:// is added).
 * The directory listing IS the catalog: whatever files are served there are what
 * /install offers. When a machine installs, its bootstrap simply curls the file
 * to a tmp folder, runs it, and cleans up.
 */
class IndexInstallers extends Command
{
    protected $signature = 'installers:index {--path=}';
    protected $description = 'Index the installers from the Web Station listing into the installers table';

    public function handle(): int
    {
        $path = $this->option('path')
            ?: DB::table('companies')->whereNotNull('installers_path')->value('installers_path');
        if (! $path) {
            $this->error('No installers path set (Settings > Installers).');
            return self::FAILURE;
        }

        [$rows, $err] = self::scan($path);
        if ($err) {
            $this->error($err);
            return self::FAILURE;
        }

        $now = Carbon::now();
        DB::transaction(function () use ($rows, $now) {
            DB::table('installers')->delete();
            foreach (array_chunk(array_map(fn ($r) => $r + ['created_at' => $now, 'updated_at' => $now, 'indexed_at' => $now], $rows), 200) as $chunk) {
                DB::table('installers')->insert($chunk);
            }
        });

        $this->info(count($rows) . ' installers indexed from ' . self::baseUrl($path));
        return self::SUCCESS;
    }

    /** Base URL from the stored path (http:// added, trailing slash ensured). */
    public static function baseUrl(string $path): string
    {
        if (! preg_match('#^https?://#i', $path)) {
            $path = 'http://' . ltrim($path, '/');
        }
        return rtrim($path, '/') . '/';
    }

    /**
     * Walk the Web Station directory listing over HTTP (one level of subfolders).
     * Unauthenticated. Returns [rows, errorOrNull].
     */
    public static function scan(string $path): array
    {
        $base = self::baseUrl($path);
        $get = fn (string $url) => Http::timeout(12)->withOptions(['verify' => false])->get($url);

        $root = $get($base);
        if (! $root->ok()) {
            return [[], "Could not read {$base} (HTTP {$root->status()}). In Web Station, serve this folder and turn on directory listing."];
        }
        // DSM's port-80 redirect stub isn't a listing — catch it early.
        if (str_contains($root->body(), 'prefer_https') && ! self::links($root->body())) {
            return [[], "That URL redirects to DSM, not a file listing. Point at the Web Station virtual host / folder that serves the files."];
        }

        $rows = [];
        foreach (self::links($root->body()) as $entry) {
            if ($entry['dir']) {
                $sub = $get($base . rawurlencode(rtrim($entry['name'], '/')) . '/');
                if ($sub->ok()) {
                    foreach (self::links($sub->body()) as $f) {
                        if ($f['dir']) continue;
                        $rel = rtrim($entry['name'], '/') . '/' . $f['name'];
                        $rows[$rel] = self::row($f['name'], $rel);
                    }
                }
            } else {
                $rows[$entry['name']] = self::row($entry['name'], $entry['name']);
            }
        }
        return [array_values(array_filter($rows)), null];
    }

    /** Parse <a href> file/dir links from a directory-listing page. */
    private static function links(string $html): array
    {
        if (! preg_match_all('/<a\s[^>]*href="([^"]+)"[^>]*>/i', $html, $m)) return [];
        $out = [];
        foreach ($m[1] as $href) {
            $href = html_entity_decode($href);
            if ($href === '' || $href[0] === '?' || $href[0] === '#') continue;      // sort/anchor
            if (str_starts_with($href, '/') || str_contains($href, '://')) continue;  // absolute / parent
            if (str_starts_with($href, '..')) continue;
            $name = rawurldecode(rtrim($href, '/'));
            $out[] = ['name' => $name, 'dir' => str_ends_with($href, '/')];
        }
        return $out;
    }

    private static function row(string $name, string $rel): ?array
    {
        $name = rtrim($name, '/');
        if ($name === '' || $name[0] === '.') return null;
        return [
            'name' => $name,
            'relative_path' => $rel,
            'platform' => self::platform($name, $rel),
            'arch' => preg_match('/(^|[^0-9])(32|x86)([^0-9]|$)/i', $name) ? '32'
                : (preg_match('/(^|[^0-9])(arm64|aarch64|64|x64)([^0-9]|$)/i', $name) ? '64' : null),
            'is_dir' => 0,
            'size_bytes' => null,
        ];
    }

    private static function platform(string $name, string $path): string
    {
        if (preg_match('/\.(dmg|pkg|app|mpkg)$/i', $name)) return 'Mac';
        if (preg_match('/\.(exe|msi|appx|msix)$/i', $name)) return 'Windows';
        if (preg_match('#(^|/)(mac|macos|osx|apple)(/|$)#i', $path)) return 'Mac';
        if (preg_match('#(^|/)(win|windows|pc)(/|$)#i', $path)) return 'Windows';
        return 'other';
    }
}
