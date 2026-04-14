<div x-data="{ stepUpOpen: @entangle('requiresStepUp') }">

    {{-- ── Step-up modal ──────────────────────────────────────────────────────── --}}
    <div
        x-show="stepUpOpen"
        x-cloak
        class="fixed inset-0 z-50 flex items-center justify-center"
        style="background: rgba(15,23,42,0.55); backdrop-filter: blur(2px);">
        <div
            @click.stop
            class="w-full max-w-sm mx-4 bg-white rounded-2xl shadow-2xl border border-slate-200 overflow-hidden"
            x-show="stepUpOpen"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 scale-95"
            x-transition:enter-end="opacity-100 scale-100"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-95">

            <div class="bg-slate-900 px-6 py-4 flex items-center gap-3">
                <svg class="w-5 h-5 text-amber-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                </svg>
                <div>
                    <p class="text-white text-sm font-semibold">Re-authenticate to continue</p>
                    <p class="text-slate-400 text-xs mt-0.5">Sensitive changes require identity verification</p>
                </div>
            </div>

            <div class="px-6 py-5 space-y-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1.5">Current Password</label>
                    <input
                        type="password"
                        wire:model="stepUpPassword"
                        wire:keydown.enter="confirmStepUp"
                        placeholder="Enter your password"
                        class="w-full px-3 py-2.5 border rounded-lg text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-amber-400 focus:border-amber-400 border-slate-300"
                        autocomplete="current-password"
                        autofocus />
                    @if ($stepUpError)
                        <p class="mt-1.5 text-xs text-red-600 flex items-center gap-1">
                            <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
                            {{ $stepUpError }}
                        </p>
                    @endif
                </div>

                <div class="flex gap-2 pt-1">
                    <button
                        wire:click="confirmStepUp"
                        wire:loading.attr="disabled"
                        class="flex-1 px-4 py-2.5 bg-slate-900 text-white text-sm font-medium rounded-lg hover:bg-slate-700 transition-colors disabled:opacity-60">
                        <span wire:loading.remove wire:target="confirmStepUp">Verify Identity</span>
                        <span wire:loading wire:target="confirmStepUp">Verifying…</span>
                    </button>
                    <button
                        wire:click="cancelStepUp"
                        class="px-4 py-2.5 bg-slate-100 text-slate-600 text-sm font-medium rounded-lg hover:bg-slate-200 transition-colors">
                        Cancel
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- ── Page header ──────────────────────────────────────────────────────────── --}}
    <div class="flex items-start justify-between mb-6">
        <div>
            <h1 class="text-xl font-bold text-slate-900 tracking-tight">Policy Configuration</h1>
            <p class="text-sm text-slate-500 mt-0.5">Manage platform-wide operational settings</p>
        </div>
        @if ($stepGranted)
            <span class="inline-flex items-center gap-1.5 text-xs font-medium text-emerald-700 bg-emerald-50 border border-emerald-200 px-2.5 py-1 rounded-full">
                <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                Identity verified
            </span>
        @else
            <span class="inline-flex items-center gap-1.5 text-xs font-medium text-slate-500 bg-slate-100 border border-slate-200 px-2.5 py-1 rounded-full">
                <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                Step-up required to save
            </span>
        @endif
    </div>

    {{-- ── Flash message ────────────────────────────────────────────────────────── --}}
    @if ($flashMessage)
        <div class="mb-5 px-4 py-3 rounded-lg text-sm font-medium flex items-center gap-2
            {{ $flashType === 'success' ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-red-50 text-red-700 border border-red-200' }}">
            @if ($flashType === 'success')
                <svg class="w-4 h-4 shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
            @else
                <svg class="w-4 h-4 shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
            @endif
            {{ $flashMessage }}
        </div>
    @endif

    {{-- ── Group tabs ──────────────────────────────────────────────────────────── --}}
    @php
        $tabLabels = [
            'reservation'  => 'Reservation',
            'auth'         => 'Authentication & Security',
            'import'       => 'Import',
            'login_anomaly'=> 'Login Anomaly',
        ];
    @endphp

    <div class="flex flex-wrap gap-1 mb-4 bg-slate-100 p-1 rounded-xl w-fit">
        @foreach ($tabLabels as $tab => $label)
            <button
                wire:click="setGroup('{{ $tab }}')"
                class="px-4 py-1.5 rounded-lg text-sm font-medium transition-all
                    {{ $group === $tab
                        ? 'bg-white text-slate-900 shadow-sm'
                        : 'text-slate-500 hover:text-slate-700' }}">
                {{ $label }}
            </button>
        @endforeach
    </div>

    {{-- ── Config group panel ───────────────────────────────────────────────────── --}}
    @foreach ($grouped as $groupKey => $items)
        @if ($group === $groupKey)
            <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">

                <div class="bg-slate-900 px-5 py-3.5 flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-white">{{ $tabLabels[$groupKey] ?? $groupKey }}</h2>
                    <span class="text-xs text-slate-400">{{ count($items) }} settings</span>
                </div>

                <div class="divide-y divide-slate-100">
                    @foreach ($items as $item)
                        <div class="px-5 py-4 grid grid-cols-1 md:grid-cols-5 gap-2 items-center">
                            <div class="md:col-span-3">
                                <p class="font-mono text-xs font-semibold text-slate-800 leading-tight">{{ $item['key'] }}</p>
                                @if ($item['description'])
                                    <p class="text-xs text-slate-500 mt-0.5">{{ $item['description'] }}</p>
                                @endif
                                @if ($item['is_sensitive'])
                                    <span class="inline-block mt-1 text-xs text-amber-600 bg-amber-50 border border-amber-200 px-1.5 py-0.5 rounded">sensitive</span>
                                @endif
                            </div>

                            <div class="md:col-span-2">
                                @if ($item['key'] === 'late_cancel_consequence_type')
                                    <select
                                        wire:model="values.{{ $item['key'] }}"
                                        class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm text-slate-800 bg-white focus:outline-none focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400
                                            {{ $errors->has('values.' . $item['key']) ? 'border-red-400' : '' }}">
                                        <option value="fee">Fee</option>
                                        <option value="points">Points</option>
                                        <option value="none">None</option>
                                    </select>
                                @elseif (in_array($item['type'], ['integer', 'decimal']))
                                    <input
                                        type="number"
                                        step="{{ $item['type'] === 'decimal' ? '0.01' : '1' }}"
                                        wire:model="values.{{ $item['key'] }}"
                                        class="w-full px-3 py-2 border rounded-lg text-sm text-slate-800 font-mono focus:outline-none focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400
                                            {{ $errors->has('values.' . $item['key']) ? 'border-red-400' : 'border-slate-300' }}" />
                                @else
                                    <input
                                        type="text"
                                        wire:model="values.{{ $item['key'] }}"
                                        class="w-full px-3 py-2 border rounded-lg text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400
                                            {{ $errors->has('values.' . $item['key']) ? 'border-red-400' : 'border-slate-300' }}" />
                                @endif
                                @error('values.' . $item['key'])
                                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="px-5 py-4 bg-slate-50 border-t border-slate-100 flex items-center justify-between">
                    <p class="text-xs text-slate-400">
                        @if (!$stepGranted)
                            <svg class="inline w-3.5 h-3.5 mr-1 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                            You will be asked to verify your identity before saving.
                        @else
                            Identity verified — changes will be saved immediately.
                        @endif
                    </p>
                    <button
                        wire:click="save"
                        wire:loading.attr="disabled"
                        class="px-5 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 transition-colors disabled:opacity-60 flex items-center gap-2">
                        <span wire:loading.remove wire:target="save">Save Changes</span>
                        <span wire:loading wire:target="save">Saving…</span>
                    </button>
                </div>
            </div>
        @endif
    @endforeach

</div>
