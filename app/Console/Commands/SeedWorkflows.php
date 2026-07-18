<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\DocPage;
use App\Models\Space;
use App\Support\SopDocParser;
use App\Support\StarterTemplates;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Install the shipped workflow baselines as real Docs pages, and adopt the
 * legacy onboarding_templates into the doc-as-workflow model.
 *
 * Idempotent: a company that already has a page for a catalog slug is left
 * alone (their edits are theirs; upgrades never touch adopted copies). Legacy
 * templates are BACKFILLED first so existing SOPs keep their pages, bodies and
 * steps — the template table itself is left untouched (retired, not dropped).
 */
class SeedWorkflows extends Command
{
    protected $signature = 'workflows:seed {--company= : Only this company id}';
    protected $description = 'Seed shipped workflow baselines into Docs; adopt legacy onboarding templates';

    /** kind+variant -> catalog slug for the legacy templates. */
    private const LEGACY = [
        'onboarding|' => 'onboarding',
        'freelancer|' => 'freelancer',
        'offboarding|' => 'offboarding',
        'eprotection|' => 'eprotection',
        'imaging|Mac' => 'mac-workstation',
        'imaging|Windows' => 'windows-workstation',
        'imaging|Server' => 'windows-server',
    ];

    public function handle(): int
    {
        $companies = Company::query()
            ->when($this->option('company'), fn ($q, $id) => $q->whereKey($id))
            ->get(['id', 'name']);

        foreach ($companies as $company) {
            $this->line("== {$company->name} (#{$company->id})");
            $this->backfill($company->id);
            $this->seed($company->id);
        }
        return self::SUCCESS;
    }

    /** Adopt legacy templates: stamp their source pages (or create pages) as workflows. */
    private function backfill(int $companyId): void
    {
        // Fresh installs never had the legacy table — nothing to adopt.
        if (! \Illuminate\Support\Facades\Schema::hasTable('onboarding_templates')) return;
        $templates = DB::table('onboarding_templates')->where('company_id', $companyId)->get();
        foreach ($templates as $t) {
            $slug = self::LEGACY[$t->kind . '|' . $t->variant] ?? null;
            if (! $slug) { $this->warn("  skip template {$t->kind}/{$t->variant} (no catalog slug)"); continue; }
            if ($this->page($companyId, $slug)) continue;   // already adopted

            $meta = StarterTemplates::CATALOG[$slug];
            $page = $t->source_page_id
                ? DocPage::withoutGlobalScopes()->where('company_id', $companyId)->find($t->source_page_id)
                : null;

            if ($page) {
                // The page keeps its body (the human copy); it gains the workflow identity
                // and the engine steps. Retitle to the catalog name — also purges the
                // mojibake dashes the old imaging titles carried.
                $page->forceFill([
                    'title' => $meta['title'],
                    'workflow_type' => $meta['type'], 'workflow_slug' => $slug,
                    'form_factor' => $meta['form_factor'], 'workflow_active' => true,
                    'workflow_shipped' => true, 'workflow_wizard' => $meta['wizard'],
                    'workflow_steps' => $t->steps,
                ])->save();
                $this->info("  adopted {$slug} -> page #{$page->id}");
            } else {
                $this->create($companyId, $slug, json_decode($t->steps, true) ?: null);
                $this->info("  created {$slug} from template steps");
            }
        }
    }

    /** Create any catalog baseline the company doesn't have yet. */
    private function seed(int $companyId): void
    {
        foreach (array_keys(StarterTemplates::CATALOG) as $slug) {
            if ($this->page($companyId, $slug)) continue;
            $this->create($companyId, $slug, null);
            $this->info("  seeded {$slug}");
        }
    }

    private function page(int $companyId, string $slug): ?DocPage
    {
        return DocPage::withoutGlobalScopes()
            ->where('company_id', $companyId)->where('workflow_slug', $slug)->first();
    }

    private function create(int $companyId, string $slug, ?array $steps): void
    {
        $meta = StarterTemplates::CATALOG[$slug];
        $steps ??= StarterTemplates::workflow($slug);
        // File next to the company's existing workflow docs (siblings cluster in the
        // tree); first space by position only when there are none yet.
        $spaceId = DocPage::withoutGlobalScopes()->where('company_id', $companyId)
            ->whereNotNull('workflow_type')->whereNotNull('space_id')->value('space_id')
            ?? Space::withoutGlobalScopes()->where('company_id', $companyId)
                ->orderBy('position')->value('id')
            ?? Space::withoutGlobalScopes()->forceCreate(['company_id' => $companyId, 'name' => 'Docs', 'position' => 0])->id;

        DocPage::withoutGlobalScopes()->forceCreate([
            'company_id' => $companyId,
            'space_id' => $spaceId,
            'title' => $meta['title'],
            'body' => SopDocParser::toHtml($steps['steps'] ?? []),
            'category' => 'SOP',
            'workflow_type' => $meta['type'], 'workflow_slug' => $slug,
            'form_factor' => $meta['form_factor'], 'workflow_active' => true,
            'workflow_shipped' => true, 'workflow_wizard' => $meta['wizard'],
            'workflow_steps' => json_encode($steps),
        ]);
    }
}
