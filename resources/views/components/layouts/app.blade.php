<!DOCTYPE html>
<html lang="ru" class="h-full bg-zinc-50">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="h-full font-sans text-zinc-900 antialiased">
    <header class="border-b border-zinc-200 bg-white">
        <div class="mx-auto flex max-w-5xl items-center justify-between px-4 py-4">
            <a href="{{ route('booking') }}" class="flex items-center gap-2 text-lg font-bold tracking-tight">
                <span class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-zinc-900 text-white">B</span>
                <span>Blade Barbershop</span>
            </a>
            <nav class="flex items-center gap-4 text-sm">
                <a href="{{ route('booking') }}" class="text-zinc-600 hover:text-zinc-900">Запись</a>
                <a href="{{ route('admin.appointments') }}" class="text-zinc-600 hover:text-zinc-900">Админ</a>
            </nav>
        </div>
    </header>

    <main class="mx-auto max-w-5xl px-4 py-8">
        {{ $slot }}
    </main>

    @livewireScripts
</body>
</html>
