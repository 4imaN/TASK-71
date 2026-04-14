<?php

namespace App\Services\Audit;

use App\Models\SensitiveDataClassification;
use Illuminate\Support\Facades\Cache;

/**
 * Replaces sensitive field values with [REDACTED] before they enter
 * audit logs or API responses. Classification rules are loaded from
 * the sensitive_data_classifications table and cached.
 */
class SensitiveDataRedactor
{
    private const CACHE_TTL = 300; // 5 minutes
    private const REDACTED  = '[REDACTED]';

    public function redact(?string $entityType, array $data): array
    {
        if (empty($data) || $entityType === null) {
            return $data;
        }

        $sensitiveFields = $this->sensitiveFields($entityType);

        if (empty($sensitiveFields)) {
            return $data;
        }

        foreach ($sensitiveFields as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = self::REDACTED;
            }
        }

        return $data;
    }

    public function mask(mixed $value, string $maskPattern): string
    {
        if ($value === null) {
            return '';
        }

        $str = (string) $value;

        return match ($maskPattern) {
            'full'                => str_repeat('•', min(strlen($str), 8)),
            'partial_last4'       => '••••' . substr($str, -4),
            'partial_first2last4' => substr($str, 0, 2) . '••••' . substr($str, -4),
            'hash'                => '[hashed]',
            default               => self::REDACTED,
        };
    }

    /**
     * Apply configured mask patterns to classified fields before they leave the
     * system in an API response or export file.
     *
     * Unlike redact() (which replaces every classified field with [REDACTED]),
     * maskForResponse() applies the per-field mask_pattern so callers still see
     * a useful partial value (e.g. "••••4321" for partial_last4).
     *
     * @param string $entityType  Entity type key matching sensitive_data_classifications.entity_type
     * @param array  $data        Associative array of field => value pairs
     * @return array              Same structure with classified fields masked in place
     */
    public function maskForResponse(string $entityType, array $data): array
    {
        if (empty($data)) {
            return $data;
        }

        $fields = $this->classifiedFields($entityType);

        if (empty($fields)) {
            return $data;
        }

        foreach ($fields as $fieldName => $maskPattern) {
            if (array_key_exists($fieldName, $data)) {
                $data[$fieldName] = $this->mask($data[$fieldName], $maskPattern);
            }
        }

        return $data;
    }

    private function sensitiveFields(string $entityType): array
    {
        $cacheKey = "sensitive_fields:{$entityType}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($entityType) {
            return SensitiveDataClassification::where('entity_type', $entityType)
                ->pluck('field_name')
                ->toArray();
        });
    }

    /**
     * Load a map of field_name => mask_pattern for the given entity type.
     * Cached separately from sensitiveFields() to avoid redundant queries.
     */
    private function classifiedFields(string $entityType): array
    {
        $cacheKey = "classified_fields:{$entityType}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($entityType) {
            return SensitiveDataClassification::where('entity_type', $entityType)
                ->pluck('mask_pattern', 'field_name')
                ->toArray();
        });
    }
}
