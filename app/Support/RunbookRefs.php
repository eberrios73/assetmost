<?php

namespace App\Support;

use App\Models\DocPage;
use App\Models\OnboardingTemplate;

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

            // 1. Templates: kind key is the canonical slug; name matches loosely too.
            $tpl = OnboardingTemplate::query()->where('company_id', $companyId)
                ->get(['kind', 'variant', 'name', 'steps', 'source_page_id'])
                ->first(fn ($t) => $t->kind === $slug
                    || str_contains(strtolower(str_replace([' ', '-'], '', $t->name)), $slug));
            if ($tpl) {
                $steps = json_decode($tpl->steps, true)['steps'] ?? [];
                $extra = array_merge($extra, $steps);
                $link = $tpl->source_page_id ? " ({$base}/docs?page={$tpl->source_page_id})" : '';
                $text = str_replace("/{$token}", "[{$tpl->name} runbook{$link} — steps included below, current as of generation]", $text);
                continue;
            }

            // 2. Docs by title (normalized contains).
            $page = DocPage::query()->get(['id', 'title'])
                ->first(fn ($p) => str_contains(strtolower(str_replace([' ', '-'], '', $p->title)), $slug));
            if ($page) {
                $text = str_replace("/{$token}", "[See runbook: {$page->title} — {$base}/docs?page={$page->id}]", $text);
            }
            // Unresolved tokens stay as typed — visible, greppable, fixable.
        }

        return [$text, $extra];
    }
}
