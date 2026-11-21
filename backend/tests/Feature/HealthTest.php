<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_and_framework_health_endpoints_respond(): void
    {
        $this->getJson('/api/health')->assertOk();
        $this->get('/up')->assertOk();
        $this->getJson('/')->assertOk();
    }

    public function test_dashboard_endpoint_is_routed_and_works_on_an_empty_database(): void
    {
        $this->getJson('/api/dashboard')->assertOk();
    }
}
