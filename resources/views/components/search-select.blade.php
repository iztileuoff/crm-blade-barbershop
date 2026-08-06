@props([
    'searchModel',
    'options',
    'onSelect',
    'labelField',
    'subLabelField' => null,
    'placeholder' => null,
    'selectedLabel' => null,
    'onClear' => null,
])

<div x-data="{ open: false }" @click.outside="open = false" class="relative">
    <input type="text" x-on:focus="open = true" x-on:keydown.enter.prevent wire:model.live.debounce.300ms="{{ $searchModel }}"
        placeholder="{{ $placeholder ?? __('common.search').'...' }}"
        aria-label="{{ $placeholder ?? __('common.search') }}"
        class="block w-full rounded-xl border border-content/[0.08] bg-content/[0.04] px-4 py-3 text-sm text-content placeholder-content/20 outline-none">

    <div x-show="open" x-transition
        class="absolute z-100 mt-2 w-full rounded-xl border border-content/[0.08] bg-surface-raised shadow-xl max-h-60 overflow-y-auto">
        @forelse($options as $option)
            <button type="button" wire:key="opt-{{ $option->id }}"
                wire:click="{{ $onSelect }}({{ $option->id }}, '{{ addslashes($option->{$labelField}) }}{{ $subLabelField && $option->{$subLabelField} ? ' (' . addslashes($option->{$subLabelField}) . ')' : '' }}')"
                @click="open = false" class="w-full text-left px-4 py-2 text-sm text-content hover:bg-content/10">
                {{ $option->{$labelField} }}
                @if($subLabelField && $option->{$subLabelField})
                    ({{ $option->{$subLabelField} }})
                @endif
            </button>
        @empty
            <div class="px-4 py-2 text-content/40 text-sm">
                {{ __('common.nothing_found') }}
            </div>
        @endforelse
    </div>

    {{-- Подтверждённый выбор. Текст в инпуте — только строка поиска: пока чипа
         нет, ничего не привязано, и это должно быть видно без догадок. --}}
    @if ($selectedLabel)
        <div class="mt-2 inline-flex max-w-full items-center gap-1.5 rounded-lg border border-brass/30 bg-brass/10 py-1 pl-2.5 pr-1.5 text-xs font-semibold text-brass-ink">
            <svg class="h-3.5 w-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
            <span class="truncate">{{ $selectedLabel }}</span>
            @if ($onClear)
                <button type="button" wire:click="{{ $onClear }}" aria-label="{{ __('common.cancel') }}"
                    class="shrink-0 rounded p-0.5 text-brass-ink/60 transition hover:bg-brass/20 hover:text-brass-ink">
                    <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                </button>
            @endif
        </div>
    @endif
</div>
