<div x-data="{ open: false }" @click.outside="open = false" class="relative">
    <input type="text" x-on:focus="open = true" wire:model.live.debounce.300ms="{{ $searchModel }}"
        placeholder="{{ $placeholder ?? 'Поиск...' }}"
        class="block w-full rounded-xl border border-white/[0.08] bg-white/[0.04] px-4 py-3 text-sm text-white placeholder-white/20 outline-none">

    <div x-show="open" x-transition
        class="absolute z-100 mt-2 w-full rounded-xl border border-white/[0.08] bg-[#121212] shadow-xl max-h-60 overflow-y-auto"
        style="background-color:#121212;">
        @forelse($options as $option)
            <button type="button"
                wire:click="{{ $onSelect }}({{ $option->id }}, '{{ addslashes($option->{$labelField}) }}{{ $subLabelField && $option->{$subLabelField} ? ' (' . addslashes($option->{$subLabelField}) . ')' : '' }}')"
                @click="open = false" class="w-full text-left px-4 py-2 text-sm text-white hover:bg-white/10">
                {{ $option->{$labelField} }}
                @if($subLabelField && $option->{$subLabelField})
                    ({{ $option->{$subLabelField} }})
                @endif
            </button>
        @empty
            <div class="px-4 py-2 text-white/40 text-sm">
                Ничего не найдено
            </div>
        @endforelse
    </div>
</div>