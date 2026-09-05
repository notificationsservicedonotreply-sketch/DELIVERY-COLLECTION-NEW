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
$host = preg_replace('/:\d+$/', '', $host);

$isLocalhost = in_array($host, [
    'localhost',
    '127.0.0.1',
    '::1'
], true);

// Tailscale IPv4 range
$isTailscale = false;

if (filter_var($host, FILTER_VALIDATE_IP)) {
    $ip = ip2long($host);

    if ($ip !== false) {
        $isTailscale =
            $ip >= ip2long('100.64.0.0') &&
            $ip <= ip2long('100.127.255.255');
    }
}

// Only force HTTPS for public/production access
if (
    ($config['force_https'] ?? true) &&
    $env === 'production' &&
    !$isHttps &&
    !$isLocalhost &&
    !$isTailscale &&
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
