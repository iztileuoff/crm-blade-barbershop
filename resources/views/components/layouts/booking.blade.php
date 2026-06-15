<!DOCTYPE html>
<html lang="ru" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Онлайн-запись — Blade Barbershop' }}</title>
    <meta name="description" content="Онлайн-запись в барбершоп Blade. Выберите услугу, мастера и удобное время.">
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

@auth
    <body x-data="{ open: false, collapsed: localStorage.getItem('sidebarCollapsed') === '1' }"
          x-init="$watch('collapsed', value => localStorage.setItem('sidebarCollapsed', value ? '1' : '0'))"
          class="min-h-full bg-surface font-sans text-content antialiased">
        {{-- Logged-in admins keep the full admin header so navigation never disappears --}}
        <x-admin-header />

        <div class="min-h-screen transition-[padding] duration-300 ease-in-out" :class="collapsed ? 'lg:pl-20' : 'lg:pl-64'">
            {{-- Main content (wider for admins) --}}
            <main class="mx-auto w-full max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                {{ $slot }}
            </main>
        </div>

        @livewireScripts
    </body>
@else
    <body class="min-h-full bg-surface font-sans text-content antialiased">
        {{-- Public sticky header for guests --}}
        <header class="sticky top-0 z-50 border-b border-content/[0.06] bg-surface/80 backdrop-blur-xl">
            <div class="mx-auto flex max-w-lg items-center justify-between px-4 py-3">
                <a href="/" class="flex items-center gap-2.5">
                    <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-brass-bright to-brass text-sm font-extrabold text-on-brass">
                        B
                    </div>
                    <div class="leading-none">
                        <div class="font-display text-base font-semibold uppercase tracking-[0.18em]">Blade</div>
                        <div class="mt-0.5 text-[10px] font-semibold uppercase tracking-[0.2em] text-brass-ink/70">Barbershop</div>
                    </div>
                </a>
                <x-theme-toggle class="flex h-9 w-9 items-center justify-center rounded-xl border border-content/[0.06] text-content/50 transition hover:border-brass/40 hover:text-brass-ink" />
            </div>
        </header>

        {{-- Main content (narrow mobile-first for booking) --}}
        <main class="mx-auto max-w-lg px-4 pb-12 pt-6">
            {{ $slot }}
        </main>

        @livewireScripts
    </body>
@endauth
</html>
