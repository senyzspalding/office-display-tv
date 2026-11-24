# Changelog

All notable changes to this project are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow [Semantic Versioning](https://semver.org/) (0.x while the project is young).

## [0.3.0] - 2026-11-24

### Added
- `TRUSTED_IPS` option in `config.local.php`: requests from these exact addresses can read `calendar-proxy.php` without the display token, for devices (such as a Fire Stick) that can't easily hold the token. Everyone else still needs `X-Display-Token`.

### Security
- Only `REMOTE_ADDR` is checked, never `X-Forwarded-For`. Ranges, wildcards and hostnames are ignored.
- The proxy still fails closed (503) when neither `TRUSTED_IPS` nor a usable `DISPLAY_TOKEN` is configured.
- Trade-off: anyone behind a trusted address (such as a shared office IP) can read the calendar proxy without a token.

## [0.2.0] - 2026-11-24

### Changed
- **Breaking:** the proxies no longer fall back to files inside the web root. If `SENYTV_PRIVATE_DIR` is unset, missing, or inside the web root, there is no private location.
- `calendar-proxy.php` answers 503 when the private directory isn't configured, or when `DISPLAY_TOKEN` is missing, too short, or still the example value.
- `save-config.php` answers 503 when the private directory isn't configured, or when `ADMIN_PASSWORD` is missing or still the example value.
- `weather-alerts.php` treats the private directory as optional (it only holds a contact address).

### Documentation
- `docs/SYSTEM.md` describes the fail-closed behavior and the deploy order.

### Upgrading
Set `SENYTV_PRIVATE_DIR` to a directory outside the web root, and put a real `ADMIN_PASSWORD` and `DISPLAY_TOKEN` in `config.local.php`, before deploying.

## [0.1.0] - 2026-11-24

Initial release.

### Added
- Kiosk display page (`index.htm`): calendars, staff schedule, service status (sites, Gmail, Zoom), NWS weather alerts, and optional news and announcements.
- Admin page (`admin.htm`) for configuring the board.
- Small PHP proxies for calendar, news, weather alerts, and Google and Zoom status.
- `docs/SYSTEM.md` with full documentation.

### Security
- `calendar-proxy.php` requires a display token, sends no CORS header, and returns only the fields the TV renders.
- Service-account key, calendar cache and `config.local.php` are meant to live outside the web root (`SENYTV_PRIVATE_DIR`); `.htaccess` is a second layer.
- Live config (`board-config.json`) and real calendar IDs are not in git.

[0.3.0]: https://github.com/senyzspalding/office-display-tv/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/senyzspalding/office-display-tv/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/senyzspalding/office-display-tv/releases/tag/v0.1.0
