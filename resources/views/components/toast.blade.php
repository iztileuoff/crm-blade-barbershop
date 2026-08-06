{{--
    Глобальное подтверждение успеха. Любой компонент делает `$this->dispatch('saved')`
    (при желании — `dispatch('saved', message: '…')`), пилюля всплывает на 2,5 с.
    Живёт в макете, поэтому видна и при открытой модалке, и когда форма ушла за экран.
--}}
<div x-data="{ show: false, message: '' }"
     x-on:saved.window="message = ($event.detail && $event.detail.message) ? $event.detail.message : @js(__('common.saved'));
                        show = true;
                        clearTimeout($el._toastTimer);
                        $el._toastTimer = setTimeout(() => show = false, 2500)"
     class="pointer-events-none fixed inset-x-0 bottom-4 z-[60] flex justify-center px-4 sm:inset-x-auto sm:right-6 sm:justify-end">
    <div x-show="show"
         x-cloak
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 translate-y-2"
         x-transition:enter-end="opacity-100 translate-y-0"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100 translate-y-0"
         x-transition:leave-end="opacity-0 translate-y-2"
         role="status"
         aria-live="polite"
         class="pointer-events-auto flex items-center gap-2 rounded-xl border border-success/20 bg-surface-raised px-4 py-3 text-sm font-bold text-success shadow-xl">
        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
        <span x-text="message">{{ __('common.saved') }}</span>
    </div>
</div>
