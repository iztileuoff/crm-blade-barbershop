@props(['align' => 'right'])

@php
    $locales = config('app.supported_locales', []);
    $current = app()->getLocale();
@endphp

<div x-data="{ open: false }" @click.outside="open = false" class="relative">
    <button type="button" @click="open = !open"
            aria-label="{{ __('booking.language') }}"
            {{ $attributes->merge(['class' => 'flex h-9 items-center gap-1.5 rounded-xl border border-content/[0.06] px-2.5 text-content/50 transition hover:border-brass/40 hover:text-brass-ink']) }}>
        <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 0 0 8.716-6.747M12 21a9.004 9.004 0 0 1-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 0 1 7.843 4.582M12 3a8.997 8.997 0 0 0-7.843 4.582m15.686 0A11.953 11.953 0 0 1 12 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0 1 21 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0 1 12 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 0 1 3 12c0-1.605.42-3.113 1.157-4.418" />
        </svg>
        <span class="text-xs font-semibold uppercase">{{ $current }}</span>
    </button>

    <div x-show="open" x-cloak
         x-transition:enter="transition ease-out duration-150"
         x-transition:enter-start="opacity-0 -translate-y-1"
         x-transition:enter-end="opacity-100 translate-y-0"
         @class([
             'absolute z-50 mt-2 w-44 rounded-xl border border-content/[0.06] bg-surface-raised p-1.5 shadow-xl',
             'right-0' => $align === 'right',
             'left-0' => $align === 'left',
         ])>
        @foreach ($locales as $code => $label)
            <a href="{{ route('locale.switch', $code) }}"
               @class([
                   'flex items-center justify-between gap-2.5 rounded-lg px-3 py-2 text-sm font-medium transition',
                   'bg-brass/10 text-brass-ink' => $code === $current,
                   'text-content/60 hover:bg-content/[0.04] hover:text-content' => $code !== $current,
               ])>
                {{ $label }}
                @if ($code === $current)
                    <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                @endif
            </a>
        @endforeach
    </div>
</div>
