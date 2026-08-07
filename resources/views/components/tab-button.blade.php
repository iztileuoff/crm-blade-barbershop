{{--
    Вкладка внутри <x-tab-list>. `panel` — id управляемой панели; из него же
    собирается id самой вкладки, чтобы панель могла сослаться назад через
    aria-labelledby.

    aria-controls ставится только у активной: панели здесь серверные, в разметке
    живёт ровно одна, и ссылка с неактивной вкладки указывала бы в пустоту.
--}}
@props(['panel', 'active' => false])

<button type="button"
        role="tab"
        id="{{ $panel }}-tab"
        @if ($active) aria-controls="{{ $panel }}" @endif
        aria-selected="{{ $active ? 'true' : 'false' }}"
        {{-- Roving tabindex: в набор Tab заводит один раз — на активную
             вкладку, — а дальше внутри набора ходят стрелками. Без этого Tab
             обходил бы все вкладки подряд, как обычные кнопки. --}}
        tabindex="{{ $active ? '0' : '-1' }}"
        {{ $attributes }}>
    {{ $slot }}
</button>
