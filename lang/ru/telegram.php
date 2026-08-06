<?php

return [
    // Linked page
    'linked_title' => 'Telegram · Привязанные',
    'linked_subtitle' => 'Клиенты и мастера с подключённым Telegram',
    'col_chat_id' => 'Chat ID',
    'search_placeholder' => 'Имя или телефон',
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
    'broadcast_confirm' => 'Отправить :count получателям (:audience)? Действие нельзя отменить.',
    'err_no_recipients' => 'Нет получателей с привязанным Telegram.',
    'queue_stalled' => 'Очередь не разгребается: заданий в ожидании — :count, самое старое ждёт уже :minutes мин. Проверьте queue-воркер.',

    // Recent broadcasts card
    'recent_broadcasts_title' => 'Последние рассылки',
    'col_audience' => 'Аудитория',
    'col_message' => 'Сообщение',
    'col_result' => 'Результат',
    'sent_label' => 'отправлено',
    'errors_label' => 'ошибок',
    'status_processing' => 'Обрабатывается',
    'empty_broadcasts' => 'Рассылок пока нет',

    // Templates page
    'templates_title' => 'Telegram · Шаблоны',
    'templates_subtitle' => 'Тексты уведомлений в Telegram',
    'templates_saved' => 'Шаблоны сохранены',
    'html_note' => 'Поддерживается HTML-разметка Telegram: <b>, <i>, эмодзи и переносы строк.',
    'variables' => 'Переменные:',
    'save_templates' => 'Сохранить шаблоны',
    'new_for_barber_label' => 'Новая запись — мастеру',
    'new_for_barber_hint' => 'Когда клиент записался к мастеру.',
    'new_for_client_label' => 'Новая запись — клиенту',
    'new_for_client_hint' => 'Когда клиент записался (сразу после брони).',
    'confirmed_for_client_label' => 'Подтверждение записи — клиенту',
    'confirmed_for_client_hint' => 'Когда салон подтвердил запись.',
    'cancelled_for_barber_label' => 'Отмена записи — мастеру',
    'cancelled_for_barber_hint' => 'Когда запись отменена.',
    'cancelled_for_client_label' => 'Отмена записи — клиенту',
    'cancelled_for_client_hint' => 'Когда запись клиента отменена.',
    'reminder_for_client_label' => 'Напоминание — клиенту',
    'reminder_for_client_hint' => 'За 30 минут до визита.',
    'reminder_for_barber_label' => 'Напоминание — мастеру',
    'reminder_for_barber_hint' => 'За 30 минут до записи.',

    // Bot messages — client-facing, multilingual by clients.locale (#76)
    'welcome_back' => 'С возвращением! Выберите действие 👇',
    'welcome_new' => "👋 Добро пожаловать в <b>Blade Barbershop</b>!\n\nЧтобы продолжить, поделитесь своим номером телефона — мы найдём ваш профиль.",
    'not_linked' => 'Сначала привяжите профиль командой /start.',

    'contact_wrong_owner' => 'Пожалуйста, отправьте именно свой номер кнопкой ниже.',
    'contact_barber_linked' => '✅ Профиль мастера привязан. Теперь вам доступно расписание и заработок.',
    'contact_client_linked' => '✅ Профиль найден! Здесь ваши записи, история и напоминания.',
    'contact_client_created' => "✅ Готово! Мы будем присылать напоминания о записях.\nЗаписи и история появятся после первого визита.",
    'contact_invalid_phone' => '❌ Не удалось распознать номер. Он должен быть в формате 998XXXXXXXXX.',

    'no_upcoming_appointments' => '📭 У вас нет предстоящих записей.',
    'your_appointments_title' => '📅 <b>Ваши записи</b>',
    'no_history' => '📭 История визитов пока пуста.',
    'history_title' => '🕓 <b>Последние визиты</b>',
    'no_debt' => '✅ За вами нет задолженности.',
    'debt_amount' => '💳 <b>Ваш долг:</b> :amount',

    'cancel_button' => '❌ Отменить',
    'cancel_done_alert' => '✅ Запись отменена.',
    'cancel_done_message' => '❌ Запись отменена по вашей просьбе.',
    'cancel_unavailable' => 'Эту запись уже нельзя отменить.',

    // Reply-keyboard labels — рендерятся на языке клиента, поэтому текст кнопки
    // регистрируется в маршрутах на всех языках сразу (Keyboards::labelVariants()).
    'kb_share_contact' => '📱 Поделиться номером',
    'kb_today' => '📅 Сегодня',
    'kb_tomorrow' => '🗓 Завтра',
    'kb_earnings' => '💰 Заработок',
    'kb_appointments' => '📅 Мои записи',
    'kb_history' => '🕓 История',
    'kb_debt' => '💳 Долг',
];
