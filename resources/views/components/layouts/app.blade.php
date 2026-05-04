<!DOCTYPE html>
<html lang="ru" class="h-full bg-zinc-50">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    <style>[x-cloak]{display:none !important}</style>
</head>
<body class="h-full font-sans text-zinc-900 antialiased">
    <header x-data="{ open: false }" class="border-b border-zinc-200 bg-white">
        <div class="mx-auto flex max-w-5xl items-center justify-between px-4 py-3 sm:py-4">
            <a href="{{ route('booking') }}" class="flex items-center gap-2 text-base font-bold tracking-tight sm:text-lg">
                <span class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-zinc-900 text-white">B</span>
                <span>Blade Barbershop</span>
            </a>

            <nav class="hidden items-center gap-4 text-sm md:flex">
                <a href="{{ route('booking') }}" class="text-zinc-600 hover:text-zinc-900">Запись</a>
                <a href="{{ route('admin.appointments') }}" class="text-zinc-600 hover:text-zinc-900">Записи</a>
                <a href="{{ route('admin.barbers') }}" class="text-zinc-600 hover:text-zinc-900">Мастера</a>
                <a href="{{ route('admin.specializations') }}" class="text-zinc-600 hover:text-zinc-900">Специализации</a>
                <a href="{{ route('admin.clients') }}" class="text-zinc-600 hover:text-zinc-900">Клиенты</a>
            </nav>

            <button type="button" @click="open = !open" aria-label="Меню"
                    class="inline-flex h-10 w-10 items-center justify-center rounded-lg border border-zinc-200 text-zinc-700 md:hidden">
                <svg x-show="!open" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
                </svg>
                <svg x-show="open" x-cloak xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 6l12 12M6 18L18 6" />
                </svg>
            </button>
        </div>

        <nav x-show="open" x-cloak @click="open = false"
             class="border-t border-zinc-200 bg-white px-4 pb-3 pt-2 text-sm md:hidden">
            <div class="mx-auto flex max-w-5xl flex-col gap-1">
                <a href="{{ route('booking') }}" class="rounded-lg px-3 py-2 text-zinc-700 hover:bg-zinc-50">Запись</a>
                <a href="{{ route('admin.appointments') }}" class="rounded-lg px-3 py-2 text-zinc-700 hover:bg-zinc-50">Записи</a>
                <a href="{{ route('admin.barbers') }}" class="rounded-lg px-3 py-2 text-zinc-700 hover:bg-zinc-50">Мастера</a>
                <a href="{{ route('admin.specializations') }}" class="rounded-lg px-3 py-2 text-zinc-700 hover:bg-zinc-50">Специализации</a>
                <a href="{{ route('admin.clients') }}" class="rounded-lg px-3 py-2 text-zinc-700 hover:bg-zinc-50">Клиенты</a>
            </div>
        </nav>
    </header>

    <main class="mx-auto max-w-5xl px-4 py-6 sm:py-8">
        {{ $slot }}
    </main>

    @livewireScripts
</body>
</html>
