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

        // Zero-padded HH:MM compares correctly as a string.
        if ($this->work_start !== '' && $this->work_end !== '' && $this->work_end <= $this->work_start) {
            $this->addError('work_end', __('settings.err_work_end_before_start'));

            return;
        }

        Setting::set('shop_name', $this->shop_name ?: null);
        Setting::set('shop_phone', $this->phone ?: null);
        Setting::set('shop_address', $this->address ?: null);
        Setting::set('work_start', $this->work_start ?: null);
        Setting::set('work_end', $this->work_end ?: null);
        Setting::set('instagram', $this->instagram ?: null);
        Setting::set('telegram', $this->telegram ?: null);

        $this->dispatch('saved', message: __('settings.saved'));
    }
}; ?>

<x-slot:title>{{ __('settings.page_title') }}</x-slot:title>

<div class="animate-fade-in-up">
    <div class="mb-8 flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="font-display text-4xl font-semibold uppercase tracking-tight text-content">{{ __('settings.title') }}</h1>
            <p class="mt-1 text-sm text-content/40">{{ __('settings.subtitle') }}</p>
        </div>
    </div>

    <form wire:submit="save" class="space-y-6">
        {{-- Main info --}}
        <div class="overflow-hidden rounded-2xl border border-content/[0.06] bg-content/[0.03] shadow-xl backdrop-blur-md">
            <div class="border-b border-content/[0.06] bg-content/[0.03] px-6 py-4">
                <h3 class="text-sm font-bold text-content">{{ __('settings.main_info') }}</h3>
            </div>
            <div class="grid gap-6 p-6 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label class="mb-1.5 block text-xs font-semibold text-content/50">{{ __('settings.shop_name') }}</label>
                    <input type="text" wire:model="shop_name" placeholder="Blade Barbershop"
                           class="block w-full rounded-xl border border-content/[0.08] bg-content/[0.04] px-4 py-3 text-sm text-content placeholder-content/20 outline-none transition focus:border-brass/40 focus:ring-1 focus:ring-brass/20">
                    @error('shop_name') <p class="mt-1.5 text-xs text-danger">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1.5 block text-xs font-semibold text-content/50">{{ __('common.phone') }}</label>
                    <input type="tel" wire:model="phone" placeholder="998 90 123 45 67"
                           class="block w-full rounded-xl border border-content/[0.08] bg-content/[0.04] px-4 py-3 text-sm text-content placeholder-content/20 outline-none transition focus:border-brass/40 focus:ring-1 focus:ring-brass/20">
                    @error('phone') <p class="mt-1.5 text-xs text-danger">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1.5 block text-xs font-semibold text-content/50">{{ __('settings.address') }}</label>
                    <input type="text" wire:model="address" placeholder="{{ __('settings.address_placeholder') }}"
                           class="block w-full rounded-xl border border-content/[0.08] bg-content/[0.04] px-4 py-3 text-sm text-content placeholder-content/20 outline-none transition focus:border-brass/40 focus:ring-1 focus:ring-brass/20">
                    @error('address') <p class="mt-1.5 text-xs text-danger">{{ $message }}</p> @enderror
                </div>
            </div>
        </div>

        {{-- Hours & socials --}}
        <div class="overflow-hidden rounded-2xl border border-content/[0.06] bg-content/[0.03] shadow-xl backdrop-blur-md">
            <div class="border-b border-content/[0.06] bg-content/[0.03] px-6 py-4">
                <h3 class="text-sm font-bold text-content">{{ __('settings.hours_socials') }}</h3>
            </div>
            <div class="grid gap-6 p-6 sm:grid-cols-2">
                <div>
                    <label class="mb-1.5 block text-xs font-semibold text-content/50">{{ __('settings.work_start') }}</label>
                    <input type="time" wire:model="work_start"
                           class="block w-full rounded-xl border border-content/[0.08] bg-content/[0.04] px-4 py-3 text-sm text-content outline-none transition focus:border-brass/40 focus:ring-1 focus:ring-brass/20 dark:[color-scheme:dark]">
                    @error('work_start') <p class="mt-1.5 text-xs text-danger">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1.5 block text-xs font-semibold text-content/50">{{ __('settings.work_end') }}</label>
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
            <x-submit-button>{{ __('settings.save') }}</x-submit-button>
        </div>
    </form>
</div>
