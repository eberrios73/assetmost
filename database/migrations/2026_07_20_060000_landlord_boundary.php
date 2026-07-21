<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The landlord/tenant boundary, made structural instead of implied by role.
     *
     * The platform operator gets its own company row (is_landlord) — the default
     * company every install ships with, and the future home of platform concerns
     * (outbound mail, password resets). Landlord users belong to it and reach
     * tenants only through explicit admin_company assignments; landlord SuperAdmins
     * see everything. Tenant users never cross their own company again — the old
     * "IT Admin sees all companies" grant ends here.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->boolean('is_landlord')->default(false)->index()->after('can_login');
        });
        Schema::table('companies', function (Blueprint $t) {
            $t->boolean('is_landlord')->default(false)->after('active');
        });
        Schema::create('admin_company', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->unique(['user_id', 'company_id']);
            $t->timestamps();
        });

        // Exactly one landlord company per install; create it if this DB predates one.
        $landlordId = DB::table('companies')->where('is_landlord', true)->value('id');
        if (! $landlordId) {
            $landlordId = DB::table('companies')->insertGetId([
                'name' => config('app.name', 'AssetMost'), 'is_landlord' => true, 'active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // Existing SuperAdmins become landlord SuperAdmins in the landlord company,
        // assigned to every tenant — which is exactly what they could already see.
        $tenantIds = DB::table('companies')->where('is_landlord', false)->pluck('id');
        foreach (DB::table('users')->where('role', 'SuperAdmin')->pluck('id') as $uid) {
            DB::table('users')->where('id', $uid)->update(['is_landlord' => true, 'company_id' => $landlordId]);
            foreach ($tenantIds as $cid) {
                DB::table('admin_company')->insert([
                    'user_id' => $uid, 'company_id' => $cid,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_company');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('is_landlord'));
        Schema::table('companies', fn (Blueprint $t) => $t->dropColumn('is_landlord'));
    }
};
