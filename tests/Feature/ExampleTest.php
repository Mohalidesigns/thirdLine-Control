<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The root URL hands guests to the login screen via the dashboard.
     */
    public function test_the_application_redirects_guests_to_login(): void
    {
        $this->get('/')->assertRedirect('/dashboard');

        $this->get('/dashboard')->assertRedirect('/login');

        $this->get('/login')->assertStatus(200);
    }
}
