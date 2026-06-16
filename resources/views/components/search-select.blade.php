<div x-data="{ open: false }" @click.outside="open = false" class="relative">
    <input type="text" x-on:focus="open = true" wire:model.live.debounce.300ms="{{ $searchModel }}"
        placeholder="{{ $placeholder ?? __('common.search').'...' }}"
        class="block w-full rounded-xl border border-content/[0.08] bg-content/[0.04] px-4 py-3 text-sm text-content placeholder-content/20 outline-none">

    <div x-show="open" x-transition
        class="absolute z-100 mt-2 w-full rounded-xl border border-content/[0.08] bg-surface-raised shadow-xl max-h-60 overflow-y-auto"
        style="background-color:#121212;">
        @forelse($options as $option)
            <button type="button"
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
</div>