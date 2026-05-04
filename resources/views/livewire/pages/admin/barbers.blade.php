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

    #[Validate('nullable|exists:specializations,id')]
    public ?int $specialization_id = null;

    #[Validate('boolean')]
    public bool $is_active = true;

    #[Validate('nullable|image|max:4096')]
    public $photo = null;

    public bool $removePhoto = false;

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
        return Specialization::query()->orderBy('name')->get();
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
        $this->photo = null;
        $this->removePhoto = false;
        $this->showForm = true;
    }

    public function save(): void
    {
        $data = $this->validate();

        $barber = $this->editingId
            ? Barber::findOrFail($this->editingId)
            : new Barber;

        $barber->fill([
            'name' => $data['name'],
            'specialization_id' => $data['specialization_id'] ?: null,
            'is_active' => $data['is_active'],
        ])->save();

        if ($this->removePhoto && ! $this->photo) {
            $barber->clearMediaCollection('photo');
        }

        if ($this->photo) {
            $barber->clearMediaCollection('photo');
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
        $this->reset(['editingId', 'name', 'specialization_id', 'is_active', 'photo', 'removePhoto']);
        $this->is_active = true;
        $this->resetErrorBag();
    }
}; ?>

<div>
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-3xl font-bold tracking-tight">Мастера</h1>
        <button type="button" wire:click="openCreate"
                class="rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white hover:bg-zinc-800">
            Добавить
        </button>
    </div>

    @if ($showForm)
        <form wire:submit="save" enctype="multipart/form-data"
              class="mb-6 grid gap-4 rounded-xl border border-zinc-200 bg-white p-5 shadow-sm sm:grid-cols-2">
            <div>
                <label class="block text-sm font-medium text-zinc-700">Имя</label>
                <input type="text" wire:model="name"
                       class="mt-1 w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm">
                @error('name') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-zinc-700">Специализация</label>
                <select wire:model="specialization_id"
                        class="mt-1 w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm">
                    <option value="">— Не выбрана —</option>
                    @foreach ($this->specializations as $specialization)
                        <option value="{{ $specialization->id }}">{{ $specialization->name }}</option>
                    @endforeach
                </select>
                @error('specialization_id') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>

            <div class="sm:col-span-2">
                <label class="block text-sm font-medium text-zinc-700">Фото</label>
                <div class="mt-1 flex items-center gap-4">
                    @if ($photo)
                        <img src="{{ $photo->temporaryUrl() }}" alt="" class="h-16 w-16 rounded-full object-cover">
                    @elseif ($editingId && ! $removePhoto)
                        @php($current = \App\Models\Barber::find($editingId)?->getFirstMediaUrl('photo'))
                        @if ($current)
                            <img src="{{ $current }}" alt="" class="h-16 w-16 rounded-full object-cover">
                        @endif
                    @endif

                    <input type="file" wire:model="photo" accept="image/*"
                           class="block w-full text-sm text-zinc-700 file:mr-3 file:rounded-lg file:border-0 file:bg-zinc-900 file:px-3 file:py-2 file:text-sm file:font-medium file:text-white hover:file:bg-zinc-800">

                    @if ($editingId)
                        <label class="inline-flex items-center gap-2 text-sm text-zinc-600">
                            <input type="checkbox" wire:model="removePhoto" class="rounded border-zinc-300">
                            Удалить фото
                        </label>
                    @endif
                </div>
                @error('photo') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>

            <div class="sm:col-span-2 flex items-center gap-3">
                <label class="inline-flex items-center gap-2 text-sm text-zinc-700">
                    <input type="checkbox" wire:model="is_active" class="rounded border-zinc-300">
                    Активен
                </label>
            </div>

            <div class="sm:col-span-2 flex items-center justify-end gap-2">
                <button type="button" wire:click="cancel"
                        class="rounded-lg border border-zinc-200 bg-white px-4 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-50">
                    Отмена
                </button>
                <button type="submit"
                        class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700">
                    {{ $editingId ? 'Сохранить' : 'Создать' }}
                </button>
            </div>
        </form>
    @endif

    <div class="overflow-x-auto rounded-xl border border-zinc-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-zinc-200 text-sm">
            <thead class="bg-zinc-50">
                <tr class="text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">
                    <th class="px-3 py-3 sm:px-4">Фото</th>
                    <th class="px-3 py-3 sm:px-4">Имя</th>
                    <th class="hidden px-3 py-3 sm:table-cell sm:px-4">Специализация</th>
                    <th class="hidden px-3 py-3 sm:table-cell sm:px-4">Статус</th>
                    <th class="px-3 py-3 text-right sm:px-4">Действия</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100">
                @forelse ($this->barbers as $barber)
                    <tr>
                        <td class="px-3 py-3 sm:px-4">
                            @php($url = $barber->getFirstMediaUrl('photo'))
                            @if ($url)
                                <img src="{{ $url }}" alt="{{ $barber->name }}"
                                     class="h-9 w-9 rounded-full object-cover sm:h-10 sm:w-10">
                            @else
                                <div class="flex h-9 w-9 items-center justify-center rounded-full bg-zinc-100 text-xs font-semibold text-zinc-400 sm:h-10 sm:w-10">
                                    {{ mb_strtoupper(mb_substr($barber->name, 0, 1)) }}
                                </div>
                            @endif
                        </td>
                        <td class="px-3 py-3 sm:px-4">
                            <div class="font-medium">{{ $barber->name }}</div>
                            <div class="text-xs text-zinc-500 sm:hidden">{{ $barber->specialization?->name ?: '—' }}</div>
                            @if (! $barber->is_active)
                                <span class="mt-0.5 inline-flex rounded-full bg-zinc-100 px-1.5 py-0.5 text-[10px] font-medium text-zinc-600 sm:hidden">Неактивен</span>
                            @endif
                        </td>
                        <td class="hidden px-3 py-3 text-zinc-600 sm:table-cell sm:px-4">{{ $barber->specialization?->name ?: '—' }}</td>
                        <td class="hidden px-3 py-3 sm:table-cell sm:px-4">
                            @if ($barber->is_active)
                                <span class="inline-flex rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-800">Активен</span>
                            @else
                                <span class="inline-flex rounded-full bg-zinc-100 px-2 py-0.5 text-xs font-medium text-zinc-700">Неактивен</span>
                            @endif
                        </td>
                        <td class="px-3 py-3 sm:px-4">
                            <div class="flex items-center justify-end gap-1.5 sm:gap-2">
                                <button type="button" wire:click="edit({{ $barber->id }})"
                                        class="rounded-lg border border-zinc-200 bg-white px-2.5 py-1.5 text-xs font-medium text-zinc-700 hover:bg-zinc-50 sm:px-3">
                                    Изменить
                                </button>
                                <button type="button" wire:click="delete({{ $barber->id }})"
                                        wire:confirm="Удалить мастера?"
                                        class="rounded-lg bg-rose-600 px-2.5 py-1.5 text-xs font-medium text-white hover:bg-rose-700 sm:px-3">
                                    Удалить
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-10 text-center text-zinc-500">Мастеров пока нет.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
