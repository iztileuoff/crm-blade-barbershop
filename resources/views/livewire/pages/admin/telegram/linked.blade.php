<?php

use App\Enums\Role;
use App\Models\Client;
use App\Models\User;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new
#[Layout('components.layouts.app')]
class extends Component
{
    #[Computed]
    public function linked()
    {
        $clients = Client::query()
            ->whereNotNull('telegram_chat_id')
            ->get()
            ->map(fn (Client $client) => [
                'type' => 'client',
                'label' => 'Клиент',
                'id' => $client->id,
                'name' => $client->name,
                'phone' => Client::formatPhone((string) $client->phone),
                'chat_id' => $client->telegram_chat_id,
            ]);

        $barbers = User::query()
            ->where('role', Role::BARBER)
            ->whereNotNull('telegram_chat_id')
            ->get()
            ->map(fn (User $user) => [
                'type' => 'barber',
                'label' => 'Мастер',
                'id' => $user->id,
                'name' => $user->name,
                'phone' => Client::formatPhone((string) $user->phone),
                'chat_id' => $user->telegram_chat_id,
            ]);

        return $clients->concat($barbers)->sortBy('name')->values();
    }

    public function unlinkClient(int $id): void
    {
        Client::whereKey($id)->update(['telegram_chat_id' => null]);
        unset($this->linked);
    }

    public function unlinkBarber(int $id): void
    {
        User::whereKey($id)->update(['telegram_chat_id' => null]);
        unset($this->linked);
    }
}; ?>

<div class="animate-fade-in-up">
    <div class="mb-8">
        <h1 class="font-display text-4xl font-semibold uppercase tracking-tight text-content">Telegram · Привязанные</h1>
        <p class="mt-1 text-sm text-content/40">Клиенты и мастера с подключённым Telegram · Всего: <span class="font-bold text-content/70">{{ $this->linked->count() }}</span></p>
    </div>

    <div class="overflow-hidden rounded-2xl border border-content/[0.06] bg-content/[0.03] shadow-xl backdrop-blur-md">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-content/[0.06] bg-content/[0.03] text-xs font-bold uppercase tracking-wider text-content/30">
                        <th class="px-6 py-4">Имя</th>
                        <th class="px-6 py-4">Тип</th>
                        <th class="hidden px-6 py-4 sm:table-cell">Телефон</th>
                        <th class="hidden px-6 py-4 sm:table-cell">Chat ID</th>
                        <th class="px-6 py-4 text-right">Действия</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-content/[0.04]">
                    @forelse ($this->linked as $row)
                        <tr class="transition-colors hover:bg-content/[0.02]">
                            <td class="px-6 py-4 font-bold text-content">{{ $row['name'] }}</td>
                            <td class="px-6 py-4">
                                @if ($row['type'] === 'barber')
                                    <span class="inline-flex items-center rounded-full bg-brass/10 px-2.5 py-1 text-xs font-bold text-brass-ink">{{ $row['label'] }}</span>
                                @else
                                    <span class="inline-flex items-center rounded-full bg-content/[0.06] px-2.5 py-1 text-xs font-bold text-content/60">{{ $row['label'] }}</span>
                                @endif
                            </td>
                            <td class="hidden whitespace-nowrap px-6 py-4 text-brass-ink/60 sm:table-cell">{{ $row['phone'] }}</td>
                            <td class="hidden whitespace-nowrap px-6 py-4 font-mono text-content/40 sm:table-cell">{{ $row['chat_id'] }}</td>
                            <td class="px-6 py-4">
                                <div class="flex items-center justify-end">
                                    <button type="button"
                                            wire:click="{{ $row['type'] === 'barber' ? 'unlinkBarber' : 'unlinkClient' }}({{ $row['id'] }})"
                                            wire:confirm="Отвязать Telegram у «{{ $row['name'] }}»?"
                                            class="flex items-center gap-1.5 rounded-lg border border-content/[0.06] px-3 py-2 text-xs font-bold text-danger/60 transition hover:border-danger/20 hover:text-danger">
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 10.5 21 3m0 0h-5.25M21 3v5.25M10.5 6H6.75A2.25 2.25 0 0 0 4.5 8.25v9a2.25 2.25 0 0 0 2.25 2.25h9a2.25 2.25 0 0 0 2.25-2.25V13.5" /></svg>
                                        Отвязать
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-12 text-center text-content/20">Нет привязанных пользователей</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
