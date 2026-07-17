<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /** There is no public page: guests land on login, nothing else. */
    public function test_the_root_sends_guests_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
    }
}
