<?php

namespace App\Services\Import;

class ImportParserService
{
    /**
     * Parse CSV or JSON content with a field_mapping applied.
     *
     * @param string $content Raw file content
     * @param string $format 'csv'|'json'
     * @param array $fieldMapping source_column => target_field
     * @param \DateTimeInterface|null $lastSyncTimestamp Skip rows where last_updated_at <= this timestamp
     * @return array{rows: array[], skipped: int, total: int}
     */
    public function parse(
        string $content,
        string $format,
        array $fieldMapping,
        ?\DateTimeInterface $lastSyncTimestamp = null
    ): array {
        $rawRows = match (strtolower($format)) {
            'csv'  => $this->parseCsv($content),
            'json' => $this->parseJson($content),
            default => throw new \InvalidArgumentException("Unsupported format: {$format}"),
        };

        $rows    = [];
        $skipped = 0;
        $total   = count($rawRows);

        foreach ($rawRows as $raw) {
            $mapped = $this->applyMapping($raw, $fieldMapping);

            // Incremental sync: skip rows that are at or before last_sync_timestamp
            if ($lastSyncTimestamp !== null && isset($mapped['last_updated_at']) && $mapped['last_updated_at'] !== '') {
                $rowTs = $this->parseTimestamp($mapped['last_updated_at']);
                if ($rowTs !== null && $rowTs <= $lastSyncTimestamp) {
                    $skipped++;
                    continue;
                }
            }

            $rows[] = $mapped;
        }

        return [
            'rows'    => $rows,
            'skipped' => $skipped,
            'total'   => $total,
        ];
    }

    /**
     * Parse CSV content. First line is headers.
     * Returns array of associative arrays.
     */
    private function parseCsv(string $content): array
    {
        $content = trim($content);
        if ($content === '') {
            return [];
        }

        $lines = explode("\n", $content);
        $lines = array_map(fn (string $l) => rtrim($l, "\r"), $lines);

        if (count($lines) < 1) {
            return [];
        }

        $headers = str_getcsv(array_shift($lines));
        $headers = array_map('trim', $headers);

        $rows = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $values = str_getcsv($line);
            // Pad or truncate to match header count
            $values = array_pad($values, count($headers), '');
            $values = array_slice($values, 0, count($headers));
            $rows[] = array_combine($headers, $values);
        }

        return $rows;
    }

    /**
     * Parse JSON content. Supports bare array or {"data": [...]} wrapper.
     */
    private function parseJson(string $content): array
    {
        $content = trim($content);
        if ($content === '') {
            return [];
        }

        $decoded = json_decode($content, true);

        if (!is_array($decoded)) {
            throw new \RuntimeException('Invalid JSON content: could not decode as array.');
        }

        // Support {"data": [...]} wrapper
        if (isset($decoded['data']) && is_array($decoded['data'])) {
            $decoded = $decoded['data'];
        }

        // Ensure rows are associative arrays
        return array_values(array_filter($decoded, fn ($item) => is_array($item)));
    }

    /**
     * Apply field mapping: rename source columns to target field names.
     * Keys not in the mapping are kept as-is if mapping is empty,
     * or dropped if mapping is non-empty and doesn't include them.
     */
    private function applyMapping(array $row, array $fieldMapping): array
    {
        if (empty($fieldMapping)) {
            return $row;
        }

        $mapped = [];
        foreach ($fieldMapping as $sourceColumn => $targetField) {
            if (array_key_exists($sourceColumn, $row)) {
                $mapped[$targetField] = $row[$sourceColumn] ?? '';
            } else {
                $mapped[$targetField] = '';
            }
        }

        return $mapped;
    }

    /**
     * Attempt to parse a timestamp string. Returns null if unparseable.
     */
    private function parseTimestamp(string $value): ?\DateTimeInterface
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        try {
            return new \DateTime($value);
        } catch (\Exception) {
            return null;
        }
    }
}
