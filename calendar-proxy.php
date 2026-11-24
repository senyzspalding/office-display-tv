<?php
/**
 * calendar-proxy.php
 * 
 * Fetches events from private Google Calendars using a Service Account.
 * The display board calls this script — the private key never leaves the server.
 * Keep the service account JSON key OUTSIDE the web root (see paths.php).
 *
 * ACCESS CONTROL: this endpoint returns private event titles/locations, so every
 * request must carry the display token in an "X-Display-Token" header. The token
 * is DISPLAY_TOKEN in config.local.php (gitignored). There is no CORS header, so
 * other websites cannot read the response from a visitor's browser. If
 * DISPLAY_TOKEN is not configured the endpoint refuses to serve anything.
 *
 * SETUP:
 *   1. Upload this file to your server (e.g. /senytv/calendar-proxy.php)
 *   2. Put your service account JSON as "service-account.json" in SENYTV_PRIVATE_DIR
 *   3. Define DISPLAY_TOKEN in SENYTV_PRIVATE_DIR/config.local.php (see config.local.php.example)
 *   4. Share each calendar with the service account's client_email (the address
 *      inside service-account.json), permission "See all event details"
 */

// ── Config ────────────────────────────────────────────────────────────────
require_once __DIR__ . '/paths.php';
// The key, the calendar cache (private event data) and config.local.php live in
// SENYTV_PRIVATE_DIR, outside the document root (see paths.php).
define('KEY_FILE',   senytv_private_file('service-account.json'));
define('CACHE_FILE', senytv_private_file('calendar-cache.json'));
define('CACHE_TTL',  120);  // seconds to cache
define('MAX_EVENTS', 40);   // max events per calendar

// Local secrets (DISPLAY_TOKEN) — kept out of version control and the web root.
if (file_exists(senytv_private_file('config.local.php'))) require senytv_private_file('config.local.php');

// ── Response headers ──────────────────────────────────────────────────────
// Deliberately NO Access-Control-Allow-Origin: only same-origin pages (the
// display board served from this host) may read this response in a browser.
header('Content-Type: application/json');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

/** Ends the request with a JSON error and HTTP status; never includes internal detail. */
function fail($status, $message) {
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

// ── Authenticate (before touching the cache or Google) ────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    fail(405, 'Method not allowed');
}
if (!defined('DISPLAY_TOKEN') || !is_string(DISPLAY_TOKEN) || strlen(DISPLAY_TOKEN) < 24) {
    error_log('calendar-proxy: DISPLAY_TOKEN missing or shorter than 24 chars in config.local.php');
    fail(503, 'Calendar service is not configured');
}
$presented = $_SERVER['HTTP_X_DISPLAY_TOKEN'] ?? '';
if (!is_string($presented) || !hash_equals(DISPLAY_TOKEN, $presented)) {
    fail(401, 'Not authorized');
}

// ── Calendar list — from board-config.json (written by save-config.php) ───
// No calendar IDs are hard-coded here; configure them in the admin page.
$CALENDAR_IDS = [];
$boardConfig = __DIR__ . '/board-config.json';
if (file_exists($boardConfig)) {
    $cfg = json_decode(file_get_contents($boardConfig), true);
    if (!empty($cfg['calendars']) && is_array($cfg['calendars'])) {
        $CALENDAR_IDS = array_values(array_filter(array_map(function($c) {
            return empty($c['id']) ? null : [
                'id'    => trim($c['id']),
                'label' => $c['label'] ?? 'Calendar',
                'color' => $c['color'] ?? '#0081B5',
                'days'  => max(1, min(14, intval($c['days'] ?? 2))),
            ];
        }, $cfg['calendars'])));
    }
}
if (!$CALENDAR_IDS) {
    fail(500, 'No calendars configured');
}

// ── Serve from cache if fresh ─────────────────────────────────────────────
if (file_exists(CACHE_FILE)) {
    $cache = json_decode(file_get_contents(CACHE_FILE), true);
    // Validate cache has correct structure before serving
    if ($cache && isset($cache['ts']) && isset($cache['data']['ok']) && $cache['data']['ok'] === true
        && (time() - $cache['ts']) < CACHE_TTL) {
        echo json_encode($cache['data']);
        exit;
    }
    // Cache is stale or malformed — delete it and fetch fresh
    @unlink(CACHE_FILE);
}

// ── Load service account key ──────────────────────────────────────────────
if (!file_exists(KEY_FILE)) {
    error_log('calendar-proxy: service-account.json not found next to calendar-proxy.php');
    fail(500, 'Calendar service is not configured');
}

$key = json_decode(file_get_contents(KEY_FILE), true);
if (!$key || empty($key['private_key']) || empty($key['client_email'])) {
    error_log('calendar-proxy: service-account.json is invalid or missing required fields');
    fail(500, 'Calendar service is not configured');
}

// ── Build a JWT and exchange it for an access token ───────────────────────
function base64url($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function getAccessToken($key) {
    $now = time();
    $header  = base64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $payload = base64url(json_encode([
        'iss'   => $key['client_email'],
        'scope' => 'https://www.googleapis.com/auth/calendar.readonly',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $now + 3600,
    ]));

    $signing_input = "$header.$payload";
    $private_key   = openssl_pkey_get_private($key['private_key']);
    if (!$private_key) {
        throw new Exception('Could not load private key from service-account.json');
    }

    $signature = '';
    if (!openssl_sign($signing_input, $signature, $private_key, OPENSSL_ALGO_SHA256)) {
        throw new Exception('Failed to sign JWT');
    }

    $jwt = "$signing_input." . base64url($signature);

    // Exchange JWT for access token
    $response = file_get_contents('https://oauth2.googleapis.com/token', false, stream_context_create([
        'http' => [
            'method'          => 'POST',
            'header'          => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content'         => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ]),
            'timeout'         => 10,
            'ignore_errors'   => true,
        ]
    ]));

    if (!$response) throw new Exception('No response from Google token endpoint');
    $token = json_decode($response, true);
    if (empty($token['access_token'])) {
        throw new Exception('Token error: ' . ($token['error_description'] ?? $token['error'] ?? 'unknown'));
    }
    return $token['access_token'];
}

// ── Fetch events for one calendar ─────────────────────────────────────────
function fetchCalendarEvents($calId, $accessToken, $daysAhead = 2) {
    $timeMin = urlencode(gmdate('Y-m-d\TH:i:s\Z'));
    $timeMax = urlencode(gmdate('Y-m-d\TH:i:s\Z', strtotime('+' . intval($daysAhead) . ' days')));
    $max     = MAX_EVENTS;

    $url = "https://www.googleapis.com/calendar/v3/calendars/"
         . urlencode($calId)
         . "/events?timeMin={$timeMin}&timeMax={$timeMax}"
         . "&singleEvents=true&orderBy=startTime&maxResults={$max}";

    $response = file_get_contents($url, false, stream_context_create([
        'http' => [
            'method'        => 'GET',
            'header'        => "Authorization: Bearer $accessToken\r\n",
            'timeout'       => 10,
            'ignore_errors' => true,
        ]
    ]));

    if (!$response) throw new Exception("No response fetching calendar: $calId");
    $data = json_decode($response, true);
    if (!empty($data['error'])) {
        throw new Exception($data['error']['message'] ?? 'Calendar API error');
    }
    return $data['items'] ?? [];
}

// Strip any .zoom.us URLs (and optional preceding labels) from a string
function stripZoom($text) {
    if (!$text) return '';
    // Remove "Join Zoom Meeting: https://..." style prefixes plus the URL
    $text = preg_replace('/(?:Join Zoom[^:]*|Zoom Meeting[^:]*|Meeting Link[^:]*|Click here[^:]*)?:?\s*https?:\/\/[a-z0-9.\-]*\.?zoom\.us\/\S*/i', '', $text);
    // Clean up leftover double spaces or trailing punctuation
    $text = preg_replace('/\s{2,}/', ' ', $text);
    return trim($text);
}

// ── Main ──────────────────────────────────────────────────────────────────
try {
    $accessToken = getAccessToken($key);

    // Only what the TV renders is returned: no calendar IDs, event IDs,
    // attendees, descriptions, organizers, meeting links or other metadata.
    $result = [];
    foreach ($CALENDAR_IDS as $cal) {
        try {
            $events = fetchCalendarEvents($cal['id'], $accessToken, $cal['days'] ?? 2);
            $result[] = [
                'label'  => $cal['label'],
                'color'  => $cal['color'],
                'events' => array_map(function($ev) {
                    return [
                        'summary'  => stripZoom($ev['summary'] ?? '(No title)'),
                        'location' => stripZoom($ev['location'] ?? ''),
                        'start'    => array_intersect_key($ev['start'] ?? [], ['date' => 1, 'dateTime' => 1]),
                        'end'      => array_intersect_key($ev['end']   ?? [], ['date' => 1, 'dateTime' => 1]),
                        'allDay'   => isset($ev['start']['date']) && !isset($ev['start']['dateTime']),
                    ];
                }, $events),
                'error'  => null,
            ];
        } catch (Exception $e) {
            // Detail (which can name the calendar ID) goes to the server log only.
            error_log('calendar-proxy: calendar "' . $cal['label'] . '": ' . $e->getMessage());
            $result[] = [
                'label'  => $cal['label'],
                'color'  => $cal['color'],
                'events' => [],
                'error'  => 'Could not load this calendar',
            ];
        }
    }

    $output = ['ok' => true, 'calendars' => $result];

    // Write cache (already reduced to the fields above)
    file_put_contents(CACHE_FILE, json_encode(['ts' => time(), 'data' => $output]), LOCK_EX);

    echo json_encode($output);

} catch (Exception $e) {
    error_log('calendar-proxy: ' . $e->getMessage());
    fail(500, 'Could not load calendars');
}
