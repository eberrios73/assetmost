<?php

namespace App\Support;

/**
 * Convert Docmost/TipTap ProseMirror JSON into the HTML our Docs editor stores.
 * Both apps share the TipTap node vocabulary, so this is a direct node→tag map;
 * unknown nodes degrade to their inner content rather than being dropped.
 */
class ProseMirrorToHtml
{
    /** @param array|string|null $doc decoded ProseMirror doc (array) or JSON string */
    public static function convert($doc): string
    {
        if (is_string($doc)) {
            $doc = json_decode($doc, true);
        }
        if (! is_array($doc)) {
            return '';
        }
        // accept either a full doc node or a bare content array
        return isset($doc['type']) ? self::node($doc) : self::nodes($doc['content'] ?? $doc);
    }

    private static function nodes(?array $content): string
    {
        if (! $content) {
            return '';
        }
        return implode('', array_map(fn ($n) => is_array($n) ? self::node($n) : '', $content));
    }

    private static function node(array $n): string
    {
        $type = $n['type'] ?? '';
        $attrs = $n['attrs'] ?? [];
        $inner = fn () => self::nodes($n['content'] ?? []);

        return match ($type) {
            'doc' => $inner(),
            'text' => self::text($n),
            'paragraph' => '<p>'.$inner().'</p>',
            'heading' => self::heading((int) ($attrs['level'] ?? 2), $inner()),
            'bulletList' => '<ul>'.$inner().'</ul>',
            'orderedList' => '<ol>'.$inner().'</ol>',
            'listItem' => '<li>'.$inner().'</li>',
            'taskList' => '<ul>'.$inner().'</ul>',
            'taskItem' => '<li>'.(empty($attrs['checked']) ? '☐' : '☑').' '.$inner().'</li>',
            'blockquote', 'callout' => '<blockquote>'.$inner().'</blockquote>',
            'codeBlock' => '<pre><code>'.self::esc(self::plain($n)).'</code></pre>',
            'horizontalRule' => '<hr>',
            'hardBreak' => '<br>',
            'image' => self::image($attrs),
            'table' => '<table><tbody>'.$inner().'</tbody></table>',
            'tableRow' => '<tr>'.$inner().'</tr>',
            'tableHeader' => '<th>'.$inner().'</th>',
            'tableCell' => '<td>'.$inner().'</td>',
            default => $inner(), // unknown block: keep its children/text
        };
    }

    private static function heading(int $level, string $inner): string
    {
        $level = max(1, min(6, $level));
        return "<h{$level}>{$inner}</h{$level}>";
    }

    private static function image(array $attrs): string
    {
        $src = self::esc($attrs['src'] ?? '');
        if (! $src) {
            return '';
        }
        return '<img src="'.$src.'" alt="'.self::esc($attrs['alt'] ?? '').'">';
    }

    private static function text(array $n): string
    {
        $text = self::esc($n['text'] ?? '');
        // apply marks inside-out so nesting is well-formed
        foreach (array_reverse($n['marks'] ?? []) as $mark) {
            $text = self::mark($mark, $text);
        }
        return $text;
    }

    private static function mark(array $mark, string $text): string
    {
        $attrs = $mark['attrs'] ?? [];
        return match ($mark['type'] ?? '') {
            'bold', 'strong' => "<strong>{$text}</strong>",
            'italic', 'em' => "<em>{$text}</em>",
            'underline' => "<u>{$text}</u>",
            'strike', 's' => "<s>{$text}</s>",
            'code' => "<code>{$text}</code>",
            'link' => '<a href="'.self::esc($attrs['href'] ?? '#').'">'.$text.'</a>',
            default => $text,
        };
    }

    /** Flatten a node's descendant text (used for code blocks). */
    private static function plain(array $n): string
    {
        if (($n['type'] ?? '') === 'text') {
            return $n['text'] ?? '';
        }
        return implode('', array_map(fn ($c) => is_array($c) ? self::plain($c) : '', $n['content'] ?? []));
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
