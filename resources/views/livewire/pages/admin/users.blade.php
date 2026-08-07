<?php

use App\Enums\Role;
use App\Models\Barber;
use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
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

    public string $password = '';

    #[Validate('required|string')]
    public string $role = 'admin';

    public ?int $barberId = null;

    public bool $showForm = false;

    public function mount()
    {
        abort_if(! auth()->user()->isSuperAdmin(), 403, __('users.access_denied'));
    }

    #[Computed]
    public function users()
    {
        return User::with('barber')->orderBy('name')->get();
    }

    /**
     * Barbers that are unlinked, or already linked to the user being edited.
     */
    #[Computed]
    public function availableBarbers()
    {
        return Barber::query()
            ->where(function ($query) {
                $query->whereNull('user_id')
                    ->when($this->editingId, fn ($q) => $q->orWhere('user_id', $this->editingId));
            })
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
        $user = User::findOrFail($id);
        $this->editingId = $user->id;
        $this->name = $user->name;
        $this->phone = $user->phone;
        $this->role = $user->role->value;
        $this->barberId = $user->barber?->id;
        $this->password = '';
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'phone' => ['required', 'string', Rule::unique('users', 'phone')->ignore($this->editingId)],
            'role' => 'required|in:admin,super_admin,barber',
            'barberId' => [
                Rule::requiredIf($this->role === Role::BARBER->value),
                'nullable',
                Rule::exists('barbers', 'id')->where(function ($query) {
                    $query->where(function ($q) {
                        $q->whereNull('user_id')
                            ->when($this->editingId, fn ($inner) => $inner->orWhere('user_id', $this->editingId));
                    });
                }),
            ],
            'password' => $this->editingId ? 'nullable|min:6' : 'required|min:6',
        ]);

        $normalizedPhone = Client::normalizePhone($this->phone) ?? $this->phone;

        $payload = [
            'name' => $this->name,
            'phone' => $normalizedPhone,
            'role' => Role::from($this->role),
        ];

        if ($this->password) {
            $payload['password'] = Hash::make($this->password);
        }

        if ($this->editingId) {
            $user = User::findOrFail($this->editingId);
            $user->update($payload);
        } else {
            $user = User::create($payload);
        }

        $this->syncBarberLink($user);

        unset($this->users);
        $this->resetForm();
        $this->showForm = false;

        $this->dispatch('saved');
    }

    /**
     * Link the chosen barber to this user when the barber role is selected,
     * and clear any previous link otherwise.
     */
    private function syncBarberLink(User $user): void
    {
        Barber::where('user_id', $user->id)->update(['user_id' => null]);

        if ($user->role === Role::BARBER && $this->barberId) {
            Barber::whereKey($this->barberId)->update(['user_id' => $user->id]);
        }
    }

    public function delete(int $id): void
    {
        $user = User::findOrFail($id);
        if ($user->id === auth()->id()) {
            return; // Can't delete self
        }
        $user->delete();
        unset($this->users);
    }

    public function cancel(): void
    {
        $this->resetForm();
        $this->showForm = false;
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'name', 'phone', 'role', 'password', 'barberId']);
        $this->role = 'admin';
        $this->resetErrorBag();
    }
}; ?>

<x-slot:title>{{ __('users.page_title') }}</x-slot:title>

<div class="animate-fade-in-up">
    <div class="mb-8 flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="font-display text-4xl font-semibold uppercase tracking-tight text-content">{{ __('users.title') }}</h1>
            <p class="mt-1 text-sm text-content/40">{{ __('users.subtitle') }}</p>
        </div>
        <button type="button" wire:click="openCreate"
                class="flex items-center gap-2 rounded-xl bg-gradient-to-r from-brass to-brass px-5 py-2.5 text-sm font-bold text-black shadow-lg shadow-brass/20 transition-all hover:scale-[1.02] hover:shadow-brass/30 active:scale-[0.98]">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
            {{ __('users.add') }}
        </button>
    </div>

    @if ($showForm)
        <div class="mb-8 overflow-hidden rounded-2xl border border-content/[0.06] bg-content/[0.03] shadow-xl backdrop-blur-md">
            <div class="border-b border-content/[0.06] bg-content/[0.03] px-6 py-4">
                <h3 class="text-sm font-bold text-content">{{ $editingId ? __('users.edit_title') : __('users.create_title') }}</h3>
            </div>
            <form wire:submit="save" class="p-6">
                <div class="grid gap-6 sm:grid-cols-2">
                    <div>
                        <label for="user-name" class="mb-1.5 block text-xs font-semibold text-content/50">{{ __('common.name') }}</label>
                        <input type="text" id="user-name" wire:model="name" placeholder="{{ __('users.name_placeholder') }}"
                               x-data x-init="$nextTick(() => $el.focus())"
                               class="block w-full rounded-xl border border-content/[0.08] bg-content/[0.04] px-4 py-3 text-sm text-content placeholder-content/20 outline-none transition focus:border-brass/40 focus:ring-1 focus:ring-brass/20">
                        @error('name') <p class="mt-1.5 text-xs text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="user-phone" class="mb-1.5 block text-xs font-semibold text-content/50">{{ __('common.phone') }}</label>
                        <input type="tel" id="user-phone" wire:model="phone" placeholder="998 90 123 45 67"
                               class="block w-full rounded-xl border border-content/[0.08] bg-content/[0.04] px-4 py-3 text-sm text-content placeholder-content/20 outline-none transition focus:border-brass/40 focus:ring-1 focus:ring-brass/20">
                        @error('phone') <p class="mt-1.5 text-xs text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="user-role" class="mb-1.5 block text-xs font-semibold text-content/50">{{ __('users.role') }}</label>
                        <select id="user-role" wire:model.live="role"
                                class="block w-full rounded-xl border border-content/[0.08] bg-content/[0.04] px-4 py-3 text-sm text-content outline-none transition focus:border-brass/40 focus:ring-1 focus:ring-brass/20 [&>option]:bg-surface-raised">
                            <option value="admin">{{ __('enums.role.admin') }}</option>
                            <option value="super_admin">{{ __('enums.role.super_admin') }}</option>
                            <option value="barber">{{ __('enums.role.barber') }}</option>
                        </select>
                        @error('role') <p class="mt-1.5 text-xs text-danger">{{ $message }}</p> @enderror
                    </div>

                    @if ($role === 'barber')
                        <div>
                            <label for="user-barber-id" class="mb-1.5 block text-xs font-semibold text-content/50">{{ __('users.barber_profile') }}</label>
                            <select id="user-barber-id" wire:model="barberId"
                                    class="block w-full rounded-xl border border-content/[0.08] bg-content/[0.04] px-4 py-3 text-sm text-content outline-none transition focus:border-brass/40 focus:ring-1 focus:ring-brass/20 [&>option]:bg-surface-raised">
                                <option value="">{{ __('users.select_barber') }}</option>
                                @foreach ($this->availableBarbers as $barber)
                                    <option value="{{ $barber->id }}">{{ $barber->name }}</option>
                                @endforeach
                            </select>
                            <p class="mt-1.5 text-[11px] text-content-muted">{{ __('users.barber_hint') }}</p>
                            @error('barberId') <p class="mt-1.5 text-xs text-danger">{{ $message }}</p> @enderror
                        </div>
                    @endif
                    <div>
                        <label for="user-password" class="mb-1.5 block text-xs font-semibold text-content/50">{{ __('common.password') }} {{ $editingId ? __('users.password_hint') : '' }}</label>
                        <input type="password" id="user-password" wire:model="password" placeholder="••••••••"
                               class="block w-full rounded-xl border border-content/[0.08] bg-content/[0.04] px-4 py-3 text-sm text-content placeholder-content/20 outline-none transition focus:border-brass/40 focus:ring-1 focus:ring-brass/20">
                        @error('password') <p class="mt-1.5 text-xs text-danger">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="mt-8 flex items-center justify-end gap-3 border-t border-content/[0.06] pt-6">
                    <button type="button" wire:click="cancel"
                            class="rounded-xl border border-content/[0.08] px-5 py-2.5 text-sm font-bold text-content/60 transition hover:bg-content/[0.06] hover:text-content">
                        {{ __('common.cancel') }}
                    </button>
                    <x-submit-button>
                        {{ $editingId ? __('common.save_changes') : __('users.create') }}
                    </x-submit-button>
                </div>
            </form>
        </div>
    @endif

    <div class="overflow-hidden rounded-2xl border border-content/[0.06] bg-content/[0.03] shadow-xl backdrop-blur-md">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-content/[0.06] bg-content/[0.03] text-xs font-bold uppercase tracking-wider text-content-muted">
                        <th class="px-6 py-4">{{ __('common.name') }}</th>
                        <th class="px-6 py-4">{{ __('common.phone') }}</th>
                        <th class="px-6 py-4">{{ __('users.role') }}</th>
                        <th class="px-6 py-4 text-right">{{ __('common.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-content/[0.04]">
                    @forelse ($this->users as $user)
                        <tr wire:key="user-{{ $user->id }}" class="transition-colors hover:bg-content/[0.02]">
                            <td class="px-6 py-4 font-bold text-content">{{ $user->name }}</td>
                            <td class="px-6 py-4 text-brass-ink/60">{{ App\Models\Client::formatPhone($user->phone) }}</td>
                            <td class="px-6 py-4">
                                <span @class([
                                    'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-bold',
                                    'bg-brass/10 text-brass-ink' => $user->isSuperAdmin(),
                                    'bg-content/10 text-content/40' => ! $user->isSuperAdmin(),
                                ])>
                                    {{ $user->role->label() }}
                                </span>
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex items-center justify-end gap-2">
                                    <button type="button" wire:click="edit({{ $user->id }})"
                                            title="{{ __('common.edit') }}" aria-label="{{ __('common.edit') }}"
                                            class="flex h-9 w-9 items-center justify-center rounded-lg border border-content/[0.06] text-content/40 transition hover:border-content/10 hover:text-content focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-brass/40">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L6.832 19.82a4.5 4.5 0 0 1-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 0 1 1.13-1.897L16.863 4.487Zm0 0L19.5 7.125" /></svg>
                                    </button>
                                    @if ($user->id !== auth()->id())
                                        <button type="button" wire:click="delete({{ $user->id }})"
                                                wire:confirm="{{ __('users.delete_confirm', ['name' => $user->name]) }}"
                                                title="{{ __('common.delete') }}" aria-label="{{ __('common.delete') }}"
                                                class="flex h-9 w-9 items-center justify-center rounded-lg border border-content/[0.06] text-danger/50 transition hover:border-danger/20 hover:text-danger focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-danger/40">
                                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" /></svg>
                                        </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-6 py-12 text-center text-content-muted">{{ __('users.empty') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
