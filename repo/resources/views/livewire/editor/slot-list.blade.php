<div>
    {{-- Back link + page header --}}
    <div class="flex items-center justify-between mb-6">
        <div>
            <a href="{{ route('editor.services.edit', $service->id) }}"
               class="text-sm text-slate-500 hover:text-indigo-600 transition-colors mb-1 inline-block">
                ← Back to service
            </a>
            <h1 class="text-xl font-bold text-slate-900">Slots — {{ $service->title }}</h1>
        </div>
        <button
            wire:click="$toggle('showAddForm')"
            class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-indigo-600 text-white text-sm font-medium hover:bg-indigo-700 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            Add Slot
        </button>
    </div>

    {{-- Flash message --}}
    @if ($flashMessage)
        <div class="mb-4 px-4 py-3 rounded-lg text-sm font-medium
            {{ $flashType === 'success' ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-red-50 text-red-700 border border-red-200' }}">
            {{ $flashMessage }}
        </div>
    @endif

    {{-- Add slot form --}}
    @if ($showAddForm)
        <div class="mb-5 bg-white rounded-xl border border-indigo-200 p-5">
            <h2 class="text-sm font-semibold text-slate-700 mb-4">Add New Slot</h2>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Starts At</label>
                    <input
                        type="datetime-local"
                        wire:model="newStartsAt"
                        class="w-full px-3 py-2 border rounded-lg text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-300
                            {{ $errors->has('newStartsAt') ? 'border-red-400' : 'border-slate-300' }}"
                    />
                    @error('newStartsAt') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Ends At</label>
                    <input
                        type="datetime-local"
                        wire:model="newEndsAt"
                        class="w-full px-3 py-2 border rounded-lg text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-300
                            {{ $errors->has('newEndsAt') ? 'border-red-400' : 'border-slate-300' }}"
                    />
                    @error('newEndsAt') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Capacity</label>
                    <input
                        type="number"
                        min="1"
                        wire:model="newCapacity"
                        class="w-full px-3 py-2 border rounded-lg text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-300
                            {{ $errors->has('newCapacity') ? 'border-red-400' : 'border-slate-300' }}"
                    />
                    @error('newCapacity') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="flex items-center gap-3 mt-4">
                <button
                    wire:click="addSlot"
                    class="px-4 py-2 rounded-lg bg-indigo-600 text-white text-sm font-medium hover:bg-indigo-700 transition-colors">
                    Add
                </button>
                <button
                    wire:click="$set('showAddForm', false)"
                    class="px-4 py-2 rounded-lg bg-slate-100 text-slate-700 text-sm font-medium hover:bg-slate-200 transition-colors">
                    Cancel
                </button>
            </div>
        </div>
    @endif

    {{-- Slots table --}}
    @if ($slots->isEmpty())
        <div class="text-center py-16 bg-white rounded-xl border border-slate-200">
            <svg class="w-10 h-10 mx-auto text-slate-300 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                      d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
            </svg>
            <p class="text-slate-500 font-medium">No slots yet</p>
            <p class="text-sm text-slate-400 mt-1">Add the first slot to start accepting reservations.</p>
        </div>
    @else
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 bg-slate-50">
                        <th class="text-left px-4 py-3 font-semibold text-slate-600">Starts</th>
                        <th class="text-left px-4 py-3 font-semibold text-slate-600">Ends</th>
                        <th class="text-left px-4 py-3 font-semibold text-slate-600">Capacity</th>
                        <th class="text-left px-4 py-3 font-semibold text-slate-600">Booked</th>
                        <th class="text-left px-4 py-3 font-semibold text-slate-600">Status</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($slots as $slot)
                        @php
                            $isCancelled = $slot->status === 'cancelled';
                            $booked = ($slot->pending_count ?? 0) + ($slot->confirmed_count ?? 0);
                            $statusBadge = match($slot->status) {
                                'available'  => 'bg-emerald-100 text-emerald-700',
                                'full'       => 'bg-amber-100 text-amber-700',
                                'cancelled'  => 'bg-red-100 text-red-600',
                                'past'       => 'bg-slate-100 text-slate-500',
                                default      => 'bg-slate-100 text-slate-500',
                            };
                        @endphp
                        <tr class="{{ $isCancelled ? 'opacity-50' : '' }} hover:bg-slate-50 transition-colors">
                            @if ($editingSlotId === $slot->id)
                                {{-- Inline edit form --}}
                                <td class="px-4 py-3" colspan="5">
                                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                        <div>
                                            <label class="block text-xs font-medium text-slate-500 mb-1">Starts At</label>
                                            <input type="datetime-local" wire:model="editStartsAt"
                                                class="w-full px-2 py-1.5 border border-slate-300 rounded text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300" />
                                            @error('editStartsAt') <p class="mt-0.5 text-xs text-red-600">{{ $message }}</p> @enderror
                                        </div>
                                        <div>
                                            <label class="block text-xs font-medium text-slate-500 mb-1">Ends At</label>
                                            <input type="datetime-local" wire:model="editEndsAt"
                                                class="w-full px-2 py-1.5 border border-slate-300 rounded text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300" />
                                            @error('editEndsAt') <p class="mt-0.5 text-xs text-red-600">{{ $message }}</p> @enderror
                                        </div>
                                        <div>
                                            <label class="block text-xs font-medium text-slate-500 mb-1">Capacity</label>
                                            <input type="number" min="1" wire:model="editCapacity"
                                                class="w-full px-2 py-1.5 border border-slate-300 rounded text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300" />
                                            @error('editCapacity') <p class="mt-0.5 text-xs text-red-600">{{ $message }}</p> @enderror
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-2">
                                        <button wire:click="saveSlot"
                                            class="text-xs font-medium text-indigo-600 hover:text-indigo-800 transition-colors">Save</button>
                                        <span class="text-slate-300">|</span>
                                        <button wire:click="cancelEdit"
                                            class="text-xs font-medium text-slate-500 hover:text-slate-800 transition-colors">Discard</button>
                                    </div>
                                </td>
                            @else
                                <td class="px-4 py-3 text-slate-700">
                                    {{ $slot->starts_at?->format('M j, Y g:i A') }}
                                </td>
                                <td class="px-4 py-3 text-slate-500">
                                    {{ $slot->ends_at?->format('g:i A') }}
                                </td>
                                <td class="px-4 py-3 text-slate-700 text-center">
                                    {{ $slot->capacity }}
                                </td>
                                <td class="px-4 py-3 text-slate-500 text-center">
                                    {{ $booked }}
                                    @if ($slot->pending_count > 0)
                                        <span class="text-xs text-yellow-600">({{ $slot->pending_count }} pending)</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium {{ $statusBadge }}">
                                        {{ ucfirst($slot->status) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-2 justify-end">
                                        @if (!$isCancelled)
                                            <button wire:click="editSlot({{ $slot->id }})"
                                                class="text-xs font-medium text-indigo-600 hover:text-indigo-800 transition-colors">
                                                Edit
                                            </button>
                                            <span class="text-slate-300">|</span>
                                            <button
                                                wire:click="cancelSlot({{ $slot->id }})"
                                                wire:confirm="Cancel this slot? This cannot be undone."
                                                class="text-xs font-medium text-red-500 hover:text-red-700 transition-colors">
                                                Cancel Slot
                                            </button>
                                        @endif
                                    </div>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
