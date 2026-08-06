<?php

use App\Models\Barber;
use App\Models\Service;
use App\Models\Specialization;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new
#[Layout('components.layouts.app')]
class extends Component
{
    use WithFileUploads;

    public ?int $editingId = null;

    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('required|exists:specializations,id')]
    public ?int $specialization_id = null;

    #[Validate('nullable|integer|min:0')]
    public ?int $price = null;

    #[Validate('required|integer|min:0|max:100')]
    public int $salary_percent = 50;

    #[Validate('boolean')]
    public bool $is_active = true;

    #[Validate('required|array')]
    public array $schedule = [
        'mon' => ['09:00', '20:00'],
        'tue' => ['09:00', '20:00'],
        'wed' => ['09:00', '20:00'],
        'thu' => ['09:00', '20:00'],
        'fri' => ['09:00', '20:00'],
        'sat' => ['10:00', '18:00'],
        'sun' => ['10:00', '18:00'],
    ];

    #[Validate('nullable|image|max:2048')]
    public $photo;

    /** @var array<int, int|null> service_id => price */
    public array $servicePrices = [];

    public bool $showForm = false;

    /** Показывать ли деактивированные позиции: по умолчанию они спрятаны. */
    public bool $showInactive = false;

    #[Computed]
    public function barbers()
    {
        return Barber::query()
            ->with(['specialization', 'media'])
            ->when(! $this->showInactive, fn ($q) => $q->where('is_active', true))
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function specializations()
    {
        return Specialization::orderBy('name')->get();
    }

    #[Computed]
    public function allServices()
    {
        return Service::active()->ordered()->get();
    }

    public function openCreate(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $barber = Barber::with('services')->findOrFail($id);
        $this->editingId = $barber->id;
        $this->name = $barber->name;
        $this->specialization_id = $barber->specialization_id;
        $this->price = $barber->price;
        $this->salary_percent = $barber->salary_percent;
        $this->is_active = (bool) $barber->is_active;
        $this->schedule = $barber->schedule;
        $this->photo = null;
        $this->servicePrices = $barber->services
            ->pluck('pivot.price', 'id')
            ->map(fn ($p) => $p !== null ? (int) $p : null)
            ->toArray();
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->validate();

        $payload = [
            'name' => $this->name,
            'specialization_id' => $this->specialization_id,
            'price' => $this->price,
            'salary_percent' => $this->salary_percent,
            'is_active' => $this->is_active,
            'schedule' => $this->schedule,
        ];

        if ($this->editingId) {
            $barber = Barber::findOrFail($this->editingId);
            $barber->update($payload);
        } else {
            $barber = Barber::create($payload);
        }

        if ($this->photo) {
            $barber->addMedia($this->photo->getRealPath())
                ->usingFileName($this->photo->getClientOriginalName())
                ->toMediaCollection('photo');
        }

        $syncData = [];
        foreach ($this->servicePrices as $serviceId => $price) {
            $syncData[$serviceId] = ['price' => $price !== '' && $price !== null ? (int) $price : null];
        }
        $barber->services()->sync($syncData);

        unset($this->barbers);
        $this->resetForm();
        $this->showForm = false;
    }

    public function delete(int $id): void
    {
        Barber::findOrFail($id)->delete();
        unset($this->barbers);
    }

    public function cancel(): void
    {
        $this->resetForm();
        $this->showForm = false;
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'name', 'specialization_id', 'price', 'salary_percent', 'is_active', 'photo', 'servicePrices']);
        $this->salary_percent = 50;
        $this->is_active = true;
        $this->resetErrorBag();
    }
}; ?>

<div class="animate-fade-in-up">
    <div class="mb-8 flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="font-display text-4xl font-semibold uppercase tracking-tight text-content">{{ __('barbers.title') }}</h1>
            <p class="mt-1 text-sm text-content/40">{{ __('barbers.subtitle') }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-4">
            {{-- Деактивированные позиции никуда не деваются: их можно спрятать --}}
            <label class="relative inline-flex cursor-pointer items-center">
                <input type="checkbox" wire:model.live="showInactive" class="peer sr-only">
                <div class="h-6 w-11 rounded-full bg-content/10 transition-colors peer-checked:bg-brass after:absolute after:left-[2px] after:top-[2px] after:h-5 after:w-5 after:rounded-full after:bg-content after:transition-all peer-checked:after:translate-x-full"></div>
                <span class="ml-3 text-sm font-medium text-content/60">{{ __('common.show_inactive') }}</span>
            </label>
            <button type="button" wire:click="openCreate"
                    class="flex items-center gap-2 rounded-xl bg-gradient-to-r from-brass to-brass px-5 py-2.5 text-sm font-bold text-black shadow-lg shadow-brass/20 transition-all hover:scale-[1.02] hover:shadow-brass/30 active:scale-[0.98]">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                {{ __('barbers.add') }}
            </button>
        </div>
    </div>

    @if ($showForm)
        <div class="mb-8 overflow-hidden rounded-2xl border border-content/[0.06] bg-content/[0.03] shadow-xl backdrop-blur-md">
            <div class="border-b border-content/[0.06] bg-content/[0.03] px-6 py-4">
                <h3 class="text-sm font-bold text-content">{{ $editingId ? __('barbers.edit_title') : __('barbers.create_title') }}</h3>
            </div>
            <form wire:submit="save" class="p-6">
                <div class="grid gap-8 lg:grid-cols-3">
                    {{-- Photo & Basic info --}}
                    <div class="space-y-6 lg:col-span-2">
                        <div class="grid gap-6 sm:grid-cols-2">
                            <div class="sm:col-span-2">
                                <label class="mb-1.5 block text-xs font-semibold text-content/50">{{ __('common.name') }}</label>
                                <input type="text" wire:model="name" placeholder="{{ __('barbers.name_placeholder') }}"
                                       class="block w-full rounded-xl border border-content/[0.08] bg-content/[0.04] px-4 py-3 text-sm text-content placeholder-content/20 outline-none transition focus:border-brass/40 focus:ring-1 focus:ring-brass/20">
                                @error('name') <p class="mt-1.5 text-xs text-danger">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="mb-1.5 block text-xs font-semibold text-content/50">{{ __('barbers.specialization') }}</label>
                                <select wire:model="specialization_id"
                                        class="block w-full rounded-xl border border-content/[0.08] bg-content/[0.04] px-4 py-3 text-sm text-content outline-none transition focus:border-brass/40 focus:ring-1 focus:ring-brass/20 [&>option]:bg-surface-raised">
                                    <option value="">{{ __('common.select') }}</option>
                                    @foreach ($this->specializations as $spec)
                                        <option value="{{ $spec->id }}">{{ $spec->name }}</option>
                                    @endforeach
                                </select>
                                @error('specialization_id') <p class="mt-1.5 text-xs text-danger">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="mb-1.5 block text-xs font-semibold text-content/50">{{ __('barbers.salary_percent') }}</label>
                                <div class="relative">
                                    <input type="number" wire:model="salary_percent" min="0" max="100" placeholder="50"
                                           class="block w-full rounded-xl border border-content/[0.08] bg-content/[0.04] px-4 py-3 pr-10 text-sm text-content outline-none transition focus:border-brass/40 focus:ring-1 focus:ring-brass/20">
                                    <span class="pointer-events-none absolute right-4 top-1/2 -translate-y-1/2 text-sm font-bold text-content/30">%</span>
                                </div>
                                @error('salary_percent') <p class="mt-1.5 text-xs text-danger">{{ $message }}</p> @enderror
                            </div>

                            @if ($this->allServices->isNotEmpty())
                                <div class="sm:col-span-2">
                                    <label class="mb-2 block text-xs font-semibold text-content/50">{{ __('barbers.service_prices') }} ({{ __('common.currency') }})</label>
                                    <div class="grid gap-2 sm:grid-cols-2">
                                        @foreach ($this->allServices as $service)
                                            <div class="flex items-center gap-3 rounded-xl border border-content/[0.06] bg-content/[0.02] px-4 py-2.5">
                                                <span class="min-w-0 flex-1 truncate text-sm text-content/60">{{ $service->name }}</span>
                                                <input
                                                    type="number"
                                                    wire:model="servicePrices.{{ $service->id }}"
                                                    placeholder="{{ $price ?? '—' }}"
                                                    class="w-28 shrink-0 rounded-lg border border-content/[0.08] bg-content/[0.04] px-3 py-1.5 text-right text-sm text-content outline-none transition focus:border-brass/40 focus:ring-1 focus:ring-brass/20"
                                                >
                                            </div>
                                        @endforeach
                                    </div>
                                    <p class="mt-1.5 text-[11px] text-content/25">{{ __('barbers.default_price_hint') }}</p>
                                </div>
                            @endif
                            <div class="flex items-center pt-4">
                                <label class="relative inline-flex cursor-pointer items-center">
                                    <input type="checkbox" wire:model="is_active" class="peer sr-only">
                                    <div class="h-6 w-11 rounded-full bg-content/10 transition-colors peer-checked:bg-brass after:absolute after:left-[2px] after:top-[2px] after:h-5 after:w-5 after:rounded-full after:bg-content after:transition-all peer-checked:after:translate-x-full"></div>
                                    <span class="ml-3 text-sm font-medium text-content/70">{{ __('common.active') }}</span>
                                </label>
                            </div>
                        </div>

                        <div>
                            <label class="mb-3 block text-xs font-semibold text-content/50">{{ __('barbers.schedule') }}</label>
                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                @foreach (['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as $key)
                                    @php($label = __('common.weekday.'.$key))
                                    <div class="flex items-center gap-2 rounded-xl bg-content/[0.02] p-3 ring-1 ring-content/[0.06]">
                                        <span class="w-8 text-xs font-bold text-content/40">{{ $label }}</span>
                                        <div class="flex flex-1 items-center gap-1.5">
                                            <input type="text" wire:model="schedule.{{ $key }}.0" placeholder="09:00"
                                                   class="w-full bg-transparent text-center text-xs text-content outline-none">
                                            <span class="text-content/20">—</span>
                                            <input type="text" wire:model="schedule.{{ $key }}.1" placeholder="20:00"
                                                   class="w-full bg-transparent text-center text-xs text-content outline-none">
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>

                    {{-- Photo Upload --}}
                    <div>
                        <label class="mb-3 block text-xs font-semibold text-content/50">{{ __('barbers.photo') }}</label>
                        <div class="relative aspect-square overflow-hidden rounded-2xl border-2 border-dashed border-content/10 bg-content/[0.02] transition hover:border-brass/30">
                            @if ($photo)
                                <img src="{{ $photo->temporaryUrl() }}" class="h-full w-full object-cover">
                            @elseif ($editingId && $barber = Barber::find($editingId))
                                @if ($barber->photoUrl)
                                    <img src="{{ $barber->photoUrl }}" class="h-full w-full object-cover">
                                @else
                                    <div class="flex h-full w-full items-center justify-center text-5xl font-bold text-content/5">
                                        {{ mb_substr($barber->name, 0, 1) }}
                                    </div>
                                @endif
                            @else
                                <div class="flex h-full w-full flex-col items-center justify-center p-6 text-center">
                                    <svg class="mb-3 h-10 w-10 text-content/10" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 0 1 5.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 0 0-1.134-.175 2.31 2.31 0 0 1-1.64-1.055l-.822-1.316a2.192 2.192 0 0 0-1.736-1.039 48.774 48.774 0 0 0-5.232 0 2.192 2.192 0 0 0-1.736 1.039l-.821 1.316Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0ZM18.75 10.5h.008v.008h-.008V10.5Z" /></svg>
                                    <p class="text-xs text-content/30">{{ __('barbers.upload_hint') }}</p>
                                </div>
                            @endif
                            <input type="file" wire:model="photo" class="absolute inset-0 cursor-pointer opacity-0">
                        </div>
                        @error('photo') <p class="mt-2 text-xs text-danger">{{ $message }}</p> @enderror
                        <div wire:loading wire:target="photo" class="mt-2 text-xs text-brass-ink">{{ __('common.loading') }}</div>
                    </div>
                </div>

                <div class="mt-10 flex items-center justify-end gap-3 border-t border-content/[0.06] pt-6">
                    <button type="button" wire:click="cancel"
                            class="rounded-xl border border-content/[0.08] px-5 py-2.5 text-sm font-bold text-content/60 transition hover:bg-content/[0.06] hover:text-content">
                        {{ __('common.cancel') }}
                    </button>
                    <button type="submit"
                            class="rounded-xl bg-brass px-6 py-2.5 text-sm font-bold text-black transition-all hover:bg-brass-bright active:scale-[0.98]">
                        {{ $editingId ? __('common.save_changes') : __('barbers.add') }}
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
                        <th class="px-6 py-4">{{ __('common.barber') }}</th>
                        <th class="hidden px-6 py-4 sm:table-cell">{{ __('barbers.specialization') }}</th>
                        <th class="hidden px-6 py-4 sm:table-cell">{{ __('barbers.salary_percent_short') }}</th>
                        <th class="hidden px-6 py-4 sm:table-cell">{{ __('common.status') }}</th>
                        <th class="px-6 py-4 text-right">{{ __('common.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-content/[0.04]">
                    @forelse ($this->barbers as $barber)
                        <tr class="transition-colors hover:bg-content/[0.02]">
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-3">
                                    @if ($barber->photoUrl)
                                        <img src="{{ $barber->photoUrl }}" alt="{{ $barber->name }}" class="h-10 w-10 rounded-full object-cover ring-1 ring-content/10">
                                    @else
                                        <div class="flex h-10 w-10 items-center justify-center rounded-full bg-brass/15 text-xs font-bold text-brass-ink ring-1 ring-content/10">
                                            {{ mb_substr($barber->name, 0, 1) }}
                                        </div>
                                    @endif
                                    <div>
                                        <div class="font-bold text-content">{{ $barber->name }}</div>
                                        <div class="text-[10px] text-content/30 sm:hidden">{{ $barber->specialization?->name }}</div>
                                    </div>
                                </div>
                            </td>
                            <td class="hidden px-6 py-4 text-content/50 sm:table-cell">
                                {{ $barber->specialization?->name ?: '—' }}
                            </td>
                            <td class="hidden px-6 py-4 sm:table-cell">
                                <span class="inline-flex items-center rounded-full bg-content/[0.06] px-2.5 py-1 text-xs font-bold text-content/60">
                                    {{ $barber->salary_percent }}%
                                </span>
                            </td>
                            <td class="hidden px-6 py-4 sm:table-cell">
                                @if ($barber->is_active)
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-success/10 px-2.5 py-1 text-xs font-bold text-success">
                                        <span class="h-1.5 w-1.5 rounded-full bg-success"></span>
                                        {{ __('common.active') }}
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-content/10 px-2.5 py-1 text-xs font-bold text-content/30">
                                        <span class="h-1.5 w-1.5 rounded-full bg-content/30"></span>
                                        {{ __('common.inactive') }}
                                    </span>
                                @endif
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex items-center justify-end gap-2">
                                    <button type="button" wire:click="edit({{ $barber->id }})"
                                            class="flex h-9 w-9 items-center justify-center rounded-lg border border-content/[0.06] text-content/40 transition hover:border-content/10 hover:text-content">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L6.832 19.82a4.5 4.5 0 0 1-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 0 1 1.13-1.897L16.863 4.487Zm0 0L19.5 7.125" /></svg>
                                    </button>
                                    <button type="button" wire:click="delete({{ $barber->id }})"
                                            wire:confirm="{{ __('barbers.delete_confirm', ['name' => $barber->name]) }}"
                                            class="flex h-9 w-9 items-center justify-center rounded-lg border border-content/[0.06] text-danger/50 transition hover:border-danger/20 hover:text-danger">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" /></svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-12 text-center text-content/20">{{ __('barbers.empty') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
