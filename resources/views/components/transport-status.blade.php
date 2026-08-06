{{--
    Транспортная отказоустойчивость (issue #75) — три независимых сигнала,
    каждый за свой участок:
      - offline/online         — событиями браузера, на Alpine: эта разметка
                                  живёт в макете, вне Livewire-компонента, где
                                  `wire:offline` просто не обрабатывается;
      - transport-error        — конкретный Livewire-запрос не доехал или сервер
                                  ответил ошибкой (обрыв на мобильном интернете,
                                  500/503); ненавязчивый баннер с повтором;
      - transport-session-expired — тот же провал, но с кодом 419: повтор того
                                  же запроса сессию не оживит, нужен новый вход.
    Оба кастомных события шлёт resources/js/app.js — весь переведённый текст
    здесь, в JS нет ни одной русской строки.
--}}
<div x-data="{ showError: false, showExpired: false, offline: ! navigator.onLine }"
     x-on:transport-error.window="showError = true; clearTimeout($el._transportErrTimer); $el._transportErrTimer = setTimeout(() => showError = false, 10000)"
     x-on:transport-session-expired.window="showExpired = true"
     x-on:offline.window="offline = true"
     x-on:online.window="offline = false">

    {{-- Полоска "нет соединения". Не `wire:offline`: эта разметка живёт в
         макете, вне любого Livewire-компонента, а директиву Livewire
         обрабатывает только внутри компонента — полоска молча не появлялась бы
         никогда. Снизу, а не сверху: сверху она накрыла бы липкую шапку с
         кнопкой меню ровно тогда, когда до неё и надо дотянуться. --}}
    <div x-show="offline" x-cloak
         class="fixed inset-x-0 bottom-0 z-[55] flex items-center justify-center gap-2 border-t border-danger/30 bg-danger/15 px-4 py-2 text-center text-xs font-bold text-danger shadow-lg backdrop-blur-xl">
        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M18.364 5.636a9 9 0 0 1 .203 12.727M15.536 8.464a5 5 0 0 1 .203 6.98m-9.402.203a9 9 0 0 1-.203-12.727m3.032 9.695a5 5 0 0 1-.203-6.98M3 3l18 18" /></svg>
        {{ __('errors.offline_indicator') }}
    </div>

    {{-- Баннер оборвавшегося запроса — не блокирует форму, есть повтор. --}}
    <div x-show="showError" x-cloak
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 translate-y-2"
         x-transition:enter-end="opacity-100 translate-y-0"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100 translate-y-0"
         x-transition:leave-end="opacity-0 translate-y-2"
         role="alert"
         aria-live="assertive"
         class="pointer-events-none fixed inset-x-0 bottom-4 z-[65] flex justify-center px-4 sm:inset-x-auto sm:left-6 sm:justify-start">
        <div class="pointer-events-auto flex max-w-sm items-start gap-3 rounded-xl border border-danger/20 bg-surface-raised px-4 py-3 shadow-xl">
            <svg class="mt-0.5 h-5 w-5 shrink-0 text-danger" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9.303-3.376c.866 1.5-.217 3.374-1.948 3.374H4.645c-1.73 0-2.813-1.874-1.948-3.374L9.4 3.378c.866-1.5 3.032-1.5 3.898 0l6.85 11.872ZM12 17.25h.007v.008H12v-.008Z" /></svg>
            <div class="min-w-0 flex-1">
                <div class="text-sm font-bold text-content">{{ __('errors.connection_lost_title') }}</div>
                <div class="mt-0.5 text-xs text-content/60">{{ __('errors.connection_lost_body') }}</div>
                <div class="mt-2 flex items-center gap-4">
                    <button type="button" @click="showError = false; $dispatch('transport-retry')"
                            class="text-xs font-bold text-danger transition hover:text-danger/80">
                        {{ __('errors.retry') }}
                    </button>
                    <button type="button" @click="showError = false"
                            class="text-xs font-medium text-content/40 transition hover:text-content/70">
                        {{ __('errors.dismiss') }}
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- Модалка истёкшей сессии — вход в новой вкладке, чтобы форма в этой
         не потерялась. --}}
    <div x-show="showExpired" x-cloak
         class="fixed inset-0 z-[80] flex items-center justify-center p-4"
         role="alertdialog" aria-modal="true">
        <div class="absolute inset-0 bg-surface/80 backdrop-blur-sm"></div>
        <div class="relative z-10 w-full max-w-sm rounded-2xl border border-content/[0.12] bg-surface-raised p-6 text-center shadow-2xl">
            <div class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-xl bg-danger/10 text-danger">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" /></svg>
            </div>
            <h3 class="mb-1.5 text-sm font-bold text-content">{{ __('errors.session_expired_title') }}</h3>
            <p class="mb-5 text-sm text-content/60">{{ __('errors.session_expired_body') }}</p>
            <div class="flex flex-col gap-2.5">
                {{-- _blank — планшет открывает вход в новой вкладке, эта не перезагружается --}}
                <a href="{{ route('login') }}" target="_blank" rel="noopener"
                   @click="showExpired = false"
                   class="inline-flex items-center justify-center gap-2 rounded-xl bg-brass px-6 py-2.5 text-sm font-bold text-black transition-all hover:bg-brass-bright active:scale-[0.98]">
                    {{ __('errors.login_again') }}
                </a>
                <button type="button" @click="showExpired = false; $dispatch('transport-retry')"
                        class="inline-flex items-center justify-center gap-2 rounded-xl border border-content/[0.1] px-6 py-2.5 text-sm font-bold text-content/70 transition hover:border-content/20 hover:text-content">
                    {{ __('errors.retry') }}
                </button>
            </div>
        </div>
    </div>
</div>
