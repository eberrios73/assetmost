<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A credential can be held by MANY people, and answers "what is this for?" exactly one way.
 *
 * Single-valued logins.user_id couldn't express:
 *   - a pooled seat (ppdesigner1@) — forced a fake employee record to exist
 *   - a shared mailbox (ithelpdesk@) — 10 people, so it was left ownerless
 *   - an infra credential (ITAdmin on 6 servers) — no owner, no vendor
 * ...which is why ~37% of real-world logins had no owner at all.
 *
 * device_id / product_id / vendor_id are mutually exclusive by convention:
 *   device_id  -> infrastructure credential for an asset (ITAdmin @ Mail_Arch_Srv)
 *   product_id -> software account consuming a license seat
 *   vendor_id  -> service account, no seats (Godaddy, CloudFlare)
 *   none       -> a person's directory identity, a wifi SSID
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('login_access', function (Blueprint $t) {
            $t->id();
            $t->foreignId('login_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->timestamps();
            $t->unique(['login_id', 'user_id']);
        });

        // Carry existing single assignments over before the column goes.
        // CURRENT_TIMESTAMP, not NOW(): NOW() is MySQL-only and this has to run on the
        // sqlite the test suite builds, otherwise nothing downstream of it is testable.
        DB::statement('
            INSERT INTO login_access (login_id, user_id, created_at, updated_at)
            SELECT id, user_id, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP FROM logins WHERE user_id IS NOT NULL
        ');

        Schema::table('logins', function (Blueprint $t) {
            $t->dropConstrainedForeignId('user_id');   // superseded by login_access
            $t->foreignId('device_id')->nullable()->after('vendor_id')->constrained()->nullOnDelete();
            // personal = one human, permanently | pooled = one at a time, reassignable
            // shared   = many at once (a mailbox)
            // A license-consuming seat with >1 holder and sharing<>'shared' is a
            // compliance violation the UI can surface for free.
            $t->string('sharing', 10)->default('personal')->after('type')->index();
        });
    }

    public function down(): void
    {
        Schema::table('logins', function (Blueprint $t) {
            $t->dropConstrainedForeignId('device_id');
            $t->dropColumn('sharing');
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
        });
        Schema::dropIfExists('login_access');
    }
};
