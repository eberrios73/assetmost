<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vendors are company-scoped, not shared: each company keeps its own vendor list
 * (the model's BelongsToCompany scope assumes this column). The company_vendor
 * pivot remains for the legacy many-to-many view but the app writes company_id.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $t) {
            $t->foreignId('company_id')->nullable()->after('id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('vendors', fn (Blueprint $t) => $t->dropConstrainedForeignId('company_id'));
    }
};
