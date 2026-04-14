<div class="bg-white rounded-lg shadow-md p-8 w-full max-w-md">

    {{-- Header --}}
    <h1 class="text-2xl font-bold text-slate-800 mb-1">Change Password</h1>
    <p class="text-slate-500 text-sm mb-6">Research Services</p>

    {{-- Forced-change callout --}}
    @if ($forced)
        <div class="mb-5 p-4 rounded-lg bg-amber-50 border border-amber-200">
            <p class="text-sm font-semibold text-amber-800 mb-0.5">Password change required</p>
            <p class="text-xs text-amber-700">
                Your account requires a new password before you can continue.
                This may be because an administrator reset your credentials or your
                password rotation period has expired.
            </p>
        </div>
    @endif

    {{-- Service-level errors (complexity / history) --}}
    @if (!empty($changeErrors))
        <div class="mb-4 p-3 rounded-lg bg-red-50 border border-red-200">
            <ul class="list-disc list-inside space-y-0.5">
                @foreach ($changeErrors as $err)
                    <li class="text-sm text-red-700">{{ $err }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form wire:submit="changePassword" class="space-y-4">

        {{-- Current password --}}
        <div>
            <label for="currentPassword"
                   class="block text-sm font-medium text-slate-700 mb-1">
                Current password
            </label>
            <input id="currentPassword"
                   type="password"
                   wire:model="currentPassword"
                   autocomplete="current-password"
                   class="w-full border rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500
                          @error('currentPassword') border-red-400 @else border-slate-300 @enderror" />
            @error('currentPassword')
                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
            @enderror
        </div>

        {{-- New password --}}
        <div>
            <label for="newPassword"
                   class="block text-sm font-medium text-slate-700 mb-1">
                New password
            </label>
            <input id="newPassword"
                   type="password"
                   wire:model="newPassword"
                   autocomplete="new-password"
                   class="w-full border rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500
                          @error('newPassword') border-red-400 @else border-slate-300 @enderror" />
            @error('newPassword')
                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
            @enderror
            <p class="mt-1 text-xs text-slate-400">
                Minimum 12 characters &middot; uppercase &middot; lowercase &middot; digit &middot; special character
            </p>
        </div>

        {{-- Confirm new password --}}
        <div>
            <label for="newPasswordConfirmation"
                   class="block text-sm font-medium text-slate-700 mb-1">
                Confirm new password
            </label>
            <input id="newPasswordConfirmation"
                   type="password"
                   wire:model="newPasswordConfirmation"
                   autocomplete="new-password"
                   class="w-full border rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500
                          @error('newPasswordConfirmation') border-red-400 @else border-slate-300 @enderror" />
            @error('newPasswordConfirmation')
                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <button type="submit"
                wire:loading.attr="disabled"
                class="w-full bg-blue-600 text-white font-medium py-2 px-4 rounded hover:bg-blue-700 disabled:opacity-50 text-sm">
            <span wire:loading.remove>Update password</span>
            <span wire:loading>Updating…</span>
        </button>

    </form>

    {{-- History / policy hint --}}
    <p class="mt-4 text-xs text-slate-400 text-center">
        New password cannot match any of your last 5 passwords.
    </p>

</div>
