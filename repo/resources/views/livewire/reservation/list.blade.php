<div>

    {{-- Page header --}}
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-xl font-bold text-slate-900">My Reservations</h1>
            <p class="text-sm text-slate-500 mt-0.5">Track and manage your bookings</p>
        </div>
        <a href="{{ route('catalog.index') }}"
           class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-indigo-600 text-white text-sm font-medium hover:bg-indigo-700 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            Browse services
        </a>
    </div>

    {{-- Status filter tabs --}}
    <div class="flex flex-wrap gap-2 mb-5">
        @foreach (['' => 'All', 'pending' => 'Pending', 'confirmed' => 'Confirmed', 'cancelled' => 'Cancelled', 'rescheduled' => 'Rescheduled', 'expired' => 'Expired'] as $value => $label)
            <button
                wire:click="$set('statusFilter', '{{ $value }}')"
                class="px-3 py-1.5 rounded-full text-sm font-medium transition-colors
                    {{ $statusFilter === $value
                        ? 'bg-indigo-600 text-white'
                        : 'bg-slate-100 text-slate-600 hover:bg-slate-200' }}">
                {{ $label }}
            </button>
        @endforeach
    </div>

    {{-- Reservations list --}}
    @if ($reservations->isEmpty())
        <div class="text-center py-20 bg-white rounded-xl border border-slate-200">
            <svg class="w-12 h-12 mx-auto text-slate-300 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                      d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
            </svg>
            <p class="text-slate-500 font-medium">No reservations found</p>
            <p class="text-sm text-slate-400 mt-1">
                @if ($statusFilter !== '')
                    Try selecting a different status filter.
                @else
                    <a href="{{ route('catalog.index') }}" class="text-indigo-600 hover:underline">Browse services</a> to make your first booking.
                @endif
            </p>
        </div>
    @else
        <div class="space-y-3">
            @foreach ($reservations as $reservation)
                @php
                    $statusConfig = [
                        'pending'     => ['bg-yellow-50 text-yellow-700 border-yellow-200',  'Pending confirmation'],
                        'confirmed'   => ['bg-emerald-50 text-emerald-700 border-emerald-200', 'Confirmed'],
                        'cancelled'   => ['bg-red-50 text-red-700 border-red-200',            'Cancelled'],
                        'rescheduled' => ['bg-slate-50 text-slate-600 border-slate-200',      'Rescheduled'],
                        'expired'     => ['bg-slate-50 text-slate-500 border-slate-200',      'Expired'],
                        'no_show'     => ['bg-orange-50 text-orange-700 border-orange-200',   'No show'],
                    ][$reservation->status] ?? ['bg-slate-50 text-slate-500 border-slate-200', ucfirst($reservation->status)];
                @endphp

                <div class="bg-white rounded-xl border border-slate-200 p-5 hover:border-slate-300 transition-colors">
                    <div class="flex items-start justify-between gap-4 flex-wrap">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 mb-1.5">
                                <span class="inline-flex items-center text-xs font-medium border px-2 py-0.5 rounded-full {{ $statusConfig[0] }}">
                                    {{ $statusConfig[1] }}
                                </span>
                                @if ($reservation->service?->is_free)
                                    <span class="text-xs text-emerald-600 font-medium">Free</span>
                                @elseif ($reservation->service?->fee_amount)
                                    <span class="text-xs text-amber-600 font-medium">${{ number_format($reservation->service->fee_amount, 2) }}</span>
                                @endif
                            </div>

                            <h3 class="font-semibold text-slate-800 truncate">
                                @if ($reservation->service)
                                    <a href="{{ route('catalog.show', $reservation->service->slug) }}"
                                       class="hover:text-indigo-600 transition-colors">
                                        {{ $reservation->service->title }}
                                    </a>
                                @else
                                    <span class="text-slate-400 italic">Service removed</span>
                                @endif
                            </h3>

                            @if ($reservation->timeSlot)
                                <p class="text-sm text-slate-500 mt-1">
                                    <svg class="w-3.5 h-3.5 inline -mt-0.5 mr-0.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                              d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                    </svg>
                                    {{ $reservation->timeSlot->starts_at->format('M j, Y g:i A') }}
                                    –
                                    {{ $reservation->timeSlot->ends_at->format('g:i A') }}
                                </p>
                            @endif

                            <p class="text-xs text-slate-400 mt-1">
                                Booked {{ $reservation->requested_at?->diffForHumans() ?? 'recently' }}
                            </p>
                        </div>

                        <div class="flex items-center gap-2 flex-shrink-0">
                            <a href="{{ route('reservations.show', $reservation->uuid) }}"
                               class="px-3 py-1.5 text-sm font-medium text-indigo-600 border border-indigo-200 rounded-lg hover:bg-indigo-50 transition-colors">
                                View details
                            </a>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Pagination --}}
        <div class="mt-5">
            {{ $reservations->links() }}
        </div>
    @endif

</div>
