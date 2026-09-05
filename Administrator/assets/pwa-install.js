/**
 * Handles: registering the service worker, showing/wiring an "Install app"
 * button when the browser says the app is installable, and a "full screen
 * on open" preference (client-side only, no server round-trip -- matches
 * the "first tap after reopening enters full screen" pattern, since
 * browsers only allow requesting fullscreen from a direct user gesture).
 *
 * window.PWA_BASE_PREFIX must be set by the page before this script loads:
 * '' when the page is served from the repo root (the login page), or '../'
 * when served from one directory deeper (every Administrator page). This is
 * needed because service-worker.js and manifest.json both live at the repo
 * root, and this one script file is shared by pages at both depths.
 */
(function () {
    const prefix = window.PWA_BASE_PREFIX || '';

    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register(prefix + 'service-worker.js').catch((error) => {
                console.warn('Service worker registration failed.', error);
            });
        });
    }

    const installButtons = () => document.querySelectorAll('[data-pwa-install]');
    const installHint = () => document.getElementById('pwaInstallHint');
    let deferredPrompt = null;
    let promptReady = false;

    const isStandaloneNow = () => window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
    const isSecureOrigin = () => window.isSecureContext || location.hostname === 'localhost' || location.hostname === '127.0.0.1';
    const isIOS = () => /iphone|ipad|ipod/i.test(navigator.userAgent) && !window.MSStream;

    function setHint(text, isWarning) {
        const hint = installHint();
        if (!hint) return;
        hint.innerHTML = text;
        hint.style.color = isWarning ? '#a71927' : '';
    }

    function manualInstructions() {
        if (isIOS()) {
            return 'Tap the Share icon in Safari\u2019s toolbar, then "Add to Home Screen".';
        }
        if (!isSecureOrigin()) {
            return 'This page is on a plain http:// address, so one-tap install isn\u2019t offered here. Ask your admin for the https:// link, or use your browser\u2019s menu (\u22ee) \u2192 "Add to Home screen" / "Install app".';
        }
        return 'One-tap install isn\u2019t available right now (this is normal \u2014 Chrome only offers it after some engagement with the site, or hides it for a while after a prior dismissal). Use your browser\u2019s menu (\u22ee or \u2026) \u2192 "Add to Home screen" / "Install app" instead.';
    }

    function refreshInstallUi() {
        if (isStandaloneNow()) {
            installButtons().forEach((button) => {
                button.disabled = true;
                button.innerHTML = '<i class="fa-solid fa-circle-check" aria-hidden="true"></i> Already installed';
                button.classList.add('btn-installed');
            });
            setHint('This app is already installed on this device.', false);
            return;
        }

        // Don't leave the person guessing if the one-tap prompt never shows up
        // (normal on many visits -- see manualInstructions()) -- show the
        // manual fallback proactively instead of waiting for a click.
        setTimeout(() => {
            if (!promptReady && !isStandaloneNow()) setHint(manualInstructions(), !isSecureOrigin());
        }, 2000);
    }

    window.addEventListener('beforeinstallprompt', (event) => {
        event.preventDefault();
        deferredPrompt = event;
        promptReady = true;
        setHint('Ready to install \u2014 tap the button above.', false);
    });

    document.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-pwa-install]');
        if (!button || button.disabled) return;

        if (deferredPrompt) {
            deferredPrompt.prompt();
            await deferredPrompt.userChoice;
            deferredPrompt = null;
            promptReady = false;
            return;
        }

        setHint(manualInstructions(), !isSecureOrigin());
    });

    window.addEventListener('appinstalled', () => {
        deferredPrompt = null;
        refreshInstallUi();
    });

    document.addEventListener('DOMContentLoaded', refreshInstallUi);

    // Full-screen preference.
    const FULLSCREEN_KEY = 'mars_fullscreen_pref';
    const isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
    const fullscreenPreferred = () => localStorage.getItem(FULLSCREEN_KEY) === '1';

    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('[data-fullscreen-toggle]').forEach((toggle) => {
            toggle.checked = fullscreenPreferred();
            toggle.addEventListener('change', () => {
                localStorage.setItem(FULLSCREEN_KEY, toggle.checked ? '1' : '0');
            });
        });
    });

    if (fullscreenPreferred() && isStandalone && !document.fullscreenElement) {
        const enterFullscreenOnce = () => {
            document.documentElement.requestFullscreen?.().catch(() => { /* user can retry manually */ });
            document.removeEventListener('click', enterFullscreenOnce);
            document.removeEventListener('touchend', enterFullscreenOnce);
        };
        document.addEventListener('click', enterFullscreenOnce, { once: true });
        document.addEventListener('touchend', enterFullscreenOnce, { once: true });
    }
})();
