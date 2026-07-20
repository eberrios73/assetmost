<?php

namespace App\Support;

use App\Models\DocPage;

/**
 * Live references between runbooks: type /eprotection in a step and it resolves
 * AT GENERATION TIME to the current version of that runbook — first against the
 * company's templates (the kind key is the slug), then against Docs titles.
 *
 * That timing is the point: the endpoint-protection runbook goes stale often;
 * because references resolve when tasks are generated, every referencing
 * runbook stays current without being touched.
 */
class RunbookRefs
{
    /**
     * Resolve /tokens in text. Returns [annotatedText, extraSteps[]] — extraSteps
     * are the referenced template's CURRENT steps, to expand as subtasks.
     */
    public static function resolve(string $text, int $companyId, string $base = ''): array
    {
        if (! preg_match_all('~(?<=^|\s)/([a-z0-9_-]{3,})~i', $text, $m)) {
            return [$text, []];
        }

        $extra = [];
        foreach (array_unique($m[1]) as $token) {
            $slug = strtolower($token);

            // 1. Workflow docs: workflow_slug is the canonical slug; title matches loosely
            //    too (covers renamed duplicates like "Access Point").
            $wf = DocPage::withoutGlobalScopes()->visibleToCompany($companyId)
                ->whereNotNull('workflow_type')->where('workflow_active', true)
                ->get(['id', 'title', 'workflow_slug', 'workflow_steps'])
                ->first(fn ($p) => $p->workflow_slug === $slug
                    || str_contains(strtolower(str_replace([' ', '-'], '', $p->title)), $slug));
            if ($wf) {
                $steps = json_decode($wf->workflow_steps ?? '', true)['steps'] ?? [];
                $extra = array_merge($extra, $steps);
                $text = str_replace("/{$token}", "[{$wf->title} runbook ({$base}/docs?page={$wf->id}) — steps included below, current as of generation]", $text);
                continue;
            }

            // 2. Plain Docs by title (normalized contains).
            $page = DocPage::withoutGlobalScopes()->visibleToCompany($companyId)
                ->get(['id', 'title'])
                ->first(fn ($p) => str_contains(strtolower(str_replace([' ', '-'], '', $p->title)), $slug));
            if ($page) {
                $text = str_replace("/{$token}", "[See runbook: {$page->title} — {$base}/docs?page={$page->id}]", $text);
            }
            // Unresolved tokens stay as typed — visible, greppable, fixable.
        }

        return [$text, $extra];
    }
}
