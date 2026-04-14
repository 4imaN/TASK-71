<div>
    {{-- Page header --}}
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-xl font-bold text-slate-900">
                @if ($serviceId)
                    Edit: {{ $title ?: 'Untitled Service' }}
                @else
                    Create Service
                @endif
            </h1>
            @if ($serviceId)
                <p class="text-sm text-slate-500 mt-0.5">
                    <a href="{{ route('editor.services.index') }}" class="hover:text-indigo-600 transition-colors">← Back to Services</a>
                </p>
            @endif
        </div>
        @if ($serviceId)
            <a href="{{ route('editor.services.slots', $serviceId) }}"
               class="text-sm font-medium text-indigo-600 hover:text-indigo-800 transition-colors">
                Manage Slots →
            </a>
        @endif
    </div>

    {{-- Flash message --}}
    @if ($flashMessage)
        <div class="mb-5 px-4 py-3 rounded-lg text-sm font-medium
            {{ $flashType === 'success' ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-red-50 text-red-700 border border-red-200' }}">
            {{ $flashMessage }}
        </div>
    @endif

    <div class="bg-white rounded-xl border border-slate-200 p-6 space-y-5">

        {{-- Title --}}
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Title <span class="text-red-500">*</span></label>
            <input
                type="text"
                wire:model="title"
                placeholder="Service title"
                class="w-full px-3 py-2 border rounded-lg text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400
                    {{ $errors->has('title') ? 'border-red-400' : 'border-slate-300' }}"
            />
            @error('title') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>

        {{-- Description --}}
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Description</label>
            <textarea
                wire:model="description"
                rows="4"
                placeholder="Describe the service…"
                class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 resize-y"
            ></textarea>
            @error('description') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>

        {{-- Eligibility Notes --}}
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Eligibility Notes</label>
            <textarea
                wire:model="eligibilityNotes"
                rows="2"
                placeholder="Who is eligible for this service?"
                class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 resize-y"
            ></textarea>
            @error('eligibilityNotes') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
            {{-- Category --}}
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Category</label>
                <select
                    wire:model="categoryId"
                    class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 bg-white">
                    <option value="">— No category —</option>
                    @foreach ($categories as $cat)
                        <option value="{{ $cat['id'] }}">{{ $cat['name'] }}</option>
                    @endforeach
                </select>
                @error('categoryId') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            {{-- Service Type --}}
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Service Type</label>
                <select
                    wire:model="serviceTypeId"
                    class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 bg-white">
                    <option value="">— Select type —</option>
                    @foreach ($serviceTypes as $type)
                        <option value="{{ $type['id'] }}">{{ $type['label'] }}</option>
                    @endforeach
                </select>
                @error('serviceTypeId') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
        </div>

        {{-- Is Free toggle --}}
        <div class="flex items-center gap-3">
            <label class="flex items-center gap-2 cursor-pointer">
                <input type="checkbox" wire:model="isFree" class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-300" />
                <span class="text-sm font-medium text-slate-700">Free service</span>
            </label>
        </div>

        {{-- Fee fields (when not free) --}}
        @if (!$isFree)
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5 pl-2 border-l-2 border-indigo-100">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Fee Amount</label>
                    <input
                        type="number"
                        step="0.01"
                        min="0"
                        wire:model="feeAmount"
                        placeholder="0.00"
                        class="w-full px-3 py-2 border rounded-lg text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400
                            {{ $errors->has('feeAmount') ? 'border-red-400' : 'border-slate-300' }}"
                    />
                    @error('feeAmount') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Currency</label>
                    <select
                        wire:model="feeCurrency"
                        class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 bg-white">
                        <option value="USD">USD</option>
                        <option value="EUR">EUR</option>
                        <option value="GBP">GBP</option>
                    </select>
                    @error('feeCurrency') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>
        @endif

        {{-- Manual confirmation toggle --}}
        <div class="flex items-center gap-3">
            <label class="flex items-center gap-2 cursor-pointer">
                <input type="checkbox" wire:model="requiresManualConfirmation" class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-300" />
                <span class="text-sm font-medium text-slate-700">Requires manual confirmation</span>
            </label>
        </div>

        {{-- Tags --}}
        @if (count($tags) > 0)
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-2">Tags</label>
                <div class="flex flex-wrap gap-2">
                    @foreach ($tags as $tag)
                        <label class="flex items-center gap-1.5 text-sm text-slate-600 cursor-pointer">
                            <input
                                type="checkbox"
                                wire:model="selectedTagIds"
                                value="{{ $tag['id'] }}"
                                class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-300"
                            />
                            {{ $tag['name'] }}
                        </label>
                    @endforeach
                </div>
                @error('selectedTagIds') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                @error('selectedTagIds.*') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
        @endif

        {{-- Audiences --}}
        @if (count($audiences) > 0)
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-2">Target Audiences</label>
                <div class="flex flex-wrap gap-2">
                    @foreach ($audiences as $audience)
                        <label class="flex items-center gap-1.5 text-sm text-slate-600 cursor-pointer">
                            <input
                                type="checkbox"
                                wire:model="selectedAudienceIds"
                                value="{{ $audience['id'] }}"
                                class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-300"
                            />
                            {{ $audience['label'] }}
                        </label>
                    @endforeach
                </div>
                @error('selectedAudienceIds') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                @error('selectedAudienceIds.*') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
        @endif

        {{-- Research Projects --}}
        @if (count($researchProjects) > 0)
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">
                    Linked Research Projects
                </label>
                <p class="text-xs text-slate-500 mb-2">
                    Associate this service with one or more research projects so learners
                    can see which projects benefit from it.
                </p>
                <div class="max-h-48 overflow-y-auto border border-slate-200 rounded-lg divide-y divide-slate-100">
                    @foreach ($researchProjects as $project)
                        <label class="flex items-start gap-2.5 px-3 py-2 hover:bg-slate-50 cursor-pointer">
                            <input
                                type="checkbox"
                                wire:model="selectedResearchProjectIds"
                                value="{{ $project['id'] }}"
                                class="mt-0.5 rounded border-slate-300 text-indigo-600 focus:ring-indigo-300 flex-shrink-0"
                            />
                            <span class="text-sm text-slate-700">
                                <span class="font-mono text-xs text-slate-400 mr-1">{{ $project['project_number'] }}</span>
                                {{ $project['title'] }}
                            </span>
                        </label>
                    @endforeach
                </div>
                @error('selectedResearchProjectIds')   <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                @error('selectedResearchProjectIds.*') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
        @endif

        {{-- Status --}}
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Status</label>
            <select
                wire:model="status"
                class="w-full sm:w-48 px-3 py-2 border border-slate-300 rounded-lg text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 bg-white">
                <option value="draft">Draft</option>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
                <option value="archived">Archived</option>
            </select>
            @error('status') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>

        {{-- Action buttons --}}
        <div class="flex flex-wrap items-center gap-3 pt-2 border-t border-slate-100">
            <button
                wire:click="save"
                wire:loading.attr="disabled"
                class="px-4 py-2 rounded-lg bg-indigo-600 text-white text-sm font-medium hover:bg-indigo-700 transition-colors disabled:opacity-60">
                <span wire:loading.remove wire:target="save">Save Draft</span>
                <span wire:loading wire:target="save">Saving…</span>
            </button>

            @if ($serviceId && $status !== 'active')
                <button
                    wire:click="publish"
                    wire:loading.attr="disabled"
                    class="px-4 py-2 rounded-lg bg-emerald-600 text-white text-sm font-medium hover:bg-emerald-700 transition-colors disabled:opacity-60">
                    <span wire:loading.remove wire:target="publish">Publish</span>
                    <span wire:loading wire:target="publish">Publishing…</span>
                </button>
            @endif

            @if ($serviceId && $status !== 'archived')
                <button
                    wire:click="archive"
                    wire:loading.attr="disabled"
                    wire:confirm="Archive this service? It will no longer be available to learners."
                    class="px-4 py-2 rounded-lg bg-slate-100 text-slate-700 text-sm font-medium hover:bg-slate-200 transition-colors disabled:opacity-60">
                    <span wire:loading.remove wire:target="archive">Archive</span>
                    <span wire:loading wire:target="archive">Archiving…</span>
                </button>
            @endif

            <a href="{{ route('editor.services.index') }}"
               class="px-4 py-2 rounded-lg text-sm font-medium text-slate-500 hover:text-slate-800 transition-colors">
                Cancel
            </a>
        </div>
    </div>
</div>
