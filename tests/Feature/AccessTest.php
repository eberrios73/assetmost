<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Login;
use App\Models\RolePermission;
use App\Models\User;
use App\Support\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class AccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Access::forget();
    }

    private function person(string $role, array $attrs = []): User
    {
        $company = Company::first() ?? Company::create([
            'name' => 'Acme', 'tag_prefix' => 'AC', 'tag_next' => 1001, 'active' => true,
        ]);

        return User::create([
            'name' => 'Test', 'last' => str_replace(' ', '', $role),
            'email' => str()->random(10).'@test.local',
            'role' => $role, 'active' => true, 'company_id' => $company->id,
        ] + $attrs);
    }

    // ---- can_login ----

    public function test_a_password_alone_does_not_grant_access(): void
    {
        $u = $this->person('User', ['password' => 'secret1234']);

        $this->assertFalse($u->fresh()->can_login);
        $this->assertFalse(Auth::attempt([
            'email' => $u->email, 'password' => 'secret1234', 'can_login' => true, 'active' => true,
        ]));
    }

    public function test_a_person_can_sign_in_once_can_login_is_granted(): void
    {
        $u = $this->person('IT Admin', ['password' => 'secret1234', 'can_login' => true]);

        $this->post('/login', ['email' => $u->email, 'password' => 'secret1234']);

        $this->assertAuthenticatedAs($u);
    }

    public function test_revoking_can_login_locks_out_a_valid_password(): void
    {
        $u = $this->person('IT Admin', ['password' => 'secret1234', 'can_login' => true]);
        $u->update(['can_login' => false]);

        $this->post('/login', ['email' => $u->email, 'password' => 'secret1234'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    // ---- the levels ----

    public function test_operations_gets_everything_except_passwords(): void
    {
        $op = $this->person('Operations');

        $this->assertTrue($op->may('assets.edit'));
        $this->assertTrue($op->may('people.edit'));
        $this->assertTrue($op->may('licenses.edit'));
        $this->assertTrue($op->may('logins.view'));
        $this->assertFalse($op->may(Access::REVEAL));
    }

    public function test_it_admin_reveals_passwords_and_plain_user_does_not(): void
    {
        $this->assertTrue($this->person('IT Admin')->may(Access::REVEAL));
        $this->assertFalse($this->person('User')->may(Access::REVEAL));
    }

    public function test_an_override_from_the_roles_screen_takes_effect(): void
    {
        $op = $this->person('Operations');
        $this->assertFalse($op->may(Access::REVEAL));

        RolePermission::create(['role' => 'Operations', 'permission' => Access::REVEAL, 'allowed' => true]);

        $this->assertTrue($op->fresh()->may(Access::REVEAL));
    }

    public function test_superadmin_cannot_be_stripped_of_anything(): void
    {
        RolePermission::create(['role' => 'SuperAdmin', 'permission' => 'settings.manage', 'allowed' => false]);

        $this->assertTrue($this->person('SuperAdmin')->may('settings.manage'));
    }

    // ---- the screen ----

    public function test_settings_screen_renders_the_matrix(): void
    {
        $su = $this->person('SuperAdmin', ['password' => 'secret1234', 'can_login' => true]);

        $this->actingAs($su)->get('/settings')
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->component('Settings/Index')
                ->where('access.editable', true)
                ->where('access.locked', 'SuperAdmin')
                // Fetched whole: permission keys contain dots, which `where()` would
                // read as a nested path.
                ->has('access.matrix', fn ($m) => $m
                    ->where('Operations', fn ($r) => $r[Access::REVEAL] === false && $r['assets.edit'] === true)
                    ->where('IT Admin', fn ($r) => $r[Access::REVEAL] === true)
                    ->where('SuperAdmin', fn ($r) => $r[Access::REVEAL] === true)
                    ->where('User', fn ($r) => $r[Access::REVEAL] === false))
                ->etc());
    }

    public function test_the_endpoint_refuses_to_write_a_superadmin_override(): void
    {
        $su = $this->person('SuperAdmin', ['password' => 'secret1234', 'can_login' => true]);

        $this->actingAs($su)->patchJson('/settings/roles', [
            'matrix' => [
                'SuperAdmin' => [Access::REVEAL => false],
                'Operations' => [Access::REVEAL => true],
            ],
        ])->assertOk();

        $this->assertFalse(RolePermission::where('role', 'SuperAdmin')->exists());
        $this->assertTrue(Access::allows('Operations', Access::REVEAL));
    }

    public function test_only_differences_from_the_defaults_are_stored(): void
    {
        $su = $this->person('SuperAdmin', ['password' => 'secret1234', 'can_login' => true]);

        $this->actingAs($su)->patchJson('/settings/roles', ['matrix' => Access::matrix()])->assertOk();

        $this->assertSame(0, RolePermission::count());
    }

    public function test_a_non_admin_cannot_edit_the_matrix(): void
    {
        $op = $this->person('Operations', ['password' => 'secret1234', 'can_login' => true]);

        $this->actingAs($op)->patchJson('/settings/roles', ['matrix' => []])->assertForbidden();
    }

    // ---- enforcement ----

    public function test_the_reveal_endpoint_follows_the_matrix_not_a_hardcoded_role(): void
    {
        $op = $this->person('Operations', ['password' => 'secret1234', 'can_login' => true]);
        $login = Login::create([
            'login_name' => 'svc@acme.com', 'login_pass' => 'hunter2',
            'company_id' => $op->company_id, 'active' => true,
        ]);

        $this->actingAs($op)->getJson("/data/logins/{$login->id}/secret")->assertForbidden();

        RolePermission::create(['role' => 'Operations', 'permission' => Access::REVEAL, 'allowed' => true]);

        $this->actingAs($op)->getJson("/data/logins/{$login->id}/secret")
            ->assertOk()->assertJsonPath('password', 'hunter2');
    }
}
