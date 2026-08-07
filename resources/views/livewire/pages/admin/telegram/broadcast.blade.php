<?php

use App\Enums\Role;
use App\Jobs\SendTelegramBroadcast;
use App\Models\Client;
use App\Models\TelegramBroadcast;
use App\Models\User;
use App\Telegram\TelegramHtml;
use Illuminate\Support\Facades\DB;
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

    #[Validate('required|in:clients,barbers,all')]
    public string $audience = 'clients';

    #[Validate('required|string|max:2000')]
    public string $message = '';

    public bool $configured = false;

    public ?int $sentTo = null;

    /**
     * Backlog очереди виден напрямую только для database-драйвера — это
     * единственный, где задания лежат в обычной таблице. Для sync очередь
     * исполняется синхронно внутри запроса (backlog невозможен в принципе),
     * а для внешних драйверов (redis, sqs, ...) заглянуть в неё отсюда
     * нельзя без доп. интеграций — честнее промолчать, чем показать
     * фиктивную проверку.
     */
    public bool $queueCheckSupported = false;

    public int $pendingJobs = 0;

    public ?int $oldestPendingJobMinutes = null;

    /**
     * Маршрутный middleware на update-запросах Livewire не переигрывается —
     * массовая рассылка сторожит себя сама, как и денежный экран.
     */
    private function abortIfBarber(): void
    {
        abort_if(auth()->user()?->isBarber() ?? false, 403);
    }

    public function mount(): void
    {
        $this->abortIfBarber();
        $this->configured = (string) config('nutgram.token') !== '';

        if (config('queue.default') === 'database') {
            $this->queueCheckSupported = true;
            $this->pendingJobs = DB::table('jobs')->count();

            $oldestAvailableAt = DB::table('jobs')->min('available_at');
            if ($oldestAvailableAt !== null) {
                $this->oldestPendingJobMinutes = max(0, intdiv(now()->timestamp - (int) $oldestAvailableAt, 60));
            }
        }
    }

    #[Computed]
    public function clientCount(): int
    {
        return Client::whereNotNull('telegram_chat_id')->count();
    }

    #[Computed]
    public function barberCount(): int
    {
        return User::where('role', Role::BARBER)->whereNotNull('telegram_chat_id')->count();
    }

    /**
     * Ровно тот список, который уйдёт в очередь: складывать clientCount и
     * barberCount нельзя — мастер, привязавший тот же Telegram и как клиент,
     * посчитался бы дважды, и подтверждение обещало бы больше получателей,
     * чем отправка сделает.
     */
    #[Computed]
    public function recipientCount(): int
    {
        return count($this->recipients());
    }

    /** @return array<string, string> */
    public function audienceLabels(): array
    {
        return [
            'clients' => __('telegram.audience_clients'),
            'barbers' => __('telegram.audience_barbers'),
            'all' => __('telegram.audience_all'),
        ];
    }

    #[Computed]
    public function audienceLabel(): string
    {
        return $this->audienceLabels()[$this->audience] ?? '';
    }

    /**
     * Очередь явно не разгребается: задания копятся дольше порога — воркер,
     * скорее всего, не запущен или упал. См. $queueCheckSupported про то,
     * для какого драйвера этот сигнал вообще имеет смысл.
     */
    #[Computed]
    public function queueStalled(): bool
    {
        return $this->queueCheckSupported
            && $this->pendingJobs > 0
            && $this->oldestPendingJobMinutes !== null
            && $this->oldestPendingJobMinutes >= 5;
    }

    #[Computed]
    public function recentBroadcasts()
    {
        return TelegramBroadcast::query()->with('user')->latest()->paginate(10);
    }

    /** @return list<int> */
    private function recipients(): array
    {
        $ids = collect();

        if (in_array($this->audience, ['clients', 'all'], true)) {
            $ids = $ids->merge(Client::whereNotNull('telegram_chat_id')->pluck('telegram_chat_id'));
        }

        if (in_array($this->audience, ['barbers', 'all'], true)) {
            $ids = $ids->merge(User::where('role', Role::BARBER)->whereNotNull('telegram_chat_id')->pluck('telegram_chat_id'));
        }

        return $ids->filter()->unique()->values()->map(fn ($id) => (int) $id)->all();
    }

    public function send(): void
    {
        $this->abortIfBarber();
        $this->validate();

        $recipients = $this->recipients();

        if ($recipients === []) {
            $this->addError('audience', __('telegram.err_no_recipients'));

            return;
        }

        // Персистим ДО диспатча и передаём id в job — иначе retry job создал бы
        // вторую запись, а не досчитал первую.
        $broadcast = TelegramBroadcast::create([
            'user_id' => auth()->id(),
            'audience' => $this->audience,
            'message' => $this->message,
            'recipients_count' => count($recipients),
        ]);

        // Пачками: одно задание на всю базу шло бы дольше retry_after очереди,
        // и второй воркер подобрал бы его на полпути — часть клиентов получила
        // бы рассылку дважды.
        foreach (array_chunk($recipients, SendTelegramBroadcast::CHUNK) as $chunk) {
            SendTelegramBroadcast::dispatch($broadcast->id, $chunk, TelegramHtml::escape($this->message));
        }

        $this->sentTo = count($recipients);
        $this->reset('message');
        $this->dispatch('broadcast-sent');
    }
}; ?>

<div class="animate-fade-in-up">
    <x-slot:title>{{ __('telegram.broadcast_title').' — Blade Barbershop' }}</x-slot:title>
    <div class="mb-8">
        <h1 class="font-display text-4xl font-semibold uppercase tracking-tight text-content">{{ __('telegram.broadcast_title') }}</h1>
        <p class="mt-1 text-sm text-content/40">{{ __('telegram.broadcast_subtitle') }}</p>
    </div>

    @unless ($configured)
        <div class="mb-6 flex items-start gap-2 rounded-xl border border-danger/20 bg-danger/5 px-4 py-3 text-xs text-danger">
            <svg class="mt-0.5 h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
            <span>{{ __('telegram.bot_not_configured') }}</span>
        </div>
    @endunless

    {{-- Токен бота настроен — не значит, что кто-то разбирает очередь. Отдельная,
         честная проверка backlog'а, а не догадка по "queued" в баннере. --}}
    @if ($this->queueStalled)
        <div class="mb-6 flex items-start gap-2 rounded-xl border border-danger/20 bg-danger/5 px-4 py-3 text-xs text-danger">
            <svg class="mt-0.5 h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
            <span>{{ __('telegram.queue_stalled', ['count' => $pendingJobs, 'minutes' => $oldestPendingJobMinutes]) }}</span>
        </div>
    @endif

    <div x-data="{ sent: false }"
         x-on:broadcast-sent.window="sent = true; clearTimeout($el._t); $el._t = setTimeout(() => sent = false, 3500)">
        <div x-show="sent" x-cloak x-transition
             class="mb-6 flex items-center gap-2 rounded-xl border border-success/20 bg-success/10 px-4 py-3 text-sm font-bold text-success">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
            {{ __('telegram.queued', ['count' => $sentTo]) }}
        </div>

        <form wire:submit="send"
              wire:confirm="{{ __('telegram.broadcast_confirm', ['count' => $this->recipientCount, 'audience' => $this->audienceLabel]) }}"
              class="overflow-hidden rounded-2xl border border-content/[0.06] bg-content/[0.03] shadow-xl backdrop-blur-md">
            <div class="space-y-6 p-6">
                <div>
                    <label class="mb-2 block text-xs font-semibold text-content/50">{{ __('telegram.audience_label') }}</label>
                    <div class="grid gap-3 sm:grid-cols-3" role="radiogroup" aria-label="{{ __('telegram.audience_label') }}">
                        @php($counts = ['clients' => $this->clientCount, 'barbers' => $this->barberCount, 'all' => $this->clientCount + $this->barberCount])
                        @foreach($this->audienceLabels() as $value => $label)
                            @php($count = $counts[$value])
                            <label @class([
                                'flex cursor-pointer items-center justify-between gap-2 rounded-xl border px-4 py-3 text-sm transition has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-brass/40 has-[:focus-visible]:ring-offset-2 has-[:focus-visible]:ring-offset-surface',
                                'border-brass/40 bg-brass/10 text-brass-ink' => $audience === $value,
                                'border-content/[0.08] bg-content/[0.04] text-content/60 hover:border-content/20' => $audience !== $value,
                            ])>
                                <span class="flex items-center gap-2 font-semibold">
                                    <input type="radio" wire:model.live="audience" value="{{ $value }}" class="sr-only">
                                    {{ $label }}
                                </span>
                                <span class="rounded-full bg-content/[0.08] px-2 py-0.5 text-xs font-bold">{{ $count }}</span>
                            </label>
                        @endforeach
                    </div>
                    @error('audience') <p class="mt-1.5 text-xs text-danger">{{ $message }}</p> @enderror
                </div>

                {{-- mb_strlen, а не strlen: JS считает символы, и на байтах
                     кириллический черновик после перерисовки показывал вдвое
                     больше — «2400/2000» красным на валидном сообщении. --}}
                <div x-data="{ n: @js(mb_strlen((string) $message)) }">
                    <div class="mb-1.5 flex items-center justify-between">
                        <label for="broadcast-message" class="block text-xs font-semibold text-content/50">{{ __('telegram.message_label') }}</label>
                        <span class="text-xs tabular-nums" :class="n > 2000 ? 'text-danger font-bold' : 'text-content-subtle'" x-text="n + '/2000'"></span>
                    </div>
                    <textarea id="broadcast-message" wire:model="message" x-on:input="n = $event.target.value.length" autofocus rows="5" placeholder="{{ __('telegram.message_placeholder') }}"
                              class="block w-full rounded-xl border border-content/[0.08] bg-content/[0.04] px-4 py-3 text-sm text-content placeholder-content/20 outline-none transition focus:border-brass/40 focus:ring-1 focus:ring-brass/20"></textarea>
                    @error('message') <p class="mt-1.5 text-xs text-danger">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="flex items-center justify-end border-t border-content/[0.06] px-6 py-4">
                <button type="submit" @disabled(! $configured) wire:loading.attr="disabled" wire:target="send"
                        class="flex items-center gap-2 rounded-xl bg-gradient-to-r from-brass to-brass px-6 py-2.5 text-sm font-bold text-black shadow-lg shadow-brass/20 transition-all hover:scale-[1.02] active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-50">
                    <svg wire:loading.remove wire:target="send" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5" /></svg>
                    <svg wire:loading wire:target="send" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4z" /></svg>
                    {{ __('telegram.send') }}
                </button>
            </div>
        </form>
    </div>

    {{-- Раньше результат рассылки жил только 3,5 секунды в баннере. Теперь
         каждый запуск — строка в этой таблице, переживающая перезагрузку. --}}
    <div class="mt-8">
        <h2 class="mb-4 text-sm font-bold uppercase tracking-wider text-content/40">{{ __('telegram.recent_broadcasts_title') }}</h2>

        <div class="overflow-hidden rounded-2xl border border-content/[0.06] bg-content/[0.03] shadow-xl backdrop-blur-md">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-content/[0.06] bg-content/[0.03] text-xs font-bold uppercase tracking-wider text-content-muted">
                            <th class="px-6 py-4">{{ __('common.date') }}</th>
                            <th class="px-6 py-4">{{ __('telegram.col_audience') }}</th>
                            <th class="px-6 py-4">{{ __('telegram.col_message') }}</th>
                            <th class="px-6 py-4">{{ __('telegram.col_result') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-content/[0.04]">
                        @forelse ($this->recentBroadcasts as $broadcast)
                            <tr wire:key="broadcast-{{ $broadcast->id }}" class="transition-colors hover:bg-content/[0.02]">
                                <td class="whitespace-nowrap px-6 py-4">
                                    <div class="font-bold text-content">{{ $broadcast->created_at?->format('d.m.Y H:i') }}</div>
                                    @if ($broadcast->user)
                                        <div class="text-xs text-content/40">{{ $broadcast->user->name }}</div>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-6 py-4 text-content/60">{{ $this->audienceLabels()[$broadcast->audience] ?? $broadcast->audience }}</td>
                                <td class="max-w-md px-6 py-4 text-content/60">{{ \Illuminate\Support\Str::limit($broadcast->message, 80) }}</td>
                                <td class="px-6 py-4">
                                    @if (! $broadcast->isCompleted())
                                        <span class="inline-flex items-center rounded-full bg-content/10 px-2.5 py-1 text-xs font-bold text-content/50">{{ __('telegram.status_processing') }}</span>
                                    @else
                                        <span class="inline-flex items-center gap-1.5">
                                            <span class="rounded-full bg-success/10 px-2.5 py-1 text-xs font-bold text-success">{{ $broadcast->sent_count }} {{ __('telegram.sent_label') }}</span>
                                            @if ($broadcast->failed_count > 0)
                                                <span class="rounded-full bg-danger/10 px-2.5 py-1 text-xs font-bold text-danger">{{ $broadcast->failed_count }} {{ __('telegram.errors_label') }}</span>
                                            @endif
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-6 py-12 text-center text-content-muted">{{ __('telegram.empty_broadcasts') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-6">
            {{ $this->recentBroadcasts->links() }}
        </div>
    </div>
</div>
