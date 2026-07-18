<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Index the installers via the Synology FileStation API.
 *
 * companies.installers_path holds host + the share path, e.g.
 *   files.example.com/X Technology/Installers
 * The app logs in to DSM (host:port), lists that folder and its subfolders, and
 * records every file. The folder IS the catalog. At install time the bench pulls
 * a file with the same API's download endpoint to a tmp folder, runs it, cleans up.
 *
 * Credentials come from env (INSTALLERS_HTTP_USER / INSTALLERS_HTTP_PASS) and are
 * NEVER stored in code or the database. DSM port defaults to 5000 (INSTALLERS_DSM_PORT).
 */
class IndexInstallers extends Command
{
    protected $signature = 'installers:index {--path=}';
    protected $description = 'Index the installers from the Synology FileStation into the installers table';

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

    /** Split "host/share/path" into [http base, "/share/path"]. */
    public static function split(string $path): array
    {
        $path = preg_replace('#^https?://#i', '', trim($path));
        $slash = strpos($path, '/');
        $host = $slash === false ? $path : substr($path, 0, $slash);
        $folder = $slash === false ? '/' : '/' . ltrim(substr($path, $slash + 1), '/');
        $port = env('INSTALLERS_DSM_PORT', 5000);
        // host may already carry a port
        $base = str_contains($host, ':') ? "http://{$host}" : "http://{$host}:{$port}";
        return [$base, $folder];
    }

    /** @return array{0: array, 1: ?string} [rows, errorOrNull] */
    public static function scan(string $path): array
    {
        [$base, $folder] = self::split($path);
        $user = env('INSTALLERS_HTTP_USER');
        $pass = env('INSTALLERS_HTTP_PASS');
        if (! $user) {
            return [[], 'Set INSTALLERS_HTTP_USER / INSTALLERS_HTTP_PASS on the server first.'];
        }

        $auth = Http::timeout(12)->withOptions(['verify' => false])->get("{$base}/webapi/auth.cgi", [
            'api' => 'SYNO.API.Auth', 'version' => 3, 'method' => 'login',
            'account' => $user, 'passwd' => $pass, 'session' => 'FileStation', 'format' => 'sid',
        ]);
        $sid = $auth->json('data.sid');
        if (! $sid) {
            return [[], 'Synology login failed (code ' . ($auth->json('error.code') ?? $auth->status()) . '). Check the account/password in .env.'];
        }

        $list = function (string $dir) use ($base, $sid) {
            $r = Http::timeout(15)->withOptions(['verify' => false])->get("{$base}/webapi/entry.cgi", [
                'api' => 'SYNO.FileStation.List', 'version' => 2, 'method' => 'list',
                'folder_path' => $dir, '_sid' => $sid,
            ]);
            return $r->json('data.files') ?? [];
        };

        $rows = [];
        $add = function (array $f, string $platformHint) use (&$rows) {
            $name = $f['name'] ?? '';
            if ($name === '' || $name[0] === '.') return;
            $rel = ltrim(($f['path'] ?? $name), '/');
            $rows[$rel] = [
                'name' => $name,
                'relative_path' => $rel,
                'platform' => self::platform($name, $platformHint),
                'arch' => preg_match('/(^|[^0-9])(32|x86)([^0-9]|$)/i', $name) ? '32'
                    : (preg_match('/(^|[^0-9])(arm64|aarch64|64|x64)([^0-9]|$)/i', $name) ? '64' : null),
                'is_dir' => ! empty($f['isdir']) ? 1 : 0,
                'size_bytes' => $f['additional']['size'] ?? null,
            ];
        };

        foreach ($list($folder) as $f) {
            if (! empty($f['isdir'])) {
                // one level deep: list Mac/, Windows/, etc.
                $hint = strtolower($f['name']);
                foreach ($list($f['path']) as $sub) {
                    $add($sub, $hint);
                }
            } else {
                $add($f, '');
            }
        }

        // best-effort logout
        Http::timeout(6)->withOptions(['verify' => false])->get("{$base}/webapi/auth.cgi", [
            'api' => 'SYNO.API.Auth', 'version' => 3, 'method' => 'logout', 'session' => 'FileStation',
        ]);

        return [array_values($rows), null];
    }

    private static function platform(string $name, string $folderHint): string
    {
        if (preg_match('/\.(dmg|pkg|app|mpkg)$/i', $name)) return 'Mac';
        if (preg_match('/\.(exe|msi|appx|msix)$/i', $name)) return 'Windows';
        if (str_contains($folderHint, 'mac') || str_contains($folderHint, 'osx') || str_contains($folderHint, 'apple')) return 'Mac';
        if (str_contains($folderHint, 'win') || str_contains($folderHint, 'pc')) return 'Windows';
        return 'other';
    }
}
