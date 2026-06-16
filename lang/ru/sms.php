<?php

return [
    // Settings page
    'settings_title' => 'SMS · Настройки Eskiz',
    'settings_subtitle' => 'Интеграция с Eskiz SMS',
    'env_note' => 'Логин и пароль Eskiz хранятся в файле .env (ESKIZ_EMAIL, ESKIZ_PASSWORD) и не редактируются из панели в целях безопасности.',
    'integration_status' => 'Состояние интеграции',
    'credentials' => 'Учётные данные',
    'configured' => 'Настроены',
    'not_configured' => 'Не настроены',
    'sender_name' => 'Имя отправителя (from)',
    'api_endpoint' => 'API endpoint',
    'connection' => 'Подключение',
    'success' => 'Успешно',
    'error' => 'Ошибка',
    'balance' => 'Баланс',
    'balance_unavailable' => 'недоступен',
    'check_connection' => 'Проверить подключение',
    'checking' => 'Проверка…',

    // History page
    'history_title' => 'SMS · История',
    'sent_label' => 'Отправлено',
    'errors_label' => 'Ошибок',
    'all_types' => 'Все типы',
    'all_statuses' => 'Все статусы',
    'sent' => 'Отправлено',
    'context_reminder' => 'Напоминание',
    'context_retention' => 'Удержание',
    'context_broadcast' => 'Рассылка',
    'context_manual' => 'Вручную',
    'col_recipient' => 'Получатель',
    'col_message' => 'Сообщение',
    'col_type' => 'Тип',
    'empty_history' => 'SMS пока не отправлялись',

    // Templates page
    'templates_title' => 'SMS · Шаблоны',
    'templates_subtitle' => 'Тексты SMS-сообщений клиентам',
    'templates_saved' => 'Шаблоны сохранены',
    'eskiz_note' => 'Eskiz отправляет только заранее одобренные тексты. После изменения шаблона согласуйте его в кабинете Eskiz, иначе SMS не доставится.',
    'variables' => 'Переменные:',
    'save_templates' => 'Сохранить шаблоны',
    'field_reminder_label' => 'Напоминание о записи (за 30 минут)',
    'field_reminder_hint' => 'Отправляется клиенту без Telegram перед визитом.',
    'field_retention_label' => 'Сообщение для удержания',
    'field_retention_hint' => 'Отправляется клиенту, который давно не приходил.',
];
