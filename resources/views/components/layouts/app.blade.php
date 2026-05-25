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
<body x-data="{ open: false, collapsed: localStorage.getItem('sidebarCollapsed') === '1' }"
      x-init="$watch('collapsed', value => localStorage.setItem('sidebarCollapsed', value ? '1' : '0'))"
      class="min-h-full bg-[#090909] font-sans text-white antialiased">
    <x-admin-header />

    <div class="min-h-screen transition-[padding] duration-300 ease-in-out" :class="collapsed ? 'lg:pl-20' : 'lg:pl-64'">
        {{-- Main content --}}
        <main class="mx-auto w-full max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
            {{ $slot }}
        </main>
    </div>

    @livewireScripts
</body>
</html>
