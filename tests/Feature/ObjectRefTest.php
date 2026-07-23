<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Device;
use App\Models\DocPage;
use App\Models\ObjectRef;
use App\Models\User;
use App\Support\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ObjectRefTest extends TestCase
{
    use RefreshDatabase;

    private User $editor;
    private Device $device;

    protected function setUp(): void
    {
        parent::setUp();
        $co = Company::create(['name' => 'Acme', 'tag_prefix' => 'AC', 'tag_next' => 1001, 'active' => true]);
        $this->editor = User::create([
            'name' => 'Ed', 'email' => 'ed@test.local', 'role' => Access::IT_ADMIN,
            'company_id' => $co->id, 'can_login' => true, 'active' => true, 'password' => 'password',
        ]);
        $this->device = Device::create(['company_id' => $co->id, 'asset_tag' => 'AC-WS-1501', 'active' => true]);
    }

    private function pill(): string
    {
        return '<p>Reimage <span data-ref="device:'.$this->device->id.'" class="obj-ref">@AC-WS-1501</span></p>';
    }

    public function test_a_mention_in_a_saved_doc_becomes_an_edge(): void
    {
        $r = $this->actingAs($this->editor)->postJson('/data/docs', [
            'title' => 'Runbook', 'body' => $this->pill(),
        ])->assertCreated();

        $this->assertDatabaseHas('object_refs', [
            'from_type' => 'doc', 'from_id' => $r->json('id'),
            'to_type' => 'device', 'to_id' => $this->device->id,
        ]);
    }

    public function test_removing_the_mention_removes_the_edge(): void
    {
        $id = $this->actingAs($this->editor)->postJson('/data/docs', [
            'title' => 'Runbook', 'body' => $this->pill(),
        ])->json('id');

        $this->actingAs($this->editor)->patchJson("/data/docs/{$id}", ['body' => '<p>nothing here</p>'])->assertOk();
        $this->assertSame(0, ObjectRef::count());
    }

    public function test_deleting_the_doc_forgets_its_edges(): void
    {
        $id = $this->actingAs($this->editor)->postJson('/data/docs', [
            'title' => 'Runbook', 'body' => $this->pill(),
        ])->json('id');

        $this->actingAs($this->editor)->deleteJson("/data/docs/{$id}")->assertOk();
        $this->assertSame(0, ObjectRef::count());
    }

    public function test_backlinks_name_the_referencing_doc(): void
    {
        $id = $this->actingAs($this->editor)->postJson('/data/docs', [
            'title' => 'Mac rebuild SOP', 'body' => $this->pill(),
        ])->json('id');

        $r = $this->actingAs($this->editor)
            ->getJson("/data/refs?type=device&id={$this->device->id}")->assertOk();
        $this->assertSame([['type' => 'doc', 'id' => $id, 'label' => 'Mac rebuild SOP', 'sub' => 'doc']],
            $r->json('refs'));
    }

    public function test_an_unknown_ref_type_in_content_is_ignored(): void
    {
        $this->actingAs($this->editor)->postJson('/data/docs', [
            'title' => 'Odd', 'body' => '<span data-ref="dragon:9">@Smaug</span>',
        ])->assertCreated();
        $this->assertSame(0, ObjectRef::count());
    }
}
