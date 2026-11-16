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
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

$config = loadConfig();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function badRequest(string $msg): void
{
    http_response_code(400);
    echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
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
$lat = (float)$in['lat'];
$lng = (float)$in['lng'];
if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || ($lat === 0.0 && $lng === 0.0)) {
    badRequest('invalid lat/lng');
}

$cacheDir = pickCacheDir($config['cache_dir']);
$shops    = loadShops($config['shops_file']);
if (!$shops) {
    echo json_encode(['shops' => new stdClass()], JSON_UNESCAPED_UNICODE);
    exit;
}

// Cím szerinti (nem placeId-s) üzleteknél a szöveges cím megy waypointként.
$destinations = [];
foreach ($shops as $shop) {
    $placeId = shopPlaceId($shop, $key, $cacheDir);
    $destinations[] = $placeId
        ? ['waypoint' => ['placeId' => $placeId]]
        : ['waypoint' => ['address' => $shop['address']]];
}

// 5-6 tizedesjegyre kerekített origó -> kb. 100 m pontosságú cache-kulcs, 3 percig érvényes.
// Így egy-egy böngésző-újratöltés vagy véletlen dupla kattintás nem kér le kétszer mindent.
$cacheKey = sprintf('route_%.4f_%.4f', $lat, $lng);
$cached   = cacheGet($cacheDir, $cacheKey, 180);
if ($cached !== null) {
    echo json_encode($cached, JSON_UNESCAPED_UNICODE);
    exit;
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
cachePut($cacheDir, $cacheKey, $out);

echo json_encode($out, JSON_UNESCAPED_UNICODE);
