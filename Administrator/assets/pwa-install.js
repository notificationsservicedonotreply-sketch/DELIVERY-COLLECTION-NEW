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

    // Distinguish *why* the origin isn't secure, so the hint can point
    // straight at the fix instead of a generic "use https" message.
    // Covers: bare IPv4 addresses (LAN or Tailscale's 100.64.0.0/10 CGNAT
    // range) served over plain http, and Tailscale MagicDNS names
    // (*.ts.net) that haven't had a cert issued for them yet -- both are
    // extremely common ways people reach this app from outside a normal
    // domain, and both have a real fix (a Tailscale HTTPS cert), not just
    // "ask your admin for a different link".
    const isIPv4 = (host) => /^(\d{1,3}\.){3}\d{1,3}$/.test(host);
    const isPrivateIPv4 = (host) => {
        if (!isIPv4(host)) return false;
        const [a, b] = host.split('.').map(Number);
        return a === 10 || (a === 172 && b >= 16 && b <= 31) || (a === 192 && b === 168) || (a === 100 && b >= 64 && b <= 127);
    };
    const isTailscaleMagicDns = (host) => /\.ts\.net$/i.test(host);

    function insecureOriginReason() {
        const host = location.hostname;
        if (isTailscaleMagicDns(host)) {
            return `This Tailscale address (<code>${host}</code>) isn\u2019t served over HTTPS yet, and browsers require HTTPS (or localhost) before they\u2019ll let a site be installed. Tailscale can issue a free, trusted certificate for this exact name \u2014 ask whoever manages the server to run <code>tailscale cert ${host}</code> and serve this app over <code>https://</code> using that certificate. Once that\u2019s done, install will work normally, including over Tailscale from any device.`;
        }
        if (isPrivateIPv4(host)) {
            return `Browsers won\u2019t allow installing an app from a plain IP address (<code>${host}</code>) over <code>http://</code> \u2014 not on your LAN, and not over Tailscale either. If this server has Tailscale installed, use its MagicDNS name instead of the raw IP (something like <code>https://your-device.your-tailnet.ts.net</code>) and have Tailscale issue it a certificate with <code>tailscale cert</code>. Otherwise, ask your admin to put the app behind a proper <code>https://</code> domain.`;
        }
        return 'This page is on a plain http:// address, so one-tap install isn\u2019t offered here. Ask your admin for the https:// link, or follow the steps below.';
    }

    function setHint(text, isWarning) {
        const hint = installHint();
        if (!hint) return;
        hint.innerHTML = text;
        hint.style.color = isWarning ? '#a71927' : '';
    }

    function manualInstructions() {
        if (isIOS()) {
            return 'One-tap install isn\u2019t available on iOS. Follow the "iPhone / iPad (Safari)" steps below.';
        }
        if (!isSecureOrigin()) {
            return insecureOriginReason();
        }
        return 'One-tap install isn\u2019t available right now (this is normal \u2014 the browser only offers it after some engagement with the site, or hides it for a while after a prior dismissal). Follow the steps below instead.';
    }

    function refreshInstallUi() {
        const manualSteps = document.getElementById('pwaManualSteps');
        const securityNotice = document.getElementById('pwaSecurityNotice');

        if (isStandaloneNow()) {
            installButtons().forEach((button) => {
                button.disabled = true;
                button.innerHTML = '<i class="fa-solid fa-circle-check" aria-hidden="true"></i> Already installed';
                button.classList.add('btn-installed');
            });
            setHint('This app is already installed on this device.', false);
            if (manualSteps) manualSteps.style.display = 'none';
            if (securityNotice) securityNotice.style.display = 'none';
            return;
        }

        // Surface the http:// / IP-address / Tailscale diagnosis up front,
        // since it's the one case where following the manual install steps
        // below still won't work until the underlying address is fixed.
        if (securityNotice && !isSecureOrigin()) {
            securityNotice.innerHTML = '<i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i> ' + insecureOriginReason();
            securityNotice.style.display = 'block';
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
