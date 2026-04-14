<div>
    {{-- Page header --}}
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-xl font-bold text-slate-900">Backup &amp; Restore</h1>
            <p class="text-sm text-slate-500 mt-0.5">
                Manual snapshots and monthly restore-drill records.
                Snapshots are retained for {{ $retention }} days.
            </p>
        </div>
        <button
            wire:click="initiateTrigger"
            class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-indigo-600 text-white text-sm font-medium hover:bg-indigo-700 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
            </svg>
            Run Backup
        </button>
    </div>

    {{-- Step-up modal --}}
    @if ($showStepUp)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
            <div class="bg-white rounded-xl shadow-xl p-6 w-full max-w-sm">
                <h2 class="text-base font-semibold text-slate-800 mb-1">Confirm your identity</h2>
                <p class="text-sm text-slate-500 mb-4">
                    Enter your current password to authorise this backup.
                </p>
                @if ($stepUpError)
                    <p class="text-sm text-red-600 mb-3">{{ $stepUpError }}</p>
                @endif
                <input
                    type="password"
                    wire:model="stepUpPassword"
                    wire:keydown.enter="verifyStepUp"
                    placeholder="Current password"
                    class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300 mb-4"
                    autofocus
                />
                <div class="flex gap-2 justify-end">
                    <button wire:click="cancelStepUp"
                            class="px-4 py-2 text-sm text-slate-600 hover:text-slate-800">
                        Cancel
                    </button>
                    <button wire:click="verifyStepUp"
                            class="px-4 py-2 rounded-lg bg-indigo-600 text-white text-sm font-medium hover:bg-indigo-700">
                        Confirm
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- Restore-test modal --}}
    @if ($showRestoreForm)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
            <div class="bg-white rounded-xl shadow-xl p-6 w-full max-w-md">
                <h2 class="text-base font-semibold text-slate-800 mb-1">Record Restore Test</h2>
                <p class="text-sm text-slate-500 mb-4">
                    Document the outcome of a restore drill against this snapshot.
                </p>
                <label class="block text-sm font-medium text-slate-700 mb-1">Result</label>
                <select wire:model="restoreResult"
                        class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300 mb-4">
                    <option value="success">Success — full restore verified</option>
                    <option value="partial">Partial — some data recovered</option>
                    <option value="failed">Failed — restore unsuccessful</option>
                </select>
                <label class="block text-sm font-medium text-slate-700 mb-1">Notes</label>
                <textarea wire:model="restoreNotes" rows="3"
                          placeholder="Optional observations, duration, environment used…"
                          class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300 mb-4"></textarea>
                <div class="flex gap-2 justify-end">
                    <button wire:click="cancelRestoreTestForm"
                            class="px-4 py-2 text-sm text-slate-600 hover:text-slate-800">
                        Cancel
                    </button>
                    <button wire:click="submitRestoreTest"
                            class="px-4 py-2 rounded-lg bg-emerald-600 text-white text-sm font-medium hover:bg-emerald-700">
                        Save Result
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- Flash message --}}
    @if ($flashMessage)
        <div class="mb-4 px-4 py-3 rounded-lg text-sm font-medium
            {{ $flashType === 'success'
                ? 'bg-emerald-50 text-emerald-700 border border-emerald-200'
                : 'bg-red-50 text-red-700 border border-red-200' }}">
            {{ $flashMessage }}
        </div>
    @endif

    {{-- Step-up badge --}}
    <div class="mb-4 flex items-center gap-2 text-xs text-slate-500">
        @if ($stepUpOk)
            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200 font-medium">
                <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                </svg>
                Identity verified (15-min window)
            </span>
        @else
            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-slate-100 text-slate-500 border border-slate-200">
                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                </svg>
                Password required to run backup
            </span>
        @endif
    </div>

    {{-- Snapshot table --}}
    @if ($backups->isEmpty())
        <div class="text-center py-20 bg-white rounded-xl border border-slate-200">
            <svg class="w-12 h-12 mx-auto text-slate-300 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                      d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582 4 8 4m0-4c-4.418 0-8 1.79-8 4"/>
            </svg>
            <p class="text-slate-500 font-medium">No snapshots yet</p>
            <p class="text-sm text-slate-400 mt-1">Run the first manual backup to start.</p>
        </div>
    @else
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 bg-slate-50">
                        <th class="text-left px-4 py-3 font-semibold text-slate-600">Snapshot</th>
                        <th class="text-left px-4 py-3 font-semibold text-slate-600 hidden sm:table-cell">Type</th>
                        <th class="text-left px-4 py-3 font-semibold text-slate-600 hidden md:table-cell">Size</th>
                        <th class="text-left px-4 py-3 font-semibold text-slate-600">Status</th>
                        <th class="text-left px-4 py-3 font-semibold text-slate-600 hidden lg:table-cell">Restore Tests</th>
                        <th class="text-left px-4 py-3 font-semibold text-slate-600 hidden lg:table-cell">Created</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($backups as $backup)
                        @php
                            $statusClass = $backup->status === 'success'
                                ? 'bg-emerald-100 text-emerald-700'
                                : 'bg-red-100 text-red-700';
                        @endphp
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="px-4 py-3">
                                <span class="font-mono text-xs text-slate-700">{{ $backup->snapshot_filename }}</span>
                                @if ($backup->error_message)
                                    <span class="block text-xs text-red-500 mt-0.5 max-w-xs truncate" title="{{ $backup->error_message }}">
                                        {{ $backup->error_message }}
                                    </span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-slate-500 hidden sm:table-cell">
                                {{ ucfirst($backup->type) }}
                            </td>
                            <td class="px-4 py-3 text-slate-500 hidden md:table-cell font-mono text-xs">
                                @if ($backup->file_size_bytes > 0)
                                    {{ number_format($backup->file_size_bytes / 1024 / 1024, 1) }} MB
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium {{ $statusClass }}">
                                    {{ ucfirst($backup->status) }}
                                </span>
                            </td>
                            <td class="px-4 py-3 hidden lg:table-cell">
                                @if ($backup->restore_tests_count > 0)
                                    <span class="text-slate-600 text-xs">{{ $backup->restore_tests_count }} recorded</span>
                                @else
                                    <span class="text-slate-400 text-xs">None</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-slate-400 text-xs hidden lg:table-cell">
                                {{ $backup->created_at->diffForHumans() }}
                            </td>
                            <td class="px-4 py-3">
                                @if ($backup->status === 'success')
                                    <button
                                        wire:click="openRestoreTestForm({{ $backup->id }})"
                                        class="text-xs font-medium text-indigo-600 hover:text-indigo-800 transition-colors whitespace-nowrap">
                                        Record Test
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-5">
            {{ $backups->links() }}
        </div>
    @endif
</div>
