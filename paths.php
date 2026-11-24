<?php
/**
 * paths.php — locates files that must NOT be served by the web server.
 *
 * Secrets and private data (service-account.json, config.local.php,
 * calendar-cache.json) live in a directory OUTSIDE the document root, so they
 * stay unreachable even if .htaccess is ignored or the web-server config changes.
 * Point to that directory with the SENYTV_PRIVATE_DIR environment variable, e.g.
 * in the Apache vhost:
 *
 *     SetEnv SENYTV_PRIVATE_DIR /var/lib/senytv-private
 *
 * The directory must exist, be readable by the web user, writable for the cache
 * file, and NOT be inside DocumentRoot (a directory inside it is rejected).
 *
 * FAIL CLOSED: if SENYTV_PRIVATE_DIR is unset or invalid there is no fallback to
 * the web root — callers must refuse the request.
 * This file only defines functions; it outputs nothing and is denied by .htaccess.
 */

/** Returns the validated private directory (real path), or null if unset/invalid/inside the web root. */
function senytv_private_dir() {
    static $resolved = false, $dir = null;
    if ($resolved) return $dir;
    $resolved = true;

    $cfg = getenv('SENYTV_PRIVATE_DIR');
    if (!$cfg) $cfg = $_SERVER['SENYTV_PRIVATE_DIR'] ?? ($_SERVER['REDIRECT_SENYTV_PRIVATE_DIR'] ?? '');
    if (!$cfg) return null;

    $real = realpath($cfg);
    if ($real === false || !is_dir($real)) {
        error_log('senytv: SENYTV_PRIVATE_DIR does not exist or is not a directory');
        return null;
    }
    foreach ([$_SERVER['DOCUMENT_ROOT'] ?? '', __DIR__] as $root) {
        $root = $root ? realpath($root) : false;
        if ($root && ($real === $root || strpos($real, $root . DIRECTORY_SEPARATOR) === 0)) {
            error_log('senytv: SENYTV_PRIVATE_DIR is inside the web root — refusing to use it');
            return null;
        }
    }
    $dir = $real;
    return $dir;
}

/** True for empty values and the placeholder text shipped in config.local.php.example (never a usable secret). */
function senytv_is_placeholder($value) {
    $v = strtolower(trim((string)$value));
    return $v === '' || strpos($v, 'change-me') === 0 || strpos($v, 'replace_with') !== false;
}
