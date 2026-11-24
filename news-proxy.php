<?php
/**
 * news-proxy.php
 * Fetches and merges RSS feeds server-side, returns clean JSON.
 * Cached for 5 minutes to avoid hammering feed sources.
 */

define('CACHE_FILE', __DIR__ . '/news-cache.json');
define('CACHE_TTL',  300); // 5 minutes

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');

// ── Feeds to aggregate ────────────────────────────────────────────────────
// Add or remove feeds here. Label shows in the news panel.
$FEEDS = [
    [
        'url'   => 'https://americanlibrariesmagazine.org/feed/',
        'label' => 'American Libraries',
    ],
    [
        'url'   => 'https://blog.archive.org/feed/',
        'label' => 'Internet Archive',
    ],
    [
        'url'   => 'https://feeds.npr.org/1001/rss.xml',
        'label' => 'NPR News',
    ],
];

// ── Serve from cache if fresh ─────────────────────────────────────────────
if (file_exists(CACHE_FILE)) {
    $cache = json_decode(file_get_contents(CACHE_FILE), true);
    if ($cache && isset($cache['ts']) && (time() - $cache['ts']) < CACHE_TTL) {
        echo json_encode($cache['data']);
        exit;
    }
}

// ── Fetch and parse feeds ─────────────────────────────────────────────────
$allItems = [];

foreach ($FEEDS as $feed) {
    $xml = @file_get_contents($feed['url'], false, stream_context_create([
        'http' => [
            'timeout' => 8,
            'header'  => "User-Agent: Mozilla/5.0 (compatible; DisplayBoard/1.0)\r\n",
        ]
    ]));
    if (!$xml) continue;

    // Suppress XML parse warnings
    $prev = libxml_use_internal_errors(true);
    $doc  = simplexml_load_string($xml);
    libxml_use_internal_errors($prev);
    if (!$doc) continue;

    // Support both RSS 2.0 and Atom
    $items = $doc->channel->item ?? $doc->entry ?? [];

    foreach ($items as $item) {
        $title   = trim((string)($item->title   ?? ''));
        $pubDate = trim((string)($item->pubDate ?? $item->updated ?? $item->published ?? ''));
        if (!$title) continue;

        $allItems[] = [
            'title'   => html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'pubDate' => $pubDate,
            'source'  => $feed['label'],
            'ts'      => $pubDate ? strtotime($pubDate) : 0,
        ];
    }
}

// Sort newest first
usort($allItems, fn($a, $b) => $b['ts'] - $a['ts']);

// Trim timestamp field (not needed in output)
$output = array_map(fn($i) => [
    'title'   => $i['title'],
    'pubDate' => $i['pubDate'],
    'source'  => $i['source'],
], array_slice($allItems, 0, 50));

$result = ['ok' => true, 'items' => $output, 'fetchedAt' => date('c')];

file_put_contents(CACHE_FILE, json_encode(['ts' => time(), 'data' => $result]));
echo json_encode($result);
