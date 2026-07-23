<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Device;
use App\Models\User;
use App\Support\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaletteTest extends TestCase
{
    use RefreshDatabase;

    private Company $acme;
    private Company $other;
    private User $editor;
    private Device $device;

    protected function setUp(): void
    {
        parent::setUp();
        $this->acme = Company::create(['name' => 'Acme', 'tag_prefix' => 'AC', 'tag_next' => 1001, 'local_domain' => 'acme.local', 'active' => true]);
        $this->other = Company::create(['name' => 'Other', 'tag_prefix' => 'OT', 'tag_next' => 1001, 'active' => true]);
        $this->editor = User::create([
            'name' => 'Ed', 'email' => 'ed@test.local', 'role' => Access::IT_ADMIN,
            'company_id' => $this->acme->id, 'can_login' => true, 'active' => true, 'password' => 'password',
        ]);
        $this->device = Device::create(['company_id' => $this->acme->id, 'asset_tag' => 'AC-WS-1501', 'computer_name' => 'AC-WS-1501', 'active' => true]);
        Device::create(['company_id' => $this->other->id, 'asset_tag' => 'OT-WS-1501', 'active' => true]);
    }

    public function test_search_resolves_a_bare_number_to_a_device_with_fqdn(): void
    {
        $r = $this->actingAs($this->editor)->getJson('/data/palette-search?q=1501')->assertOk();
        $labels = collect($r->json('results'))->pluck('label');
        $this->assertTrue($labels->contains('AC-WS-1501'));
        $device = collect($r->json('results'))->firstWhere('label', 'AC-WS-1501');
        $this->assertSame('ac-ws-1501.acme.local', $device['fqdn']);
    }

    public function test_search_never_crosses_the_tenant_wall(): void
    {
        $r = $this->actingAs($this->editor)->getJson('/data/palette-search?q=1501')->assertOk();
        $this->assertFalse(collect($r->json('results'))->pluck('label')->contains('OT-WS-1501'));
    }

    public function test_render_produces_the_registry_script_with_args_baked_in(): void
    {
        $device = $this->device;
        // /wifi ships in the registry with params "ssid, psk"
        $r = $this->actingAs($this->editor)
            ->getJson("/data/palette-render?command=wifi&args=Guest+s3cret&device_id={$device->id}")
            ->assertOk();
        $this->assertStringContainsString('"Guest" "s3cret"', $r->json('mac'));
    }

    public function test_render_404s_on_a_command_the_registry_does_not_know(): void
    {
        $device = $this->device;
        $this->actingAs($this->editor)
            ->getJson("/data/palette-render?command=nonesuch&device_id={$device->id}")
            ->assertNotFound();
    }
}
