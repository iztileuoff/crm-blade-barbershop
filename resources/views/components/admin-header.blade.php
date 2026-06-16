@php($links = [
    ['route' => 'admin.dashboard', 'label' => __('nav.dashboard'), 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm3 0h.008v.008H18V10.5Zm-12 0h.008v.008H6V10.5Z" />'],
    ['route' => 'admin.appointments', 'label' => __('nav.appointments'), 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" />'],
    ['route' => 'booking', 'label' => __('nav.booking'), 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 9v6m3-3H9m12 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />'],
    ['route' => 'admin.barbers', 'label' => __('nav.barbers'), 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M7.848 8.25l1.536.887M7.848 8.25a3 3 0 1 1-5.196-3 3 3 0 0 1 5.196 3zm1.536.887a2.165 2.165 0 0 1 1.083 1.839c.005.351.054.695.14 1.024M9.384 9.137l10.062 5.808M9.708 6.075 6.684 11.27m12.348 5.872-7.371-4.255-.715-.41m0 0a3 3 0 1 0-3.522 4.84 3 3 0 0 0 3.522-4.84zm.715-.41-3.024-5.193m6.043 1.385L11.97 12.43m7.371-4.255a3 3 0 1 0 5.196-3 3 3 0 0 0-5.196 3z" />'],
    ['route' => 'admin.services', 'label' => __('nav.services'), 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456Z" />'],
    ['route' => 'admin.specializations', 'label' => __('nav.specializations'), 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9.568 3H5.25A2.25 2.25 0 0 0 3 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 0 0 5.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 0 0 9.568 3Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M6 6h.008v.008H6V6Z" />'],
    ['route' => 'admin.clients', 'label' => __('nav.clients'), 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" />'],
    ['route' => 'admin.products', 'label' => __('nav.products'), 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="m21 7.5-9-5.25L3 7.5m18 0-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9" />'],
    ['route' => 'admin.orders', 'label' => __('nav.orders'), 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 0 0-3 3h15.75m-12.75-3h11.218c1.121-2.3 2.1-4.684 2.924-7.138a60.114 60.114 0 0 0-16.536-1.84M7.5 14.25 5.106 5.272M6 20.25a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Zm12.75 0a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z" />'],
    ['route' => 'admin.debts', 'label' => __('nav.debts'), 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z" />'],
    [
        'label' => __('nav.sms'),
        'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M20.25 8.511c.884.284 1.5 1.128 1.5 2.097v4.286c0 1.136-.847 2.1-1.98 2.193-.34.027-.68.052-1.02.072v3.091l-3-3c-1.354 0-2.694-.055-4.02-.163a2.115 2.115 0 0 1-.825-.242m9.345-8.334a2.126 2.126 0 0 0-.476-.095 48.64 48.64 0 0 0-8.048 0c-1.131.094-1.976 1.057-1.976 2.192v4.286c0 .837.46 1.58 1.155 1.951m9.345-8.334V6.637c0-1.621-1.152-3.026-2.76-3.235A48.455 48.455 0 0 0 11.25 3c-2.115 0-4.198.137-6.24.402-1.608.209-2.76 1.614-2.76 3.235v6.226c0 1.621 1.152 3.026 2.76 3.235.577.075 1.157.14 1.74.194V21l4.155-4.155" />',
        'children' => [
            ['route' => 'admin.sms.templates', 'label' => __('nav.sms_templates'), 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25Z" />'],
            ['route' => 'admin.sms.history', 'label' => __('nav.sms_history'), 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />'],
            ['route' => 'admin.sms.settings', 'label' => __('nav.sms_settings'), 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6h9.75M10.5 6a1.5 1.5 0 1 1-3 0m3 0a1.5 1.5 0 1 0-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-9.75 0h9.75" />'],
        ],
    ],
    [
        'label' => __('nav.telegram'),
        'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5" />',
        'children' => [
            ['route' => 'admin.telegram.templates', 'label' => __('nav.telegram_templates'), 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />'],
            ['route' => 'admin.telegram.broadcast', 'label' => __('nav.telegram_broadcast'), 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M10.34 15.84c-.688-.06-1.386-.09-2.09-.09H7.5a4.5 4.5 0 1 1 0-9h.75c.704 0 1.402-.03 2.09-.09m0 9.18c.253.962.584 1.892.985 2.783.247.55.06 1.21-.463 1.511l-.657.38c-.551.318-1.26.117-1.527-.461a20.845 20.845 0 0 1-1.44-4.282m3.102.069a18.03 18.03 0 0 1-.59-4.59c0-1.586.205-3.124.59-4.59m0 9.18a23.848 23.848 0 0 1 8.835 2.535M10.34 6.66a23.847 23.847 0 0 0 8.835-2.535m0 0A23.74 23.74 0 0 0 18.795 3m.38 1.125a23.91 23.91 0 0 1 1.014 5.395m-1.014 8.855c-.118.38-.245.754-.38 1.125m.38-1.125a23.91 23.91 0 0 0 1.014-5.395m0-3.46c.495.413.811 1.035.811 1.73 0 .695-.316 1.317-.811 1.73m0-3.46a24.347 24.347 0 0 1 0 3.46" />'],
            ['route' => 'admin.telegram.linked', 'label' => __('nav.telegram_linked'), 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622 1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244" />'],
        ],
    ],
    ['route' => 'admin.settings', 'label' => __('nav.settings'), 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.24-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.281Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />'],
])
@if(auth()->user()?->isSuperAdmin())
    @php($links[] = ['route' => 'admin.users', 'label' => __('nav.users'), 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" />'])
@endif

{{-- Barbers only ever see their own appointments --}}
@if(auth()->user()?->isBarber())
    @php($links = array_values(array_filter($links, fn ($link) => ($link['route'] ?? null) === 'admin.appointments')))
@endif

<div>
    {{-- Mobile top bar --}}
    <div class="sticky top-0 z-30 flex items-center justify-between border-b border-content/[0.06] bg-surface/80 px-4 py-3 backdrop-blur-xl lg:hidden">
        <a href="{{ route('booking') }}" class="flex items-center gap-2.5">
            <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-brass-bright to-brass text-sm font-extrabold text-on-brass">B</div>
            <div class="leading-none">
                <div class="font-display text-base font-semibold uppercase tracking-[0.18em] text-content">Blade</div>
                <div class="mt-0.5 text-[10px] font-semibold uppercase tracking-[0.2em] text-brass-ink/70">{{ __('nav.admin_panel') }}</div>
            </div>
        </a>
        <div class="flex items-center gap-2">
            <x-language-switcher class="flex h-10 items-center gap-1.5 rounded-xl border border-content/[0.06] px-2.5 text-content/50 transition hover:border-brass/40 hover:text-brass-ink" />
            <x-theme-toggle class="flex h-10 w-10 items-center justify-center rounded-xl border border-content/[0.06] text-content/50 transition hover:border-brass/40 hover:text-brass-ink" />
            <button type="button" @click="open = true"
                    class="flex h-10 w-10 items-center justify-center rounded-xl border border-content/[0.06] text-content/40 transition hover:border-content/10 hover:text-content">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" /></svg>
            </button>
        </div>
    </div>

    {{-- Mobile overlay --}}
    <div x-show="open" x-cloak @click="open = false"
         x-transition:enter="transition-opacity ease-out duration-200"
         x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
         x-transition:leave="transition-opacity ease-in duration-150"
         x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
         class="fixed inset-0 z-40 bg-black/60 backdrop-blur-sm lg:hidden"></div>

    {{-- Sidebar --}}
    <aside :class="{ 'translate-x-0!': open, 'lg:w-20': collapsed }"
           class="fixed inset-y-0 left-0 z-50 flex w-64 -translate-x-full flex-col border-r border-content/[0.06] bg-surface-raised transition-all duration-300 ease-in-out lg:translate-x-0">
        {{-- Logo --}}
        <div class="flex items-center justify-between px-5 py-5" :class="{ 'lg:justify-center lg:px-3': collapsed }">
            <a href="{{ route('booking') }}" class="flex items-center gap-2.5 group">
                <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-brass-bright to-brass text-sm font-extrabold text-on-brass transition-transform group-hover:scale-105">B</div>
                <div class="leading-none" :class="{ 'lg:hidden': collapsed }">
                    <div class="font-display text-base font-semibold uppercase tracking-[0.18em] text-content">Blade</div>
                    <div class="mt-0.5 text-[10px] font-semibold uppercase tracking-[0.2em] text-brass-ink/70">{{ __('nav.admin_panel') }}</div>
                </div>
            </a>
            <button type="button" @click="open = false"
                    class="flex h-8 w-8 items-center justify-center rounded-lg text-content/40 transition hover:text-content lg:hidden">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
            </button>
        </div>

        {{-- Nav --}}
        <nav class="flex-1 space-y-1 overflow-y-auto px-3 py-2">
            @foreach($links as $link)
                @if(isset($link['children']))
                    @php($groupActive = collect($link['children'])->contains(fn ($child) => request()->routeIs($child['route'])))
                    <div x-data="{ expanded: @js($groupActive) }">
                        <button type="button" @click="expanded = !expanded" title="{{ $link['label'] }}"
                                :class="{ 'lg:justify-center': collapsed }"
                                @class([
                                    'flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition-colors',
                                    'bg-brass/10 text-brass-ink' => $groupActive,
                                    'text-content/45 hover:bg-content/[0.04] hover:text-content' => !$groupActive,
                                ])>
                            <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">{!! $link['icon'] !!}</svg>
                            <span class="flex-1 text-left" :class="{ 'lg:hidden': collapsed }">{{ $link['label'] }}</span>
                            <svg class="h-4 w-4 shrink-0 transition-transform duration-200" :class="{ 'rotate-180': expanded, 'lg:hidden': collapsed }" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
                        </button>
                        <div x-show="expanded" x-cloak
                             x-transition:enter="transition ease-out duration-150"
                             x-transition:enter-start="opacity-0 -translate-y-1"
                             x-transition:enter-end="opacity-100 translate-y-0"
                             class="mt-1 space-y-1 border-l border-content/[0.06] pl-3" :class="{ 'lg:border-l-0 lg:pl-0': collapsed }">
                            @foreach($link['children'] as $child)
                                <a href="{{ route($child['route']) }}" @click="open = false" title="{{ $child['label'] }}"
                                   :class="{ 'lg:justify-center': collapsed }"
                                   @class([
                                       'flex items-center gap-3 rounded-xl px-3 py-2 text-sm font-medium transition-colors',
                                       'bg-brass/10 text-brass-ink' => request()->routeIs($child['route']),
                                       'text-content/45 hover:bg-content/[0.04] hover:text-content' => !request()->routeIs($child['route']),
                                   ])>
                                    <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">{!! $child['icon'] !!}</svg>
                                    <span :class="{ 'lg:hidden': collapsed }">{{ $child['label'] }}</span>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @else
                    <a href="{{ route($link['route']) }}" @click="open = false" title="{{ $link['label'] }}"
                       :class="{ 'lg:justify-center': collapsed }"
                       @class([
                           'flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition-colors',
                           'bg-brass/10 text-brass-ink' => request()->routeIs($link['route']),
                           'text-content/45 hover:bg-content/[0.04] hover:text-content' => !request()->routeIs($link['route']),
                       ])>
                        <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">{!! $link['icon'] !!}</svg>
                        <span :class="{ 'lg:hidden': collapsed }">{{ $link['label'] }}</span>
                    </a>
                @endif
            @endforeach
        </nav>

        {{-- Logout (mobile drawer only — desktop uses the top header) --}}
        @auth
            <div class="border-t border-content/[0.06] p-3 lg:hidden">
                <a href="{{ route('logout') }}" title="{{ __('common.logout') }}"
                   :class="{ 'lg:justify-center': collapsed }"
                   class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium text-danger/60 transition hover:bg-danger/10 hover:text-danger">
                    <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 9V5.25A2.25 2.25 0 0 1 10.5 3h6a2.25 2.25 0 0 1 2.25 2.25v13.5A2.25 2.25 0 0 1 16.5 21h-6a2.25 2.25 0 0 1-2.25-2.25V15M12 9l3 3m0 0-3 3m3-3H2.25" /></svg>
                    <span :class="{ 'lg:hidden': collapsed }">{{ __('common.logout') }}</span>
                </a>
            </div>
        @endauth

        {{-- Theme toggle (mobile drawer only — desktop uses the top header) --}}
        <div class="border-t border-content/[0.06] p-3 lg:hidden">
            <x-theme-toggle label="{{ __('common.theme') }}"
                            class="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium text-content/45 transition-colors hover:bg-content/[0.04] hover:text-content" />
        </div>
    </aside>
</div>
