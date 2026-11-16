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
];
