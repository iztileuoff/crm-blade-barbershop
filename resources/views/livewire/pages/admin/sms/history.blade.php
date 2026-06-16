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
            'reminder' => __('sms.context_reminder'),
            'retention' => __('sms.context_retention'),
            'broadcast' => __('sms.context_broadcast'),
            'manual' => __('sms.context_manual'),
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
            <h1 class="font-display text-4xl font-semibold uppercase tracking-tight text-content">{{ __('sms.history_title') }}</h1>
            <p class="mt-1 text-sm text-content/40">
                {{ __('sms.sent_label') }}: <span class="font-bold text-success">{{ $this->sentCount }}</span>
                · {{ __('sms.errors_label') }}: <span class="font-bold text-danger">{{ $this->failedCount }}</span>
            </p>
        </div>
        <div class="flex items-center gap-3">
            <select wire:model.live="context"
                    class="rounded-xl border border-content/[0.08] bg-content/[0.04] px-4 py-2.5 text-sm text-content outline-none transition focus:border-brass/40 focus:ring-1 focus:ring-brass/20 dark:[color-scheme:dark]">
                <option value="">{{ __('sms.all_types') }}</option>
                @foreach($this->contextLabels() as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
            <select wire:model.live="status"
                    class="rounded-xl border border-content/[0.08] bg-content/[0.04] px-4 py-2.5 text-sm text-content outline-none transition focus:border-brass/40 focus:ring-1 focus:ring-brass/20 dark:[color-scheme:dark]">
                <option value="">{{ __('sms.all_statuses') }}</option>
                <option value="sent">{{ __('sms.sent') }}</option>
                <option value="failed">{{ __('sms.error') }}</option>
            </select>
        </div>
    </div>

    <div class="overflow-hidden rounded-2xl border border-content/[0.06] bg-content/[0.03] shadow-xl backdrop-blur-md">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-content/[0.06] bg-content/[0.03] text-xs font-bold uppercase tracking-wider text-content/30">
                        <th class="px-6 py-4">{{ __('sms.col_recipient') }}</th>
                        <th class="px-6 py-4">{{ __('sms.col_message') }}</th>
                        <th class="hidden px-6 py-4 sm:table-cell">{{ __('sms.col_type') }}</th>
                        <th class="px-6 py-4">{{ __('common.status') }}</th>
                        <th class="hidden px-6 py-4 sm:table-cell">{{ __('common.date') }}</th>
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
                                    <span class="inline-flex items-center rounded-full bg-success/10 px-2.5 py-1 text-xs font-bold text-success">{{ __('sms.sent') }}</span>
                                @else
                                    <span class="inline-flex items-center rounded-full bg-danger/10 px-2.5 py-1 text-xs font-bold text-danger">{{ __('sms.error') }}</span>
                                @endif
                            </td>
                            <td class="hidden whitespace-nowrap px-6 py-4 text-content/40 sm:table-cell">{{ $message->created_at->format('d.m.Y H:i') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-12 text-center text-content/20">{{ __('sms.empty_history') }}</td>
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
