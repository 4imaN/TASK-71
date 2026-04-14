<?php

namespace App\Services\Import\EntityStrategies;

use App\Models\ResearchProject;
use App\Services\Admin\SystemConfigService;
use App\Services\Import\EntityStrategyInterface;
use Illuminate\Database\Eloquent\Model;

class ResearchProjectStrategy implements EntityStrategyInterface
{
    public function __construct(private readonly SystemConfigService $config) {}

    /**
     * Normalize a title string for similarity comparison.
     * Matches the project-wide normalization rule.
     */
    public static function normalizeTitle(string $text): string
    {
        $text = preg_replace('/[^a-z0-9 ]/i', '', $text);
        $text = preg_replace('/\s+/', ' ', $text);
        return strtolower(trim($text));
    }

    /**
     * Find existing ResearchProject:
     *  1. Exact project_number match
     *  2. Exact patent_number match (if provided)
     *  3. Normalized title similarity >= threshold
     */
    public function findExisting(array $row): ?Model
    {
        // 1. Exact project_number
        if (!empty($row['project_number'])) {
            $found = ResearchProject::where('project_number', $row['project_number'])->first();
            if ($found) {
                return $found;
            }
        }

        // 2. Exact patent_number
        if (!empty($row['patent_number'])) {
            $found = ResearchProject::where('patent_number', $row['patent_number'])->first();
            if ($found) {
                return $found;
            }
        }

        // 3. Normalized title similarity
        if (!empty($row['title'])) {
            $threshold       = $this->config->importSimilarityThreshold();
            $incomingNorm    = self::normalizeTitle($row['title']);
            $candidates      = ResearchProject::whereNotNull('normalized_title')->get();

            foreach ($candidates as $candidate) {
                similar_text($incomingNorm, (string) $candidate->normalized_title, $percent);
                if (($percent / 100) >= $threshold) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * Compute field-level diffs.
     */
    public function computeFieldDiffs(array $row, Model $existing): array
    {
        $diffs = [];
        $fields = [
            'project_number', 'title', 'principal_investigator_name',
            'department_id', 'grant_id', 'patent_number',
            'project_status_id', 'start_date', 'end_date', 'last_updated_at',
        ];

        foreach ($fields as $field) {
            if (!array_key_exists($field, $row)) {
                continue;
            }
            $incoming = $row[$field];
            $local    = $existing->getAttribute($field);

            $incomingNorm = (string) ($incoming ?? '');
            $localNorm    = (string) ($local ?? '');

            if ($incomingNorm !== $localNorm) {
                $diffs[] = [
                    'field'          => $field,
                    'local_value'    => $local,
                    'incoming_value' => $incoming,
                ];
            }
        }

        return $diffs;
    }

    /**
     * Upsert or create ResearchProject. Always stores normalized_title.
     */
    public function apply(array $row, ?Model $existing): Model
    {
        $data = array_filter([
            'project_number'              => $row['project_number'] ?? null,
            'title'                       => $row['title'] ?? null,
            'normalized_title'            => isset($row['title']) ? self::normalizeTitle($row['title']) : null,
            'principal_investigator_name' => $row['principal_investigator_name'] ?? null,
            'department_id'               => $row['department_id'] ?? null,
            'grant_id'                    => $row['grant_id'] ?? null,
            'patent_number'               => $row['patent_number'] ?? null,
            'project_status_id'           => $row['project_status_id'] ?? null,
            'start_date'                  => $row['start_date'] ?? null,
            'end_date'                    => $row['end_date'] ?? null,
            'last_updated_at'             => $row['last_updated_at'] ?? null,
        ], fn ($v) => $v !== null);

        if ($existing) {
            $existing->update($data);
            return $existing->refresh();
        }

        return ResearchProject::create($data);
    }

    /**
     * Required fields.
     */
    public function requiredFields(): array
    {
        return ['title'];
    }
}
