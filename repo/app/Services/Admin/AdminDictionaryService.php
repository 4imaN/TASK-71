<?php

namespace App\Services\Admin;

use App\Models\DataDictionaryType;
use App\Models\DataDictionaryValue;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Collection;

class AdminDictionaryService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function allTypes(): Collection
    {
        return DataDictionaryType::with(['values' => function ($q) {
            $q->orderBy('sort_order')->orderBy('label');
        }])->orderBy('label')->get();
    }

    /**
     * Create a new value for a type. key must be unique within the type.
     */
    public function createValue(DataDictionaryType $type, array $data, User $admin): DataDictionaryValue
    {
        $value = DataDictionaryValue::create([
            'type_id'     => $type->id,
            'key'         => $data['key'],
            'label'       => $data['label'],
            'description' => $data['description'] ?? null,
            'sort_order'  => (int) ($data['sort_order'] ?? 0),
            'is_active'   => true,
        ]);

        $this->auditLogger->log(
            action: 'admin.dict_value_created',
            actorId: $admin->id,
            entityType: 'data_dictionary_value',
            entityId: $value->id,
            afterState: ['type' => $type->code, 'key' => $value->key, 'label' => $value->label],
        );

        return $value;
    }

    /**
     * Update label/description/sort_order/is_active of an existing value.
     */
    public function updateValue(DataDictionaryValue $value, array $data, User $admin): DataDictionaryValue
    {
        $before = $value->toArray();

        $updates = [];
        foreach (['label', 'description', 'sort_order', 'is_active'] as $field) {
            if (array_key_exists($field, $data)) {
                $updates[$field] = $data[$field];
            }
        }
        $value->update($updates);
        $value->refresh();

        $this->auditLogger->log(
            action: 'admin.dict_value_updated',
            actorId: $admin->id,
            entityType: 'data_dictionary_value',
            entityId: $value->id,
            beforeState: $before,
            afterState: $value->toArray(),
        );

        return $value;
    }

    public function deactivateValue(DataDictionaryValue $value, User $admin): DataDictionaryValue
    {
        return $this->updateValue($value, ['is_active' => false], $admin);
    }
}
