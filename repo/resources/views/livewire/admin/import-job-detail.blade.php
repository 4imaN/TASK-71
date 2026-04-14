<div>
    {{-- ── Page header ──────────────────────────────────────────────────────────── --}}
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-3">
            <a href="{{ route('admin.import-export.index') }}" class="text-slate-400 hover:text-slate-700 transition-colors">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
                </svg>
            </a>
            <div>
                <h1 class="text-2xl font-bold text-slate-900 tracking-tight">Import Job #{{ $job->id }}</h1>
                <p class="text-sm text-slate-500 mt-0.5">{{ str_replace('_', ' ', $job->entity_type) }} &middot; {{ strtoupper($job->file_format) }}</p>
            </div>
        </div>

        @if ($job->status === 'needs_review')
            <button
                wire:click="reprocess"
                wire:confirm="Reprocess all resolved conflicts?"
                class="inline-flex items-center gap-2 bg-amber-600 text-white text-sm font-medium px-4 py-2 rounded-lg hover:bg-amber-700 transition-colors">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                </svg>
                Reprocess Resolved
            </button>
        @endif
    </div>

    {{-- ── Flash message ────────────────────────────────────────────────────────── --}}
    @if ($flashMessage)
        <div class="mb-4 px-4 py-3 rounded-lg text-sm font-medium
            {{ $flashType === 'success' ? 'bg-emerald-50 text-emerald-800 border border-emerald-200' : 'bg-red-50 text-red-800 border border-red-200' }}">
            {{ $flashMessage }}
        </div>
    @endif

    {{-- ── Job summary cards ────────────────────────────────────────────────────── --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        @php
            $statusColors = [
                'pending'      => 'bg-slate-100 text-slate-600',
                'processing'   => 'bg-blue-100 text-blue-700',
                'needs_review' => 'bg-amber-100 text-amber-700',
                'completed'    => 'bg-emerald-100 text-emerald-700',
                'failed'       => 'bg-red-100 text-red-700',
            ];
            $sc = $statusColors[$job->status] ?? 'bg-slate-100 text-slate-600';
        @endphp

        <div class="bg-white border border-slate-200 rounded-xl p-4">
            <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1">Status</p>
            <span class="px-2 py-1 rounded-full text-sm font-medium {{ $sc }}">
                {{ str_replace('_', ' ', $job->status) }}
            </span>
        </div>

        <div class="bg-white border border-slate-200 rounded-xl p-4">
            <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1">Processed</p>
            <p class="text-2xl font-bold text-slate-900 font-mono">{{ $job->processed_count }}<span class="text-base text-slate-400">/{{ $job->total_records }}</span></p>
        </div>

        <div class="bg-white border border-slate-200 rounded-xl p-4">
            <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1">Conflicts</p>
            <p class="text-2xl font-bold {{ $job->conflict_count > 0 ? 'text-amber-600' : 'text-slate-900' }} font-mono">{{ $job->conflict_count }}</p>
        </div>

        <div class="bg-white border border-slate-200 rounded-xl p-4">
            <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1">Errors</p>
            <p class="text-2xl font-bold {{ $job->error_count > 0 ? 'text-red-600' : 'text-slate-900' }} font-mono">{{ $job->error_count }}</p>
        </div>
    </div>

    {{-- ── Job metadata ─────────────────────────────────────────────────────────── --}}
    <div class="bg-white border border-slate-200 rounded-xl p-5 mb-6">
        <h2 class="text-sm font-semibold text-slate-700 mb-3">Job Details</h2>
        <dl class="grid grid-cols-2 md:grid-cols-3 gap-x-6 gap-y-3 text-sm">
            <div>
                <dt class="text-xs text-slate-400 uppercase font-semibold tracking-wider">Source System</dt>
                <dd class="text-slate-800 mt-0.5">{{ $job->source_system ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-xs text-slate-400 uppercase font-semibold tracking-wider">Conflict Strategy</dt>
                <dd class="text-slate-800 mt-0.5">{{ str_replace('_', ' ', $job->conflict_resolution_strategy) }}</dd>
            </div>
            <div>
                <dt class="text-xs text-slate-400 uppercase font-semibold tracking-wider">Original Filename</dt>
                <dd class="text-slate-800 mt-0.5 truncate">{{ $job->original_filename ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-xs text-slate-400 uppercase font-semibold tracking-wider">Created</dt>
                <dd class="text-slate-800 mt-0.5">{{ $job->created_at?->format('M j, Y H:i') }}</dd>
            </div>
            <div>
                <dt class="text-xs text-slate-400 uppercase font-semibold tracking-wider">Completed</dt>
                <dd class="text-slate-800 mt-0.5">{{ $job->completed_at?->format('M j, Y H:i') ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-xs text-slate-400 uppercase font-semibold tracking-wider">Last Sync Cutoff</dt>
                <dd class="text-slate-800 mt-0.5">{{ $job->last_sync_timestamp?->format('M j, Y H:i') ?? '—' }}</dd>
            </div>
        </dl>

        @if ($job->error_summary)
            <div class="mt-4 p-3 bg-red-50 border border-red-200 rounded-lg">
                <p class="text-xs font-semibold text-red-700 mb-1">Error Summary</p>
                <pre class="text-xs text-red-600 whitespace-pre-wrap">{{ $job->error_summary }}</pre>
            </div>
        @endif
    </div>

    {{-- ── Conflicts section ────────────────────────────────────────────────────── --}}
    <div class="bg-white border border-slate-200 rounded-xl overflow-hidden">
        <div class="border-b border-slate-200 px-5 py-4 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-slate-700">Conflicts</h2>
            <div class="flex bg-slate-100 rounded-lg p-0.5 text-xs">
                @foreach (['pending' => 'Pending', 'resolved' => 'Resolved', 'all' => 'All'] as $filter => $label)
                    <button
                        wire:click="$set('conflictFilter', '{{ $filter }}')"
                        class="px-3 py-1.5 rounded-md font-medium transition-colors {{ $conflictFilter === $filter ? 'bg-white shadow-sm text-slate-800' : 'text-slate-500 hover:text-slate-700' }}">
                        {{ $label }}
                    </button>
                @endforeach
            </div>
        </div>

        @forelse ($conflicts as $conflict)
            <div class="border-b border-slate-100 p-5 last:border-b-0">
                <div class="flex items-start justify-between mb-3">
                    <div>
                        <span class="font-mono text-xs text-slate-500">Record #{{ $conflict->record_identifier }}</span>
                        <span class="ml-2 px-2 py-0.5 rounded-full text-xs font-medium {{ $conflict->resolution === 'pending' ? 'bg-amber-100 text-amber-700' : 'bg-emerald-100 text-emerald-700' }}">
                            {{ $conflict->resolution }}
                        </span>
                    </div>
                    @if ($conflict->resolution === 'pending')
                        <div class="flex items-center gap-2">
                            <button
                                wire:click="resolveConflict({{ $conflict->id }}, 'prefer_newest')"
                                wire:confirm="Use incoming record (prefer newest)?"
                                class="px-3 py-1.5 text-xs font-medium bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                                Use Incoming
                            </button>
                            <button
                                wire:click="resolveConflict({{ $conflict->id }}, 'admin_override')"
                                wire:confirm="Apply admin override with current values?"
                                class="px-3 py-1.5 text-xs font-medium bg-slate-800 text-white rounded-lg hover:bg-slate-700 transition-colors">
                                Override
                            </button>
                        </div>
                    @else
                        <span class="text-xs text-slate-400">Resolved {{ $conflict->resolved_at?->diffForHumans() }}</span>
                    @endif
                </div>

                {{-- Field diffs table --}}
                @if (!empty($conflict->field_diffs))
                    <div class="bg-slate-50 rounded-lg border border-slate-200 overflow-hidden">
                        <table class="w-full text-xs">
                            <thead>
                                <tr class="bg-slate-100 border-b border-slate-200">
                                    <th class="text-left px-3 py-2 font-semibold text-slate-500 uppercase tracking-wider">Field</th>
                                    <th class="text-left px-3 py-2 font-semibold text-slate-500 uppercase tracking-wider">Current Value</th>
                                    <th class="text-left px-3 py-2 font-semibold text-slate-500 uppercase tracking-wider">Incoming Value</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200">
                                @foreach ($conflict->field_diffs as $diff)
                                    <tr>
                                        <td class="px-3 py-2 font-mono font-semibold text-slate-700">{{ $diff['field'] }}</td>
                                        <td class="px-3 py-2 text-slate-500">{{ is_array($diff['local_value']) ? json_encode($diff['local_value']) : ($diff['local_value'] ?? '—') }}</td>
                                        <td class="px-3 py-2 text-indigo-700 font-medium">{{ is_array($diff['incoming_value']) ? json_encode($diff['incoming_value']) : ($diff['incoming_value'] ?? '—') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        @empty
            <div class="px-5 py-10 text-center text-slate-400 text-sm">
                No conflicts found for this filter.
            </div>
        @endforelse

        @if ($conflicts->hasPages())
            <div class="border-t border-slate-200 px-4 py-3">
                {{ $conflicts->links() }}
            </div>
        @endif
    </div>
</div>
