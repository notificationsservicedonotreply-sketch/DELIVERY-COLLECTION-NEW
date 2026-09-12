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
 *                                outbox (including any File/photo fields --
 *                                stored as real Blobs, IndexedDB handles
 *                                those natively) and returns {queued:true}
 *                                instead of throwing, so calling code can
 *                                show "saved offline, will sync" instead of
 *                                an error.
 *   getState()                - {online, checking, pendingCount, lastError,
 *                                forcedOffline}
 *   setForcedOffline(bool)    - manual override: true puts the app in
 *                                offline mode on purpose (no network calls
 *                                at all) even with a real connection; false
 *                                restores real connectivity immediately
 *   onStatusChange(fn)        - subscribe to state changes
 *   flushOutbox()             - replay queued writes now (called
 *                                automatically on reconnect too)
 *   retryOutboxItem(id) / discardOutboxItem(id)
 *   clearAll()                - wipes this user's local data (called on
 *                                logout and on user-switch detection)
 *
 *   bootstrap({force})        - fetches Ajax/ajax_offline_bootstrap.php and
 *                                mirrors Customers/InvoiceList/TripInvoice/
 *                                TriplistAssign (plus empty FileAttachment/
 *                                CollectionSyntax* stores) into IndexedDB.
 *                                Called once after landing on the Dashboard
 *                                post-login, then kept fresh automatically
 *                                from here on -- checkConnectivity() below
 *                                re-runs it on every offline->online
 *                                transition and every BOOTSTRAP_REFRESH_MS
 *                                while continuously online (e.g. a new stop
 *                                added to TriplistAssign mid-shift), so a
 *                                rider doesn't have to revisit the
 *                                Dashboard or tap "Sync now" for a newly
 *                                assigned customer to show up offline. Safe
 *                                to call again any time while online too.
 *   getBootstrapMeta()         - {generatedAt} of the last successful
 *                                bootstrap, or null if none yet.
 *   searchCustomersOffline(query, module) - same matching behaviour as the
 *                                server's "customers" search action, run
 *                                entirely against the cached tables.
 *   getCustomerOffline(code)
 *   getAssignedDeliveryInvoicesOffline(userId, customerCode)
 *   getTripsAssignedOffline(userId)
 *   getDeliveryRadiusOffline()  - Delivery Portal's GPS radius (meters),
 *                                as of the last bootstrap.
 *   getIsJkasRiderOffline()     - this rider's UserList.SType === 'JKAS',
 *                                as of the last bootstrap.
 *   deliveryRequiresCollectionOffline(customerCode) - mirrors
 *                                deliveryRequiresCollection().
 *   searchCollectionInvoicesOffline(customerCode, query) - mirrors the
 *                                "collection_invoices" action, run
 *                                entirely against the cached InvoiceList.
 *
 * Every page that wants offline behaviour opts in by calling
 * mars.offline.fetchJSON / .submit instead of raw fetch(). Pages that
 * haven't been converted yet keep working exactly as before -- this file
 * changes nothing about how existing fetch() calls behave.
 */
(function () {
    'use strict';

    const PING_URL_SUFFIX = 'Ajax/ajax_session_ping.php';
    const BOOTSTRAP_URL_SUFFIX = 'Ajax/ajax_offline_bootstrap.php';
    const PING_INTERVAL_MS = 20000;
    // Keeps the offline snapshot (Customers/InvoiceList/TripInvoice/
    // TriplistAssign) from silently going stale during a long shift spent
    // continuously online -- e.g. a rider gets a new stop added to their
    // TriplistAssign mid-day without ever actually losing connection, so
    // the offline->online-transition refresh in checkConnectivity() below
    // would never fire for them. This re-runs bootstrap() on a plain timer
    // instead, independent of any connectivity transition.
    const BOOTSTRAP_REFRESH_MS = 5 * 60 * 1000;
    const FETCH_TIMEOUT_MS = 8000;
    const MAX_AUTO_RETRIES = 5;
    // v2 adds the business-data stores (customers, invoiceList, tripInvoice,
    // triplistAssign, fileAttachment, collectionSyntax*) on top of v1's
    // generic kv/outbox stores.
    const DB_VERSION = 2;

    const prefix = window.PWA_BASE_PREFIX || '';
    const pingUrl = prefix + PING_URL_SUFFIX;
    const bootstrapUrl = prefix + BOOTSTRAP_URL_SUFFIX;

    // Table name -> {store, keyPath}. keyPath 'null' means "no natural
    // unique key in what the server returns" -> use an IndexedDB
    // auto-incrementing key instead.
    const BOOTSTRAP_TABLES = {
        customers: { store: 'customers', keyPath: 'CustomerID' },
        invoiceList: { store: 'invoiceList', keyPath: 'REFID' },
        tripInvoice: { store: 'tripInvoice', keyPath: 'ID' },
        triplistAssign: { store: 'triplistAssign', keyPath: null },
        fileAttachment: { store: 'fileAttachment', keyPath: null },
        collectionSyntaxCategory: { store: 'collectionSyntaxCategory', keyPath: null },
        collectionSyntaxDtl: { store: 'collectionSyntaxDtl', keyPath: null },
        collectionSyntaxHdr: { store: 'collectionSyntaxHdr', keyPath: null },
        collectionSyntaxInvDtl: { store: 'collectionSyntaxInvDtl', keyPath: null },
    };

    // The current user id is rendered server-side into every page (see
    // header.php). 'guest' scopes local data for the logged-out (login)
    // page, which never stores anything sensitive anyway.
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
            req.onupgradeneeded = (event) => {
                const db = req.result;
                if (!db.objectStoreNames.contains('kv')) {
                    db.createObjectStore('kv', { keyPath: 'key' });
                }
                if (!db.objectStoreNames.contains('outbox')) {
                    const store = db.createObjectStore('outbox', { keyPath: 'id', autoIncrement: true });
                    store.createIndex('createdAt', 'createdAt');
                }
                // Business-data mirrors (added in v2). Upgrading from v1
                // only needs these created -- bootstrap() then fills them
                // the first time the app calls it, same as a brand-new DB.
                Object.values(BOOTSTRAP_TABLES).forEach(({ store: storeName, keyPath }) => {
                    if (db.objectStoreNames.contains(storeName)) return;
                    if (keyPath) {
                        db.createObjectStore(storeName, { keyPath });
                    } else {
                        db.createObjectStore(storeName, { autoIncrement: true });
                    }
                });
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
    // Business-data mirrors (customers, invoiceList, tripInvoice,
    // triplistAssign, fileAttachment, collectionSyntax*)
    // ------------------------------------------------------------------

    /** Replaces the entire contents of one store with `rows` -- a full
     *  mirror, not a merge, since the server bundle is always the
     *  authoritative full list for that user/DB scope. */
    async function replaceStore(storeName, rows) {
        const db = await openDatabase();
        await new Promise((resolve, reject) => {
            const transaction = db.transaction(storeName, 'readwrite');
            const store = transaction.objectStore(storeName);
            store.clear();
            (rows || []).forEach((row) => store.put(row));
            transaction.oncomplete = () => resolve();
            transaction.onerror = () => reject(transaction.error);
        });
    }

    async function getAllFromStore(storeName) {
        try {
            const store = await tx(storeName, 'readonly');
            return await idbRequest(store.getAll());
        } catch (e) {
            return [];
        }
    }

    // Friendly groupings of BOOTSTRAP_TABLES for progress reporting --
    // riders don't think in table names, they think in "Customers",
    // "Invoices", etc. Order here is also the order stores are filled in,
    // so progress always moves forward category by category rather than
    // jumping around.
    const BOOTSTRAP_PROGRESS_GROUPS = [
        { label: 'Customers', tables: ['customers'] },
        { label: 'Invoices', tables: ['invoiceList'] },
        { label: 'Trip assignments', tables: ['triplistAssign'] },
        { label: 'Deliveries', tables: ['tripInvoice'] },
        { label: 'Collections', tables: ['fileAttachment', 'collectionSyntaxCategory', 'collectionSyntaxDtl', 'collectionSyntaxHdr', 'collectionSyntaxInvDtl'] },
    ];
    const bootstrapProgressListeners = new Set();
    function emitBootstrapProgress(detail) {
        bootstrapProgressListeners.forEach((fn) => { try { fn(detail); } catch (e) { /* listener's problem, not ours */ } });
    }

    /**
     * Fetches the login-scoped data bundle (Customers ⋈ InvoiceList,
     * InvoiceList, TripInvoice ⋈ TriplistAssign, TriplistAssign, plus empty
     * FileAttachment/CollectionSyntax* stores) and mirrors it into
     * IndexedDB. Meant to run once right after the Dashboard loads
     * post-login (see dashboard.js), but safe to call again any time while
     * online -- e.g. from a manual "Refresh offline data" action -- since
     * it always fully replaces each store rather than appending.
     *
     * No-ops (resolves false) when offline instead of throwing, so callers
     * can just "fire and forget" this on page load.
     */
    async function bootstrap(opts) {
        opts = opts || {};
        if ((!state.online) && !opts.force) return false;

        let response;
        try {
            response = await fetchWithTimeout(bootstrapUrl, { credentials: 'same-origin', cache: 'no-store' });
        } catch (e) {
            return false; // offline -- whatever was cached from last time stays as-is
        }
        if (!response.ok) return false;
        const json = await response.json();
        if (!json || !json.success || !json.tables) return false;

        emitBootstrapProgress({ label: 'Starting…', index: 0, total: BOOTSTRAP_PROGRESS_GROUPS.length, percent: 0, done: false });
        for (let i = 0; i < BOOTSTRAP_PROGRESS_GROUPS.length; i++) {
            const group = BOOTSTRAP_PROGRESS_GROUPS[i];
            for (const tableKey of group.tables) {
                const config = BOOTSTRAP_TABLES[tableKey];
                const rows = json.tables[tableKey] || [];
                await replaceStore(config.store, rows);
            }
            emitBootstrapProgress({
                label: group.label,
                index: i + 1,
                total: BOOTSTRAP_PROGRESS_GROUPS.length,
                percent: Math.round(((i + 1) / BOOTSTRAP_PROGRESS_GROUPS.length) * 100),
                done: false,
            });
        }
        // Not a table -- a handful of settings (GPS radii, JKAS/location-lock
        // flags, unlocked-customer list) the offline customer view needs to
        // replicate the same proximity/access gates the server enforces
        // online. Stored in the same small key/value cache as everything
        // else non-tabular.
        await cachePut('offline_settings', json.tables.settings || {});
        await cachePut('offline_bootstrap_meta', { generatedAt: json.generatedAt || Date.now() });
        lastBootstrapAt = Date.now();
        emitBootstrapProgress({ label: 'Offline data ready', index: BOOTSTRAP_PROGRESS_GROUPS.length, total: BOOTSTRAP_PROGRESS_GROUPS.length, percent: 100, done: true });
        return true;
    }

    async function getBootstrapMeta() {
        const cached = await cacheGet('offline_bootstrap_meta');
        return cached ? cached.data : null;
    }

    /** Delivery Portal's GPS validation radius (meters), as of the last
     *  bootstrap. Falls back to the same safe default the server uses
     *  (SystemSettingsRepository::DEFAULT_RADIUS_METERS) if nothing has
     *  been cached yet. */
    async function getDeliveryRadiusOffline() {
        const cached = await cacheGet('offline_settings');
        const radius = Number(cached?.data?.deliveryRadius);
        return radius > 0 ? radius : 100;
    }

    /** Whether the signed-in rider's UserList.SType is 'JKAS', as of the
     *  last bootstrap -- one half of deliveryRequiresCollection()'s
     *  decision (the other half is the customer's SellingType, already on
     *  the cached customer record). */
    async function getIsJkasRiderOffline() {
        const cached = await cacheGet('offline_settings');
        return !!cached?.data?.isJkasRider;
    }

    /** Collection Portal's own GPS validation radius (meters), as of the
     *  last bootstrap -- separate setting from the Delivery Portal's radius
     *  above (see DeliveryCollectionRepository::radius() vs
     *  deliveryRadius()). Same safe-default fallback as getDeliveryRadiusOffline(). */
    async function getCollectionRadiusOffline() {
        const cached = await cacheGet('offline_settings');
        const radius = Number(cached?.data?.collectionRadius);
        return radius > 0 ? radius : 100;
    }

    /** Signed-in user's UserList.LocationLock, as of the last bootstrap --
     *  when false, collectionAccess() (and its offline counterpart below)
     *  grants access with no GPS check at all, same as online. */
    async function getLocationLockOffline() {
        const cached = await cacheGet('offline_settings');
        return !!cached?.data?.locationLock;
    }

    /** Whether this customer is in CustomerUnlockList, as of the last
     *  bootstrap -- mirrors customerUnlocked(), the other override
     *  collectionAccess() checks before falling back to a GPS distance
     *  check. */
    async function isCustomerUnlockedOffline(customerCode) {
        const cached = await cacheGet('offline_settings');
        const unlocked = cached?.data?.unlockedCustomers;
        return Array.isArray(unlocked) && unlocked.map(String).includes(String(customerCode));
    }

    /** Haversine great-circle distance in meters -- kept local to this file
     *  (rather than importing delivery-collection.js's identical
     *  haversineMeters()) since offline-core.js is meant to stand alone as
     *  the data layer, independent of which page/module happens to be
     *  loaded alongside it. */
    function haversineMetersOffline(lat1, lng1, lat2, lng2) {
        const toRad = Math.PI / 180;
        const h = Math.sin((lat2 - lat1) * toRad / 2) ** 2
            + Math.cos(lat1 * toRad) * Math.cos(lat2 * toRad) * Math.sin((lng2 - lng1) * toRad / 2) ** 2;
        return 2 * 6371000 * Math.atan2(Math.sqrt(h), Math.sqrt(1 - h));
    }

    /** Offline counterpart of DeliveryCollectionRepository::collectionAccess()
     *  -- same precedence: location lock disabled for the system, then this
     *  customer individually unlocked, then an on-site GPS distance check
     *  against the cached Collection Portal radius. Returned in the same
     *  {allowed, reason, distance, override} shape the server uses so
     *  callers don't need to branch on offline vs online. */
    async function collectionAccessOffline(customerCode, latitude, longitude) {
        const locationLock = await getLocationLockOffline();
        if (!locationLock) {
            return { allowed: true, reason: 'Location lock is disabled. You can view this customer’s collection details.', distance: null, override: 'location_lock_disabled' };
        }

        const unlocked = await isCustomerUnlockedOffline(customerCode);
        if (unlocked) {
            return { allowed: true, reason: 'This customer is unlocked for collection viewing.', distance: null, override: 'customer_unlocked' };
        }

        if (!Number.isFinite(Number(latitude)) || !Number.isFinite(Number(longitude))) {
            return { allowed: false, reason: 'Salesman GPS location is required.', distance: null, override: null };
        }

        const customer = await getCustomerOffline(customerCode);
        if (!customer || !Number.isFinite(Number(customer.Latitude)) || !Number.isFinite(Number(customer.Longitude))) {
            return { allowed: false, reason: 'Customer coordinates are not configured.', distance: null, override: null };
        }

        const radius = await getCollectionRadiusOffline();
        const distance = haversineMetersOffline(Number(latitude), Number(longitude), Number(customer.Latitude), Number(customer.Longitude));
        const allowed = distance <= radius;
        return {
            allowed,
            reason: allowed ? 'Salesman is within the allowed customer radius.' : 'Customer is out of range.',
            distance,
            override: null,
        };
    }

    /** Offline counterpart of agingReceivables(): same Net-30-assumption
     *  bucketing, run against the cached InvoiceList instead of a live
     *  query. Returned in the same {items, totals} shape the server uses. */
    async function agingReceivablesOffline(customerCode) {
        const invoices = await getAllFromStore('invoiceList');
        const rows = invoices.filter((row) => String(row.CUSTOMERID) === String(customerCode) && Number(row.BALANCE) > 0);

        const today = new Date();
        today.setHours(0, 0, 0, 0);
        const buckets = { current: 0, past30: 0, past60: 0, past90: 0, past120: 0, past150: 0 };
        const items = [];
        const fmt = (d) => `${String(d.getMonth() + 1).padStart(2, '0')}/${String(d.getDate()).padStart(2, '0')}/${d.getFullYear()}`;

        rows
            .sort((a, b) => (String(a.INVOICEDATE).localeCompare(String(b.INVOICEDATE)) || String(a.REFID).localeCompare(String(b.REFID))))
            .forEach((row) => {
                const invoiceDate = new Date(row.INVOICEDATE);
                const deliveryDateRaw = row.DELIVERYDATE;
                const deliveryDate = deliveryDateRaw && String(deliveryDateRaw).trim() !== '' ? new Date(deliveryDateRaw) : null;
                const effectiveDate = (deliveryDate && deliveryDate > invoiceDate) ? deliveryDate : invoiceDate;

                const dueDate = new Date(invoiceDate);
                dueDate.setDate(dueDate.getDate() + 30);
                const daysPastDue = Math.floor((today - dueDate) / 86400000);
                const balance = Number(row.BALANCE);

                let bucket;
                if (daysPastDue <= 0) bucket = 'current';
                else if (daysPastDue <= 30) bucket = 'past30';
                else if (daysPastDue <= 60) bucket = 'past60';
                else if (daysPastDue <= 90) bucket = 'past90';
                else if (daysPastDue <= 120) bucket = 'past120';
                else bucket = 'past150';

                buckets[bucket] += balance;
                items.push({
                    refid: String(row.REFID),
                    date: fmt(effectiveDate),
                    due_date: fmt(dueDate),
                    salesman: String(row.SALESMANID || ''),
                    balance,
                    bucket,
                });
            });

        const total = Object.values(buckets).reduce((sum, v) => sum + v, 0);
        return { items, totals: { ...buckets, total } };
    }

    /** Mirrors deliveryRequiresCollection(): true when either the rider is
     *  JKAS or the customer's SellingType is null/blank. */
    async function deliveryRequiresCollectionOffline(customerCode) {
        const [isJkas, customer] = await Promise.all([
            getIsJkasRiderOffline(),
            getCustomerOffline(customerCode),
        ]);
        const sellingType = customer?.SellingType;
        const sellingTypeIsNull = sellingType === null || sellingType === undefined || String(sellingType).trim() === '';
        return isJkas || sellingTypeIsNull;
    }

    /** Same "REFID contains query, positive balance" matching the server's
     *  collection_invoices action applies (minus the "already collected"
     *  cross-check against CollectionSyntaxInvDtl, which isn't meaningfully
     *  available offline -- see the Delivery Portal's offline Collection
     *  modal for how that limitation is surfaced to the rider). Returned in
     *  the same shape the server uses so findInvoice()/searchInvoices() in
     *  delivery-collection.js work against it unmodified. */
    async function searchCollectionInvoicesOffline(customerCode, query) {
        query = String(query || '').trim().toLowerCase();
        if (query.length < 2) return { items: [], already_collected: false };

        const invoices = await getAllFromStore('invoiceList');
        const items = invoices.filter((row) => (
            String(row.CUSTOMERID) === String(customerCode)
            && Number(row.BALANCE) > 0
            && String(row.REFID || '').toLowerCase().includes(query)
        )).map((row) => ({
            InvoiceNo: row.REFID,
            Balance: row.BALANCE,
            DeliveryDate: row.DELIVERYDATE,
            DEPARTMENT: row.DEPARTMENT,
            AlreadyCollected: 0,
        }));

        return { items, already_collected: false };
    }

    /** Offline counterpart of collection_invoices_batch: looks up an exact
     *  set of invoice numbers (typically the ones just confirmed delivered
     *  at this stop) in the cached InvoiceList, for the Collection modal's
     *  auto-add-on-open step. */
    async function searchCollectionInvoicesBatchOffline(customerCode, invoiceNumbers) {
        const wanted = new Set((invoiceNumbers || []).map((no) => String(no)));
        const invoices = await getAllFromStore('invoiceList');
        const byRefId = new Map(
            invoices
                .filter((row) => String(row.CUSTOMERID) === String(customerCode) && Number(row.BALANCE) > 0)
                .map((row) => [String(row.REFID), row])
        );
        const items = [];
        const notFound = [];
        wanted.forEach((invoiceNo) => {
            const row = byRefId.get(invoiceNo);
            if (row) {
                items.push({ InvoiceNo: row.REFID, Balance: row.BALANCE, DeliveryDate: row.DELIVERYDATE, DEPARTMENT: row.DEPARTMENT });
            } else {
                notFound.push(invoiceNo);
            }
        });
        return { items, not_found: notFound, already_collected: [] };
    }

    /** Same two-pass "prefix match first, then contains" ranking as the
     *  server's searchCustomers(), run against the cached customers table.
     *  `module === 'delivery'` additionally restricts to customers with a
     *  pending (undelivered, unresolved) stop assigned to this user, same
     *  as searchAssignedDeliveryCustomers(). */
    async function searchCustomersOffline(query, module) {
        query = String(query || '').trim();
        if (query.length < 2) return [];
        const needle = query.toLowerCase();

        let customers = await getAllFromStore('customers');

        if (module === 'delivery') {
            const [tripInvoices, assignments] = await Promise.all([
                getAllFromStore('tripInvoice'),
                getAllFromStore('triplistAssign'),
            ]);
            const activeTripIds = new Set(
                assignments
                    .filter((a) => String(a.USERID) === String(currentUserId) && Number(a.Status) !== 0)
                    .map((a) => a.TRIPID)
            );
            const pendingCustomerIds = new Set(
                tripInvoices
                    .filter((i) => activeTripIds.has(i.TripID) && !i.DeliveredDate && !i.NotDeliveredReason)
                    .map((i) => i.CustomerID)
            );
            customers = customers.filter((c) => pendingCustomerIds.has(c.CustomerID));
        }

        const prefixMatches = [];
        const containsMatches = [];
        customers.forEach((c) => {
            const id = String(c.CustomerID || '').toLowerCase();
            const name = String(c.CustomerName || '').toLowerCase();
            if (id.startsWith(needle) || name.startsWith(needle)) {
                prefixMatches.push(c);
            } else if (id.includes(needle) || name.includes(needle)) {
                containsMatches.push(c);
            }
        });
        const sortByName = (a, b) => String(a.CustomerName).localeCompare(String(b.CustomerName));
        prefixMatches.sort(sortByName);
        containsMatches.sort(sortByName);

        return [...prefixMatches, ...containsMatches]
            .slice(0, 20)
            .map((c) => ({ code: c.CustomerID, name: c.CustomerName }));
    }

    async function getCustomerOffline(code) {
        try {
            const store = await tx('customers', 'readonly');
            const row = await idbRequest(store.get(String(code)));
            return row || null;
        } catch (e) {
            return null;
        }
    }

    /** Mirrors assignedDeliveryInvoices(): pending (undelivered, unresolved)
     *  TripInvoice rows for this customer, across every trip assigned to
     *  `userId`. */
    async function getAssignedDeliveryInvoicesOffline(userId, customerCode) {
        const [tripInvoices, assignments] = await Promise.all([
            getAllFromStore('tripInvoice'),
            getAllFromStore('triplistAssign'),
        ]);
        const activeTripIds = new Set(
            assignments.filter((a) => String(a.USERID) === String(userId) && Number(a.Status) !== 0).map((a) => a.TRIPID)
        );
        return tripInvoices
            .filter((i) => activeTripIds.has(i.TripID) && String(i.CustomerID) === String(customerCode) && !i.DeliveredDate && !i.NotDeliveredReason)
            .sort((a, b) => (a.TripID === b.TripID ? String(a.InvoiceNo).localeCompare(String(b.InvoiceNo)) : String(a.TripID).localeCompare(String(b.TripID))))
            .map((i) => ({ TripID: i.TripID, InvoiceNo: i.InvoiceNo, DrNo: i.DrNo, CustomerID: i.CustomerID, TotalCrtns: i.TotalCrtns }));
    }

    /** Mirrors assignedTrips(): distinct, still-active trip IDs for this user. */
    async function getTripsAssignedOffline(userId) {
        const assignments = await getAllFromStore('triplistAssign');
        const tripIds = assignments
            .filter((a) => String(a.USERID) === String(userId) && Number(a.Status) !== 0)
            .map((a) => String(a.TRIPID).trim())
            .filter(Boolean);
        return [...new Set(tripIds)].sort();
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

    // A File is a Blob with a name/type/lastModified -- IndexedDB's
    // structured-clone storage handles Blobs natively (no base64 encoding
    // needed), so a queued attachment (store photo, deposit slip, payment/
    // split proof) survives a tab close and page reload just like any other
    // field. Only the name/type/lastModified need capturing separately,
    // since re-wrapping a Blob back into a File on replay needs them.
    function formDataToFields(formData) {
        const fields = {};
        for (const [key, value] of formData.entries()) {
            if (value instanceof File) {
                fields[key] = { __file: true, blob: value, name: value.name, type: value.type, lastModified: value.lastModified };
            } else {
                fields[key] = value;
            }
        }
        return fields;
    }

    function fieldsToFormData(fields) {
        const fd = new FormData();
        Object.keys(fields).forEach((k) => {
            const v = fields[k];
            if (v && typeof v === 'object' && v.__file) {
                fd.append(k, new File([v.blob], v.name, { type: v.type, lastModified: v.lastModified }));
            } else {
                fd.append(k, v);
            }
        });
        return fd;
    }

    // ------------------------------------------------------------------
    // Connectivity state
    // ------------------------------------------------------------------
    // Manual "force offline" override -- lets a rider (or a tester) put the
    // app into offline mode on purpose even with a perfectly good
    // connection, so every read comes from IndexedDB and every save queues
    // in the outbox exactly as if the network had actually dropped.
    // Persisted per-user (same key pattern as the stale-user IndexedDB
    // guard above) so it survives a page reload/refresh but never leaks
    // from one account to another on a shared device.
    const FORCE_OFFLINE_KEY = 'mars_force_offline_' + currentUserId;
    function readForcedOffline() {
        return localStorage.getItem(FORCE_OFFLINE_KEY) === '1';
    }

    const state = {
        online: navigator.onLine,
        checking: false,
        pendingCount: 0,
        lastError: null,
        lastCheckedAt: null,
        forcedOffline: readForcedOffline(),
    };
    // Real network reachability, tracked separately from state.online --
    // state.online (what every caller in the app actually checks) is the
    // effective value: real AND not manually forced off. Kept so turning
    // the override back off can restore the true status immediately
    // instead of waiting for the next scheduled ping.
    let realOnline = navigator.onLine;
    function recomputeEffectiveOnline() {
        state.online = !!realOnline && !state.forcedOffline;
    }
    recomputeEffectiveOnline();
    const listeners = new Set();
    // In-memory only (reset on page load); combined with BOOTSTRAP_REFRESH_MS
    // in checkConnectivity() below to re-run bootstrap() periodically while
    // continuously online, not just right after reconnecting.
    let lastBootstrapAt = 0;

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
        // Forced offline: skip the real ping entirely -- no network traffic
        // at all while the override is on, which is the whole point of it.
        // realOnline is left as whatever it last was, so the true state is
        // known immediately (no false "just reconnected" bootstrap/flush)
        // the moment the override is switched back off.
        if (state.forcedOffline) {
            recomputeEffectiveOnline();
            state.checking = false;
            state.lastCheckedAt = Date.now();
            state.pendingCount = await outboxCount();
            emit();
            return state.online;
        }

        state.checking = true;
        emit();
        const wasOnline = state.online;
        try {
            const res = await fetchWithTimeout(pingUrl, { credentials: 'same-origin', cache: 'no-store' });
            if (!res.ok) throw new Error('ping HTTP ' + res.status);
            const data = await res.json();
            if (data.csrfToken) lastCsrfToken = data.csrfToken;
            realOnline = true;
            state.lastError = data.loggedIn ? null : 'session-expired';
        } catch (e) {
            realOnline = false;
        }
        recomputeEffectiveOnline();
        state.checking = false;
        state.lastCheckedAt = Date.now();
        state.pendingCount = await outboxCount();
        emit();

        if (state.online && state.pendingCount > 0) {
            flushOutbox();
        }
        // Just came back online -- refresh the offline data snapshot too,
        // not only the outbox, so cached lists don't go stale indefinitely
        // across a long reconnect gap.
        if (state.online && !wasOnline) {
            bootstrap();
        } else if (state.online && Date.now() - lastBootstrapAt > BOOTSTRAP_REFRESH_MS) {
            // Also refresh periodically while continuously online -- e.g. a
            // rider assigned a new stop mid-shift without ever actually
            // losing connection would otherwise never get that stop mirrored
            // into IndexedDB until their next login.
            bootstrap();
        }
        return state.online;
    }

    /** Manual offline-mode override. Flip on and the app behaves exactly as
     *  if the network had dropped -- reads come from IndexedDB, saves queue
     *  in the outbox -- even with a live connection. Flip back off and it
     *  re-checks real connectivity immediately, then (if actually online)
     *  flushes anything that queued while forced off and refreshes the
     *  bootstrap snapshot, the same recovery path a real reconnect gets. */
    async function setForcedOffline(value) {
        const forced = !!value;
        state.forcedOffline = forced;
        if (forced) {
            localStorage.setItem(FORCE_OFFLINE_KEY, '1');
        } else {
            localStorage.removeItem(FORCE_OFFLINE_KEY);
        }
        recomputeEffectiveOnline();
        emit();
        await checkConnectivity();
    }

    async function refreshPendingCount() {
        state.pendingCount = await outboxCount();
        emit();
    }

    window.addEventListener('online', () => checkConnectivity());
    window.addEventListener('offline', () => { realOnline = false; recomputeEffectiveOnline(); emit(); });
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
            // Queue it (attachments included) and let the sync engine
            // replay it later.
            const fields = formDataToFields(formData);
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
            // A successful sync run means we're genuinely online -- take the
            // opportunity to refresh the offline data snapshot too.
            if ((await outboxCount()) === 0) bootstrap();
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
        setForcedOffline,
        isForcedOffline: () => state.forcedOffline,
        getUserId: () => currentUserId,
        bootstrap,
        getBootstrapMeta,
        onBootstrapProgress: (fn) => { bootstrapProgressListeners.add(fn); return () => bootstrapProgressListeners.delete(fn); },
        searchCustomersOffline,
        getCustomerOffline,
        getAssignedDeliveryInvoicesOffline,
        getTripsAssignedOffline,
        getDeliveryRadiusOffline,
        getIsJkasRiderOffline,
        deliveryRequiresCollectionOffline,
        searchCollectionInvoicesOffline,
        searchCollectionInvoicesBatchOffline,
        getCollectionRadiusOffline,
        getLocationLockOffline,
        isCustomerUnlockedOffline,
        collectionAccessOffline,
        agingReceivablesOffline,
    };
})();
