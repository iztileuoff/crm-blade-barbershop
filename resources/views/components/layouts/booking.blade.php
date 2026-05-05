<!DOCTYPE html>
<html lang="ru" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Онлайн-запись — Blade Barbershop' }}</title>
    <meta name="description" content="Онлайн-запись в барбершоп Blade. Выберите услугу, мастера и удобное время.">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700,800" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    <style>[x-cloak]{display:none !important}</style>
</head>
<body class="min-h-full bg-[#090909] font-sans text-white antialiased">

    {{-- Sticky header --}}
    <header class="sticky top-0 z-50 border-b border-white/[0.06] bg-[#090909]/80 backdrop-blur-xl">
        <div class="mx-auto flex max-w-lg items-center justify-between px-4 py-3">
            <a href="/" class="flex items-center gap-2.5">
                <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-amber-400 to-amber-600 text-sm font-extrabold text-black">
                    B
                </div>
                <div class="leading-none">
                    <div class="text-sm font-bold tracking-tight">Blade</div>
                    <div class="text-[10px] font-semibold uppercase tracking-[0.2em] text-amber-500/70">Barbershop</div>
                </div>
            </a>
            <div class="flex items-center gap-2">
                <a href="{{ route('admin.appointments') }}"
                   class="rounded-lg border border-white/[0.06] px-3 py-1.5 text-[11px] font-medium text-white/30 transition hover:border-white/10 hover:text-white/60">
                    Панель
                </a>
                @auth
                <a href="{{ route('logout') }}"
                   class="rounded-lg border border-white/[0.06] px-3 py-1.5 text-[11px] font-medium text-rose-500/50 transition hover:border-rose-500/30 hover:text-rose-500">
                    Выход
                </a>
                @endauth
            </div>
        </div>
    </header>

    {{-- Main content --}}
    <main class="mx-auto max-w-lg px-4 pb-12 pt-6">
        {{ $slot }}
    </main>

    @livewireScripts
</body>
</html>
