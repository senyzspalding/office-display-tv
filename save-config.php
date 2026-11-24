<?php
/**
 * save-config.php
 * Password-protected endpoint that writes board-config.json.
 * Only accepts POST from the same origin (no wildcard CORS).
 */

// ── Admin password ────────────────────────────────────────────────────────
// Kept OUT of version control and out of the web root: define ADMIN_PASSWORD in
// config.local.php inside SENYTV_PRIVATE_DIR (see paths.php, config.local.php.example).
require_once __DIR__ . '/paths.php';
// FAIL CLOSED: no private dir => no admin saves (there is no web-root fallback).
$privateDir = senytv_private_dir();
if ($privateDir !== null && is_file($privateDir . '/config.local.php')) require $privateDir . '/config.local.php';
if ($privateDir === null || !defined('ADMIN_PASSWORD') || senytv_is_placeholder(ADMIN_PASSWORD)) {
    error_log('save-config: SENYTV_PRIVATE_DIR unset/invalid, or ADMIN_PASSWORD missing/still the example value');
    http_response_code(503);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Admin saving is not configured on the server']);
    exit;
}

// ── Rate limiting — max 10 attempts per 5 minutes per IP ─────────────────
define('RATE_FILE',    sys_get_temp_dir() . '/senytv_rate_' . md5($_SERVER['REMOTE_ADDR'] ?? ''));
define('RATE_WINDOW',  300);  // 5 minutes
define('RATE_LIMIT',   10);

/** Counts this request against the per-IP window (RATE_LIMIT per RATE_WINDOW, stored in a temp file); returns false once exceeded. */
function checkRateLimit() {
    $data = ['count' => 0, 'window_start' => time()];
    if (file_exists(RATE_FILE)) {
        $data = json_decode(file_get_contents(RATE_FILE), true) ?: $data;
        if (time() - $data['window_start'] > RATE_WINDOW) {
            $data = ['count' => 0, 'window_start' => time()];
        }
    }
    $data['count']++;
    file_put_contents(RATE_FILE, json_encode($data));
    return $data['count'] <= RATE_LIMIT;
}

// ── Restrict CORS to same origin only ────────────────────────────────────
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$host   = $_SERVER['HTTP_HOST']   ?? '';
// Only allow requests from the same host
if ($origin && parse_url($origin, PHP_URL_HOST) !== $host) {
    http_response_code(403);
    exit;
}

header('Content-Type: application/json');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
// Reflect same-origin only (not wildcard *)
if ($origin) header('Access-Control-Allow-Origin: ' . $origin);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST')    { http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit; }

// ── Rate limit check ──────────────────────────────────────────────────────
if (!checkRateLimit()) {
    http_response_code(429);
    echo json_encode(['error' => 'Too many requests — try again in a few minutes']);
    exit;
}

// ── Parse input ───────────────────────────────────────────────────────────
$raw   = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!$input) { http_response_code(400); echo json_encode(['error' => 'Invalid JSON']); exit; }

// ── Constant-time password check (prevents timing attacks) ───────────────
$provided = $input['password'] ?? '';
if (!hash_equals(ADMIN_PASSWORD, $provided)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// ── Password probe (just checking credentials, not saving) ───────────────
if (!empty($input['_probe'])) {
    echo json_encode(['ok' => true, 'probe' => true]);
    exit;
}

// ── Sanitize — only allow known safe keys into the config file ───────────
$allowed = ['boardTitle','reloadInterval','showAnnouncement','announcement',
            'annExpireDate','annExpireTime','showNews','rssFeeds',
            'calendars','statusSites',
            'gmailCfg','zoomMeetCfg','zoomPhoneCfg',
            'showAnimals','animalOpacity','animalInterval','animalFeeds',
            'showWeather','weatherZones',
            'savedAt'];

$clean = [];
foreach ($allowed as $key) {
    if (array_key_exists($key, $input)) $clean[$key] = $input[$key];
}
$clean['savedAt'] = date('c');

// ── Validate types for critical fields ───────────────────────────────────
if (isset($clean['reloadInterval']))
    $clean['reloadInterval'] = max(10, min(3600, intval($clean['reloadInterval'])));
if (isset($clean['announcement']))
    $clean['announcement'] = substr(strip_tags($clean['announcement']), 0, 500);
if (isset($clean['boardTitle']))
    $clean['boardTitle'] = substr(strip_tags($clean['boardTitle']), 0, 100);

// ── Write to file ─────────────────────────────────────────────────────────
$file = __DIR__ . '/board-config.json';
$json = json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

if (file_put_contents($file, $json, LOCK_EX) === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Could not write board-config.json — check folder permissions']);
    exit;
}

echo json_encode(['ok' => true, 'savedAt' => $clean['savedAt']]);
