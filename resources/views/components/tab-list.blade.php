{{--
    Набор вкладок по паттерну WAI-ARIA: Tab заводит в набор один раз (roving
    tabindex живёт в <x-tab-button>), а между вкладками ходят стрелками —
    именно этого и ждёт от role="tablist" любой скринридер.

    Переключение серверное (wire:click), поэтому стрелка не «выбирает» сама, а
    переносит фокус и кликает — состояние остаётся одно, на компоненте.
--}}
@props(['label' => null])

<div role="tablist"
     @if ($label) aria-label="{{ $label }}" @endif
     x-data="{
        tabs() { return [...$el.querySelectorAll('[role=tab]')] },
        step(direction) {
            const tabs = this.tabs()

            if (! tabs.length) { return }

            const from = tabs.indexOf(document.activeElement)

            this.activate(tabs[(from + direction + tabs.length) % tabs.length])
        },
        jump(index) {
            this.activate(this.tabs().at(index))
        },
        activate(tab) {
            if (! tab) { return }

            tab.focus()
            tab.click()
        },
     }"
     x-on:keydown.right.prevent="step(1)"
     x-on:keydown.left.prevent="step(-1)"
     x-on:keydown.home.prevent="jump(0)"
     x-on:keydown.end.prevent="jump(-1)"
     {{ $attributes }}>
    {{ $slot }}
</div>
