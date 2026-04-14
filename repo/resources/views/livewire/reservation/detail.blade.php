<div class="max-w-3xl" x-data>

    {{-- Breadcrumb --}}
    <nav class="flex items-center gap-1.5 text-xs text-slate-400 mb-6">
        <a href="{{ route('dashboard') }}" class="hover:text-slate-600 transition-colors">Home</a>
        <span>/</span>
        <a href="{{ route('reservations.index') }}" class="hover:text-slate-600 transition-colors">My reservations</a>
        <span>/</span>
        <span class="text-slate-600 font-medium truncate max-w-xs">{{ $reservation->service?->title ?? 'Reservation' }}</span>
    </nav>

    @php
        $isCancelable = in_array($reservation->status, ['pending', 'confirmed']);
        $isCheckedIn  = in_array($reservation->status, ['checked_in', 'partial_attendance']);

        $statusConfig = [
            'pending'            => ['bg-yellow-50 text-yellow-700 border-yellow-200',   'Pending confirmation'],
            'confirmed'          => ['bg-emerald-50 text-emerald-700 border-emerald-200', 'Confirmed'],
            'checked_in'         => ['bg-blue-50 text-blue-700 border-blue-200',          'Checked in'],
            'partial_attendance' => ['bg-sky-50 text-sky-700 border-sky-200',             'Partial attendance'],
            'checked_out'        => ['bg-teal-50 text-teal-700 border-teal-200',          'Checked out'],
            'cancelled'          => ['bg-red-50 text-red-700 border-red-200',             'Cancelled'],
            'rescheduled'        => ['bg-slate-50 text-slate-600 border-slate-200',       'Rescheduled'],
            'expired'            => ['bg-slate-50 text-slate-500 border-slate-200',       'Expired'],
            'no_show'            => ['bg-orange-50 text-orange-700 border-orange-200',    'No show'],
        ][$reservation->status] ?? ['bg-slate-50 text-slate-500 border-slate-200', ucfirst($reservation->status)];
    @endphp

    {{-- No-show warning: slot has passed without check-in --}}
    @if ($reservation->status === 'no_show')
        <div class="bg-orange-50 border border-orange-200 rounded-xl p-4 mb-5 flex items-start gap-3">
            <svg class="w-5 h-5 text-orange-600 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/>
            </svg>
            <div>
                <p class="text-sm font-semibold text-orange-800">No-show recorded</p>
                <p class="text-sm text-orange-700 mt-0.5">
                    You did not check in during the required window. This no-show has been recorded.
                    Accumulating 2 no-shows within 60 days will result in a temporary booking freeze.
                </p>
            </div>
        </div>
    @endif

    {{-- Cancel consequence warning --}}
    @if ($isCancelable && $isLateCancellation)
        <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 mb-5 flex items-start gap-3">
            <svg class="w-5 h-5 text-amber-600 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/>
            </svg>
            <div>
                <p class="text-sm font-semibold text-amber-800">Late cancellation warning</p>
                <p class="text-sm text-amber-700 mt-0.5">
                    This slot starts in
                    @if ($hoursUntilSlot !== null)
                        {{ number_format($hoursUntilSlot, 1) }} hour(s).
                    @endif
                    Cancelling now will incur a
                    @if ($consequence['type'] === 'fee')
                        <strong>${{ number_format($consequence['amount'], 2) }} fee</strong>.
                    @else
                        <strong>{{ (int) $consequence['amount'] }}-point deduction</strong>.
                    @endif
                </p>
            </div>
        </div>
    @endif

    {{-- Reservation card --}}
    <div class="bg-white rounded-xl border border-slate-200 p-6 mb-5">
        <div class="flex items-start justify-between gap-4 flex-wrap mb-4">
            <div>
                <span class="inline-flex items-center text-xs font-medium border px-2.5 py-1 rounded-full {{ $statusConfig[0] }} mb-2">
                    {{ $statusConfig[1] }}
                </span>
                <h1 class="text-xl font-bold text-slate-900">
                    {{ $reservation->service?->title ?? 'Service' }}
                </h1>
                @if ($reservation->service)
                    <a href="{{ route('catalog.show', $reservation->service->slug) }}"
                       class="text-sm text-indigo-500 hover:underline">View service →</a>
                @endif
            </div>

            @if ($reservation->service?->requires_manual_confirmation)
                <span class="text-xs bg-yellow-50 text-yellow-700 border border-yellow-200 px-2.5 py-1 rounded-full">
                    Requires manual confirmation
                </span>
            @endif
        </div>

        {{-- Slot details --}}
        @if ($reservation->timeSlot)
            <div class="grid grid-cols-2 gap-4 py-4 border-t border-slate-100">
                <div>
                    <p class="text-xs font-medium text-slate-400 uppercase tracking-wide mb-1">Date</p>
                    <p class="text-sm font-medium text-slate-700">{{ $reservation->timeSlot->starts_at->format('l, F j, Y') }}</p>
                </div>
                <div>
                    <p class="text-xs font-medium text-slate-400 uppercase tracking-wide mb-1">Time</p>
                    <p class="text-sm font-medium text-slate-700">
                        {{ $reservation->timeSlot->starts_at->format('g:i A') }}
                        –
                        {{ $reservation->timeSlot->ends_at->format('g:i A') }}
                    </p>
                </div>
                <div>
                    <p class="text-xs font-medium text-slate-400 uppercase tracking-wide mb-1">Booked</p>
                    <p class="text-sm text-slate-600">{{ $reservation->requested_at?->format('M j, Y g:i A') ?? '—' }}</p>
                </div>
                @if ($reservation->expires_at && $reservation->status === 'pending')
                    <div>
                        <p class="text-xs font-medium text-slate-400 uppercase tracking-wide mb-1">Expires</p>
                        <p class="text-sm text-amber-600 font-medium">{{ $reservation->expires_at->format('M j, Y g:i A') }}</p>
                    </div>
                @endif
            </div>
        @endif

        {{-- Cancellation consequence (already applied) --}}
        @if ($reservation->cancellation_consequence)
            <div class="mt-4 pt-4 border-t border-slate-100">
                <p class="text-xs font-medium text-slate-400 uppercase tracking-wide mb-1">Late cancellation consequence</p>
                @if ($reservation->cancellation_consequence === 'fee')
                    <p class="text-sm text-red-600 font-medium">${{ number_format($reservation->cancellation_consequence_amount, 2) }} fee applied</p>
                @else
                    <p class="text-sm text-red-600 font-medium">{{ (int) $reservation->cancellation_consequence_amount }} points deducted</p>
                @endif
            </div>
        @endif
    </div>

    {{-- Check-in / Check-out panel --}}
    @if ($reservation->status === 'confirmed')
        <div class="bg-white rounded-xl border border-slate-200 p-5 mb-5">
            <h2 class="text-sm font-semibold text-slate-700 uppercase tracking-wide mb-3">Attendance</h2>

            @if ($isCheckinOpen)
                @if ($isCheckinLate)
                    <div class="mb-3 p-3 bg-sky-50 border border-sky-200 rounded-lg text-sm text-sky-700">
                        The session has already started. Checking in now will record
                        <strong>partial attendance</strong>.
                    </div>
                @else
                    <p class="text-sm text-slate-500 mb-3">
                        Check-in window is open.
                        Closes at <strong>{{ $checkinClosesAt }}</strong>.
                    </p>
                @endif

                @if ($checkinError)
                    <p class="mb-3 text-sm text-red-600">{{ $checkinError }}</p>
                @endif

                <button
                    wire:click="checkIn"
                    wire:loading.attr="disabled"
                    class="px-4 py-2 rounded-lg bg-blue-600 text-white text-sm font-medium hover:bg-blue-700 disabled:opacity-50 transition-colors">
                    <span wire:loading.remove wire:target="checkIn">
                        {{ $isCheckinLate ? 'Check in (late arrival)' : 'Check in' }}
                    </span>
                    <span wire:loading wire:target="checkIn">Checking in…</span>
                </button>
            @elseif ($checkinOpensAt && $reservation->timeSlot && $reservation->timeSlot->starts_at->isFuture())
                <p class="text-sm text-slate-500">
                    Check-in opens at <strong>{{ $checkinOpensAt }}</strong>.
                </p>
            @else
                <p class="text-sm text-slate-400 italic">
                    The check-in window has closed for this reservation.
                </p>
            @endif
        </div>
    @endif

    @if ($isCheckedIn)
        <div class="bg-white rounded-xl border border-blue-200 p-5 mb-5">
            <h2 class="text-sm font-semibold text-slate-700 uppercase tracking-wide mb-3">Attendance</h2>
            <p class="text-sm text-slate-600 mb-3">
                Checked in at <strong>{{ $reservation->checked_in_at?->format('g:i A') }}</strong>.
                @if ($reservation->status === 'partial_attendance')
                    <span class="ml-1 text-sky-600 font-medium">(Late arrival — partial attendance)</span>
                @endif
            </p>

            @if ($checkinError)
                <p class="mb-3 text-sm text-red-600">{{ $checkinError }}</p>
            @endif

            <button
                wire:click="checkOut"
                wire:loading.attr="disabled"
                class="px-4 py-2 rounded-lg bg-teal-600 text-white text-sm font-medium hover:bg-teal-700 disabled:opacity-50 transition-colors">
                <span wire:loading.remove wire:target="checkOut">Check out</span>
                <span wire:loading wire:target="checkOut">Checking out…</span>
            </button>
        </div>
    @endif

    @if ($reservation->status === 'checked_out')
        <div class="bg-teal-50 border border-teal-200 rounded-xl p-5 mb-5 flex items-start gap-3">
            <svg class="w-5 h-5 text-teal-600 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
            </svg>
            <div>
                <p class="text-sm font-semibold text-teal-800">Session complete</p>
                <p class="text-sm text-teal-700 mt-0.5">
                    Checked in {{ $reservation->checked_in_at?->format('g:i A') }},
                    checked out {{ $reservation->checked_out_at?->format('g:i A') }}.
                </p>
            </div>
        </div>
    @endif

    {{-- Actions --}}
    @if ($isCancelable)
        <div class="bg-white rounded-xl border border-slate-200 p-5 mb-5">
            <h2 class="text-sm font-semibold text-slate-700 uppercase tracking-wide mb-4">Actions</h2>

            <div class="flex flex-wrap gap-3">
                {{-- Reschedule toggle --}}
                <button
                    wire:click="toggleReschedule"
                    class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg border border-slate-300 text-slate-600 text-sm font-medium hover:border-indigo-300 hover:text-indigo-600 transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>
                    </svg>
                    Reschedule
                </button>

                {{-- Cancel --}}
                <button
                    wire:click="openCancelModal"
                    class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg border text-sm font-medium transition-colors
                        {{ $isLateCancellation
                            ? 'border-amber-300 text-amber-700 bg-amber-50 hover:bg-amber-100'
                            : 'border-red-200 text-red-600 bg-red-50 hover:bg-red-100' }}">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                    {{ $isLateCancellation ? 'Cancel (late)' : 'Cancel reservation' }}
                </button>
            </div>

            @if ($cancelError)
                <p class="mt-3 text-sm text-red-600">{{ $cancelError }}</p>
            @endif
        </div>
    @endif

    {{-- Reschedule panel --}}
    @if ($showReschedule)
        <div class="bg-white rounded-xl border border-indigo-200 p-5 mb-5">
            <h2 class="text-sm font-semibold text-slate-700 uppercase tracking-wide mb-4">Select a new slot</h2>

            @if ($availableSlots->isEmpty())
                <p class="text-sm text-slate-500">No other slots are currently available for this service.</p>
            @else
                <div class="space-y-2 mb-4">
                    @foreach ($availableSlots as $slot)
                        <label class="flex items-center gap-3 p-3 rounded-lg border cursor-pointer transition-colors
                            {{ $selectedSlotId === $slot->id
                                ? 'border-indigo-400 bg-indigo-50'
                                : 'border-slate-200 hover:border-indigo-200' }}">
                            <input type="radio"
                                   wire:model="selectedSlotId"
                                   value="{{ $slot->id }}"
                                   class="accent-indigo-600">
                            <span class="text-sm text-slate-700">
                                {{ $slot->starts_at->format('M j, Y g:i A') }}
                                –
                                {{ $slot->ends_at->format('g:i A') }}
                                <span class="text-slate-400">({{ $slot->remainingCapacity() }} left)</span>
                            </span>
                        </label>
                    @endforeach
                </div>

                @if ($rescheduleError)
                    <p class="mb-3 text-sm text-red-600">{{ $rescheduleError }}</p>
                @endif

                <div class="flex gap-3">
                    <button
                        wire:click="submitReschedule"
                        wire:loading.attr="disabled"
                        class="px-4 py-2 rounded-lg bg-indigo-600 text-white text-sm font-medium hover:bg-indigo-700 disabled:opacity-50 transition-colors">
                        <span wire:loading.remove wire:target="submitReschedule">Confirm reschedule</span>
                        <span wire:loading wire:target="submitReschedule">Rescheduling…</span>
                    </button>
                    <button
                        wire:click="toggleReschedule"
                        class="px-4 py-2 rounded-lg border border-slate-300 text-slate-600 text-sm font-medium hover:bg-slate-50 transition-colors">
                        Cancel
                    </button>
                </div>
            @endif
        </div>
    @endif

    {{-- Cancel confirmation modal --}}
    @if ($showCancelModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40">
            <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md p-6" @click.outside="$wire.showCancelModal = false">
                <h2 class="text-lg font-bold text-slate-900 mb-2">
                    @if ($isLateCancellation) Late cancellation @else Cancel reservation @endif
                </h2>

                @if ($isLateCancellation)
                    <div class="bg-amber-50 border border-amber-200 rounded-lg p-3 mb-4 text-sm text-amber-700">
                        <strong>Warning:</strong>
                        @if ($consequence['type'] === 'fee')
                            A <strong>${{ number_format($consequence['amount'], 2) }} fee</strong> will be recorded on your account.
                        @else
                            <strong>{{ (int) $consequence['amount'] }} points</strong> will be deducted from your balance.
                        @endif
                    </div>
                @else
                    <p class="text-sm text-slate-500 mb-4">
                        Are you sure you want to cancel your booking for
                        <strong>{{ $reservation->service?->title }}</strong>?
                        This action cannot be undone.
                    </p>
                @endif

                <div class="flex justify-end gap-3">
                    <button
                        wire:click="$set('showCancelModal', false)"
                        class="px-4 py-2 rounded-lg border border-slate-300 text-slate-600 text-sm font-medium hover:bg-slate-50 transition-colors">
                        Keep reservation
                    </button>
                    <button
                        wire:click="confirmCancel"
                        wire:loading.attr="disabled"
                        class="px-4 py-2 rounded-lg bg-red-600 text-white text-sm font-medium hover:bg-red-700 disabled:opacity-50 transition-colors">
                        <span wire:loading.remove wire:target="confirmCancel">
                            @if ($isLateCancellation) Accept consequence &amp; cancel @else Confirm cancellation @endif
                        </span>
                        <span wire:loading wire:target="confirmCancel">Cancelling…</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- Back link --}}
    <div class="mt-2">
        <a href="{{ route('reservations.index') }}"
           class="inline-flex items-center gap-1.5 text-sm text-slate-500 hover:text-indigo-600 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
            </svg>
            Back to reservations
        </a>
    </div>

</div>
