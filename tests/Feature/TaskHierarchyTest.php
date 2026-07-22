<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Task;
use App\Models\User;
use App\Support\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskHierarchyTest extends TestCase
{
    use RefreshDatabase;

    private User $editor;
    private Company $co;

    protected function setUp(): void
    {
        parent::setUp();
        $this->co = Company::create(['name' => 'Acme', 'tag_prefix' => 'AC', 'tag_next' => 1001, 'active' => true]);
        $this->editor = User::create([
            'name' => 'Ed', 'email' => 'ed@test.local', 'role' => Access::IT_ADMIN,
            'company_id' => $this->co->id, 'can_login' => true, 'active' => true, 'password' => 'password',
        ]);
    }

    private function make(array $attrs): Task
    {
        return Task::create($attrs + ['company_id' => $this->co->id, 'week' => now()->startOfWeek()]);
    }

    public function test_a_milestone_must_belong_to_a_project(): void
    {
        $project = $this->make(['title' => 'P', 'kind' => 'project']);
        $task = $this->make(['title' => 'T']);

        $this->actingAs($this->editor)->postJson('/data/tasks', [
            'title' => 'M', 'kind' => 'milestone', 'parent_id' => $project->id,
        ])->assertCreated();

        $this->actingAs($this->editor)->postJson('/data/tasks', [
            'title' => 'M2', 'kind' => 'milestone', 'parent_id' => $task->id,
        ])->assertStatus(422);

        $this->actingAs($this->editor)->postJson('/data/tasks', [
            'title' => 'M3', 'kind' => 'milestone',
        ])->assertStatus(422);
    }

    public function test_state_and_done_never_disagree(): void
    {
        $t = $this->make(['title' => 'T']);

        $this->actingAs($this->editor)->patchJson("/data/tasks/{$t->id}", ['state' => 'doing'])->assertOk();
        $this->assertFalse($t->fresh()->done);

        $this->actingAs($this->editor)->patchJson("/data/tasks/{$t->id}", ['state' => 'done'])->assertOk();
        $t->refresh();
        $this->assertTrue($t->done);
        $this->assertSame(100, (int) $t->pct);

        $this->actingAs($this->editor)->patchJson("/data/tasks/{$t->id}", ['done' => false])->assertOk();
        $t->refresh();
        $this->assertSame('todo', $t->state);
        $this->assertSame(0, (int) $t->pct);

        // The checkbox path lands on done too.
        $this->actingAs($this->editor)->patchJson("/data/tasks/{$t->id}", ['done' => true])->assertOk();
        $this->assertSame('done', $t->fresh()->state);
    }

    public function test_links_attach_and_prs_label_themselves(): void
    {
        $t = $this->make(['title' => 'T']);

        $res = $this->actingAs($this->editor)->postJson("/data/tasks/{$t->id}/links", [
            'url' => 'https://github.com/acme/app/pull/7',
        ])->assertCreated();
        $this->assertSame('acme/app #7', $res->json('label'));

        $other = $this->make(['title' => 'Other']);
        $linkId = $res->json('id');
        // A link can only be deleted through its own task.
        $this->actingAs($this->editor)->deleteJson("/data/tasks/{$other->id}/links/{$linkId}")->assertNotFound();
        $this->actingAs($this->editor)->deleteJson("/data/tasks/{$t->id}/links/{$linkId}")->assertOk();
        $this->assertSame(0, $t->links()->count());
    }

    public function test_subprojects_are_projects_under_projects(): void
    {
        $this->actingAs($this->editor);   // the company global scope needs a viewer
        $parent = $this->make(['title' => 'P', 'kind' => 'project']);
        $child = $this->make(['title' => 'Sub', 'kind' => 'project', 'parent_id' => $parent->id]);
        $this->assertTrue($child->fresh()->isProject());
        $this->assertSame($parent->id, $child->fresh()->parent->id);
    }
}
