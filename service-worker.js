/**
 * Minimal service worker, only here to satisfy PWA installability criteria
 * and speed up repeat loads of static assets.
 *
 * Deliberately does NOT cache anything dynamic: no .php pages, no Ajax
 * responses, nothing from a POST request. Caching a login/session-bearing
 * page or an API response would risk showing stale data (or another user's
 * data on a shared device) after logout. Only true static files
 * (CSS/JS/images/the manifest itself) are cached, and only via GET.
 */

const CACHE_NAME = 'mars-dc-static-v1';
const STATIC_PATTERN = /\.(css|js|png|jpg|jpeg|svg|ico|woff2?)(\?.*)?$/i;

self.addEventListener('install', (event) => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key)))
        )
    );
    self.clients.claim();
});

self.addEventListener('fetch', (event) => {
    const request = event.request;

    // Never intercept anything but simple GETs, and never anything that
    // isn't a plain static asset -- every .php request (pages, Ajax
    // endpoints) always goes straight to the network, untouched.
    if (request.method !== 'GET' || !STATIC_PATTERN.test(new URL(request.url).pathname)) {
        return;
    }

    event.respondWith(
        caches.open(CACHE_NAME).then(async (cache) => {
            const cached = await cache.match(request);
            const network = fetch(request)
                .then((response) => {
                    if (response.ok) cache.put(request, response.clone());
                    return response;
                })
                .catch(() => cached);
            // Cache-first for instant repeat loads, refreshed in the background.
            return cached || network;
        })
    );
});
