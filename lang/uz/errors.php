<?php

return [
    // wire:offline strip — brauzer o'zi oflayn deb hisoblasa ko'rinadi
    'offline_indicator' => 'Internet aloqasi yo\'q',

    // Aynan bitta Livewire so'rovi uzildi: tarmoq uzildi yoki server xato
    // qaytardi. Forma o'chirilmaydi — shunchaki qayta urinishni taklif qilamiz.
    'connection_lost_title' => 'Aloqa uzildi',
    'connection_lost_body' => 'Saqlab bo\'lmadi. Aloqani tekshirib, qayta urinib ko\'ring.',
    'retry' => 'Qayta urinish',
    'dismiss' => 'Yopish',

    // Ish jarayonida 419: sessiya tugadi, lekin ekrandagi forma tirik —
    // "Yangilash" bosish o'rniga yangi vkladkada kirishni taklif qilamiz.
    'session_expired_title' => 'Sessiya tugadi',
    'session_expired_body' => 'Sessiya vaqti tugadi. Yangi vkladkada qayta kiring — bu forma yo\'qolmaydi.',
    'login_again' => 'Qayta kirish',

    // Ochiq bronda mehmondagi o'sha 419: uning "qayta kiradigan" sessiyasi yo'q —
    // sahifaning CSRF-tokeni tugagan, yangilash bilan hal bo'ladi.
    'session_expired_body_guest' => 'Sahifa juda uzoq ochiq turdi. Uni yangilab, yozuvni qayta yuboring.',

    // To'liq sahifali errors/419|500|503.blade.php — sessiyasiz va bazasiz
    // renderlanadi, shuning uchun matn shu yerda qat'iy, dinamikasiz.
    'page_419_title' => 'Sessiya tugadi',
    'page_419_body' => 'Sessiya vaqti tugadi. Sahifani yangilab, qayta kiring.',
    'page_500_title' => 'Nimadir noto\'g\'ri ketdi',
    'page_500_body' => 'Serverda xatolik yuz berdi. Bir daqiqadan so\'ng sahifani yangilab ko\'ring.',
    'page_503_title' => 'Texnik ishlar',
    'page_503_body' => 'Xizmat vaqtincha mavjud emas. Biz allaqachon bilamiz — birozdan so\'ng qayta kiring.',
    'page_reload' => 'Sahifani yangilash',
    'page_go_login' => 'Kirish sahifasiga o\'tish',
];
