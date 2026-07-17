<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Public registration is deliberately GONE. This app is an IT-managed credential
 * registry — accounts are created by an admin on the People screen, and sign-in is a
 * granted flag, not something you help yourself to with a form. These tests pin that
 * decision so scaffolding can't quietly bring the route back.
 */
class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_registration_screen_does_not_exist(): void
    {
        $this->get('/register')->assertNotFound();
    }

    public function test_nobody_can_register_an_account(): void
    {
        $this->post('/register', [
            'name' => 'Walk In',
            'email' => 'walkin@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertNotFound();

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'walkin@example.com']);
    }

    public function test_a_created_person_still_cannot_sign_in_without_the_flag(): void
    {
        $user = User::factory()->create();   // password set, can_login false

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $this->assertGuest();
    }
}
