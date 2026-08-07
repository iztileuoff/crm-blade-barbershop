<?php

use App\Models\Client;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new
    #[Layout('components.layouts.auth')]
    class extends Component
    {
        #[Validate('required|string')]
        public string $phone = '';

        #[Validate('required|string')]
        public string $password = '';

        public bool $remember = false;

        public function login()
        {
            $this->validate();

            $credentials = [
                'phone' => Client::normalizePhone($this->phone) ?? $this->phone,
                'password' => $this->password,
            ];

            if (Auth::attempt($credentials, $this->remember)) {
                session()->regenerate();

                return redirect()->route('admin.appointments');
            }

            throw ValidationException::withMessages([
                'phone' => trans('auth.failed'),
            ]);
        }
    }; ?>

@php
    $scissors = '<path stroke-linecap="round" stroke-linejoin="round" d="M7.848 8.25l1.536.887M7.848 8.25a3 3 0 1 1-5.196-3 3 3 0 0 1 5.196 3zm1.536.887a2.165 2.165 0 0 1 1.083 1.839c.005.351.054.695.14 1.024M9.384 9.137l10.062 5.808M9.708 6.075 6.684 11.27m12.348 5.872-7.371-4.255-.715-.41m0 0a3 3 0 1 0-3.522 4.84 3 3 0 0 0 3.522-4.84zm.715-.41-3.024-5.193m6.043 1.385L11.97 12.43m7.371-4.255a3 3 0 1 0 5.196-3 3 3 0 0 0-5.196 3z" />';
@endphp

<div class="min-h-screen lg:grid lg:grid-cols-[1fr_1.05fr]">
    {{-- ─── Form column ──────────────────────────────────────────────── --}}
    <div class="relative flex min-h-screen items-center justify-center px-6 py-12 lg:min-h-0">
        <div class="absolute right-5 top-5 flex items-center gap-2">
            <x-language-switcher class="flex h-10 items-center gap-1.5 rounded-xl border border-line px-2.5 text-content/50 transition hover:border-brass/40 hover:text-brass-ink" />
            <x-theme-toggle class="flex h-10 w-10 items-center justify-center rounded-xl border border-line text-content/50 transition hover:border-brass/40 hover:text-brass-ink" />
        </div>

        <div class="w-full max-w-sm">
            {{-- Brand lockup --}}
            <div class="flex items-center gap-3">
                <div class="bg-brushed flex h-11 w-11 items-center justify-center rounded-xl border border-brass/40 shadow-lg shadow-black/20">
                    <svg class="h-6 w-6 text-brass-ink" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">{!! $scissors !!}</svg>
                </div>
                <div class="leading-none">
                    <div class="font-display text-lg font-semibold uppercase tracking-[0.18em] text-content">Blade</div>
                    <div class="mt-1 text-[10px] font-semibold uppercase tracking-[0.32em] text-brass-ink/70">Barbershop</div>
                </div>
            </div>

            {{-- Heading + razor-stroke signature --}}
            <div class="mt-14">
                <h1 class="font-display text-5xl font-semibold uppercase leading-none tracking-tight text-content">{{ __('login.title') }}</h1>
                <div class="razor-stroke animate-razor mt-4 w-32"></div>
                <p class="mt-5 text-sm text-content/50">{{ __('login.subtitle') }}</p>
            </div>

            {{-- Form --}}
            <form wire:submit.prevent="login" class="mt-9 flex flex-col gap-5">
                <div>
                    <label for="phone" class="mb-2 block text-[11px] font-semibold uppercase tracking-[0.18em] text-content/55">{{ __('common.phone') }}</label>
                    <div class="relative">
                        <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-4 text-content/35">
                            <svg class="h-4.5 w-4.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 0 0 2.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 0 1-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 0 0-1.091-.852H4.5A2.25 2.25 0 0 0 2.25 4.5v2.25Z" /></svg>
                        </span>
                        <input id="phone" type="tel" inputmode="tel" autocomplete="tel" autofocus wire:model="phone" placeholder="998 90 123 45 67"
                            class="w-full rounded-lg border border-line bg-surface-sunken py-3 pl-11 pr-4 text-sm text-content placeholder-content/30 outline-none transition focus:border-brass focus:ring-2 focus:ring-brass/25">
                    </div>
                    @error('phone') <p class="mt-2 text-xs text-danger">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="password" class="mb-2 block text-[11px] font-semibold uppercase tracking-[0.18em] text-content/55">{{ __('common.password') }}</label>
                    <div class="relative">
                        <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-4 text-content/35">
                            <svg class="h-4.5 w-4.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" /></svg>
                        </span>
                        <input id="password" type="password" autocomplete="current-password" wire:model="password" placeholder="••••••••"
                            class="w-full rounded-lg border border-line bg-surface-sunken py-3 pl-11 pr-4 text-sm text-content placeholder-content/30 outline-none transition focus:border-brass focus:ring-2 focus:ring-brass/25">
                    </div>
                    @error('password') <p class="mt-2 text-xs text-danger">{{ $message }}</p> @enderror
                </div>

                <label class="flex w-fit select-none items-center gap-2.5 text-sm text-content/60">
                    <input type="checkbox" wire:model="remember"
                        class="h-4 w-4 rounded border-line bg-surface-sunken text-brass focus:ring-2 focus:ring-brass/40 focus:ring-offset-0">
                    {{ __('login.remember') }}
                </label>

                <button type="submit" wire:loading.attr="disabled" wire:target="login"
                    class="mt-2 inline-flex w-full items-center justify-center gap-2 rounded-lg bg-brass px-4 py-3 text-sm font-bold uppercase tracking-[0.12em] text-on-brass transition hover:bg-brass-bright focus:outline-none focus:ring-2 focus:ring-brass/40 focus:ring-offset-2 focus:ring-offset-surface active:scale-[0.99] disabled:opacity-60">
                    <span wire:loading.remove wire:target="login">{{ __('login.submit') }}</span>
                    <span wire:loading wire:target="login" class="inline-flex items-center gap-2">
                        <svg class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3"></circle><path class="opacity-90" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                        {{ __('login.submitting') }}
                    </span>
                </button>
            </form>

            <p class="mt-12 text-[11px] text-content-subtle">Blade Barbershop © {{ date('Y') }}</p>
        </div>
    </div>

    {{-- ─── Brand panel (desktop only) ───────────────────────────────── --}}
    <div class="bg-brushed relative hidden overflow-hidden border-l border-line lg:block">
        {{-- Oversized signage wordmark, set vertically like a barber-shop sign --}}
        <div class="pointer-events-none absolute -right-10 top-1/2 -translate-y-1/2 select-none font-display text-[16rem] font-bold uppercase leading-none tracking-tighter text-content/[0.04]"
             style="writing-mode: vertical-rl;">Blade</div>

        {{-- Faint scissors emblem --}}
        <svg class="pointer-events-none absolute left-1/2 top-1/2 h-[28rem] w-[28rem] -translate-x-1/2 -translate-y-1/2 text-brass/[0.06]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1">{!! $scissors !!}</svg>

        {{-- Content --}}
        <div class="relative flex h-full flex-col justify-between p-14">
            <div class="font-display text-xs font-semibold uppercase tracking-[0.4em] text-brass-ink/70">{{ __('login.brand_eyebrow') }}</div>

            <div>
                <h2 class="font-display text-4xl font-semibold uppercase leading-[1.05] tracking-tight text-content">
                    {{ __('login.brand_title_line1') }}<br>{{ __('login.brand_title_line2') }}
                </h2>
                <div class="razor-stroke mt-5 w-44 opacity-80"></div>
                <p class="mt-6 max-w-xs text-sm leading-relaxed text-content/50">
                    {{ __('login.brand_text') }}
                </p>
            </div>
        </div>
    </div>
</div>
