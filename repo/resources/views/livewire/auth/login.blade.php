<div class="bg-white rounded-lg shadow-md p-8">
    <h1 class="text-2xl font-bold text-slate-800 mb-2">{{ config('app.name') }}</h1>
    <p class="text-slate-500 text-sm mb-6">Research Services — Sign In</p>

    @if ($error)
        <div class="mb-4 p-3 bg-red-50 border border-red-200 text-red-700 rounded text-sm">
            {{ $error }}
        </div>
    @endif

    <form wire:submit="authenticate" class="space-y-4">
        <div>
            <label for="username" class="block text-sm font-medium text-slate-700 mb-1">Username</label>
            <input
                id="username"
                type="text"
                wire:model="username"
                autocomplete="username"
                class="w-full border border-slate-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 @error('username') border-red-400 @enderror"
            >
            @error('username')
                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="password" class="block text-sm font-medium text-slate-700 mb-1">Password</label>
            <input
                id="password"
                type="password"
                wire:model="password"
                autocomplete="current-password"
                class="w-full border border-slate-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 @error('password') border-red-400 @enderror"
            >
            @error('password')
                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
            @enderror
        </div>

        {{--
            Local math-challenge CAPTCHA.
            Shown after brute-force threshold is reached (configured via system_config).
            The question is generated server-side; the answer is validated server-side.
            No external service or image rendering — fully offline.
        --}}
        @if ($showCaptcha)
            <div class="p-4 bg-amber-50 border border-amber-200 rounded">
                <p class="text-sm font-medium text-amber-900 mb-2">Security check</p>
                <p class="text-base font-mono font-semibold text-amber-800 mb-2 select-none">
                    What is <span class="inline-block px-2 py-0.5 bg-amber-100 rounded">{{ $captchaQuestion }}</span>?
                </p>
                <input
                    id="captcha-input"
                    type="text"
                    wire:model="captchaInput"
                    inputmode="numeric"
                    autocomplete="off"
                    placeholder="Your answer"
                    class="w-full border border-amber-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-amber-400 @error('captchaInput') border-red-400 @enderror"
                >
                @error('captchaInput')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>
        @endif

        <button
            type="submit"
            wire:loading.attr="disabled"
            class="w-full bg-blue-600 text-white font-medium py-2 px-4 rounded hover:bg-blue-700 disabled:opacity-50 text-sm"
        >
            <span wire:loading.remove>Sign In</span>
            <span wire:loading>Signing in…</span>
        </button>
    </form>

    <p class="mt-4 text-xs text-slate-400 text-center">
        Offline-only authentication. No external identity provider.
    </p>
</div>
