/**
 * Service worker: static-asset caching (as before) plus page-shell
 * caching for real browser navigations, which is what lets Dashboard,
 * Delivery-Portal, Collection-Portal and Device-Settings keep opening
 * (from the sidebar, a refresh, or a bookmark) while the device has no
 * connection at all -- not just while an already-open tab is online.
 *
 * Still deliberately does NOT touch anything that isn't a plain GET:
 * every Ajax POST (saves, uploads, customer search, ...) always goes
 * straight to the network, untouched -- offline-core.js is the one place
 * responsible for queueing/caching those, with full knowledge of what's
 * safe to replay or serve stale. This file only ever caches:
 *   1. Static assets (css/js/images/fonts) -- cache-first, refreshed in
 *      the background on every load.
 *   2. Page shells for actual navigations (typing a URL, clicking a link,
 *      hitting refresh) -- network-first, falling back to whatever was
 *      last cached for that *exact* URL (including its query string, so
 *      "?page=Delivery-Portal&customer=ABC123" and a plain
 *      "?page=Delivery-Portal" are cached separately), and finally
 *      falling back to offline.html if nothing was ever cached for it.
 *
 * A navigation response is only cached when it lands directly on the
 * requested URL with a 200 (response.redirected === false). A redirect
 * (e.g. the session had expired and the server bounced to the login page)
 * is never cached under the original page's URL -- otherwise a later
 * offline visit to that URL could silently show stale login-page content
 * instead of the real page.
 */

const STATIC_CACHE = 'mars-dc-static-v2';
const PAGE_CACHE = 'mars-dc-pages-v2';
const OFFLINE_URL = 'offline.html';
const STATIC_PATTERN = /\.(css|js|png|jpg|jpeg|svg|ico|woff2?)(\?.*)?$/i;

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(PAGE_CACHE)
            .then((cache) => cache.add(OFFLINE_URL))
            .catch(() => { /* offline.html not reachable at install time -- non-fatal */ })
    );
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    const keep = new Set([STATIC_CACHE, PAGE_CACHE]);
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(keys.filter((key) => !keep.has(key)).map((key) => caches.delete(key)))
        )
    );
    self.clients.claim();
});

// offline-core.js (on user-switch/logout) and offline-indicator.js (Logout
// click, when there's nothing left unsynced) both post this to make sure a
// shared device never serves the next person a page shell baked with the
// previous person's data (dashboard totals, delivery/collection lists,
// customer names...).
self.addEventListener('message', (event) => {
    if (event.data === 'CLEAR_SESSION_DATA') {
        event.waitUntil(
            caches.open(PAGE_CACHE).then((cache) =>
                cache.keys().then((requests) =>
                    Promise.all(
                        requests
                            .filter((request) => !request.url.endsWith(OFFLINE_URL))
                            .map((request) => cache.delete(request))
                    )
                )
            )
        );
    }
});

async function staticAssetStrategy(request) {
    const cache = await caches.open(STATIC_CACHE);
    const cached = await cache.match(request);
    const network = fetch(request)
        .then((response) => {
            if (response.ok) cache.put(request, response.clone());
            return response;
        })
        .catch(() => cached);
    // Cache-first for instant repeat loads, refreshed in the background.
    return cached || network;
}

async function pageShellStrategy(request) {
    const cache = await caches.open(PAGE_CACHE);
    try {
        const response = await fetch(request);
        if (response.ok && !response.redirected) {
            cache.put(request, response.clone());
        }
        return response;
    } catch (networkErr) {
        const cached = await cache.match(request);
        if (cached) return cached;
        const offlinePage = await cache.match(OFFLINE_URL);
        return offlinePage || Response.error();
    }
}

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') return; // every write always goes straight to the network

    const url = new URL(request.url);
    if (url.origin !== self.location.origin) return; // never touch cross-origin (map tiles, CDN fonts, ...)

    if (STATIC_PATTERN.test(url.pathname)) {
        event.respondWith(staticAssetStrategy(request));
        return;
    }

    // request.mode is 'navigate' only for a real top-level browser
    // navigation (address bar, link, form GET, refresh) -- never for a
    // page's own fetch()/XHR calls, which is exactly the distinction that
    // keeps this from ever intercepting an Ajax GET.
    if (request.mode === 'navigate') {
        event.respondWith(pageShellStrategy(request));
    }
});
