<?php

return [
    // Linked page
    'linked_title' => 'Telegram · Bogʻlangan',
    'linked_subtitle' => 'Telegram ulangan mijozlar va barberlar',
    'col_chat_id' => 'Chat ID',
    'search_placeholder' => 'Ism yoki telefon',
    'unlink' => 'Uzish',
    'unlink_confirm' => '«:name» dan Telegram uzilsinmi?',
    'empty_linked' => 'Bogʻlangan foydalanuvchilar yoʻq',

    // Broadcast page
    'broadcast_title' => 'Telegram · Tarqatma',
    'broadcast_subtitle' => 'Barcha bogʻlangan foydalanuvchilarga xabar',
    'bot_not_configured' => 'Bot sozlanmagan: .env faylida TELEGRAM_TOKEN ni belgilang. Xabarlar yetkazilmaydi.',
    'queued' => 'Tarqatma navbatga qoʻyildi · qabul qiluvchilar: :count',
    'audience_label' => 'Kimga yuborish',
    'audience_clients' => 'Mijozlarga',
    'audience_barbers' => 'Barberlarga',
    'audience_all' => 'Hammaga',
    'message_label' => 'Xabar matni',
    'message_placeholder' => 'Masalan: Ertaga 10:00 dan ishlaymiz. Kutamiz!',
    'send' => 'Tarqatmani yuborish',
    'broadcast_confirm' => ':count qabul qiluvchiga (:audience) yuborilsinmi? Bu amalni bekor qilib boʻlmaydi.',
    'err_no_recipients' => 'Telegram bogʻlangan qabul qiluvchilar yoʻq.',
    'queue_stalled' => 'Navbat ishlanmayapti: kutayotgan vazifalar — :count, eng eskisi allaqachon :minutes daqiqa kutmoqda. Queue-workerni tekshiring.',

    // Recent broadcasts card
    'recent_broadcasts_title' => 'Soʻnggi tarqatmalar',
    'col_audience' => 'Auditoriya',
    'col_message' => 'Xabar',
    'col_result' => 'Natija',
    'sent_label' => 'yuborildi',
    'errors_label' => 'xato',
    'status_processing' => 'Ishlanmoqda',
    'empty_broadcasts' => 'Hozircha tarqatmalar yoʻq',

    // Templates page
    'templates_title' => 'Telegram · Shablonlar',
    'templates_subtitle' => 'Telegram bildirishnoma matnlari',
    'templates_saved' => 'Shablonlar saqlandi',
    'html_note' => 'Telegram HTML belgilash qoʻllab-quvvatlanadi: <b>, <i>, emoji va satr koʻchirish.',
    'variables' => 'Oʻzgaruvchilar:',
    'save_templates' => 'Shablonlarni saqlash',
    'new_for_barber_label' => 'Yangi yozuv — barberga',
    'new_for_barber_hint' => 'Mijoz barberga yozilganda.',
    'new_for_client_label' => 'Yangi yozuv — mijozga',
    'new_for_client_hint' => 'Mijoz yozilganda (bron qilinishi bilanoq).',
    'confirmed_for_client_label' => 'Yozuv tasdiqlandi — mijozga',
    'confirmed_for_client_hint' => 'Salon yozuvni tasdiqlaganda.',
    'cancelled_for_barber_label' => 'Yozuv bekor qilindi — barberga',
    'cancelled_for_barber_hint' => 'Yozuv bekor qilinganda.',
    'cancelled_for_client_label' => 'Yozuv bekor qilindi — mijozga',
    'cancelled_for_client_hint' => 'Mijoz yozuvi bekor qilinganda.',
    'reminder_for_client_label' => 'Eslatma — mijozga',
    'reminder_for_client_hint' => 'Tashrifdan 30 daqiqa oldin.',
    'reminder_for_barber_label' => 'Eslatma — barberga',
    'reminder_for_barber_hint' => 'Yozuvdan 30 daqiqa oldin.',

    // Bot xabarlari — mijozga, clients.locale boʻyicha koʻp tilli (#76)
    'welcome_back' => 'Xush kelibsiz! Amalni tanlang 👇',
    'welcome_new' => "👋 <b>Blade Barbershop</b>ga xush kelibsiz!\n\nDavom etish uchun telefon raqamingizni ulashing — profilingizni topamiz.",
    'not_linked' => 'Avval /start buyrugʻi bilan profilni ulang.',

    'contact_wrong_owner' => 'Iltimos, aynan oʻzingizning raqamingizni pastdagi tugma orqali yuboring.',
    'contact_barber_linked' => '✅ Barber profili ulandi. Endi jadval va daromad sizga ochiq.',
    'contact_client_linked' => '✅ Profil topildi! Bu yerda yozuvlaringiz, tarix va eslatmalar.',
    'contact_client_created' => "✅ Tayyor! Yozuvlar haqida eslatmalar yuborib turamiz.\nYozuvlar va tarix birinchi tashrifdan keyin paydo boʻladi.",
    'contact_invalid_phone' => '❌ Raqamni aniqlab boʻlmadi. U 998XXXXXXXXX formatida boʻlishi kerak.',

    'no_upcoming_appointments' => '📭 Sizda kelayotgan yozuvlar yoʻq.',
    'your_appointments_title' => '📅 <b>Sizning yozuvlaringiz</b>',
    'upcoming_truncated' => '<i>:total tadan eng yaqin :shown tasi koʻrsatildi — qolgani uchun salonga murojaat qiling.</i>',
    'no_history' => '📭 Tashriflar tarixi hali boʻsh.',
    'history_title' => '🕓 <b>Soʻnggi tashriflar</b>',
    'no_debt' => '✅ Sizda qarzdorlik yoʻq.',
    'debt_amount' => '💳 <b>Qarzingiz:</b> :amount',

    'cancel_button' => '❌ Bekor qilish',
    'cancel_done_alert' => '✅ Yozuv bekor qilindi.',
    'cancel_done_message' => '❌ Yozuv soʻrovingiz boʻyicha bekor qilindi.',
    'cancel_unavailable' => 'Bu yozuvni endi bekor qilib boʻlmaydi.',

    // Reply-klaviatura yorliqlari — mijoz tilida chiziladi, shuning uchun tugma
    // matni marshrutlarda barcha tillar uchun birdan roʻyxatga olinadi (Keyboards::labelVariants()).
    'kb_share_contact' => '📱 Raqamni ulashish',
    'kb_today' => '📅 Bugun',
    'kb_tomorrow' => '🗓 Ertaga',
    'kb_earnings' => '💰 Daromadim',
    'kb_appointments' => '📅 Yozuvlarim',
    'kb_history' => '🕓 Tarix',
    'kb_debt' => '💳 Qarz',
];
