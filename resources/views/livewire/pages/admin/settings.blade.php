<?php

use App\Models\Setting;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new
#[Layout('components.layouts.app')]
class extends Component
{
    public string $shop_name = '';

    public string $phone = '';

    public string $address = '';

    public string $work_start = '';

    public string $work_end = '';

    public string $instagram = '';

    public string $telegram = '';

    public function mount(): void
    {
        $this->shop_name = Setting::get('shop_name', '') ?? '';
        $this->phone = Setting::get('shop_phone', '') ?? '';
        $this->address = Setting::get('shop_address', '') ?? '';
        $this->work_start = Setting::get('work_start', '') ?? '';
        $this->work_end = Setting::get('work_end', '') ?? '';
        $this->instagram = Setting::get('instagram', '') ?? '';
        $this->telegram = Setting::get('telegram', '') ?? '';
    }

    public function save(): void
    {
        $this->validate([
            'shop_name' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:500',
            'work_start' => 'nullable|regex:/^\d{2}:\d{2}$/',
            'work_end' => 'nullable|regex:/^\d{2}:\d{2}$/',
            'instagram' => 'nullable|string|max:255',
            'telegram' => 'nullable|string|max:255',
        ]);

        Setting::set('shop_name', $this->shop_name ?: null);
        Setting::set('shop_phone', $this->phone ?: null);
        Setting::set('shop_address', $this->address ?: null);
        Setting::set('work_start', $this->work_start ?: null);
        Setting::set('work_end', $this->work_end ?: null);
        Setting::set('instagram', $this->instagram ?: null);
        Setting::set('telegram', $this->telegram ?: null);

        $this->dispatch('settings-saved');
    }
}; ?>

<div class="animate-fade-in-up">
    <div class="mb-8 flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="font-display text-4xl font-semibold uppercase tracking-tight text-content">Настройки</h1>
            <p class="mt-1 text-sm text-content/40">Информация о салоне и контакты</p>
        </div>
    </div>

    <div x-data="{ saved: false }"
         x-on:settings-saved.window="saved = true; clearTimeout($el._t); $el._t = setTimeout(() => saved = false, 2500)">
        <div x-show="saved" x-cloak x-transition
             class="mb-6 flex items-center gap-2 rounded-xl border border-success/20 bg-success/10 px-4 py-3 text-sm font-bold text-success">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
            Настройки сохранены
        </div>

        <form wire:submit="save" class="space-y-6">
            {{-- Main info --}}
            <div class="overflow-hidden rounded-2xl border border-content/[0.06] bg-content/[0.03] shadow-xl backdrop-blur-md">
                <div class="border-b border-content/[0.06] bg-content/[0.03] px-6 py-4">
                    <h3 class="text-sm font-bold text-content">Основная информация</h3>
                </div>
                <div class="grid gap-6 p-6 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label class="mb-1.5 block text-xs font-semibold text-content/50">Название салона</label>
                        <input type="text" wire:model="shop_name" placeholder="Blade Barbershop"
                               class="block w-full rounded-xl border border-content/[0.08] bg-content/[0.04] px-4 py-3 text-sm text-content placeholder-content/20 outline-none transition focus:border-brass/40 focus:ring-1 focus:ring-brass/20">
                        @error('shop_name') <p class="mt-1.5 text-xs text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs font-semibold text-content/50">Телефон</label>
                        <input type="tel" wire:model="phone" placeholder="998 90 123 45 67"
                               class="block w-full rounded-xl border border-content/[0.08] bg-content/[0.04] px-4 py-3 text-sm text-content placeholder-content/20 outline-none transition focus:border-brass/40 focus:ring-1 focus:ring-brass/20">
                        @error('phone') <p class="mt-1.5 text-xs text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs font-semibold text-content/50">Адрес</label>
                        <input type="text" wire:model="address" placeholder="г. Ташкент, ул. ..."
                               class="block w-full rounded-xl border border-content/[0.08] bg-content/[0.04] px-4 py-3 text-sm text-content placeholder-content/20 outline-none transition focus:border-brass/40 focus:ring-1 focus:ring-brass/20">
                        @error('address') <p class="mt-1.5 text-xs text-danger">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>

            {{-- Hours & socials --}}
            <div class="overflow-hidden rounded-2xl border border-content/[0.06] bg-content/[0.03] shadow-xl backdrop-blur-md">
                <div class="border-b border-content/[0.06] bg-content/[0.03] px-6 py-4">
                    <h3 class="text-sm font-bold text-content">Часы работы и соцсети</h3>
                </div>
                <div class="grid gap-6 p-6 sm:grid-cols-2">
                    <div>
                        <label class="mb-1.5 block text-xs font-semibold text-content/50">Начало работы</label>
                        <input type="time" wire:model="work_start"
                               class="block w-full rounded-xl border border-content/[0.08] bg-content/[0.04] px-4 py-3 text-sm text-content outline-none transition focus:border-brass/40 focus:ring-1 focus:ring-brass/20 dark:[color-scheme:dark]">
                        @error('work_start') <p class="mt-1.5 text-xs text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs font-semibold text-content/50">Конец работы</label>
                        <input type="time" wire:model="work_end"
                               class="block w-full rounded-xl border border-content/[0.08] bg-content/[0.04] px-4 py-3 text-sm text-content outline-none transition focus:border-brass/40 focus:ring-1 focus:ring-brass/20 dark:[color-scheme:dark]">
                        @error('work_end') <p class="mt-1.5 text-xs text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs font-semibold text-content/50">Instagram</label>
                        <input type="text" wire:model="instagram" placeholder="@blade.barbershop"
                               class="block w-full rounded-xl border border-content/[0.08] bg-content/[0.04] px-4 py-3 text-sm text-content placeholder-content/20 outline-none transition focus:border-brass/40 focus:ring-1 focus:ring-brass/20">
                        @error('instagram') <p class="mt-1.5 text-xs text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs font-semibold text-content/50">Telegram</label>
                        <input type="text" wire:model="telegram" placeholder="@blade_barbershop"
                               class="block w-full rounded-xl border border-content/[0.08] bg-content/[0.04] px-4 py-3 text-sm text-content placeholder-content/20 outline-none transition focus:border-brass/40 focus:ring-1 focus:ring-brass/20">
                        @error('telegram') <p class="mt-1.5 text-xs text-danger">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>

            <div class="flex items-center justify-end">
                <button type="submit"
                        class="rounded-xl bg-gradient-to-r from-brass to-brass px-6 py-2.5 text-sm font-bold text-black shadow-lg shadow-brass/20 transition-all hover:scale-[1.02] hover:shadow-brass/30 active:scale-[0.98]">
                    Сохранить настройки
                </button>
            </div>
        </form>
    </div>
</div>
