<div>
    {{-- Page header --}}
    <div class="flex items-center justify-between mb-6">
        <div>
            <a href="{{ route('editor.services.index') }}"
               class="text-sm text-slate-500 hover:text-indigo-600 transition-colors mb-1 inline-block">
                ← Back to services
            </a>
            <h1 class="text-xl font-bold text-slate-900">Pending Confirmations</h1>
            <p class="text-sm text-slate-500 mt-0.5">
                Reservations awaiting manual operator approval
            </p>
        </div>
    </div>

    {{-- Flash message --}}
    @if ($flashMessage)
        <div class="mb-4 px-4 py-3 rounded-lg text-sm font-medium
            {{ $flashType === 'success' ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-red-50 text-red-700 border border-red-200' }}">
            {{ $flashMessage }}
        </div>
    @endif

    {{-- Empty state --}}
    @if ($pending->isEmpty())
        <div class="text-center py-20 bg-white rounded-xl border border-slate-200">
            <svg class="w-12 h-12 mx-auto text-slate-300 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                      d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <p class="text-slate-500 font-medium">No pending confirmations</p>
            <p class="text-sm text-slate-400 mt-1">All manual-confirm reservations have been reviewed.</p>
        </div>
    @else
        {{-- Table --}}
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 bg-slate-50 text-xs font-semibold text-slate-500 uppercase tracking-wide">
                        <th class="px-4 py-3 text-left">Learner</th>
                        <th class="px-4 py-3 text-left">Service</th>
                        <th class="px-4 py-3 text-left">Slot</th>
                        <th class="px-4 py-3 text-left">Capacity</th>
                        <th class="px-4 py-3 text-left">Requested</th>
                        <th class="px-4 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($pending as $reservation)
                        @php
                            $label = ($reservation->user?->display_name ?? $reservation->user?->username ?? 'Unknown')
                                   . ' — ' . ($reservation->service?->title ?? 'Unknown service');
                        @endphp
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="px-4 py-3">
                                <div class="font-medium text-slate-800">
                                    {{ $reservation->user?->display_name ?? '—' }}
                                </div>
                                <div class="text-xs text-slate-400">
                                    {{ $reservation->user?->username ?? '' }}
                                    @if ($reservation->user?->audience_type)
                                        · {{ $reservation->user->audience_type }}
                                    @endif
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <span class="font-medium text-slate-800">
                                    {{ $reservation->service?->title ?? '—' }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-slate-600">
                                @if ($reservation->timeSlot)
                                    <div>{{ $reservation->timeSlot->starts_at->format('M j, Y') }}</div>
                                    <div class="text-xs text-slate-400">
                                        {{ $reservation->timeSlot->starts_at->format('g:i A') }}
                                        –
                                        {{ $reservation->timeSlot->ends_at->format('g:i A') }}
                                    </div>
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-4 py-3 text-slate-600">
                                @if ($reservation->timeSlot)
                                    {{ $reservation->timeSlot->booked_count }} /
                                    {{ $reservation->timeSlot->capacity }}
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-4 py-3 text-slate-400 text-xs">
                                {{ $reservation->requested_at?->format('M j, Y g:i A') ?? '—' }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <button
                                        wire:click="confirm({{ $reservation->id }})"
                                        wire:loading.attr="disabled"
                                        class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-emerald-600 text-white text-xs font-medium hover:bg-emerald-700 transition-colors disabled:opacity-50">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                        </svg>
                                        Confirm
                                    </button>
                                    <button
                                        wire:click="openRejectModal({{ $reservation->id }}, '{{ addslashes($label) }}')"
                                        class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-red-50 text-red-700 border border-red-200 text-xs font-medium hover:bg-red-100 transition-colors">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                        </svg>
                                        Reject
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Pagination --}}
        @if ($pending->hasPages())
            <div class="mt-4">
                {{ $pending->links() }}
            </div>
        @endif
    @endif

    {{-- Reject confirmation modal --}}
    @if ($showRejectModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm"
             x-data x-on:keydown.escape.window="$wire.closeRejectModal()">
            <div class="bg-white rounded-2xl shadow-xl w-full max-w-md mx-4 p-6">
                <div class="flex items-start gap-3 mb-4">
                    <div class="w-10 h-10 rounded-full bg-red-100 flex items-center justify-center flex-shrink-0">
                        <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/>
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-base font-semibold text-slate-900">Reject reservation?</h3>
                        <p class="text-sm text-slate-500 mt-1">
                            This will cancel the reservation for
                            <strong class="text-slate-700">{{ $rejectReservationLabel }}</strong>
                            and free the slot capacity. The learner will not receive automatic notice.
                        </p>
                    </div>
                </div>
                <div class="flex items-center justify-end gap-3 mt-6">
                    <button
                        wire:click="closeRejectModal"
                        class="px-4 py-2 rounded-lg text-sm font-medium text-slate-700 bg-slate-100 hover:bg-slate-200 transition-colors">
                        Cancel
                    </button>
                    <button
                        wire:click="confirmReject"
                        wire:loading.attr="disabled"
                        class="px-4 py-2 rounded-lg text-sm font-medium text-white bg-red-600 hover:bg-red-700 transition-colors disabled:opacity-50">
                        Reject reservation
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
