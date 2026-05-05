<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new
#[Layout('components.layouts.booking')]
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
            'phone' => \App\Models\Client::normalizePhone($this->phone) ?? $this->phone,
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

<div class="flex min-h-screen items-center justify-center p-4">
    <div class="w-full max-w-md animate-fade-in-up rounded-3xl border border-white/[0.06] bg-white/[0.02] p-8 shadow-2xl shadow-black/50 backdrop-blur-xl">
        <div class="mb-8 text-center">
            <h1 class="text-3xl font-extrabold tracking-tight text-white">Вход</h1>
            <p class="mt-2 text-sm text-white/40">Войдите, чтобы управлять системой</p>
        </div>

        <form wire:submit="login" class="space-y-5">
            <div>
                <label for="phone" class="mb-1.5 block text-xs font-semibold text-white/50">Телефон</label>
                <input id="phone" type="tel" wire:model="phone" placeholder="998 90 123 45 67" required autofocus autocomplete="username"
                       class="block w-full rounded-xl border border-white/[0.08] bg-white/[0.04] px-4 py-3.5 text-sm text-white placeholder-white/20 outline-none transition focus:border-amber-500/40 focus:ring-1 focus:ring-amber-500/20">
                @error('phone') <p class="mt-1.5 text-xs text-rose-400">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="password" class="mb-1.5 block text-xs font-semibold text-white/50">Пароль</label>
                <input id="password" type="password" wire:model="password" placeholder="••••••••" required autocomplete="current-password"
                       class="block w-full rounded-xl border border-white/[0.08] bg-white/[0.04] px-4 py-3.5 text-sm text-white placeholder-white/20 outline-none transition focus:border-amber-500/40 focus:ring-1 focus:ring-amber-500/20">
                @error('password') <p class="mt-1.5 text-xs text-rose-400">{{ $message }}</p> @enderror
            </div>

            <div class="flex items-center">
                <label class="relative inline-flex cursor-pointer items-center">
                    <input type="checkbox" wire:model="remember" class="peer sr-only">
                    <div class="h-5 w-9 rounded-full bg-white/10 transition-colors peer-checked:bg-amber-500 after:absolute after:left-[2px] after:top-[2px] after:h-4 after:w-4 after:rounded-full after:bg-white after:transition-all peer-checked:after:translate-x-full"></div>
                    <span class="ml-3 text-sm font-medium text-white/60">Запомнить меня</span>
                </label>
            </div>

            <button type="submit"
                    class="mt-4 w-full rounded-xl bg-gradient-to-r from-amber-500 to-amber-600 px-4 py-3.5 text-sm font-bold text-black shadow-lg shadow-amber-500/20 transition-all hover:shadow-amber-500/30 active:scale-[0.98]">
                Войти в систему
            </button>
        </form>
    </div>
</div>
