<!DOCTYPE html>
<html lang="ru" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Админ-панель — Blade Barbershop' }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700,800" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    <style>[x-cloak]{display:none !important}</style>
</head>
<body class="min-h-full bg-[#090909] font-sans text-white antialiased">
    <div x-data="{ open: false }" class="flex min-h-screen flex-col">
        {{-- Sticky header --}}
        <header class="sticky top-0 z-50 border-b border-white/[0.06] bg-[#090909]/80 backdrop-blur-xl">
            <div class="mx-auto flex max-w-7xl items-center justify-between px-4 py-3 sm:px-6 lg:px-8">
                <div class="flex items-center gap-8">
                    <a href="{{ route('booking') }}" class="flex items-center gap-2.5 group">
                        <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-amber-400 to-amber-600 text-sm font-extrabold text-black transition-transform group-hover:scale-105">
                            B
                        </div>
                        <div class="leading-none">
                            <div class="text-sm font-bold tracking-tight text-white">Blade</div>
                            <div class="text-[10px] font-semibold uppercase tracking-[0.2em] text-amber-500/70">Admin Panel</div>
                        </div>
                    </a>

                    <nav class="hidden items-center gap-1 text-sm font-medium md:flex">
                        @php($links = [
                            ['route' => 'admin.dashboard', 'label' => 'Касса'],
                            ['route' => 'admin.appointments', 'label' => 'Записи'],
                            ['route' => 'admin.barbers', 'label' => 'Мастера'],
                            ['route' => 'admin.services', 'label' => 'Услуги'],
                            ['route' => 'admin.specializations', 'label' => 'Спец.'],
                            ['route' => 'admin.clients', 'label' => 'Клиенты'],
                        ])
                        @if(auth()->user()?->isSuperAdmin())
                            @php($links[] = ['route' => 'admin.users', 'label' => 'Пользователи'])
                        @endif
                        @foreach($links as $link)
                            <a href="{{ route($link['route']) }}" 
                               @class([
                                   'rounded-lg px-3 py-2 transition-colors',
                                   'bg-white/[0.06] text-amber-400' => request()->routeIs($link['route']),
                                   'text-white/40 hover:bg-white/[0.03] hover:text-white' => !request()->routeIs($link['route']),
                               ])>
                                {{ $link['label'] }}
                            </a>
                        @endforeach
                    </nav>
                </div>

                <div class="flex items-center gap-3">
                    <a href="{{ route('booking') }}" target="_blank" class="flex items-center gap-3 rounded-xl px-4 py-3 text-sm font-medium text-white/60 hover:bg-white/[0.03] hover:text-white">
                        На сайт
                    </a>
                    
                    @auth
                    <a href="{{ route('logout') }}" class="hidden items-center gap-1.5 rounded-lg border border-white/[0.06] px-3 py-1.5 text-xs font-medium text-rose-500/50 transition hover:border-rose-500/30 hover:text-rose-500 sm:flex">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 9V5.25A2.25 2.25 0 0 1 10.5 3h6a2.25 2.25 0 0 1 2.25 2.25v13.5A2.25 2.25 0 0 1 16.5 21h-6a2.25 2.25 0 0 1-2.25-2.25V15M12 9l3 3m0 0-3 3m3-3H2.25" /></svg>
                        Выход
                    </a>
                    @endauth
                    <button type="button" @click="open = !open" 
                            class="flex h-10 w-10 items-center justify-center rounded-xl border border-white/[0.06] text-white/40 transition hover:border-white/10 hover:text-white md:hidden">
                        <svg x-show="!open" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" /></svg>
                        <svg x-show="open" x-cloak class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                    </button>
                </div>
            </div>

            {{-- Mobile nav --}}
            <div x-show="open" x-cloak 
                 x-transition:enter="transition ease-out duration-200"
                 x-transition:enter-start="opacity-0 -translate-y-4"
                 x-transition:enter-end="opacity-100 translate-y-0"
                 class="border-t border-white/[0.06] bg-[#090909] px-4 py-4 md:hidden">
                <nav class="grid gap-2">
                    @foreach($links as $link)
                        <a href="{{ route($link['route']) }}" 
                           @class([
                               'flex items-center gap-3 rounded-xl px-4 py-3 text-sm font-medium transition-all',
                               'bg-amber-500/10 text-amber-400' => request()->routeIs($link['route']),
                               'text-white/40 hover:bg-white/[0.03] hover:text-white' => !request()->routeIs($link['route']),
                           ])>
                            {{ $link['label'] }}
                        </a>
                    @endforeach
                    @auth
                        <a href="{{ route('logout') }}" class="flex items-center gap-3 rounded-xl px-4 py-3 text-sm font-medium text-rose-500/50 transition hover:bg-rose-500/10 hover:text-rose-500">
                            Выход
                        </a>
                    @endauth
                </nav>
            </div>
        </header>

        {{-- Main content --}}
        <main class="mx-auto w-full max-w-7xl flex-1 px-4 py-8 sm:px-6 lg:px-8">
            {{ $slot }}
        </main>
    </div>

    @livewireScripts
</body>
</html>
