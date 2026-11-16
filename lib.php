<?php
/**
 * Hol van tea? – közös segédfüggvények (api.php és distance.php között megosztva).
 */

declare(strict_types=1);

/** shops.txt beolvasása. */
function loadShops(string $file): array
{
    if (!is_readable($file)) {
        return [];
    }
    $shops = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $f = array_map('trim', explode('|', $line));
        if (count($f) < 3) {
            continue;
        }
        $shops[] = [
            'id'       => $f[0],
            'name'     => $f[1],
            'address'  => $f[2],
            'placeId'  => $f[3] ?? '',
            'fallback' => $f[4] ?? '',
        ];
    }
    return $shops;
}

/** Egyszerű HTTP kliens (curl, vagy ha nincs, stream context). */
function httpJson(string $method, string $url, array $headers, ?array $body = null): ?array
{
    $headers[] = 'Content-Type: application/json';
    $payload   = $body === null ? null : json_encode($body, JSON_UNESCAPED_UNICODE);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_POSTFIELDS     => $payload,
        ]);
        $raw  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => [
            'method'        => $method,
            'header'        => implode("\r\n", $headers),
            'content'       => $payload,
            'timeout'       => 8,
            'ignore_errors' => true,
        ]]);
        $raw  = @file_get_contents($url, false, $ctx);
        $code = isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m) ? (int)$m[1] : 0;
    }

    if ($raw === false || $code < 200 || $code >= 300) {
        error_log("holvantea: Google API $code – " . substr((string)$raw, 0, 1500));
        return null;
    }
    $json = json_decode($raw, true);
    return is_array($json) ? $json : null;
}

function googleHeaders(string $key, string $fieldMask): array
{
    return [
        'X-Goog-Api-Key: ' . $key,
        'X-Goog-FieldMask: ' . $fieldMask,
    ];
}

/** Place ID keresése név + cím alapján (Text Search). Eredmény a cache/place_ids.json-ba kerül. */
function resolvePlaceId(array $shop, string $key, string $cacheDir): ?string
{
    $file = $cacheDir . '/place_ids.json';
    $map  = is_readable($file) ? (json_decode((string)file_get_contents($file), true) ?: []) : [];
    if (!empty($map[$shop['id']])) {
        return $map[$shop['id']];
    }
    $res = httpJson(
        'POST',
        'https://places.googleapis.com/v1/places:searchText',
        googleHeaders($key, 'places.id'),
        ['textQuery' => $shop['name'] . ', ' . $shop['address'], 'languageCode' => 'hu', 'regionCode' => 'HU', 'pageSize' => 1]
    );
    $id = $res['places'][0]['id'] ?? null;
    if ($id) {
        $map[$shop['id']] = $id;
        @file_put_contents($file, json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
    return $id;
}

/** Kézzel megadott Place ID csak akkor számít, ha formailag is az (nincs benne szóköz, vessző, per jel). */
function looksLikePlaceId(string $value): bool
{
    return (bool)preg_match('/^[A-Za-z0-9_-]{20,}$/', $value);
}

/** Az adott üzlet Place ID-ja: kézi megadás (ha érvényesnek tűnik), különben keresés + cache. */
function shopPlaceId(array $shop, string $key, string $cacheDir): ?string
{
    $manual = looksLikePlaceId($shop['placeId']) ? $shop['placeId'] : '';
    if ($shop['placeId'] !== '' && $manual === '') {
        error_log("holvantea: a(z) {$shop['id']} sor 4. oszlopa nem Place ID, név+cím alapján keresem: {$shop['placeId']}");
    }
    return $manual !== '' ? $manual : resolvePlaceId($shop, $key, $cacheDir);
}

// ------------------------------------------------------------------ cache

function cacheGet(string $cacheDir, string $key, int $ttl): ?array
{
    $file = $cacheDir . '/' . preg_replace('/[^a-z0-9_-]/i', '_', $key) . '.json';
    if (!is_readable($file) || filemtime($file) < time() - $ttl) {
        return null;
    }
    $data = json_decode((string)file_get_contents($file), true);
    return is_array($data) ? $data : null;
}

function cachePut(string $cacheDir, string $key, array $data): void
{
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }
    $file = $cacheDir . '/' . preg_replace('/[^a-z0-9_-]/i', '_', $key) . '.json';
    @file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

/**
 * Írható cache könyvtár kiválasztása. Ha a beállított mappa nem használható
 * (pl. open_basedir korlátozás cPanelen), a rendszer tmp mappájába esik vissza.
 */
function pickCacheDir(string $preferred): string
{
    $candidates = [$preferred, sys_get_temp_dir() . '/holvantea-cache'];
    foreach ($candidates as $dir) {
        if (!@is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (@is_dir($dir) && @is_writable($dir)) {
            return $dir;
        }
    }
    return $preferred;
}

function loadConfig(): array
{
    $config = require __DIR__ . '/config.php';
    date_default_timezone_set($config['timezone']);
    return $config;
}
