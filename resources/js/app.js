//
// Транспортная отказоустойчивость Livewire (issue #75). Планшет в
// барбершопе живёт на мобильном интернете: оборванный запрос не должен
// показывать стоковый английский оверлей Livewire и не должен стирать
// наполовину заполненную форму записи/продажи.
//
// Перевод и вся разметка баннера/модалки живут в
// resources/views/components/transport-status.blade.php — здесь только
// перехват сети и события `transport-*` на window, которые эта разметка
// слушает. Ни одной русской строки в этом файле быть не должно.
//
document.addEventListener('livewire:init', () => {
    // Элемент, чей клик/сабмит запустил упавший запрос — это единственный
    // документированный способ узнать, что повторять. `data-loading`
    // (Livewire v4) ставится на него на время запроса и снимается только в
    // message-level onFinish — то есть он ещё на месте, когда сработает
    // request-level onError/onFailure ниже.
    let retryTarget = null

    // На дашборде рядом с кликом пользователя висит `wire:poll`, и его запрос
    // тоже помечает свой элемент. Повторять опрос по кнопке «Повторить»
    // бессмысленно — он и сам придёт на следующем тике, — поэтому polling-и
    // из кандидатов отбрасываем.
    const findRetryTarget = () => [...document.querySelectorAll('[data-loading]')]
        .find((el) => ! [...el.attributes].some((attr) => attr.name.startsWith('wire:poll'))) ?? null

    // Род отказа, а не «что-то упало». Сервер, ответивший 403, не «потерял
    // связь», и повтор того же запроса даст тот же 403 — кнопка «Повторить»
    // на таком коде превращается в бесконечный цикл по одной и той же
    // отклонённой команде. Текст и наличие повтора выбирает разметка
    // transport-status по этому виду.
    const classifyStatus = (status) => {
        if (status >= 500) {
            return 'server'
        }

        if (status === 403) {
            return 'forbidden'
        }

        if (status === 404) {
            return 'missing'
        }

        return 'rejected'
    }

    const reportError = (kind) => {
        retryTarget = findRetryTarget()
        window.dispatchEvent(new CustomEvent('transport-error', { detail: { kind } }))
    }

    Livewire.interceptRequest(({ onError, onFailure }) => {
        onError(({ response, preventDefault }) => {
            // Не даём Livewire показать нативный confirm() на 419 или
            // HTML-дамп ошибки на прочих кодах — обе формы английские и обе
            // не оставляют пользователю ничего, кроме перезагрузки страницы.
            preventDefault()

            if (response.status === 419) {
                retryTarget = findRetryTarget()
                window.dispatchEvent(new CustomEvent('transport-session-expired'))
                return
            }

            reportError(classifyStatus(response.status))
        })

        // Запрос не дошёл вообще — обрыв сети, DNS, таймаут. response нет.
        onFailure(() => reportError('network'))
    })

    // Кнопка «Повторить» в баннере/модалке шлёт это событие, а не сама
    // хранит ссылку на элемент: баннер живёт в layout и переживает морфинг
    // компонентов, тогда как retryTarget актуален только на момент отказа.
    window.addEventListener('transport-retry', () => {
        const el = retryTarget
        retryTarget = null

        if (!el || !el.isConnected) {
            return
        }

        if (el.tagName === 'FORM') {
            el.requestSubmit()
        } else {
            el.click()
        }
    })
})

//
// Общее состояние левого меню — свёрнуто/развёрнуто и открыта ли шторка на
// телефоне (issue #94). Живёт в глобальном сторе, а не в `x-data` на <body>:
// навигация Livewire делает `document.body.replaceWith(newBody)` и следом
// `Alpine.destroyTree(oldBody)`, тогда как `@persist('sidebar')` переносит в
// новый документ ту же самую ноду <aside>, не переинициализируя её. Держи
// состояние на <body> — и после первого же перехода шапка писала бы в новую
// область видимости, а меню читало старую, уничтоженную. Стор один на всё
// приложение и переживает обе операции.
//
document.addEventListener('alpine:init', () => {
    Alpine.store('sidebar', {
        open: false,
        collapsed: localStorage.getItem('sidebarCollapsed') === '1',

        toggleCollapsed() {
            this.collapsed = ! this.collapsed
            localStorage.setItem('sidebarCollapsed', this.collapsed ? '1' : '0')
        },
    })
})

//
// Тёмная тема переживает переход по меню (issue #95). Класс `dark` висит на
// <html> и ставится инлайновым скриптом в <head> — до первой отрисовки, чтобы
// не мигало белым. Но навигация Livewire вызывает `replaceHtmlAttributes`,
// которая переписывает атрибуты <html> тем, что пришло с сервера. Сервер про
// тему не знает (она в localStorage), поэтому в разметке всегда
// `class="h-full"` — и `dark` стирался на каждом переходе. Инлайновый скрипт
// заново не выполняется: mergeNewHead не перезапускает уже стоящие в <head> теги.
//
// Логика выбора темы обязана совпадать с тем самым инлайновым скриптом
// (resources/views/components/layouts/{app,booking,auth}.blade.php).
//
const prefersDarkTheme = () => {
    try {
        const stored = localStorage.getItem('theme')

        return stored ? stored === 'dark' : window.matchMedia('(prefers-color-scheme: dark)').matches
    } catch (e) {
        return false
    }
}

document.addEventListener('livewire:navigated', () => {
    const dark = prefersDarkTheme()

    document.documentElement.classList.toggle('dark', dark)

    // `livewire:navigated` приходит уже ПОСЛЕ того, как Alpine поднял новую
    // страницу, так что каждый свежий x-theme-toggle успел прочитать классы
    // <html> в момент, когда `dark` там ещё не было, и показал бы луну на
    // тёмном фоне. Тем же событием, что и обычное переключение, возвращаем
    // иконкам актуальное состояние.
    window.dispatchEvent(new CustomEvent('theme-changed', { detail: { dark } }))
})
