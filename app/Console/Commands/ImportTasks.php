<?php

namespace App\Console\Commands;

use App\Models\Task;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Import tasks from the standalone IT-tasks app — either its SQLite database
 * (ittasks.db) or a JSON export. Rows land in AssetMost's tasks table,
 * tenant-scoped. Adds to whatever is already there (clear seed tasks first
 * if you don't want them).
 *
 *   php artisan tasks:import --company=1 --sqlite=/path/ittasks.db --dry-run
 *   php artisan tasks:import --company=1 --json=/path/it-tasks-export.json
 */
class ImportTasks extends Command
{
    protected $signature = 'tasks:import
        {--company= : company_id these tasks belong to}
        {--sqlite= : path to the IT-tasks SQLite db (ittasks.db)}
        {--json= : path to a JSON export}
        {--dry-run : report what would be imported, write nothing}';

    protected $description = 'Import tasks from the IT-tasks app (SQLite db or JSON export).';

    public function handle(): int
    {
        $companyId = (int) $this->option('company');
        if (! $companyId) {
            $this->error('Pass --company=<id> — the company these tasks belong to.');
            return self::FAILURE;
        }

        $rows = match (true) {
            (bool) $this->option('sqlite') => $this->fromSqlite($this->option('sqlite')),
            (bool) $this->option('json') => $this->fromJson($this->option('json')),
            default => null,
        };
        if ($rows === null) {
            $this->error('Pass --sqlite=<path> or --json=<path>.');
            return self::FAILURE;
        }
        if ($rows === false) {
            return self::FAILURE; // reader already reported why
        }

        $projects = count(array_filter($rows, fn ($r) => $r['is_project']));
        $this->info(count($rows)." tasks found — {$projects} projects, " . (count($rows) - $projects) . ' regular.');

        if ($this->option('dry-run')) {
            foreach (array_slice($rows, 0, 6) as $r) {
                $this->line("  • [{$r['week']}] {$r['title']} " . ($r['done'] ? '(done)' : "({$r['pct']}%)"));
            }
            $this->info('Dry run — nothing written.');
            return self::SUCCESS;
        }

        $now = now()->toDateTimeString();
        foreach ($rows as &$r) {
            $r['company_id'] = $companyId;
            $r['assigned_to'] = null;
            $r['created_at'] = $r['created_at'] ?: $now;
            $r['updated_at'] = $now;
        }
        DB::table((new Task)->getTable())->insert($rows);
        $this->info('Imported ' . count($rows) . " tasks into company {$companyId}.");
        return self::SUCCESS;
    }

    private function fromSqlite(string $path): array|false
    {
        if (! is_file($path)) {
            $this->error("No file at: {$path}");
            return false;
        }
        config(['database.connections.ittasks' => ['driver' => 'sqlite', 'database' => $path, 'prefix' => '', 'foreign_key_constraints' => false]]);
        try {
            $src = DB::connection('ittasks')->table('tasks')->get();
        } catch (\Throwable $e) {
            $this->error('Could not read the SQLite db: ' . $e->getMessage());
            return false;
        }
        return $src->map(fn ($t) => $this->normalize((array) $t))->all();
    }

    private function fromJson(string $path): array|false
    {
        if (! is_file($path)) {
            $this->error("No file at: {$path}");
            return false;
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data)) {
            $this->error('That file is not a valid JSON array of tasks.');
            return false;
        }
        return array_map(fn ($t) => $this->normalize($t), $data);
    }

    /** Normalize a source row (SQLite uses ord/completed_at/int flags; JSON uses order/completedAt/bool). */
    private function normalize(array $t): array
    {
        $ms = fn ($v) => ($v !== null && $v !== '') ? Carbon::createFromTimestampMs((int) $v)->toDateTimeString() : null;
        $done = ! empty($t['done']);
        $week = $t['week'] ?? now()->startOfWeek(Carbon::MONDAY)->toDateString();

        return [
            'title' => (string) ($t['title'] ?? 'Untitled'),
            'week' => $week,
            'origin' => $t['origin'] ?? $week,
            'done' => $done,
            'pct' => (int) ($t['pct'] ?? ($done ? 100 : 0)),
            'pri' => (int) ($t['pri'] ?? 0),
            'is_project' => ! empty($t['project']),
            'ord' => (int) ($t['ord'] ?? $t['order'] ?? 0),
            'status' => (string) ($t['status'] ?? ''),
            'details' => ($t['details'] ?? '') ?: null,
            'impact' => ($t['impact'] ?? '') ?: null,
            'needs' => ($t['needs'] ?? '') ?: null,
            'challenges' => ($t['challenges'] ?? '') ?: null,
            'workarounds' => ($t['workarounds'] ?? '') ?: null,
            'notes' => null,
            'completed_at' => $ms($t['completedAt'] ?? $t['completed_at'] ?? null),
            'created_at' => $ms($t['created'] ?? null),
        ];
    }
}
