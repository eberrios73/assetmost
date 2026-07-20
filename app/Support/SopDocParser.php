<?php

namespace App\Support;

/**
 * Compiles a Docs page (TipTap HTML) into template steps. Deterministic — no AI.
 *
 * The rigid format, which real SOPs already approximate:
 *   - Headings or numbered lines ("1. Pre-Arrival…")  → SECTIONS. A section sets
 *     the timing for what's under it (matched by words: "prior/pre-" → -2 days,
 *     "first/second day" → 0, "week after" → +7). Sections are timing, not steps.
 *   - Top-level bullets / bold-labelled lines ("Device Hand-off:") → STEPS.
 *   - Nested bullets → SUBTASKS.
 *   - Lines labelled "Why:", "How:", "Done when:", "Do ne:" or "Record:" attach to
 *     the step above them as playbook fields instead of becoming steps.
 *
 * Editing loop: edit the DOC (rich text, in Docs), hit re-parse. The doc is the
 * master; the template is compiled output.
 */
class SopDocParser
{
    /** @return array{version:int, steps:array<int,array>} */
    public static function parse(string $html): array
    {
        $steps = [];
        $currentOffset = 0;
        $lastStep = null;      // reference target for field lines / subtasks
        $lastWasSub = false;

        $lines = self::lines($html);
        // Real docs have preambles (title, "Employee Name: ___", intro prose). If the
        // doc has sections at all, nothing before the first section is a step.
        $hasSections = false;
        foreach ($lines as [$t, $d, $h]) {
            if ($h || ($d === 0 && preg_match('/^\d+\s*[.)]\s+/', trim($t)))) { $hasSections = true; break; }
        }
        $seenSection = ! $hasSections;
        $meta = [];
        $section = '';

        foreach ($lines as $line) {
            [$text, $depth, $isHeading] = $line;
            $cells = $line[3] ?? null;

            // Table rows: the governance block (before any section) becomes metadata;
            // a Procedure table's rows become steps; Revision history is the doc's own
            // memory and stays out of the template.
            if ($cells !== null) {
                // A step's field table (the /step scaffold): 2 columns, labelled
                // Why / How / Done when / Record. Rows attach to the current step
                // (or its last subtask); empty cells — a fresh scaffold — are skipped.
                if ($lastStep !== null && isset($cells[0])
                    && preg_match('/^(why|how|notes|done when|done|record)\s*:?\s*$/i', trim($cells[0]), $fm)) {
                    $val = trim($cells[1] ?? '');
                    if ($val !== '') {
                        $key = match (strtolower($fm[1])) {
                            'why' => 'why', 'how', 'notes' => 'instructions',
                            'done when', 'done' => 'done_when', 'record' => 'record',
                        };
                        $target = &$steps[$lastStep];
                        if ($lastWasSub && $target['subtasks']) {
                            $sub = &$target['subtasks'][array_key_last($target['subtasks'])];
                            $sub[$key] = trim(($sub[$key] ?? '') !== '' ? $sub[$key] . "\n" . $val : $val);
                            unset($sub);
                        } else {
                            $target[$key] = trim(($target[$key] ?? '') !== '' ? $target[$key] . "\n" . $val : $val);
                        }
                        unset($target);
                    }
                    continue;
                }
                if (! $seenSection || $section === '') {
                    // The SOP header table: governance rows plus the document-level
                    // sections of a formal SOP (all optional, synonyms accepted).
                    $metaMap = [
                        'owner' => 'owner', 'version' => 'version', 'effective' => 'effective',
                        'review by' => 'review_by', 'approver' => 'approver', 'status' => 'status',
                        'why' => 'why', 'purpose' => 'why',
                        'how' => 'how', 'scope' => 'scope',
                        'os' => 'os', 'platform' => 'os',
                        'tools and materials' => 'tools', 'tools' => 'tools', 'materials' => 'tools',
                        'safety precautions' => 'safety', 'safety' => 'safety',
                    ];
                    for ($i = 0; $i + 1 < count($cells); $i += 2) {
                        $k = strtolower(trim(rtrim(trim($cells[$i]), ':')));
                        if ($k !== '' && $cells[$i + 1] !== '' && isset($metaMap[$k])) {
                            $meta[$metaMap[$k]] = $metaMap[$k] === 'os'
                                ? self::canonicalOs($cells[$i + 1])
                                : $cells[$i + 1];
                        }
                    }
                } elseif (preg_match('/procedure/i', $section)) {
                    // header row says "Action"; data rows: [#, action, responsible, notes]
                    if (! preg_match('/^action$/i', trim($cells[1] ?? ''))) {
                        $action = trim($cells[1] ?? '');
                        if ($action !== '') {
                            $notes = trim($cells[3] ?? '');
                            $resp = trim($cells[2] ?? '');
                            $steps[] = ['id' => substr(md5($action . count($steps)), 0, 8), 'title' => $action,
                                'category' => self::category($action), 'offset_days' => $currentOffset,
                                'why' => '', 'instructions' => trim($notes . ($resp !== '' ? ($notes !== '' ? "\n" : '') . "Responsible: {$resp}" : '')),
                                'done_when' => '', 'record' => '', 'automatable' => false, 'subtasks' => []];
                            $lastStep = array_key_last($steps);
                            $lastWasSub = false;
                        }
                    }
                }
                continue;
            }

            $trim = trim($text);
            if ($trim === '') continue;

            // Prose sections describe the SOP; they are not procedure. Their text
            // stays in the doc and out of the template.
            if (! $isHeading && $section !== '' && preg_match('/purpose|scope|verification|rollback|revision/i', $section)) continue;

            // Prose paragraphs (long sentences) are context, not steps: before any step
            // they're the doc's intro; after one, they join its How.
            if ($cellsFree = str_word_count($trim) > 20 && ! preg_match('/^[o§·▪☐□☑✓]/u', $trim)) {
                if ($lastStep === null) continue;
                $steps[$lastStep]['instructions'] = trim(($steps[$lastStep]['instructions'] ?? '') . "\n" . $trim);
                continue;
            }

            // Word-paste sub-bullets arrive as flat lines starting "o " / "§ " / "· ".
            // A checkbox line splits on INNER checkboxes too: "☐ Ram ☐ HD" = two items.
            if (preg_match('/^[☐□☑✓]\s*/u', $trim)) {
                $parts = array_values(array_filter(array_map('trim', preg_split('/[☐□☑✓]/u', $trim)), fn ($x) => $x !== ''));
                if (count($parts) > 1) {
                    foreach ($parts as $part) {
                        $item = ['id' => substr(md5($part . count($steps)), 0, 8), 'title' => $part,
                            'category' => self::category($part), 'offset_days' => $currentOffset,
                            'why' => '', 'instructions' => '', 'done_when' => '', 'record' => '',
                            'automatable' => false, 'subtasks' => []];
                        if ($lastStep !== null) { $steps[$lastStep]['subtasks'][] = $item; $lastWasSub = true; }
                        else { $steps[] = $item; $lastStep = array_key_last($steps); }
                    }
                    continue;
                }
                $trim = $parts[0] ?? '';
                if ($trim === '') continue;
                $depth = max($depth, 1);
            } elseif (preg_match('/^[o§·▪]\s+(.+)$/u', $trim, $wm)) {
                $trim = trim($wm[1]);
                $depth = max($depth, 1);
            }
            // Continuation lines (dial codes, paths) attach to the previous step's
            // How — but a /command token ("/localadmin", "/mdm Jamf auto") is a
            // SUBSTEP, never a continuation; swallowing it would eat the command.
            if ($lastStep !== null && preg_match('~^[#*/\\\\]~', $trim)
                && ! preg_match('~^/[a-z][\w-]{2,}+(?![./])~i', $trim)) {
                $steps[$lastStep]['instructions'] = trim(($steps[$lastStep]['instructions'] ?? '') . "\n" . $trim);
                continue;
            }

            // Playbook field lines attach to the current step (or its last subtask).
            if (preg_match('/^(why|how|notes|done when|done|record)\s*[:—-]\s*(.+)$/i', $trim, $m)) {
                if ($lastStep === null) continue;   // stray field line — never a step
                if (true) {
                    $key = match (strtolower($m[1])) {
                        'why' => 'why', 'how', 'notes' => 'instructions',
                        'done when', 'done' => 'done_when', 'record' => 'record',
                    };
                    $target = &$steps[$lastStep];
                    if ($lastWasSub && $target['subtasks']) {
                        $target['subtasks'][array_key_last($target['subtasks'])][$key] = $m[2];
                    } else {
                        $target[$key] = trim(($target[$key] ?? '') . ($target[$key] ?? '' ? "\n" : '') . $m[2]);
                    }
                    unset($target);
                    continue;
                }
            }

            // Sections: headings, or "1. Title" numbered lines at top level.
            $numbered = preg_match('/^\d+\s*[.)]\s+(.+)$/', $trim, $nm);
            if ($isHeading || ($numbered && $depth === 0)) {
                $title = $numbered ? $nm[1] : $trim;
                $currentOffset = self::offsetFromSection($title);
                $lastStep = null; $lastWasSub = false;
                $seenSection = true;
                $section = $title;
                continue;
            }
            if (! $seenSection) continue;   // preamble prose, form blanks, the doc's own title

            $title = rtrim($trim, ':');
            $item = ['id' => substr(md5($title . count($steps)), 0, 8), 'title' => $title,
                'category' => self::category($title), 'offset_days' => $currentOffset,
                'why' => '', 'instructions' => '', 'done_when' => '', 'record' => '',
                'automatable' => false, 'subtasks' => []];

            if ($depth > 0 && $lastStep !== null) {
                $steps[$lastStep]['subtasks'][] = $item;
                $lastWasSub = true;
            } else {
                $steps[] = $item;
                $lastStep = array_key_last($steps);
                $lastWasSub = false;
            }
        }

        return ['version' => 1, 'steps' => array_values($steps), 'meta' => $meta];
    }

    /** Section title → timing anchor. Words, not magic: the SOP already says when. */
    private static function offsetFromSection(string $title): int
    {
        $t = strtolower($title);
        if (preg_match('/pre[- ]?arrival|prior|before|in advance/', $t)) return -2;
        if (preg_match('/week(s)? after|follow[- ]?up|post[- ]?onboarding/', $t)) return 7;
        // "First/Second Day" means it STARTS day one — check first before second.
        if (preg_match('/first/', $t)) return 0;
        if (preg_match('/second day|next day|day two/', $t)) return 1;
        return 0;
    }

    private static function category(string $title): string
    {
        $t = strtolower($title);
        if (preg_match('/\b(account|email|mailbox|365|microsoft|adobe|zoom|domain|credential|login|voicemail|license)\b/', $t)) return 'accounts';
        if (preg_match('/\b(machine|laptop|workstation|computer|hardware|device|dock|monitor|printer|imag)\b/', $t)) return 'machine';
        if (preg_match('/\b(vpn|wifi|network|badge|door|key|access|mfa|sso|firewall|permission)\b/', $t)) return 'access';
        if (preg_match('/\b(training|orientation|policies|policy|handbook|welcome|walkthrough)\b/', $t)) return 'training';
        return 'other';
    }

    /**
     * HTML → [text, depth, isHeading] lines. Depth counts nested lists; TipTap
     * nests as ul > li > (p + ul > li ...).
     */
    private static function lines(string $html): array
    {
        $out = [];
        $doc = new \DOMDocument();
        @$doc->loadHTML('<?xml encoding="utf-8"?><body>' . $html . '</body>');
        $body = $doc->getElementsByTagName('body')->item(0);
        if (! $body) return $out;

        $walk = function (\DOMNode $node, int $depth) use (&$walk, &$out) {
            foreach ($node->childNodes as $child) {
                if (! ($child instanceof \DOMElement)) continue;
                $tag = strtolower($child->tagName);
                if (in_array($tag, ['h1', 'h2', 'h3', 'h4'], true)) {
                    $out[] = [self::text($child), $depth, true];
                } elseif ($tag === 'li') {
                    // Emit each block paragraph of the li separately (title, then any
                    // field paragraphs), so fields never merge into the title. Nested
                    // lists recurse one level deeper.
                    $blocks = [];
                    $inline = '';
                    foreach ($child->childNodes as $g) {
                        if ($g instanceof \DOMElement && in_array(strtolower($g->tagName), ['ul', 'ol'], true)) continue;
                        if ($g instanceof \DOMElement && strtolower($g->tagName) === 'p') {
                            $t = trim(self::text($g));
                            if ($t !== '') $blocks[] = $t;
                        } else {
                            $inline .= ' ' . self::text($g);
                        }
                    }
                    if (! $blocks && trim($inline) !== '') $blocks[] = trim($inline);
                    foreach ($blocks as $b) $out[] = [$b, $depth, false];
                    foreach ($child->childNodes as $g) {
                        if ($g instanceof \DOMElement && in_array(strtolower($g->tagName), ['ul', 'ol'], true)) {
                            $walk($g, $depth + 1);
                        }
                    }
                } elseif ($tag === 'table') {
                    foreach ($child->getElementsByTagName('tr') as $tr) {
                        $cells = [];
                        foreach ($tr->childNodes as $cell) {
                            if ($cell instanceof \DOMElement && in_array(strtolower($cell->tagName), ['td', 'th'], true)) {
                                // Keep a cell's paragraphs — and bullet items (a Tools
                                // or Safety cell holds a list) — as separate lines.
                                $blocks = [];
                                $gather = function (\DOMNode $n) use (&$gather, &$blocks) {
                                    foreach ($n->childNodes as $b) {
                                        if (! ($b instanceof \DOMElement)) continue;
                                        if (strtolower($b->tagName) === 'p') {
                                            $t = trim(self::text($b));
                                            if ($t !== '') $blocks[] = $t;
                                        } else {
                                            $gather($b);
                                        }
                                    }
                                };
                                $gather($cell);
                                $cells[] = $blocks ? implode("\n", $blocks) : trim(self::text($cell));
                            }
                        }
                        if (array_filter($cells, fn ($c) => $c !== '')) $out[] = ['', $depth, false, $cells];
                    }
                } elseif (in_array($tag, ['ul', 'ol'], true)) {
                    // A list's items are one level deeper — they attach as subtasks of
                    // the preceding step (or become steps if none precedes them).
                    $walk($child, $depth + 1);
                } elseif ($tag === 'p') {
                    $t = self::text($child);
                    if (trim($t) !== '') $out[] = [trim($t), $depth, false];
                } else {
                    // Step cards (<section data-sop-step>) and other wrappers: their
                    // children (title, field table, substep list) parse as plain markup.
                    $walk($child, $depth);
                }
            }
        };
        $walk($body, 0);
        return $out;
    }

    /** Whatever someone typed ("Win", "Windows, 11", "Ubuntu") → the canonical OS. */
    public static function canonicalOs(string $v): string
    {
        $s = strtolower($v);
        return match (true) {
            (bool) preg_match('/mac|osx|os x/', $s) => 'macOS',
            (bool) preg_match('/win/', $s) => 'Windows',
            (bool) preg_match('/linux|ubuntu|debian|fedora|centos|rhel|mint/', $s) => 'Linux',
            (bool) preg_match('/ios|iphone|ipad/', $s) => 'iOS',
            (bool) preg_match('/android/', $s) => 'Android',
            default => trim($v),
        };
    }

    private static function text(\DOMNode $n): string
    {
        $t = $n->textContent ?? '';
        // Word-paste artifacts: non-breaking spaces and stray replacement chars.
        $t = str_replace(["\u{00A0}", "\u{FFFD}"], ' ', $t);
        return preg_replace('/\s+/u', ' ', $t);
    }

    /**
     * The reverse direction: template steps -> rigid-format HTML for a Docs page.
     * Emits exactly the shape parse() reads: bold-paragraph step titles, "o "
     * subtask lines, labelled Why/How/Done when/Record paragraphs. Round-trip safe.
     */
    public static function toHtml(array $steps, string $sectionLabel = '', array $meta = []): string
    {
        $esc = fn ($v) => htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
        $cellLines = function (?string $v) use ($esc) {
            $out = '';
            foreach (preg_split('/\n+/', $v ?? '') as $line) $out .= "<p>{$esc($line)}</p>";
            return $out;
        };
        // The SOP header: document-level Why/How, Tools, Safety and governance as
        // one table at the top — every row optional; parse() reads them back to meta.
        // OS, Owner and Version share one compact row as the LAST row; when it's
        // present, single-row values span the width (colspan) so columns stay clean.
        $header = '';
        $trio = '';
        foreach (['os' => 'OS', 'owner' => 'Owner', 'version' => 'Version', 'status' => 'Status'] as $k => $label) {
            if (! empty($meta[$k])) {
                $trio .= "<td><p><strong>{$label}:</strong></p></td><td>{$cellLines($meta[$k])}</td>";
            }
        }
        $span = $trio !== '' ? ' colspan="7"' : '';
        $headerRows = ['why' => 'Why', 'how' => 'How', 'scope' => 'Scope',
            'tools' => 'Tools and Materials', 'safety' => 'Safety Precautions',
            'effective' => 'Effective', 'review_by' => 'Review by',
            'approver' => 'Approver'];
        foreach ($headerRows as $k => $label) {
            if (! empty($meta[$k])) {
                $header .= "<tr><td><p><strong>{$label}:</strong></p></td><td{$span}>{$cellLines($meta[$k])}</td></tr>";
            }
        }
        if ($trio !== '') $header .= "<tr>{$trio}</tr>";
        if ($header !== '') $header = "<table><tbody>{$header}</tbody></table>";
        // A step's playbook fields as a neat 2-column table — the same shape /step
        // scaffolds in the editor, and what parse() reads back by its row labels.
        $fieldTable = function (array $s) use ($esc) {
            $rows = '';
            foreach (['why' => 'Why', 'instructions' => 'How', 'done_when' => 'Done when', 'record' => 'Record'] as $k => $label) {
                if (! empty($s[$k])) {
                    $cell = '';
                    foreach (preg_split('/\n+/', $s[$k]) as $line) $cell .= "<p>{$esc($line)}</p>";
                    $rows .= "<tr><td><p><strong>{$label}:</strong></p></td><td>{$cell}</td></tr>";
                }
            }
            return $rows ? "<table><tbody>{$rows}</tbody></table>" : '';
        };
        // Subtask fields stay labelled paragraphs — a table inside a list item would
        // not survive the li parsing, and bullets read better lean.
        $fieldParas = function (array $s) use ($esc) {
            $out = '';
            foreach (['why' => 'Why', 'instructions' => 'Notes', 'done_when' => 'Done when', 'record' => 'Record'] as $k => $label) {
                if (! empty($s[$k])) {
                    foreach (preg_split('/\n+/', $s[$k]) as $i => $line) {
                        $lbl = $i === 0 ? "<strong>{$label}:</strong> " : '';
                        $out .= "<p>{$lbl}{$esc($line)}</p>";
                    }
                }
            }
            return $out;
        };

        $h = $header . ($sectionLabel ? "<h2>{$esc($sectionLabel)}</h2>" : '');
        foreach ($steps as $s) {
            // Each step is a card: <section data-sop-step> renders as the structured
            // card in the editor.
            $h .= '<section data-sop-step>';
            $h .= "<p><strong>{$esc($s['title'])}</strong></p>" . $fieldTable($s);
            // Subtasks are a real bulleted list; each carries its own fields inside the li.
            if (! empty($s['subtasks'])) {
                $h .= '<ul>';
                foreach ($s['subtasks'] as $sub) {
                    $h .= "<li><p>{$esc($sub['title'])}</p>" . $fieldParas($sub) . '</li>';
                }
                $h .= '</ul>';
            }
            $h .= '</section>';
        }
        return $h;
    }
}
