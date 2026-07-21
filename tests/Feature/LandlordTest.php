<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Support\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LandlordTest extends TestCase
{
    use RefreshDatabase;

    private Company $landlordCo;
    private Company $tenantA;
    private Company $tenantB;

    protected function setUp(): void
    {
        parent::setUp();
        Access::forget();
        // The landlord migration itself guarantees one landlord company per install.
        $this->landlordCo = Company::landlord();
        $this->tenantA = Company::create(['name' => 'Tenant A', 'tag_prefix' => 'TA', 'tag_next' => 1001, 'active' => true]);
        $this->tenantB = Company::create(['name' => 'Tenant B', 'tag_prefix' => 'TB', 'tag_next' => 1001, 'active' => true]);
    }

    private function user(string $role, bool $landlord, ?Company $company = null, array $attrs = []): User
    {
        return User::create([
            'name' => 'Test', 'last' => str_replace(' ', '', $role),
            'email' => str()->random(10).'@test.local',
            'role' => $role, 'active' => true, 'can_login' => true,
            'is_landlord' => $landlord,
            'company_id' => ($company ?? ($landlord ? $this->landlordCo : $this->tenantA))->id,
            'password' => 'password',
        ] + $attrs);
    }

    // ---- Visibility ----

    public function test_landlord_superadmin_sees_every_company(): void
    {
        $super = $this->user(Access::SUPER_ADMIN, true);
        $this->assertEqualsCanonicalizing(
            [$this->landlordCo->id, $this->tenantA->id, $this->tenantB->id],
            $super->managedCompanyIds(),
        );
    }

    public function test_landlord_admin_sees_only_assigned_tenants(): void
    {
        $admin = $this->user(Access::ADMIN, true);
        $admin->managedCompanies()->sync([$this->tenantA->id]);
        $this->assertEqualsCanonicalizing(
            [$this->landlordCo->id, $this->tenantA->id],
            $admin->managedCompanyIds(),
        );
    }

    public function test_tenant_it_admin_no_longer_crosses_companies(): void
    {
        $it = $this->user(Access::IT_ADMIN, false, $this->tenantA);
        $this->assertSame([$this->tenantA->id], $it->managedCompanyIds());
    }

    // ---- Companies ----

    public function test_only_landlord_superadmin_creates_companies(): void
    {
        $payload = ['name' => 'New Co', 'tag_prefix' => 'NC'];

        $this->actingAs($this->user(Access::IT_ADMIN, false, $this->tenantA))
            ->postJson('/data/companies', $payload)->assertForbidden();
        $admin = $this->user(Access::ADMIN, true);
        $this->actingAs($admin)->postJson('/data/companies', $payload)->assertForbidden();

        $this->actingAs($this->user(Access::SUPER_ADMIN, true))
            ->postJson('/data/companies', $payload)->assertCreated();
    }

    public function test_a_company_out_of_sight_cannot_be_read_or_edited(): void
    {
        $admin = $this->user(Access::ADMIN, true);
        $admin->managedCompanies()->sync([$this->tenantA->id]);

        $this->actingAs($admin)->getJson("/data/companies/{$this->tenantB->id}")->assertForbidden();
        $this->actingAs($admin)->patchJson("/data/companies/{$this->tenantB->id}", ['name' => 'X'])->assertForbidden();
        $this->actingAs($admin)->patchJson("/data/companies/{$this->tenantA->id}", ['name' => 'Renamed A'])->assertOk();
    }

    // ---- People filing ----

    public function test_nobody_files_a_person_into_a_company_they_cannot_see(): void
    {
        $admin = $this->user(Access::ADMIN, true);
        $admin->managedCompanies()->sync([$this->tenantA->id]);

        $this->actingAs($admin)->postJson('/data/people', [
            'name' => 'Stray', 'email' => 'stray@test.local', 'company_id' => $this->tenantB->id,
        ])->assertForbidden();

        $this->actingAs($admin)->postJson('/data/people', [
            'name' => 'Placed', 'email' => 'placed@test.local', 'company_id' => $this->tenantA->id,
        ])->assertCreated();
    }

    public function test_people_endpoints_refuse_landlord_roles(): void
    {
        $super = $this->user(Access::SUPER_ADMIN, true);
        $this->actingAs($super)->postJson('/data/people', [
            'name' => 'Sneaky', 'email' => 'sneaky@test.local',
            'role' => Access::SUPER_ADMIN, 'company_id' => $this->tenantA->id,
        ])->assertStatus(422);
    }

    // ---- Landlord user management ----

    public function test_only_landlord_manage_may_touch_landlord_users(): void
    {
        $tenant = $this->user(Access::IT_ADMIN, false, $this->tenantA);
        $this->actingAs($tenant)->postJson('/settings/landlord/users', [
            'name' => 'L', 'email' => 'l@test.local', 'role' => Access::USER, 'password' => 'password',
        ])->assertForbidden();

        $super = $this->user(Access::SUPER_ADMIN, true);
        $this->actingAs($super)->postJson('/settings/landlord/users', [
            'name' => 'L', 'email' => 'l@test.local', 'role' => Access::ADMIN, 'password' => 'password',
            'company_ids' => [$this->tenantA->id],
        ])->assertCreated();

        $made = User::where('email', 'l@test.local')->first();
        $this->assertTrue($made->isLandlord());
        $this->assertSame($this->landlordCo->id, $made->company_id);
        $this->assertEqualsCanonicalizing(
            [$this->landlordCo->id, $this->tenantA->id],
            $made->managedCompanyIds(),
        );
    }

    public function test_admin_cannot_assign_tenants_outside_their_horizon(): void
    {
        // Give landlord Admins the manage permission for this scenario.
        \App\Models\RolePermission::create(['role' => Access::ADMIN, 'permission' => 'landlord.manage', 'allowed' => true]);
        Access::forget();

        $admin = $this->user(Access::ADMIN, true);
        $admin->managedCompanies()->sync([$this->tenantA->id]);
        $target = $this->user(Access::USER, true);

        $this->actingAs($admin)->patchJson("/settings/landlord/users/{$target->id}", [
            'company_ids' => [$this->tenantA->id, $this->tenantB->id],
        ])->assertOk();

        // B was out of the actor's sight — silently dropped, never granted.
        $this->assertEqualsCanonicalizing(
            [$this->tenantA->id],
            $target->managedCompanies()->pluck('companies.id')->all(),
        );
    }

    public function test_only_a_superadmin_can_mint_or_touch_a_superadmin(): void
    {
        \App\Models\RolePermission::create(['role' => Access::ADMIN, 'permission' => 'landlord.manage', 'allowed' => true]);
        Access::forget();

        $admin = $this->user(Access::ADMIN, true);
        $super = $this->user(Access::SUPER_ADMIN, true);

        $this->actingAs($admin)->postJson('/settings/landlord/users', [
            'name' => 'S', 'email' => 's@test.local', 'role' => Access::SUPER_ADMIN, 'password' => 'password',
        ])->assertForbidden();

        $this->actingAs($admin)->patchJson("/settings/landlord/users/{$super->id}", [
            'can_login' => false,
        ])->assertForbidden();
    }

    public function test_the_last_superadmin_cannot_be_demoted_or_locked_out(): void
    {
        $super = $this->user(Access::SUPER_ADMIN, true);
        $this->actingAs($super)->patchJson("/settings/landlord/users/{$super->id}", [
            'role' => Access::ADMIN,
        ])->assertStatus(422);
        $this->actingAs($super)->patchJson("/settings/landlord/users/{$super->id}", [
            'can_login' => false,
        ])->assertStatus(422);
    }
}
