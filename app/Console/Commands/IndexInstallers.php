<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Scan the mounted installers share into the `installers` index. The directory
 * IS the catalog: drop an installer in the folder, it's in /install after the
 * next scan; delete it, it's gone. Run nightly (cron) and on demand.
 *
 *   INSTALLERS_MOUNT=/mnt/installers php artisan installers:index
 *
 * Layout: <mount>/Mac/... and <mount>/Windows/... — the top folder is the
 * platform; 32|x86 / 64|x64 in a name is the arch.
 */
class IndexInstallers extends Command
{
    protected $signature = 'installers:index {--mount=}';
    protected $description = 'Index the mounted installers share into the installers table';

    public function handle(): int
    {
        $mount = $this->option('mount') ?: env('INSTALLERS_MOUNT', '/mnt/installers');
        if (! is_dir($mount)) {
            $this->error("Mount not found: {$mount} — mount the share there (read-only) first.");
            return self::FAILURE;
        }

        $rows = [];
        $now = Carbon::now();
        foreach (scandir($mount) as $platform) {
            if ($platform[0] === '.' || ! is_dir("{$mount}/{$platform}")) continue;
            // depth 2 under each platform folder is plenty: Office64/setup.exe etc.
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator("{$mount}/{$platform}", \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );
            $it->setMaxDepth(1);
            foreach ($it as $f) {
                $name = $f->getFilename();
                if ($name[0] === '.') continue;
                $rel = ltrim(str_replace($mount, '', $f->getPathname()), '/');
                $rows[] = [
                    'name' => $name,
                    'relative_path' => $rel,
                    'platform' => $platform,
                    'arch' => preg_match('/(^|[^0-9])(32|x86)([^0-9]|$)/i', $name) ? '32'
                        : (preg_match('/(^|[^0-9])(64|x64)([^0-9]|$)/i', $name) ? '64' : null),
                    'is_dir' => $f->isDir(),
                    'size_bytes' => $f->isDir() ? null : $f->getSize(),
                    'indexed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                ];
            }
        }

        DB::transaction(function () use ($rows, $now) {
            DB::table('installers')->delete();   // full refresh: the folder is the truth
            foreach (array_chunk($rows, 200) as $chunk) {
                DB::table('installers')->insert($chunk);
            }
        });

        $this->info(count($rows) . ' entries indexed from ' . $mount);
        return self::SUCCESS;
    }
}
