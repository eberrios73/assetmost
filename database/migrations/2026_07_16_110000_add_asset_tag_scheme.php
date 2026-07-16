<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-company asset-tag scheme: XX-XX-XXXX  (PG-WS-1001)
 *
 *   tag_prefix  the company's 2-letter prefix (PG, AC)
 *   tag_next    the next number to issue. ONE line per company, shared across types —
 *               matches how the legacy numbers actually ran (642=Laptop, 643=Laptop,
 *               644=Workstation). Only ever increments; never reused.
 *
 * Starts at 1001 so it cannot collide with the legacy CPU tags (which stop around 644).
 * Legacy tags stay in asset_tag untouched — the old scheme just stops growing.
 *
 * `tag_next` is a counter, not MAX(n)+1 on purpose: retire the highest device and MAX+1
 * re-issues its number, colliding with a sticker that's still on the hardware.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            $t->string('tag_prefix', 4)->nullable()->after('name');
            $t->unsignedBigInteger('tag_next')->default(1001)->after('tag_prefix');
        });

        Schema::table('devices', function (Blueprint $t) {
            // Uniqueness is enforced here, not by the generator being clever.
            // Nullable asset_tag is fine: MySQL allows multiple NULLs in a unique index.
            $t->unique(['company_id', 'asset_tag']);
        });
    }

    public function down(): void
    {
        Schema::table('devices', fn (Blueprint $t) => $t->dropUnique(['company_id', 'asset_tag']));
        Schema::table('companies', fn (Blueprint $t) => $t->dropColumn(['tag_prefix', 'tag_next']));
    }
};
