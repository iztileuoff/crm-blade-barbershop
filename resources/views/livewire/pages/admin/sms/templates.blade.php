<?php

use App\Support\NotificationTemplates;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new
#[Layout('components.layouts.app')]
class extends Component
{
    /** @var array<string, string> */
    public array $values = [];

    /**
     * Редактируемые SMS-шаблоны: ключ => [подпись, плейсхолдеры].
     *
     * @return array<string, array{label: string, hint: string, placeholders: list<string>}>
     */
    public function fields(): array
    {
        return [
            'sms_reminder' => [
                'label' => __('sms.field_reminder_label'),
                'hint' => __('sms.field_reminder_hint'),
                'placeholders' => ['{time}'],
            ],
            'sms_retention' => [
                'label' => __('sms.field_retention_label'),
                'hint' => __('sms.field_retention_hint'),
                'placeholders' => ['{days}'],
            ],
        ];
    }

    public function mount(): void
    {
        foreach (array_keys($this->fields()) as $key) {
            $this->values[$key] = NotificationTemplates::get($key);
        }
    }

    public function save(): void
    {
        $this->validate([
            'values.*' => 'required|string|max:1000',
        ]);

        foreach (array_keys($this->fields()) as $key) {
            NotificationTemplates::set($key, $this->values[$key]);
        }

        $this->dispatch('settings-saved');
    }
}; ?>

<div class="animate-fade-in-up">
    <div class="mb-8">
        <h1 class="font-display text-4xl font-semibold uppercase tracking-tight text-content">{{ __('sms.templates_title') }}</h1>
        <p class="mt-1 text-sm text-content/40">{{ __('sms.templates_subtitle') }}</p>
    </div>

    <div x-data="{ saved: false }"
         x-on:settings-saved.window="saved = true; clearTimeout($el._t); $el._t = setTimeout(() => saved = false, 2500)">
        <div x-show="saved" x-cloak x-transition
             class="mb-6 flex items-center gap-2 rounded-xl border border-success/20 bg-success/10 px-4 py-3 text-sm font-bold text-success">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
            {{ __('sms.templates_saved') }}
        </div>

        <div class="mb-6 flex items-start gap-2 rounded-xl border border-brass/20 bg-brass/5 px-4 py-3 text-xs text-content/60">
            <svg class="mt-0.5 h-4 w-4 shrink-0 text-brass-ink/70" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z" /></svg>
            <span>{{ __('sms.eskiz_note') }}</span>
        </div>

        <form wire:submit="save" class="space-y-6">
            @foreach($this->fields() as $key => $field)
                <div class="overflow-hidden rounded-2xl border border-content/[0.06] bg-content/[0.03] shadow-xl backdrop-blur-md">
                    <div class="border-b border-content/[0.06] bg-content/[0.03] px-6 py-4">
                        <h3 class="text-sm font-bold text-content">{{ $field['label'] }}</h3>
                        <p class="mt-0.5 text-xs text-content/40">{{ $field['hint'] }}</p>
                    </div>
                    <div class="p-6">
                        <textarea wire:model="values.{{ $key }}" rows="3"
                                  class="block w-full rounded-xl border border-content/[0.08] bg-content/[0.04] px-4 py-3 text-sm text-content placeholder-content/20 outline-none transition focus:border-brass/40 focus:ring-1 focus:ring-brass/20"></textarea>
                        @error('values.'.$key) <p class="mt-1.5 text-xs text-danger">{{ $message }}</p> @enderror
                        <div class="mt-3 flex flex-wrap items-center gap-2">
                            <span class="text-xs text-content/40">{{ __('sms.variables') }}</span>
                            @foreach($field['placeholders'] as $placeholder)
                                <code class="rounded-md bg-content/[0.06] px-2 py-0.5 text-xs font-medium text-brass-ink/80">{{ $placeholder }}</code>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endforeach

            <div class="flex items-center justify-end">
                <button type="submit"
                        class="rounded-xl bg-gradient-to-r from-brass to-brass px-6 py-2.5 text-sm font-bold text-black shadow-lg shadow-brass/20 transition-all hover:scale-[1.02] hover:shadow-brass/30 active:scale-[0.98]">
                    {{ __('sms.save_templates') }}
                </button>
            </div>
        </form>
    </div>
</div>
