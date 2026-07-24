<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\DocTemplate;
use App\Models\User;
use App\Support\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocTemplateTest extends TestCase
{
    use RefreshDatabase;

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();
        $co = Company::create(['name' => 'Acme', 'tag_prefix' => 'AC', 'tag_next' => 1001, 'active' => true]);
        $this->editor = User::create([
            'name' => 'Ed', 'email' => 'ed@test.local', 'role' => Access::IT_ADMIN,
            'company_id' => $co->id, 'can_login' => true, 'active' => true, 'password' => 'password',
        ]);
    }

    public function test_a_company_authors_its_own_template_and_edits_it(): void
    {
        $id = $this->actingAs($this->editor)->postJson('/data/doc-templates', ['label' => 'Site Survey'])
            ->assertCreated()->json('id');

        $this->actingAs($this->editor)->patchJson("/data/doc-templates/{$id}", [
            'body' => '<h2>Walk the site</h2>', 'category' => 'Reference', 'hint' => 'On-site checklist',
        ])->assertOk();

        $r = $this->actingAs($this->editor)->getJson('/data/doc-templates')->assertOk();
        $this->assertSame('Site Survey', $r->json('0.label'));
        $this->assertSame('<h2>Walk the site</h2>', $r->json('0.body'));

        $this->actingAs($this->editor)->deleteJson("/data/doc-templates/{$id}")->assertOk();
        $this->assertSame(0, DocTemplate::count());
    }

    public function test_readonly_users_cannot_author_templates(): void
    {
        $reader = User::create([
            'name' => 'Ro', 'email' => 'ro@test.local', 'role' => Access::USER,
            'company_id' => $this->editor->company_id, 'can_login' => true, 'active' => true, 'password' => 'password',
        ]);
        $this->actingAs($reader)->postJson('/data/doc-templates', ['label' => 'Nope'])->assertForbidden();
    }
}
