@props(['target' => 'save', 'variant' => 'brass'])

@php
    // Цвет — параметром, а не классом с места вызова: одинаковые по весу утилиты
    // Tailwind разрешает порядком в готовом CSS, и bg-success мог бы проиграть bg-brass.
    $tone = $variant === 'success'
        ? 'bg-success hover:brightness-110'
        : 'bg-brass hover:bg-brass-bright';
@endphp

{{--
    Единая кнопка отправки формы: на время запроса гаснет, блокируется и крутит спиннер.
    `target` — имя действия из wire:submit, чтобы индикация не реагировала на чужие запросы.
--}}
<button type="submit"
        wire:loading.attr="disabled"
        wire:target="{{ $target }}"
        {{ $attributes->merge(['class' => 'inline-flex items-center justify-center gap-2 rounded-xl '.$tone.' px-6 py-2.5 text-sm font-bold text-black transition-all active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-50']) }}>
    <svg wire:loading wire:target="{{ $target }}" class="h-4 w-4 shrink-0 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4z" /></svg>
    {{ $slot }}
</button>
