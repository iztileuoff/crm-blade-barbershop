<?php

use App\Models\Barber;
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

    public bool $showForm = false;

    #[Computed]
    public function barbers()
    {
        return Barber::query()
            ->with(['specialization', 'media'])
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function specializations()
    {
        return Specialization::orderBy('name')->get();
    }

    public function openCreate(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $barber = Barber::findOrFail($id);
        $this->editingId = $barber->id;
        $this->name = $barber->name;
        $this->specialization_id = $barber->specialization_id;
        $this->is_active = (bool) $barber->is_active;
        $this->schedule = $barber->schedule;
        $this->photo = null;
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->validate();

        $payload = [
            'name' => $this->name,
            'specialization_id' => $this->specialization_id,
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
        $this->reset(['editingId', 'name', 'specialization_id', 'is_active', 'photo']);
        $this->is_active = true;
        $this->resetErrorBag();
    }
}; ?>

<div class="animate-fade-in-up">
    <div class="mb-8 flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="text-3xl font-extrabold tracking-tight text-white">Мастера</h1>
            <p class="mt-1 text-sm text-white/40">Управление командой и графиком работы</p>
        </div>
        <button type="button" wire:click="openCreate"
                class="flex items-center gap-2 rounded-xl bg-gradient-to-r from-amber-400 to-amber-600 px-5 py-2.5 text-sm font-bold text-black shadow-lg shadow-amber-500/20 transition-all hover:scale-[1.02] hover:shadow-amber-500/30 active:scale-[0.98]">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
            Добавить мастера
        </button>
    </div>

    @if ($showForm)
        <div class="mb-8 overflow-hidden rounded-2xl border border-white/[0.06] bg-white/[0.03] shadow-xl backdrop-blur-md">
            <div class="border-b border-white/[0.06] bg-white/[0.03] px-6 py-4">
                <h3 class="text-sm font-bold text-white">{{ $editingId ? 'Изменение мастера' : 'Новый мастер' }}</h3>
            </div>
            <form wire:submit="save" class="p-6">
                <div class="grid gap-8 lg:grid-cols-3">
                    {{-- Photo & Basic info --}}
                    <div class="space-y-6 lg:col-span-2">
                        <div class="grid gap-6 sm:grid-cols-2">
                            <div class="sm:col-span-2">
                                <label class="mb-1.5 block text-xs font-semibold text-white/50">Имя</label>
                                <input type="text" wire:model="name" placeholder="Имя мастера..."
                                       class="block w-full rounded-xl border border-white/[0.08] bg-white/[0.04] px-4 py-3 text-sm text-white placeholder-white/20 outline-none transition focus:border-amber-500/40 focus:ring-1 focus:ring-amber-500/20">
                                @error('name') <p class="mt-1.5 text-xs text-rose-400">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="mb-1.5 block text-xs font-semibold text-white/50">Специализация</label>
                                <select wire:model="specialization_id"
                                        class="block w-full rounded-xl border border-white/[0.08] bg-white/[0.04] px-4 py-3 text-sm text-white outline-none transition focus:border-amber-500/40 focus:ring-1 focus:ring-amber-500/20 [&>option]:bg-[#121212]">
                                    <option value="">Выберите...</option>
                                    @foreach ($this->specializations as $spec)
                                        <option value="{{ $spec->id }}">{{ $spec->name }}</option>
                                    @endforeach
                                </select>
                                @error('specialization_id') <p class="mt-1.5 text-xs text-rose-400">{{ $message }}</p> @enderror
                            </div>
                            <div class="flex items-center pt-4">
                                <label class="relative inline-flex cursor-pointer items-center">
                                    <input type="checkbox" wire:model="is_active" class="peer sr-only">
                                    <div class="h-6 w-11 rounded-full bg-white/10 transition-colors peer-checked:bg-amber-500 after:absolute after:left-[2px] after:top-[2px] after:h-5 after:w-5 after:rounded-full after:bg-white after:transition-all peer-checked:after:translate-x-full"></div>
                                    <span class="ml-3 text-sm font-medium text-white/70">Активен</span>
                                </label>
                            </div>
                        </div>

                        <div>
                            <label class="mb-3 block text-xs font-semibold text-white/50">График работы</label>
                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                @foreach (['mon' => 'Пн', 'tue' => 'Вт', 'wed' => 'Ср', 'thu' => 'Чт', 'fri' => 'Пт', 'sat' => 'Сб', 'sun' => 'Вс'] as $key => $label)
                                    <div class="flex items-center gap-2 rounded-xl bg-white/[0.02] p-3 ring-1 ring-white/[0.06]">
                                        <span class="w-8 text-xs font-bold text-white/40">{{ $label }}</span>
                                        <div class="flex flex-1 items-center gap-1.5">
                                            <input type="text" wire:model="schedule.{{ $key }}.0" placeholder="09:00"
                                                   class="w-full bg-transparent text-center text-xs text-white outline-none">
                                            <span class="text-white/20">—</span>
                                            <input type="text" wire:model="schedule.{{ $key }}.1" placeholder="20:00"
                                                   class="w-full bg-transparent text-center text-xs text-white outline-none">
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>

                    {{-- Photo Upload --}}
                    <div>
                        <label class="mb-3 block text-xs font-semibold text-white/50">Фотография</label>
                        <div class="relative aspect-square overflow-hidden rounded-2xl border-2 border-dashed border-white/10 bg-white/[0.02] transition hover:border-amber-500/30">
                            @if ($photo)
                                <img src="{{ $photo->temporaryUrl() }}" class="h-full w-full object-cover">
                            @elseif ($editingId && $barber = Barber::find($editingId))
                                @if ($barber->photoUrl)
                                    <img src="{{ $barber->photoUrl }}" class="h-full w-full object-cover">
                                @else
                                    <div class="flex h-full w-full items-center justify-center text-5xl font-bold text-white/5">
                                        {{ mb_substr($barber->name, 0, 1) }}
                                    </div>
                                @endif
                            @else
                                <div class="flex h-full w-full flex-col items-center justify-center p-6 text-center">
                                    <svg class="mb-3 h-10 w-10 text-white/10" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 0 1 5.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 0 0-1.134-.175 2.31 2.31 0 0 1-1.64-1.055l-.822-1.316a2.192 2.192 0 0 0-1.736-1.039 48.774 48.774 0 0 0-5.232 0 2.192 2.192 0 0 0-1.736 1.039l-.821 1.316Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0ZM18.75 10.5h.008v.008h-.008V10.5Z" /></svg>
                                    <p class="text-xs text-white/30">Нажмите для загрузки (PNG, JPG до 2MB)</p>
                                </div>
                            @endif
                            <input type="file" wire:model="photo" class="absolute inset-0 cursor-pointer opacity-0">
                        </div>
                        @error('photo') <p class="mt-2 text-xs text-rose-400">{{ $message }}</p> @enderror
                        <div wire:loading wire:target="photo" class="mt-2 text-xs text-amber-400">Загрузка...</div>
                    </div>
                </div>

                <div class="mt-10 flex items-center justify-end gap-3 border-t border-white/[0.06] pt-6">
                    <button type="button" wire:click="cancel"
                            class="rounded-xl border border-white/[0.08] px-5 py-2.5 text-sm font-bold text-white/60 transition hover:bg-white/[0.06] hover:text-white">
                        Отмена
                    </button>
                    <button type="submit"
                            class="rounded-xl bg-amber-500 px-6 py-2.5 text-sm font-bold text-black transition-all hover:bg-amber-400 active:scale-[0.98]">
                        {{ $editingId ? 'Сохранить изменения' : 'Добавить мастера' }}
                    </button>
                </div>
            </form>
        </div>
    @endif

    <div class="overflow-hidden rounded-2xl border border-white/[0.06] bg-white/[0.03] shadow-xl backdrop-blur-md">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-white/[0.06] bg-white/[0.03] text-xs font-bold uppercase tracking-wider text-white/30">
                        <th class="px-6 py-4">Мастер</th>
                        <th class="hidden px-6 py-4 sm:table-cell">Специализация</th>
                        <th class="hidden px-6 py-4 sm:table-cell">Статус</th>
                        <th class="px-6 py-4 text-right">Действия</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/[0.04]">
                    @forelse ($this->barbers as $barber)
                        <tr class="transition-colors hover:bg-white/[0.02]">
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-3">
                                    @if ($barber->photoUrl)
                                        <img src="{{ $barber->photoUrl }}" class="h-10 w-10 rounded-full object-cover ring-1 ring-white/10">
                                    @else
                                        <div class="flex h-10 w-10 items-center justify-center rounded-full bg-amber-500/15 text-xs font-bold text-amber-400 ring-1 ring-white/10">
                                            {{ mb_substr($barber->name, 0, 1) }}
                                        </div>
                                    @endif
                                    <div>
                                        <div class="font-bold text-white">{{ $barber->name }}</div>
                                        <div class="text-[10px] text-white/30 sm:hidden">{{ $barber->specialization?->name }}</div>
                                    </div>
                                </div>
                            </td>
                            <td class="hidden px-6 py-4 text-white/50 sm:table-cell">
                                {{ $barber->specialization?->name ?: '—' }}
                            </td>
                            <td class="hidden px-6 py-4 sm:table-cell">
                                @if ($barber->is_active)
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-500/10 px-2.5 py-1 text-xs font-bold text-emerald-400">
                                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-400"></span>
                                        Активен
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-white/10 px-2.5 py-1 text-xs font-bold text-white/30">
                                        <span class="h-1.5 w-1.5 rounded-full bg-white/30"></span>
                                        Неактивен
                                    </span>
                                @endif
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex items-center justify-end gap-2">
                                    <button type="button" wire:click="edit({{ $barber->id }})"
                                            class="flex h-9 w-9 items-center justify-center rounded-lg border border-white/[0.06] text-white/40 transition hover:border-white/10 hover:text-white">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L6.832 19.82a4.5 4.5 0 0 1-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 0 1 1.13-1.897L16.863 4.487Zm0 0L19.5 7.125" /></svg>
                                    </button>
                                    <button type="button" wire:click="delete({{ $barber->id }})"
                                            wire:confirm="Удалить мастера «{{ $barber->name }}»?"
                                            class="flex h-9 w-9 items-center justify-center rounded-lg border border-white/[0.06] text-rose-500/50 transition hover:border-rose-500/20 hover:text-rose-500">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" /></svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-6 py-12 text-center text-white/20">Мастера пока не добавлены</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
