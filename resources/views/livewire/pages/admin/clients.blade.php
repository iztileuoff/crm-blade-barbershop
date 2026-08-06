<?php

use App\Models\Client;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new
#[Layout('components.layouts.app')]
class extends Component
{
    use WithPagination;

    public ?int $editingId = null;

    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('required|string')]
    public string $phone = '';

    #[Validate('nullable|date')]
    public string $birth_date = '';

    public string $search = '';

    public bool $showForm = false;

    #[Computed]
    public function clients()
    {
        return Client::query()
            ->with(['latestAppointment.barber'])
            ->search($this->search)
            ->orderByDesc('id')
            ->paginate(25);
    }

    #[Computed]
    public function totalClients(): int
    {
        return Client::count();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
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
        $this->birth_date = $client->birth_date?->format('Y-m-d') ?? '';
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->validate();

        $normalized = Client::normalizePhone($this->phone);
        if ($normalized === null) {
            $this->addError('phone', __('clients.err_phone_format'));

            return;
        }

        $duplicate = Client::query()
            ->where('phone', $normalized)
            ->when($this->editingId, fn ($q) => $q->where('id', '!=', $this->editingId))
            ->exists();

        if ($duplicate) {
            $this->addError('phone', __('clients.err_duplicate'));

            return;
        }

        $payload = [
            'name' => $this->name,
            'phone' => $normalized,
            'birth_date' => $this->birth_date ?: null,
        ];

        if ($this->editingId) {
            Client::findOrFail($this->editingId)->update($payload);
        } else {
            Client::create($payload);
        }

        unset($this->clients);
        $this->resetForm();
        $this->showForm = false;
        $this->dispatch('saved');
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
        $this->reset(['editingId', 'name', 'phone', 'birth_date']);
        $this->resetErrorBag();
    }
}; ?>

<div class="animate-fade-in-up">
    <div class="mb-8 flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="font-display text-4xl font-semibold uppercase tracking-tight text-content">{{ __('clients.title') }}</h1>
            <p class="mt-1 text-sm text-content/40">{{ __('clients.subtitle') }} · {{ __('clients.total_label') }}: <span class="font-bold text-content/70">{{ $this->totalClients }}</span></p>
        </div>
        <div class="flex items-center gap-3">
            <div class="relative">
                <svg class="absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-content/20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" /></svg>
                <input type="text" wire:model.live.debounce.300ms="search" placeholder="{{ __('clients.search_placeholder') }}"
                       class="w-64 rounded-xl border border-content/[0.08] bg-content/[0.04] py-2.5 pl-10 pr-4 text-sm text-content placeholder-content/20 outline-none transition focus:border-brass/40 focus:ring-1 focus:ring-brass/20">
            </div>
            <button type="button" wire:click="openCreate"
                    class="flex items-center gap-2 rounded-xl bg-gradient-to-r from-brass to-brass px-5 py-2.5 text-sm font-bold text-black shadow-lg shadow-brass/20 transition-all hover:scale-[1.02] hover:shadow-brass/30 active:scale-[0.98]">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                {{ __('common.add') }}
            </button>
        </div>
    </div>

    @if ($showForm)
        <div class="mb-8 overflow-hidden rounded-2xl border border-content/[0.06] bg-content/[0.03] shadow-xl backdrop-blur-md">
            <div class="border-b border-content/[0.06] bg-content/[0.03] px-6 py-4">
                <h3 class="text-sm font-bold text-content">{{ $editingId ? __('clients.edit_title') : __('clients.create_title') }}</h3>
            </div>
            <form wire:submit="save" class="p-6">
                <div class="grid gap-6 sm:grid-cols-3">
                    <div>
                        <label class="mb-1.5 block text-xs font-semibold text-content/50">{{ __('common.name') }}</label>
                        <input type="text" wire:model="name" placeholder="{{ __('clients.name_placeholder') }}"
                               class="block w-full rounded-xl border border-content/[0.08] bg-content/[0.04] px-4 py-3 text-sm text-content placeholder-content/20 outline-none transition focus:border-brass/40 focus:ring-1 focus:ring-brass/20">
                        @error('name') <p class="mt-1.5 text-xs text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs font-semibold text-content/50">{{ __('common.phone') }}</label>
                        <input type="text" wire:model="phone" placeholder="+998 90 123 45 67"
                               class="block w-full rounded-xl border border-content/[0.08] bg-content/[0.04] px-4 py-3 text-sm text-content placeholder-content/20 outline-none transition focus:border-brass/40 focus:ring-1 focus:ring-brass/20">
                        @error('phone') <p class="mt-1.5 text-xs text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs font-semibold text-content/50">{{ __('common.birth_date') }}</label>
                        <input type="date" wire:model="birth_date"
                               class="block w-full rounded-xl border border-content/[0.08] bg-content/[0.04] px-4 py-3 text-sm text-content outline-none transition focus:border-brass/40 focus:ring-1 focus:ring-brass/20 dark:[color-scheme:dark]">
                        @error('birth_date') <p class="mt-1.5 text-xs text-danger">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="mt-8 flex items-center justify-end gap-3 border-t border-content/[0.06] pt-6">
                    <button type="button" wire:click="cancel"
                            class="rounded-xl border border-content/[0.08] px-5 py-2.5 text-sm font-bold text-content/60 transition hover:bg-content/[0.06] hover:text-content">
                        {{ __('common.cancel') }}
                    </button>
                    <x-submit-button>{{ $editingId ? __('common.save_changes') : __('clients.create') }}</x-submit-button>
                </div>
            </form>
        </div>
    @endif

    <div class="overflow-hidden rounded-2xl border border-content/[0.06] bg-content/[0.03] shadow-xl backdrop-blur-md">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-content/[0.06] bg-content/[0.03] text-xs font-bold uppercase tracking-wider text-content/30">
                        <th class="px-6 py-4">{{ __('common.client') }}</th>
                        <th class="px-6 py-4">{{ __('common.phone') }}</th>
                        <th class="hidden px-6 py-4 sm:table-cell">{{ __('common.birth_date') }}</th>
                        <th class="hidden px-6 py-4 sm:table-cell">{{ __('clients.last_appointment') }}</th>
                        <th class="px-6 py-4 text-right">{{ __('common.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-content/[0.04]">
                    @forelse ($this->clients as $client)
                        <tr class="transition-colors hover:bg-content/[0.02]">
                            <td class="px-6 py-4">
                                <a href="{{ route('admin.clients.show', $client) }}" wire:navigate
                                   class="font-bold text-content transition hover:text-brass-ink">{{ $client->name }}</a>
                            </td>
                            <td class="whitespace-nowrap px-6 py-4 font-medium text-brass-ink/60">{{ $client->formattedPhone }}</td>
                            <td class="hidden px-6 py-4 text-content/40 sm:table-cell">
                                {{ $client->formattedBirthDate }}
                            </td>
                            <td class="hidden px-6 py-4 sm:table-cell">
                                @if ($client->latestAppointment)
                                    <div class="text-content/60">{{ $client->latestAppointment->starts_at->format('d.m.Y H:i') }}</div>
                                    <div class="text-[10px] text-content/30">{{ $client->latestAppointment->barber?->name ?? '—' }}</div>
                                @else
                                    <span class="text-content/40">—</span>
                                @endif
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex items-center justify-end gap-2">
                                    <button type="button" wire:click="edit({{ $client->id }})"
                                            class="flex h-9 w-9 items-center justify-center rounded-lg border border-content/[0.06] text-content/40 transition hover:border-content/10 hover:text-content">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L6.832 19.82a4.5 4.5 0 0 1-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 0 1 1.13-1.897L16.863 4.487Zm0 0L19.5 7.125" /></svg>
                                    </button>
                                    <button type="button" wire:click="delete({{ $client->id }})"
                                            wire:confirm="{{ __('clients.delete_confirm', ['name' => $client->name]) }}"
                                            class="flex h-9 w-9 items-center justify-center rounded-lg border border-content/[0.06] text-danger/50 transition hover:border-danger/20 hover:text-danger">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" /></svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-12 text-center text-content/20">{{ __('clients.empty') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-6">
        {{ $this->clients->links() }}
    </div>
</div>
