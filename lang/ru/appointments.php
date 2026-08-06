<?php

return [
    'title' => 'Записи',
    'subtitle' => 'Управление журналом посещений',
    'all_barbers' => 'Все мастера',
    'new' => 'Новая запись',
    'empty' => 'На этот день записей нет',

    // Modal
    'edit_title' => 'Редактирование записи',
    'create_title' => 'Создание новой записи',
    'search_client' => 'Поиск клиента...',
    'select_barber' => 'Выберите мастера...',
    'payment_method' => 'Способ оплаты',
    'on_debt' => 'В долг',
    'debt_hint' => 'Если клиент не оплачивает сейчас',
    'debt_client_required' => 'Клиент обязателен при долге',
    'add_service' => 'Добавить услугу',
    'select_service' => 'Выберите услугу...',
    'duplicate' => 'дубль',
    'no_services_hint' => 'Нет услуг — нажмите «Добавить услугу»',
    'one_hour' => '1 ч',
    'start' => 'Начало',
    'end' => 'Окончание',
    'select_placeholder' => '— выберите —',
    'note' => 'Заметка',
    'note_placeholder' => 'Дополнительная информация...',
    'save_changes' => 'Сохранить изменения',
    'create' => 'Создать запись',

    // Table
    'client_note' => 'Клиент / Заметка',
    'services_total' => 'Услуги / Итого',
    'cash_short' => 'нал',
    'card_short' => 'карта',
    'complete' => 'Завершить',
    'cancel_action' => 'Отменить',
    'delete_confirm' => 'Удалить запись?',
    'cancel_confirm' => 'Отменить запись?',
    'complete_confirm' => 'Завершить запись?',
    'return_to_confirmed' => 'Вернуть в подтверждённые',
    'discard_confirm' => 'Закрыть без сохранения?',

    // Validation
    'err_end_after_start' => 'Время окончания должно быть позже времени начала.',
    'err_add_service' => 'Добавьте хотя бы одну услугу.',
    'err_delete_has_payments' => 'По этой записи есть погашения долга — удаление сдвинет кассу других дней. Сначала удалите погашения.',
    'debt_already_paid' => 'По этому долгу уже принято :amount сум.',
    'err_service_once' => 'Каждая услуга должна быть указана только один раз.',
];
