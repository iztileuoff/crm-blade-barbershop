<?php

use App\Models\Specialization;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new
#[Layout('components.layouts.app')]
class extends Component
{
    public ?int $editingId = null;

    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('nullable|string|max:255')]
    public string $description = '';

    public bool $showForm = false;

    #[Computed]
    public function specializations()
    {
        return Specialization::query()
            ->withCount('barbers')
            ->orderBy('name')
            ->get();
    }

    public function openCreate(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $specialization = Specialization::findOrFail($id);
        $this->editingId = $specialization->id;
        $this->name = $specialization->name;
        $this->description = (string) $specialization->description;
        $this->showForm = true;
    }

    public function save(): void
    {
        $rules = $this->rules();
        $rules['name'] .= '|unique:specializations,name'.($this->editingId ? ','.$this->editingId : '');

        $data = $this->validate($rules);

        if ($this->editingId) {
            Specialization::findOrFail($this->editingId)->update($data);
        } else {
            Specialization::create($data);
        }

        unset($this->specializations);
        $this->resetForm();
        $this->showForm = false;
    }

    public function delete(int $id): void
    {
        Specialization::findOrFail($id)->delete();
        unset($this->specializations);
    }

    public function cancel(): void
    {
        $this->resetForm();
        $this->showForm = false;
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'name', 'description']);
        $this->resetErrorBag();
    }
}; ?>

<div>
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-3xl font-bold tracking-tight">Специализации</h1>
        <button type="button" wire:click="openCreate"
                class="rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white hover:bg-zinc-800">
            Добавить
        </button>
    </div>

    @if ($showForm)
        <form wire:submit="save"
              class="mb-6 grid gap-4 rounded-xl border border-zinc-200 bg-white p-5 shadow-sm sm:grid-cols-2">
            <div>
                <label class="block text-sm font-medium text-zinc-700">Название</label>
                <input type="text" wire:model="name"
                       class="mt-1 w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm">
                @error('name') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-zinc-700">Описание</label>
                <input type="text" wire:model="description"
                       class="mt-1 w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm">
                @error('description') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
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

    <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-zinc-200 text-sm">
            <thead class="bg-zinc-50">
                <tr class="text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">
                    <th class="px-4 py-3">Название</th>
                    <th class="px-4 py-3">Описание</th>
                    <th class="px-4 py-3">Мастеров</th>
                    <th class="px-4 py-3 text-right">Действия</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100">
                @forelse ($this->specializations as $specialization)
                    <tr>
                        <td class="px-4 py-3 font-medium">{{ $specialization->name }}</td>
                        <td class="px-4 py-3 text-zinc-600">{{ $specialization->description ?: '—' }}</td>
                        <td class="px-4 py-3 text-zinc-600">{{ $specialization->barbers_count }}</td>
                        <td class="px-4 py-3">
                            <div class="flex items-center justify-end gap-2">
                                <button type="button" wire:click="edit({{ $specialization->id }})"
                                        class="rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-xs font-medium text-zinc-700 hover:bg-zinc-50">
                                    Изменить
                                </button>
                                <button type="button" wire:click="delete({{ $specialization->id }})"
                                        wire:confirm="Удалить специализацию?"
                                        class="rounded-lg bg-rose-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-rose-700">
                                    Удалить
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-4 py-10 text-center text-zinc-500">Специализаций пока нет.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
