<div>

    {{-- ── Flash ─────────────────────────────────────────────────────────────── --}}
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

    {{-- ── Page header ─────────────────────────────────────────────────────────── --}}
    <div class="flex items-start justify-between mb-6">
        <div>
            <h1 class="text-xl font-bold text-slate-900 tracking-tight">Relationship Manager</h1>
            <p class="text-sm text-slate-500 mt-0.5">Define and manage configurable associations between entity types</p>
        </div>
        <button
            wire:click="$toggle('showDefinitionForm')"
            class="flex items-center gap-1.5 text-sm font-medium px-4 py-2 rounded-lg transition-colors
                {{ $showDefinitionForm ? 'bg-slate-100 text-slate-600 hover:bg-slate-200' : 'bg-indigo-600 text-white hover:bg-indigo-700' }}">
            @if (!$showDefinitionForm)
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                New Relationship Type
            @else
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                Cancel
            @endif
        </button>
    </div>

    {{-- ── New definition form ────────────────────────────────────────────────── --}}
    @if ($showDefinitionForm)
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden mb-5">
            <div class="bg-slate-900 px-5 py-3.5">
                <h2 class="text-sm font-semibold text-white">New Relationship Type</h2>
            </div>
            <div class="px-5 py-5 space-y-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1.5">Name <span class="text-red-500">*</span></label>
                    <input type="text" wire:model="defName" placeholder="e.g. Service serves Department"
                        class="w-full px-3 py-2.5 border rounded-lg text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400
                            {{ $errors->has('defName') ? 'border-red-400' : 'border-slate-300' }}" />
                    @error('defName') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1.5">Source Entity <span class="text-red-500">*</span></label>
                        <select wire:model="defSourceEntityType"
                            class="w-full px-3 py-2.5 border rounded-lg text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400
                                {{ $errors->has('defSourceEntityType') ? 'border-red-400' : 'border-slate-300' }}">
                            <option value="">Select…</option>
                            @foreach ($allowedEntityTypes as $type)
                                <option value="{{ $type }}">{{ $type }}</option>
                            @endforeach
                        </select>
                        @error('defSourceEntityType') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1.5">Target Entity <span class="text-red-500">*</span></label>
                        <select wire:model="defTargetEntityType"
                            class="w-full px-3 py-2.5 border rounded-lg text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400
                                {{ $errors->has('defTargetEntityType') ? 'border-red-400' : 'border-slate-300' }}">
                            <option value="">Select…</option>
                            @foreach ($allowedEntityTypes as $type)
                                <option value="{{ $type }}">{{ $type }}</option>
                            @endforeach
                        </select>
                        @error('defTargetEntityType') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1.5">Cardinality</label>
                        <select wire:model="defCardinality"
                            class="w-full px-3 py-2.5 border border-slate-300 rounded-lg text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400">
                            <option value="many_to_many">Many-to-many</option>
                            <option value="one_to_many">One-to-many</option>
                        </select>
                    </div>
                </div>

                <div class="flex items-center gap-2 pt-1 border-t border-slate-100">
                    <button wire:click="saveDefinition" wire:loading.attr="disabled"
                        class="px-5 py-2.5 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 transition-colors disabled:opacity-60">
                        <span wire:loading.remove wire:target="saveDefinition">Create Definition</span>
                        <span wire:loading wire:target="saveDefinition">Saving…</span>
                    </button>
                    <button wire:click="resetDefinitionForm"
                        class="px-4 py-2.5 bg-slate-100 text-slate-600 text-sm font-medium rounded-lg hover:bg-slate-200 transition-colors">
                        Cancel
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- ── Two-column layout: definitions list + instances panel ─────────────── --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">

        {{-- Definitions list --}}
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div class="bg-slate-900 px-5 py-3.5 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-white">Relationship Types</h2>
                <span class="text-xs text-slate-400">{{ $definitions->count() }} defined</span>
            </div>

            @if ($definitions->isNotEmpty())
                <div class="divide-y divide-slate-100">
                    @foreach ($definitions as $def)
                        <div class="px-5 py-3.5 flex items-start justify-between gap-3
                            {{ $selectedDefinitionId === $def->id ? 'bg-indigo-50 border-l-2 border-indigo-500' : 'hover:bg-slate-50' }}
                            {{ !$def->is_active ? 'opacity-40' : '' }} transition-colors cursor-pointer"
                            wire:click="selectDefinition({{ $def->id }})">
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-slate-800 truncate">{{ $def->name }}</p>
                                <p class="text-xs text-slate-500 mt-0.5">
                                    <span class="font-mono bg-slate-100 px-1 rounded">{{ $def->source_entity_type }}</span>
                                    <span class="mx-1">→</span>
                                    <span class="font-mono bg-slate-100 px-1 rounded">{{ $def->target_entity_type }}</span>
                                    <span class="ml-2 text-slate-400">{{ $def->cardinality }}</span>
                                </p>
                                <p class="text-xs text-slate-400 mt-1">{{ $def->instance_count }} active {{ Str::plural('link', $def->instance_count) }}</p>
                            </div>
                            <div class="flex items-center gap-2 shrink-0">
                                @if (!$def->is_active)
                                    <span class="text-xs text-slate-400 bg-slate-100 px-2 py-0.5 rounded-full">inactive</span>
                                @endif
                                @if ($def->is_active)
                                    <button
                                        wire:click.stop="deactivateDefinition({{ $def->id }})"
                                        wire:confirm="Deactivate this relationship type? Existing instances are preserved but no new links can be created."
                                        class="text-xs font-medium text-red-500 hover:text-red-700 transition-colors">
                                        Deactivate
                                    </button>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="px-5 py-12 text-center">
                    <svg class="w-10 h-10 text-slate-200 mx-auto mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/>
                    </svg>
                    <p class="text-sm text-slate-400">No relationship types defined yet.</p>
                </div>
            @endif
        </div>

        {{-- Instances panel --}}
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            @if ($selectedDefinition)
                <div class="bg-slate-900 px-5 py-3.5 flex items-center justify-between">
                    <div>
                        <h2 class="text-sm font-semibold text-white">{{ $selectedDefinition->name }}</h2>
                        <p class="text-xs text-slate-400 mt-0.5">
                            {{ $selectedDefinition->source_entity_type }} → {{ $selectedDefinition->target_entity_type }}
                        </p>
                    </div>
                    @if ($selectedDefinition->is_active)
                        <button wire:click="$toggle('showInstanceForm')"
                            class="flex items-center gap-1 text-xs font-medium px-3 py-1.5 rounded-lg transition-colors
                                {{ $showInstanceForm ? 'bg-slate-700 text-slate-300' : 'bg-indigo-600 text-white hover:bg-indigo-500' }}">
                            @if (!$showInstanceForm)
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                            @endif
                            {{ $showInstanceForm ? 'Cancel' : 'Link Entities' }}
                        </button>
                    @endif
                </div>

                {{-- Link form --}}
                @if ($showInstanceForm && $selectedDefinition->is_active)
                    <div class="px-5 py-4 bg-slate-50 border-b border-slate-200">
                        <div class="grid grid-cols-2 gap-3 mb-3">
                            <div>
                                <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1">
                                    {{ $selectedDefinition->source_entity_type }} ID <span class="text-red-500">*</span>
                                </label>
                                <input type="number" wire:model="instanceSourceId" min="1" placeholder="Source ID"
                                    class="w-full px-3 py-2 border rounded-lg text-sm font-mono text-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400
                                        {{ $errors->has('instanceSourceId') ? 'border-red-400' : 'border-slate-300' }}" />
                                @error('instanceSourceId') <p class="mt-0.5 text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1">
                                    {{ $selectedDefinition->target_entity_type }} ID <span class="text-red-500">*</span>
                                </label>
                                <input type="number" wire:model="instanceTargetId" min="1" placeholder="Target ID"
                                    class="w-full px-3 py-2 border rounded-lg text-sm font-mono text-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400
                                        {{ $errors->has('instanceTargetId') ? 'border-red-400' : 'border-slate-300' }}" />
                                @error('instanceTargetId') <p class="mt-0.5 text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>
                        </div>
                        <button wire:click="linkEntities" wire:loading.attr="disabled"
                            class="px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 transition-colors disabled:opacity-60">
                            <span wire:loading.remove wire:target="linkEntities">Create Link</span>
                            <span wire:loading wire:target="linkEntities">Linking…</span>
                        </button>
                    </div>
                @endif

                {{-- Instances list --}}
                @if ($instances->isNotEmpty())
                    <div class="divide-y divide-slate-100">
                        @foreach ($instances as $instance)
                            <div class="px-5 py-3 flex items-center justify-between gap-3 hover:bg-slate-50 transition-colors">
                                <div class="text-sm font-mono text-slate-700">
                                    <span class="text-slate-400 text-xs">{{ $selectedDefinition->source_entity_type }}</span>
                                    <span class="font-semibold ml-1">{{ $instance->source_id }}</span>
                                    <span class="mx-2 text-slate-300">→</span>
                                    <span class="text-slate-400 text-xs">{{ $selectedDefinition->target_entity_type }}</span>
                                    <span class="font-semibold ml-1">{{ $instance->target_id }}</span>
                                </div>
                                <button
                                    wire:click="unlinkInstance({{ $instance->id }})"
                                    wire:confirm="Remove this link?"
                                    class="text-xs font-medium text-red-500 hover:text-red-700 transition-colors shrink-0">
                                    Unlink
                                </button>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="px-5 py-10 text-center">
                        <p class="text-sm text-slate-400">No links yet. Use "Link Entities" to add the first one.</p>
                    </div>
                @endif

            @else
                <div class="px-5 py-16 text-center">
                    <svg class="w-10 h-10 text-slate-200 mx-auto mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 15l-2 5L9 9l11 4-5 2zm0 0l5 5"/>
                    </svg>
                    <p class="text-sm text-slate-400">Select a relationship type on the left to manage its instances.</p>
                </div>
            @endif
        </div>

    </div>

</div>
