<?php

return [
    // Linked page
    'linked_title' => 'Telegram · Привязанные',
    'linked_subtitle' => 'Клиенты и мастера с подключённым Telegram',
    'col_chat_id' => 'Chat ID',
    'unlink' => 'Отвязать',
    'unlink_confirm' => 'Отвязать Telegram у «:name»?',
    'empty_linked' => 'Нет привязанных пользователей',

    // Broadcast page
    'broadcast_title' => 'Telegram · Рассылка',
    'broadcast_subtitle' => 'Сообщение всем привязанным пользователям',
    'bot_not_configured' => 'Бот не настроен: задайте TELEGRAM_TOKEN в .env. Сообщения не будут доставлены.',
    'queued' => 'Рассылка поставлена в очередь · получателей: :count',
    'audience_label' => 'Кому отправить',
    'audience_clients' => 'Клиентам',
    'audience_barbers' => 'Мастерам',
    'audience_all' => 'Всем',
    'message_label' => 'Текст сообщения',
    'message_placeholder' => 'Например: Завтра работаем с 10:00. Ждём вас!',
    'send' => 'Отправить рассылку',
    'err_no_recipients' => 'Нет получателей с привязанным Telegram.',

    // Templates page
    'templates_title' => 'Telegram · Шаблоны',
    'templates_subtitle' => 'Тексты уведомлений в Telegram',
    'templates_saved' => 'Шаблоны сохранены',
    'html_note' => 'Поддерживается HTML-разметка Telegram: <b>, <i>, эмодзи и переносы строк.',
    'variables' => 'Переменные:',
    'save_templates' => 'Сохранить шаблоны',
    'new_for_barber_label' => 'Новая запись — мастеру',
    'new_for_barber_hint' => 'Когда клиент записался к мастеру.',
    'cancelled_for_barber_label' => 'Отмена записи — мастеру',
    'cancelled_for_barber_hint' => 'Когда запись отменена.',
    'cancelled_for_client_label' => 'Отмена записи — клиенту',
    'cancelled_for_client_hint' => 'Когда запись клиента отменена.',
    'reminder_for_client_label' => 'Напоминание — клиенту',
    'reminder_for_client_hint' => 'За 30 минут до визита.',
    'reminder_for_barber_label' => 'Напоминание — мастеру',
    'reminder_for_barber_hint' => 'За 30 минут до записи.',
];
