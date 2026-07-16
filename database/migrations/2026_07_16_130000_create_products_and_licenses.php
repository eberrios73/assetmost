<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * vendor -> product -> license(seats) -> accounts -> people
 *
 * Products are the missing layer. Without them, product names get filed as vendors
 * ("Adobe Creative Suite" as a sibling of "Adobe"), and a vendor's seats fragment across
 * rows that can never be summed.
 *
 * Product stays separate from license because a product is a catalog fact ("Creative
 * Cloud All Apps") while a license is a per-company purchase ("25 seats, renews January").
 * Two companies buying the same product is the normal case, not an edge case.
 *
 * license_login replaces subscriptions.login_id: one account can consume seats of
 * several licenses (one Adobe login holding both CC and Acrobat).
 *
 * seats_total is nullable by design — real seat counts get backfilled as the data is
 * cleaned up. Available renders as unknown until then; it does not gate anything.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $t->string('name');                 // "Creative Cloud All Apps", "Acrobat Pro"
            $t->string('sku')->nullable();
            $t->text('notes')->nullable();
            $t->boolean('active')->default(true)->index();
            $t->timestamps();
            $t->unique(['vendor_id', 'name']);
        });

        Schema::rename('subscriptions', 'licenses');

        Schema::table('licenses', function (Blueprint $t) {
            $t->foreignId('product_id')->nullable()->after('vendor_id')->constrained()->nullOnDelete();
            $t->unsignedInteger('seats_total')->nullable()->after('serial_number');
            $t->renameColumn('subscription_name', 'name');
        });

        Schema::create('license_login', function (Blueprint $t) {
            $t->id();
            $t->foreignId('license_id')->constrained()->cascadeOnDelete();
            $t->foreignId('login_id')->constrained()->cascadeOnDelete();
            $t->timestamps();
            $t->unique(['license_id', 'login_id']);
        });

        // Carry the old 1:1 login link into the pivot before dropping it.
        DB::statement('
            INSERT INTO license_login (license_id, login_id, created_at, updated_at)
            SELECT id, login_id, NOW(), NOW() FROM licenses WHERE login_id IS NOT NULL
        ');

        Schema::table('licenses', function (Blueprint $t) {
            // Renaming a table does NOT rename its foreign keys — these are still named
            // after `subscriptions`, so they have to be dropped by their real names
            // rather than via dropConstrainedForeignId()'s guess.
            $t->dropForeign('subscriptions_login_id_foreign');
            $t->dropColumn('login_id');                 // superseded by license_login
            $t->dropForeign('subscriptions_user_id_foreign');
            $t->dropColumn('user_id');                  // holders come via login_access
        });

        // A login can point at the product whose seat it consumes.
        Schema::table('logins', function (Blueprint $t) {
            $t->foreignId('product_id')->nullable()->after('device_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('logins', fn (Blueprint $t) => $t->dropConstrainedForeignId('product_id'));
        Schema::dropIfExists('license_login');
        Schema::table('licenses', function (Blueprint $t) {
            $t->renameColumn('name', 'subscription_name');
            $t->dropConstrainedForeignId('product_id');
            $t->dropColumn('seats_total');
            $t->foreignId('login_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
        });
        Schema::rename('licenses', 'subscriptions');
        Schema::dropIfExists('products');
    }
};
