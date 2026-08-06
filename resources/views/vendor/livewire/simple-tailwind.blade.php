@php
    if (! isset($scrollTo)) {
        $scrollTo = 'body';
    }

    $scrollIntoViewJsSnippet = ($scrollTo !== false)
        ? <<<JS
           (\$el.closest('{$scrollTo}') || document.querySelector('{$scrollTo}')).scrollIntoView()
        JS
        : '';

    $base = 'inline-flex items-center justify-center rounded-xl border px-4 py-2.5 text-sm font-semibold transition';
    $idle = 'border-content/[0.08] bg-content/[0.04] text-content/60 hover:border-content/15 hover:bg-content/[0.08] hover:text-content active:scale-[0.98]';
    $muted = 'border-content/[0.06] bg-content/[0.02] text-content/20 cursor-not-allowed';
    $isCursor = method_exists($paginator, 'getCursorName');
@endphp

<div>
    @if ($paginator->hasPages())
        <nav role="navigation" aria-label="{{ __('pagination.nav_label') }}" class="flex justify-between gap-3">
            {{-- Previous Page Link --}}
            @if ($paginator->onFirstPage())
                <span aria-disabled="true" class="{{ $base }} {{ $muted }}">{{ __('pagination.previous') }}</span>
            @elseif ($isCursor)
                <button type="button" dusk="previousPage"
                        wire:key="cursor-{{ $paginator->getCursorName() }}-{{ $paginator->previousCursor()->encode() }}"
                        wire:click="setPage('{{ $paginator->previousCursor()->encode() }}','{{ $paginator->getCursorName() }}')"
                        x-on:click="{{ $scrollIntoViewJsSnippet }}" wire:loading.attr="disabled"
                        class="{{ $base }} {{ $idle }}">{{ __('pagination.previous') }}</button>
            @else
                <button type="button" wire:click="previousPage('{{ $paginator->getPageName() }}')"
                        x-on:click="{{ $scrollIntoViewJsSnippet }}" wire:loading.attr="disabled"
                        dusk="previousPage{{ $paginator->getPageName() == 'page' ? '' : '.'.$paginator->getPageName() }}"
                        class="{{ $base }} {{ $idle }}">{{ __('pagination.previous') }}</button>
            @endif

            {{-- Next Page Link --}}
            @if (! $paginator->hasMorePages())
                <span aria-disabled="true" class="{{ $base }} {{ $muted }}">{{ __('pagination.next') }}</span>
            @elseif ($isCursor)
                <button type="button" dusk="nextPage"
                        wire:key="cursor-{{ $paginator->getCursorName() }}-{{ $paginator->nextCursor()->encode() }}"
                        wire:click="setPage('{{ $paginator->nextCursor()->encode() }}','{{ $paginator->getCursorName() }}')"
                        x-on:click="{{ $scrollIntoViewJsSnippet }}" wire:loading.attr="disabled"
                        class="{{ $base }} {{ $idle }}">{{ __('pagination.next') }}</button>
            @else
                <button type="button" wire:click="nextPage('{{ $paginator->getPageName() }}')"
                        x-on:click="{{ $scrollIntoViewJsSnippet }}" wire:loading.attr="disabled"
                        dusk="nextPage{{ $paginator->getPageName() == 'page' ? '' : '.'.$paginator->getPageName() }}"
                        class="{{ $base }} {{ $idle }}">{{ __('pagination.next') }}</button>
            @endif
        </nav>
    @endif
</div>
