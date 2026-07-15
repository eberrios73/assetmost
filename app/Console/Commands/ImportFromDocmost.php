<?php

namespace App\Console\Commands;

use App\Models\DocPage;
use App\Support\ProseMirrorToHtml;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Import a Docmost workspace (Postgres) into AssetMost Docs.
 * Each Docmost space becomes a top-level folder page; page hierarchy is
 * preserved; ProseMirror content is converted to our editor's HTML.
 *
 * Configure the source in .env (never hardcode creds):
 *   DOCMOST_DB_HOST, DOCMOST_DB_PORT, DOCMOST_DB_DATABASE,
 *   DOCMOST_DB_USERNAME, DOCMOST_DB_PASSWORD, DOCMOST_DB_SCHEMA (default public)
 *
 * Usage:
 *   php artisan docs:import-docmost --company=1 --dry-run   # preview
 *   php artisan docs:import-docmost --company=1             # write
 */
class ImportFromDocmost extends Command
{
    protected $signature = 'docs:import-docmost
        {--company= : company_id all imported pages belong to}
        {--dry-run : report what would be imported without writing}';

    protected $description = 'Import Docmost pages (Postgres) into AssetMost Docs — one Space per Docmost space.';

    private const SPACE_COLORS = ['#7c3aed', '#2563eb', '#b91c1c', '#0d9488', '#b45309', '#16a34a', '#db2777'];

    public function handle(): int
    {
        $companyId = (int) $this->option('company');
        if (! $companyId) {
            $this->error('Pass --company=<id> — the company these docs belong to.');
            return self::FAILURE;
        }
        $dry = (bool) $this->option('dry-run');

        config(['database.connections.docmost' => [
            'driver' => 'pgsql',
            'host' => env('DOCMOST_DB_HOST', '127.0.0.1'),
            'port' => env('DOCMOST_DB_PORT', '5432'),
            'database' => env('DOCMOST_DB_DATABASE', 'docmost'),
            'username' => env('DOCMOST_DB_USERNAME', 'docmost'),
            'password' => env('DOCMOST_DB_PASSWORD', ''),
            'charset' => 'utf8',
            'search_path' => env('DOCMOST_DB_SCHEMA', 'public'),
            'sslmode' => env('DOCMOST_DB_SSLMODE', 'prefer'),
        ]]);

        try {
            $src = DB::connection('docmost');
            $spaces = $src->table('spaces')->get();
            $pages = $src->table('pages')->get();
        } catch (\Throwable $e) {
            $this->error('Could not read Docmost: '.$e->getMessage());
            $this->line('Check the DOCMOST_DB_* env vars and that pdo_pgsql can reach the host.');
            return self::FAILURE;
        }

        // drop soft-deleted pages if that column exists
        $pages = $pages->reject(fn ($p) => ! empty($p->deleted_at ?? null))->values();

        $this->info(sprintf('Docmost source: %d spaces, %d pages.', $spaces->count(), $pages->count()));
        if ($pages->isEmpty()) {
            $this->warn('No pages found — verify the schema/column names for your Docmost version.');
            return self::SUCCESS;
        }

        $bySpace = $pages->groupBy('space_id');
        $created = 0;
        $images = 0;
        $spaceIx = 0;

        foreach ($spaces as $space) {
            $spacePages = $bySpace->get($space->id, collect());
            if ($spacePages->isEmpty()) {
                continue;
            }
            $name = $space->name ?? 'Imported space';
            $this->line("• {$name} — {$spacePages->count()} pages");

            // a page is a root of the space if it has no parent (or its parent
            // isn't in this space, e.g. deleted) so nothing is orphaned
            $idSet = $spacePages->pluck('id')->flip();
            $childrenOf = $spacePages->groupBy(function ($p) use ($idSet) {
                $parent = $p->parent_page_id ?? null;
                return ($parent && $idSet->has($parent)) ? $parent : '__root__';
            });

            if ($dry) {
                $sample = $spacePages->first();
                $html = ProseMirrorToHtml::convert($sample->content ?? '');
                $this->line('    e.g. "'.($sample->title ?? 'Untitled').'" → '.strlen($html).' bytes html');
                $images += $spacePages->sum(fn ($p) => substr_count(ProseMirrorToHtml::convert($p->content ?? ''), '<img '));
                continue;
            }

            // each Docmost space becomes an AssetMost Space; pages hang off it (space_id), not a folder-page
            $ourSpace = \App\Models\Space::create([
                'company_id' => $companyId, 'name' => $name, 'icon' => '📁',
                'color' => self::SPACE_COLORS[$spaceIx++ % count(self::SPACE_COLORS)],
                'position' => $spaceIx,
            ]);

            $insert = function ($docmostParent, $ourParentId) use (&$insert, $childrenOf, $companyId, $ourSpace, &$created, &$images) {
                foreach ($childrenOf->get($docmostParent, collect()) as $p) {
                    $html = ProseMirrorToHtml::convert($p->content ?? '');
                    $images += substr_count($html, '<img ');
                    $icon = ($p->icon ?? null) && mb_strlen($p->icon) <= 8 ? $p->icon : null;
                    $page = DocPage::create([
                        'company_id' => $companyId, 'space_id' => $ourSpace->id, 'parent_id' => $ourParentId,
                        'title' => $p->title ?: 'Untitled', 'icon' => $icon,
                        'body' => $html, 'updated_by' => null,
                    ]);
                    $created++;
                    $insert($p->id, $page->id);
                }
            };
            $insert('__root__', null);   // space's top-level pages have no parent
        }

        $this->newLine();
        $this->info($dry ? 'Dry run complete — no changes written.' : "Imported {$created} pages into company {$companyId}.");
        if ($images) {
            $this->warn("{$images} images point at Docmost attachments — migrate those files and rewrite their src separately.");
        }
        return self::SUCCESS;
    }
}
