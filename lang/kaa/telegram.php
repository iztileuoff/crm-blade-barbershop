<?php

return [
    // Linked page
    'linked_title' => 'Telegram · Baylanısqan',
    'linked_subtitle' => 'Telegram jalǵanǵan klientler hám barberler',
    'col_chat_id' => 'Chat ID',
    'search_placeholder' => 'At yaki telefon',
    'unlink' => 'Ajıratıw',
    'unlink_confirm' => '«:name» den Telegram ajıratılsınba?',
    'empty_linked' => 'Baylanısqan paydalanıwshılar joq',

    // Broadcast page
    'broadcast_title' => 'Telegram · Tarqatıw',
    'broadcast_subtitle' => 'Barlıq baylanısqan paydalanıwshılarǵa xabar',
    'bot_not_configured' => 'Bot sazlanbaǵan: .env faylında TELEGRAM_TOKEN dı belgileń. Xabarlar jetkizilmeydi.',
    'queued' => 'Tarqatıw gezekke qoyıldı · qabıl etiwshiler: :count',
    'audience_label' => 'Kimge jiberiw',
    'audience_clients' => 'Klientlerge',
    'audience_barbers' => 'Barberlerge',
    'audience_all' => 'Hámmege',
    'message_label' => 'Xabar teksti',
    'message_placeholder' => 'Mısalı: Erteń 10:00 den islaymiz. Kútemiz!',
    'send' => 'Tarqatıwdı jiberiw',
    'broadcast_confirm' => ':count qabıl etiwshige (:audience) jiberilsinbe? Bul ámeldi biykarlaw múmkin emes.',
    'err_no_recipients' => 'Telegram baylanısqan qabıl etiwshiler joq.',
    'queue_stalled' => 'Gezek islenbey atır: kútip atırǵan wazıypalar — :count, eń eskisi :minutes minut kútip atır. Queue-workerdi tekseriń.',

    // Recent broadcasts card
    'recent_broadcasts_title' => 'Aqırǵı tarqatıwlar',
    'col_audience' => 'Auditoriya',
    'col_message' => 'Xabar',
    'col_result' => 'Nátiyje',
    'sent_label' => 'jiberildi',
    'errors_label' => 'qátelik',
    'status_processing' => 'Islenip atır',
    'empty_broadcasts' => 'Hazirshe tarqatıwlar joq',

    // Templates page
    'templates_title' => 'Telegram · Shablonlar',
    'templates_subtitle' => 'Telegram bildiriw tekstleri',
    'templates_saved' => 'Shablonlar saqlandı',
    'html_note' => 'Telegram HTML belgilew qollap-quwwatlanadı: <b>, <i>, emoji hám qatar awıstırıw.',
    'variables' => 'Ózgeriwshiler:',
    'save_templates' => 'Shablonlardı saqlaw',
    'new_for_barber_label' => 'Jańa jazılıw — barberge',
    'new_for_barber_hint' => 'Klient barberge jazılǵanda.',
    'new_for_client_label' => 'Jańa jazılıw — klientke',
    'new_for_client_hint' => 'Klient jazılǵanda (bronlanǵannan soń).',
    'confirmed_for_client_label' => 'Jazılıw tastıyıqlandı — klientke',
    'confirmed_for_client_hint' => 'Salon jazılıwdı tastıyıqlaǵanda.',
    'cancelled_for_barber_label' => 'Jazılıw biykarlandı — barberge',
    'cancelled_for_barber_hint' => 'Jazılıw biykarlanǵanda.',
    'cancelled_for_client_label' => 'Jazılıw biykarlandı — klientke',
    'cancelled_for_client_hint' => 'Klient jazılıwı biykarlanǵanda.',
    'reminder_for_client_label' => 'Esletpe — klientke',
    'reminder_for_client_hint' => 'Keliwden 30 minut aldın.',
    'reminder_for_barber_label' => 'Esletpe — barberge',
    'reminder_for_barber_hint' => 'Jazılıwdan 30 minut aldın.',

    // Bot xabarları — klientke, clients.locale boyınsha kóp tilli (#76)
    'welcome_back' => 'Qaytıp kelgenińizge quwanamız! Ámeldi saylań 👇',
    'welcome_new' => "👋 <b>Blade Barbershop</b>qa xosh keldińiz!\n\nDawam etiw ushın telefon nomerińizdi bólisiń — profilińizdi tabamız.",
    'not_linked' => 'Aldın /start buyrıǵı menen profildi baylanıstırıń.',

    'contact_wrong_owner' => 'Ótinish, ózińizdiń nomerińizdi tómendegi túymesheden jiberiń.',
    'contact_barber_linked' => '✅ Barber profili baylanıstırıldı. Endi kesteler hám daramat sizge ashıq.',
    'contact_client_linked' => '✅ Profil tabıldı! Bul jerde jazılıwlarıńız, tariyx hám esletpeler.',
    'contact_client_created' => "✅ Tayyar! Jazılıwlar haqqında esletpeler jiberip turamız.\nJazılıwlar hám tariyx birinshi kelıwden keyin payda boladı.",
    'contact_invalid_phone' => '❌ Nomerdi anıqlaw múmkin bolmadı. Ol 998XXXXXXXXX formatında bolıwı kerek.',

    'no_upcoming_appointments' => '📭 Sizde aldaǵı jazılıwlar joq.',
    'your_appointments_title' => '📅 <b>Sizdiń jazılıwlarıńız</b>',
    'upcoming_truncated' => '<i>:total ishinen eń jaqın :shown kórsetildi — qalǵanı ushın salonǵa múrájat etiń.</i>',
    'no_history' => '📭 Kelıw tariyxı házirshe bos.',
    'history_title' => '🕓 <b>Aqırǵı kelıwler</b>',
    'no_debt' => '✅ Sizde qarızdarlıq joq.',
    'debt_amount' => '💳 <b>Qarızıńız:</b> :amount',

    'cancel_button' => '❌ Biykarlaw',
    'cancel_done_alert' => '✅ Jazılıw biykarlandı.',
    'cancel_done_message' => '❌ Jazılıw sizdiń sorawıńız boyınsha biykarlandı.',
    'cancel_unavailable' => 'Bul jazılıwdı endi biykarlaw múmkin emes.',

    // Reply-klaviatura yarlıqları — klient tilinde sızıladı, sonlıqtan túyme
    // teksti marshrutlarda barlıq tiller ushın birden dizimge alınadı (Keyboards::labelVariants()).
    'kb_share_contact' => '📱 Nomerdi bólisiw',
    'kb_today' => '📅 Búgin',
    'kb_tomorrow' => '🗓 Erteń',
    'kb_earnings' => '💰 Daramatım',
    'kb_appointments' => '📅 Jazılıwlarım',
    'kb_history' => '🕓 Tariyx',
    'kb_debt' => '💳 Qarız',
];
