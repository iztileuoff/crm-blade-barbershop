@props(['code', 'title', 'body', 'icon'])

{{--
    Каркас страниц 419/500/503 (issue #75). Полностью самодостаточен: не
    использует <x-admin-header>, <x-language-switcher> и не читает
    session()/auth() — эти страницы обязаны отрендериться, даже когда сессия
    или БД недоступны (сама причина, по которой пользователь их видит).
    Тема — тем же inline-скриптом до покраски, что и в layouts/app и
    layouts/auth, но без @livewireScripts/@livewireStyles: тут нет ни одного
    Livewire-компонента, а csrf_token() внутри них требует живую сессию.
--}}
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} — Blade Barbershop</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700,800|oswald:300,400,500,600,700" rel="stylesheet" />
    <script>
        (function () {
            try {
                var stored = localStorage.getItem('theme');
                var dark = stored ? stored === 'dark' : window.matchMedia('(prefers-color-scheme: dark)').matches;
                if (dark) { document.documentElement.classList.add('dark'); }
            } catch (e) {}
        })();
    </script>
    {{-- Никакого @vite: сорванный деплой или затёртый public/build — сам по себе
         повод увидеть 500, и страница ошибки не имеет права падать вместе с
         манифестом. Поэтому здесь ровно тот минимум стилей, который нужен
         одной карточке, инлайном. --}}
    <style>
        :root { color-scheme: light; --surface: #f4f5f7; --surface-raised: #fff; --content: 23 25 28; --danger: #d92d20; }
        html.dark { color-scheme: dark; --surface: #0d0e10; --surface-raised: #17191c; --content: 215 220 226; --danger: #f97066; }
        * { box-sizing: border-box; }
        html, body { height: 100%; margin: 0; }
        body {
            display: flex; align-items: center; justify-content: center;
            padding: 3rem 1rem; background: var(--surface);
            color: rgb(var(--content));
            font-family: Inter, ui-sans-serif, system-ui, sans-serif; -webkit-font-smoothing: antialiased;
        }
        .card { width: 100%; max-width: 24rem; padding: 2rem; text-align: center; border-radius: 1rem;
                background: var(--surface-raised); border: 1px solid rgb(var(--content) / 0.1);
                box-shadow: 0 25px 50px -12px rgb(0 0 0 / 0.35); }
        .badge { width: 3.5rem; height: 3.5rem; margin: 0 auto 1.25rem; border-radius: 1rem;
                 display: flex; align-items: center; justify-content: center;
                 background: color-mix(in srgb, var(--danger) 12%, transparent); color: var(--danger); }
        .badge svg { width: 1.75rem; height: 1.75rem; }
        .code { font-size: .75rem; font-weight: 700; letter-spacing: .18em; text-transform: uppercase; color: rgb(var(--content) / 0.4); }
        h1 { margin: .25rem 0 .5rem; font-family: Oswald, Inter, sans-serif; font-size: 1.5rem; font-weight: 600;
             letter-spacing: -.01em; text-transform: uppercase; }
        p { margin: 0 0 1.75rem; font-size: .875rem; color: rgb(var(--content) / 0.6); }
        .actions { display: flex; flex-direction: column; gap: .625rem; }
        .actions a, .actions button {
            display: inline-flex; align-items: center; justify-content: center; gap: .5rem;
            padding: .625rem 1.5rem; border-radius: .75rem; font-size: .875rem; font-weight: 700;
            text-decoration: none; cursor: pointer; border: 1px solid transparent;
        }
        .actions .primary { background: #c9a24a; color: #14110a; }
        .actions .secondary { background: transparent; border-color: rgb(var(--content) / 0.12); color: rgb(var(--content) / 0.7); }
    </style>
</head>
<body>
    <div class="card">
        <div class="badge">{!! $icon !!}</div>
        <div class="code">{{ $code }}</div>
        <h1>{{ $title }}</h1>
        <p>{{ $body }}</p>
        <div class="actions">
            {{ $slot }}
        </div>
    </div>
</body>
</html>
