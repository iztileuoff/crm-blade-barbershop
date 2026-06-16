<?php

use App\Models\SmsMessage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new
#[Layout('components.layouts.app')]
class extends Component
{
    use WithPagination;

    public string $status = '';

    public string $context = '';

    /** @return array<string, string> */
    public function contextLabels(): array
    {
        return [
            'reminder' => 'Напоминание',
            'retention' => 'Удержание',
            'broadcast' => 'Рассылка',
            'manual' => 'Вручную',
        ];
    }

    #[Computed]
    public function messages()
    {
        return SmsMessage::query()
            ->with('client')
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->when($this->context !== '', fn ($q) => $q->where('context', $this->context))
            ->latest()
            ->paginate(25);
    }

    #[Computed]
    public function sentCount(): int
    {
        return SmsMessage::where('status', 'sent')->count();
    }

    #[Computed]
    public function failedCount(): int
    {
        return SmsMessage::where('status', 'failed')->count();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedContext(): void
    {
        $this->resetPage();
    }
}; ?>

<div class="animate-fade-in-up">
    <div class="mb-8 flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="font-display text-4xl font-semibold uppercase tracking-tight text-content">SMS · История</h1>
            <p class="mt-1 text-sm text-content/40">
                Отправлено: <span class="font-bold text-success">{{ $this->sentCount }}</span>
                · Ошибок: <span class="font-bold text-danger">{{ $this->failedCount }}</span>
            </p>
        </div>
        <div class="flex items-center gap-3">
            <select wire:model.live="context"
                    class="rounded-xl border border-content/[0.08] bg-content/[0.04] px-4 py-2.5 text-sm text-content outline-none transition focus:border-brass/40 focus:ring-1 focus:ring-brass/20 dark:[color-scheme:dark]">
                <option value="">Все типы</option>
                @foreach($this->contextLabels() as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
            <select wire:model.live="status"
                    class="rounded-xl border border-content/[0.08] bg-content/[0.04] px-4 py-2.5 text-sm text-content outline-none transition focus:border-brass/40 focus:ring-1 focus:ring-brass/20 dark:[color-scheme:dark]">
                <option value="">Все статусы</option>
                <option value="sent">Отправлено</option>
                <option value="failed">Ошибка</option>
            </select>
        </div>
    </div>

    <div class="overflow-hidden rounded-2xl border border-content/[0.06] bg-content/[0.03] shadow-xl backdrop-blur-md">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-content/[0.06] bg-content/[0.03] text-xs font-bold uppercase tracking-wider text-content/30">
                        <th class="px-6 py-4">Получатель</th>
                        <th class="px-6 py-4">Сообщение</th>
                        <th class="hidden px-6 py-4 sm:table-cell">Тип</th>
                        <th class="px-6 py-4">Статус</th>
                        <th class="hidden px-6 py-4 sm:table-cell">Дата</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-content/[0.04]">
                    @forelse ($this->messages as $message)
                        <tr class="transition-colors hover:bg-content/[0.02]">
                            <td class="whitespace-nowrap px-6 py-4">
                                <div class="font-bold text-content">{{ $message->client?->name ?? '—' }}</div>
                                <div class="text-xs text-brass-ink/60">{{ \App\Models\Client::formatPhone($message->phone) }}</div>
                            </td>
                            <td class="max-w-md px-6 py-4 text-content/60">{{ $message->message }}</td>
                            <td class="hidden whitespace-nowrap px-6 py-4 text-content/40 sm:table-cell">
                                {{ $this->contextLabels()[$message->context] ?? '—' }}
                            </td>
                            <td class="px-6 py-4">
                                @if ($message->isSent())
                                    <span class="inline-flex items-center rounded-full bg-success/10 px-2.5 py-1 text-xs font-bold text-success">Отправлено</span>
                                @else
                                    <span class="inline-flex items-center rounded-full bg-danger/10 px-2.5 py-1 text-xs font-bold text-danger">Ошибка</span>
                                @endif
                            </td>
                            <td class="hidden whitespace-nowrap px-6 py-4 text-content/40 sm:table-cell">{{ $message->created_at->format('d.m.Y H:i') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-12 text-center text-content/20">SMS пока не отправлялись</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-6">
        {{ $this->messages->links() }}
    </div>
</div>
