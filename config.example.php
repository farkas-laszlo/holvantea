<?php
// Hol van tea? – beállítások (minta)
// Másold le config.php néven, és írd be a saját API kulcsodat.
// A config.php NE kerüljön git repóba – a .gitignore kizárja.

return [
    // Google Cloud API kulcs (Places API (New) + Routes API engedélyezve). Üresen hagyva a tartalék nyitvatartás érvényesül.
    'google_api_key' => '',

    // Meddig legyen érvényes a Google-től lekért nyitvatartás (másodperc). 6 óra: napi ~4 lekérés/üzlet.
    'cache_ttl' => 6 * 3600,

    // Cache könyvtár (írhatónak kell lennie a webszerver számára).
    // Ha a tárhelyen open_basedir hiba jön rá, az api.php magától a /tmp-be vált,
    // vagy állítsd be kézzel: sys_get_temp_dir() . '/holvantea-cache'
    'cache_dir' => __DIR__ . '/cache',

    // Adatbázis fájl.
    'shops_file' => __DIR__ . '/shops.txt',

    // Időzóna.
    'timezone' => 'Europe/Budapest',

    // ---- Védelem a túlzott használat (és a Google-számla) ellen ----
    // Ezek elhagyhatók: ha hiányoznak, az itt látható alapértékek érvényesek. 0 = nincs korlát (nem ajánlott).

    // Odajutási idő: ennyi új Google-lekérés naponta / havonta (egy lekérés = 4 mátrixhívás, üzletenként 4 elem).
    // 450/hó 7 üzlettel kb. 3150 Pro és 9450 Essentials elem: a Routes API ingyenes havi keretén belül marad.
    'travel_daily_limit'   => 30,
    'travel_monthly_limit' => 450,

    // Odajutási idő: egy IP-címről legfeljebb ennyi új lekérés 10 percenként.
    'travel_per_ip' => 10,

    // Csak innen kérhető odajutási idő (Budapest és környéke).
    'travel_area' => ['minLat' => 47.20, 'maxLat' => 47.80, 'minLng' => 18.60, 'maxLng' => 19.60],

    // Biztonsági plafon: összesen ennyi Google-hívás naponta (nyitvatartás + odajutás együtt).
    'google_daily_limit' => 300,
];
