<div>
    {{-- Page header --}}
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-xl font-bold text-slate-900">Services</h1>
            <p class="text-sm text-slate-500 mt-0.5">Manage and publish your service catalog</p>
        </div>
        <a href="{{ route('editor.services.create') }}"
           class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-indigo-600 text-white text-sm font-medium hover:bg-indigo-700 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            New Service
        </a>
    </div>

    {{-- Flash message --}}
    @if ($flashMessage ?? '')
        <div class="mb-4 px-4 py-3 rounded-lg text-sm font-medium
            {{ ($flashType ?? '') === 'success' ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-red-50 text-red-700 border border-red-200' }}">
            {{ $flashMessage }}
        </div>
    @endif

    {{-- Search --}}
    <div class="mb-4">
        <input
            type="text"
            wire:model.live.debounce.400ms="search"
            placeholder="Search services…"
            class="w-full sm:w-80 px-3 py-2 rounded-lg border border-slate-300 text-sm text-slate-800 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400"
        />
    </div>

    {{-- Status filter tabs --}}
    <div class="flex flex-wrap gap-2 mb-5">
        @foreach (['' => 'All', 'draft' => 'Draft', 'active' => 'Active', 'inactive' => 'Inactive', 'archived' => 'Archived'] as $value => $label)
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

    {{-- Table --}}
    @if ($services->isEmpty())
        <div class="text-center py-20 bg-white rounded-xl border border-slate-200">
            <svg class="w-12 h-12 mx-auto text-slate-300 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                      d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
            </svg>
            <p class="text-slate-500 font-medium">No services found</p>
            @if ($search !== '' || $statusFilter !== '')
                <p class="text-sm text-slate-400 mt-1">Try adjusting your search or filter.</p>
            @else
                <p class="text-sm text-slate-400 mt-1">
                    <a href="{{ route('editor.services.create') }}" class="text-indigo-600 hover:underline">Create your first service</a>.
                </p>
            @endif
        </div>
    @else
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 bg-slate-50">
                        <th class="text-left px-4 py-3 font-semibold text-slate-600">Title</th>
                        <th class="text-left px-4 py-3 font-semibold text-slate-600">Status</th>
                        <th class="text-left px-4 py-3 font-semibold text-slate-600 hidden md:table-cell">Category</th>
                        <th class="text-left px-4 py-3 font-semibold text-slate-600 hidden sm:table-cell">Slots</th>
                        <th class="text-left px-4 py-3 font-semibold text-slate-600 hidden lg:table-cell">Updated</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($services as $service)
                        @php
                            $badgeClasses = match($service->status) {
                                'draft'    => 'bg-slate-100 text-slate-600',
                                'active'   => 'bg-emerald-100 text-emerald-700',
                                'inactive' => 'bg-yellow-100 text-yellow-700',
                                'archived' => 'bg-red-100 text-red-700',
                                default    => 'bg-slate-100 text-slate-600',
                            };
                        @endphp
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="px-4 py-3 font-medium text-slate-800">
                                <a href="{{ route('editor.services.edit', $service->id) }}"
                                   class="hover:text-indigo-600 transition-colors">
                                    {{ $service->title }}
                                </a>
                                <span class="block text-xs text-slate-400 font-normal">{{ $service->slug }}</span>
                            </td>
                            <td class="px-4 py-3">
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium {{ $badgeClasses }}">
                                    {{ ucfirst($service->status) }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-slate-500 hidden md:table-cell">
                                {{ $service->category?->name ?? '—' }}
                            </td>
                            <td class="px-4 py-3 text-slate-500 hidden sm:table-cell">
                                {{ $service->time_slots_count }}
                            </td>
                            <td class="px-4 py-3 text-slate-400 text-xs hidden lg:table-cell">
                                {{ $service->updated_at->diffForHumans() }}
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2 justify-end">
                                    <a href="{{ route('editor.services.edit', $service->id) }}"
                                       class="text-xs font-medium text-indigo-600 hover:text-indigo-800 transition-colors">
                                        Edit
                                    </a>
                                    <span class="text-slate-300">|</span>
                                    <a href="{{ route('editor.services.slots', $service->id) }}"
                                       class="text-xs font-medium text-slate-500 hover:text-slate-800 transition-colors">
                                        Slots
                                    </a>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Pagination --}}
        <div class="mt-5">
            {{ $services->links() }}
        </div>
    @endif
</div>
