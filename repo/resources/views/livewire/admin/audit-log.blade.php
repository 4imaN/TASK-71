<div>
    {{-- Page header --}}
    <div class="mb-6">
        <h1 class="text-xl font-bold text-slate-900">Audit Log</h1>
        <p class="text-sm text-slate-500 mt-0.5">
            Immutable record of all system actions. Before/after states with sensitive fields
            already redacted at write time.
        </p>
    </div>

    {{-- Filters --}}
    <div class="bg-white rounded-xl border border-slate-200 p-4 mb-5">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
            <input
                type="text"
                wire:model.live.debounce.400ms="filterAction"
                placeholder="Filter by action…"
                class="px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300"
            />
            <select wire:model.live="filterEntityType"
                    class="px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300">
                <option value="">All entity types</option>
                @foreach ($entityTypes as $et)
                    <option value="{{ $et }}">{{ $et }}</option>
                @endforeach
            </select>
            <input
                type="text"
                wire:model.live.debounce.400ms="filterActorUsername"
                placeholder="Actor username…"
                class="px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300"
            />
            <input
                type="date"
                wire:model.live="filterDateFrom"
                class="px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300"
            />
            <input
                type="date"
                wire:model.live="filterDateTo"
                class="px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300"
            />
            <div class="flex gap-2">
                <input
                    type="text"
                    wire:model.live.debounce.400ms="filterCorrelationId"
                    placeholder="Correlation ID…"
                    class="flex-1 px-3 py-2 rounded-lg border border-slate-300 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-indigo-300"
                />
                <button wire:click="resetFilters"
                        class="px-3 py-2 text-xs text-slate-500 hover:text-slate-800 border border-slate-200 rounded-lg">
                    Reset
                </button>
            </div>
        </div>
    </div>

    {{-- Table --}}
    @if ($entries->isEmpty())
        <div class="text-center py-16 bg-white rounded-xl border border-slate-200">
            <p class="text-slate-500 font-medium">No audit entries match the current filters.</p>
        </div>
    @else
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 bg-slate-50">
                        <th class="text-left px-4 py-3 font-semibold text-slate-600">Occurred</th>
                        <th class="text-left px-4 py-3 font-semibold text-slate-600">Action</th>
                        <th class="text-left px-4 py-3 font-semibold text-slate-600 hidden md:table-cell">Actor</th>
                        <th class="text-left px-4 py-3 font-semibold text-slate-600 hidden lg:table-cell">Entity</th>
                        <th class="text-left px-4 py-3 font-semibold text-slate-600 hidden xl:table-cell">IP</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($entries as $entry)
                        <tr class="hover:bg-slate-50 transition-colors cursor-pointer"
                            wire:click="toggleExpand({{ $entry->id }})">
                            <td class="px-4 py-3 text-slate-500 text-xs whitespace-nowrap">
                                {{ $entry->occurred_at->format('Y-m-d H:i:s') }}
                            </td>
                            <td class="px-4 py-3">
                                <span class="font-mono text-xs font-medium text-slate-800">{{ $entry->action }}</span>
                            </td>
                            <td class="px-4 py-3 text-slate-500 hidden md:table-cell text-xs">
                                @if ($entry->actor_type === 'system')
                                    <span class="italic text-slate-400">system</span>
                                @elseif ($entry->actor)
                                    {{ $entry->actor->username }}
                                @else
                                    <span class="text-slate-400">#{{ $entry->actor_id }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-slate-500 hidden lg:table-cell text-xs">
                                @if ($entry->entity_type)
                                    <span>{{ $entry->entity_type }}</span>
                                    @if ($entry->entity_id)
                                        <span class="text-slate-400">#{{ $entry->entity_id }}</span>
                                    @endif
                                @else
                                    <span class="text-slate-300">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 font-mono text-xs text-slate-400 hidden xl:table-cell">
                                {{ $entry->ip_address ?? '—' }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                @if ($entry->correlation_id)
                                    <button
                                        wire:click.stop="filterByCorrelation('{{ $entry->correlation_id }}')"
                                        class="text-xs text-indigo-500 hover:text-indigo-700 mr-2"
                                        title="Filter by correlation ID">
                                        chain
                                    </button>
                                @endif
                                <svg class="inline w-3.5 h-3.5 text-slate-400 transition-transform {{ $expandedEntryId === $entry->id ? 'rotate-180' : '' }}"
                                     fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                                </svg>
                            </td>
                        </tr>

                        {{-- Expanded detail row --}}
                        @if ($expandedEntryId === $entry->id && $expandedEntry)
                            <tr class="bg-slate-50">
                                <td colspan="6" class="px-4 py-4">
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
                                        {{-- Meta --}}
                                        <div class="space-y-1">
                                            <p class="font-semibold text-slate-600 mb-2">Event metadata</p>
                                            <p><span class="text-slate-500">Correlation ID:</span>
                                               <span class="font-mono text-slate-700">{{ $expandedEntry->correlation_id ?? '—' }}</span></p>
                                            <p><span class="text-slate-500">IP address:</span>
                                               <span class="font-mono">{{ $expandedEntry->ip_address ?? '—' }}</span></p>
                                            <p><span class="text-slate-500">Device fingerprint:</span>
                                               <span class="{{ $expandedEntry->device_fingerprint ? 'text-slate-700' : 'text-slate-400' }}">
                                                   {{ $expandedEntry->device_fingerprint ? 'present' : 'absent' }}
                                               </span></p>
                                        </div>
                                        {{-- State changes --}}
                                        <div class="space-y-2">
                                            @if ($expandedEntry->before_state)
                                                <p class="font-semibold text-slate-600">Before</p>
                                                <pre class="bg-white border border-slate-200 rounded p-2 text-xs overflow-auto max-h-40 text-slate-700">{{ json_encode($expandedEntry->before_state, JSON_PRETTY_PRINT) }}</pre>
                                            @endif
                                            @if ($expandedEntry->after_state)
                                                <p class="font-semibold text-slate-600">After</p>
                                                <pre class="bg-white border border-slate-200 rounded p-2 text-xs overflow-auto max-h-40 text-slate-700">{{ json_encode($expandedEntry->after_state, JSON_PRETTY_PRINT) }}</pre>
                                            @endif
                                            @if ($expandedEntry->metadata)
                                                <p class="font-semibold text-slate-600">Metadata</p>
                                                <pre class="bg-white border border-slate-200 rounded p-2 text-xs overflow-auto max-h-32 text-slate-700">{{ json_encode($expandedEntry->metadata, JSON_PRETTY_PRINT) }}</pre>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-5">
            {{ $entries->links() }}
        </div>
    @endif
</div>
