<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Index the installers share by listing it OVER SSH — no mount, no CIFS.
 *
 * The path stored on a company is host/path, e.g.
 *   files.example.com/X Technology/Installers
 * The app ssh'es to the host and lists the Mac/ and Windows/ subfolders. The
 * directory IS the catalog: whatever is there is what /install offers.
 *
 * SSH auth comes from env: INSTALLERS_SSH_USER (default root) and
 * INSTALLERS_SSH_KEY (a private key readable by the web user). Host-key checking
 * is disabled for the LAN box; scope the key to a read-only account.
 */
class IndexInstallers extends Command
{
    protected $signature = 'installers:index {--path=}';
    protected $description = 'Index the installers share over SSH into the installers table';

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

    /**
     * List host/path over SSH. Returns [rows, errorOrNull]. rows: name, relative_path,
     * platform, arch, is_dir. Up to three levels deep (platform/app/file).
     */
    public static function scan(string $hostPath): array
    {
        $slash = strpos($hostPath, '/');
        if ($slash === false) {
            return [[], 'Path must be host/path, e.g. files.example.com/IT/Installers'];
        }
        $host = substr($hostPath, 0, $slash);
        $remote = substr($hostPath, $slash + 1);

        $user = env('INSTALLERS_SSH_USER', 'root');
        $key = env('INSTALLERS_SSH_KEY');
        $keyOpt = $key ? '-i ' . escapeshellarg($key) . ' ' : '';

        // find is portable and handles spaces; -maxdepth 3 = platform/app/file.
        $remoteCmd = 'cd ' . escapeshellarg($remote) . " && find . -maxdepth 3 -mindepth 1 -printf '%y\\t%s\\t%p\\n' 2>/dev/null";
        $ssh = "ssh {$keyOpt}-o StrictHostKeyChecking=no -o ConnectTimeout=8 "
            . escapeshellarg("{$user}@{$host}") . ' ' . escapeshellarg($remoteCmd) . ' 2>&1';

        exec($ssh, $out, $code);
        if ($code !== 0) {
            return [[], 'SSH scan failed: ' . (trim(implode(' ', array_slice($out, 0, 3))) ?: "exit {$code}") . ' (check INSTALLERS_SSH_KEY / the read-only account on ' . $host . ')'];
        }

        $rows = [];
        foreach ($out as $line) {
            $parts = explode("\t", $line, 3);
            if (count($parts) < 3) continue;
            [$type, $size, $rel] = $parts;
            $rel = ltrim($rel, './');
            if ($rel === '') continue;
            $seg = explode('/', $rel);
            $platform = $seg[0];                                  // Mac | Windows | ...
            $name = end($seg);
            if ($name === '' || $name[0] === '.') continue;
            $rows[$rel] = [
                'name' => $name,
                'relative_path' => $rel,
                'platform' => $platform,
                'arch' => preg_match('/(^|[^0-9])(32|x86)([^0-9]|$)/i', $name) ? '32'
                    : (preg_match('/(^|[^0-9])(64|x64)([^0-9]|$)/i', $name) ? '64' : null),
                'is_dir' => $type === 'd' ? 1 : 0,
                'size_bytes' => $type === 'd' ? null : (int) $size,
            ];
        }
        return [array_values($rows), null];
    }
}
