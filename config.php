<?php
/**
 * Application configuration.
 *
 * SECURITY: This file must never contain real credentials. Every value below
 * is read from the environment (OS environment variables, or a local .env
 * file loaded by bootstrap.php in development). If a required value is
 * missing, we fail loudly instead of silently falling back to a hardcoded
 * secret -- a hardcoded fallback here is exactly what leaked the previous
 * database password when this repository was made public.
 *
 * Required environment variables (see .env.example):
 *   DB1_SERVER, DB1_NAME, DB1_USER, DB1_PASS
 *   APP_ENV            'production' | 'development'  (default: production)
 *   APP_FORCE_HTTPS    '1' to require HTTPS in production (default: '1')
 *   APP_TRUSTED_HOSTS  optional comma-separated hostnames (no scheme/port)
 *                      that skip the HTTPS requirement above even in
 *                      production, e.g. a bare Tailscale MagicDNS short
 *                      name or a custom .local hostname that bootstrap.php
 *                      can't recognize automatically. Localhost, LAN IPs
 *                      (192.168.x.x/10.x.x.x/172.16-31.x.x), Tailscale IPs
 *                      (100.64.0.0/10), and any *.ts.net hostname are
 *                      already trusted without needing to be listed here --
 *                      see bootstrap.php.
 */

function config_require(string $name): string
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        http_response_code(500);
        error_log("Configuration error: required environment variable {$name} is not set.");

        // This hint doesn't leak secrets -- it only says whether a .env file
        // was found at all, which is the #1 cause of this error on a fresh
        // local setup (usually because the file didn't actually get saved as
        // ".env" -- e.g. Windows Explorer's rename box hides extensions, so
        // renaming ".env.example" can silently leave it as ".env.example" or
        // save it as ".env.txt" instead of ".env").
        if (defined('ENV_FILE_FOUND') && !ENV_FILE_FOUND) {
            die(
                'Server configuration error: no .env file was found in the project root, ' .
                'and no environment variables are set at the OS level. ' .
                'If you just renamed .env.example to .env, verify the exact filename from a ' .
                'command prompt (Windows Explorer often hides the real extension) -- run ' .
                '"dir /a" in the project folder and confirm it shows exactly ".env", not ' .
                '".env.example" or ".env.txt". See .env.example and MIGRATION.md for setup steps.'
            );
        }

        die('Server configuration error. Please contact the administrator.');
    }
    return $value;
}

return [
    'env' => getenv('APP_ENV') ?: 'production',
    'force_https' => (getenv('APP_FORCE_HTTPS') ?: '1') === '1',

    'db1' => [
        'serverName'   => config_require('DB1_SERVER'),
        'databaseName' => config_require('DB1_NAME'),
        'userName'     => config_require('DB1_USER'),
        'password'     => config_require('DB1_PASS'),
    ],
];
