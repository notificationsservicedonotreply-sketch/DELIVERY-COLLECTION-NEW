/**
 * Service worker: static asset caching (unchanged from before) plus
 * best-effort caching of *page shells* for offline reloads.
 *
 * Still deliberately does NOT cache Ajax responses or anything from a
 * POST/PUT/DELETE request -- that guarantee from the original minimal
 * version is untouched. Application *data* for offline use lives in
 * IndexedDB via offline-core.js instead, where it can be scoped per user
 * and explicitly cleared on logout. This file only ever caches:
 *   1. True static files (CSS/JS/images/fonts/the manifest) -- cache-first,
 *      refreshed in the background. (Original behaviour.)
 *   2. The last successfully-loaded HTML for pages the user actually
 *      navigated to (GET, mode:'navigate' only) -- network-first, so a
 *      rider or admin who refreshes or reopens the app with no signal still
 *      sees the page they were just on, instead of a browser error page.
 *
 * Why this doesn't reopen the "stale/leaked session data" risk the original
 * comment warned about: the page cache is wiped whenever offline-core.js
 * detects the logged-in user changed, and again on explicit Logout (see
 * offline-indicator.js) via the CLEAR_SESSION_DATA message below. Ajax
 * endpoints (/Ajax/*.php) are excluded from navigation handling entirely --
 * they only ever load inside a page via fetch(), never as a top-level
 * navigation, so PAGE_CACHE never sees them regardless.
 */

const STATIC_CACHE = 'mars-dc-static-v2';
const PAGE_CACHE = 'mars-dc-pages-v1';
const CURRENT_CACHES = [STATIC_CACHE, PAGE_CACHE];

const STATIC_PATTERN = /\.(css|js|png|jpg|jpeg|svg|ico|woff2?)(\?.*)?$/i;
const OFFLINE_URL = 'offline.html';

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(STATIC_CACHE).then((cache) => cache.addAll([OFFLINE_URL]).catch(() => {
            // Best-effort: don't block installability if this fails.
        }))
    );
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(keys.filter((key) => !CURRENT_CACHES.includes(key)).map((key) => caches.delete(key)))
        )
    );
    self.clients.claim();
});

// Logout (or user-switch detection) tells us to drop any cached page HTML
// so the next person on a shared device never gets served a stale,
// permission-gated page that belonged to the previous user.
self.addEventListener('message', (event) => {
    if (event.data === 'CLEAR_SESSION_DATA') {
        event.waitUntil(caches.delete(PAGE_CACHE));
    }
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') return; // never touch POST/PUT/DELETE, unchanged

    const url = new URL(request.url);

    // Static assets: cache-first, background refresh. Unchanged behaviour.
    if (STATIC_PATTERN.test(url.pathname)) {
        event.respondWith(
            caches.open(STATIC_CACHE).then(async (cache) => {
                const cached = await cache.match(request);
                const network = fetch(request)
                    .then((response) => {
                        if (response.ok) cache.put(request, response.clone());
                        return response;
                    })
                    .catch(() => cached);
                return cached || network;
            })
        );
        return;
    }

    // Page navigations only (address bar / link / reload) -- never Ajax
    // fetches, which use mode 'cors' or 'same-origin', not 'navigate'.
    if (request.mode === 'navigate') {
        event.respondWith(
            (async () => {
                const cache = await caches.open(PAGE_CACHE);
                try {
                    const response = await fetch(request);
                    if (response.ok) cache.put(request, response.clone());
                    return response;
                } catch (networkErr) {
                    const cached = await cache.match(request);
                    if (cached) return cached;
                    const offline = await caches.match(OFFLINE_URL);
                    return offline || Response.error();
                }
            })()
        );
    }
    // Everything else (Ajax/*.php, etc.) is left completely untouched and
    // goes straight to the network, exactly as before.
});
