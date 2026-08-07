<?php

return [
    // wire:offline strip — brauzer óziniń oflayn dep esaplaǵanda kórinedi
    'offline_indicator' => 'Internet baylanısı joq',

    // Bir Livewire sorawı úzildi: tarmaq úzildi yamasa server qátelik
    // qayttı. Forma óshirilmeydi — qayta urınıwdı usınamız.
    'connection_lost_title' => 'Baylanıs úzildi',
    'connection_lost_body' => 'Saqlanbadı. Baylanıstı tekserip, qayta urınıp kóriń.',
    'retry' => 'Qayta urınıw',
    'dismiss' => 'Jabıw',

    // Jumıs waqtında 419: sessiya tamamlandı, biraq ekrandaǵı forma tiri —
    // «Jańalaw» basıwdıń ornına jańa vkladkada kiriwdi usınamız.
    'session_expired_title' => 'Sessiya tamamlandı',
    'session_expired_body' => 'Sessiya waqtı tamamlandı. Jańa vkladkada qayta kiriń — bul forma joǵalmaydı.',
    'login_again' => 'Qayta kiriw',

    // Ashıq bronda mıymandaǵı sol 419: onıń «qayta kiretuǵın» sessiyası joq —
    // bettiń CSRF-tokeni tamamlanǵan, jańalaw menen sheshiledi.
    'session_expired_body_guest' => 'Bet júdá uzaq ashıq turdı. Onı jańalap, jazılıwdı qayta jiberiń.',

    // Tolıq betli errors/419|500|503.blade.php — sessiyasız hám bazasız
    // render etiledi, sonlıqtan matın usı jerde qatań, dinamikasız.
    'page_419_title' => 'Sessiya tamamlandı',
    'page_419_body' => 'Sessiya waqtı tamamlandı. Bettі jańalap, qayta kiriń.',
    'page_500_title' => 'Bir nárse qátelesti',
    'page_500_body' => 'Serverde qátelik júz berdi. Bir minuttan soń bettі jańalap kóriń.',
    'page_503_title' => 'Texnikalıq jumıslar',
    'page_503_body' => 'Xızmet waqtınsha islemeydi. Biz bul haqqında bilemiz — biraz waqıttan soń qayta kiriń.',
    'page_reload' => 'Bettі jańalaw',
    'page_go_login' => 'Kiriw betine ótiw',
];
