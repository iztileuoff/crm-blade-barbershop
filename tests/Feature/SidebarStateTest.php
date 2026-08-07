<?php

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Issue #94 — «Свернуть/развернуть» умирало после первого wire:navigate
|--------------------------------------------------------------------------
|
| <aside> завёрнута в @persist('sidebar'), а wire:navigate делает
| document.body.replaceWith(newBody) + Alpine.destroyTree(oldBody). Перенесённая
| нода сохраняет свои биндинги, но они навсегда смотрят в область видимости
| уничтоженного <body>. Значит, состояние, которым меню обменивается с шапкой,
| не может жить ни в одном x-data — только в глобальном сторе.
|
| Браузерного драйвера в проекте нет, поэтому клик проверить нечем; вместо этого
| закрываем сам класс дефекта — разделяемое имя в локальной области видимости.
*/

/**
 * Оболочка админки: всё, что рендерится и внутри @persist('sidebar'), и снаружи.
 *
 * @return array<int, string>
 */
function sidebarShellFiles(): array
{
    return [
        'components/layouts/app.blade.php',
        'components/layouts/booking.blade.php',
        'components/admin-header.blade.php',
        'components/theme-toggle.blade.php',
    ];
}

it('keeps the shared sidebar state out of every local Alpine scope in the admin shell', function (string $relativePath) {
    $source = file_get_contents(resource_path('views/'.$relativePath));

    // Blade-комментарии объясняют как раз этот запрет и сами называют `open`/`collapsed`.
    $source = preg_replace('/\{\{--.*?--\}\}/s', '', $source);

    // (?<![\w.$]) отсекает и `$store.sidebar.collapsed`, и `nav.open_menu`: там перед
    // словом стоит точка. Остаётся ровно голый идентификатор из чьего-то x-data.
    preg_match_all('/(?<![\w.$])(collapsed|open)\b/', $source, $matches, PREG_OFFSET_CAPTURE);

    $offenders = array_map(function (array $match) use ($source): string {
        $line = substr_count(substr($source, 0, $match[1]), "\n") + 1;

        return sprintf('%s (строка %d в файле без комментариев)', $match[0], $line);
    }, $matches[0]);

    expect($offenders)->toBe([], sprintf(
        '%s читает состояние меню из локальной области видимости — после первого wire:navigate '.
        "она разъедется с той, в которую пишет шапка. Нужен \$store.sidebar.*:\n%s",
        $relativePath,
        implode("\n", $offenders)
    ));
})->with(sidebarShellFiles());

it('registers the sidebar store once, in a module the navigation never re-runs', function () {
    $appJs = file_get_contents(resource_path('js/app.js'));

    // alpine:init, а не livewire:init: стор должен существовать до первого рендера,
    // иначе `$store.sidebar.collapsed` на <aside> упадёт на undefined.
    expect($appJs)->toContain("document.addEventListener('alpine:init'")
        ->toContain("Alpine.store('sidebar'")
        ->toContain("localStorage.getItem('sidebarCollapsed')")
        // Ширина должна переживать перезагрузку — раньше это делал $watch на <body>,
        // который теперь перевешивался бы на каждом переходе впустую.
        ->toContain("localStorage.setItem('sidebarCollapsed'");
});

it('binds the rendered sidebar and the header toggle to that one store', function () {
    $admin = User::factory()->create(['role' => Role::SUPER_ADMIN]);

    $html = $this->actingAs($admin)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->getContent();

    // Сама <aside> — то, что переживает навигацию.
    expect($html)->toContain("'lg:w-20': \$store.sidebar.collapsed")
        ->toContain("'translate-x-0!': \$store.sidebar.open")
        // Кнопка в шапке — то, что при навигации создаётся заново.
        ->toContain('$store.sidebar.toggleCollapsed()')
        // Отступ основного контента — третий читатель того же значения.
        ->toContain("\$store.sidebar.collapsed ? 'lg:pl-20' : 'lg:pl-64'");
});
