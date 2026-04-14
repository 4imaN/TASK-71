<?php

namespace Tests\Unit\Services;

use App\Services\Audit\SensitiveDataRedactor;
use Tests\TestCase;

class SensitiveDataRedactorTest extends TestCase
{
    private SensitiveDataRedactor $redactor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->redactor = new SensitiveDataRedactor();
    }

    public function test_mask_full_pattern_hides_value(): void
    {
        $masked = $this->redactor->mask('secret123', 'full');
        $this->assertStringContainsString('•', $masked);
        $this->assertStringNotContainsString('secret', $masked);
    }

    public function test_mask_partial_last4_shows_last_four(): void
    {
        $masked = $this->redactor->mask('123456789', 'partial_last4');
        $this->assertStringEndsWith('6789', $masked);
        $this->assertStringNotContainsString('12345', $masked);
    }

    public function test_mask_hash_returns_hashed_placeholder(): void
    {
        $masked = $this->redactor->mask('any_value', 'hash');
        $this->assertEquals('[hashed]', $masked);
    }

    public function test_redact_returns_data_unchanged_when_entity_type_null(): void
    {
        $data = ['username' => 'testuser', 'password' => 'secret'];
        $result = $this->redactor->redact(null, $data);
        $this->assertEquals($data, $result);
    }
}
