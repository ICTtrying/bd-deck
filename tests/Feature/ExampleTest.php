<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * De app staat altijd achter het hoofdwachtwoord.
     */
    public function test_the_application_requires_unlocking(): void
    {
        $response = $this->get('/');

        $response->assertRedirect(route('login'));
    }
}
