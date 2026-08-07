<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? __('booking.meta_title') }}</title>
    <meta name="description" content="{{ __('booking.meta_description') }}">
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

        <x-transport-status />

        @livewireScripts
    </body>
@else
    <body class="min-h-full bg-surface font-sans text-content antialiased">
        {{-- Public sticky header for guests --}}
        @php
            $shopPhone = \App\Models\Setting::get('shop_phone');
            $shopTelegramUrl = \App\Models\Setting::telegramUrl();
        @endphp
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
                <div class="flex items-center gap-2">
                    @if ($shopPhone)
                        <a href="tel:{{ preg_replace('/\D+/', '', $shopPhone) }}" aria-label="{{ __('common.phone') }}"
                           class="flex h-9 w-9 items-center justify-center rounded-xl border border-content/[0.06] text-content/50 transition hover:border-brass/40 hover:text-brass-ink">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 0 0 2.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 0 1-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 0 0-1.091-.852H4.5A2.25 2.25 0 0 0 2.25 4.5v2.25Z" /></svg>
                        </a>
                    @endif
                    @if ($shopTelegramUrl)
                        <a href="{{ $shopTelegramUrl }}" target="_blank" rel="noopener" aria-label="Telegram"
                           class="flex h-9 w-9 items-center justify-center rounded-xl border border-content/[0.06] text-content/50 transition hover:border-brass/40 hover:text-brass-ink">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5" /></svg>
                        </a>
                    @endif
                    <x-language-switcher />
                    <x-theme-toggle class="flex h-9 w-9 items-center justify-center rounded-xl border border-content/[0.06] text-content/50 transition hover:border-brass/40 hover:text-brass-ink" />
                </div>
            </div>
        </header>

        {{-- Main content (narrow mobile-first for booking) --}}
        <main class="mx-auto max-w-lg px-4 pb-12 pt-6">
            {{ $slot }}
        </main>

        {{-- app.js гасит собственную реакцию Livewire на упавший запрос, так что
             без этого компонента гость не увидит ни истёкшей сессии, ни обрыва
             связи — кнопка просто выглядела бы мёртвой. --}}
        <x-transport-status guest />

        @livewireScripts
    </body>
@endauth
</html>
