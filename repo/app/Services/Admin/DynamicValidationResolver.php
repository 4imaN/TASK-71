<?php

namespace App\Services\Admin;

use App\Models\FormRule;
use Illuminate\Support\Facades\Cache;

/**
 * Merges admin-configured dynamic form rules with hard-coded static rules.
 *
 * Dynamic rules can TIGHTEN constraints but cannot remove or loosen
 * hard-coded safety rules. For example, a dynamic min_length=15
 * overrides a static min_length=5, but a dynamic required=false
 * cannot override a static required=true.
 */
class DynamicValidationResolver
{
    private const CACHE_TTL = 300;

    /**
     * Returns a merged Laravel validation rule array for the given entity+field.
     *
     * @param  array  $staticRules  Hard-coded baseline rules (never weakened)
     * @return array  Merged rule array suitable for Validator::make() or $this->validate()
     */
    public function resolve(string $entityType, string $fieldName, array $staticRules = []): array
    {
        $dynamic = $this->dynamicRulesFor($entityType, $fieldName);

        if (empty($dynamic)) {
            return $staticRules;
        }

        return $this->merge($staticRules, $dynamic);
    }

    private function dynamicRulesFor(string $entityType, string $fieldName): array
    {
        $cacheKey = "form_rule:{$entityType}:{$fieldName}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($entityType, $fieldName) {
            $rule = FormRule::where('entity_type', $entityType)
                ->where('field_name', $fieldName)
                ->where('is_active', true)
                ->first();

            return $rule ? ($rule->rules ?? []) : [];
        });
    }

    /**
     * Merge dynamic into static using stricter-wins strategy.
     */
    private function merge(array $static, array $dynamic): array
    {
        $merged = $static;

        // required: static required=true cannot be overridden
        if (isset($dynamic['required']) && $dynamic['required'] === true) {
            $merged[] = 'required';
        }

        // min_length: take the stricter (larger) value
        if (isset($dynamic['min_length'])) {
            $merged = $this->replaceOrAdd($merged, 'min', (int) $dynamic['min_length']);
        }

        // max_length: take the stricter (smaller) value
        if (isset($dynamic['max_length'])) {
            $merged = $this->replaceOrAdd($merged, 'max', (int) $dynamic['max_length']);
        }

        // regex: append additional pattern if not already present
        if (isset($dynamic['regex'])) {
            $merged[] = 'regex:' . $dynamic['regex'];
        }

        return array_values(array_unique($merged));
    }

    private function replaceOrAdd(array $rules, string $type, int $value): array
    {
        foreach ($rules as &$rule) {
            if (is_string($rule) && str_starts_with($rule, $type . ':')) {
                $existing = (int) substr($rule, strlen($type) + 1);
                // For min: take larger; for max: take smaller
                $rule = $type . ':' . ($type === 'min' ? max($existing, $value) : min($existing, $value));
                return $rules;
            }
        }
        $rules[] = $type . ':' . $value;
        return $rules;
    }
}
