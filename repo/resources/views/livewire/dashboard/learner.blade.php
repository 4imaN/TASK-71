<div class="space-y-6">

    {{-- Page header --}}
    <div class="flex items-start justify-between">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">
                Welcome back, {{ auth()->user()->display_name }}
            </h1>
            <p class="text-sm text-slate-400 mt-0.5">Research Services Dashboard</p>
        </div>
        @if ($pointsBalance > 0)
            <div class="text-right">
                <p class="text-xs text-slate-400 uppercase tracking-wide">Points balance</p>
                <p class="text-2xl font-bold text-indigo-600">{{ number_format($pointsBalance) }}</p>
            </div>
        @endif
    </div>

    {{-- ── Main grid ──────────────────────────────────────────────────────── --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        {{-- Upcoming reservations — spans 2 columns --}}
        <div class="lg:col-span-2 bg-white rounded-xl border border-slate-200">
            <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-slate-700 uppercase tracking-wide">Upcoming Reservations</h2>
                <a href="{{ route('reservations.index') }}"
                   class="text-xs text-indigo-600 hover:text-indigo-800 transition-colors">View all →</a>
            </div>
            <div class="divide-y divide-slate-100">
                @forelse ($upcomingReservations as $reservation)
                    <div class="px-5 py-3 flex items-center justify-between gap-3">
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-slate-800 truncate">
                                @if ($reservation->service)
                                    <a href="{{ route('catalog.show', $reservation->service->slug) }}"
                                       class="hover:text-indigo-600 transition-colors hover:underline underline-offset-2">
                                        {{ $reservation->service->title }}
                                    </a>
                                @else
                                    —
                                @endif
                            </p>
                            <p class="text-xs text-slate-400 mt-0.5">
                                {{ $reservation->timeSlot?->starts_at?->format('M j, Y · g:i A') ?? 'Time TBD' }}
                            </p>
                        </div>
                        <span class="text-xs font-medium px-2.5 py-1 rounded-full flex-shrink-0
                            {{ $reservation->status === 'confirmed'
                                ? 'bg-emerald-50 text-emerald-700'
                                : 'bg-amber-50 text-amber-700' }}">
                            {{ ucfirst($reservation->status) }}
                        </span>
                    </div>
                @empty
                    <div class="px-5 py-10 text-center">
                        <p class="text-sm text-slate-400">No upcoming reservations.</p>
                        <a href="{{ route('catalog.index') }}"
                           class="mt-2 inline-block text-xs text-indigo-600 hover:text-indigo-800 underline underline-offset-2">
                            Browse the catalog →
                        </a>
                    </div>
                @endforelse
            </div>
        </div>

        {{-- Right column: Favorites + Recent views --}}
        <div class="space-y-5">

            {{-- Favorites --}}
            <div class="bg-white rounded-xl border border-slate-200">
                <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-slate-700 uppercase tracking-wide">Saved Services</h2>
                    <a href="{{ route('catalog.index') }}"
                       class="text-xs text-indigo-600 hover:text-indigo-800 transition-colors">Browse →</a>
                </div>
                <div class="divide-y divide-slate-100">
                    @forelse ($favorites as $fav)
                        @if ($fav->service)
                            <div class="px-5 py-2.5">
                                <a href="{{ route('catalog.show', $fav->service->slug) }}"
                                   class="text-sm text-slate-700 hover:text-indigo-600 transition-colors truncate block hover:underline underline-offset-2">
                                    {{ $fav->service->title }}
                                </a>
                                @if ($fav->service->category)
                                    <p class="text-xs text-slate-400 mt-0.5">{{ $fav->service->category->name }}</p>
                                @endif
                            </div>
                        @endif
                    @empty
                        <div class="px-5 py-8 text-center">
                            <p class="text-xs text-slate-400">No saved services yet.</p>
                            <p class="text-xs text-slate-400 mt-0.5">Use the ♡ button in the catalog.</p>
                        </div>
                    @endforelse
                </div>
            </div>

            {{-- Recently viewed --}}
            <div class="bg-white rounded-xl border border-slate-200">
                <div class="px-5 py-4 border-b border-slate-100">
                    <h2 class="text-sm font-semibold text-slate-700 uppercase tracking-wide">Recently Viewed</h2>
                </div>
                <div class="divide-y divide-slate-100">
                    @forelse ($recentViews as $view)
                        @if ($view->service)
                            <div class="px-5 py-2.5 flex items-center justify-between gap-2">
                                <a href="{{ route('catalog.show', $view->service->slug) }}"
                                   class="text-sm text-slate-700 hover:text-indigo-600 transition-colors truncate hover:underline underline-offset-2 flex-1">
                                    {{ $view->service->title }}
                                </a>
                                <span class="text-xs text-slate-400 flex-shrink-0">
                                    {{ $view->viewed_at->diffForHumans(short: true) }}
                                </span>
                            </div>
                        @endif
                    @empty
                        <div class="px-5 py-8 text-center">
                            <p class="text-xs text-slate-400">Nothing viewed yet.</p>
                            <p class="text-xs text-slate-400 mt-0.5">Open any service to track it here.</p>
                        </div>
                    @endforelse
                </div>
            </div>

        </div>
    </div>

</div>
