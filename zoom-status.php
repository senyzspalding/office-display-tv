<?php
/**
 * zoom-status.php — Zoom service status via public Statuspage API
 * PHP 7.0+ compatible
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');

$cacheFile = __DIR__ . '/zoom-status-cache.json';
$cacheTtl  = 90;

// Component IDs — overridable via query string
$meetId  = isset($_GET['meetId'])  ? preg_replace('/[^a-zA-Z0-9]/','',$_GET['meetId'])  : '3z7b6jks10hm';
$phoneId = isset($_GET['phoneId']) ? preg_replace('/[^a-zA-Z0-9]/','',$_GET['phoneId']): 'gwtd6dbkck60';
if (!$meetId)  $meetId  = '3z7b6jks10hm';
if (!$phoneId) $phoneId = 'gwtd6dbkck60';
$cacheKey = $meetId.'_'.$phoneId;

// Serve cache if fresh
if (file_exists($cacheFile)) {
    $raw = file_get_contents($cacheFile);
    $c   = $raw ? json_decode($raw, true) : null;
    if (is_array($c)
        && isset($c['ts'], $c['key'], $c['data'])
        && $c['key'] === $cacheKey
        && (time() - $c['ts']) < $cacheTtl) {
        echo json_encode($c['data']);
        exit;
    }
}

function zMapStatus($s) {
    if ($s === 'operational')          return 'up';
    if ($s === 'degraded_performance') return 'degraded';
    if ($s === 'partial_outage')       return 'degraded';
    if ($s === 'major_outage')         return 'down';
    return 'unknown';
}

$ctx = stream_context_create(['http' => [
    'method'        => 'GET',
    'timeout'       => 10,
    'ignore_errors' => true,
    'header'        => "User-Agent: Mozilla/5.0 (compatible)\r\nAccept: application/json\r\n",
]]);

$raw = @file_get_contents('https://www.zoomstatus.com/api/v2/summary.json', false, $ctx);
if (!$raw) {
    echo json_encode(['ok'=>false,'error'=>'Could not reach Zoom API','php'=>PHP_VERSION]);
    exit;
}

$data = json_decode($raw, true);
if (!$data || !isset($data['components'])) {
    echo json_encode(['ok'=>false,'error'=>'Invalid Zoom API response','raw'=>substr($raw,0,200),'php'=>PHP_VERSION]);
    exit;
}

// Index components by ID
$byId = [];
foreach ($data['components'] as $comp) {
    if (isset($comp['id'], $comp['status'])) {
        $byId[$comp['id']] = $comp['status'];
    }
}

$meetRaw  = isset($byId[$meetId])  ? $byId[$meetId]  : 'unknown';
$phoneRaw = isset($byId[$phoneId]) ? $byId[$phoneId] : 'unknown';

$indicator   = isset($data['status']['indicator']) ? $data['status']['indicator'] : 'none';
$overallRaw  = ($indicator !== 'none') ? $indicator : 'operational';

$result = [
    'ok'       => true,
    'overall'  => zMapStatus($overallRaw),
    'services' => [
        'zoom_meetings' => ['label'=>'Zoom Meetings',   'status'=>zMapStatus($meetRaw),  'raw'=>$meetRaw ],
        'zoom_phone_na' => ['label'=>'Zoom Phone (NA)', 'status'=>zMapStatus($phoneRaw), 'raw'=>$phoneRaw],
    ],
    'fetchedAt' => date('c'),
    'php'       => PHP_VERSION,
];

// Write cache
@file_put_contents($cacheFile, json_encode(['ts'=>time(),'key'=>$cacheKey,'data'=>$result]));
echo json_encode($result);
