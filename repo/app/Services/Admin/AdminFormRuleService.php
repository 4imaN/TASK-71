<?php

namespace App\Services\Admin;

use App\Models\FormRule;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class AdminFormRuleService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function all(): Collection
    {
        return FormRule::orderBy('entity_type')->orderBy('field_name')->get();
    }

    /**
     * Create or update a rule.
     * Rules JSON supports: required, min_length, max_length, regex.
     */
    public function upsert(array $data, User $admin): FormRule
    {
        $existing = FormRule::where('entity_type', $data['entity_type'])
            ->where('field_name', $data['field_name'])
            ->first();

        $rules = array_filter([
            'required'   => isset($data['rules']['required']) ? (bool) $data['rules']['required'] : null,
            'min_length' => isset($data['rules']['min_length']) ? (int) $data['rules']['min_length'] : null,
            'max_length' => isset($data['rules']['max_length']) ? (int) $data['rules']['max_length'] : null,
            'regex'      => $data['rules']['regex'] ?? null,
        ], fn ($v) => $v !== null && $v !== '' && $v !== false);

        if ($existing) {
            $before = $existing->toArray();
            $existing->update([
                'rules'     => $rules,
                'is_active' => $data['is_active'] ?? true,
            ]);
            Cache::forget("form_rule:{$data['entity_type']}:{$data['field_name']}");
            $existing->refresh();

            $this->auditLogger->log(
                action: 'admin.form_rule_updated',
                actorId: $admin->id,
                entityType: 'form_rule',
                entityId: $existing->id,
                beforeState: $before,
                afterState: $existing->toArray(),
            );

            return $existing;
        }

        $rule = FormRule::create([
            'entity_type' => $data['entity_type'],
            'field_name'  => $data['field_name'],
            'rules'       => $rules,
            'is_active'   => $data['is_active'] ?? true,
        ]);

        Cache::forget("form_rule:{$data['entity_type']}:{$data['field_name']}");

        $this->auditLogger->log(
            action: 'admin.form_rule_created',
            actorId: $admin->id,
            entityType: 'form_rule',
            entityId: $rule->id,
            afterState: $rule->toArray(),
        );

        return $rule;
    }

    public function deactivate(FormRule $rule, User $admin): FormRule
    {
        $before = $rule->toArray();
        $rule->update(['is_active' => false]);
        Cache::forget("form_rule:{$rule->entity_type}:{$rule->field_name}");
        $rule->refresh();

        $this->auditLogger->log(
            action: 'admin.form_rule_deactivated',
            actorId: $admin->id,
            entityType: 'form_rule',
            entityId: $rule->id,
            beforeState: $before,
            afterState: $rule->toArray(),
        );

        return $rule;
    }
}
