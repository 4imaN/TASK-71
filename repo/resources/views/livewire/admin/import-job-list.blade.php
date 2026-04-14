<div>
    {{-- ── Page header ──────────────────────────────────────────────────────────── --}}
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-slate-900 tracking-tight">Import Jobs</h1>
            <p class="text-sm text-slate-500 mt-0.5">Manage data imports from external systems</p>
        </div>
        <button
            wire:click="$set('showCreateForm', true)"
            class="inline-flex items-center gap-2 bg-slate-900 text-white text-sm font-medium px-4 py-2 rounded-lg hover:bg-slate-700 transition-colors">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
            </svg>
            New Import
        </button>
    </div>

    {{-- ── Flash message ────────────────────────────────────────────────────────── --}}
    @if ($flashMessage)
        <div class="mb-4 px-4 py-3 rounded-lg text-sm font-medium
            {{ $flashType === 'success' ? 'bg-emerald-50 text-emerald-800 border border-emerald-200' : 'bg-red-50 text-red-800 border border-red-200' }}">
            {{ $flashMessage }}
        </div>
    @endif

    {{-- ── Filters ──────────────────────────────────────────────────────────────── --}}
    <div class="bg-white border border-slate-200 rounded-xl p-4 mb-5 flex flex-wrap items-center gap-3">
        <div class="flex-1 min-w-48">
            <input
                type="text"
                wire:model.live.debounce.300ms="search"
                placeholder="Search by filename or source system..."
                class="w-full text-sm px-3 py-2 border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-slate-400 bg-slate-50"/>
        </div>

        <select
            wire:model.live="entityFilter"
            class="text-sm px-3 py-2 border border-slate-200 rounded-lg bg-slate-50 focus:outline-none focus:ring-2 focus:ring-slate-400">
            <option value="">All entity types</option>
            <option value="departments">Departments</option>
            <option value="user_profiles">User Profiles</option>
            <option value="research_projects">Research Projects</option>
            <option value="services">Services</option>
            <option value="users">Users</option>
        </select>

        <select
            wire:model.live="statusFilter"
            class="text-sm px-3 py-2 border border-slate-200 rounded-lg bg-slate-50 focus:outline-none focus:ring-2 focus:ring-slate-400">
            <option value="">All statuses</option>
            <option value="pending">Pending</option>
            <option value="processing">Processing</option>
            <option value="needs_review">Needs Review</option>
            <option value="completed">Completed</option>
            <option value="failed">Failed</option>
        </select>
    </div>

    {{-- ── Jobs table ───────────────────────────────────────────────────────────── --}}
    <div class="bg-white border border-slate-200 rounded-xl overflow-hidden">
        <table class="w-full text-sm">
            <thead>
                <tr class="bg-slate-50 border-b border-slate-200">
                    <th class="text-left px-4 py-3 font-semibold text-slate-600 text-xs uppercase tracking-wide">ID</th>
                    <th class="text-left px-4 py-3 font-semibold text-slate-600 text-xs uppercase tracking-wide">Entity</th>
                    <th class="text-left px-4 py-3 font-semibold text-slate-600 text-xs uppercase tracking-wide">Source</th>
                    <th class="text-left px-4 py-3 font-semibold text-slate-600 text-xs uppercase tracking-wide">Format</th>
                    <th class="text-left px-4 py-3 font-semibold text-slate-600 text-xs uppercase tracking-wide">Status</th>
                    <th class="text-right px-4 py-3 font-semibold text-slate-600 text-xs uppercase tracking-wide">Records</th>
                    <th class="text-right px-4 py-3 font-semibold text-slate-600 text-xs uppercase tracking-wide">Conflicts</th>
                    <th class="text-left px-4 py-3 font-semibold text-slate-600 text-xs uppercase tracking-wide">Created</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($jobs as $job)
                    <tr class="hover:bg-slate-50 transition-colors">
                        <td class="px-4 py-3 font-mono text-xs text-slate-400">#{{ $job->id }}</td>
                        <td class="px-4 py-3">
                            <span class="font-medium text-slate-800">{{ str_replace('_', ' ', $job->entity_type) }}</span>
                        </td>
                        <td class="px-4 py-3 text-slate-500">{{ $job->source_system ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <span class="px-2 py-0.5 bg-slate-100 text-slate-600 rounded text-xs font-mono uppercase">{{ $job->file_format }}</span>
                        </td>
                        <td class="px-4 py-3">
                            @php
                                $statusColors = [
                                    'pending'      => 'bg-slate-100 text-slate-600',
                                    'processing'   => 'bg-blue-100 text-blue-700',
                                    'needs_review' => 'bg-amber-100 text-amber-700',
                                    'completed'    => 'bg-emerald-100 text-emerald-700',
                                    'failed'       => 'bg-red-100 text-red-700',
                                    'mapping'      => 'bg-purple-100 text-purple-700',
                                    'validating'   => 'bg-indigo-100 text-indigo-700',
                                ];
                                $cls = $statusColors[$job->status] ?? 'bg-slate-100 text-slate-600';
                            @endphp
                            <span class="px-2 py-0.5 rounded-full text-xs font-medium {{ $cls }}">
                                {{ str_replace('_', ' ', $job->status) }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right font-mono text-xs text-slate-600">
                            {{ $job->processed_count }}/{{ $job->total_records }}
                        </td>
                        <td class="px-4 py-3 text-right font-mono text-xs {{ $job->conflict_count > 0 ? 'text-amber-600 font-semibold' : 'text-slate-400' }}">
                            {{ $job->conflict_count }}
                        </td>
                        <td class="px-4 py-3 text-slate-400 text-xs">{{ $job->created_at?->diffForHumans() }}</td>
                        <td class="px-4 py-3 text-right">
                            <a
                                href="{{ route('admin.import-export.show', $job->id) }}"
                                class="text-xs text-indigo-600 hover:text-indigo-800 font-medium">
                                View
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="px-4 py-12 text-center text-slate-400 text-sm">
                            No import jobs found. Create one to get started.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        @if ($jobs->hasPages())
            <div class="border-t border-slate-200 px-4 py-3">
                {{ $jobs->links() }}
            </div>
        @endif
    </div>

    {{-- ── Create import modal ──────────────────────────────────────────────────── --}}
    @if ($showCreateForm)
        <div
            class="fixed inset-0 z-50 flex items-center justify-center"
            style="background: rgba(15,23,42,0.60); backdrop-filter: blur(3px);">
            <div class="w-full max-w-2xl mx-4 bg-white rounded-2xl shadow-2xl border border-slate-200 overflow-hidden max-h-screen overflow-y-auto">

                <div class="bg-slate-900 px-6 py-5 flex items-center justify-between">
                    <div>
                        <h2 class="text-white text-base font-semibold">Create Import Job</h2>
                        <p class="text-slate-400 text-xs mt-0.5">Upload or paste file content to start processing</p>
                    </div>
                    <button wire:click="$set('showCreateForm', false)" class="text-slate-400 hover:text-white transition-colors">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <div class="p-6 space-y-5">
                    {{-- Template loader --}}
                    @if ($templates->isNotEmpty())
                        <div>
                            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1.5">Load from template</label>
                            <div class="flex gap-2">
                                <select
                                    wire:model="templateId"
                                    class="flex-1 text-sm px-3 py-2 border border-slate-200 rounded-lg bg-slate-50 focus:outline-none focus:ring-2 focus:ring-slate-400">
                                    <option value="">Select a template...</option>
                                    @foreach ($templates as $tpl)
                                        <option value="{{ $tpl->id }}">{{ $tpl->name }} ({{ $tpl->entity_type }})</option>
                                    @endforeach
                                </select>
                                <button
                                    wire:click="loadTemplate({{ $templateId ?? 0 }})"
                                    class="px-4 py-2 bg-slate-800 text-white text-sm rounded-lg hover:bg-slate-700 transition-colors">
                                    Load
                                </button>
                            </div>
                        </div>
                        <hr class="border-slate-200">
                    @endif

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1.5">Entity Type *</label>
                            <select
                                wire:model="newEntityType"
                                class="w-full text-sm px-3 py-2 border border-slate-200 rounded-lg bg-slate-50 focus:outline-none focus:ring-2 focus:ring-slate-400">
                                <option value="">Select entity...</option>
                                <option value="departments">Departments</option>
                                <option value="user_profiles">User Profiles</option>
                                <option value="research_projects">Research Projects</option>
                                <option value="services">Services</option>
                                <option value="users">Users</option>
                            </select>
                            @error('newEntityType') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1.5">File Format *</label>
                            <select
                                wire:model="newFormat"
                                class="w-full text-sm px-3 py-2 border border-slate-200 rounded-lg bg-slate-50 focus:outline-none focus:ring-2 focus:ring-slate-400">
                                <option value="csv">CSV</option>
                                <option value="json">JSON</option>
                            </select>
                            @error('newFormat') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1.5">Source System</label>
                            <input
                                type="text"
                                wire:model="newSourceSystem"
                                placeholder="e.g. hr_finance, sso, research_admin"
                                class="w-full text-sm px-3 py-2 border border-slate-200 rounded-lg bg-slate-50 focus:outline-none focus:ring-2 focus:ring-slate-400"/>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1.5">Conflict Strategy *</label>
                            <select
                                wire:model="newConflictStrategy"
                                class="w-full text-sm px-3 py-2 border border-slate-200 rounded-lg bg-slate-50 focus:outline-none focus:ring-2 focus:ring-slate-400">
                                <option value="prefer_newest">Prefer Newest</option>
                                <option value="admin_override">Admin Override</option>
                                <option value="pending">Mark for Review</option>
                            </select>
                            @error('newConflictStrategy') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1.5">Upload File</label>
                        <input
                            type="file"
                            wire:model="newFile"
                            accept=".csv,.json"
                            class="w-full text-sm px-3 py-2 border border-dashed border-slate-300 rounded-lg bg-slate-50 focus:outline-none focus:ring-2 focus:ring-slate-400"/>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1.5">Or Paste Content</label>
                        <textarea
                            wire:model="newContent"
                            rows="4"
                            placeholder="Paste CSV or JSON content here..."
                            class="w-full text-xs font-mono px-3 py-2 border border-slate-200 rounded-lg bg-slate-50 focus:outline-none focus:ring-2 focus:ring-slate-400 resize-y"></textarea>
                    </div>

                    {{-- Field mapping section --}}
                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wider">Field Mapping</label>
                            <button
                                type="button"
                                wire:click="$set('fieldMapping', array_merge($fieldMapping, [''=>'']))"
                                class="text-xs text-indigo-600 hover:text-indigo-800 font-medium">
                                + Add Mapping
                            </button>
                        </div>
                        @if (!empty($fieldMapping))
                            <div class="space-y-2">
                                @foreach ($fieldMapping as $sourceKey => $targetField)
                                    <div class="flex items-center gap-2">
                                        <input
                                            type="text"
                                            wire:model="fieldMapping.{{ $loop->index }}.source"
                                            placeholder="Source column"
                                            class="flex-1 text-xs font-mono px-3 py-1.5 border border-slate-200 rounded-lg bg-slate-50 focus:outline-none focus:ring-2 focus:ring-slate-300"/>
                                        <svg class="w-4 h-4 text-slate-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/>
                                        </svg>
                                        <input
                                            type="text"
                                            wire:model="fieldMapping.{{ $loop->index }}.target"
                                            placeholder="Target field"
                                            class="flex-1 text-xs font-mono px-3 py-1.5 border border-slate-200 rounded-lg bg-slate-50 focus:outline-none focus:ring-2 focus:ring-slate-300"/>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <p class="text-xs text-slate-400 italic">No mappings defined — columns will be used as-is.</p>
                        @endif
                    </div>
                </div>

                <div class="border-t border-slate-200 px-6 py-4 flex items-center justify-end gap-3">
                    <button
                        wire:click="$set('showCreateForm', false)"
                        class="px-4 py-2 text-sm font-medium text-slate-600 hover:text-slate-900 transition-colors">
                        Cancel
                    </button>
                    <button
                        wire:click="createJob"
                        wire:loading.attr="disabled"
                        class="px-5 py-2 bg-slate-900 text-white text-sm font-medium rounded-lg hover:bg-slate-700 transition-colors disabled:opacity-50">
                        <span wire:loading.remove wire:target="createJob">Create & Process</span>
                        <span wire:loading wire:target="createJob">Processing...</span>
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
