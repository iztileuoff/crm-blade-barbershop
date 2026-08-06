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

    Livewire.interceptRequest(({ onError, onFailure }) => {
        onError(({ response, preventDefault }) => {
            // Не даём Livewire показать нативный confirm() на 419 или
            // HTML-дамп ошибки на прочих кодах — обе формы английские и обе
            // не оставляют пользователю ничего, кроме перезагрузки страницы.
            preventDefault()

            retryTarget = findRetryTarget()

            if (response.status === 419) {
                window.dispatchEvent(new CustomEvent('transport-session-expired'))
                return
            }

            window.dispatchEvent(new CustomEvent('transport-error'))
        })

        // Запрос не дошёл вообще — обрыв сети, DNS, таймаут. response нет.
        onFailure(() => {
            retryTarget = findRetryTarget()
            window.dispatchEvent(new CustomEvent('transport-error'))
        })
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
