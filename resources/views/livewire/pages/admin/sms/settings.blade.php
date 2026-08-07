<?php

use App\Models\Setting;
use App\Services\SmsService;
use App\Support\NotificationTemplates;
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

    public bool $remindersEnabled = true;

    public bool $retentionEnabled = true;

    public string $smsLocale = 'ru';

    /**
     * Поддерживаемые языки SMS и их названия для селектора.
     *
     * @return array<string, string>
     */
    public function locales(): array
    {
        return [
            'ru' => 'Русский',
            'uz' => 'Oʻzbekcha',
            'kaa' => 'Qaraqalpaqsha',
        ];
    }

    public function mount(SmsService $sms): void
    {
        $this->configured = $sms->isConfigured();
        $this->from = $sms->from();
        $this->baseUrl = $sms->baseUrl();
        $this->remindersEnabled = $sms->isEnabledFor('reminder');
        $this->retentionEnabled = $sms->isEnabledFor('retention');
        $this->smsLocale = NotificationTemplates::smsLocale();
    }

    public function updatedSmsLocale(string $value): void
    {
        if (! array_key_exists($value, $this->locales())) {
            $value = NotificationTemplates::SMS_DEFAULT_LOCALE;
            $this->smsLocale = $value;
        }

        Setting::set('sms_locale', $value);
        $this->dispatch('saved');
    }

    public function updatedRemindersEnabled(bool $value): void
    {
        Setting::set('sms_enabled_reminder', $value ? '1' : '0');
        $this->dispatch('saved');
    }

    public function updatedRetentionEnabled(bool $value): void
    {
        Setting::set('sms_enabled_retention', $value ? '1' : '0');
        $this->dispatch('saved');
    }

    public function check(SmsService $sms): void
    {
        $this->connectionOk = $sms->checkConnection();
        $this->balance = $this->connectionOk ? $sms->balance() : null;
        $this->checked = true;
    }
}; ?>

<div class="animate-fade-in-up">
    <x-slot:title>{{ __('sms.settings_title').' — Blade Barbershop' }}</x-slot:title>
    <div class="mb-8">
        <h1 class="font-display text-4xl font-semibold uppercase tracking-tight text-content">{{ __('sms.settings_title') }}</h1>
        <p class="mt-1 text-sm text-content/40">{{ __('sms.settings_subtitle') }}</p>
    </div>

    <div class="mb-6 flex items-start gap-2 rounded-xl border border-content/[0.08] bg-content/[0.03] px-4 py-3 text-xs text-content/60">
        <svg class="mt-0.5 h-4 w-4 shrink-0 text-content/40" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" /></svg>
        <span>{{ __('sms.env_note') }}</span>
    </div>

    <div wire:init="check" class="overflow-hidden rounded-2xl border border-content/[0.06] bg-content/[0.03] shadow-xl backdrop-blur-md">
        <div class="border-b border-content/[0.06] bg-content/[0.03] px-6 py-4">
            <h3 class="text-sm font-bold text-content">{{ __('sms.integration_status') }}</h3>
        </div>
        <div class="divide-y divide-content/[0.04]">
            <div class="flex items-center justify-between px-6 py-4">
                <span class="text-sm text-content/50">{{ __('sms.credentials') }}</span>
                @if ($configured)
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-success/10 px-3 py-1 text-xs font-bold text-success">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                        {{ __('sms.configured') }}
                    </span>
                @else
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-danger/10 px-3 py-1 text-xs font-bold text-danger">{{ __('sms.not_configured') }}</span>
                @endif
            </div>
            <div class="flex items-center justify-between px-6 py-4">
                <span class="text-sm text-content/50">{{ __('sms.sender_name') }}</span>
                <span class="font-mono text-sm text-content">{{ $from ?: '—' }}</span>
            </div>
            <div class="flex items-center justify-between px-6 py-4">
                <span class="text-sm text-content/50">{{ __('sms.api_endpoint') }}</span>
                <span class="font-mono text-sm text-content/60">{{ $baseUrl ?: '—' }}</span>
            </div>
            @if ($checked)
                <div class="flex items-center justify-between px-6 py-4">
                    <span class="text-sm text-content/50">{{ __('sms.connection') }}</span>
                    @if ($connectionOk)
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-success/10 px-3 py-1 text-xs font-bold text-success">{{ __('sms.success') }}</span>
                    @else
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-danger/10 px-3 py-1 text-xs font-bold text-danger">{{ __('sms.error') }}</span>
                    @endif
                </div>
                @if ($connectionOk)
                    <div class="flex items-center justify-between px-6 py-4">
                        <span class="text-sm text-content/50">{{ __('sms.balance') }}</span>
                        <span class="font-bold text-content">{{ $balance !== null ? $balance.' '.__('common.currency') : __('sms.balance_unavailable') }}</span>
                    </div>
                @endif
            @endif
        </div>
        <div class="flex items-center justify-end border-t border-content/[0.06] px-6 py-4">
            <button type="button" wire:click="check" wire:loading.attr="disabled" wire:target="check"
                    @disabled(! $configured)
                    class="flex items-center gap-2 rounded-xl bg-brass px-5 py-2.5 text-sm font-bold text-black transition-all hover:bg-brass-bright active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-40">
                <svg wire:loading wire:target="check" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                <span wire:loading.remove wire:target="check">{{ __('sms.check_connection') }}</span>
                <span wire:loading wire:target="check">{{ __('sms.checking') }}</span>
            </button>
        </div>
    </div>

    <div class="mt-6 overflow-hidden rounded-2xl border border-content/[0.06] bg-content/[0.03] shadow-xl backdrop-blur-md">
        <div class="flex items-center justify-between border-b border-content/[0.06] bg-content/[0.03] px-6 py-4">
            <h3 class="text-sm font-bold text-content">{{ __('sms.dispatch_title') }}</h3>
        </div>
        <div class="divide-y divide-content/[0.04]">
            <div class="flex items-center justify-between gap-4 px-6 py-4">
                <div>
                    <p class="text-sm font-medium text-content">{{ __('sms.locale_label') }}</p>
                    <p class="mt-0.5 text-xs text-content/40">{{ __('sms.locale_hint') }}</p>
                </div>
                <select wire:model.live="smsLocale" aria-label="{{ __('sms.locale_label') }}"
                        class="shrink-0 rounded-xl border border-content/[0.08] bg-content/[0.04] px-4 py-2.5 text-sm text-content outline-none transition focus:border-brass/40 focus:ring-1 focus:ring-brass/20 dark:[color-scheme:dark]">
                    @foreach($this->locales() as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex items-center justify-between gap-4 px-6 py-4">
                <div>
                    <p id="reminders-toggle-label" class="text-sm font-medium text-content">{{ __('sms.toggle_reminder_label') }}</p>
                    <p class="mt-0.5 text-xs text-content/40">{{ __('sms.toggle_reminder_hint') }}</p>
                </div>
                <label class="relative inline-flex shrink-0 cursor-pointer items-center">
                    <input type="checkbox" wire:model.live="remindersEnabled" aria-labelledby="reminders-toggle-label" class="peer sr-only">
                    <div class="h-6 w-11 rounded-full bg-content/10 transition-colors peer-checked:bg-brass peer-focus-visible:ring-2 peer-focus-visible:ring-brass/40 peer-focus-visible:ring-offset-2 peer-focus-visible:ring-offset-surface after:absolute after:left-[2px] after:top-[2px] after:h-5 after:w-5 after:rounded-full after:bg-content after:transition-all peer-checked:after:translate-x-full"></div>
                </label>
            </div>
            <div class="flex items-center justify-between gap-4 px-6 py-4">
                <div>
                    <p id="retention-toggle-label" class="text-sm font-medium text-content">{{ __('sms.toggle_retention_label') }}</p>
                    <p class="mt-0.5 text-xs text-content/40">{{ __('sms.toggle_retention_hint') }}</p>
                </div>
                <label class="relative inline-flex shrink-0 cursor-pointer items-center">
                    <input type="checkbox" wire:model.live="retentionEnabled" aria-labelledby="retention-toggle-label" class="peer sr-only">
                    <div class="h-6 w-11 rounded-full bg-content/10 transition-colors peer-checked:bg-brass peer-focus-visible:ring-2 peer-focus-visible:ring-brass/40 peer-focus-visible:ring-offset-2 peer-focus-visible:ring-offset-surface after:absolute after:left-[2px] after:top-[2px] after:h-5 after:w-5 after:rounded-full after:bg-content after:transition-all peer-checked:after:translate-x-full"></div>
                </label>
            </div>
        </div>
    </div>
</div>
