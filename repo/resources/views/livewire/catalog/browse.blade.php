<div class="flex gap-6">

    {{-- ── Sidebar filters ──────────────────────────────────────────────────── --}}
    <aside class="hidden lg:block w-56 flex-shrink-0 space-y-6">

        {{-- Category --}}
        <div>
            <h3 class="text-xs font-semibold uppercase tracking-wider text-slate-400 mb-2">Category</h3>
            <ul class="space-y-0.5">
                <li>
                    <button
                        wire:click="$set('categoryId', '')"
                        class="w-full text-left text-sm px-2 py-1.5 rounded transition-colors
                            {{ $categoryId === '' ? 'bg-indigo-50 text-indigo-700 font-medium' : 'text-slate-600 hover:bg-slate-100' }}">
                        All categories
                    </button>
                </li>
                @foreach ($categories as $cat)
                    <li>
                        <button
                            wire:click="$set('categoryId', '{{ $cat->id }}')"
                            class="w-full text-left text-sm px-2 py-1.5 rounded transition-colors
                                {{ $categoryId == $cat->id ? 'bg-indigo-50 text-indigo-700 font-medium' : 'text-slate-600 hover:bg-slate-100' }}">
                            {{ $cat->name }}
                        </button>
                    </li>
                @endforeach
            </ul>
        </div>

        {{-- Price --}}
        <div>
            <h3 class="text-xs font-semibold uppercase tracking-wider text-slate-400 mb-2">Price</h3>
            <div class="space-y-1.5">
                @foreach (['all' => 'All services', 'free' => 'Free only', 'paid' => 'Paid only'] as $val => $label)
                    <label class="flex items-center gap-2 text-sm cursor-pointer">
                        <input
                            type="radio"
                            wire:model.live="priceType"
                            value="{{ $val }}"
                            class="text-indigo-600 focus:ring-indigo-500">
                        <span class="{{ $priceType === $val ? 'text-indigo-700 font-medium' : 'text-slate-600' }}">
                            {{ $label }}
                        </span>
                    </label>
                @endforeach
            </div>
        </div>

        {{-- Audience --}}
        @if ($audiences->isNotEmpty())
            <div>
                <h3 class="text-xs font-semibold uppercase tracking-wider text-slate-400 mb-2">Audience</h3>
                <ul class="space-y-0.5">
                    <li>
                        <button
                            wire:click="$set('audienceId', '')"
                            class="w-full text-left text-sm px-2 py-1.5 rounded transition-colors
                                {{ $audienceId === '' ? 'bg-indigo-50 text-indigo-700 font-medium' : 'text-slate-600 hover:bg-slate-100' }}">
                            All audiences
                        </button>
                    </li>
                    @foreach ($audiences as $aud)
                        <li>
                            <button
                                wire:click="$set('audienceId', '{{ $aud->id }}')"
                                class="w-full text-left text-sm px-2 py-1.5 rounded transition-colors
                                    {{ $audienceId == $aud->id ? 'bg-indigo-50 text-indigo-700 font-medium' : 'text-slate-600 hover:bg-slate-100' }}">
                                {{ $aud->label }}
                            </button>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- Tags --}}
        @if ($tags->isNotEmpty())
            <div>
                <h3 class="text-xs font-semibold uppercase tracking-wider text-slate-400 mb-2">Tags</h3>
                <div class="flex flex-wrap gap-1.5">
                    @foreach ($tags as $tag)
                        <button
                            wire:click="@if(in_array($tag->id, $tagIds)) $set('tagIds', array_values(array_filter($tagIds, fn($t) => $t != {{ $tag->id }}))) @else $set('tagIds', array_merge($tagIds, [{{ $tag->id }}])) @endif"
                            class="text-xs px-2 py-1 rounded-full border transition-colors
                                {{ in_array($tag->id, $tagIds)
                                    ? 'bg-indigo-600 text-white border-indigo-600'
                                    : 'bg-white text-slate-500 border-slate-200 hover:border-indigo-300 hover:text-indigo-600' }}">
                            {{ $tag->name }}
                        </button>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Sort --}}
        <div>
            <h3 class="text-xs font-semibold uppercase tracking-wider text-slate-400 mb-2">Sort by</h3>
            <select
                wire:model.live="sortBy"
                class="w-full text-sm border border-slate-200 rounded px-2 py-1.5 text-slate-700 bg-white focus:outline-none focus:ring-2 focus:ring-indigo-400">
                <option value="name">Name (A–Z)</option>
                <option value="earliest_availability">Earliest availability</option>
                <option value="lowest_fee">Lowest fee</option>
            </select>
        </div>

        {{-- Clear filters --}}
        @if ($hasActiveFilters)
            <button
                wire:click="resetFilters"
                class="w-full text-xs text-center py-1.5 border border-slate-200 rounded text-slate-500 hover:bg-slate-50 hover:text-slate-700 transition-colors">
                ↺ Clear all filters
            </button>
        @endif

    </aside>

    {{-- ── Main content ─────────────────────────────────────────────────────── --}}
    <div class="flex-1 min-w-0">

        {{-- Header + search --}}
        <div class="mb-5">
            <div class="flex items-center justify-between mb-3">
                <div>
                    <h1 class="text-xl font-semibold text-slate-800">Research Services</h1>
                    <p class="text-xs text-slate-400 mt-0.5">
                        {{ $services->total() }} {{ $services->total() === 1 ? 'service' : 'services' }} available
                    </p>
                </div>
                {{-- Mobile-only sort selector (sidebar hidden on small screens) --}}
                <div class="lg:hidden">
                    <select
                        wire:model.live="sortBy"
                        class="text-sm border border-slate-200 rounded px-2 py-1.5 text-slate-700 bg-white focus:outline-none">
                        <option value="name">Name</option>
                        <option value="earliest_availability">Earliest first</option>
                        <option value="lowest_fee">Lowest fee</option>
                    </select>
                </div>
            </div>

            {{-- Search bar --}}
            <div class="relative">
                <span class="absolute inset-y-0 left-3 flex items-center pointer-events-none text-slate-400">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                </span>
                <input
                    type="search"
                    wire:model.live.debounce.350ms="search"
                    placeholder="Search services by name or description…"
                    class="w-full pl-9 pr-4 py-2 text-sm border border-slate-200 rounded-lg bg-white
                           focus:outline-none focus:ring-2 focus:ring-indigo-400 focus:border-transparent
                           placeholder-slate-400">
            </div>
        </div>

        {{-- Active filter chips --}}
        @if ($hasActiveFilters)
            <div class="flex flex-wrap gap-2 mb-4">
                @if ($search !== '')
                    <span class="inline-flex items-center gap-1 text-xs bg-indigo-50 text-indigo-700 border border-indigo-200 rounded-full px-3 py-1">
                        "{{ $search }}"
                        <button wire:click="$set('search', '')" class="ml-0.5 text-indigo-400 hover:text-indigo-700 font-bold leading-none">×</button>
                    </span>
                @endif
                @if ($categoryId !== '')
                    @php $activeCat = $categories->firstWhere('id', $categoryId); @endphp
                    @if ($activeCat)
                        <span class="inline-flex items-center gap-1 text-xs bg-indigo-50 text-indigo-700 border border-indigo-200 rounded-full px-3 py-1">
                            {{ $activeCat->name }}
                            <button wire:click="$set('categoryId', '')" class="ml-0.5 text-indigo-400 hover:text-indigo-700 font-bold leading-none">×</button>
                        </span>
                    @endif
                @endif
                @if ($audienceId !== '')
                    @php $activeAud = $audiences->firstWhere('id', $audienceId); @endphp
                    @if ($activeAud)
                        <span class="inline-flex items-center gap-1 text-xs bg-indigo-50 text-indigo-700 border border-indigo-200 rounded-full px-3 py-1">
                            {{ $activeAud->label }}
                            <button wire:click="$set('audienceId', '')" class="ml-0.5 text-indigo-400 hover:text-indigo-700 font-bold leading-none">×</button>
                        </span>
                    @endif
                @endif
                @foreach ($tagIds as $tid)
                    @php $activeTag = $tags->firstWhere('id', $tid); @endphp
                    @if ($activeTag)
                        <span class="inline-flex items-center gap-1 text-xs bg-indigo-50 text-indigo-700 border border-indigo-200 rounded-full px-3 py-1">
                            #{{ $activeTag->name }}
                            <button wire:click="$set('tagIds', array_values(array_filter($tagIds, fn($t) => $t != {{ $tid }})))" class="ml-0.5 text-indigo-400 hover:text-indigo-700 font-bold leading-none">×</button>
                        </span>
                    @endif
                @endforeach
                @if ($priceType !== 'all')
                    <span class="inline-flex items-center gap-1 text-xs bg-indigo-50 text-indigo-700 border border-indigo-200 rounded-full px-3 py-1">
                        {{ $priceType === 'free' ? 'Free only' : 'Paid only' }}
                        <button wire:click="$set('priceType', 'all')" class="ml-0.5 text-indigo-400 hover:text-indigo-700 font-bold leading-none">×</button>
                    </span>
                @endif
                <button wire:click="resetFilters" class="text-xs text-slate-400 hover:text-slate-600 px-1 underline underline-offset-2">
                    Clear all
                </button>
            </div>
        @endif

        {{-- Updating spinner --}}
        <div wire:loading.delay class="flex items-center gap-1.5 text-xs text-slate-400 mb-3">
            <svg class="animate-spin w-3 h-3" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
            </svg>
            Updating results…
        </div>

        {{-- Results --}}
        @if ($services->isEmpty())
            <div class="py-16 text-center">
                <div class="text-slate-300 mb-3">
                    <svg class="w-12 h-12 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                              d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
                <p class="text-slate-500 text-sm font-medium">No services match your criteria</p>
                <p class="text-slate-400 text-xs mt-1">Try broadening your search or clearing some filters.</p>
                @if ($hasActiveFilters)
                    <button wire:click="resetFilters"
                            class="mt-4 text-sm text-indigo-600 hover:text-indigo-800 underline underline-offset-2">
                        Clear all filters
                    </button>
                @endif
            </div>
        @else
            <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4 mb-6" wire:loading.class="opacity-50">
                @foreach ($services as $service)
                    <article class="group bg-white rounded-lg border border-slate-200 flex flex-col hover:border-indigo-200 hover:shadow-sm transition-all">

                        <div class="p-4 flex-1">
                            {{-- Category label + favorite toggle --}}
                            <div class="flex items-start justify-between gap-2 mb-2">
                                <div class="flex-1 min-w-0">
                                    @if ($service->category)
                                        <p class="text-xs text-slate-400 mb-1 truncate">{{ $service->category->name }}</p>
                                    @endif
                                    <h2 class="text-sm font-semibold text-slate-800 leading-snug group-hover:text-indigo-700 transition-colors">
                                        <a href="{{ route('catalog.show', $service->slug) }}"
                                           class="line-clamp-2 hover:underline underline-offset-2">
                                            {{ $service->title }}
                                        </a>
                                    </h2>
                                </div>

                                <button
                                    wire:click="toggleFavorite({{ $service->id }})"
                                    wire:key="fav-{{ $service->id }}"
                                    title="{{ $favoriteIds->contains($service->id) ? 'Remove from favorites' : 'Save to favorites' }}"
                                    class="flex-shrink-0 p-1 rounded-full transition-colors mt-0.5
                                        {{ $favoriteIds->contains($service->id)
                                            ? 'text-rose-500 hover:text-rose-700'
                                            : 'text-slate-300 hover:text-rose-400' }}">
                                    <svg class="w-4 h-4"
                                         fill="{{ $favoriteIds->contains($service->id) ? 'currentColor' : 'none' }}"
                                         stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                              d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/>
                                    </svg>
                                </button>
                            </div>

                            {{-- Description excerpt --}}
                            @if ($service->description)
                                <p class="text-xs text-slate-500 line-clamp-2 leading-relaxed mb-3">
                                    {{ $service->description }}
                                </p>
                            @endif

                            {{-- Tag chips --}}
                            @if ($service->tags->isNotEmpty())
                                <div class="flex flex-wrap gap-1 mb-2">
                                    @foreach ($service->tags->take(3) as $tag)
                                        <span class="text-xs px-1.5 py-0.5 bg-slate-100 text-slate-500 rounded font-mono">
                                            {{ $tag->name }}
                                        </span>
                                    @endforeach
                                    @if ($service->tags->count() > 3)
                                        <span class="text-xs text-slate-400 self-center">+{{ $service->tags->count() - 3 }}</span>
                                    @endif
                                </div>
                            @endif

                            {{-- Audience badges --}}
                            @if ($service->audiences->isNotEmpty())
                                <div class="flex flex-wrap gap-1">
                                    @foreach ($service->audiences->take(2) as $aud)
                                        <span class="text-xs px-1.5 py-0.5 bg-blue-50 text-blue-600 rounded">
                                            {{ $aud->label }}
                                        </span>
                                    @endforeach
                                    @if ($service->audiences->count() > 2)
                                        <span class="text-xs text-slate-400 self-center">+{{ $service->audiences->count() - 2 }}</span>
                                    @endif
                                </div>
                            @endif
                        </div>

                        {{-- Card footer --}}
                        <div class="px-4 py-3 border-t border-slate-100 flex items-center justify-between rounded-b-lg bg-slate-50/70">
                            <span class="text-xs font-semibold
                                {{ $service->is_free ? 'text-emerald-600' : 'text-slate-700' }}">
                                @if ($service->is_free)
                                    Free
                                @else
                                    ${{ number_format($service->fee_amount, 2) }}
                                @endif
                            </span>
                            <a href="{{ route('catalog.show', $service->slug) }}"
                               class="text-xs font-medium text-indigo-600 hover:text-indigo-800 transition-colors">
                                View details →
                            </a>
                        </div>
                    </article>
                @endforeach
            </div>

            {{-- Pagination --}}
            @if ($services->hasPages())
                <div class="flex justify-center pt-2">
                    {{ $services->links() }}
                </div>
            @endif
        @endif

    </div>
</div>
