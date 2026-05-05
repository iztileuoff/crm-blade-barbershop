<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new
    #[Layout('components.layouts.booking')]
    class extends Component {
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

<div class="flex min-h-screen items-center justify-center">
    <div class="w-full max-w-md rounded-2xl border border-white/10 bg-white/5 p-8 shadow-xl backdrop-blur-lg">
        <div class="mb-6 text-center">
            <h1 class="text-2xl font-bold text-white">Вход</h1>
            <p class="mt-1 text-sm text-white/50">Управление системой</p>
        </div>

        <form wire:submit.prevent="login" class="flex flex-col gap-3">
            <div>
                <label class="mb-1 block text-xs text-white/60">Телефон</label>
                <input type="tel" wire:model.defer="phone" placeholder="998 90 123 45 67"
                    class="w-full rounded-lg border border-white/10 bg-black/30 px-3 py-2.5 text-sm text-white placeholder-white/30 focus:border-amber-500 focus:ring-1 focus:ring-amber-500/30 outline-none">
                @error('phone') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="mb-1 block text-xs text-white/60">Пароль</label>
                <input type="password" wire:model.defer="password" placeholder="••••••••"
                    class="w-full rounded-lg border border-white/10 bg-black/30 px-3 py-2.5 text-sm text-white placeholder-white/30 focus:border-amber-500 focus:ring-1 focus:ring-amber-500/30 outline-none">
                @error('password') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
            </div>

            <div class="flex items-center justify-between text-sm">
                <label class="flex items-center gap-2 text-white/60">
                    <input type="checkbox" wire:model="remember"
                        class="rounded border-white/20 bg-black/30 text-amber-500 focus:ring-amber-500">
                    Запомнить
                </label>
            </div>

            <button type="submit"
                class="w-full rounded-lg bg-amber-500 py-2.5 text-sm font-semibold text-black hover:bg-amber-400 active:scale-[0.98] transition">
                Войти
            </button>
        </form>
    </div>
</div>