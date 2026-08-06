@php
    if (! isset($scrollTo)) {
        $scrollTo = 'body';
    }

    $scrollIntoViewJsSnippet = ($scrollTo !== false)
        ? <<<JS
           (\$el.closest('{$scrollTo}') || document.querySelector('{$scrollTo}')).scrollIntoView()
        JS
        : '';

    // Токены дизайн-системы: пагинатор обязан выглядеть частью админки, а не
    // вендорной вставкой. Светлая и тёмная темы идут от переменных content/brass.
    $base = 'inline-flex items-center justify-center rounded-xl border text-sm font-semibold transition';
    $idle = 'border-content/[0.08] bg-content/[0.04] text-content/60 hover:border-content/15 hover:bg-content/[0.08] hover:text-content';
    $muted = 'border-content/[0.06] bg-content/[0.02] text-content/20 cursor-not-allowed';
    $current = 'border-brass bg-brass text-on-brass shadow-lg shadow-brass/20';
@endphp

<div>
    @if ($paginator->hasPages())
        <nav role="navigation" aria-label="{{ __('pagination.nav_label') }}" class="flex items-center justify-between gap-4">
            {{-- Мобильная версия: только «назад» и «вперёд» --}}
            <div class="flex flex-1 justify-between gap-3 sm:hidden">
                @if ($paginator->onFirstPage())
                    <span aria-disabled="true" class="{{ $base }} {{ $muted }} px-4 py-2.5">{{ __('pagination.previous') }}</span>
                @else
                    <button type="button" wire:click="previousPage('{{ $paginator->getPageName() }}')"
                            x-on:click="{{ $scrollIntoViewJsSnippet }}" wire:loading.attr="disabled"
                            dusk="previousPage{{ $paginator->getPageName() == 'page' ? '' : '.'.$paginator->getPageName() }}.before"
                            class="{{ $base }} {{ $idle }} px-4 py-2.5 active:scale-[0.98]">{{ __('pagination.previous') }}</button>
                @endif

                <span class="self-center text-xs tabular-nums text-content/40">{{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}</span>

                @if ($paginator->hasMorePages())
                    <button type="button" wire:click="nextPage('{{ $paginator->getPageName() }}')"
                            x-on:click="{{ $scrollIntoViewJsSnippet }}" wire:loading.attr="disabled"
                            dusk="nextPage{{ $paginator->getPageName() == 'page' ? '' : '.'.$paginator->getPageName() }}.before"
                            class="{{ $base }} {{ $idle }} px-4 py-2.5 active:scale-[0.98]">{{ __('pagination.next') }}</button>
                @else
                    <span aria-disabled="true" class="{{ $base }} {{ $muted }} px-4 py-2.5">{{ __('pagination.next') }}</span>
                @endif
            </div>

            <div class="hidden sm:flex sm:flex-1 sm:items-center sm:justify-between sm:gap-4">
                <p class="text-sm text-content/40">
                    {{ __('pagination.summary', [
                        'first' => $paginator->firstItem(),
                        'last' => $paginator->lastItem(),
                        'total' => $paginator->total(),
                    ]) }}
                </p>

                <div class="flex items-center gap-1.5 rtl:flex-row-reverse">
                    {{-- Previous Page Link --}}
                    @if ($paginator->onFirstPage())
                        <span aria-disabled="true" aria-label="{{ __('pagination.previous') }}"
                              class="{{ $base }} {{ $muted }} h-9 w-9">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" /></svg>
                        </span>
                    @else
                        <button type="button" wire:click="previousPage('{{ $paginator->getPageName() }}')"
                                x-on:click="{{ $scrollIntoViewJsSnippet }}" wire:loading.attr="disabled"
                                dusk="previousPage{{ $paginator->getPageName() == 'page' ? '' : '.'.$paginator->getPageName() }}.after"
                                aria-label="{{ __('pagination.previous') }}"
                                class="{{ $base }} {{ $idle }} h-9 w-9 active:scale-95">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" /></svg>
                        </button>
                    @endif

                    {{-- Pagination Elements --}}
                    @foreach ($elements as $element)
                        {{-- "Three Dots" Separator --}}
                        @if (is_string($element))
                            <span aria-hidden="true" class="px-1 text-sm font-semibold text-content/25">{{ $element }}</span>
                        @endif

                        {{-- Array Of Links --}}
                        @if (is_array($element))
                            @foreach ($element as $page => $url)
                                <span wire:key="paginator-{{ $paginator->getPageName() }}-page{{ $page }}">
                                    @if ($page == $paginator->currentPage())
                                        <span aria-current="page" class="{{ $base }} {{ $current }} h-9 min-w-9 px-2 tabular-nums">{{ $page }}</span>
                                    @else
                                        <button type="button" wire:click="gotoPage({{ $page }}, '{{ $paginator->getPageName() }}')"
                                                x-on:click="{{ $scrollIntoViewJsSnippet }}" wire:loading.attr="disabled"
                                                aria-label="{{ __('pagination.goto_page', ['page' => $page]) }}"
                                                class="{{ $base }} {{ $idle }} h-9 min-w-9 px-2 tabular-nums active:scale-95">{{ $page }}</button>
                                    @endif
                                </span>
                            @endforeach
                        @endif
                    @endforeach

                    {{-- Next Page Link --}}
                    @if ($paginator->hasMorePages())
                        <button type="button" wire:click="nextPage('{{ $paginator->getPageName() }}')"
                                x-on:click="{{ $scrollIntoViewJsSnippet }}" wire:loading.attr="disabled"
                                dusk="nextPage{{ $paginator->getPageName() == 'page' ? '' : '.'.$paginator->getPageName() }}.after"
                                aria-label="{{ __('pagination.next') }}"
                                class="{{ $base }} {{ $idle }} h-9 w-9 active:scale-95">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                        </button>
                    @else
                        <span aria-disabled="true" aria-label="{{ __('pagination.next') }}"
                              class="{{ $base }} {{ $muted }} h-9 w-9">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                        </span>
                    @endif
                </div>
            </div>
        </nav>
    @endif
</div>
