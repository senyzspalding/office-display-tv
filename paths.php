<?php
/**
 * paths.php — locates files that must NOT be served by the web server.
 *
 * Secrets and private data (service-account.json, config.local.php,
 * calendar-cache.json) belong in a directory OUTSIDE the document root, so they
 * stay unreachable even if .htaccess is ignored or the web-server config changes.
 * Point to that directory with the SENYTV_PRIVATE_DIR environment variable, e.g.
 * in the Apache vhost:
 *
 *     SetEnv SENYTV_PRIVATE_DIR /var/lib/senytv-private
 *
 * (the directory must be readable by the web user, writable for the cache file,
 * and not inside DocumentRoot — a directory inside it is rejected).
 *
 * Without SENYTV_PRIVATE_DIR the old in-web-root location is used as a
 * DEPRECATED fallback (protected only by .htaccess) and a warning is logged.
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

/** Full path of a private file: in the private dir when configured, else the deprecated web-root fallback. */
function senytv_private_file($name) {
    $dir = senytv_private_dir();
    if ($dir !== null) return $dir . DIRECTORY_SEPARATOR . $name;
    error_log("senytv: SENYTV_PRIVATE_DIR not set/valid; using in-web-root '$name' (deprecated) — move it outside the document root");
    return __DIR__ . DIRECTORY_SEPARATOR . $name;
}
