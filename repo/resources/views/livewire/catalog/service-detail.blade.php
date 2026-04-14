<div class="max-w-4xl" x-data>

    {{-- Breadcrumb --}}
    <nav class="flex items-center gap-1.5 text-xs text-slate-400 mb-6">
        <a href="{{ route('dashboard') }}" class="hover:text-slate-600 transition-colors">Home</a>
        <span>/</span>
        <a href="{{ route('catalog.index') }}" class="hover:text-slate-600 transition-colors">Catalog</a>
        <span>/</span>
        <span class="text-slate-600 font-medium truncate max-w-xs">{{ $service->title }}</span>
    </nav>

    {{-- Service header --}}
    <div class="bg-white rounded-xl border border-slate-200 p-6 mb-5">
        <div class="flex items-start justify-between gap-4 flex-wrap">
            <div class="flex-1 min-w-0">

                {{-- Category + service type badges --}}
                <div class="flex flex-wrap items-center gap-2 mb-3">
                    @if ($service->category)
                        <span class="inline-flex items-center text-xs font-medium bg-slate-100 text-slate-600 px-2.5 py-1 rounded-full">
                            {{ $service->category->name }}
                        </span>
                    @endif
                    <span class="inline-flex items-center text-xs font-semibold px-2.5 py-1 rounded-full
                        {{ $service->is_free ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700' }}">
                        {{ $service->is_free ? 'Free' : '$' . number_format($service->fee_amount, 2) . ' ' . $service->fee_currency }}
                    </span>
                    @if ($service->requires_manual_confirmation)
                        <span class="inline-flex items-center text-xs bg-yellow-50 text-yellow-700 px-2.5 py-1 rounded-full border border-yellow-200">
                            Requires confirmation
                        </span>
                    @endif
                </div>

                <h1 class="text-2xl font-bold text-slate-900 leading-tight mb-1">
                    {{ $service->title }}
                </h1>
            </div>

            {{-- Favorite button --}}
            <button
                wire:click="toggleFavorite"
                class="flex items-center gap-2 px-4 py-2 rounded-lg border text-sm font-medium transition-all flex-shrink-0
                    {{ $isFavorited
                        ? 'bg-rose-50 border-rose-200 text-rose-600 hover:bg-rose-100'
                        : 'bg-white border-slate-200 text-slate-500 hover:border-rose-300 hover:text-rose-500' }}">
                <svg class="w-4 h-4"
                     fill="{{ $isFavorited ? 'currentColor' : 'none' }}"
                     stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/>
                </svg>
                {{ $isFavorited ? 'Saved' : 'Save' }}
            </button>
        </div>

        {{-- Tags --}}
        @if ($service->tags->isNotEmpty())
            <div class="flex flex-wrap gap-1.5 mt-4">
                @foreach ($service->tags as $tag)
                    <a href="{{ route('catalog.index', ['tags[]' => $tag->id]) }}"
                       class="text-xs font-mono px-2 py-0.5 bg-slate-100 text-slate-500 rounded hover:bg-indigo-50 hover:text-indigo-600 transition-colors">
                        {{ $tag->name }}
                    </a>
                @endforeach
            </div>
        @endif

        {{-- Audiences --}}
        @if ($service->audiences->isNotEmpty())
            <div class="flex flex-wrap gap-1.5 mt-2">
                @foreach ($service->audiences as $aud)
                    <span class="text-xs px-2 py-0.5 bg-blue-50 text-blue-600 rounded">
                        {{ $aud->label }}
                    </span>
                @endforeach
            </div>
        @endif
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">

        {{-- Left: Description + eligibility --}}
        <div class="lg:col-span-2 space-y-5">

            {{-- Description --}}
            @if ($service->description)
                <div class="bg-white rounded-xl border border-slate-200 p-6">
                    <h2 class="text-sm font-semibold text-slate-700 uppercase tracking-wide mb-3">About this service</h2>
                    <div class="text-sm text-slate-600 leading-relaxed prose prose-sm max-w-none">
                        {!! nl2br(e($service->description)) !!}
                    </div>
                </div>
            @endif

            {{-- Eligibility notes --}}
            @if ($service->eligibility_notes)
                <div class="bg-amber-50 rounded-xl border border-amber-200 p-6">
                    <div class="flex items-start gap-3">
                        <svg class="w-5 h-5 text-amber-600 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <div>
                            <h2 class="text-sm font-semibold text-amber-800 mb-1">Eligibility</h2>
                            <p class="text-sm text-amber-700 leading-relaxed">{{ $service->eligibility_notes }}</p>
                        </div>
                    </div>
                </div>
            @endif

        </div>

        {{-- Right: Upcoming time slots --}}
        <div class="space-y-4">
            <div class="bg-white rounded-xl border border-slate-200 p-5">
                <h2 class="text-sm font-semibold text-slate-700 uppercase tracking-wide mb-4">Upcoming slots</h2>

                @if ($bookError)
                    <div class="mb-3 p-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-700">
                        {{ $bookError }}
                    </div>
                @endif

                @if ($timeSlots->isEmpty())
                    <div class="text-center py-6">
                        <svg class="w-8 h-8 mx-auto text-slate-300 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                  d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                        </svg>
                        <p class="text-xs text-slate-400">No upcoming slots available</p>
                    </div>
                @else
                    <ul class="space-y-2.5">
                        @foreach ($timeSlots as $slot)
                            <li class="flex items-start justify-between gap-2 py-2.5 border-b border-slate-100 last:border-0">
                                <div>
                                    <p class="text-sm font-medium text-slate-700">
                                        {{ $slot->starts_at->format('M j, Y') }}
                                    </p>
                                    <p class="text-xs text-slate-400">
                                        {{ $slot->starts_at->format('g:i A') }}
                                        –
                                        {{ $slot->ends_at->format('g:i A') }}
                                    </p>
                                </div>
                                <div class="text-right flex-shrink-0 flex flex-col items-end gap-1">
                                    @if ($slot->hasCapacity())
                                        <span class="text-xs font-medium text-emerald-600">
                                            {{ $slot->remainingCapacity() }} left
                                        </span>
                                        <button
                                            wire:click="bookSlot({{ $slot->id }})"
                                            wire:loading.attr="disabled"
                                            wire:target="bookSlot({{ $slot->id }})"
                                            class="px-3 py-1 text-xs font-semibold rounded-lg bg-indigo-600 text-white hover:bg-indigo-700 disabled:opacity-50 transition-colors">
                                            <span wire:loading.remove wire:target="bookSlot({{ $slot->id }})">
                                                @if ($service->requires_manual_confirmation) Request @else Book @endif
                                            </span>
                                            <span wire:loading wire:target="bookSlot({{ $slot->id }})">…</span>
                                        </button>
                                    @else
                                        <span class="text-xs font-medium text-slate-400">Full</span>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>

    </div>

    {{-- Back link --}}
    <div class="mt-6">
        <a href="{{ route('catalog.index') }}"
           class="inline-flex items-center gap-1.5 text-sm text-slate-500 hover:text-indigo-600 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
            </svg>
            Back to catalog
        </a>
    </div>

</div>
