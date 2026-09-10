/**
 * offline-core.js
 * ----------------
 * The data layer behind offline mode. Everything here is deliberately kept
 * separate from service-worker.js: the service worker only ever caches
 * static assets and page *shells* (see its own comments for why it still
 * never touches Ajax responses). Actual application data -- the stuff a
 * rider or admin needs to see and act on with no signal -- lives here, in
 * IndexedDB, scoped per logged-in user so nothing leaks across accounts on
 * a shared device.
 *
 * Exposes window.mars.offline with:
 *   fetchJSON(url, options)   - GET wrapper: network first, IndexedDB cache
 *                                fallback when offline. Every successful
 *                                response is cached for next time.
 *   submit(url, formData, {description}) - POST wrapper: sends immediately
 *                                if reachable; if the network itself is
 *                                unreachable, queues the request in the
 *                                outbox and returns {queued:true} instead of
 *                                throwing, so calling code can show "saved
 *                                offline, will sync" instead of an error.
 *   getState()                - {online, checking, pendingCount, lastError}
 *   onStatusChange(fn)        - subscribe to state changes
 *   flushOutbox()             - replay queued writes now (called
 *                                automatically on reconnect too)
 *   retryOutboxItem(id) / discardOutboxItem(id)
 *   clearAll()                - wipes this user's local data (called on
 *                                logout and on user-switch detection)
 *
 * Every page that wants offline behaviour opts in by calling
 * mars.offline.fetchJSON / .submit instead of raw fetch(). Pages that
 * haven't been converted yet keep working exactly as before -- this file
 * changes nothing about how existing fetch() calls behave.
 */
(function () {
    'use strict';

    const PING_URL_SUFFIX = 'Ajax/ajax_session_ping.php';
    const PING_INTERVAL_MS = 20000;
    const FETCH_TIMEOUT_MS = 8000;
    const MAX_AUTO_RETRIES = 5;
    const DB_VERSION = 1;

    const prefix = window.PWA_BASE_PREFIX || '';
    const pingUrl = prefix + PING_URL_SUFFIX;

    // The current user id is rendered server-side into every page (see
    // header.php / login.php). 'guest' scopes local data for the logged-out
    // (login) page, which never stores anything sensitive anyway.
    const currentUserId = (window.MARS_USER_ID && String(window.MARS_USER_ID).trim()) || 'guest';

    // ------------------------------------------------------------------
    // User-switch guard: if IndexedDB still holds another user's database
    // from a previous session on this device (e.g. they closed the tab
    // instead of using Logout), drop it before doing anything else. This is
    // best-effort defense-in-depth, not a guarantee -- the "Clear offline
    // data" action in the indicator, and always using Logout, are the real
    // safeguards on a shared device.
    // ------------------------------------------------------------------
    (function guardAgainstStaleUser() {
        try {
            const lastUser = localStorage.getItem('mars_offline_last_user');
            if (lastUser && lastUser !== currentUserId) {
                indexedDB.deleteDatabase(dbName(lastUser));
                notifyServiceWorkerToClearPages();
            }
            localStorage.setItem('mars_offline_last_user', currentUserId);
        } catch (e) {
            // localStorage/IndexedDB unavailable (private mode, very old
            // browser) -- offline mode simply won't persist; nothing to do.
        }
    })();

    function dbName(userId) {
        return 'mars-offline-' + userId;
    }

    let dbPromise = null;
    function openDatabase() {
        if (dbPromise) return dbPromise;
        dbPromise = new Promise((resolve, reject) => {
            if (!('indexedDB' in window)) {
                reject(new Error('IndexedDB unavailable'));
                return;
            }
            const req = indexedDB.open(dbName(currentUserId), DB_VERSION);
            req.onupgradeneeded = () => {
                const db = req.result;
                if (!db.objectStoreNames.contains('kv')) {
                    db.createObjectStore('kv', { keyPath: 'key' });
                }
                if (!db.objectStoreNames.contains('outbox')) {
                    const store = db.createObjectStore('outbox', { keyPath: 'id', autoIncrement: true });
                    store.createIndex('createdAt', 'createdAt');
                }
            };
            req.onsuccess = () => resolve(req.result);
            req.onerror = () => reject(req.error);
        });
        return dbPromise;
    }

    function tx(storeName, mode) {
        return openDatabase().then((db) => db.transaction(storeName, mode).objectStore(storeName));
    }

    function idbRequest(request) {
        return new Promise((resolve, reject) => {
            request.onsuccess = () => resolve(request.result);
            request.onerror = () => reject(request.error);
        });
    }

    // ------------------------------------------------------------------
    // Generic GET cache (the "kv" store)
    // ------------------------------------------------------------------
    async function cachePut(url, data) {
        try {
            const store = await tx('kv', 'readwrite');
            await idbRequest(store.put({ key: url, data, ts: Date.now() }));
        } catch (e) { /* non-fatal: caching is best-effort */ }
    }

    async function cacheGet(url) {
        try {
            const store = await tx('kv', 'readonly');
            return await idbRequest(store.get(url));
        } catch (e) {
            return undefined;
        }
    }

    // ------------------------------------------------------------------
    // Outbox (queued writes)
    // ------------------------------------------------------------------
    async function outboxAll() {
        try {
            const store = await tx('outbox', 'readonly');
            return await idbRequest(store.getAll());
        } catch (e) {
            return [];
        }
    }

    async function outboxCount() {
        try {
            const store = await tx('outbox', 'readonly');
            return await idbRequest(store.count());
        } catch (e) {
            return 0;
        }
    }

    async function outboxAdd(entry) {
        const store = await tx('outbox', 'readwrite');
        return idbRequest(store.add(entry));
    }

    async function outboxUpdate(entry) {
        const store = await tx('outbox', 'readwrite');
        return idbRequest(store.put(entry));
    }

    async function outboxDelete(id) {
        const store = await tx('outbox', 'readwrite');
        return idbRequest(store.delete(id));
    }

    function formDataToFields(formData) {
        const fields = {};
        for (const [key, value] of formData.entries()) {
            if (value instanceof File) {
                throw new Error(
                    "This includes a file/photo attachment, which can't be queued for offline sync yet. " +
                    'Please retry once you have a connection.'
                );
            }
            fields[key] = value;
        }
        return fields;
    }

    function fieldsToFormData(fields) {
        const fd = new FormData();
        Object.keys(fields).forEach((k) => fd.append(k, fields[k]));
        return fd;
    }

    // ------------------------------------------------------------------
    // Connectivity state
    // ------------------------------------------------------------------
    const state = {
        online: navigator.onLine,
        checking: false,
        pendingCount: 0,
        lastError: null,
        lastCheckedAt: null,
    };
    const listeners = new Set();

    function emit() {
        listeners.forEach((fn) => {
            try { fn({ ...state }); } catch (e) { /* listener's problem, not ours */ }
        });
        window.dispatchEvent(new CustomEvent('mars:offline-status', { detail: { ...state } }));
    }

    function fetchWithTimeout(url, options) {
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), FETCH_TIMEOUT_MS);
        return fetch(url, { ...options, signal: controller.signal }).finally(() => clearTimeout(timer));
    }

    let lastCsrfToken = null;

    async function checkConnectivity() {
        state.checking = true;
        emit();
        try {
            const res = await fetchWithTimeout(pingUrl, { credentials: 'same-origin', cache: 'no-store' });
            if (!res.ok) throw new Error('ping HTTP ' + res.status);
            const data = await res.json();
            if (data.csrfToken) lastCsrfToken = data.csrfToken;
            state.online = true;
            state.lastError = data.loggedIn ? null : 'session-expired';
        } catch (e) {
            state.online = false;
        }
        state.checking = false;
        state.lastCheckedAt = Date.now();
        state.pendingCount = await outboxCount();
        emit();

        if (state.online && state.pendingCount > 0) {
            flushOutbox();
        }
        return state.online;
    }

    async function refreshPendingCount() {
        state.pendingCount = await outboxCount();
        emit();
    }

    window.addEventListener('online', () => checkConnectivity());
    window.addEventListener('offline', () => { state.online = false; emit(); });
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') checkConnectivity();
    });
    setInterval(checkConnectivity, PING_INTERVAL_MS);
    // Kick off an initial check shortly after load rather than trusting
    // navigator.onLine alone, which only reflects the network adapter, not
    // whether the server is actually reachable.
    setTimeout(checkConnectivity, 300);
    refreshPendingCount();

    // ------------------------------------------------------------------
    // Public: fetchJSON (GET, cache-backed)
    // ------------------------------------------------------------------
    async function fetchJSON(url, options) {
        options = options || {};
        try {
            const res = await fetchWithTimeout(url, { credentials: 'same-origin', ...options });
            if (!res.ok) {
                const err = new Error('Server error ' + res.status);
                err.status = res.status;
                throw err;
            }
            const data = await res.json();
            cachePut(url, data);
            return { data, fromCache: false };
        } catch (err) {
            if (err && err.status) throw err; // real server error, not a connectivity problem
            const cached = await cacheGet(url);
            if (cached) {
                return { data: cached.data, fromCache: true, cachedAt: cached.ts };
            }
            throw err;
        }
    }

    // ------------------------------------------------------------------
    // Public: submit (POST, outbox-backed)
    // ------------------------------------------------------------------
    async function submit(url, formData, opts) {
        opts = opts || {};
        let res;
        try {
            res = await fetchWithTimeout(url, { method: 'POST', body: formData, credentials: 'same-origin' });
        } catch (networkErr) {
            // fetch() itself threw -- a genuine network failure (offline,
            // DNS failure, timeout abort), not a server-side rejection.
            // Queue it and let the sync engine replay it later.
            const fields = formDataToFields(formData); // throws if a File is attached
            const id = await outboxAdd({
                url,
                fields,
                description: opts.description || null,
                createdAt: Date.now(),
                attempts: 0,
                lastError: null,
            });
            await refreshPendingCount();
            return { queued: true, id };
        }
        if (!res.ok) {
            // We got a response, so we ARE online -- this is a real
            // validation/server error, not a connectivity issue. Surface it
            // instead of silently queueing (queueing here would just retry
            // a request the server has already told us is invalid).
            const err = new Error('Server error ' + res.status);
            err.status = res.status;
            throw err;
        }
        return { queued: false, response: res };
    }

    // ------------------------------------------------------------------
    // Sync engine
    // ------------------------------------------------------------------
    let flushing = false;
    async function flushOutbox() {
        if (flushing) return;
        flushing = true;
        try {
            if (!navigator.onLine) return;
            const items = await outboxAll();
            if (items.length === 0) return;

            // Refresh the CSRF token once per batch rather than per item --
            // it only rotates on login, but a long-offline session could
            // still have gone stale.
            try {
                const pingRes = await fetchWithTimeout(pingUrl, { credentials: 'same-origin', cache: 'no-store' });
                const pingData = await pingRes.json();
                if (!pingData.loggedIn) {
                    // Session is gone -- nothing we send will succeed. Leave
                    // the outbox intact and let the indicator tell the user
                    // to log back in; do NOT discard queued work.
                    state.lastError = 'session-expired';
                    emit();
                    return;
                }
                lastCsrfToken = pingData.csrfToken;
            } catch (e) {
                return; // still offline after all, stop here
            }

            items.sort((a, b) => a.createdAt - b.createdAt);
            for (const item of items) {
                if (lastCsrfToken && 'csrf_token' in item.fields) {
                    item.fields.csrf_token = lastCsrfToken;
                }
                const fd = fieldsToFormData(item.fields);
                try {
                    const res = await fetchWithTimeout(item.url, { method: 'POST', body: fd, credentials: 'same-origin' });
                    if (res.ok) {
                        await outboxDelete(item.id);
                    } else {
                        item.attempts = (item.attempts || 0) + 1;
                        item.lastError = 'Server rejected the request (HTTP ' + res.status + ').';
                        if (item.attempts >= MAX_AUTO_RETRIES) {
                            item.lastError += ' Stopped retrying automatically -- please review and retry or discard.';
                        }
                        await outboxUpdate(item);
                    }
                } catch (networkErr) {
                    // Lost connectivity mid-flush -- stop, keep the rest queued.
                    break;
                }
            }
            await refreshPendingCount();
        } finally {
            flushing = false;
        }
    }

    async function retryOutboxItem(id) {
        const items = await outboxAll();
        const item = items.find((i) => i.id === id);
        if (item) {
            item.attempts = 0;
            item.lastError = null;
            await outboxUpdate(item);
        }
        return flushOutbox();
    }

    async function discardOutboxItem(id) {
        await outboxDelete(id);
        await refreshPendingCount();
    }

    function notifyServiceWorkerToClearPages() {
        if (navigator.serviceWorker && navigator.serviceWorker.controller) {
            navigator.serviceWorker.controller.postMessage('CLEAR_SESSION_DATA');
        }
    }

    async function clearAll() {
        try {
            const db = await openDatabase();
            db.close();
        } catch (e) { /* ignore */ }
        await new Promise((resolve) => {
            const req = indexedDB.deleteDatabase(dbName(currentUserId));
            req.onsuccess = resolve;
            req.onerror = resolve;
            req.onblocked = resolve;
        });
        dbPromise = null;
        notifyServiceWorkerToClearPages();
        state.pendingCount = 0;
        emit();
    }

    window.mars = window.mars || {};
    window.mars.offline = {
        fetchJSON,
        submit,
        getState: () => ({ ...state }),
        getOutboxItems: outboxAll,
        onStatusChange: (fn) => { listeners.add(fn); return () => listeners.delete(fn); },
        flushOutbox,
        retryOutboxItem,
        discardOutboxItem,
        clearAll,
        checkNow: checkConnectivity,
        getUserId: () => currentUserId,
    };
})();
