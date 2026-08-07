<?php

use App\Services\SmsService;
use App\Support\NotificationTemplates;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new
#[Layout('components.layouts.app')]
class extends Component
{
    /**
     * Число SMS-частей (сегментов) для текста шаблона — та же логика,
     * что считает стоимость на странице истории.
     */
    public function segments(string $text): int
    {
        return SmsService::segments($text);
    }

    /**
     * Зафиксированные SMS-шаблоны для показа: тип => [подпись, плейсхолдеры].
     *
     * @return array<string, array{label: string, hint: string, placeholders: list<string>}>
     */
    public function fields(): array
    {
        return [
            'reminder' => [
                'label' => __('sms.field_reminder_label'),
                'hint' => __('sms.field_reminder_hint'),
                'placeholders' => ['{time}'],
            ],
            'retention' => [
                'label' => __('sms.field_retention_label'),
                'hint' => __('sms.field_retention_hint'),
                'placeholders' => [],
            ],
            'cancelled' => [
                'label' => __('sms.field_cancelled_label'),
                'hint' => __('sms.field_cancelled_hint'),
                'placeholders' => ['{time}'],
            ],
        ];
    }

    /** @return array<string, string> */
    public function localeLabels(): array
    {
        return [
            'ru' => 'Русский',
            'uz' => 'Oʻzbekcha',
            'kaa' => 'Qaraqalpaqsha',
        ];
    }

    /**
     * Тексты шаблона по типу: язык => текст.
     *
     * @return array<string, string>
     */
    public function texts(string $type): array
    {
        return NotificationTemplates::SMS[$type] ?? [];
    }

    public function activeLocale(): string
    {
        return NotificationTemplates::smsLocale();
    }

    /**
     * Текст с подставленным примером времени — чтобы {time} читался как
     * реальное сообщение, а не как сырой плейсхолдер.
     */
    public function preview(string $text): string
    {
        return str_replace('{time}', '14:30', $text);
    }
}; ?>

<div class="animate-fade-in-up">
    <x-slot:title>{{ __('sms.templates_title').' — Blade Barbershop' }}</x-slot:title>
    <div class="mb-8">
        <h1 class="font-display text-4xl font-semibold uppercase tracking-tight text-content">{{ __('sms.templates_title') }}</h1>
        <p class="mt-1 text-sm text-content/40">{{ __('sms.templates_subtitle') }}</p>
    </div>

    <div class="mb-6 flex items-start gap-2 rounded-xl border border-brass/20 bg-brass/5 px-4 py-3 text-xs text-content/60">
        <svg class="mt-0.5 h-4 w-4 shrink-0 text-brass-ink/70" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z" /></svg>
        <span>{{ __('sms.readonly_note') }}</span>
    </div>

    <div class="space-y-6">
        @foreach($this->fields() as $type => $field)
            <div class="overflow-hidden rounded-2xl border border-content/[0.06] bg-content/[0.03] shadow-xl backdrop-blur-md">
                <div class="flex items-center justify-between gap-3 border-b border-content/[0.06] bg-content/[0.03] px-6 py-4">
                    <div>
                        <h3 class="text-sm font-bold text-content">{{ $field['label'] }}</h3>
                        <p class="mt-0.5 text-xs text-content/40">{{ $field['hint'] }}</p>
                    </div>
                    @if($field['placeholders'])
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-xs text-content/40">{{ __('sms.variables') }}</span>
                            @foreach($field['placeholders'] as $placeholder)
                                <code class="rounded-md bg-content/[0.06] px-2 py-0.5 text-xs font-medium text-brass-ink/80">{{ $placeholder }}</code>
                            @endforeach
                        </div>
                    @endif
                </div>
                <div class="divide-y divide-content/[0.04]">
                    @foreach($this->texts($type) as $locale => $text)
                        <div class="px-6 py-4">
                            <div class="mb-2 flex items-center gap-2">
                                <span class="text-xs font-bold uppercase tracking-wider text-content/40">{{ $this->localeLabels()[$locale] ?? $locale }}</span>
                                @if($locale === $this->activeLocale())
                                    <span class="inline-flex items-center rounded-full bg-success/10 px-2 py-0.5 text-[10px] font-bold uppercase text-success">{{ __('sms.active_badge') }}</span>
                                @endif
                            </div>
                            <p class="rounded-xl border border-content/[0.08] bg-content/[0.04] px-4 py-3 text-sm text-content/80">{{ $text }}</p>
                            @if(str_contains($text, '{'))
                                <p class="mt-1.5 px-1 text-xs italic text-content-subtle">{{ $this->preview($text) }}</p>
                            @endif
                            <p class="mt-1 px-1 text-[11px] text-content-subtle">{{ mb_strlen($text) }} {{ __('sms.chars') }} · ≈{{ $this->segments($text) }} SMS</p>
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
</div>
