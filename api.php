<?php
/**
 * Hol van tea? – szerveroldali proxy a Google Places API (New) felé.
 *
 * Beolvassa a shops.txt fájlt, minden üzlethez lekéri a nyitvatartást a Google-től
 * (gyorsítótárral), és egységes JSON-t ad vissza a böngészőnek. Az API kulcs
 * soha nem kerül ki a kliensre.
 *
 * Válasz: { generatedAt, timezone, shops: [ { id, name, address, mapsUrl, website, phone, source, periods: [...] } ] }
 *   periods: [ { open: {day,hour,minute}, close: {day,hour,minute} } ]  (day: 0 = vasárnap … 6 = szombat)
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

$config = loadConfig();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// ------------------------------------------------ tartalék nyitvatartás parse

/** "H-Szo 10:00-21:00; V 10:00-19:00" -> periods tömb */
function parseFallbackHours(string $spec): array
{
    $dayIndex = ['V' => 0, 'H' => 1, 'K' => 2, 'SZE' => 3, 'CS' => 4, 'P' => 5, 'SZO' => 6];
    $order    = [1, 2, 3, 4, 5, 6, 0]; // hétfőtől vasárnapig
    $periods  = [];

    foreach (explode(';', $spec) as $chunk) {
        $chunk = trim($chunk);
        if ($chunk === '') {
            continue;
        }
        if (!preg_match('/^(\S+)\s+(\d{1,2}):(\d{2})-(\d{1,2}):(\d{2})$/u', $chunk, $m)) {
            continue;
        }
        $days = [];
        foreach (explode(',', $m[1]) as $range) {
            $range = mb_strtoupper(trim($range));
            if (strpos($range, '-') !== false) {
                [$a, $b] = array_map('trim', explode('-', $range, 2));
                if (!isset($dayIndex[$a], $dayIndex[$b])) {
                    continue;
                }
                $ia = array_search($dayIndex[$a], $order, true);
                $ib = array_search($dayIndex[$b], $order, true);
                for ($i = $ia; ; $i = ($i + 1) % 7) {
                    $days[] = $order[$i];
                    if ($i === $ib) {
                        break;
                    }
                }
            } elseif (isset($dayIndex[$range])) {
                $days[] = $dayIndex[$range];
            }
        }
        foreach ($days as $d) {
            $closeDay = $d;
            $oh = (int)$m[2]; $om = (int)$m[3]; $ch = (int)$m[4]; $cm = (int)$m[5];
            if ($ch * 60 + $cm <= $oh * 60 + $om) { // éjfél utáni zárás
                $closeDay = ($d + 1) % 7;
            }
            $periods[] = [
                'open'  => ['day' => $d, 'hour' => $oh, 'minute' => $om],
                'close' => ['day' => $closeDay, 'hour' => $ch, 'minute' => $cm],
            ];
        }
    }
    return $periods;
}

// ------------------------------------------------------------ Google Places

/** Nyitvatartás + kapcsolat lekérése (Place Details). */
function fetchPlace(string $placeId, string $key): ?array
{
    return httpJson(
        'GET',
        'https://places.googleapis.com/v1/places/' . rawurlencode($placeId) . '?languageCode=hu&regionCode=HU',
        googleHeaders($key, 'id,displayName,formattedAddress,googleMapsUri,websiteUri,nationalPhoneNumber,internationalPhoneNumber,regularOpeningHours,currentOpeningHours')
    );
}

/** Google periods -> egységes forma. A currentOpeningHours (ünnepnapokkal) elsőbbséget élvez. */
function normalizeGooglePeriods(array $place): array
{
    $src = $place['currentOpeningHours']['periods'] ?? $place['regularOpeningHours']['periods'] ?? [];
    $out = [];
    foreach ($src as $p) {
        if (!isset($p['open'])) {
            continue;
        }
        $open = $p['open'];
        if (!isset($p['close'])) { // 0–24
            return [['open' => ['day' => 0, 'hour' => 0, 'minute' => 0], 'close' => ['day' => 0, 'hour' => 0, 'minute' => 0], 'allWeek' => true]];
        }
        $close = $p['close'];
        $out[] = [
            'open'  => ['day' => (int)$open['day'], 'hour' => (int)($open['hour'] ?? 0), 'minute' => (int)($open['minute'] ?? 0)],
            'close' => ['day' => (int)$close['day'], 'hour' => (int)($close['hour'] ?? 0), 'minute' => (int)($close['minute'] ?? 0)],
        ];
    }
    return $out;
}

// ------------------------------------------------------------------- main

$key      = trim((string)$config['google_api_key']);
$cacheDir = pickCacheDir($config['cache_dir']);
$ttl      = (int)$config['cache_ttl'];
$result   = [];

foreach (loadShops($config['shops_file']) as $shop) {
    $entry = [
        'id'      => $shop['id'],
        'name'    => $shop['name'],
        'address' => $shop['address'],
        'mapsUrl' => 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($shop['name'] . ' ' . $shop['address']),
        'website' => null,
        'phone'   => null,
        'source'  => 'fallback',
        'periods' => parseFallbackHours($shop['fallback']),
    ];

    if ($key !== '') {
        $cached = cacheGet($cacheDir, 'place_' . $shop['id'], $ttl);
        if ($cached === null) {
            $placeId = shopPlaceId($shop, $key, $cacheDir);
            $place   = $placeId ? fetchPlace($placeId, $key) : null;
            if ($place === null && $shop['placeId'] !== '' && looksLikePlaceId($shop['placeId'])) {
                // a kézi ID-t a Google elutasította -> keresés név+cím alapján
                $placeId = resolvePlaceId($shop, $key, $cacheDir);
                $place   = $placeId ? fetchPlace($placeId, $key) : null;
            }
            if ($place && !empty($place['id'])) {
                $cached = [
                    'placeId' => $place['id'],
                    'mapsUrl' => $place['googleMapsUri'] ?? $entry['mapsUrl'],
                    'website' => $place['websiteUri'] ?? null,
                    'phone'   => $place['nationalPhoneNumber'] ?? $place['internationalPhoneNumber'] ?? null,
                    'address' => $place['formattedAddress'] ?? $shop['address'],
                    'periods' => normalizeGooglePeriods($place),
                    'fetched' => date('c'),
                ];
                cachePut($cacheDir, 'place_' . $shop['id'], $cached);
            }
        }
        if ($cached !== null && !empty($cached['periods'])) {
            $entry['mapsUrl'] = $cached['mapsUrl'];
            $entry['website'] = $cached['website'];
            $entry['phone']   = $cached['phone'] ?? null;
            $entry['periods'] = $cached['periods'];
            $entry['source']  = 'google';
            $entry['fetched'] = $cached['fetched'] ?? null;
        }
    }

    $result[] = $entry;
}

echo json_encode([
    'generatedAt' => date('c'),
    'timezone'    => $config['timezone'],
    'hasApiKey'   => $key !== '',
    'shops'       => $result,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
