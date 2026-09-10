/**
 * Dashboard stats card refresh. This file previously also contained a
 * submenu dropdown toggle, a notification-bell dropdown, and a
 * "more info" modal (openMoreInfoModal/closeMoreInfoModal/
 * closeMoreInfoModalx/toggleDropdown) -- none of those had matching
 * elements in any current view, and the notification dropdown called
 * Ajax/ajax_notification.php, which doesn't exist in this codebase. All of
 * that was dead code and has been removed; this file now only does what's
 * actually wired to app/Views/dashboard/index.php.
 */
function fetchUpdates() {
    // This script only loads on the Dashboard page, but guard anyway.
    if (!document.getElementById("totalUsers")) return;

    // Offline mode: mars.offline.fetchJSON tries the network first and
    // transparently falls back to the last cached response when there's no
    // connection, so refreshing the Dashboard offline shows the numbers
    // from the last successful load instead of erroring out. Falls back to
    // a plain fetch if offline-core.js somehow didn't load.
    const loader = window.mars && window.mars.offline
        ? window.mars.offline.fetchJSON("../Ajax/ajax_dashboard.php?_=" + Date.now())
        : fetch("../Ajax/ajax_dashboard.php?_=" + Date.now(), { credentials: "same-origin" })
            .then(async (res) => ({ data: await res.json(), fromCache: false, __res: res }));

    loader
        .then(({ data, fromCache, __res }) => {
            if (__res && __res.status === 401) {
                window.location.href = "../";
                return null;
            }
            if (!data || data.error) throw new Error((data && data.message) || "Unable to refresh dashboard.");
            return { data, fromCache };
        })
        .then((result) => {
            if (!result) return;
            const { data, fromCache } = result;

            [['totalUsers', 'totalUsers'], ['totalTransactions', 'totalTransactions'], ['totalCollections', 'totalCollections'], ['totalDeliveries', 'totalDeliveries']]
                .forEach(([elementId, field]) => {
                    const element = document.getElementById(elementId);
                    if (element) element.innerText = data[field] ?? 0;
                });

            // "own" scope (a regular rider/salesman) shows only their own
            // numbers, not everyone's -- relabel the cards so that's clear.
            if (data.scope === 'own') {
                const labels = {
                    totalTransactionsLabel: 'My Transactions',
                    totalCollectionsLabel: 'My Confirmed Collections',
                    totalDeliveriesLabel: 'My Completed Deliveries',
                };
                Object.entries(labels).forEach(([elementId, text]) => {
                    const element = document.getElementById(elementId);
                    if (element) element.innerText = text;
                });
            }

            const staleNotice = document.getElementById('dashboardStaleNotice');
            if (staleNotice) staleNotice.classList.toggle('dc-hidden', !fromCache);
        })
        .catch(err => {
            // mars.offline.fetchJSON() doesn't hand back the raw Response
            // (there may not even be one, on a cache-fallback hit), so a
            // real 401 surfaces here as a thrown Error with `.status` set
            // instead of via the `__res.status` check above -- catch it
            // here too so an expired session still redirects to login
            // instead of just logging to the console.
            if (err && err.status === 401) {
                window.location.href = "../";
                return;
            }
            console.error("Fetch error:", err);
        });
}
// run immediately
fetchUpdates();
// Refresh dashboard figures periodically while the page is open.
setInterval(fetchUpdates, 30000);

// Login-time offline bootstrap: the Dashboard is always the first page a
// rider lands on after signing in, so it's the natural place to pull down
// Customers/InvoiceList/TripInvoice/TriplistAssign into IndexedDB for the
// Delivery/Collection portals to use later with no connection. No-ops
// quietly if already offline right now -- whatever was cached last login
// stays in place.
if (window.mars && window.mars.offline) {
    window.mars.offline.bootstrap();
}
