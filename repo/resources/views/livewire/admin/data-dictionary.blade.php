<div x-data="{ stepUpOpen: @entangle('requiresStepUp') }">

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
                    <p class="text-slate-400 text-xs mt-0.5">Dictionary changes require identity verification</p>
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
            <h1 class="text-xl font-bold text-slate-900 tracking-tight">Data Dictionary</h1>
            <p class="text-sm text-slate-500 mt-0.5">Manage lookup types and their enumerated values</p>
        </div>
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

    <div class="flex gap-5 min-h-0">

        {{-- ── Type sidebar ─────────────────────────────────────────────────────── --}}
        <aside class="w-52 shrink-0">
            <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
                <div class="bg-slate-900 px-4 py-2.5">
                    <p class="text-xs font-semibold text-slate-300 uppercase tracking-wider">Types</p>
                </div>
                <nav class="divide-y divide-slate-100">
                    @foreach ($types as $type)
                        <button
                            wire:click="setType({{ $type->id }}, '{{ $type->code }}')"
                            class="w-full text-left px-4 py-3 transition-colors
                                {{ $activeTypeCode === $type->code
                                    ? 'bg-indigo-50 border-l-2 border-indigo-500'
                                    : 'hover:bg-slate-50 border-l-2 border-transparent' }}">
                            <p class="text-sm font-medium {{ $activeTypeCode === $type->code ? 'text-indigo-700' : 'text-slate-700' }}">
                                {{ $type->label }}
                            </p>
                            <p class="font-mono text-xs {{ $activeTypeCode === $type->code ? 'text-indigo-400' : 'text-slate-400' }} mt-0.5">
                                {{ $type->code }}
                            </p>
                        </button>
                    @endforeach
                    @if ($types->isEmpty())
                        <p class="px-4 py-6 text-sm text-slate-400 text-center">No types found</p>
                    @endif
                </nav>
            </div>
        </aside>

        {{-- ── Main panel ───────────────────────────────────────────────────────── --}}
        <div class="flex-1 min-w-0">
            @if ($activeType)
                <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">

                    {{-- Type header --}}
                    <div class="bg-slate-900 px-5 py-3.5 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <span class="font-mono text-xs bg-slate-700 text-slate-200 px-2 py-0.5 rounded">{{ $activeType->code }}</span>
                            <h2 class="text-sm font-semibold text-white">{{ $activeType->label }}</h2>
                            @if ($activeType->is_system)
                                <span class="text-xs bg-amber-500 text-amber-950 font-semibold px-2 py-0.5 rounded">SYSTEM</span>
                            @endif
                        </div>
                        <button
                            wire:click="$toggle('showAddForm')"
                            class="flex items-center gap-1.5 text-xs font-medium px-3 py-1.5 rounded-lg transition-colors
                                {{ $showAddForm ? 'bg-slate-700 text-slate-300' : 'bg-indigo-600 text-white hover:bg-indigo-500' }}">
                            @if ($showAddForm)
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                Cancel
                            @else
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                                Add Value
                            @endif
                        </button>
                    </div>

                    @if ($activeType->description)
                        <div class="px-5 py-2.5 bg-slate-50 border-b border-slate-100">
                            <p class="text-xs text-slate-500">{{ $activeType->description }}</p>
                        </div>
                    @endif

                    {{-- Add form --}}
                    @if ($showAddForm)
                        <div class="px-5 py-4 bg-indigo-50 border-b border-indigo-100">
                            <h3 class="text-xs font-semibold text-indigo-700 uppercase tracking-wider mb-3">New Value</h3>
                            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                                <div>
                                    <label class="block text-xs font-medium text-slate-600 mb-1">Key <span class="text-red-500">*</span></label>
                                    <input type="text" wire:model="newKey" placeholder="e.g. active"
                                        class="w-full px-2.5 py-2 border rounded-lg text-xs font-mono text-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400
                                            {{ $errors->has('newKey') ? 'border-red-400' : 'border-slate-300' }}" />
                                    @error('newKey') <p class="mt-0.5 text-xs text-red-600">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-slate-600 mb-1">Label <span class="text-red-500">*</span></label>
                                    <input type="text" wire:model="newLabel" placeholder="Display label"
                                        class="w-full px-2.5 py-2 border rounded-lg text-xs text-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400
                                            {{ $errors->has('newLabel') ? 'border-red-400' : 'border-slate-300' }}" />
                                    @error('newLabel') <p class="mt-0.5 text-xs text-red-600">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-slate-600 mb-1">Description</label>
                                    <input type="text" wire:model="newDescription" placeholder="Optional"
                                        class="w-full px-2.5 py-2 border border-slate-300 rounded-lg text-xs text-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400" />
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-slate-600 mb-1">Sort Order</label>
                                    <input type="number" min="0" wire:model="newSortOrder" value="0"
                                        class="w-full px-2.5 py-2 border border-slate-300 rounded-lg text-xs font-mono text-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400" />
                                </div>
                            </div>
                            <div class="mt-3 flex justify-end">
                                <button
                                    wire:click="addValue"
                                    wire:loading.attr="disabled"
                                    class="px-4 py-2 bg-indigo-600 text-white text-xs font-semibold rounded-lg hover:bg-indigo-700 transition-colors disabled:opacity-60">
                                    <span wire:loading.remove wire:target="addValue">Add Value</span>
                                    <span wire:loading wire:target="addValue">Adding…</span>
                                </button>
                            </div>
                        </div>
                    @endif

                    {{-- Values table --}}
                    @if ($activeType->values->isNotEmpty())
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b border-slate-100 bg-slate-50">
                                        <th class="text-left px-5 py-2.5 text-xs font-semibold text-slate-500 uppercase tracking-wider">Key</th>
                                        <th class="text-left px-3 py-2.5 text-xs font-semibold text-slate-500 uppercase tracking-wider">Label</th>
                                        <th class="text-left px-3 py-2.5 text-xs font-semibold text-slate-500 uppercase tracking-wider hidden md:table-cell">Description</th>
                                        <th class="text-center px-3 py-2.5 text-xs font-semibold text-slate-500 uppercase tracking-wider">Sort</th>
                                        <th class="text-center px-3 py-2.5 text-xs font-semibold text-slate-500 uppercase tracking-wider">Active</th>
                                        <th class="text-right px-5 py-2.5 text-xs font-semibold text-slate-500 uppercase tracking-wider">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    @foreach ($activeType->values as $val)
                                        @if ($editingValueId === $val->id)
                                            {{-- Inline edit row --}}
                                            <tr class="bg-indigo-50">
                                                <td class="px-5 py-3">
                                                    <span class="font-mono text-xs text-slate-500">{{ $val->key }}</span>
                                                </td>
                                                <td class="px-3 py-3">
                                                    <input type="text" wire:model="editLabel"
                                                        class="w-full px-2 py-1.5 border rounded text-xs text-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-300
                                                            {{ $errors->has('editLabel') ? 'border-red-400' : 'border-slate-300' }}" />
                                                    @error('editLabel') <p class="text-xs text-red-600 mt-0.5">{{ $message }}</p> @enderror
                                                </td>
                                                <td class="px-3 py-3 hidden md:table-cell">
                                                    <input type="text" wire:model="editDescription"
                                                        class="w-full px-2 py-1.5 border border-slate-300 rounded text-xs text-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-300" />
                                                </td>
                                                <td class="px-3 py-3 text-center">
                                                    <input type="number" min="0" wire:model="editSortOrder"
                                                        class="w-16 px-2 py-1.5 border border-slate-300 rounded text-xs font-mono text-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-300 text-center" />
                                                </td>
                                                <td class="px-3 py-3 text-center">
                                                    <span class="{{ $val->is_active ? 'text-emerald-600' : 'text-slate-400' }} text-xs">
                                                        {{ $val->is_active ? 'Yes' : 'No' }}
                                                    </span>
                                                </td>
                                                <td class="px-5 py-3 text-right">
                                                    <div class="flex items-center justify-end gap-2">
                                                        <button
                                                            wire:click="saveValue"
                                                            wire:loading.attr="disabled"
                                                            class="text-xs font-medium text-white bg-indigo-600 hover:bg-indigo-700 px-3 py-1.5 rounded-lg transition-colors disabled:opacity-60">
                                                            Save
                                                        </button>
                                                        <button
                                                            wire:click="cancelEdit"
                                                            class="text-xs font-medium text-slate-600 bg-slate-100 hover:bg-slate-200 px-3 py-1.5 rounded-lg transition-colors">
                                                            Cancel
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        @else
                                            <tr class="{{ !$val->is_active ? 'opacity-40' : '' }} hover:bg-slate-50 transition-colors">
                                                <td class="px-5 py-3">
                                                    <span class="font-mono text-xs font-semibold text-slate-700">{{ $val->key }}</span>
                                                </td>
                                                <td class="px-3 py-3">
                                                    <span class="text-sm text-slate-800">{{ $val->label }}</span>
                                                </td>
                                                <td class="px-3 py-3 hidden md:table-cell">
                                                    <span class="text-xs text-slate-500">{{ $val->description ?: '—' }}</span>
                                                </td>
                                                <td class="px-3 py-3 text-center">
                                                    <span class="font-mono text-xs text-slate-500">{{ $val->sort_order }}</span>
                                                </td>
                                                <td class="px-3 py-3 text-center">
                                                    @if ($val->is_active)
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
                                                    <div class="flex items-center justify-end gap-2">
                                                        <button
                                                            wire:click="editValue({{ $val->id }})"
                                                            class="text-xs font-medium text-indigo-600 hover:text-indigo-800 transition-colors">
                                                            Edit
                                                        </button>
                                                        @if ($val->is_active)
                                                            <button
                                                                wire:click="deactivateValue({{ $val->id }})"
                                                                wire:confirm="Deactivate this value? It will no longer be available for selection."
                                                                class="text-xs font-medium text-red-500 hover:text-red-700 transition-colors">
                                                                Deactivate
                                                            </button>
                                                        @endif
                                                    </div>
                                                </td>
                                            </tr>
                                        @endif
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <div class="px-5 py-10 text-center">
                            <p class="text-sm text-slate-400">No values yet for this type.</p>
                            @if (!$showAddForm)
                                <button wire:click="$set('showAddForm', true)" class="mt-2 text-sm text-indigo-600 hover:text-indigo-800 font-medium">Add the first value</button>
                            @endif
                        </div>
                    @endif

                </div>
            @else
                <div class="bg-white rounded-xl border border-slate-200 px-6 py-12 text-center">
                    <p class="text-slate-400 text-sm">Select a type from the sidebar to manage its values.</p>
                </div>
            @endif
        </div>

    </div>

</div>
