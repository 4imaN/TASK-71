<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * Unauthenticated requests to the root URL should redirect to the login page.
     * The root route is behind the 'auth' middleware which issues a 302 redirect.
     */
    public function test_unauthenticated_root_redirects_to_login(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('/login');
    }
}
