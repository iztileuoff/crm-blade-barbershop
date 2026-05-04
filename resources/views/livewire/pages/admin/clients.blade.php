<?php

use App\Models\Client;
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

    #[Validate('required|string')]
    public string $phone = '';

    public string $search = '';

    public bool $showForm = false;

    #[Computed]
    public function clients()
    {
        return Client::query()
            ->when($this->search !== '', function ($query) {
                $term = '%'.$this->search.'%';
                $query->where(function ($q) use ($term) {
                    $q->where('name', 'like', $term)
                        ->orWhere('phone', 'like', $term);
                });
            })
            ->orderByDesc('id')
            ->limit(100)
            ->get();
    }

    public function openCreate(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $client = Client::findOrFail($id);
        $this->editingId = $client->id;
        $this->name = $client->name;
        $this->phone = $client->formattedPhone ?: $client->phone;
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->validate();

        $normalized = Client::normalizePhone($this->phone);
        if ($normalized === null) {
            $this->addError('phone', 'Телефон должен быть в формате 998XXXXXXXXX.');

            return;
        }

        $duplicate = Client::query()
            ->where('phone', $normalized)
            ->when($this->editingId, fn ($q) => $q->where('id', '!=', $this->editingId))
            ->exists();

        if ($duplicate) {
            $this->addError('phone', 'Клиент с таким номером уже существует.');

            return;
        }

        $payload = ['name' => $this->name, 'phone' => $normalized];

        if ($this->editingId) {
            Client::findOrFail($this->editingId)->update($payload);
        } else {
            Client::create($payload);
        }

        unset($this->clients);
        $this->resetForm();
        $this->showForm = false;
    }

    public function delete(int $id): void
    {
        Client::findOrFail($id)->delete();
        unset($this->clients);
    }

    public function cancel(): void
    {
        $this->resetForm();
        $this->showForm = false;
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'name', 'phone']);
        $this->resetErrorBag();
    }
}; ?>

<div>
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-3xl font-bold tracking-tight">Клиенты</h1>
        <div class="flex items-center gap-2">
            <input type="text" wire:model.live.debounce.300ms="search" placeholder="Поиск..."
                   class="rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm">
            <button type="button" wire:click="openCreate"
                    class="rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white hover:bg-zinc-800">
                Добавить
            </button>
        </div>
    </div>

    @if ($showForm)
        <form wire:submit="save"
              class="mb-6 grid gap-4 rounded-xl border border-zinc-200 bg-white p-5 shadow-sm sm:grid-cols-2">
            <div>
                <label class="block text-sm font-medium text-zinc-700">Имя</label>
                <input type="text" wire:model="name"
                       class="mt-1 w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm">
                @error('name') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-zinc-700">Телефон</label>
                <input type="text" wire:model="phone" placeholder="+998 90 123 45 67"
                       class="mt-1 w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm">
                @error('phone') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
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
                    <th class="px-3 py-3 sm:px-4">Имя</th>
                    <th class="px-3 py-3 sm:px-4">Телефон</th>
                    <th class="hidden px-3 py-3 sm:table-cell sm:px-4">Последний визит</th>
                    <th class="px-3 py-3 text-right sm:px-4">Действия</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100">
                @forelse ($this->clients as $client)
                    <tr>
                        <td class="px-3 py-3 font-medium sm:px-4">{{ $client->name }}</td>
                        <td class="whitespace-nowrap px-3 py-3 sm:px-4">{{ $client->formattedPhone }}</td>
                        <td class="hidden px-3 py-3 text-zinc-600 sm:table-cell sm:px-4">
                            {{ $client->last_visit_at?->translatedFormat('d MMMM Y') ?: '—' }}
                        </td>
                        <td class="px-3 py-3 sm:px-4">
                            <div class="flex items-center justify-end gap-1.5 sm:gap-2">
                                <button type="button" wire:click="edit({{ $client->id }})"
                                        class="rounded-lg border border-zinc-200 bg-white px-2.5 py-1.5 text-xs font-medium text-zinc-700 hover:bg-zinc-50 sm:px-3">
                                    Изменить
                                </button>
                                <button type="button" wire:click="delete({{ $client->id }})"
                                        wire:confirm="Удалить клиента?"
                                        class="rounded-lg bg-rose-600 px-2.5 py-1.5 text-xs font-medium text-white hover:bg-rose-700 sm:px-3">
                                    Удалить
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-4 py-10 text-center text-zinc-500">Клиентов не найдено.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
