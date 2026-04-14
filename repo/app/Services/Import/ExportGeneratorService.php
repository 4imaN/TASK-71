<?php

namespace App\Services\Import;

use App\Models\Department;
use App\Models\ResearchProject;
use App\Models\Service;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\Audit\AuditLogger;
use App\Services\Audit\SensitiveDataRedactor;

class ExportGeneratorService
{
    /** @var array<string, array<string>> */
    private array $entityFields = [
        'departments' => [
            'id', 'code', 'name', 'is_active', 'last_updated_at',
        ],
        'user_profiles' => [
            'id', 'employee_id', 'user_id', 'department_id', 'cost_center',
            'job_title', 'employment_status', 'last_updated_at',
        ],
        'research_projects' => [
            'id', 'project_number', 'title', 'principal_investigator_name',
            'department_id', 'grant_id', 'patent_number', 'project_status_id',
            'start_date', 'end_date', 'last_updated_at',
        ],
        'services' => [
            'id', 'slug', 'title', 'status', 'category_id', 'service_type_id',
            'is_free', 'fee_amount', 'updated_at',
        ],
        'users' => [
            'id', 'uuid', 'username', 'display_name', 'status', 'audience_type', 'created_at',
        ],
    ];

    /**
     * Maps export entity type keys to the entity_type used in
     * sensitive_data_classifications. Plural export keys ≠ singular DB keys.
     */
    private array $entityTypeMap = [
        'user_profiles'     => 'user_profile',
        'research_projects' => 'research_project',
        'departments'       => 'department',
        'services'          => 'service',
        'users'             => 'user',
    ];

    public function __construct(
        private readonly AuditLogger           $auditLogger,
        private readonly SensitiveDataRedactor $redactor,
    ) {}

    /**
     * Generate an export file for the given entity type and format.
     *
     * @param string $entityType users|services|research_projects|departments|user_profiles
     * @param string $format csv|json
     * @param array $filters Optional filters (status, date_from, date_to, etc.)
     * @param User $admin Acting administrator
     * @return array{content: string, filename: string, mime_type: string}
     */
    public function generate(string $entityType, string $format, array $filters, User $admin): array
    {
        if (!isset($this->entityFields[$entityType])) {
            throw new \InvalidArgumentException("Unknown entity type for export: {$entityType}");
        }

        $fields = $this->entityFields[$entityType];
        $records = $this->fetchRecords($entityType, $fields, $filters);

        $content  = match (strtolower($format)) {
            'csv'  => $this->buildCsv($fields, $records),
            'json' => $this->buildJson($records),
            default => throw new \InvalidArgumentException("Unsupported export format: {$format}"),
        };

        $timestamp = now()->format('Ymd_His');
        $filename  = "{$entityType}_{$timestamp}.{$format}";
        $mimeType  = $format === 'csv' ? 'text/csv' : 'application/json';

        $this->auditLogger->log(
            action: 'export.generated',
            actorId: $admin->id,
            entityType: $entityType,
            afterState: [
                'entity_type' => $entityType,
                'format'      => $format,
                'record_count'=> count($records),
                'filters'     => $filters,
            ],
        );

        return [
            'content'   => $content,
            'filename'  => $filename,
            'mime_type' => $mimeType,
        ];
    }

    /**
     * Fetch records for the given entity type, applying optional filters.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchRecords(string $entityType, array $fields, array $filters): array
    {
        $query = match ($entityType) {
            'departments'       => Department::query(),
            'user_profiles'     => UserProfile::query(),
            'research_projects' => ResearchProject::query(),
            'services'          => Service::query(),
            'users'             => User::query(),
        };

        // Apply common filters
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['date_from'])) {
            $dateField = in_array($entityType, ['users']) ? 'created_at' : 'last_updated_at';
            $query->where($dateField, '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $dateField = in_array($entityType, ['users']) ? 'created_at' : 'last_updated_at';
            $query->where($dateField, '<=', $filters['date_to']);
        }

        $classifiedEntityType = $this->entityTypeMap[$entityType] ?? $entityType;

        return $query->get($fields)
            ->map(fn ($model) => $this->redactor->maskForResponse(
                $classifiedEntityType,
                $model->toArray(),
            ))
            ->toArray();
    }

    /**
     * Build CSV content from fields and records.
     */
    private function buildCsv(array $fields, array $records): string
    {
        $lines = [];

        // Header row
        $lines[] = implode(',', array_map(fn (string $f) => $this->csvEscape($f), $fields));

        // Data rows
        foreach ($records as $record) {
            $values = array_map(function (string $field) use ($record) {
                return $this->csvEscape((string) ($record[$field] ?? ''));
            }, $fields);
            $lines[] = implode(',', $values);
        }

        return implode("\n", $lines);
    }

    /**
     * Escape a value for CSV output.
     */
    private function csvEscape(string $value): string
    {
        // Wrap in quotes if value contains comma, quote, or newline
        if (str_contains($value, ',') || str_contains($value, '"') || str_contains($value, "\n")) {
            return '"' . str_replace('"', '""', $value) . '"';
        }
        return $value;
    }

    /**
     * Build pretty-printed JSON content from records.
     */
    private function buildJson(array $records): string
    {
        return json_encode($records, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
}
