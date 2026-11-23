# Hol van tea? – telepítési útmutató

Egyszerű, reszponzív oldal, ami megmutatja, melyik budapesti teabolt van éppen nyitva, és nyitás szerint sorba rendezi őket. A nyitvatartást a Google Places API-ból kéri le egy kis PHP proxy, gyorsítótárral. Emellett kártyánként megmutatja az üzlet weboldalát és telefonszámát, jelzi, ha egy nyitva lévő bolt hamarosan zár, megosztható kártyánként, és kérésre kiszámolja az odajutási időt is (autó, tömegközlekedés, bicikli, gyalog – forgalommal).

## Fájlok

| Fájl | Szerep |
|---|---|
| `index.html` | A teljes felület (HTML + CSS + JS egyben). Magyar/angol váltó a fejlécben. |
| `lib.php` | Közös segédfüggvények (`api.php` és `distance.php` ezt használja). |
| `api.php` | Nyitvatartás, weboldal, telefonszám lekérése a Google-től, cache-eléssel. |
| `distance.php` | Odajutási idő lekérése a látogató helyzetéből (Google Routes API), rövid cache-eléssel. |
| `config.example.php` | Minta beállítások – `config.php` néven lemásolva: API kulcs, cache idő, időzóna. |
| `shops.txt` | Mini adatbázis – egy sor egy üzlet. |
| `cache/` | Ide kerülnek a lekért adatok. Írhatónak kell lennie (ha nem az, az `api.php`/`distance.php` automatikusan a rendszer `/tmp`-jébe vált). |
| `manifest.json`, `sw.js`, `icons/` | A „kezdőképernyőre tehető” (PWA) működéshez. |

## Telepítés cPanel tárhelyre

1. Töltsd fel a repó **teljes** tartalmát a kívánt helyre (pl. `public_html/tea/`) – a `lib.php`-t is, e nélkül az `api.php` és a `distance.php` sem indul. A `docs/` mappát nem kötelező feltölteni.
2. Állítsd be, hogy a `cache/` mappa írható legyen a webszerver számára (általában 755 elég cPanelen, mert a PHP a te felhasználóddal fut; ha nem, 775).
3. Másold le a `config.example.php`-t `config.php` néven, és írd be az API kulcsot (`google_api_key`). A `config.php` a `.gitignore` miatt nem kerül a repóba – így is kell maradnia.
4. Nyisd meg az oldalt. A lábléc mutatja, hogy Google-adat vagy tartalék nyitvatartás látszik-e.

Kulcs nélkül is működik: ilyenkor a `shops.txt` utolsó oszlopában megadott tartalék nyitvatartás érvényesül (a lábléc ezt barnával jelzi), weboldal/telefon/odajutási idő pedig nem jelenik meg (ezek csak a Google-től jönnek).

## Google API-k bekapcsolása

Ugyanaz az API kulcs használható mindkét funkcióhoz, de **két külön Google API-t** kell bekapcsolni hozzá a Cloud Console-ban:

1. Menj a Google Cloud Console-ra: <https://console.cloud.google.com/> és jelentkezz be.
2. Hozz létre egy új projektet (fent a projektválasztó → **New project**), pl. „holvantea”.
3. Kapcsold be a számlázást a projekthez (**Billing**). Kell hozzá bankkártya, de mindkét API-nak van ingyenes havi keret.
4. **APIs & Services → Library**, és kapcsold be mindkettőt:
   - **Places API (New)** – ez adja a nyitvatartást, weboldalt, telefonszámot. (Fontos: a *New* változat kell, nem a régi „Places API”.)
   - **Routes API** – ez adja az odajutási időt. Enélkül a „Mutasd, mennyi idő ideérni” gomb hibaüzenetet ad.
5. **APIs & Services → Credentials → Create credentials → API key**. Másold ki a kulcsot.
6. Korlátozd a kulcsot (**Edit API key**):
   - **API restrictions → Restrict key** → pipáld ki mindkettőt: *Places API (New)* és *Routes API*.
   - **Application restrictions**: mivel szerverről hívjuk, választhatod az **IP addresses** opciót a tárhely kimenő IP-jével (cPanel → „Server information” vagy kérdezd meg a szolgáltatót). Ha bizonytalan az IP, hagyd „None”-on – az API-korlátozás önmagában is véd.
7. Írd be a kulcsot a `config.php`-ba.

Az első betöltéskor az `api.php` név + cím alapján kikeresi az üzletek Google Place ID-ját (Text Search), és elmenti a `cache/place_ids.json`-ba, így ezt csak egyszer teszi (a `distance.php` ugyanezt a cache-elt Place ID-t használja fel). Ha egy üzletnél rossz helyet talál, írd be kézzel a Place ID-t a `shops.txt` 4. oszlopába (Place ID-t itt lehet keresni: <https://developers.google.com/maps/documentation/places/web-service/place-id>).

### Költség az odajutási időnél

Egy kattintás a „Mutasd, mennyi idő ideérni” gombra 4 Google-hívást indít (autó/tömegközlekedés/bicikli/gyalog egyenként egy hívással, mind az 5 üzletre egyszerre – ún. mátrixhívás). A Route Matrixot a Google elemenként számlázza (indulópont × célpont): 7 üzlettel egy kattintás 28 elem, ebből az autós 7 a drágább, forgalomfigyelős „Pro” kategória.

### Védelem botok és elszálló számla ellen

A `distance.php` több réteggel védi a Google-keretet (a `config.php`-ban állíthatók, ha hiányoznak, az alapértékek érvényesek):

| Védelem | Alapérték |
|---|---|
| Csak POST, csak a saját oldalról (Origin fejléc) | – |
| Csak Budapest és környéke (`travel_area`) | é. sz. 47,2–47,8 · k. h. 18,6–19,6 |
| Koordináta kerekítése, 3 perces cache ugyanarra a helyre | kb. 100 m |
| IP-nként új lekérés 10 percenként (`travel_per_ip`) | 10 |
| Új odajutási lekérés naponta (`travel_daily_limit`) | 30 |
| Új odajutási lekérés havonta (`travel_monthly_limit`) | 450 – a Routes API ingyenes havi keretén belül |
| Összes Google-hívás naponta, nyitvatartással együtt (`google_daily_limit`) | 300 |

A cache-ből kiszolgált válasz nem számít bele a keretekbe. Ha egy keret elfogy, az oldal ezt szövegesen jelzi, a nyitvatartás ettől függetlenül működik. A számlálók a `cache/` mappában vannak (`cnt_*.txt`), a régi fájlokat a kód magától takarítja.

**Ajánlott még a Google Cloud Console-ban** (ez a kódtól függetlenül is véd):
- **APIs & Services → Routes API / Places API (New) → Quotas**: állíts be napi kérésplafont (pl. Routes: 200/nap, Places: 500/nap). Ez kemény korlát, efölött a Google nem szolgál ki.
- **Billing → Budgets & alerts**: költségkeret-értesítés (pl. 5 USD). Ez csak e-mailt küld, a számlázást nem állítja le.

## Üzletek szerkesztése

A `shops.txt` formátuma:

```
azonosító|név|cím|Place ID (opcionális)|tartalék nyitvatartás
```

Tartalék nyitvatartás példa: `H-Szo 10:00-21:00; V 10:00-19:00`. Napok: `H K Sze Cs P Szo V`, tartomány kötőjellel, több blokk pontosvesszővel.

Új üzlet felvétele után törölhető a `cache/` tartalma, hogy azonnal frissüljön, de nem kötelező – a következő cache-lejáratkor magától frissül.

## Hogyan működik a sorrend

A böngésző Budapest idő szerint kiszámolja minden üzletre:

- **Nyitva** (mosolygó arc, sötétzöld) – felül, a később záró van elöl. Ha egy nyitva lévő bolt 30 percen belül zár, a kártya bal széle sötétsárgára vált, és a záráskor helyett „X perc múlva zár!” szöveg jelenik meg.
- **Hamarosan nyit** (óra, sötétkék) – 60 percen belül nyit.
- **Zárva** (hold, sötétszürke) – több mint 1 óra múlva nyit; a hamarabb nyitó előrébb kerül.

A kártyára kattintva (a weboldal/telefon/megosztás gombok kivételével) az üzlet Google Térkép-adatlapja nyílik meg. A státuszt az oldal 30 másodpercenként újraszámolja, a nyitvatartást 15 percenként újra letölti. Az odajutási idő nem frissül automatikusan – a „Frissítés” gombbal kérhető újra, ha a látogató időközben elmozdult.

## Odajutási idő

A „Mutasd, mennyi idő ideérni” gombra kattintva a böngésző rákérdez a helymeghatározás engedélyezésére. Elfogadás után a `distance.php` mind az 5 üzletre lekéri a Google Routes API-tól:

- **autóval** – valós idejű forgalommal (a Google `TRAFFIC_AWARE` beállítása),
- **tömegközlekedéssel** – az aktuális menetrend szerint,
- **biciklivel**,
- **gyalog**.

Ha a látogató nem engedélyezi a helymeghatározást, vagy a böngészője nem támogatja, ezt az oldal jelzi, de minden más funkció (nyitvatartás, kontakt, megosztás) ettől függetlenül működik.

## Kezdőképernyőre tehető (PWA)

A `manifest.json` és `sw.js` miatt Androidon a böngésző felajánlja a „Hozzáadás a kezdőképernyőhöz” lehetőséget, és az oldal egyszer megnyitva offline is betölt (csak a felület, a nyitvatartás/odajutás adatok mindig friss internetkapcsolatot igényelnek). iPhone-on a Safari megosztás-menüjéből lehet kezdőképernyőre tenni; ott a `icons/icon-180.png` jelenik meg ikonként.
