<?php

use App\Enums\Role;
use App\Jobs\SendTelegramBroadcast;
use App\Models\Client;
use App\Models\User;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new
#[Layout('components.layouts.app')]
class extends Component
{
    #[Validate('required|in:clients,barbers,all')]
    public string $audience = 'clients';

    #[Validate('required|string|max:2000')]
    public string $message = '';

    public bool $configured = false;

    public ?int $sentTo = null;

    public function mount(): void
    {
        $this->configured = (string) config('nutgram.token') !== '';
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
        $this->validate();

        $recipients = $this->recipients();

        if ($recipients === []) {
            $this->addError('audience', 'Нет получателей с привязанным Telegram.');

            return;
        }

        SendTelegramBroadcast::dispatch($recipients, e($this->message));

        $this->sentTo = count($recipients);
        $this->reset('message');
        $this->dispatch('broadcast-sent');
    }
}; ?>

<div class="animate-fade-in-up">
    <div class="mb-8">
        <h1 class="font-display text-4xl font-semibold uppercase tracking-tight text-content">Telegram · Рассылка</h1>
        <p class="mt-1 text-sm text-content/40">Сообщение всем привязанным пользователям</p>
    </div>

    @unless ($configured)
        <div class="mb-6 flex items-start gap-2 rounded-xl border border-danger/20 bg-danger/5 px-4 py-3 text-xs text-danger">
            <svg class="mt-0.5 h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
            <span>Бот не настроен: задайте <code class="rounded bg-danger/10 px-1.5 py-0.5">TELEGRAM_TOKEN</code> в <code class="rounded bg-danger/10 px-1.5 py-0.5">.env</code>. Сообщения не будут доставлены.</span>
        </div>
    @endunless

    <div x-data="{ sent: false }"
         x-on:broadcast-sent.window="sent = true; clearTimeout($el._t); $el._t = setTimeout(() => sent = false, 3500)">
        <div x-show="sent" x-cloak x-transition
             class="mb-6 flex items-center gap-2 rounded-xl border border-success/20 bg-success/10 px-4 py-3 text-sm font-bold text-success">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
            Рассылка поставлена в очередь · получателей: {{ $sentTo }}
        </div>

        <form wire:submit="send" class="overflow-hidden rounded-2xl border border-content/[0.06] bg-content/[0.03] shadow-xl backdrop-blur-md">
            <div class="space-y-6 p-6">
                <div>
                    <label class="mb-2 block text-xs font-semibold text-content/50">Кому отправить</label>
                    <div class="grid gap-3 sm:grid-cols-3">
                        @php($options = [
                            'clients' => ['Клиентам', $this->clientCount],
                            'barbers' => ['Мастерам', $this->barberCount],
                            'all' => ['Всем', $this->clientCount + $this->barberCount],
                        ])
                        @foreach($options as $value => [$label, $count])
                            <label @class([
                                'flex cursor-pointer items-center justify-between gap-2 rounded-xl border px-4 py-3 text-sm transition',
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

                <div>
                    <label class="mb-1.5 block text-xs font-semibold text-content/50">Текст сообщения</label>
                    <textarea wire:model="message" rows="5" placeholder="Например: Завтра работаем с 10:00. Ждём вас!"
                              class="block w-full rounded-xl border border-content/[0.08] bg-content/[0.04] px-4 py-3 text-sm text-content placeholder-content/20 outline-none transition focus:border-brass/40 focus:ring-1 focus:ring-brass/20"></textarea>
                    @error('message') <p class="mt-1.5 text-xs text-danger">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="flex items-center justify-end border-t border-content/[0.06] px-6 py-4">
                <button type="submit" wire:loading.attr="disabled" wire:target="send"
                        class="flex items-center gap-2 rounded-xl bg-gradient-to-r from-brass to-brass px-6 py-2.5 text-sm font-bold text-black shadow-lg shadow-brass/20 transition-all hover:scale-[1.02] active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-50">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5" /></svg>
                    Отправить рассылку
                </button>
            </div>
        </form>
    </div>
</div>
