<?php
/**
 * google-status.php — Gmail status via Google Workspace Dashboard API
 * PHP 7.0+ compatible
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');

$cacheFile    = __DIR__ . '/google-status-cache.json';
$cacheTtl     = 120;
$defaultProdId= 'prPt2Yra2CbGsbEm9cpC';

// Product ID — overridable via query string
$productId = isset($_GET['productId']) ? preg_replace('/[^a-zA-Z0-9]/','',$_GET['productId']) : $defaultProdId;
if (!$productId) $productId = $defaultProdId;

// Serve cache if fresh and same product ID
if (file_exists($cacheFile)) {
    $raw = file_get_contents($cacheFile);
    $c   = $raw ? json_decode($raw, true) : null;
    if (is_array($c)
        && isset($c['ts'], $c['pid'], $c['data'])
        && $c['pid'] === $productId
        && (time() - $c['ts']) < $cacheTtl) {
        echo json_encode($c['data']);
        exit;
    }
}

$ctx = stream_context_create(['http' => [
    'method'        => 'GET',
    'timeout'       => 10,
    'ignore_errors' => true,
    'header'        => "User-Agent: Mozilla/5.0 (compatible)\r\nAccept: application/json\r\n",
]]);

$raw = @file_get_contents('https://www.google.com/appsstatus/dashboard/incidents.json', false, $ctx);
if (!$raw) {
    echo json_encode(['ok'=>false,'error'=>'Could not reach Google status API','php'=>PHP_VERSION]);
    exit;
}

$incidents = json_decode($raw, true);
if (!is_array($incidents)) {
    echo json_encode(['ok'=>false,'error'=>'Invalid Google API response','php'=>PHP_VERSION]);
    exit;
}

// Find active incidents affecting Gmail
$gmailStatus = 'up';
$gmailDesc   = 'Operational';

foreach ($incidents as $incident) {
    // Skip resolved
    if (!empty($incident['end'])) continue;

    // Check if Gmail is affected
    $affectedIds = array();
    if (isset($incident['affected_products']) && is_array($incident['affected_products'])) {
        foreach ($incident['affected_products'] as $p) {
            if (isset($p['id'])) $affectedIds[] = $p['id'];
        }
    }
    if (!in_array($productId, $affectedIds)) continue;

    $impact = isset($incident['status_impact']) ? $incident['status_impact'] : '';
    $desc   = '';
    if (isset($incident['most_recent_update']['text'])) {
        $desc = $incident['most_recent_update']['text'];
    } elseif (isset($incident['external_desc'])) {
        $desc = $incident['external_desc'];
    } else {
        $desc = $impact;
    }
    $desc = substr($desc, 0, 150);

    if ($impact === 'SERVICE_OUTAGE') {
        $gmailStatus = 'down';
        $gmailDesc   = 'Outage: '.$desc;
        break;
    }
    if ($impact === 'SERVICE_DISRUPTION' && $gmailStatus !== 'down') {
        $gmailStatus = 'degraded';
        $gmailDesc   = 'Disruption: '.$desc;
    }
    if ($impact === 'SERVICE_INFORMATION' && $gmailStatus === 'up') {
        $gmailStatus = 'degraded';
        $gmailDesc   = 'Info: '.$desc;
    }
}

$result = [
    'ok'        => true,
    'gmail'     => ['status'=>$gmailStatus, 'description'=>$gmailDesc],
    'fetchedAt' => date('c'),
    'php'       => PHP_VERSION,
];

@file_put_contents($cacheFile, json_encode(['ts'=>time(),'pid'=>$productId,'data'=>$result]));
echo json_encode($result);
