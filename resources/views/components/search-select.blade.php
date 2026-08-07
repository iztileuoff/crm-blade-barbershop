@props([
    'searchModel',
    'options',
    'onSelect',
    'labelField',
    'subLabelField' => null,
    'placeholder' => null,
    'selectedLabel' => null,
    'onClear' => null,
    'inputId' => null,
])

@php
    // Пара label/for живёт внутри: input рисует компонент, наружу его id не
    // отдавался, и подписи над выпадашками висели ни к чему не привязанные —
    // клик по слову «Клиент» не попадал в поле, а скринридер называл его
    // плейсхолдером. Подпись приходит слотом, а не строкой: у продаж в ней
    // ещё и пометка «обязательно/необязательно».
    $fieldId = $inputId ?? 'search-select-'.\Illuminate\Support\Str::slug($searchModel);
    $hasVisibleLabel = isset($label);
@endphp

<div x-data="{ open: false, highlighted: -1 }" @click.outside="open = false" class="relative">
    @isset($label)
        <label for="{{ $fieldId }}" class="mb-1.5 block text-xs font-semibold text-content/50">{{ $label }}</label>
    @endisset

    <input type="text" id="{{ $fieldId }}" x-on:focus="open = true" wire:model.live.debounce.300ms="{{ $searchModel }}"
        x-on:input="highlighted = -1"
        x-on:keydown.down.prevent="open = true; highlighted = Math.min(highlighted + 1, $refs.searchSelectList.children.length - 1)"
        {{-- Как и стрелка вниз, сначала открывает список: иначе одно нажатие ↑
             подсвечивало первый вариант при закрытой выпадашке, и следующий
             Enter выбирал клиента, которого пользователь не видел. Нижняя
             граница -1, а не 0, чтобы можно было вернуться к «ничего не
             выбрано». --}}
        x-on:keydown.up.prevent="open = true; highlighted = Math.max(highlighted - 1, -1)"
        x-on:keydown.escape="open = false; highlighted = -1"
        x-on:keydown.enter.prevent="if (open && highlighted >= 0) { $refs.searchSelectList.children[highlighted]?.click() }"
        placeholder="{{ $placeholder ?? __('common.search').'...' }}"
        {{-- aria-label только без видимой подписи: иначе он перекрыл бы её, и
             озвученное имя поля разошлось бы с написанным. --}}
        @unless ($hasVisibleLabel) aria-label="{{ $placeholder ?? __('common.search') }}" @endunless
        role="combobox" :aria-expanded="open" aria-autocomplete="list" aria-controls="{{ $fieldId }}-list"
        class="block w-full rounded-xl border border-content/[0.08] bg-content/[0.04] px-4 py-3 text-sm text-content placeholder-content/20 outline-none">

    <div x-show="open" x-transition x-ref="searchSelectList" id="{{ $fieldId }}-list" role="listbox"
        class="absolute z-100 mt-2 w-full rounded-xl border border-content/[0.08] bg-surface-raised shadow-xl max-h-60 overflow-y-auto">
        @forelse($options as $option)
            {{-- Наружу уходит только id: подпись компонент больше не передаёт.
                 Раньше она приезжала с клиента вместе с id, и текст в поле мог
                 не соответствовать тому, кого на самом деле выбрали. --}}
            <button type="button" wire:key="opt-{{ $option->id }}"
                wire:click="{{ $onSelect }}({{ $option->id }})"
                @click="open = false; highlighted = -1"
                x-on:mouseenter="highlighted = {{ $loop->index }}"
                :class="{ 'bg-content/10': highlighted === {{ $loop->index }} }"
                role="option" class="w-full text-left px-4 py-2 text-sm text-content hover:bg-content/10">
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
