<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? __('common.admin_title') }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700,800|oswald:300,400,500,600,700" rel="stylesheet" />
    <script>
        (function () {
            try {
                var stored = localStorage.getItem('theme');
                var dark = stored ? stored === 'dark' : window.matchMedia('(prefers-color-scheme: dark)').matches;
                if (dark) { document.documentElement.classList.add('dark'); }
            } catch (e) {}
        })();
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    <style>[x-cloak]{display:none !important}</style>
</head>
<body x-data="{ open: false, collapsed: localStorage.getItem('sidebarCollapsed') === '1' }"
      x-init="$watch('collapsed', value => localStorage.setItem('sidebarCollapsed', value ? '1' : '0'))"
      class="min-h-full bg-surface font-sans text-content antialiased">
    <x-admin-header />

    <div class="min-h-screen transition-[padding] duration-300 ease-in-out" :class="collapsed ? 'lg:pl-20' : 'lg:pl-64'">
        {{-- Desktop top header --}}
        <header class="sticky top-0 z-30 hidden items-center justify-between border-b border-content/[0.06] bg-surface/80 px-6 py-3 backdrop-blur-xl lg:flex">
            {{-- Collapse / expand toggle --}}
            <button type="button" @click="collapsed = !collapsed"
                    class="flex items-center gap-2.5 rounded-xl px-3 py-2 text-sm font-medium text-content/45 transition-colors hover:bg-content/[0.04] hover:text-content">
                <svg class="h-5 w-5 shrink-0 transition-transform duration-300" :class="{ 'rotate-180': collapsed }" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M18.75 19.5 11.25 12l7.5-7.5m-7.5 15L3.75 12l7.5-7.5" /></svg>
                <span x-text="collapsed ? '{{ __('common.expand') }}' : '{{ __('common.collapse') }}'">{{ __('common.collapse') }}</span>
            </button>

            <div class="flex items-center gap-2.5">
                {{-- Language switcher --}}
                <x-language-switcher class="flex h-10 items-center gap-1.5 rounded-xl border border-content/[0.06] px-2.5 text-content/50 transition hover:border-brass/40 hover:text-brass-ink" />

                {{-- Theme toggle --}}
                <x-theme-toggle class="flex h-10 w-10 items-center justify-center rounded-xl border border-content/[0.06] text-content/50 transition hover:border-brass/40 hover:text-brass-ink" />

                {{-- Profile / logout --}}
                @auth
                    <div x-data="{ profileOpen: false }" class="relative">
                        <button type="button" @click="profileOpen = !profileOpen"
                                class="flex items-center gap-2.5 rounded-xl border border-content/[0.06] py-1.5 pl-1.5 pr-3 transition hover:border-content/10">
                            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-brass/15 text-xs font-bold text-brass-ink ring-1 ring-content/10">{{ mb_substr(auth()->user()->name, 0, 1) }}</div>
                            <div class="text-left leading-tight">
                                <div class="text-sm font-semibold text-content">{{ auth()->user()->name }}</div>
                                <div class="mt-0.5 text-[10px] font-medium text-content/40">{{ auth()->user()->role->label() }}</div>
                            </div>
                            <svg class="h-4 w-4 text-content/30 transition-transform duration-200" :class="{ 'rotate-180': profileOpen }" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
                        </button>
                        <div x-show="profileOpen" x-cloak @click.outside="profileOpen = false"
                             x-transition:enter="transition ease-out duration-150"
                             x-transition:enter-start="opacity-0 -translate-y-1"
                             x-transition:enter-end="opacity-100 translate-y-0"
                             class="absolute right-0 z-50 mt-2 w-48 rounded-xl border border-content/[0.06] bg-surface-raised p-1.5 shadow-xl">
                            <a href="{{ route('logout') }}"
                               class="flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm font-medium text-danger/70 transition hover:bg-danger/10 hover:text-danger">
                                <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 9V5.25A2.25 2.25 0 0 1 10.5 3h6a2.25 2.25 0 0 1 2.25 2.25v13.5A2.25 2.25 0 0 1 16.5 21h-6a2.25 2.25 0 0 1-2.25-2.25V15M12 9l3 3m0 0-3 3m3-3H2.25" /></svg>
                                {{ __('common.logout') }}
                            </a>
                        </div>
                    </div>
                @endauth
            </div>
        </header>

        {{-- Main content --}}
        <main class="mx-auto w-full max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
            {{ $slot }}
        </main>
    </div>

    @livewireScripts
</body>
</html>
