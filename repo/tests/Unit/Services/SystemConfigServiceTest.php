<?php

namespace Tests\Unit\Services;

use App\Services\Admin\SystemConfigService;
use Tests\TestCase;

class SystemConfigServiceTest extends TestCase
{
    public function test_returns_default_when_key_missing(): void
    {
        $service = new SystemConfigService();
        // When DB is available and seeded: returns DB value; when not seeded: returns default
        // This test verifies the default fallback only (no DB dependency)
        $value = $service->getInt('nonexistent_key_xyz', 42);
        $this->assertEquals(42, $value);
    }

    public function test_typed_accessor_returns_correct_integer(): void
    {
        $service = new SystemConfigService();
        $this->assertIsInt($service->passwordMinLength());
    }

    public function test_typed_accessor_returns_correct_float(): void
    {
        $service = new SystemConfigService();
        $this->assertIsFloat($service->importSimilarityThreshold());
    }
}
