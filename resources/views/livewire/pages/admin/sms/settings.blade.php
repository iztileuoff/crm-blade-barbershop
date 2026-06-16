<?php

use App\Services\SmsService;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new
#[Layout('components.layouts.app')]
class extends Component
{
    public bool $configured = false;

    public string $from = '';

    public string $baseUrl = '';

    public bool $checked = false;

    public ?bool $connectionOk = null;

    public ?string $balance = null;

    public function mount(SmsService $sms): void
    {
        $this->configured = $sms->isConfigured();
        $this->from = $sms->from();
        $this->baseUrl = $sms->baseUrl();
    }

    public function check(SmsService $sms): void
    {
        $this->connectionOk = $sms->checkConnection();
        $this->balance = $this->connectionOk ? $sms->balance() : null;
        $this->checked = true;
    }
}; ?>

<div class="animate-fade-in-up">
    <div class="mb-8">
        <h1 class="font-display text-4xl font-semibold uppercase tracking-tight text-content">SMS · Настройки Eskiz</h1>
        <p class="mt-1 text-sm text-content/40">Интеграция с Eskiz SMS</p>
    </div>

    <div class="mb-6 flex items-start gap-2 rounded-xl border border-content/[0.08] bg-content/[0.03] px-4 py-3 text-xs text-content/60">
        <svg class="mt-0.5 h-4 w-4 shrink-0 text-content/40" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" /></svg>
        <span>Логин и пароль Eskiz хранятся в файле <code class="rounded bg-content/[0.06] px-1.5 py-0.5">.env</code> (<code class="rounded bg-content/[0.06] px-1.5 py-0.5">ESKIZ_EMAIL</code>, <code class="rounded bg-content/[0.06] px-1.5 py-0.5">ESKIZ_PASSWORD</code>) и не редактируются из панели в целях безопасности.</span>
    </div>

    <div class="overflow-hidden rounded-2xl border border-content/[0.06] bg-content/[0.03] shadow-xl backdrop-blur-md">
        <div class="border-b border-content/[0.06] bg-content/[0.03] px-6 py-4">
            <h3 class="text-sm font-bold text-content">Состояние интеграции</h3>
        </div>
        <div class="divide-y divide-content/[0.04]">
            <div class="flex items-center justify-between px-6 py-4">
                <span class="text-sm text-content/50">Учётные данные</span>
                @if ($configured)
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-success/10 px-3 py-1 text-xs font-bold text-success">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                        Настроены
                    </span>
                @else
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-danger/10 px-3 py-1 text-xs font-bold text-danger">Не настроены</span>
                @endif
            </div>
            <div class="flex items-center justify-between px-6 py-4">
                <span class="text-sm text-content/50">Имя отправителя (from)</span>
                <span class="font-mono text-sm text-content">{{ $from ?: '—' }}</span>
            </div>
            <div class="flex items-center justify-between px-6 py-4">
                <span class="text-sm text-content/50">API endpoint</span>
                <span class="font-mono text-sm text-content/60">{{ $baseUrl ?: '—' }}</span>
            </div>
            @if ($checked)
                <div class="flex items-center justify-between px-6 py-4">
                    <span class="text-sm text-content/50">Подключение</span>
                    @if ($connectionOk)
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-success/10 px-3 py-1 text-xs font-bold text-success">Успешно</span>
                    @else
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-danger/10 px-3 py-1 text-xs font-bold text-danger">Ошибка</span>
                    @endif
                </div>
                @if ($connectionOk)
                    <div class="flex items-center justify-between px-6 py-4">
                        <span class="text-sm text-content/50">Баланс</span>
                        <span class="font-bold text-content">{{ $balance !== null ? $balance.' сум' : 'недоступен' }}</span>
                    </div>
                @endif
            @endif
        </div>
        <div class="flex items-center justify-end border-t border-content/[0.06] px-6 py-4">
            <button type="button" wire:click="check" wire:loading.attr="disabled" wire:target="check"
                    @disabled(! $configured)
                    class="flex items-center gap-2 rounded-xl bg-brass px-5 py-2.5 text-sm font-bold text-black transition-all hover:bg-brass-bright active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-40">
                <svg wire:loading wire:target="check" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                <span wire:loading.remove wire:target="check">Проверить подключение</span>
                <span wire:loading wire:target="check">Проверка…</span>
            </button>
        </div>
    </div>
</div>
