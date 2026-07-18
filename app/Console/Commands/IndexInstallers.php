<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Index the installers by listing them over HTTP from the Synology Web Station.
 *
 * companies.installers_path holds the base URL (or host/path — http:// is added).
 * The web server's directory listing IS the catalog: whatever files are there
 * are what /install offers. When a machine installs, its bootstrap curls the
 * file to a tmp folder, runs it, and cleans up — nothing is mounted.
 *
 * HTTP basic-auth credentials come from env (INSTALLERS_HTTP_USER /
 * INSTALLERS_HTTP_PASS) and are NEVER stored in code or the database.
 */
class IndexInstallers extends Command
{
    protected $signature = 'installers:index {--path=}';
    protected $description = 'Index the installers over HTTP into the installers table';

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

        $this->info(count($rows) . ' installers indexed from ' . $path);
        return self::SUCCESS;
    }

    /** Base URL from the stored path (http:// added if missing, trailing slash ensured). */
    public static function baseUrl(string $path): string
    {
        if (! preg_match('#^https?://#i', $path)) {
            $path = 'http://' . ltrim($path, '/');
        }
        return rtrim($path, '/') . '/';
    }

    /**
     * Walk the Web Station directory listing over HTTP (one level of subfolders).
     * Returns [rows, errorOrNull].
     */
    public static function scan(string $path): array
    {
        $base = self::baseUrl($path);
        $user = env('INSTALLERS_HTTP_USER');
        $pass = env('INSTALLERS_HTTP_PASS');

        $get = function (string $url) use ($user, $pass) {
            $req = Http::timeout(12)->withOptions(['verify' => false]);
            if ($user) $req = $req->withBasicAuth($user, $pass ?? '');
            return $req->get($url);
        };

        $root = $get($base);
        if ($root->status() === 401) {
            return [[], 'The installers URL needs a login — set INSTALLERS_HTTP_USER / INSTALLERS_HTTP_PASS on the server.'];
        }
        if (! $root->ok()) {
            return [[], "Could not read {$base} (HTTP {$root->status()}). Enable directory listing in Web Station for that folder."];
        }

        $rows = [];
        foreach (self::links($root->body()) as $entry) {
            if ($entry['dir']) {
                // one level deep: list the subfolder's files
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

    /** Parse <a href> links from a directory-listing page. Skips parent/sort links. */
    private static function links(string $html): array
    {
        if (! preg_match_all('/<a\s[^>]*href="([^"]+)"[^>]*>/i', $html, $m)) return [];
        $out = [];
        foreach ($m[1] as $href) {
            $href = html_entity_decode($href);
            if ($href === '' || $href[0] === '?' || $href[0] === '#') continue;   // sort/anchor links
            if (str_starts_with($href, '/') || str_contains($href, '://')) continue; // absolute / parent
            if (str_starts_with($href, '..')) continue;
            $name = rawurldecode($href);
            $out[] = ['name' => rtrim($name, '/') . (str_ends_with($href, '/') ? '/' : ''), 'dir' => str_ends_with($href, '/')];
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
                : (preg_match('/(^|[^0-9])(64|x64)([^0-9]|$)/i', $name) ? '64' : null),
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
