<?php

return [
    'meta_title' => 'Onlayn yozilish — Blade Barbershop',
    'meta_description' => 'Blade barbershopiga onlayn yozilish. Xizmat, barber va qulay vaqtni tanlang.',

    'language' => 'Til',
    'minutes_short' => 'daq',
    'back' => 'Orqaga',

    'steps' => [
        'nav_label' => 'Band qilish bosqichlari',
        'service' => 'Xizmat',
        'barber' => 'Barber',
        'time' => 'Vaqt',
        'details' => 'Maʼlumotlar',
    ],

    'service' => [
        'title' => 'Xizmatni tanlang',
        'subtitle' => 'Nima qilishni xohlaysiz?',
        'empty' => 'Xizmatlar hali qoʻshilmagan',
    ],

    'barber' => [
        'title' => 'Barberni tanlang',
        'subtitle' => 'Kim sizning barberingiz boʻladi?',
        'empty' => 'Barberlar hali qoʻshilmagan',
    ],

    'datetime' => [
        'title' => 'Sana va vaqt',
        'subtitle' => 'Sizga qachon qulay?',
        'today' => 'Bugun',
        'horizon' => 'Keyingi 14 kun uchun band qilish mumkin',
        'no_slots' => 'Bu kuni boʻsh vaqt yoʻq. Boshqa sanani tanlang.',
        'taken' => 'Bu vaqtga allaqachon yozuv bor',
        'taken_bookable' => 'Bu vaqtga allaqachon yozuv bor — ustiga yozish mumkin',
        'taken_short' => 'band',
        'past' => 'Bu vaqt allaqachon oʻtib ketdi',
        'past_bookable' => 'Bu vaqt allaqachon oʻtib ketdi — baribir yozish mumkin',
        'past_short' => 'oʻtdi',
    ],

    'confirm' => [
        'title' => 'Tasdiqlash',
        'subtitle' => 'Tafsilotlarni tekshiring va kontaktlarni kiriting',
        'name' => 'Ism',
        'name_placeholder' => 'Ismingiz nima?',
        'phone' => 'Telefon',
        'phone_hint' => 'Avval telefon raqamini kiriting',
        'client_found' => 'Bu raqam bazada bor — ismingizni tasdiqlang',
        'birth_date' => 'Tugʻilgan sana',
        'optional' => '(ixtiyoriy)',
        'submit' => 'Yozuvni tasdiqlash',
        'submitting' => 'Band qilinmoqda…',
    ],

    'success' => [
        'title' => 'Ariza qabul qilindi!',
        'message' => 'Yozuvingizni tasdiqlash uchun :date kuni soat :time da :barber barberga bogʻlanamiz.',
        'sms_note' => 'Tasdiqlangach, tashrifdan 30 daqiqa oldin SMS eslatma keladi',
        'booking_number' => 'Yozuv raqami',
        'service' => 'Xizmat',
        'price' => 'Narxi',
        'contacts_title' => 'Salon kontaktlari',
        'open_bot' => 'Telegram botni ochish',
        'again' => 'Yana yozilish',
    ],

    'validation' => [
        'name' => 'ism',
        'phone' => 'telefon',
        'birth_date' => 'tugʻilgan sana',
        'time' => 'vaqt',
        'date' => 'sana',
        'invalid_phone' => 'Toʻgʻri raqam kiriting: 998XXXXXXXXX',
        'reselect' => 'Xizmat va ustani qaytadan tanlang.',
        'too_many' => 'Juda koʻp soʻrov. Bir daqiqadan soʻng urinib koʻring.',
        'slot_taken' => 'Bu vaqt band boʻlib qoldi. Boshqa vaqtni tanlang.',
    ],
];
