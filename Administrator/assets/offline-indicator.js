/**
 * offline-indicator.js
 * ---------------------
 * Renders the ONLINE / OFFLINE / SYNCING badge and its dropdown (pending
 * queued items, a manual "Sync now", and "Clear offline data"). Purely a
 * view over window.mars.offline's state -- all the real logic lives in
 * offline-core.js.
 *
 * Also wires the Logout link: offline data must not survive past logout on
 * a shared device, so clicking Logout clears this user's IndexedDB data and
 * the service worker's page-shell cache before the normal navigation to the
 * logout URL proceeds.
 */
(function () {
    'use strict';

    if (!window.mars || !window.mars.offline) return; // offline-core.js didn't load; fail quiet

    function el(tag, attrs, children) {
        const node = document.createElement(tag);
        Object.keys(attrs || {}).forEach((k) => {
            if (k === 'text') node.textContent = attrs[k];
            else node.setAttribute(k, attrs[k]);
        });
        (children || []).forEach((c) => node.appendChild(c));
        return node;
    }

    const badge = el('button', { id: 'marsOfflineBadge', class: 'mars-offline-badge', type: 'button' });
    const dot = el('span', { class: 'mars-offline-dot' });
    const label = el('span', { class: 'mars-offline-label', text: 'Checking...' });
    badge.appendChild(dot);
    badge.appendChild(label);

    const panel = el('div', { id: 'marsOfflinePanel', class: 'mars-offline-panel' });
    panel.hidden = true;

    document.addEventListener('DOMContentLoaded', () => {
        document.body.appendChild(badge);
        document.body.appendChild(panel);
    });

    badge.addEventListener('click', () => {
        panel.hidden = !panel.hidden;
        if (!panel.hidden) renderPanel();
    });
    document.addEventListener('click', (e) => {
        if (!panel.hidden && !panel.contains(e.target) && e.target !== badge) {
            panel.hidden = true;
        }
    });

    function render(state) {
        badge.classList.toggle('is-online', !!state.online);
        badge.classList.toggle('is-offline', !state.online);
        badge.classList.toggle('is-syncing', state.online && state.pendingCount > 0);
        if (!state.online) {
            label.textContent = 'OFFLINE';
        } else if (state.pendingCount > 0) {
            label.textContent = 'SYNCING (' + state.pendingCount + ')';
        } else if (state.lastError === 'session-expired') {
            label.textContent = 'SIGN IN AGAIN';
        } else {
            label.textContent = 'ONLINE';
        }
        if (!panel.hidden) renderPanel();
    }

    async function renderPanel() {
        const state = window.mars.offline.getState();
        const items = await window.mars.offline.getOutboxItems();
        panel.innerHTML = '';

        const statusLine = el('div', { class: 'mars-offline-panel-status' });
        statusLine.textContent = state.online
            ? (state.pendingCount > 0 ? state.pendingCount + ' change(s) waiting to sync.' : 'All changes are synced.')
            : "You're offline. Changes you make will be saved and synced automatically once you're back online.";
        panel.appendChild(statusLine);

        if (state.lastError === 'session-expired') {
            const warn = el('div', { class: 'mars-offline-panel-warn' });
            warn.textContent = 'Your session expired while offline. Please log in again to sync your changes.';
            panel.appendChild(warn);
        }

        if (items.length > 0) {
            const list = el('ul', { class: 'mars-offline-panel-list' });
            items.forEach((item) => {
                const li = el('li');
                const desc = el('span', { class: 'mars-offline-item-desc', text: item.description || item.url });
                li.appendChild(desc);
                if (item.lastError) {
                    li.appendChild(el('span', { class: 'mars-offline-item-error', text: item.lastError }));
                }
                const actions = el('div', { class: 'mars-offline-item-actions' });
                const retryBtn = el('button', { type: 'button', text: 'Retry' });
                retryBtn.addEventListener('click', async () => {
                    await window.mars.offline.retryOutboxItem(item.id);
                    renderPanel();
                });
                const discardBtn = el('button', { type: 'button', text: 'Discard' });
                discardBtn.addEventListener('click', async () => {
                    if (confirm('Discard this unsynced change? This cannot be undone.')) {
                        await window.mars.offline.discardOutboxItem(item.id);
                        renderPanel();
                    }
                });
                actions.appendChild(retryBtn);
                actions.appendChild(discardBtn);
                li.appendChild(actions);
                list.appendChild(li);
            });
            panel.appendChild(list);
        }

        const btnRow = el('div', { class: 'mars-offline-panel-actions' });
        const syncBtn = el('button', { type: 'button', text: 'Sync now' });
        syncBtn.addEventListener('click', async () => {
            await window.mars.offline.checkNow();
            await window.mars.offline.flushOutbox();
            renderPanel();
        });
        btnRow.appendChild(syncBtn);

        const clearBtn = el('button', { type: 'button', class: 'mars-offline-clear-btn', text: 'Clear offline data' });
        clearBtn.addEventListener('click', async () => {
            if (confirm('This removes any locally cached data and unsynced changes on this device. Continue?')) {
                await window.mars.offline.clearAll();
                renderPanel();
            }
        });
        btnRow.appendChild(clearBtn);

        panel.appendChild(btnRow);
    }

    window.mars.offline.onStatusChange(render);
    render(window.mars.offline.getState());

    // ------------------------------------------------------------------
    // Logout: clear this user's offline data before leaving the page, so a
    // shared device doesn't hand the next person cached invoices, trip
    // lists, or a queued-but-unsynced write belonging to the previous user.
    // ------------------------------------------------------------------
    document.addEventListener('DOMContentLoaded', () => {
        const logoutLink = document.getElementById('logoutLink');
        if (!logoutLink) return;
        logoutLink.addEventListener('click', async (e) => {
            const state = window.mars.offline.getState();
            if (state.pendingCount > 0) {
                const proceed = confirm(
                    state.pendingCount + ' change(s) have not synced yet. Logging out now will keep them saved on ' +
                    'this device for next time you log in as the same user, but they will NOT be visible to other ' +
                    'accounts. Continue logging out?'
                );
                if (!proceed) {
                    e.preventDefault();
                    return;
                }
                // Pending items belong to this user; only clear the page
                // shell cache, keep the outbox so it can sync next login.
                if (navigator.serviceWorker && navigator.serviceWorker.controller) {
                    navigator.serviceWorker.controller.postMessage('CLEAR_SESSION_DATA');
                }
                return;
            }
            e.preventDefault();
            await window.mars.offline.clearAll();
            window.location.href = logoutLink.href;
        });
    });
})();
