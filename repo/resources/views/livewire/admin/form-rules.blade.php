<div
    x-data="{
        stepUpOpen: @entangle('requiresStepUp'),
        showForm: false
    }">

    {{-- ── Step-up modal ──────────────────────────────────────────────────────── --}}
    <div
        x-show="stepUpOpen"
        x-cloak
        class="fixed inset-0 z-50 flex items-center justify-center"
        style="background: rgba(15,23,42,0.55); backdrop-filter: blur(2px);">
        <div
            @click.stop
            class="w-full max-w-sm mx-4 bg-white rounded-2xl shadow-2xl border border-slate-200 overflow-hidden"
            x-show="stepUpOpen"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 scale-95"
            x-transition:enter-end="opacity-100 scale-100"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-95">

            <div class="bg-slate-900 px-6 py-4 flex items-center gap-3">
                <svg class="w-5 h-5 text-amber-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                </svg>
                <div>
                    <p class="text-white text-sm font-semibold">Re-authenticate to continue</p>
                    <p class="text-slate-400 text-xs mt-0.5">Rule changes require identity verification</p>
                </div>
            </div>

            <div class="px-6 py-5 space-y-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1.5">Current Password</label>
                    <input
                        type="password"
                        wire:model="stepUpPassword"
                        wire:keydown.enter="confirmStepUp"
                        placeholder="Enter your password"
                        class="w-full px-3 py-2.5 border rounded-lg text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-amber-400 focus:border-amber-400 border-slate-300"
                        autocomplete="current-password" />
                    @if ($stepUpError)
                        <p class="mt-1.5 text-xs text-red-600 flex items-center gap-1">
                            <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
                            {{ $stepUpError }}
                        </p>
                    @endif
                </div>

                <div class="flex gap-2 pt-1">
                    <button
                        wire:click="confirmStepUp"
                        wire:loading.attr="disabled"
                        class="flex-1 px-4 py-2.5 bg-slate-900 text-white text-sm font-medium rounded-lg hover:bg-slate-700 transition-colors disabled:opacity-60">
                        <span wire:loading.remove wire:target="confirmStepUp">Verify Identity</span>
                        <span wire:loading wire:target="confirmStepUp">Verifying…</span>
                    </button>
                    <button
                        wire:click="cancelStepUp"
                        class="px-4 py-2.5 bg-slate-100 text-slate-600 text-sm font-medium rounded-lg hover:bg-slate-200 transition-colors">
                        Cancel
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- ── Page header ─────────────────────────────────────────────────────────── --}}
    <div class="flex items-start justify-between mb-6">
        <div>
            <h1 class="text-xl font-bold text-slate-900 tracking-tight">Form Rules</h1>
            <p class="text-sm text-slate-500 mt-0.5">Configure dynamic field validation rules per entity type</p>
        </div>
        <button
            @click="showForm = !showForm; $wire.clearForm()"
            class="flex items-center gap-1.5 text-sm font-medium px-4 py-2 rounded-lg transition-colors"
            :class="showForm ? 'bg-slate-100 text-slate-600 hover:bg-slate-200' : 'bg-indigo-600 text-white hover:bg-indigo-700'">
            <template x-if="!showForm">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
            </template>
            <template x-if="showForm">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </template>
            <span x-text="showForm ? 'Cancel' : 'Add Rule'"></span>
        </button>
    </div>

    {{-- ── Flash message ───────────────────────────────────────────────────────── --}}
    @if ($flashMessage)
        <div class="mb-5 px-4 py-3 rounded-lg text-sm font-medium flex items-center gap-2
            {{ $flashType === 'success' ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-red-50 text-red-700 border border-red-200' }}">
            @if ($flashType === 'success')
                <svg class="w-4 h-4 shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
            @else
                <svg class="w-4 h-4 shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
            @endif
            {{ $flashMessage }}
        </div>
    @endif

    {{-- ── Create / Edit form ──────────────────────────────────────────────────── --}}
    <div x-show="showForm || {{ $editingId ? 'true' : 'false' }}" x-cloak>
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden mb-5">

            <div class="bg-slate-900 px-5 py-3.5">
                <h2 class="text-sm font-semibold text-white">{{ $editingId ? 'Edit Rule' : 'New Rule' }}</h2>
            </div>

            <div class="px-5 py-5 space-y-4">

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1.5">Entity Type <span class="text-red-500">*</span></label>
                        <input
                            type="text"
                            wire:model="entityType"
                            placeholder="e.g. user, reservation"
                            {{ $editingId ? 'readonly' : '' }}
                            class="w-full px-3 py-2.5 border rounded-lg text-sm font-mono text-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400
                                {{ $errors->has('entityType') ? 'border-red-400' : 'border-slate-300' }}
                                {{ $editingId ? 'bg-slate-50 text-slate-500 cursor-not-allowed' : '' }}" />
                        @error('entityType') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1.5">Field Name <span class="text-red-500">*</span></label>
                        <input
                            type="text"
                            wire:model="fieldName"
                            placeholder="e.g. email, phone"
                            {{ $editingId ? 'readonly' : '' }}
                            class="w-full px-3 py-2.5 border rounded-lg text-sm font-mono text-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400
                                {{ $errors->has('fieldName') ? 'border-red-400' : 'border-slate-300' }}
                                {{ $editingId ? 'bg-slate-50 text-slate-500 cursor-not-allowed' : '' }}" />
                        @error('fieldName') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="bg-slate-50 rounded-lg p-4 space-y-3">
                    <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Validation Rules</p>

                    <div class="flex items-center gap-3">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input
                                type="checkbox"
                                wire:model="ruleRequired"
                                class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-300" />
                            <span class="text-sm font-medium text-slate-700">Required field</span>
                        </label>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-slate-600 mb-1">Min Length</label>
                            <input
                                type="number"
                                min="1"
                                wire:model="ruleMinLength"
                                placeholder="—"
                                class="w-full px-2.5 py-2 border rounded-lg text-sm font-mono text-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400
                                    {{ $errors->has('ruleMinLength') ? 'border-red-400' : 'border-slate-300' }}" />
                            @error('ruleMinLength') <p class="mt-0.5 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-600 mb-1">Max Length</label>
                            <input
                                type="number"
                                min="1"
                                wire:model="ruleMaxLength"
                                placeholder="—"
                                class="w-full px-2.5 py-2 border rounded-lg text-sm font-mono text-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400
                                    {{ $errors->has('ruleMaxLength') ? 'border-red-400' : 'border-slate-300' }}" />
                            @error('ruleMaxLength') <p class="mt-0.5 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-600 mb-1">Regex Pattern</label>
                            <input
                                type="text"
                                wire:model="ruleRegex"
                                placeholder="/^[a-z]+$/"
                                class="w-full px-2.5 py-2 border border-slate-300 rounded-lg text-sm font-mono text-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400" />
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input
                            type="checkbox"
                            wire:model="isActive"
                            class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-300" />
                        <span class="text-sm font-medium text-slate-700">Active</span>
                    </label>
                </div>

                <div class="flex items-center gap-2 pt-1 border-t border-slate-100">
                    <button
                        wire:click="saveRule"
                        wire:loading.attr="disabled"
                        class="px-5 py-2.5 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 transition-colors disabled:opacity-60">
                        <span wire:loading.remove wire:target="saveRule">{{ $editingId ? 'Update Rule' : 'Create Rule' }}</span>
                        <span wire:loading wire:target="saveRule">Saving…</span>
                    </button>
                    <button
                        wire:click="clearForm"
                        @click="showForm = false"
                        class="px-4 py-2.5 bg-slate-100 text-slate-600 text-sm font-medium rounded-lg hover:bg-slate-200 transition-colors">
                        Cancel
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- ── Rules table ──────────────────────────────────────────────────────────── --}}
    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">

        <div class="bg-slate-900 px-5 py-3.5 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-white">Configured Rules</h2>
            <span class="text-xs text-slate-400">{{ $rules->count() }} {{ Str::plural('rule', $rules->count()) }}</span>
        </div>

        @if ($rules->isNotEmpty())
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 bg-slate-50">
                            <th class="text-left px-5 py-2.5 text-xs font-semibold text-slate-500 uppercase tracking-wider">Entity</th>
                            <th class="text-left px-3 py-2.5 text-xs font-semibold text-slate-500 uppercase tracking-wider">Field</th>
                            <th class="text-center px-3 py-2.5 text-xs font-semibold text-slate-500 uppercase tracking-wider">Req.</th>
                            <th class="text-center px-3 py-2.5 text-xs font-semibold text-slate-500 uppercase tracking-wider">Min</th>
                            <th class="text-center px-3 py-2.5 text-xs font-semibold text-slate-500 uppercase tracking-wider">Max</th>
                            <th class="text-left px-3 py-2.5 text-xs font-semibold text-slate-500 uppercase tracking-wider hidden lg:table-cell">Regex</th>
                            <th class="text-center px-3 py-2.5 text-xs font-semibold text-slate-500 uppercase tracking-wider">Active</th>
                            <th class="text-right px-5 py-2.5 text-xs font-semibold text-slate-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($rules as $rule)
                            <tr class="{{ !$rule->is_active ? 'opacity-40' : '' }} hover:bg-slate-50 transition-colors">
                                <td class="px-5 py-3">
                                    <span class="font-mono text-xs font-semibold text-slate-700 bg-slate-100 px-2 py-0.5 rounded">{{ $rule->entity_type }}</span>
                                </td>
                                <td class="px-3 py-3">
                                    <span class="font-mono text-xs text-slate-600">{{ $rule->field_name }}</span>
                                </td>
                                <td class="px-3 py-3 text-center">
                                    @if (!empty($rule->rules['required']))
                                        <svg class="w-4 h-4 text-emerald-500 mx-auto" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                                    @else
                                        <span class="text-slate-300">—</span>
                                    @endif
                                </td>
                                <td class="px-3 py-3 text-center">
                                    <span class="font-mono text-xs text-slate-500">{{ $rule->rules['min_length'] ?? '—' }}</span>
                                </td>
                                <td class="px-3 py-3 text-center">
                                    <span class="font-mono text-xs text-slate-500">{{ $rule->rules['max_length'] ?? '—' }}</span>
                                </td>
                                <td class="px-3 py-3 hidden lg:table-cell">
                                    <span class="font-mono text-xs text-slate-500 truncate max-w-xs block">{{ $rule->rules['regex'] ?? '—' }}</span>
                                </td>
                                <td class="px-3 py-3 text-center">
                                    @if ($rule->is_active)
                                        <span class="inline-flex items-center gap-1 text-xs font-medium text-emerald-700 bg-emerald-50 px-1.5 py-0.5 rounded-full">
                                            <span class="w-1.5 h-1.5 bg-emerald-500 rounded-full"></span> Yes
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1 text-xs font-medium text-slate-500 bg-slate-100 px-1.5 py-0.5 rounded-full">
                                            <span class="w-1.5 h-1.5 bg-slate-400 rounded-full"></span> No
                                        </span>
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-right">
                                    <div class="flex items-center justify-end gap-3">
                                        <button
                                            wire:click="editRule({{ $rule->id }})"
                                            @click="showForm = true"
                                            class="text-xs font-medium text-indigo-600 hover:text-indigo-800 transition-colors">
                                            Edit
                                        </button>
                                        @if ($rule->is_active)
                                            <button
                                                wire:click="deactivateRule({{ $rule->id }})"
                                                wire:confirm="Deactivate this rule? The field will no longer be validated dynamically."
                                                class="text-xs font-medium text-red-500 hover:text-red-700 transition-colors">
                                                Deactivate
                                            </button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <div class="px-5 py-12 text-center">
                <svg class="w-10 h-10 text-slate-200 mx-auto mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                </svg>
                <p class="text-sm text-slate-400">No form rules configured yet.</p>
                <button @click="showForm = true" class="mt-2 text-sm text-indigo-600 hover:text-indigo-800 font-medium">Create the first rule</button>
            </div>
        @endif

    </div>

</div>
