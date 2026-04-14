<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="h-full">
    <nav class="bg-white border-b border-slate-200 px-6 py-0 flex items-stretch justify-between sticky top-0 z-30">
        <div class="flex items-center gap-1">
            <a href="{{ route('dashboard') }}" class="font-semibold text-slate-800 text-sm px-3 py-3 mr-2">
                {{ config('app.name') }}
            </a>
            @php
                $navItems = [
                    ['route' => 'dashboard',         'label' => 'Dashboard'],
                    ['route' => 'catalog.index',     'label' => 'Catalog'],
                    ['route' => 'reservations.index','label' => 'Reservations'],
                ];
            @endphp
            @foreach ($navItems as $item)
                <a href="{{ route($item['route']) }}"
                   class="text-sm px-3 py-3 border-b-2 transition-colors
                       {{ request()->routeIs($item['route']) || (str_starts_with(request()->path(), str_replace('.index', '', $item['route'])) && $item['route'] !== 'dashboard')
                           ? 'border-indigo-500 text-indigo-700 font-medium'
                           : 'border-transparent text-slate-500 hover:text-slate-800 hover:border-slate-300' }}">
                    {{ $item['label'] }}
                </a>
            @endforeach
            @hasrole('content_editor|administrator')
                <a href="{{ route('editor.services.index') }}"
                   class="text-sm px-3 py-3 border-b-2 transition-colors
                       {{ request()->routeIs('editor.*') && !request()->routeIs('editor.pending.*') ? 'border-indigo-500 text-indigo-700 font-medium' : 'border-transparent text-slate-500 hover:text-slate-800 hover:border-slate-300' }}">
                    Editor
                </a>
                <a href="{{ route('editor.pending.index') }}"
                   class="text-sm px-3 py-3 border-b-2 transition-colors
                       {{ request()->routeIs('editor.pending.*') ? 'border-indigo-500 text-indigo-700 font-medium' : 'border-transparent text-slate-500 hover:text-slate-800 hover:border-slate-300' }}">
                    Pending
                </a>
            @endhasrole
            @hasrole('administrator')
                <a href="{{ route('admin.users.index') }}"
                   class="text-sm px-3 py-3 border-b-2 transition-colors
                       {{ request()->routeIs('admin.*') ? 'border-indigo-500 text-indigo-700 font-medium' : 'border-transparent text-slate-500 hover:text-slate-800 hover:border-slate-300' }}">
                    Admin
                </a>
            @endhasrole
        </div>
        <div class="flex items-center gap-3 text-sm text-slate-500">
            <span class="hidden sm:inline text-xs">{{ auth()->user()?->display_name }}</span>
            <form method="POST" action="{{ route('logout') }}" class="inline">
                @csrf
                <button type="submit" class="px-3 py-3 hover:text-red-600 transition-colors">Sign out</button>
            </form>
        </div>
    </nav>

    <main class="max-w-screen-xl mx-auto px-6 py-6">
        @if (session('warning'))
            <div class="mb-4 p-3 bg-yellow-50 border border-yellow-200 text-yellow-800 rounded text-sm">
                {{ session('warning') }}
            </div>
        @endif
        @if (session('status'))
            <div class="mb-4 p-3 bg-green-50 border border-green-200 text-green-800 rounded text-sm">
                {{ session('status') }}
            </div>
        @endif

        {{-- Supports both Livewire $slot and classic @extends/@yield --}}
        @hasSection('content')
            @yield('content')
        @else
            {{ $slot }}
        @endif
    </main>

    @livewireScripts
</body>
</html>
