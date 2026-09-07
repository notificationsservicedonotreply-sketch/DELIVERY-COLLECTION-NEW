<?php
/**
 * Single entry point every script in this application requires first.
 *
 * Responsibilities:
 *   - Load .env (development convenience; production should set real
 *     environment variables at the OS/IIS/Apache level instead)
 *   - Autoload app/Core, app/Models, app/Controllers classes
 *   - Apply security headers
 *   - Start the hardened session
 *   - Connect to the database
 *
 * Defines APP_BOOTSTRAPPED so that every Controller/Model/View file can
 * refuse to run if it is somehow requested directly instead of through this
 * bootstrap -- see app/Core/Security.php::requireBootstrapped().
 */

declare(strict_types=1);

define('APP_BOOTSTRAPPED', true);
define('APP_ROOT', __DIR__);

// ---------------------------------------------------------------------
// 1. Load .env (development only -- production should set real env vars)
// ---------------------------------------------------------------------
$envFile = APP_ROOT . '/.env';
define('ENV_FILE_FOUND', is_file($envFile));

if (ENV_FILE_FOUND) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line, " \t\n\r\0\x0B\xEF\xBB\xBF"); // also strips a UTF-8 BOM if Notepad added one
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if ($key !== '' && getenv($key) === false) {
            putenv("{$key}={$value}");
        }
    }
}

// ---------------------------------------------------------------------
// 2. Autoload
// ---------------------------------------------------------------------
spl_autoload_register(function (string $class): void {
    $map = [
        APP_ROOT . '/app/Core/' . $class . '.php',
        APP_ROOT . '/app/Models/' . $class . '.php',
        APP_ROOT . '/app/Controllers/' . $class . '.php',
    ];
    foreach ($map as $path) {
        if (is_file($path)) {
            require_once $path;
            return;
        }
    }
});

require_once APP_ROOT . '/app/Core/Security.php';

// ---------------------------------------------------------------------
// 3. Config + baseline HTTP hygiene
// ---------------------------------------------------------------------
/*$config = require APP_ROOT . '/config.php';

if (($config['force_https'] ?? true) && ($config['env'] ?? 'production') === 'production') {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    if (!$isHttps && php_sapi_name() !== 'cli') {
        http_response_code(403);
        exit('HTTPS is required.');
    }
}*/

$config = require APP_ROOT . '/config.php';

$env = $config['env'] ?? 'production';

$isHttps = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
);

$host = strtolower($_SERVER['HTTP_HOST'] ?? '');
// Strip a trailing ":port" -- but carefully, since a bare/unbracketed IPv6
// address (like the loopback address "::1") also contains colons, and a
// naive `:\d+$` strip would wrongly chop it down to just ":" (matching the
// trailing ":1"). Bracketed IPv6 host headers ("[::1]:8080") are stripped
// via the bracket itself; a bare IPv6 host with no port is left untouched.
if (preg_match('/^\[(.+)\](?::\d+)?$/', $host, $ipv6Match)) {
    $host = $ipv6Match[1];
} elseif (substr_count($host, ':') <= 1) {
    $host = preg_replace('/:\d+$/', '', $host);
}

$isLocalhost = in_array($host, [
    'localhost',
    '127.0.0.1',
    '::1'
], true);

// Any private/LAN address (RFC 1918 IPv4: 10.0.0.0/8, 172.16.0.0/12,
// 192.168.0.0/16 -- and their IPv6 ULA equivalent, fc00::/7) -- covers
// browsing to this machine's own network IP (e.g. http://192.168.1.50/...)
// from another device on the same LAN. Uses PHP's own validated ranges
// rather than hand-rolled CIDR math for this part, since getting a
// security-relevant IP range check subtly wrong is an easy mistake to make
// by hand.
$isPrivateLanIp = filter_var($host, FILTER_VALIDATE_IP) !== false
    && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE) === false;

// Tailscale IPv4 CGNAT range (100.64.0.0/10, RFC 6598) -- this is a
// *separate* reserved block from the RFC 1918 ranges above, so it needs its
// own check; every device's Tailscale IP falls somewhere in here.
$isTailscaleIp = false;
if (filter_var($host, FILTER_VALIDATE_IP)) {
    $ip = ip2long($host);
    if ($ip !== false) {
        $isTailscaleIp =
            $ip >= ip2long('100.64.0.0') &&
            $ip <= ip2long('100.127.255.255');
    }
}

// Tailscale MagicDNS hostnames are always "<device>.<tailnet-name>.ts.net"
// -- e.g. https://my-desktop.tail1a2b3c.ts.net/... -- so any hostname
// ending in ".ts.net" is a Tailscale device, regardless of tailnet name.
// (A bare short MagicDNS name with no ".ts.net" suffix looks like any other
// hostname and can't be told apart safely by pattern alone -- if you use
// short names, add them to APP_TRUSTED_HOSTS below instead.)
$isTailscaleHostname = $host !== '' && substr($host, -7) === '.ts.net';

// Escape hatch for anything the checks above don't anticipate -- a bare
// Tailscale short name, a custom mDNS/.local hostname, a VPN hostname, etc.
// Comma-separated exact hostnames (no scheme, no port), e.g.:
//   APP_TRUSTED_HOSTS=my-laptop,my-laptop.local,office-pc
$trustedHosts = array_filter(array_map('trim', explode(',', strtolower((string) (getenv('APP_TRUSTED_HOSTS') ?: '')))));
$isExplicitlyTrustedHost = $host !== '' && in_array($host, $trustedHosts, true);

$isTrustedLocalAccess = $isLocalhost
    || $isPrivateLanIp
    || $isTailscaleIp
    || $isTailscaleHostname
    || $isExplicitlyTrustedHost;

// Only force HTTPS for public/production access
if (
    ($config['force_https'] ?? true) &&
    $env === 'production' &&
    !$isHttps &&
    !$isTrustedLocalAccess &&
    php_sapi_name() !== 'cli'
) {
    http_response_code(403);
    exit('HTTPS is required.');
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');

date_default_timezone_set('Asia/Manila');
ini_set('max_execution_time', '60');

// ---------------------------------------------------------------------
// 4. Database
// ---------------------------------------------------------------------
Database::setConfig($config);
$pdo = Database::getConnection('db1');

// ---------------------------------------------------------------------
// 5. Secure session
// ---------------------------------------------------------------------
startSecureSession();

/** Cache-busted asset URL helper, unchanged behavior from the original app.
 *  $file is a path relative to the repo root, and the emitted href is that
 *  same repo-root-relative path -- correct for pages served from the repo
 *  root (e.g. the login page, index.php). */
function asset(string $file): string
{
    $path = APP_ROOT . '/' . $file;
    return is_file($path) ? $file . '?v=' . filemtime($path) : $file;
}

/** Same idea, but for views served from /Administrator/index.php: the href
 *  must stay relative to /Administrator/ (e.g. "assets/foo.css"), while the
 *  on-disk file actually lives at Administrator/assets/foo.css. */
function adminAsset(string $file): string
{
    $path = APP_ROOT . '/Administrator/' . $file;
    return is_file($path) ? $file . '?v=' . filemtime($path) : $file;
}
