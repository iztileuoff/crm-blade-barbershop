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

    // Server javob berdi — lekin rad etib. Qayta urinishdan foyda yo'q: o'sha
    // so'rov o'sha javobni beradi, shuning uchun sahifani yangilash taklif etiladi.
    'server_error_title' => 'Serverda xatolik',
    'server_error_body' => 'So\'rovni bajara olmadik. Bir daqiqadan so\'ng qayta urinib ko\'ring.',
    'forbidden_title' => 'Ruxsat yo\'q',
    'forbidden_body' => 'Bu amal sizga ochiq emas. Huquqlar o\'zgargan bo\'lishi mumkin — sahifani yangilang.',
    'missing_title' => 'Hech narsa topilmadi',
    'missing_body' => 'Yozuv o\'chirilgan yoki o\'zgartirilgan ko\'rinadi. Sahifani yangilang.',
    'rejected_title' => 'So\'rov rad etildi',
    'rejected_body' => 'Server so\'rovni rad etdi. Sahifani yangilab, qayta urinib ko\'ring.',

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
