<?php

use App\Models\Service;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new
#[Layout('components.layouts.app')]
class extends Component
{
    public ?int $editingId = null;

    public string $name_ru = '';

    public string $name_uz = '';

    public string $name_kaa = '';

    public string $icon = Service::DEFAULT_ICON;

    public int $duration_minutes = 30;

    public bool $is_active = true;

    public bool $showForm = false;

    #[Computed]
    public function services()
    {
        return Service::query()
            ->withCount('appointments')
            ->ordered()
            ->get();
    }

    public function openCreate(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $service = Service::findOrFail($id);
        $translations = $service->translations;

        $this->editingId = $service->id;
        $this->name_ru = $translations['ru'] ?? '';
        $this->name_uz = $translations['uz'] ?? '';
        $this->name_kaa = $translations['kaa'] ?? '';
        $this->icon = in_array($service->icon, Service::ICONS, true) ? $service->icon : Service::DEFAULT_ICON;
        $this->duration_minutes = (int) $service->duration_minutes;
        $this->is_active = (bool) $service->is_active;
        $this->showForm = true;
    }

    public function save(): void
    {
        $data = $this->validate([
            'name_ru' => ['required', 'string', 'max:255'],
            'name_uz' => ['required', 'string', 'max:255'],
            'name_kaa' => ['required', 'string', 'max:255'],
            'icon' => ['required', 'in:'.implode(',', Service::ICONS)],
            'duration_minutes' => ['required', 'integer', 'min:5'],
            'is_active' => ['boolean'],
        ], attributes: [
            'name_ru' => __('services.name_ru'),
            'name_uz' => __('services.name_uz'),
            'name_kaa' => __('services.name_kaa'),
            'icon' => __('services.icon'),
            'duration_minutes' => __('services.duration'),
        ]);

        $payload = [
            'name' => Service::encodeTranslations([
                'ru' => $data['name_ru'],
                'uz' => $data['name_uz'],
                'kaa' => $data['name_kaa'],
            ]),
            'icon' => $data['icon'],
            'duration_minutes' => $data['duration_minutes'],
            'is_active' => $data['is_active'],
        ];

        if ($this->editingId) {
            Service::findOrFail($this->editingId)->update($payload);
        } else {
            Service::create($payload);
        }

        unset($this->services);
        $this->resetForm();
        $this->showForm = false;
    }

    public function delete(int $id): void
    {
        Service::findOrFail($id)->delete();
        unset($this->services);
    }

    public function cancel(): void
    {
        $this->resetForm();
        $this->showForm = false;
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'name_ru', 'name_uz', 'name_kaa', 'icon', 'duration_minutes', 'is_active']);
        $this->icon = Service::DEFAULT_ICON;
        $this->duration_minutes = 30;
        $this->is_active = true;
        $this->resetErrorBag();
    }
}; ?>

<div class="animate-fade-in-up">
    <div class="mb-8 flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="font-display text-4xl font-semibold uppercase tracking-tight text-content">{{ __('common.services') }}</h1>
            <p class="mt-1 text-sm text-content/40">{{ __('services.subtitle') }}</p>
        </div>
        <button type="button" wire:click="openCreate"
                class="flex items-center gap-2 rounded-xl bg-gradient-to-r from-brass to-brass px-5 py-2.5 text-sm font-bold text-black shadow-lg shadow-brass/20 transition-all hover:scale-[1.02] hover:shadow-brass/30 active:scale-[0.98]">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
            {{ __('services.add') }}
        </button>
    </div>

    @if ($showForm)
        <div class="mb-8 overflow-hidden rounded-2xl border border-content/[0.06] bg-content/[0.03] shadow-xl backdrop-blur-md">
            <div class="border-b border-content/[0.06] bg-content/[0.03] px-6 py-4">
                <h3 class="text-sm font-bold text-content">{{ $editingId ? __('services.edit_title') : __('services.create_title') }}</h3>
            </div>
            <form wire:submit="save" class="p-6">
                <div class="grid gap-6 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label class="mb-1.5 block text-xs font-semibold text-content/50">{{ __('services.name_ru') }}</label>
                        <input type="text" wire:model="name_ru" placeholder="{{ __('services.name_placeholder') }}"
                               class="block w-full rounded-xl border border-content/[0.08] bg-content/[0.04] px-4 py-3 text-sm text-content placeholder-content/20 outline-none transition focus:border-brass/40 focus:ring-1 focus:ring-brass/20">
                        @error('name_ru') <p class="mt-1.5 text-xs text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div class="sm:col-span-2">
                        <label class="mb-1.5 block text-xs font-semibold text-content/50">{{ __('services.name_uz') }}</label>
                        <input type="text" wire:model="name_uz" placeholder="{{ __('services.name_placeholder') }}"
                               class="block w-full rounded-xl border border-content/[0.08] bg-content/[0.04] px-4 py-3 text-sm text-content placeholder-content/20 outline-none transition focus:border-brass/40 focus:ring-1 focus:ring-brass/20">
                        @error('name_uz') <p class="mt-1.5 text-xs text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div class="sm:col-span-2">
                        <label class="mb-1.5 block text-xs font-semibold text-content/50">{{ __('services.name_kaa') }}</label>
                        <input type="text" wire:model="name_kaa" placeholder="{{ __('services.name_placeholder') }}"
                               class="block w-full rounded-xl border border-content/[0.08] bg-content/[0.04] px-4 py-3 text-sm text-content placeholder-content/20 outline-none transition focus:border-brass/40 focus:ring-1 focus:ring-brass/20">
                        @error('name_kaa') <p class="mt-1.5 text-xs text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div class="sm:col-span-2">
                        <label class="mb-1.5 block text-xs font-semibold text-content/50">{{ __('services.icon') }}</label>
                        <div class="flex flex-wrap gap-2.5">
                            @foreach (\App\Models\Service::ICONS as $iconKey)
                                <button type="button" wire:click="$set('icon', '{{ $iconKey }}')"
                                        @class([
                                            'flex h-12 w-12 items-center justify-center rounded-xl border transition',
                                            'border-brass bg-brass/15 text-brass-ink' => $icon === $iconKey,
                                            'border-content/[0.08] bg-content/[0.04] text-content/40 hover:border-content/20 hover:text-content/70' => $icon !== $iconKey,
                                        ])>
                                    <x-service-icon :name="$iconKey" class="h-5 w-5" />
                                </button>
                            @endforeach
                        </div>
                        @error('icon') <p class="mt-1.5 text-xs text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs font-semibold text-content/50">{{ __('services.duration') }}</label>
                        <input type="number" wire:model="duration_minutes" min="5" max="480" step="5"
                               class="block w-full rounded-xl border border-content/[0.08] bg-content/[0.04] px-4 py-3 text-sm text-content outline-none transition focus:border-brass/40 focus:ring-1 focus:ring-brass/20">
                        @error('duration_minutes') <p class="mt-1.5 text-xs text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex items-center pt-4">
                        <label class="relative inline-flex cursor-pointer items-center">
                            <input type="checkbox" wire:model="is_active" class="peer sr-only">
                            <div class="h-6 w-11 rounded-full bg-content/10 transition-colors peer-checked:bg-brass after:absolute after:left-[2px] after:top-[2px] after:h-5 after:after:w-5 after:rounded-full after:bg-content after:transition-all peer-checked:after:translate-x-full"></div>
                            <span class="ml-3 text-sm font-medium text-content/70">{{ __('services.active') }}</span>
                        </label>
                    </div>
                </div>
                <div class="mt-8 flex items-center justify-end gap-3 border-t border-content/[0.06] pt-6">
                    <button type="button" wire:click="cancel"
                            class="rounded-xl border border-content/[0.08] px-5 py-2.5 text-sm font-bold text-content/60 transition hover:bg-content/[0.06] hover:text-content">
                        {{ __('common.cancel') }}
                    </button>
                    <button type="submit"
                            class="rounded-xl bg-brass px-6 py-2.5 text-sm font-bold text-black transition-all hover:bg-brass-bright active:scale-[0.98]">
                        {{ $editingId ? __('common.save_changes') : __('services.create') }}
                    </button>
                </div>
            </form>
        </div>
    @endif

    <div class="overflow-hidden rounded-2xl border border-content/[0.06] bg-content/[0.03] shadow-xl backdrop-blur-md">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-content/[0.06] bg-content/[0.03] text-xs font-bold uppercase tracking-wider text-content/30">
                        <th class="px-6 py-4">{{ __('common.service') }}</th>
                        <th class="px-6 py-4">{{ __('services.duration_short') }}</th>
                        <th class="hidden px-6 py-4 sm:table-cell">{{ __('common.status') }}</th>
                        <th class="hidden px-6 py-4 sm:table-cell">{{ __('services.appointments_short') }}</th>
                        <th class="px-6 py-4 text-right">{{ __('common.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-content/[0.04]">
                    @forelse ($this->services as $service)
                        <tr class="transition-colors hover:bg-content/[0.02]">
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-3">
                                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brass/10">
                                        <x-service-icon :name="$service->icon" class="h-5 w-5 text-brass-ink" />
                                    </div>
                                    <div>
                                        <div class="font-bold text-content">{{ $service->name }}</div>
                                        @if (! $service->is_active)
                                            <span class="mt-1 inline-flex rounded-full bg-content/10 px-2 py-0.5 text-[10px] font-bold text-content/40 sm:hidden">{{ __('services.inactive') }}</span>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-content/50">{{ $service->duration_minutes }} {{ __('common.minutes_short') }}</td>
                            <td class="hidden px-6 py-4 sm:table-cell">
                                @if ($service->is_active)
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-success/10 px-2.5 py-1 text-xs font-bold text-success">
                                        <span class="h-1.5 w-1.5 rounded-full bg-success"></span>
                                        {{ __('services.active') }}
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-content/10 px-2.5 py-1 text-xs font-bold text-content/30">
                                        <span class="h-1.5 w-1.5 rounded-full bg-content/30"></span>
                                        {{ __('services.inactive') }}
                                    </span>
                                @endif
                            </td>
                            <td class="hidden px-6 py-4 text-content/30 sm:table-cell">{{ $service->appointments_count }}</td>
                            <td class="px-6 py-4">
                                <div class="flex items-center justify-end gap-2">
                                    <button type="button" wire:click="edit({{ $service->id }})"
                                            class="flex h-9 w-9 items-center justify-center rounded-lg border border-content/[0.06] text-content/40 transition hover:border-content/10 hover:text-content">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L6.832 19.82a4.5 4.5 0 0 1-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 0 1 1.13-1.897L16.863 4.487Zm0 0L19.5 7.125" /></svg>
                                    </button>
                                    <button type="button" wire:click="delete({{ $service->id }})"
                                            wire:confirm="{{ __('services.delete_confirm', ['name' => $service->name]) }}"
                                            class="flex h-9 w-9 items-center justify-center rounded-lg border border-content/[0.06] text-danger/50 transition hover:border-danger/20 hover:text-danger">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" /></svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-6 py-12 text-center text-content/20">{{ __('services.empty') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
