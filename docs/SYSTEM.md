# SENYLRC TV Display Board — System Documentation

## 1. Purpose
A kiosk web page (`index.htm`) shown on a lobby/office TV for the Southeastern NY Library Resources Council (SENYLRC). It displays upcoming calendar events, the staff schedule, service-health dots, NWS weather alerts, optional news and announcements, and a rotating animal photo background. A password-protected admin page (`admin.htm`) edits the runtime configuration. Served from any PHP-capable web host (`your-host.example` below).

## 2. Architecture
No framework, no database, no build step. Static HTML/CSS/JS served by Apache/PHP; small PHP proxies keep secrets server-side and cache upstream APIs. All state is flat JSON files.

```
 TV browser ── index.htm + style.css ──┬─ board-config.json   (runtime config, read directly)
                                       ├─ calendar-proxy.php  ── Google Calendar API (service account JWT)
                                       ├─ weather-alerts.php  ── api.weather.gov
                                       ├─ google-status.php   ── google.com/appsstatus (Gmail)
                                       ├─ zoom-status.php     ── zoomstatus.com
                                       ├─ news-proxy.php      ── RSS feeds (optional)
                                       ├─ site pings          ── HEAD no-cors fetch from the browser
                                       └─ animal APIs         ── fetched from the browser
 Admin browser ── admin.htm ── save-config.php (password) ──> writes board-config.json
```

## 3. Files
| File | Role |
|---|---|
| `index.htm` | Display page: layout, all display JS (boot, clock, calendars, status, weather, news, announcements, animals, wake-lock, auto-reload) |
| `style.css` | Display styling (bump `?v=` on the `<link>` in `index.htm` after edits so kiosk browsers refetch) |
| `admin.htm` | Admin UI (self-contained HTML/CSS/JS) |
| `save-config.php` | Authenticated POST endpoint; whitelists keys, validates, writes `board-config.json` |
| `calendar-proxy.php` | Google Calendar via service account; requires the display token; 120 s cache; returns only the fields the TV renders |
| `weather-alerts.php` | NWS alerts for a lat/lon point; 300 s cache; event whitelist `$WATCH_EVENTS` |
| `google-status.php` | Gmail status from Google Workspace dashboard; 120 s cache |
| `zoom-status.php` | Zoom Meetings/Phone status from Statuspage API; 90 s cache |
| `news-proxy.php` | Merges RSS/Atom feeds, newest 50; 300 s cache. Feed list is hard-coded in the file (the `rssFeeds` setting saved by admin is not read by it) |
| `board-config.json` | Runtime configuration (see §4) — **live state, not versioned** (it holds real calendar IDs). Start from `board-config.example.json` |
| `board-config.example.json` | Sanitized template for `board-config.json` |
| `*-cache.json` | Generated caches — not versioned. `calendar-cache.json` holds private event data and lives in `SENYTV_PRIVATE_DIR`; the weather/status caches hold public data and stay beside the scripts |
| `service-account.json` | Google service-account key — **secret, not versioned; keep outside the web root** (`SENYTV_PRIVATE_DIR`) |
| `paths.php` | Resolves `SENYTV_PRIVATE_DIR` (private files outside the document root); rejects a directory inside the web root |
| `config.local.php` | Defines `ADMIN_PASSWORD`, `DISPLAY_TOKEN` (and optional `NWS_CONTACT`) — **secret, not versioned; keep outside the web root** (template: `config.local.php.example`) |
| `.htaccess` | Denies web access to secrets/caches/backups; disables directory listings |
| `docs/screenshots/` | README screenshots (sample data) |
| `images/air-quality-qr.png` | QR code shown in Air Quality Alert banner |
| `index.htm-bak` | Old backup of the display page (not versioned) |

## 4. Configuration (`board-config.json`)
Written only by `save-config.php`; keys outside its whitelist are dropped.

| Key | Meaning |
|---|---|
| `boardTitle` | Board title (≤100 chars) |
| `reloadInterval` | Seconds between full page reloads (10–3600) |
| `showAnnouncement`, `announcement`, `annExpireDate`, `annExpireTime` | Announcement overlay (≤500 chars) and auto-expiry |
| `showNews`, `rssFeeds[]` | News panel toggle and feed list |
| `calendars[]` | `{id, label, color, days}` — Google Calendar ID, display name, color, days ahead |
| `statusSites[]` | `{name, url, enabled}` — sites pinged for header dots |
| `gmailCfg`, `zoomMeetCfg`, `zoomPhoneCfg` | `{enabled, name, productId/componentId}` for service-status dots |
| `showAnimals`, `animalOpacity`, `animalInterval`, `animalFeeds[]` | Animal background (cat, dog, fox, duck, bunny, bird, panda; max 7) |
| `showWeather`, `weatherZones` | Weather banner toggle; zones are legacy — the point is set in `weather-alerts.php` |
| `savedAt` | Set by the server on save |

## 5. Data flows
- **Boot** (`boot()`): load config → build status dots → start clock/countdown → run checks (sites, Zoom, Gmail every 2 min; weather every 5 min) → load calendars/news → start auto-scroll. The page reloads itself every `reloadInterval` seconds, so it is effectively stateless.
- **Calendars:** browser → `calendar-proxy.php` → signs an RS256 JWT with `service-account.json`, exchanges it for a token, queries each calendar (`singleEvents`, ≤40 events), strips Zoom links, caches. Each calendar must be shared with the service account (the `client_email` in `service-account.json`, "See all event details"). The browser must send the display token (`X-Display-Token`) or the proxy answers 401; the response contains only calendar label/color and each event's title, location, start, end and all-day flag.
- **Weather:** NWS `alerts/active?point=` filtered to the `$WATCH_EVENTS` whitelist, deduped by VTEC key, sorted by priority; Special Weather Statements get a headline built from HAZARD + lead paragraph. The banner cycles through multiple alerts.
- **Offline detection:** if every status site fails, the "internet offline" overlay appears.
- **Wake-lock:** `keepAwake()` plays a hidden looping video to stop the TV sleeping.

## 6. Security model
- Admin auth: shared password in `config.local.php`, compared with `hash_equals`; 10 attempts / 5 min / IP rate limit; same-origin check; input type-clamping and `strip_tags` on text.
- Secrets (`service-account.json`, `config.local.php`) must never be committed; `.gitignore` excludes them. They sit in the web root, so the bundled `.htaccess` denies web access to them (plus caches and backups) and disables directory listings. Apache must allow overrides for the folder (`AllowOverride All` or `FileInfo`); verify with `curl -I https://your-host.example/service-account.json` (expect 403).
- **Calendar proxy (private data):** `calendar-proxy.php` returns event titles/locations from private calendars, so it requires `DISPLAY_TOKEN` (constant in `config.local.php`, ≥ 24 chars) in an `X-Display-Token` request header, checked with `hash_equals` before the cache or Google is touched. It fails closed (503) if no token is configured, accepts GET only, sends **no CORS header** (other sites cannot read it from a visitor's browser), returns generic errors (details go to the server error log) and never returns calendar IDs, event IDs, attendees or descriptions. The token is not accepted in the query string, to keep it out of logs.
- **TV setup:** open the board once on each TV as `https://your-host.example/index.htm#key=<DISPLAY_TOKEN>`. `index.htm` saves the token in that browser's `localStorage` and removes it from the address bar; the fragment is never sent to the server. Rotate by changing `DISPLAY_TOKEN` and repeating on each TV. Because the token lives in the TV's browser and in page requests, treat it as a shared access code, not a per-user credential. Serve everything over HTTPS.
- **Defense in depth (recommended):** also restrict the site, or at least `calendar-proxy.php`, to the office network/VPN at the web-server level if the TV is the only intended client.
- Other proxies (`weather-alerts.php`, `google-status.php`, `zoom-status.php`, `news-proxy.php`) serve public data and still send `Access-Control-Allow-Origin: *`.
- `board-config.json` is read directly by the display and admin pages, so it is web-readable: it contains calendar IDs (identifiers, not credentials) and announcement text. Keep the Google calendars themselves **un-shared** with the public (share only with the service account) so an ID alone grants nothing.
- **Private files outside the web root:** `service-account.json`, `config.local.php` and `calendar-cache.json` (private event titles) should live in a directory outside `DocumentRoot`, selected with `SetEnv SENYTV_PRIVATE_DIR /var/lib/senytv-private` in the Apache vhost (readable by the web user; writable for the cache; `paths.php` rejects a directory inside the web root). That way they stay unreachable even if `.htaccess` is ignored (`AllowOverride None`), a vhost is rebuilt, or the site is moved. Without `SENYTV_PRIVATE_DIR` the code falls back to the old in-web-root location (protected only by `.htaccess`), logs a warning and should be treated as temporary. If a key or cache was ever reachable over the web, rotate the service-account key in Google Cloud and check the access logs.
- Source control: the repo is public. Never commit `service-account.json`, `config.local.php`, `board-config.json`, caches, `STATUS.md` or real calendar IDs; `.gitignore` excludes them and `.htaccess` also denies web access to them (and to `*.md`/`*.example*`).
- Known gaps: password is sent in the POST body (use HTTPS only); no per-user accounts.

## 7. Deployment & operations
1. Copy the files to the web root (e.g. `/var/www/html/senytv/`) with PHP ≥ 7 (8.3 in use), OpenSSL and `allow_url_fopen` enabled.
2. Create a private directory **outside** the document root (e.g. `/var/lib/senytv-private`, owned by/writable for the web user, mode 750) and add `SetEnv SENYTV_PRIVATE_DIR /var/lib/senytv-private` to the vhost. Put `service-account.json` (Google Cloud service account with Calendar API enabled) and `config.local.php` (copy `config.local.php.example`; set a strong `ADMIN_PASSWORD` and a random `DISPLAY_TOKEN`) there. Copy `board-config.example.json` to `board-config.json` and set your calendar IDs via `admin.htm`.
3. Make the web folder writable by the web user for `board-config.json` and the public `*-cache.json` files (the calendar cache is written to the private directory).
4. Open `index.htm#key=<DISPLAY_TOKEN>` once on the TV in kiosk mode (see §6); manage settings at `admin.htm`.

Troubleshooting: calendars blank → "not authorized" means the TV has no/wrong token (reopen with `#key=`); otherwise check calendar sharing / key file and the web server error log; weather banner missing → confirm `weather-alerts.php` returns JSON; admin "Unauthorized" → check `config.local.php`; stale styling on the TV → bump `?v=` in `index.htm`.
