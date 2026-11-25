<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_root_redirects_to_the_console(): void
    {
        $this->get('/')->assertRedirect('/console');
    }

    /**
     * The console is behind login: guests are sent to the login page.
     */
    public function test_guests_are_redirected_from_the_console_to_login(): void
    {
        $this->get(route('console'))->assertRedirect(route('login'));
    }

    public function test_the_login_page_renders(): void
    {
        $this->get(route('login'))->assertOk();
    }
}
