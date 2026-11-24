<?php
/**
 * Hol van tea? – odajutási idő a látogató helyzetéből, valós idejű forgalommal.
 *
 * A böngésző POST-ol egy { lat, lng } koordinátát, ez a végpont pedig a Google
 * Routes API-val (computeRouteMatrix) lekéri mind az 5 teaboltra, mind a 4
 * közlekedési módra (autó – forgalommal, tömegközlekedés, bicikli, gyalog) az
 * odajutási időt. Módonként EGY hívás fedi le mind az 5 üzletet (mátrixhívás),
 * tehát összesen 4 Google-hívás történik kattintásonként, nem 20.
 *
 * A kulcshoz külön API-t kell engedélyezni: "Routes API" (nem ugyanaz, mint a
 * Places API). Lásd a README.md-t.
 *
 * Válasz: { shops: { <id>: { drive: {minutes}|null, transit: {...}|null, bicycle: {...}|null, walk: {...}|null } } }
 *
 * Védelem a túlzott használat (és a Google-számla) ellen:
 *  - csak POST, és csak a saját oldalról (Origin fejléc),
 *  - a koordináta kb. 100 m-re kerekítve (kevesebb egyedi lekérés, jobb cache-találat),
 *  - IP-nként legfeljebb travel_per_ip lekérés 10 percenként,
 *  - napi (travel_daily_limit) és havi (travel_monthly_limit) plafon a Google-lekérésekre,
 *  - plusz a lib.php napi biztonsági plafonja minden Google-hívásra (google_daily_limit).
 * A cache-ből kiszolgált válasz nem számít bele a keretekbe.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

$config = loadConfig();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function fail(int $status, string $msg): void
{
    http_response_code($status);
    echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function badRequest(string $msg): void
{
    fail(400, $msg);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    fail(405, 'method_not_allowed');
}
// A böngésző POST-nál mindig küld Origin fejlécet: más oldalról ne lehessen ezt a végpontot hívogatni.
$origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
$host   = strtolower(preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
if ($origin !== '' && strtolower((string)parse_url($origin, PHP_URL_HOST)) !== $host) {
    fail(403, 'forbidden');
}

$key = trim((string)$config['google_api_key']);
if ($key === '') {
    http_response_code(503);
    echo json_encode(['error' => 'no_api_key'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input');
$in  = json_decode((string)$raw, true);
if (!is_array($in) || !isset($in['lat'], $in['lng'])) {
    badRequest('missing lat/lng');
}
// kb. 100 m-re kerekítve: az odajutási időn ez nem látszik, de sokkal kevesebb egyedi lekérés lehet
$lat = round((float)$in['lat'], 3);
$lng = round((float)$in['lng'], 3);
if (!is_finite($lat) || !is_finite($lng) || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || ($lat === 0.0 && $lng === 0.0)) {
    badRequest('invalid lat/lng');
}

$cacheDir = pickCacheDir($config['cache_dir']);
googleGuardInit($cacheDir, $config);
cacheHousekeeping($cacheDir);
$shops    = loadShops($config['shops_file']);
if (!$shops) {
    echo json_encode(['shops' => new stdClass()], JSON_UNESCAPED_UNICODE);
    exit;
}

// Ugyanarra a helyre 3 percig a mentett eredmény megy (ez nem számít bele a keretekbe).
$cacheKey = sprintf('route_%.3f_%.3f', $lat, $lng);
$cached   = cacheGet($cacheDir, $cacheKey, 180);
if ($cached !== null) {
    echo json_encode($cached, JSON_UNESCAPED_UNICODE);
    exit;
}

// Új Google-lekérés: IP-nkénti, napi és havi keret.
if (!rateLimitOk($cacheDir, 'travel', (int)($config['travel_per_ip'] ?? 10), 600)) {
    header('Retry-After: 600');
    fail(429, 'rate_limited');
}
if (!counterTake($cacheDir, 'travel_' . date('Ymd'), (int)($config['travel_daily_limit'] ?? 30))
    || !counterTake($cacheDir, 'travel_' . date('Ym'), (int)($config['travel_monthly_limit'] ?? 450))) {
    fail(429, 'daily_limit');
}

// Cím szerinti (nem placeId-s) üzleteknél a szöveges cím megy waypointként.
$destinations = [];
foreach ($shops as $shop) {
    $placeId = shopPlaceId($shop, $key, $cacheDir);
    $destinations[] = $placeId
        ? ['waypoint' => ['placeId' => $placeId]]
        : ['waypoint' => ['address' => $shop['address']]];
}

/**
 * Egy közlekedési mód lekérése az összes úti célra egyetlen hívással.
 * A "TRAFFIC_AWARE" routingPreference csak autónál/motoros kétkerekűnél
 * elérhető, ezért csak DRIVE módnál küldjük el.
 */
function fetchModeMatrix(string $key, float $lat, float $lng, array $destinations, string $mode): array
{
    $body = [
        'origins'      => [['waypoint' => ['location' => ['latLng' => ['latitude' => $lat, 'longitude' => $lng]]]]],
        'destinations' => $destinations,
        'travelMode'   => $mode,
    ];
    if ($mode === 'DRIVE') {
        $body['routingPreference'] = 'TRAFFIC_AWARE';
    }
    $res = httpJson(
        'POST',
        'https://routes.googleapis.com/distanceMatrix/v2:computeRouteMatrix',
        googleHeaders($key, 'originIndex,destinationIndex,duration,distanceMeters,condition,status'),
        $body
    );
    return is_array($res) ? $res : [];
}

function parseDuration(?string $iso): ?int
{
    // Google itt egyszerű "1234s" formában adja vissza, nem ISO 8601 P-vel.
    if ($iso === null || !preg_match('/^(\d+)s$/', $iso, $m)) {
        return null;
    }
    return (int)round(((int)$m[1]) / 60);
}

$modes = ['drive' => 'DRIVE', 'transit' => 'TRANSIT', 'bicycle' => 'BICYCLE', 'walk' => 'WALK'];
$byShop = [];
foreach ($shops as $shop) {
    $byShop[$shop['id']] = ['drive' => null, 'transit' => null, 'bicycle' => null, 'walk' => null];
}

foreach ($modes as $outKey => $googleMode) {
    $rows = fetchModeMatrix($key, $lat, $lng, $destinations, $googleMode);
    foreach ($rows as $row) {
        $idx = $row['destinationIndex'] ?? null;
        if ($idx === null || !isset($shops[$idx])) {
            continue;
        }
        $ok = ($row['condition'] ?? '') === 'ROUTE_EXISTS';
        $minutes = $ok ? parseDuration($row['duration'] ?? null) : null;
        if ($minutes !== null) {
            $byShop[$shops[$idx]['id']][$outKey] = [
                'minutes' => $minutes,
                'meters'  => $row['distanceMeters'] ?? null,
            ];
        }
    }
}

$out = ['shops' => $byShop, 'fetched' => date('c')];
$gotAny = false;
foreach ($byShop as $modesOfShop) {
    foreach ($modesOfShop as $v) {
        $gotAny = $gotAny || $v !== null;
    }
}
if (!$gotAny) {
    fail(502, 'google_error'); // pl. elfogyott a napi Google-plafon vagy hálózati hiba – üres eredményt nem mentünk el
}
cachePut($cacheDir, $cacheKey, $out);

echo json_encode($out, JSON_UNESCAPED_UNICODE);
