<?php
/**
 * weather-alerts.php
 * Fetches severe weather alerts from the National Weather Service public API.
 * No API key required. Filters to SENYLRC's primary service point in NY.
 * Only returns the event types listed in $WATCH_EVENTS below (tornado,
 * thunderstorm, tropical, flood, wind, extreme temperature, winter, air
 * quality, and Special Weather Statements).
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');

define('CACHE_FILE', __DIR__ . '/weather-cache.json');
define('CACHE_TTL',  300); // 5 minutes

// NWS point lookup for SENYLRC's primary service area.
// Using `point=` instead of a hand-maintained zone list means NWS resolves
// this to whichever zone/county actually contains this lat/lon — avoiding
// the earlier problem where a broad multi-county zone list pulled in
// alerts from unrelated areas (e.g. Broome/Tioga via a Binghamton-office
// warning) that had nothing to do with our actual location.
// 41.7212,-73.9632 = zip 12528 / New Paltz, NY (~NYZ064, Eastern Ulster).
$DEFAULT_POINT = '41.7212,-73.9632';

// Accept a custom point via query param (lat,lon), else fall back to default.
$point = preg_replace('/[^0-9.,\-]/', '', $_GET['point'] ?? $DEFAULT_POINT);
if (empty($point)) $point = $DEFAULT_POINT;

// Serve cache if fresh and same point
if (file_exists(CACHE_FILE)) {
    $cache = json_decode(file_get_contents(CACHE_FILE), true);
    if ($cache && isset($cache['ts'], $cache['point'])
        && $cache['point'] === $point
        && (time() - $cache['ts']) < CACHE_TTL) {
        echo json_encode($cache['data']);
        exit;
    }
}

// Alert types we care about — everything else is ignored.
// IMPORTANT: this includes Warning, Watch, and Advisory tiers for each
// hazard category. Previously only the highest (Warning) tier was
// whitelisted, so a genuinely distinct, lower-tier alert for the same
// hazard — e.g. a "Heat Advisory" starting the day after an "Extreme
// Heat Warning" ends, or a "Severe Thunderstorm Watch" — was silently
// dropped by the `if (!isset($WATCH_EVENTS[$event])) continue;` filter
// below, even though it wasn't a duplicate of anything else. That's
// what made the board appear to only ever show one alert even when NWS
// had multiple active/upcoming alerts for the same zone.
$WATCH_EVENTS = [
    // Tornado
    'Tornado Warning'               => ['color'=>'#ef4444', 'icon'=>'🌪️', 'priority'=>1],
 //   'Tornado Watch'                  => ['color'=>'#f87171', 'icon'=>'🌪️', 'priority'=>2],
    // Thunderstorm
    'Severe Thunderstorm Warning'   => ['color'=>'#f97316', 'icon'=>'⛈️', 'priority'=>3],
 //   'Severe Thunderstorm Watch'     => ['color'=>'#fb923c', 'icon'=>'⛈️', 'priority'=>4],
    // Hurricane / Tropical
    'Hurricane Warning'             => ['color'=>'#dc2626', 'icon'=>'🌀', 'priority'=>5],
    'Hurricane Watch'               => ['color'=>'#f87171', 'icon'=>'🌀', 'priority'=>6],
    'Tropical Storm Warning'        => ['color'=>'#ea580c', 'icon'=>'🌀', 'priority'=>6.5],
 //   'Tropical Storm Watch'          => ['color'=>'#fb923c', 'icon'=>'🌀', 'priority'=>6.7],
    // Flood
    'Flash Flood Warning'           => ['color'=>'#16a34a', 'icon'=>'🌊', 'priority'=>7],
//    'Flash Flood Watch'             => ['color'=>'#4ade80', 'icon'=>'🌊', 'priority'=>8],
    'Flood Warning'                 => ['color'=>'#15803d', 'icon'=>'💧', 'priority'=>9],
//    'Flood Watch'                   => ['color'=>'#4ade80', 'icon'=>'💧', 'priority'=>10],
    // Wind
    'High Wind Warning'             => ['color'=>'#a855f7', 'icon'=>'💨', 'priority'=>11],
//    'High Wind Watch'               => ['color'=>'#c084fc', 'icon'=>'💨', 'priority'=>12],
    // Extreme temperatures
    'Extreme Cold Warning'          => ['color'=>'#1d4ed8', 'icon'=>'🥶', 'priority'=>13],
//    'Extreme Cold Watch'            => ['color'=>'#3b82f6', 'icon'=>'🥶', 'priority'=>14],
    'Extreme Heat Warning'          => ['color'=>'#b45309', 'icon'=>'🌡️', 'priority'=>15],
    'Excessive Heat Warning'        => ['color'=>'#b45309', 'icon'=>'🌡️', 'priority'=>15],
//    'Excessive Heat Watch'          => ['color'=>'#d97706', 'icon'=>'🌡️', 'priority'=>16],
    'Heat Advisory'                 => ['color'=>'#f59e0b', 'icon'=>'🌡️', 'priority'=>16.5],
    // Winter
    'Blizzard Warning'              => ['color'=>'#2563eb', 'icon'=>'❄️', 'priority'=>17],
    'Winter Storm Warning'          => ['color'=>'#60a5fa', 'icon'=>'🌨️', 'priority'=>18],
//    'Winter Storm Watch'            => ['color'=>'#93c5fd', 'icon'=>'🌨️', 'priority'=>19],
//    'Winter Weather Advisory'       => ['color'=>'#93c5fd', 'icon'=>'🌨️', 'priority'=>20],
    'Ice Storm Warning'             => ['color'=>'#7dd3fc', 'icon'=>'🧊', 'priority'=>21],
    // Environmental / Air Quality
    'Air Quality Alert'              => ['color'=>'#6b7280', 'icon'=>'🌫️', 'priority'=>22],
    // Informational / non-severe
    // Special Weather Statements cover hazards that don't rise to warning or
    // advisory level (patchy dense fog, gusty non-severe winds, brief heavy
    // downpours, a squall moving through). They're worth showing on the board
    // but must never outrank an actual warning, so they sit last in the
    // priority order and use a calm blue rather than an alarm color.
    'Special Weather Statement'      => ['color'=>'#0284c7', 'icon'=>'🌦️', 'priority'=>23],
];

$url = 'https://api.weather.gov/alerts/active?point=' . urlencode($point) . '&status=actual&message_type=alert,update';
// NWS asks API clients to identify themselves. The contact address is read from
// config.local.php (optional NWS_CONTACT) so it is not published in source control.
require_once __DIR__ . '/paths.php';
// Optional setting only: if the private dir is unavailable, just send the generic User-Agent.
$privateDir = senytv_private_dir();
if ($privateDir !== null && is_file($privateDir . '/config.local.php')) require_once $privateDir . '/config.local.php';
$ua = 'SENYLRC-DisplayBoard/1.0' . (defined('NWS_CONTACT') && NWS_CONTACT ? ' (' . NWS_CONTACT . ')' : '');
$ctx = stream_context_create(['http' => [
    'timeout'       => 8,
    'ignore_errors' => true,
    'header'        => "User-Agent: {$ua}\r\nAccept: application/geo+json\r\n",
]]);

$raw = @file_get_contents($url, false, $ctx);
if (!$raw) {
    http_response_code(503);
    echo json_encode(['ok'=>false, 'error'=>'Could not reach NWS API']);
    exit;
}

$data = json_decode($raw, true);
if (!$data || !isset($data['features'])) {
    http_response_code(503);
    echo json_encode(['ok'=>false, 'error'=>'Invalid NWS response']);
    exit;
}

// Build alert list, deduping repeat NEW/UPDATE messages for the same
// ongoing warning down to a single entry (keeping the most recently
// `sent` version). NWS keeps issuing "update" messages for an alert
// that's still active — without this, the same warning would show up
// as multiple near-identical entries and the board would appear to be
// "stuck" cycling between duplicates instead of genuinely different
// watches/warnings.
$alerts  = [];
$byVtec  = []; // dedupe key => index in $alerts

foreach ($data['features'] as $feature) {
    $props = $feature['properties'] ?? [];
    $event = $props['event'] ?? '';
    if (!isset($WATCH_EVENTS[$event])) continue;

    $meta = $WATCH_EVENTS[$event];
    $sent = $props['sent'] ?? '';

    // ── Headline for Special Weather Statements ──────────────────────────
    // For a warning, the NWS `headline` is informative enough once the
    // front-end strips the "issued ... by NWS ..." boilerplate, because the
    // event name itself ("Severe Thunderstorm Warning") carries the meaning.
    // A Special Weather Statement has no such meaning — its headline is
    // literally "Special Weather Statement issued July 21 at 9:28PM EDT by
    // NWS Albany NY", which collapses to just the event name and leaves the
    // banner showing "SPECIAL WEATHER STATEMENT / Special Weather Statement".
    // The actual content lives in `description`, so build the headline from
    // the HAZARD line plus the lead paragraph:
    //   "Wind gusts up to 50 mph. · At 928 PM EDT, Doppler radar was
    //    tracking a strong thunderstorm near Poughkeepsie, moving north..."
    // Scoped to SPS on purpose. To use the same treatment for every event
    // type, drop the `$event === 'Special Weather Statement' &&` test below.
    $headline = trim($props['headline'] ?? '');
    if ($event === 'Special Weather Statement') {
        $desc   = trim($props['description'] ?? '');
        $paras  = preg_split('/\n\s*\n/', $desc);
        $lead   = trim(preg_replace('/\s+/', ' ', $paras[0] ?? ''));
        $hazard = '';
        if (preg_match('/HAZARD\.\.\.(.+?)(?=\n\s*\n|$)/is', $desc, $hm)) {
            $hazard = trim(preg_replace('/\s+/', ' ', $hm[1]));
        }
        $built = $hazard !== ''
            ? trim($hazard . ($lead !== '' ? ' · ' . $lead : ''))
            : $lead;
        if ($built !== '') {
            if (mb_strlen($built) > 200) $built = mb_substr($built, 0, 199) . '…';
            $headline = $built;
        }
    }
    if ($headline === '') $headline = $event;

    // Build a dedupe key from VTEC (office.phenomena.significance.ETN) so
    // repeat "update" messages for the same ongoing warning collapse into
    // one entry instead of appearing as separate alerts. Falls back to
    // event name if VTEC is missing/unparsable.
//    $vtecKey = $event;
//    if (!empty($props['parameters']['VTEC'][0])
//        && preg_match('/\/[A-Z]\.[A-Z]{3}\.([A-Z]{4})\.([A-Z]{2})\.([A-Z])\.(\d{4})\./', $props['parameters']['VTEC'][0], $m)) {
//        $vtecKey = $m[1] . '.' . $m[2] . '.' . $m[3] . '.' . $m[4];
//    }

   $vtecKey = $event . '.' . ($feature['id'] ?? md5(serialize($props)));
   if (!empty($props['parameters']['VTEC'][0])
       && preg_match('/\/[A-Z]\.[A-Z]{3}\.([A-Z]{4})\.([A-Z]{2})\.([A-Z])\.(\d{4})\./', $props['parameters']['VTEC'][0], $m)) {
       $vtecKey = $m[1] . '.' . $m[2] . '.' . $m[3] . '.' . $m[4];
   }

    $entry = [
        'event'    => $event,
        'icon'     => $meta['icon'],
        'color'    => $meta['color'],
        'priority' => $meta['priority'],
        'headline' => $headline,
        'areas'    => $props['areaDesc'] ?? '',
        'onset'    => $props['onset'] ?? null,
        'ends'     => $props['ends'] ?? $props['expires'] ?? null,
        'severity' => $props['severity'] ?? '',
        'urgency'  => $props['urgency']  ?? '',
        '_sent'    => $sent,
    ];

if ($event === 'Air Quality Alert') {
    $entry['qr_url'] = 'https://justair.app/?deviceId=6967dbcb09f2e395debeed10';
    $entry['qr_code_img'] = 'images/air-quality-qr.png'  /* relative to index.htm, so no host name in source */;
}

    if (isset($byVtec[$vtecKey])) {
        // Keep whichever message was sent more recently
        $existingIdx = $byVtec[$vtecKey];
        if ($sent > $alerts[$existingIdx]['_sent']) {
            $alerts[$existingIdx] = $entry;
        }
    } else {
        $byVtec[$vtecKey] = count($alerts);
        $alerts[] = $entry;
    }
}

// Strip the internal sort-only field before output
$alerts = array_map(function($a) { unset($a['_sent']); return $a; }, $alerts);

// Sort by priority (most severe first)
usort($alerts, fn($a,$b) => $a['priority'] - $b['priority']);

$result = [
    'ok'        => true,
    'alerts'    => $alerts,
    'count'     => count($alerts),
    'fetchedAt' => date('c'),
];

file_put_contents(CACHE_FILE, json_encode(['ts'=>time(), 'point'=>$point, 'data'=>$result]));
echo json_encode($result);
