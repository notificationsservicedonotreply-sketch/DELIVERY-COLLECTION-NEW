<?php
declare(strict_types=1);
/**
 * Setup diagnostic. Visit this file directly in a browser after copying
 * .env.example to .env, to check what's missing before dealing with the
 * app's own (deliberately generic) error page.
 *
 * Shows only pass/fail per item -- never prints actual secret values.
 *
 * DELETE THIS FILE once your setup is working. It's harmless (no secrets
 * leak from it), but there's no reason to leave a diagnostic endpoint
 * reachable in production.
 */

header('Content-Type: text/plain; charset=utf-8');

$root = __DIR__;
echo "=== MARS Delivery & Collection -- setup check ===\n\n";

// 1. .env file presence, with the exact filename check that trips people up
$envPath = $root . '/.env';
$found = is_file($envPath);
echo '[.env file]      ' . ($found ? "FOUND at {$envPath}" : 'NOT FOUND at ' . $envPath) . "\n";
if (!$found) {
    $candidates = glob($root . '/.env*');
    if ($candidates) {
        echo "  Files matching '.env*' in this folder instead:\n";
        foreach ($candidates as $c) {
            echo '    - ' . basename($c) . "\n";
        }
        echo "  If you see '.env.example' or '.env.txt' above, that's the problem --\n";
        echo "  rename it to exactly '.env' (no other extension). On Windows, do this\n";
        echo "  from Command Prompt (ren .env.example .env), not Explorer's rename box,\n";
        echo "  since Explorer hides extensions by default and can rename it wrong.\n";
    } else {
        echo "  No '.env*' file found at all. Copy .env.example to .env first.\n";
    }
}
echo "\n";

// 2. Load .env the same way bootstrap.php does, into a local array only
// (never touches real process env, never printed).
$parsed = [];
if ($found) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line, " \t\n\r\0\x0B\xEF\xBB\xBF");
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        [$key, $value] = explode('=', $line, 2);
        $parsed[trim($key)] = trim($value);
    }
}

// 3. Required variables -- report presence only, never the value
$required = ['DB1_SERVER', 'DB1_NAME', 'DB1_USER', 'DB1_PASS'];
echo "[Required variables]\n";
foreach ($required as $name) {
    $inEnvFile = array_key_exists($name, $parsed) && $parsed[$name] !== '';
    $inProcessEnv = getenv($name) !== false && getenv($name) !== '';
    $ok = $inEnvFile || $inProcessEnv;
    $source = $inProcessEnv ? 'OS environment' : ($inEnvFile ? '.env file' : 'not set');
    echo '  ' . str_pad($name, 12) . ($ok ? "OK  ({$source})" : 'MISSING') . "\n";
}
echo "\n";

// 4. PHP / driver checks
echo "[PHP environment]\n";
echo '  PHP version      ' . PHP_VERSION . "\n";
echo '  pdo_sqlsrv        ' . (extension_loaded('pdo_sqlsrv') ? 'loaded' : 'NOT LOADED -- install the Microsoft Drivers for PHP for SQL Server') . "\n";
echo "\n";

// 5. Uploads folder writability
$uploadsDir = $root . '/Uploads';
echo "[Uploads folder]\n";
echo '  ' . $uploadsDir . '   ' . (is_dir($uploadsDir) && is_writable($uploadsDir) ? 'OK (writable)' : 'NOT WRITABLE -- create it and grant the web server write access') . "\n";
echo "\n";

echo "Delete this file (check-setup.php) once everything above says OK.\n";
