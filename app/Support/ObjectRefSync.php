<?php

namespace App\Support;

use App\Models\ObjectRef;

/**
 * Keeps object_refs matching what the content actually says. @-mention pills
 * serialize as <span data-ref="device:12">; on every save the edges are diffed
 * against the pills — mentions removed from the text lose their rows, so the
 * graph never claims a connection the content no longer makes.
 */
class ObjectRefSync
{
    /** Types a pill may point at — matches the palette resolver's output. */
    public const TYPES = ['device', 'person', 'doc', 'task'];

    public static function sync(string $fromType, int $fromId, ?string $html): void
    {
        $found = [];
        if ($html && preg_match_all('/data-ref="(\w+):(\d+)"/', $html, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                if (in_array($hit[1], self::TYPES, true)) {
                    $found["{$hit[1]}:{$hit[2]}"] = ['to_type' => $hit[1], 'to_id' => (int) $hit[2]];
                }
            }
        }

        $existing = ObjectRef::query()->where(['from_type' => $fromType, 'from_id' => $fromId])->get();
        foreach ($existing as $row) {
            $key = "{$row->to_type}:{$row->to_id}";
            if (! isset($found[$key])) $row->delete();
            else unset($found[$key]);
        }
        foreach ($found as $new) {
            ObjectRef::create(['from_type' => $fromType, 'from_id' => $fromId] + $new);
        }
    }

    public static function forget(string $fromType, int $fromId): void
    {
        ObjectRef::query()->where(['from_type' => $fromType, 'from_id' => $fromId])->delete();
    }
}
