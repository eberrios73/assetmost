<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Task;
use App\Models\User;
use App\Support\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskCommentTest extends TestCase
{
    use RefreshDatabase;

    private User $sam;
    private User $lisa;
    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();
        $co = Company::create(['name' => 'Acme', 'tag_prefix' => 'AC', 'tag_next' => 1001, 'active' => true]);
        $mk = fn ($n, $e) => User::create([
            'name' => $n, 'email' => $e, 'role' => Access::OPERATIONS,
            'company_id' => $co->id, 'can_login' => true, 'active' => true, 'password' => 'password',
        ]);
        $this->sam = $mk('Sam', 'sam@test.local');
        $this->lisa = $mk('Lisa', 'lisa@test.local');
        $this->task = Task::create(['company_id' => $co->id, 'title' => 'T', 'week' => now()->startOfWeek()]);
    }

    public function test_log_lines_append_stamped_with_their_author(): void
    {
        $this->actingAs($this->sam)->postJson("/data/tasks/{$this->task->id}/comments", ['body' => 'waiting on vendor'])
            ->assertCreated()->assertJsonPath('author', 'Sam')->assertJsonPath('mine', true);

        $r = $this->actingAs($this->lisa)->getJson("/data/tasks/{$this->task->id}/comments")->assertOk();
        $this->assertSame('waiting on vendor', $r->json('0.body'));
        $this->assertSame('Sam', $r->json('0.author'));
        $this->assertFalse($r->json('0.mine'));
    }

    public function test_only_the_author_or_an_admin_deletes_a_line(): void
    {
        $id = $this->actingAs($this->sam)->postJson("/data/tasks/{$this->task->id}/comments", ['body' => 'typo'])
            ->json('id');

        $this->actingAs($this->lisa)->deleteJson("/data/tasks/{$this->task->id}/comments/{$id}")->assertForbidden();
        $this->actingAs($this->sam)->deleteJson("/data/tasks/{$this->task->id}/comments/{$id}")->assertOk();
        $this->assertSame(0, $this->task->comments()->count());
    }

    public function test_a_comment_only_deletes_through_its_own_task(): void
    {
        $other = Task::create(['company_id' => $this->task->company_id, 'title' => 'Other', 'week' => now()->startOfWeek()]);
        $id = $this->actingAs($this->sam)->postJson("/data/tasks/{$this->task->id}/comments", ['body' => 'x'])->json('id');
        $this->actingAs($this->sam)->deleteJson("/data/tasks/{$other->id}/comments/{$id}")->assertNotFound();
    }
}
