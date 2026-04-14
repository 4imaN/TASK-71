<?php

namespace Tests\Feature\Import;

use App\Services\Import\ImportParserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportParserTest extends TestCase
{
    use RefreshDatabase;

    private ImportParserService $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new ImportParserService();
    }

    // ── CSV parsing ───────────────────────────────────────────────────────────────

    public function test_csv_parse_basic(): void
    {
        $csv = "code,name,is_active\nDEP01,Engineering,1\nDEP02,Research,0";

        $result = $this->parser->parse($csv, 'csv', []);

        $this->assertCount(2, $result['rows']);
        $this->assertEquals(2, $result['total']);
        $this->assertEquals(0, $result['skipped']);
        $this->assertEquals('DEP01', $result['rows'][0]['code']);
        $this->assertEquals('Engineering', $result['rows'][0]['name']);
    }

    public function test_csv_parse_with_field_mapping(): void
    {
        $csv = "dept_code,dept_name\nHR001,Human Resources\nIT002,Information Technology";

        $mapping = [
            'dept_code' => 'code',
            'dept_name' => 'name',
        ];

        $result = $this->parser->parse($csv, 'csv', $mapping);

        $this->assertCount(2, $result['rows']);
        $this->assertArrayHasKey('code', $result['rows'][0]);
        $this->assertArrayHasKey('name', $result['rows'][0]);
        $this->assertArrayNotHasKey('dept_code', $result['rows'][0]);
        $this->assertEquals('HR001', $result['rows'][0]['code']);
        $this->assertEquals('Human Resources', $result['rows'][0]['name']);
    }

    public function test_csv_parse_handles_empty_content(): void
    {
        $result = $this->parser->parse('', 'csv', []);

        $this->assertCount(0, $result['rows']);
        $this->assertEquals(0, $result['total']);
    }

    public function test_csv_parse_handles_headers_only(): void
    {
        $csv = "code,name,is_active";

        $result = $this->parser->parse($csv, 'csv', []);

        $this->assertCount(0, $result['rows']);
        $this->assertEquals(0, $result['total']);
    }

    public function test_csv_parse_handles_missing_trailing_values(): void
    {
        $csv = "code,name,is_active\nDEP01,Engineering";

        $result = $this->parser->parse($csv, 'csv', []);

        $this->assertCount(1, $result['rows']);
        $this->assertEquals('DEP01', $result['rows'][0]['code']);
        $this->assertEquals('', $result['rows'][0]['is_active']);
    }

    // ── JSON parsing ─────────────────────────────────────────────────────────────

    public function test_json_parse_bare_array(): void
    {
        $json = json_encode([
            ['code' => 'DEP01', 'name' => 'Engineering'],
            ['code' => 'DEP02', 'name' => 'Research'],
        ]);

        $result = $this->parser->parse($json, 'json', []);

        $this->assertCount(2, $result['rows']);
        $this->assertEquals('DEP01', $result['rows'][0]['code']);
    }

    public function test_json_parse_data_wrapper(): void
    {
        $json = json_encode([
            'data' => [
                ['code' => 'DEP01', 'name' => 'Engineering'],
            ],
            'meta' => ['total' => 1],
        ]);

        $result = $this->parser->parse($json, 'json', []);

        $this->assertCount(1, $result['rows']);
        $this->assertEquals('DEP01', $result['rows'][0]['code']);
    }

    public function test_json_parse_with_field_mapping(): void
    {
        $json = json_encode([
            ['dept_code' => 'HR001', 'dept_name' => 'Human Resources'],
        ]);

        $mapping = [
            'dept_code' => 'code',
            'dept_name' => 'name',
        ];

        $result = $this->parser->parse($json, 'json', $mapping);

        $this->assertCount(1, $result['rows']);
        $this->assertArrayHasKey('code', $result['rows'][0]);
        $this->assertArrayNotHasKey('dept_code', $result['rows'][0]);
    }

    public function test_json_parse_handles_empty_content(): void
    {
        $result = $this->parser->parse('', 'json', []);

        $this->assertCount(0, $result['rows']);
    }

    public function test_json_parse_throws_on_invalid_json(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->parser->parse('{not valid json}', 'json', []);
    }

    // ── Incremental sync ─────────────────────────────────────────────────────────

    public function test_incremental_sync_skips_old_rows(): void
    {
        $csv = "code,name,last_updated_at\n"
             . "DEP01,Engineering,2024-01-01 10:00:00\n"
             . "DEP02,Research,2024-06-01 10:00:00\n"
             . "DEP03,Science,2025-01-01 10:00:00";

        $lastSync = new \DateTime('2024-03-01 00:00:00');

        $result = $this->parser->parse($csv, 'csv', [], $lastSync);

        // DEP01 (2024-01-01) <= lastSync (2024-03-01) → skipped
        // DEP02 (2024-06-01) > lastSync → kept
        // DEP03 (2025-01-01) > lastSync → kept
        $this->assertCount(2, $result['rows']);
        $this->assertEquals(1, $result['skipped']);
        $this->assertEquals(3, $result['total']);

        $codes = array_column($result['rows'], 'code');
        $this->assertContains('DEP02', $codes);
        $this->assertContains('DEP03', $codes);
        $this->assertNotContains('DEP01', $codes);
    }

    public function test_incremental_sync_keeps_rows_without_timestamp(): void
    {
        $csv = "code,name,last_updated_at\nDEP01,Engineering,";

        $lastSync = new \DateTime('2024-03-01 00:00:00');

        $result = $this->parser->parse($csv, 'csv', [], $lastSync);

        // No timestamp → keep by default
        $this->assertCount(1, $result['rows']);
        $this->assertEquals(0, $result['skipped']);
    }

    public function test_no_incremental_sync_when_no_timestamp_provided(): void
    {
        $csv = "code,name,last_updated_at\n"
             . "DEP01,Engineering,2020-01-01 00:00:00\n"
             . "DEP02,Research,2019-01-01 00:00:00";

        $result = $this->parser->parse($csv, 'csv', [], null);

        $this->assertCount(2, $result['rows']);
        $this->assertEquals(0, $result['skipped']);
    }

    public function test_unsupported_format_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->parser->parse('data', 'xml', []);
    }
}
