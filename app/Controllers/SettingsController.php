<?php
declare(strict_types=1);

/**
 * Per-device app settings (install-as-app, full-screen preference). These
 * are stored client-side (localStorage) by pwa-install.js, not in the
 * database -- they describe how this one browser/device behaves, not
 * anything about the user's account.
 */
class SettingsController
{
    public function index(): void
    {
        requireBootstrapped();
        View::render('settings/device', [], []);
    }
}
