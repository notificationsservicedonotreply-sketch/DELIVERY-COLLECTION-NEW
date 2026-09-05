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
    fetch("../Ajax/ajax_dashboard.php?_=" + Date.now(), { credentials: "same-origin" })
        .then(async res => {
            const data = await res.json();
            if (res.status === 401) {
                window.location.href = "../";
                return null;
            }
            if (!res.ok || data.error) throw new Error(data.message || "Unable to refresh dashboard.");
            return data;
        })
        .then(data => {
            if (!data) return;

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
        })
        .catch(err => console.error("Fetch error:", err));
}
// run immediately
fetchUpdates();
// Refresh dashboard figures periodically while the page is open.
setInterval(fetchUpdates, 30000);
