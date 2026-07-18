<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Index the installers by curling a tiny PHP listing page on the Synology Web
 * Station — unauthenticated, no mount, no API, no nginx autoindex.
 *
 * companies.installers_url is the Web Station base (e.g. http://files.example.com:8080).
 * The app GETs {base}/installers.php which returns the folder as JSON; the same
 * base serves the files, so the bench downloads with a plain curl:
 *   {base}/{relative_path}
 *
 * The one-file PHP page to drop in the Installers web folder ships in the repo
 * at public/synology-installers.php (also printed by `installers:php`).
 */
class IndexInstallers extends Command
{
    protected $signature = 'installers:index {--url=}';
    protected $description = 'Index the installers from the Synology PHP listing page';

    public function handle(): int
    {
        $url = $this->option('url')
            ?: DB::table('companies')->whereNotNull('installers_url')->value('installers_url');
        if (! $url) {
            $this->error('No installers URL set (Settings > Installers).');
            return self::FAILURE;
        }

        [$rows, $err] = self::scan($url);
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

        $this->info(count($rows) . ' installers indexed from ' . $url);
        return self::SUCCESS;
    }

    public static function endpoint(string $baseUrl): string
    {
        if (! preg_match('#^https?://#i', $baseUrl)) {
            $baseUrl = 'http://' . $baseUrl;
        }
        return rtrim($baseUrl, '/') . '/installers.php';
    }

    /** @return array{0: array, 1: ?string} [rows, errorOrNull] */
    public static function scan(string $baseUrl): array
    {
        $res = Http::timeout(15)->withOptions(['verify' => false])->acceptJson()->get(self::endpoint($baseUrl));
        if (! $res->ok()) {
            return [[], 'Could not reach ' . self::endpoint($baseUrl) . " (HTTP {$res->status()}). Put installers.php in the Installers web folder and assign PHP to that Web Station service."];
        }
        $files = $res->json();
        if (! is_array($files)) {
            return [[], 'installers.php did not return JSON — check PHP is enabled for that Web Station service.'];
        }

        $rows = [];
        foreach ($files as $f) {
            $name = $f['name'] ?? '';
            $rel = ltrim($f['relative_path'] ?? $name, '/');
            if ($name === '' || $rel === '') continue;
            $rows[$rel] = [
                'name' => $name,
                'relative_path' => $rel,
                'platform' => self::platform($name, $rel),
                'arch' => preg_match('/(^|[^0-9])(32|x86)([^0-9]|$)/i', $name) ? '32'
                    : (preg_match('/(^|[^0-9])(arm64|aarch64|64|x64)([^0-9]|$)/i', $name) ? '64' : null),
                'is_dir' => 0,
                'size_bytes' => $f['size'] ?? null,
            ];
        }
        return [array_values($rows), null];
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
