<?php

namespace Tests\Feature\Health;

use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    public function test_health_endpoint_returns_200_when_db_connected(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertStatus(200)
            ->assertJsonStructure(['status', 'service', 'checks', 'time'])
            ->assertJsonPath('service', 'research-services')
            ->assertJsonPath('checks.database', 'ok');
    }

    public function test_laravel_up_endpoint_is_accessible(): void
    {
        $response = $this->get('/up');
        $response->assertSuccessful();
    }
}
