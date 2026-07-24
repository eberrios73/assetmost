<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Device;
use App\Models\Task;
use App\Models\User;
use App\Support\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class NotesCanvasTest extends TestCase
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

    public function test_a_mention_in_task_notes_becomes_an_edge_and_a_backlink(): void
    {
        $notes = '<p>swap <span data-ref="device:'.$this->device->id.'">@AC-WS-1501</span></p>';
        $id = $this->actingAs($this->editor)->postJson('/data/tasks', [
            'title' => 'Swap the disk', 'notes' => $notes,
        ])->json('id');

        $this->assertDatabaseHas('object_refs', [
            'from_type' => 'task', 'from_id' => $id,
            'to_type' => 'device', 'to_id' => $this->device->id,
        ]);

        $r = $this->actingAs($this->editor)->getJson("/data/refs?type=device&id={$this->device->id}")->assertOk();
        $this->assertSame('Swap the disk', $r->json('refs.0.label'));

        // Clearing the notes clears the edge.
        $this->actingAs($this->editor)->patchJson("/data/tasks/{$id}", ['notes' => null])->assertOk();
        $this->assertDatabaseMissing('object_refs', ['from_type' => 'task', 'from_id' => $id]);
    }

    public function test_pasted_screenshots_upload_as_files_never_base64(): void
    {
        Storage::fake('public');
        $r = $this->actingAs($this->editor)->post('/data/uploads', [
            'image' => UploadedFile::fake()->image('shot.png', 800, 600),
        ])->assertCreated();
        $this->assertStringStartsWith('/storage/pasted/', $r->json('url'));
        Storage::disk('public')->assertExists(str_replace('/storage/', '', $r->json('url')));
    }

    public function test_uploads_refuse_non_images_and_readonly_users(): void
    {
        Storage::fake('public');
        $this->actingAs($this->editor)->post('/data/uploads', [
            'image' => UploadedFile::fake()->create('evil.php', 10, 'text/x-php'),
        ])->assertSessionHasErrors('image');

        $reader = User::create([
            'name' => 'Ro', 'email' => 'ro@test.local', 'role' => Access::USER,
            'company_id' => $this->editor->company_id, 'can_login' => true, 'active' => true, 'password' => 'password',
        ]);
        $this->actingAs($reader)->post('/data/uploads', [
            'image' => UploadedFile::fake()->image('shot.png'),
        ])->assertForbidden();
    }
}
