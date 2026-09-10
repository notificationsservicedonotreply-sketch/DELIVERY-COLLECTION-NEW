(function(){
'use strict';

// Shared page state and helpers.
const endpoint = '../Ajax/ajax_delivery_collection.php';
const id = (elementId) => document.getElementById(elementId);

let gps = window.collectionLocationRequired === false ? { latitude: 0, longitude: 0 } : null;
let searchTimer;
let selectedSearchCustomer = null;
let collectionInRange = false;
let collectionAccessGranted = false;
let locationConfirmedForDelivery = false;
let mapFitTarget = null;
// Deposit Slip modal: which row's button triggered it, so a successful (or
// offline-queued) upload can mark that same row as uploaded without a page
// reload. Module-scoped (not local to the DOMContentLoaded handler below)
// so markDepositSlipUploaded() can reach it too.
let currentDepositSlipButton = null;
// Delivery Portal only: once a required Collection has been saved for this
// customer visit, further "Confirm delivery" clicks don't need to open the
// Collection modal again.
let collectionCompletedForDelivery = false;
// Delivery Portal only: the "Confirm delivery" button that triggered the
// required Collection modal, so it can be completed automatically once the
// collection has been saved.
let pendingDeliveryAfterCollection = null;
// Delivery Portal, "requires collection" customers only: every invoice is
// resolved locally first ("temporary only" -- nothing saved to the server
// yet). Keyed by `${tripId}::${invoiceNo}`, each entry holds what's needed
// to replay that resolution server-side once the Collection is saved.
const pendingDeliveryResolutions = new Map();
// True once every invoice for this stop has been resolved locally and the
// Collection modal has been opened for the final combined submit.
let finalSubmitMode = false;

// Resolved on demand (not once at script-parse time) so it never depends on
// whether this script tag happens to load before or after the #moduleName
// hidden input exists in the DOM.
function moduleContext() {
    // Prefer detecting the module from elements that only exist on their own
    // portal page (delivery_portal.php has #viewDeliveryDetails, collection_portal.php
    // has #viewCollectionDetails). This can't be fooled by a stale cached script or a
    // duplicate #moduleName element elsewhere on the page -- only the real markup counts.
    const module = document.getElementById('viewDeliveryDetails')
        ? 'delivery'
        : document.getElementById('viewCollectionDetails')
            ? 'collection'
            : (id('moduleName')?.value === 'delivery' ? 'delivery' : 'collection');
    return {
        module,
        rangeNoticeId: module === 'delivery' ? 'deliveryRangeNotice' : 'collectionRangeNotice',
        detailsPanelId: module === 'delivery' ? 'deliveryDetails' : 'collectionDetails',
        proceedButtonId: module === 'delivery' ? 'viewDeliveryDetails' : 'viewCollectionDetails',
    };
}

// Actions whose file-carrying FormData can be safely queued in the offline
// outbox and replayed later -- the server treats each one as a single
// all-or-nothing write, so a queued-then-later-sent copy behaves exactly
// like sending it now would have. Anything not in this list (searches,
// GPS/location verification, invoice lookups) needs a live answer from the
// server to be trustworthy, so it fails clearly instead of guessing.
const OFFLINE_QUEUEABLE_ACTIONS = new Set([
    'complete_delivery',
    'complete_collection',
    'complete_delivery_with_collection',
    'not_delivered',
    'upload_deposit_slip',
]);

async function post(action, data) {
    const body = data instanceof FormData ? data : new URLSearchParams(data || {});
    body.set('action', action);
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
    if (csrfToken) body.set('csrf_token', csrfToken);

    let response;
    try {
        response = await fetch(endpoint, { method: 'POST', body, credentials: 'same-origin' });
    } catch (networkError) {
        // fetch() itself threw -- a genuine connectivity failure (offline,
        // DNS, timeout), not the server rejecting anything.
        return handleOfflineAction(action, body);
    }
    const json = await response.json();

    if (response.status === 401) {
        location.href = '../';
        throw Error('Please sign in again.');
    }

    if (!response.ok || !json.success) {
        throw Error(json.message || 'Request failed.');
    }

    return json;
}

/** Best-effort handling for a post() call that couldn't reach the server at
 *  all. Customer search falls back to the IndexedDB mirror kept up to date
 *  by mars.offline.bootstrap(); saves/uploads that carry a file are queued
 *  in the offline outbox (attachments included) and replayed automatically
 *  once back online. Anything else throws a clear, honest error instead of
 *  guessing at an answer offline can't actually provide. */
async function handleOfflineAction(action, body) {
    if (!window.mars || !window.mars.offline) {
        throw Error("You're offline and offline mode isn't available in this browser.");
    }

    if (action === 'customers') {
        const items = await window.mars.offline.searchCustomersOffline(body.get('q'), body.get('module'));
        return { success: true, items, offline: true };
    }

    if (action === 'collection_invoices') {
        const result = await window.mars.offline.searchCollectionInvoicesOffline(body.get('customer'), body.get('q'));
        return { success: true, ...result, offline: true };
    }

    if (action === 'collection_invoices_batch') {
        const invoiceNumbers = JSON.parse(body.get('invoice_numbers') || '[]');
        const result = await window.mars.offline.searchCollectionInvoicesBatchOffline(body.get('customer'), invoiceNumbers);
        return { success: true, ...result, offline: true };
    }

    if (OFFLINE_QUEUEABLE_ACTIONS.has(action)) {
        const result = await window.mars.offline.submit(endpoint, body, { description: describeQueuedAction(action, body) });
        if (result.queued) {
            const error = Error("You're offline. This has been saved on your device and will sync automatically once you're back online.");
            error.offlineQueued = true;
            throw error;
        }
        // submit() only returns queued:false when it actually reached the
        // network after all (a brief connectivity blip) -- re-read the body.
        const json = await result.response.json();
        if (!json.success) throw Error(json.message || 'Request failed.');
        return json;
    }

    throw Error("You're offline. This needs a connection to continue -- please try again once you're back online.");
}

/** Marks the row's deposit-slip button as uploaded immediately, without a
 *  page reload -- used for both a normal upload and one queued offline
 *  (optimistic: the file is safely in the outbox either way). */
function markDepositSlipUploaded() {
    if (!currentDepositSlipButton) return;
    currentDepositSlipButton.classList.remove('btn-gray');
    currentDepositSlipButton.classList.add('btn-green', 'deposit-slip-btn--uploaded');
    currentDepositSlipButton.title = 'Deposit slip already uploaded — tap to view or replace it';
    currentDepositSlipButton.querySelector('i')?.classList.replace('fa-receipt', 'fa-circle-check');
    if (!currentDepositSlipButton.querySelector('.deposit-slip-uploaded-badge')) {
        const uploadedBadge = document.createElement('span');
        uploadedBadge.className = 'deposit-slip-uploaded-badge';
        uploadedBadge.textContent = 'Uploaded';
        currentDepositSlipButton.append(uploadedBadge);
    }
}

function describeQueuedAction(action, body) {
    const customer = body.get('customer') || '';
    const labels = {
        complete_delivery: `Delivery confirmation for ${customer}`,
        complete_collection: `Collection for ${customer}`,
        complete_delivery_with_collection: `Delivery + collection for ${customer}`,
        not_delivered: `Not-delivered report for ${customer}`,
        upload_deposit_slip: `Deposit slip for ${customer}`,
    };
    return labels[action] || (action + ' for ' + customer);
}

function notice(text, type) {
    const box = id('portalMessage');
    if (!box) return;

    const isCollectionException = text === 'This customer is unlocked for collection viewing.'
        || text.includes('Location lock is disabled')
        || (window.collectionLocationRequired === false && text === 'Location access is optional for this customer.');
    if (isCollectionException) {
        const rangeNotice = id(moduleContext().rangeNoticeId);
        if (rangeNotice) {
            rangeNotice.textContent = window.collectionAccessMessage || text;
            rangeNotice.className = 'notice success';
            return;
        }
    }

    box.textContent = text;
    box.className = `notice ${type || 'info'}`;
    box.classList.remove('dc-hidden');
}

function openCustomer() {
    if (!selectedSearchCustomer) {
        return notice('Select a customer from the search results first.', 'error');
    }

    if (isOffline()) {
        openCustomerOffline(selectedSearchCustomer.code, selectedSearchCustomer.name);
        return;
    }

    location.href = `?page=${encodeURIComponent(id('pageToken').value)}&customer=${encodeURIComponent(selectedSearchCustomer.code)}`;
}

function search(query) {
    const list = id('customerResults');
    const hint = id('customerSearchHint');
    clearTimeout(searchTimer);

    if (query.length < 2) {
        list.replaceChildren();
        list.classList.add('dc-hidden');
        hint.textContent = 'Type at least 2 characters to search.';
        selectedSearchCustomer = null;
        return;
    }

    searchTimer = setTimeout(async () => {
        try {
            const data = await post('customers', { q: query, module: moduleContext().module });
            list.replaceChildren(...data.items.map((customer) => customerResult(customer)));

            list.classList.toggle('dc-hidden', !data.items.length);
            hint.textContent = data.items.length
                ? `${data.items.length} matching customer(s). Select one.`
                : 'No matching Customer ID or Customer Name.';

            selectedSearchCustomer = null;
        } catch (error) {
            notice(error.message, 'error');
        }
    }, 250);
}

function customerResult(customer) {
    const result = document.createElement('button');
    result.type = 'button';
    result.className = 'customer-result';
    result.setAttribute('role', 'option');

    const name = document.createElement('strong');
    name.textContent = customer.name;
    const code = document.createElement('span');
    code.textContent = customer.code;
    result.append(name, code);

    result.addEventListener('click', () => {
        selectedSearchCustomer = customer;
        id('customerSearch').value = customer.name;
        id('customerResults').classList.add('dc-hidden');
        id('customerSearchHint').textContent = `${customer.name} selected.`;
    });

    return result;
}

/** True connectivity, per mars.offline's own ping-based check (more
 *  reliable than navigator.onLine alone -- see offline-core.js) when
 *  available, falling back to navigator.onLine otherwise. */
function isOffline() {
    const marsState = window.mars?.offline?.getState?.();
    return marsState ? !marsState.online : !navigator.onLine;
}

/** Small DOM-building helper so renderOfflineCustomerView() and friends
 *  don't need innerHTML + string interpolation (and the manual escaping
 *  that would require) for content that includes customer-supplied data
 *  like names and addresses. */
function h(tag, attrs, children) {
    const node = document.createElement(tag);
    Object.entries(attrs || {}).forEach(([key, value]) => {
        if (value === undefined || value === null || value === false) return;
        if (key === 'class') node.className = value;
        else if (key === 'text') node.textContent = value;
        else node.setAttribute(key, value === true ? '' : value);
    });
    (children || []).forEach((child) => { if (child) node.append(child); });
    return node;
}

function infoBox(label, value) {
    return h('div', { class: 'info-box' }, [
        h('label', { text: label }),
        h('div', { class: 'value', text: value }),
    ]);
}

/** Same as infoBox(), but for when the value itself needs to be a live
 *  element (e.g. a <span id="summaryBalance"> that other shared functions
 *  update directly) rather than static text. */
function infoBoxEl(label, valueEl) {
    return h('div', { class: 'info-box' }, [
        h('label', { text: label }),
        h('div', { class: 'value' }, [valueEl]),
    ]);
}

/** One <tr> of the offline "Invoices for delivery" table -- same fields,
 *  classes and data attributes as the server-rendered version in
 *  delivery/portal.php, so it's wired up by (and looks identical to) the
 *  exact same deliver()/notDelivered()/updateDeliveryButtonStates() code
 *  the online page uses. */
function offlineInvoiceRow(invoice) {
    const photoInput = h('input', {
        type: 'file', class: 'store-photo-input',
        accept: 'image/jpeg,image/png,image/gif,image/webp', capture: 'environment', required: true,
    });
    const confirmBtn = h('button', {
        type: 'button', class: 'btn btn-green confirm-delivery',
        'data-trip-id': invoice.TripID, 'data-invoice-no': invoice.InvoiceNo, disabled: true,
    }, [h('i', { class: 'fa-solid fa-circle-check', 'aria-hidden': 'true' }), document.createTextNode(' Confirm delivery')]);
    const notDeliveredBtn = h('button', {
        type: 'button', class: 'btn btn-red not-delivered-toggle',
        'data-trip-id': invoice.TripID, 'data-invoice-no': invoice.InvoiceNo,
    }, [h('i', { class: 'fa-solid fa-triangle-exclamation', 'aria-hidden': 'true' }), document.createTextNode(' Not received')]);
    const reasonText = h('textarea', {
        class: 'input not-delivered-reason-text', rows: '2', maxlength: '255',
        placeholder: "Why wasn't this delivery received? e.g. store closed, customer not around, refused delivery",
    });
    const cancelBtn = h('button', { type: 'button', class: 'btn btn-gray not-delivered-cancel', text: 'Cancel' });
    const saveReasonBtn = h('button', {
        type: 'button', class: 'btn btn-red not-delivered-submit',
        'data-trip-id': invoice.TripID, 'data-invoice-no': invoice.InvoiceNo, text: 'Save reason',
    });
    const reasonPanel = h('div', { class: 'not-delivered-reason dc-hidden' }, [
        reasonText,
        h('div', { class: 'not-delivered-reason-actions' }, [cancelBtn, saveReasonBtn]),
    ]);

    confirmBtn.addEventListener('click', () => deliver(confirmBtn));
    notDeliveredBtn.addEventListener('click', () => {
        reasonPanel.classList.toggle('dc-hidden');
        if (!reasonPanel.classList.contains('dc-hidden')) reasonText.focus();
    });
    cancelBtn.addEventListener('click', () => { reasonPanel.classList.add('dc-hidden'); reasonText.value = ''; });
    saveReasonBtn.addEventListener('click', () => notDelivered(saveReasonBtn));
    // No need to wire photoInput's 'change' event here -- there's already a
    // document-level delegated listener (see the DOMContentLoaded wiring
    // below) that calls updateDeliveryButtonStates() for any
    // .store-photo-input, including these dynamically-created ones.

    return h('tr', { 'data-delivery-invoice': true }, [
        h('td', { text: invoice.TripID }),
        h('td', { text: invoice.InvoiceNo }),
        h('td', { text: invoice.DrNo || '' }),
        h('td', { text: String(invoice.TotalCrtns ?? '') }),
        h('td', {}, [photoInput]),
        h('td', { class: 'delivery-action-cell' }, [confirmBtn, notDeliveredBtn, reasonPanel]),
    ]);
}

function closeOfflineCustomerView() {
    const container = id('offlineCustomerView');
    container?.classList.add('dc-hidden');
    container?.replaceChildren();
    document.querySelector('.customer-search-card')?.classList.remove('dc-hidden');
    id('portalMessage')?.classList.remove('dc-hidden');
    if (id('customerSearch')) id('customerSearch').value = '';
    selectedSearchCustomer = null;
}

/**
 * Offline stand-in for a full "?page=Delivery-Portal&customer=X" page
 * navigation: builds the same Customer details + Invoices for delivery
 * sections the server would render, from what's already mirrored into
 * IndexedDB by offline-core.js's bootstrap(), instead of requiring a live
 * request.
 *
 * Always treats the stop as delivery-only (window.deliveryRequiresCollection
 * stays false): whether a Collection is actually required depends on
 * server-side rider/customer data this view doesn't have, and collecting
 * payment needs its own live GPS/server round-trip (collection_access)
 * regardless -- so that stays a "Resume collection" task for once the
 * rider is back online, same as an interrupted Collection already is today.
 *
 * The GPS proximity gate itself (confirm_location online) is replicated
 * client-side using the same haversineMeters() formula used for route
 * suggestions below, the customer's cached coordinates, and the cached
 * Delivery Portal radius -- so a rider genuinely has to be on-site to
 * unlock these buttons, offline or not. The coordinates recorded at that
 * moment still travel with the queued submission and get re-validated by
 * the server once it syncs.
 */
async function openCustomerOffline(code, name) {
    const container = id('offlineCustomerView');
    if (!container || !window.mars?.offline) return;

    const customer = await window.mars.offline.getCustomerOffline(code);
    if (!customer) {
        notice(`${name || code} isn't on your cached trip list for this device yet. It'll be included the next time you sync while online (Dashboard, or "Sync now" in the offline badge).`, 'error');
        return;
    }

    const userId = window.MARS_USER_ID;
    const [invoices, radius, requiresCollection] = await Promise.all([
        window.mars.offline.getAssignedDeliveryInvoicesOffline(userId, code),
        window.mars.offline.getDeliveryRadiusOffline(),
        window.mars.offline.deliveryRequiresCollectionOffline(code),
    ]);

    // Hide the search UI and route modal, same as a real navigation would.
    id('customerResults')?.classList.add('dc-hidden');
    id('portalMessage')?.classList.add('dc-hidden');
    document.querySelector('.customer-search-card')?.classList.add('dc-hidden');
    id('routeModal')?.classList.remove('active');

    window.deliveryRequiresCollection = requiresCollection;
    locationConfirmedForDelivery = false;
    collectionInRange = false;
    collectionAccessGranted = false;
    gps = null;

    const address = [customer.Street, customer.Barangay, customer.Municipality, customer.Province].filter(Boolean).join(', ');
    const lat = Number(customer.Latitude);
    const lng = Number(customer.Longitude);

    const rangeNotice = h('div', { class: 'notice info', text: 'Getting your location…' });
    const invoicesSection = h('div', { class: 'dc-hidden' });
    const proceedBtn = h('button', { type: 'button', class: 'btn btn-green dc-hidden' }, [
        h('i', { class: 'fa-solid fa-truck', 'aria-hidden': 'true' }), document.createTextNode(' Proceed to delivery'),
    ]);
    proceedBtn.addEventListener('click', () => {
        locationConfirmedForDelivery = true;
        // Collecting payment needs the same on-site GPS proximity that was
        // just verified to show this button -- so unlock it here too,
        // rather than asking the rider to re-confirm the same location a
        // second time a moment later. This mirrors how the online page's
        // confirm_location and collection_access checks both key off the
        // same on-site GPS reading for a single visit to one stop.
        collectionInRange = true;
        collectionAccessGranted = requiresCollection;
        invoicesSection.classList.remove('dc-hidden');
        proceedBtn.classList.add('dc-hidden');
        updateDeliveryButtonStates();
        notice('Location confirmed. Attach a store photo, then confirm each invoice one at a time.', 'success');
    });
    const backBtn = h('button', { type: 'button', class: 'btn btn-gray' }, [
        h('i', { class: 'fa-solid fa-arrow-left', 'aria-hidden': 'true' }), document.createTextNode(' Back to search'),
    ]);
    backBtn.addEventListener('click', closeOfflineCustomerView);

    const customerCard = h('div', { class: 'card customer-card' }, [
        h('div', { class: 'card-title', text: 'Customer details' }),
        h('div', { class: 'notice info', text: "You're offline — showing what was last saved to this device." }),
        h('div', { class: 'grid' }, [
            infoBox('Customer ID', customer.CustomerID),
            infoBox('Customer Name', customer.CustomerName),
            infoBox('Address', address || '—'),
            infoBox('Latitude / Longitude', `${customer.Latitude}, ${customer.Longitude}`),
        ]),
        rangeNotice,
        h('div', { class: 'footer-actions' }, [backBtn, proceedBtn]),
    ]);

    invoicesSection.append(h('div', { class: 'card' }, [
        h('div', { class: 'card-title', text: 'Invoices for delivery' }),
        requiresCollection
            ? h('div', { class: 'notice info', text: 'This customer requires a Collection. Confirm every invoice below, then record the collection to finish.' })
            : null,
        h('div', { class: 'table-wrapper' }, [
            h('table', { class: 'table' }, [
                h('thead', {}, [
                    h('tr', {}, [
                        h('th', { text: 'Trip ID' }), h('th', { text: 'Invoice No.' }), h('th', { text: 'DR No.' }),
                        h('th', { text: 'Total Cartons' }), h('th', { text: 'Store photo *' }), h('th', { text: 'Action' }),
                    ]),
                ]),
                h('tbody', { id: 'deliveryDetails' }, invoices.length
                    ? invoices.map((invoice) => offlineInvoiceRow(invoice))
                    : [h('tr', {}, [h('td', { colspan: '6', text: 'No pending invoices assigned to you for this customer.' })])]),
            ]),
        ]),
        requiresCollection
            ? (() => {
                const resumeBtn = h('button', { id: 'resumeCollection', type: 'button', class: 'btn btn-blue dc-hidden' }, [
                    h('i', { class: 'fa-solid fa-hand-holding-dollar', 'aria-hidden': 'true' }), document.createTextNode(' Resume collection'),
                ]);
                resumeBtn.addEventListener('click', () => id('collectionRequiredModal')?.classList.add('active'));
                return h('div', { class: 'footer-actions' }, [resumeBtn]);
            })()
            : null,
    ]));

    const selectedCustomerInput = h('input', { type: 'hidden', id: 'selectedCustomer', value: customer.CustomerID });
    const nodes = [selectedCustomerInput, customerCard, invoicesSection];
    if (requiresCollection) {
        nodes.push(buildOfflineCollectionModal(), buildOfflineSaveConfirmModal());
    }

    container.replaceChildren(...nodes);
    container.classList.remove('dc-hidden');

    if (requiresCollection) {
        // Same one-time setup the online page's DOMContentLoaded wiring
        // does when this modal is present: start with one empty payment
        // row and the totals at zero, ready to fill in.
        paymentRow();
        summary();
        updateCollectionTotals();
    }

    if (!Number.isFinite(lat) || !Number.isFinite(lng) || !navigator.geolocation) {
        rangeNotice.textContent = 'Location for this customer is not available on this device -- deliveries here need a connection.';
        rangeNotice.className = 'notice error';
        return;
    }

    navigator.geolocation.getCurrentPosition(
        (position) => {
            gps = { latitude: position.coords.latitude, longitude: position.coords.longitude };
            const distance = haversineMeters(lat, lng, gps.latitude, gps.longitude);
            const withinRange = distance <= radius;
            rangeNotice.textContent = withinRange
                ? `You're ${Math.round(distance)} m from the customer — within the ${radius} m radius.`
                : `You're ${Math.round(distance)} m from the customer — move within ${radius} m to proceed.`;
            rangeNotice.className = `notice ${withinRange ? 'success' : 'error'}`;
            proceedBtn.classList.toggle('dc-hidden', !withinRange);
        },
        () => {
            rangeNotice.textContent = 'Could not get your location. Enable location access and try again.';
            rangeNotice.className = 'notice error';
        },
        { enableHighAccuracy: true, timeout: 15000 }
    );
}

/**
 * Builds the Collection-required modal from scratch (it only exists in the
 * server-rendered page when a live ?customer= request determines
 * $requiresCollection -- see delivery/portal.php). Deliberately reuses the
 * exact same element ids the ONLINE page uses (paymentRows, invoiceRows,
 * prNumber, totalOutstandingInvoices, etc.), so the existing, already
 *-tested paymentRow()/manualInvoiceRow()/findInvoice()/selectedInvoices()/
 * collectionRequirements()/submitDeliveriesWithCollection() functions all
 * work against it completely unmodified -- this only has to build the
 * markup and wire the handful of buttons those functions don't already
 * self-wire (since the page's one-time DOMContentLoaded wiring ran before
 * this modal existed).
 *
 * Splits (Step 4 online) are intentionally omitted -- an optional
 * accounting breakdown, not required to save a collection -- but the
 * elements shared functions unconditionally read (splitRows,
 * totalSplitBalance, summarySplit) are still present, empty, so nothing
 * throws.
 */
function buildOfflineCollectionModal() {
    const prNumber = h('input', { id: 'prNumber', class: 'input', required: true, placeholder: 'Enter PR number' });

    const invoiceSearch = h('input', { id: 'invoiceSearch', class: 'input', autocomplete: 'off', placeholder: 'Type at least 2 characters' });
    const invoiceResults = h('div', { id: 'invoiceResults', class: 'customer-results dc-hidden', role: 'listbox' });
    const invoiceSearchHint = h('small', { id: 'invoiceSearchHint', text: 'Enter an invoice number, then press Enter or select Add invoice.' });
    const addInvoiceBtn = h('button', { id: 'addInvoice', type: 'button', class: 'btn btn-blue' }, [
        h('i', { class: 'fa-solid fa-search', 'aria-hidden': 'true' }), document.createTextNode(' Search invoice'),
    ]);
    const addManualInvoiceRowBtn = h('button', { id: 'addManualInvoiceRow', type: 'button', class: 'btn btn-blue' }, [
        h('i', { class: 'fa-solid fa-pen-to-square', 'aria-hidden': 'true' }), document.createTextNode(' Add Invoice Manual row'),
    ]);
    invoiceSearch.addEventListener('input', (e) => searchInvoices(e.target.value.trim()));
    invoiceSearch.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); findInvoice(); } });
    addInvoiceBtn.addEventListener('click', findInvoice);
    addManualInvoiceRowBtn.addEventListener('click', manualInvoiceRow);

    const paymentRows = h('tbody', { id: 'paymentRows' });
    const addPaymentBtn = h('button', { id: 'addPayment', type: 'button', class: 'btn btn-blue' }, [
        h('i', { class: 'fa-solid fa-plus', 'aria-hidden': 'true' }), document.createTextNode(' Add payment'),
    ]);
    addPaymentBtn.addEventListener('click', paymentRow);

    const closeBtn = h('button', { class: 'close-btn', id: 'closeCollectionRequired', 'aria-label': 'Close' }, [document.createTextNode('×')]);
    closeBtn.addEventListener('click', () => {
        modal.classList.remove('active');
        pendingDeliveryAfterCollection = null;
        if (finalSubmitMode) id('resumeCollection')?.classList.remove('dc-hidden');
    });

    const modal = h('div', { class: 'custom-modal', id: 'collectionRequiredModal', role: 'dialog', 'aria-modal': 'true' }, [
        h('div', { class: 'custom-modal-content route-modal-content' }, [
            h('div', { class: 'modal-header' }, [
                h('h2', {}, [h('i', { class: 'fa-solid fa-hand-holding-dollar', 'aria-hidden': 'true' }), document.createTextNode(' Collection required before delivery')]),
                closeBtn,
            ]),
            h('div', { class: 'modal-body' }, [
                h('div', { class: 'notice info', text: "You're offline — this collection will be saved on your device and synced once you're back online." }),
                h('div', { id: 'collectionDetails' }, [
                    h('div', { class: 'card workflow-step pr-number-card', id: 'collectionStep1' }, [
                        h('div', { class: 'card-title' }, [h('span', { class: 'step-number', text: '1' }), document.createTextNode(' PR number *')]),
                        h('div', { class: 'grid' }, [h('div', { class: 'info-box' }, [prNumber])]),
                    ]),
                    h('div', { class: 'card workflow-step', id: 'collectionStep2' }, [
                        h('div', { class: 'card-title' }, [h('span', { class: 'step-number', text: '2' }), document.createTextNode(' Invoices with outstanding balance *')]),
                        h('div', { class: 'portal-actions' }, [
                            h('div', { class: 'customer-picker', style: 'position:relative;' }, [
                                h('label', { for: 'invoiceSearch', text: 'Search invoice number *' }),
                                invoiceSearch,
                                invoiceResults,
                                invoiceSearchHint,
                            ]),
                            addInvoiceBtn,
                            addManualInvoiceRowBtn,
                        ]),
                        h('div', { class: 'table-wrapper' }, [
                            h('table', { class: 'table' }, [
                                h('thead', {}, [h('tr', {}, [h('th', {}), h('th', { text: 'Invoice' }), h('th', { text: 'Delivery date' }), h('th', { text: 'Department' }), h('th', { text: 'Balance' }), h('th', {})])]),
                                h('tbody', { id: 'invoiceRows' }, [h('tr', {}, [h('td', { colspan: '6', text: 'Search for an invoice number.' })])]),
                                h('tfoot', {}, [h('tr', { class: 'table-total' }, [h('td', { colspan: '5', text: 'Total selected invoices' }), h('td', { id: 'totalOutstandingInvoices', text: '0.00' })])]),
                            ]),
                        ]),
                    ]),
                    h('div', { class: 'card workflow-step', id: 'collectionStep3' }, [
                        h('div', { class: 'card-title' }, [h('span', { class: 'step-number', text: '3' }), document.createTextNode(' Collection details')]),
                        h('p', { class: 'form-help', text: 'Add at least one payment amount. For Cash, bank initial, check number, and attachment are unavailable. For PDC, all fields are available.' }),
                        h('div', { class: 'table-wrapper' }, [
                            h('table', { class: 'table' }, [
                                h('thead', {}, [h('tr', {}, [h('th', { text: 'Payment type' }), h('th', { text: 'Bank initial' }), h('th', { text: 'Check no.' }), h('th', { text: 'Attachment' }), h('th', { text: 'Amount *' }), h('th', {})])]),
                                paymentRows,
                                h('tfoot', {}, [h('tr', { class: 'table-total' }, [h('td', { colspan: '4', text: 'Total collection details' }), h('td', { id: 'totalCollectionDetails', text: '0.00' }), h('td', {})])]),
                            ]),
                        ]),
                        addPaymentBtn,
                        // collectionRequirements()/summary()/updateCollectionTotals() all
                        // read these unconditionally -- kept empty/zero since Splits
                        // aren't offered offline (see docblock above).
                        h('table', { class: 'dc-hidden' }, [h('tbody', { id: 'splitRows' })]),
                        h('span', { id: 'totalSplitBalance', class: 'dc-hidden', text: '0.00' }),
                    ]),
                    h('div', { class: 'card' }, [
                        h('div', { class: 'card-title', text: 'Summary' }),
                        h('div', { class: 'grid' }, [
                            infoBoxEl('Invoice total', h('span', { id: 'summaryInvoice', text: '0.00' })),
                            infoBoxEl('Splits', h('span', { id: 'summarySplit', text: '- 0.00' })),
                            infoBoxEl('Collected', h('span', { id: 'summaryCollected', text: '- 0.00' })),
                            infoBoxEl('Balance', h('span', { id: 'summaryBalance', text: '0.00' })),
                        ]),
                    ]),
                ]),
                h('div', { class: 'footer-actions' }, [
                    (() => {
                        const btn = h('button', { id: 'completeTransaction', type: 'button', class: 'btn btn-green' }, [
                            h('i', { class: 'fa-solid fa-floppy-disk', 'aria-hidden': 'true' }), document.createTextNode(' Save collection'),
                        ]);
                        btn.addEventListener('click', showSaveConfirmation);
                        return btn;
                    })(),
                ]),
            ]),
        ]),
    ]);

    return modal;
}

function buildOfflineSaveConfirmModal() {
    const saveRequirements = h('div', { id: 'saveRequirements', class: 'save-requirements' });
    const cancelBtn = h('button', { id: 'cancelSave', class: 'btn btn-blue' }, [h('i', { class: 'fa-solid fa-xmark', 'aria-hidden': 'true' }), document.createTextNode(' No')]);
    const confirmBtn = h('button', { id: 'confirmSave', class: 'btn btn-green' }, [h('i', { class: 'fa-solid fa-floppy-disk', 'aria-hidden': 'true' }), document.createTextNode(' Yes, save collection')]);
    const closeBtn = h('button', { class: 'close-btn', id: 'closeSaveConfirm' }, [document.createTextNode('×')]);
    const modal = h('div', { class: 'custom-modal', id: 'saveConfirmModal', role: 'dialog', 'aria-modal': 'true' }, [
        h('div', { class: 'custom-modal-content confirmation-modal' }, [
            h('div', { class: 'modal-header' }, [
                h('h2', {}, [h('i', { class: 'fa-solid fa-circle-check', 'aria-hidden': 'true' }), document.createTextNode(' Save collection?')]),
                closeBtn,
            ]),
            h('div', { class: 'modal-body' }, [
                h('p', { text: 'Are you sure you want to save this collection?' }),
                saveRequirements,
                h('div', { class: 'footer-actions' }, [cancelBtn, confirmBtn]),
            ]),
        ]),
    ]);
    [closeBtn, cancelBtn].forEach((btn) => btn.addEventListener('click', () => modal.classList.remove('active')));
    confirmBtn.addEventListener('click', () => {
        if (finalSubmitMode) return submitDeliveriesWithCollection();
        return pendingDeliveryAfterCollection ? saveCollectionThenDeliver() : save();
    });
    return modal;
}


function initMap(){
    const n=id('dcMap'),lat=Number(n?.dataset.lat),lng=Number(n?.dataset.lng); if(!n||!L||!lat||!lng)return;
    const m=L.map(n).setView([lat,lng],17); window.deliveryCollectionMap=m;
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{attribution:'OpenStreetMap'}).addTo(m);
    const customerName=n.dataset.customer||'Customer',salesmanName=n.dataset.salesman||'Salesman',radius=Number(n.dataset.radius||5);
    L.marker([lat,lng]).addTo(m).bindPopup(`Customer: ${customerName}`).openPopup();
    L.circle([lat,lng],{radius,color:'#198754',fillColor:'#198754',fillOpacity:.08,weight:2}).addTo(m);
    navigator.geolocation?.getCurrentPosition(async p=>{
        const here=[p.coords.latitude,p.coords.longitude]; gps={latitude:here[0],longitude:here[1]};
        L.marker(here).addTo(m).bindPopup(`Salesman: ${salesmanName}`);
        const a=Math.PI/180,h=Math.sin((lat-here[0])*a/2)**2+Math.cos(here[0]*a)*Math.cos(lat*a)*Math.sin((lng-here[1])*a/2)**2,d=2*6371000*Math.atan2(Math.sqrt(h),Math.sqrt(1-h)),ok=d<=radius,allowed=window.collectionLocationRequired===false||ok;
        updateCollectionRange(d,allowed,radius,ok); if(id('confirmLocation')) id('confirmLocation').disabled=!ok;
        id('directionsLink').href=`https://www.google.com/maps/dir/?api=1&origin=${here[0]},${here[1]}&destination=${lat},${lng}`;
        try {
            const response=await fetch(`https://router.project-osrm.org/route/v1/driving/${here[1]},${here[0]};${lng},${lat}?overview=full&geometries=geojson`),route=await response.json();
            if(route.routes?.[0]?.geometry?.coordinates){const points=route.routes[0].geometry.coordinates.map(v=>[v[1],v[0]]),line=L.polyline(points,{color:'#0d6efd',weight:5,opacity:.8}).addTo(m);mapFitTarget=line.getBounds();m.fitBounds(mapFitTarget,{padding:[40,40]});return;}
        } catch(error) { console.warn('Road route unavailable.',error); }
        mapFitTarget=[here,[lat,lng]];
        m.fitBounds(mapFitTarget,{padding:[40,40]});
    },()=>{const allowed=window.collectionLocationRequired===false;notice(allowed?'Location access is optional for this customer.':'Location permission is required to check the customer range.',allowed?'info':'error');updateCollectionRange(null,allowed,radius,false);},{enableHighAccuracy:true,timeout:12000});
}
function updateCollectionRange(distance, allowed, radius) {
    collectionInRange = Boolean(allowed);

    const { module: currentModule, rangeNoticeId, detailsPanelId, proceedButtonId } = moduleContext();

    const distanceText = distance === null ? 'Waiting for GPS' : `${distance.toFixed(1)} m`;
    const hasLocationException = window.collectionLocationRequired === false;
    const exceptionMessage = window.collectionAccessMessage
        || `Location restriction is disabled for this customer. You can now view the ${currentModule} details.`;
    const message = !allowed
        ? 'Customer is out of range'
        : hasLocationException
            ? exceptionMessage
            : `You are within the allowed range. You can now view the ${currentModule} details.`;

    ['distanceMeters'].forEach((key) => {
        if (id(key)) id(key).textContent = distanceText;
    });

    ['rangeState'].forEach((key) => {
        if (!id(key)) return;
        id(key).textContent = message;
        id(key).style.color = allowed ? '#146c43' : '#842029';
    });

    const rangeNotice = id(rangeNoticeId);
    if (rangeNotice) {
        rangeNotice.textContent = allowed && hasLocationException
            ? exceptionMessage
            : allowed
                ? `Allowed radius: ${radius} m. Your distance from the customer: ${distanceText}. You can now view the ${currentModule} details.`
                : `Allowed radius: ${radius} m. Your distance from the customer: ${distanceText}. Customer is out of range.`;
        rangeNotice.className = `notice ${allowed ? 'success' : 'error'}`;
    }

    id(proceedButtonId)?.classList.toggle('dc-hidden', !allowed);
    if (!allowed) id(detailsPanelId)?.classList.add('dc-hidden');
}
function paymentRow(){const tr=document.createElement('tr');tr.innerHTML=`<td><select class="input payment-type"><option>Cash</option><option>PDC</option></select></td><td><input class="input payment-bank" disabled></td><td><input class="input payment-check" disabled></td><td><input class="payment-file" type="file" accept="image/jpeg,image/png,image/gif,image/webp" disabled></td><td><input class="input payment-amount" type="number" min="0" step=".01" value="0"></td><td><button class="btn btn-red remove-row"><i class="fa-solid fa-trash" aria-hidden="true"></i> Remove</button></td>`;tr.querySelector('.payment-type').addEventListener('change',e=>{const enabled=e.target.value==='PDC';tr.querySelectorAll('.payment-bank,.payment-check,.payment-file').forEach(field=>{field.disabled=!enabled;if(!enabled&&field.type!=='file')field.value='';});if(!enabled)tr.querySelector('.payment-file').value='';});id('paymentRows').append(tr);}
function splitRow(){const options=(window.collectionCategories||[]).map(c=>`<option value="${c.catid}" data-required="${c.RequiresAttachment?1:0}">${c.CategoryName}${c.RequiresAttachment?' *':''}</option>`).join('');const tr=document.createElement('tr');tr.innerHTML=`<td><select class="input split-category"><option value="">Select category</option>${options}</select></td><td><input class="input split-amount" type="number" min="0" step=".01" value="0"></td><td><input class="input split-reference"></td><td><input class="split-file" type="file" accept="image/jpeg,image/png,image/gif,image/webp"></td><td><button class="btn btn-red remove-row"><i class="fa-solid fa-trash" aria-hidden="true"></i> Remove</button></td>`;id('splitRows').append(tr);}
function money(value){return Number(value||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});}
/** Populates the Aging of Accounts Receivable modal for the current customer, from InvoiceList via the aging_receivables action. */
async function loadAging(){
    const tbody = id('agingRows');
    if (!tbody) return;
    tbody.innerHTML = '<tr><td colspan="11">Loading…</td></tr>';
    try {
        const data = await post('aging_receivables', { customer: id('selectedCustomer').value });
        const items = data.items || [];
        if (!items.length) {
            tbody.innerHTML = '<tr><td colspan="11">No outstanding invoices for this customer.</td></tr>';
        } else {
            tbody.innerHTML = '';
            items.forEach((item) => {
                const tr = document.createElement('tr');
                const cells = [
                    ['Date', item.date],
                    ['Due Date', item.due_date],
                    ['RefID', item.refid],
                    ['Salesman', item.salesman],
                    ['Current', item.bucket === 'current' ? money(item.balance) : ''],
                    ['Past 30 Days', item.bucket === 'past30' ? money(item.balance) : ''],
                    ['Past 60 Days', item.bucket === 'past60' ? money(item.balance) : ''],
                    ['Past 90 Days', item.bucket === 'past90' ? money(item.balance) : ''],
                    ['Past 120 Day', item.bucket === 'past120' ? money(item.balance) : ''],
                    ['Past 150 Day', item.bucket === 'past150' ? money(item.balance) : ''],
                    ['Total', ''],
                ];
                cells.forEach(([label, value]) => {
                    const td = document.createElement('td');
                    td.setAttribute('data-label', label);
                    td.textContent = value;
                    tr.append(td);
                });
                tbody.append(tr);
            });
        }
        const totals = data.totals || {};
        id('agingTotalCurrent').textContent = money(totals.current);
        id('agingTotalPast30').textContent = money(totals.past30);
        id('agingTotalPast60').textContent = money(totals.past60);
        id('agingTotalPast90').textContent = money(totals.past90);
        id('agingTotalPast120').textContent = money(totals.past120);
        id('agingTotalPast150').textContent = money(totals.past150);
        id('agingTotalGrand').textContent = money(totals.total);
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="11">${e.message}</td></tr>`;
    }
}
function selectedInvoices(){return [...id('invoiceRows').querySelectorAll('tr[data-invoice]')].filter(row=>row.querySelector('.invoice-select')?.checked).map(row=>{const manual=row.dataset.manual==='1';return{invoice_no:manual?row.querySelector('.manual-invoice-number').value.trim():row.dataset.invoice,amount:manual?Number(row.querySelector('.manual-invoice-amount').value||0):Number(row.dataset.amount),manual};});}
function refreshInvoiceTotal(){if(!id('invoiceBalance'))return;id('invoiceBalance').value=selectedInvoices().reduce((total,invoice)=>total+invoice.amount,0);summary();updateCollectionTotals();}
let invoiceSearchTimer;
function searchInvoices(query) {
    const list = id('invoiceResults');
    const hint = id('invoiceSearchHint');
    clearTimeout(invoiceSearchTimer);

    if (query.length < 2) {
        list.replaceChildren();
        list.classList.add('dc-hidden');
        hint.textContent = 'Enter an invoice number, then press Enter or select Add invoice.';
        return;
    }

    invoiceSearchTimer = setTimeout(async () => {
        try {
            const data = await post('collection_invoices', { customer: id('selectedCustomer').value, q: query });
            if (data.already_collected) {
                list.replaceChildren();
                list.classList.add('dc-hidden');
                hint.textContent = `Invoice ${query} has already been collected.`;
                return;
            }
            const available = data.items.filter((item) => !Number(item.AlreadyCollected));
            list.replaceChildren(...available.map((item) => invoiceResult(item)));
            list.classList.toggle('dc-hidden', !available.length);
            hint.textContent = available.length
                ? `${available.length} matching invoice(s). Select one, or press Enter to add all.`
                : (data.items.length ? 'Matching invoice(s) already collected.' : 'No matching invoice. You may add a manual row.');
        } catch (error) {
            notice(error.message, 'error');
        }
    }, 250);
}

function invoiceResult(item) {
    const result = document.createElement('button');
    result.type = 'button';
    result.className = 'customer-result';
    result.setAttribute('role', 'option');

    const name = document.createElement('strong');
    name.textContent = item.InvoiceNo;
    const meta = document.createElement('span');
    meta.textContent = [item.DeliveryDate, item.DEPARTMENT, money(Number(item.Balance))].filter(Boolean).join(' \u2022 ');
    result.append(name, meta);

    result.addEventListener('click', () => {
        invoiceRow({ invoice_no: item.InvoiceNo, amount: Number(item.Balance), delivery_date: item.DeliveryDate, department: item.DEPARTMENT });
        id('invoiceSearch').value = '';
        id('invoiceResults').classList.add('dc-hidden');
        id('invoiceSearchHint').textContent = `Invoice ${item.InvoiceNo} added.`;
        id('addManualInvoiceRow').classList.add('dc-hidden');
    });

    return result;
}

function invoiceRow(invoice){const rows=id('invoiceRows');if([...rows.querySelectorAll('tr[data-invoice]')].some(row=>row.dataset.manual!=='1'&&row.dataset.invoice===invoice.invoice_no))return notice(`Invoice ${invoice.invoice_no} is already in the list.`,'info');const tr=document.createElement('tr');tr.dataset.invoice=invoice.invoice_no;tr.dataset.amount=String(invoice.amount);tr.dataset.manual='0';const checkbox=document.createElement('input');checkbox.type='checkbox';checkbox.className='invoice-select';checkbox.checked=true;checkbox.addEventListener('change',refreshInvoiceTotal);const checkCell=document.createElement('td');checkCell.append(checkbox);tr.append(checkCell);[invoice.invoice_no,invoice.delivery_date||'',invoice.department||'',money(invoice.amount),''].forEach(value=>{const cell=document.createElement('td');cell.textContent=value;tr.append(cell);});if(rows.querySelector('td[colspan]'))rows.replaceChildren();rows.append(tr);refreshInvoiceTotal();}
function manualInvoiceRow(){const rows=id('invoiceRows'),tr=document.createElement('tr');tr.dataset.invoice='';tr.dataset.amount='0';tr.dataset.manual='1';const checkbox=document.createElement('input');checkbox.type='checkbox';checkbox.className='invoice-select';checkbox.checked=true;checkbox.addEventListener('change',refreshInvoiceTotal);const number=document.createElement('input');number.className='input manual-invoice-number';number.placeholder='Invoice number';const amount=document.createElement('input');amount.className='input manual-invoice-amount';amount.type='number';amount.min='0.01';amount.step='0.01';amount.value='0';[number,amount].forEach(field=>field.addEventListener('input',refreshInvoiceTotal));const selectedCell=document.createElement('td');selectedCell.append(checkbox);const invoiceCell=document.createElement('td');invoiceCell.append(number);const dateCell=document.createElement('td');dateCell.textContent='Manual entry';const departmentCell=document.createElement('td');departmentCell.textContent='-';const amountCell=document.createElement('td');amountCell.append(amount);const remove=document.createElement('button');remove.type='button';remove.className='btn btn-red remove-invoice-row';remove.innerHTML='<i class="fa-solid fa-trash" aria-hidden="true"></i> Remove';const actionCell=document.createElement('td');actionCell.append(remove);tr.append(selectedCell,invoiceCell,dateCell,departmentCell,amountCell,actionCell);if(rows.querySelector('td[colspan]'))rows.replaceChildren();rows.append(tr);refreshInvoiceTotal();number.focus();}
async function findInvoice(){const query=id('invoiceSearch').value.trim(),hint=id('invoiceSearchHint'),manualButton=id('addManualInvoiceRow');if(query.length<2)return notice('Enter at least two characters of the invoice number.','error');try{const data=await post('collection_invoices',{customer:id('selectedCustomer').value,q:query});if(data.already_collected){manualButton.classList.add('dc-hidden');hint.textContent='This invoice has already been collected.';return notice(`Invoice ${query} has already been collected.`,'error');}const available=data.items.filter(item=>!Number(item.AlreadyCollected));const collected=data.items.filter(item=>Number(item.AlreadyCollected));if(collected.length)notice(`Invoice ${collected.map(item=>item.InvoiceNo).join(', ')} has already been collected.`,'error');if(available.length){manualButton.classList.add('dc-hidden');available.forEach(item=>invoiceRow({invoice_no:item.InvoiceNo,amount:Number(item.Balance),delivery_date:item.DeliveryDate,department:item.DEPARTMENT}));hint.textContent=`${available.length} InvoiceList record(s) added. Uncheck an invoice to exclude it.`;}else if(!data.items.length){manualButton.classList.remove('dc-hidden');hint.textContent='No record exists in CollectionSyntaxInvDtl or InvoiceList. You may add a manual invoice row.';}else{manualButton.classList.add('dc-hidden');hint.textContent='This invoice has already been collected.';}}catch(e){notice(e.message,'error');}}
function summary(){if(!id('invoiceBalance')||!id('summaryInvoice'))return;const invoice=Number(id('invoiceBalance').value),split=[...document.querySelectorAll('.split-amount')].reduce((s,v)=>s+Number(v.value||0),0),paid=[...document.querySelectorAll('.payment-amount')].reduce((s,v)=>s+Number(v.value||0),0),balance=invoice-split-paid;id('summaryInvoice').textContent=invoice.toFixed(2);id('summarySplit').textContent=`- ${split.toFixed(2)}`;id('summaryCollected').textContent=`- ${paid.toFixed(2)}`;id('summaryBalance').textContent=balance.toFixed(2);id('summaryBalance').style.color=Math.abs(balance)<.01?'#198754':'#dc3545';}
function updateCollectionTotals(){if(!id('invoiceBalance')||!id('totalOutstandingInvoices'))return;const invoice=Number(id('invoiceBalance').value),split=[...document.querySelectorAll('.split-amount')].reduce((sum,input)=>sum+Number(input.value||0),0),paid=[...document.querySelectorAll('.payment-amount')].reduce((sum,input)=>sum+Number(input.value||0),0),balance=invoice-split-paid;id('totalOutstandingInvoices').textContent=invoice.toFixed(2);id('totalCollectionDetails').textContent=paid.toFixed(2);id('totalSplitBalance').textContent=split.toFixed(2);id('summaryBalance').style.color=balance<-.009?'#b45309':Math.abs(balance)<.01?'#198754':'#dc3545';if(collectionAccessGranted)id('completeTransaction').disabled=balance>.009;}
function collectionRequirements(){const missing=[];if(!id('prNumber')?.value.trim())missing.push({label:'PR number',step:'collectionStep1'});const invoices=selectedInvoices();if(!invoices.length)missing.push({label:'at least one invoice',step:'collectionStep2'});else if(invoices.some(invoice=>!invoice.invoice_no||invoice.amount<=0))missing.push({label:'a valid invoice number and amount',step:'collectionStep2'});const hasPayment=[...id('paymentRows')?.querySelectorAll('.payment-amount')||[]].some(input=>Number(input.value||0)>0);if(!hasPayment)missing.push({label:'at least one payment amount',step:'collectionStep3'});[...id('splitRows')?.rows||[]].forEach(row=>{const amount=Number(row.querySelector('.split-amount')?.value||0),category=row.querySelector('.split-category');if(amount>0&&!category?.value&&!missing.some(item=>item.step==='collectionStep4'))missing.push({label:'a category for each split amount',step:'collectionStep4'});if(amount>0&&category?.value&&category.selectedOptions[0]?.dataset.required==='1'&&!row.querySelector('.split-file')?.files[0]&&!missing.some(item=>item.label==='the required split attachment'))missing.push({label:'the required split attachment',step:'collectionStep4'});});return missing;}
function showSaveConfirmation(){const requirements=collectionRequirements(),box=id('saveRequirements'),confirm=id('confirmSave');if(box){box.innerHTML=requirements.length?`<div class="notice error"><strong>Please complete before saving:</strong><ul>${requirements.map(item=>`<li><a href="#${item.step}">${item.label}</a></li>`).join('')}</ul></div>`:'<div class="notice success">All required fields are complete. You can save this collection.</div>';}if(confirm)confirm.disabled=requirements.length>0;id('saveConfirmModal')?.classList.add('active');}
/** Validates and posts the collection form. Returns the saved syntax reference. Does not navigate -- callers decide what happens after a successful save. */
async function submitCollection(){
    if(!collectionInRange||!collectionAccessGranted)throw Error('Open the customer collection details before saving.');
    const invoice=Number(id('invoiceBalance').value),invoices=selectedInvoices(),splits=[],form=new FormData(),payments=[];
    [...id('paymentRows').rows].forEach((r,i)=>{const type=r.querySelector('.payment-type').value,amount=Number(r.querySelector('.payment-amount').value||0),file=r.querySelector('.payment-file').files[0],attachmentReference=file?`payment_${i}`:'';if(amount>0){payments.push({type,bank:r.querySelector('.payment-bank').value.trim(),check:r.querySelector('.payment-check').value.trim(),attachment_reference:attachmentReference,amount});if(file)form.append(`payment_attachment_${attachmentReference}`,file);}});
    [...id('splitRows').rows].forEach((r,i)=>{const category=r.querySelector('.split-category'),amount=Number(r.querySelector('.split-amount').value||0),file=r.querySelector('.split-file').files[0];if(amount>0){if(!category.value)throw Error('Select a category for every split amount.');if(category.selectedOptions[0].dataset.required==='1'&&!file)throw Error('An attachment is required for the selected category.');const attachmentReference=file?`split_${category.value}_${i}`:'';splits.push({catid:Number(category.value),amount,reference:r.querySelector('.split-reference').value.trim(),attachment_reference:attachmentReference});if(file)form.append(`split_attachment_${attachmentReference}`,file);}});
    const collected=payments.reduce((s,p)=>s+p.amount,0),splitTotal=splits.reduce((s,p)=>s+p.amount,0);
    if(!invoices.length)throw Error('Add at least one invoice before saving.');
    if(!id('prNumber').value.trim())throw Error('PR number is required.');
    if(!payments.length)throw Error('Add a payment amount before saving.');
    if(invoice-collected-splitTotal>.009)throw Error('Total balance must be zero or an overpayment before saving.');
    form.set('customer',id('selectedCustomer').value);
    form.set('amount',collected);
    form.set('split_amount',splitTotal);
    form.set('payments',JSON.stringify(payments));
    form.set('splits',JSON.stringify(splits));
    form.set('invoices',JSON.stringify(invoices));
    form.set('pr_number',id('prNumber').value.trim());
    const result=await post('complete_collection',form);
    return result.reference||'';
}
/** Collection Portal flow: save the collection, then leave the page (as before). */
async function save(){
    try{
        const reference=await submitCollection();
        const next=new URL(window.location.href);
        next.searchParams.delete('customer');
        next.searchParams.set('focusCustomer','1');
        next.searchParams.set('savedReference',reference);
        window.location.href=next.toString();
    }catch(e){
        // Saved to the offline outbox -- there's no server-assigned
        // reference yet (that only exists once it actually syncs), so stay
        // on the page rather than navigating with an unknown ?savedReference=.
        if(e.offlineQueued){notice(e.message,'success');id('saveConfirmModal')?.classList.remove('active');return;}
        notice(e.message,'error');
    }
}
/** Delivery Portal flow: save the required collection, then complete the delivery that was waiting on it -- without leaving the page. */
async function saveCollectionThenDeliver(){
    try{
        const reference=await submitCollection();
        id('saveConfirmModal')?.classList.remove('active');
        id('collectionRequiredModal')?.classList.remove('active');
        collectionCompletedForDelivery=true;
        notice(`Collection saved (reference: ${reference}). Completing delivery…`,'success');
        const pending=pendingDeliveryAfterCollection;
        pendingDeliveryAfterCollection=null;
        if(pending?.button)await deliver(pending.button);
    }catch(e){
        if(e.offlineQueued){
            notice(e.message,'success');
            id('saveConfirmModal')?.classList.remove('active');
            id('collectionRequiredModal')?.classList.remove('active');
            return;
        }
        notice(e.message,'error');
    }
}
async function deliver(button){
    try {
        if (!gps) throw Error('Waiting for GPS location.');
        const row = button.closest('tr');
        const photoInput = row?.querySelector('.store-photo-input');
        const photo = photoInput?.files?.[0];
        if (!photo) throw Error('Attach a photo of the store before confirming this delivery.');

        // Some riders (UserList.SType = 'JKAS') or customers not yet
        // classified (Customers.SellingType IS NULL) must have a Collection
        // recorded before delivery is confirmed. For these, every invoice is
        // staged locally ("temporary only") -- nothing is saved until every
        // invoice for this stop has been resolved and the Collection is
        // recorded, at which point both are saved together in one request.
        if (window.deliveryRequiresCollection) {
            stageDeliveryResolution(button, 'delivered', { photo });
            return;
        }

        button.disabled = true;
        const form = new FormData();
        form.set('customer', id('selectedCustomer').value);
        form.set('trip_id', button.dataset.tripId);
        form.set('invoice_no', button.dataset.invoiceNo);
        form.set('latitude', gps.latitude);
        form.set('longitude', gps.longitude);
        form.set('store_photo', photo);
        const result = await post('complete_delivery', form);
        row?.remove();
        notice(result.message, 'success');
        if (Number(result.remaining_for_customer) === 0) {
            const next = new URL(window.location.href);
            next.searchParams.delete('customer');
            next.searchParams.set('focusCustomer', '1');
            window.location.href = next.toString();
        }
    } catch (error) {
        if (error.offlineQueued) {
            // Saved to the outbox with the photo attached -- remove the row
            // optimistically (like a normal confirmation) since there's
            // nothing left for the rider to do here until it syncs.
            button.closest('tr')?.remove();
            notice(error.message, 'success');
            return;
        }
        button.disabled = false;
        notice(error.message, 'error');
    }
}

/**
 * Stages one invoice's resolution (delivered or not received) locally
 * instead of saving it right away -- used only for customers where
 * window.deliveryRequiresCollection is true. The row is visually marked as
 * resolved-but-unsaved, with a "Change" button to undo it, and once every
 * invoice for this stop is staged, the Collection modal opens automatically
 * for the final combined submit.
 */
function stageDeliveryResolution(button, status, extra) {
    const row = button.closest('tr');
    if (!row || !gps) return;
    const tripId = button.dataset.tripId;
    const invoiceNo = button.dataset.invoiceNo;
    const key = `${tripId}::${invoiceNo}`;

    pendingDeliveryResolutions.set(key, {
        tripId,
        invoiceNo,
        status,
        latitude: gps.latitude,
        longitude: gps.longitude,
        photo: extra.photo || null,
        reason: extra.reason || null,
    });

    row.dataset.resolved = '1';
    row.classList.remove('row-pending-delivered', 'row-not-delivered');
    row.classList.add(status === 'delivered' ? 'row-pending-delivered' : 'row-not-delivered');
    row.querySelector('.not-delivered-reason')?.classList.add('dc-hidden');

    const actionCell = row.querySelector('.delivery-action-cell');
    if (actionCell) {
        actionCell.querySelectorAll('button, input, textarea').forEach((el) => { el.disabled = true; });

        let badge = actionCell.querySelector('.resolution-pending-badge');
        if (!badge) {
            badge = document.createElement('div');
            badge.className = 'resolution-pending-badge';
            actionCell.prepend(badge);
        }
        badge.innerHTML = status === 'delivered'
            ? '<span class="status-chip status-chip--delivered" tabindex="0" data-tooltip="Marked delivered (unsaved). Tap Change to undo."><i class="fa-solid fa-clock" aria-hidden="true"></i> Unsaved</span>'
            : '<span class="status-chip status-chip--not-received" tabindex="0" data-tooltip="Marked not received (unsaved). Tap Change to undo."><i class="fa-solid fa-clock" aria-hidden="true"></i> Unsaved</span>';

        let undo = actionCell.querySelector('.resolution-undo');
        if (!undo) {
            undo = document.createElement('button');
            undo.type = 'button';
            undo.className = 'btn btn-gray resolution-undo';
            undo.innerHTML = '<i class="fa-solid fa-rotate-left" aria-hidden="true"></i> Change';
            undo.addEventListener('click', () => unstageDeliveryResolution(key, row));
            actionCell.append(undo);
        }
        undo.disabled = false;
    }

    checkAllInvoicesResolved();
}

/** Reverses stageDeliveryResolution() for one row, so the rider can correct a mistake before the final submit. */
function unstageDeliveryResolution(key, row) {
    pendingDeliveryResolutions.delete(key);
    row.classList.remove('row-pending-delivered', 'row-not-delivered');
    delete row.dataset.resolved;

    const actionCell = row.querySelector('.delivery-action-cell');
    if (actionCell) {
        actionCell.querySelectorAll('button, input, textarea').forEach((el) => {
            if (!el.classList.contains('resolution-undo')) el.disabled = false;
        });
        actionCell.querySelector('.resolution-pending-badge')?.remove();
        actionCell.querySelector('.resolution-undo')?.remove();
    }

    updateDeliveryButtonStates();
    if (finalSubmitMode) {
        finalSubmitMode = false;
        id('collectionRequiredModal')?.classList.remove('active');
        id('resumeCollection')?.classList.add('dc-hidden');
        notice('Invoice reopened. Resolve every invoice again to record the collection.', 'info');
    }
}

/** Once every invoice for this stop has been resolved locally, opens the Collection modal and pre-fills it with the confirmed-delivered invoices. */
async function checkAllInvoicesResolved() {
    const rows = document.querySelectorAll('#deliveryDetails tr[data-delivery-invoice]');
    if (!rows.length || finalSubmitMode) return;
    const allResolved = [...rows].every((row) => row.dataset.resolved === '1');
    if (!allResolved) return;

    // Nothing was actually delivered at this stop (every invoice ended up
    // "not received" -- store closed, owner not around, refused, etc.):
    // there is no payment to collect, so save every reason directly
    // instead of opening the Collection modal (which would ask for a PR
    // number and a payment that doesn't exist here).
    if ([...pendingDeliveryResolutions.values()].every((item) => item.status === 'not_received')) {
        await submitNotDeliveredOnly();
        return;
    }

    finalSubmitMode = true;
    collectionAccessGranted = true;
    collectionInRange = true;
    id('resumeCollection')?.classList.add('dc-hidden');
    id('collectionRequiredModal')?.classList.add('active');
    notice('All invoices for this stop have been processed. Record the collection below to finish this delivery.', 'success');

    const delivered = [...pendingDeliveryResolutions.values()].filter((item) => item.status === 'delivered');
    const invoiceNumbers = delivered.map((item) => item.invoiceNo);
    if (invoiceNumbers.length) {
        try {
            const data = await post('collection_invoices_batch', {
                customer: id('selectedCustomer').value,
                invoice_numbers: JSON.stringify(invoiceNumbers),
            });
            (data.items || []).forEach((match) => {
                invoiceRow({ invoice_no: match.InvoiceNo, amount: Number(match.Balance), delivery_date: match.DeliveryDate, department: match.DEPARTMENT });
            });
            const missing = [...(data.not_found || []), ...(data.already_collected || [])];
            if (missing.length) {
                notice(`Couldn't auto-add invoice(s) ${missing.join(', ')} to the outstanding balance. Add manually if needed.`, 'error');
            }
        } catch (error) {
            notice(`Couldn't load invoice details from InvoiceList: ${error.message}. Add the confirmed invoice(s) manually below.`, 'error');
        }
    }
    updateCollectionTotals();
}

/**
 * Submits every staged "not received" resolution for this stop directly --
 * no Collection modal, since nothing was delivered and there is nothing to
 * collect. Reuses the same per-invoice not_delivered action as the
 * non-"requires collection" flow, one call per invoice, so the server-side
 * validation/behavior is identical either way. Each item is handled
 * independently so one offline/failed item doesn't stop the rest -- e.g.
 * connectivity drops mid-way, every remaining reason still gets queued.
 */
async function submitNotDeliveredOnly() {
    const items = [...pendingDeliveryResolutions.entries()];
    let anyQueued = false;
    const failed = [];

    for (const [key, item] of items) {
        try {
            await post('not_delivered', {
                customer: id('selectedCustomer').value,
                trip_id: item.tripId,
                invoice_no: item.invoiceNo,
                reason: item.reason,
                latitude: item.latitude,
                longitude: item.longitude,
            });
            pendingDeliveryResolutions.delete(key);
        } catch (error) {
            if (error.offlineQueued) {
                anyQueued = true;
                pendingDeliveryResolutions.delete(key);
            } else {
                failed.push(`${item.invoiceNo} (${error.message})`);
            }
        }
    }

    if (failed.length) {
        // Leave the failed ones staged (still shown as "Unsaved" with a
        // working "Change" button) so the rider can retry or correct them
        // -- don't silently drop a reason that didn't actually save.
        notice(`Couldn't save invoice(s) ${failed.join(', ')}. Tap Change on that row to retry.`, 'error');
        return;
    }

    notice(
        anyQueued
            ? "You're offline. Every invoice for this stop was saved on your device and will sync automatically once you're back online."
            : 'All invoices for this stop were recorded as not received.',
        'success'
    );
    const next = new URL(window.location.href);
    next.searchParams.delete('customer');
    next.searchParams.set('focusCustomer', '1');
    window.location.href = next.toString();
}

/** Final Submit for the "requires collection" flow: saves every staged delivery resolution and the collection together in one request. */
async function submitDeliveriesWithCollection() {
    try {
        if (!collectionInRange || !collectionAccessGranted) throw Error('Open the customer collection details before saving.');
        if (!pendingDeliveryResolutions.size) throw Error('No delivery confirmations were staged.');

        const invoice = Number(id('invoiceBalance').value);
        const invoices = selectedInvoices();
        const payments = [];
        const splits = [];
        const form = new FormData();

        [...id('paymentRows').rows].forEach((r, i) => {
            const type = r.querySelector('.payment-type').value;
            const amount = Number(r.querySelector('.payment-amount').value || 0);
            const file = r.querySelector('.payment-file').files[0];
            const attachmentReference = file ? `payment_${i}` : '';
            if (amount > 0) {
                payments.push({ type, bank: r.querySelector('.payment-bank').value.trim(), check: r.querySelector('.payment-check').value.trim(), attachment_reference: attachmentReference, amount });
                if (file) form.append(`payment_attachment_${attachmentReference}`, file);
            }
        });
        [...id('splitRows').rows].forEach((r, i) => {
            const category = r.querySelector('.split-category');
            const amount = Number(r.querySelector('.split-amount').value || 0);
            const file = r.querySelector('.split-file').files[0];
            if (amount > 0) {
                if (!category.value) throw Error('Select a category for every split amount.');
                if (category.selectedOptions[0].dataset.required === '1' && !file) throw Error('An attachment is required for the selected category.');
                const attachmentReference = file ? `split_${category.value}_${i}` : '';
                splits.push({ catid: Number(category.value), amount, reference: r.querySelector('.split-reference').value.trim(), attachment_reference: attachmentReference });
                if (file) form.append(`split_attachment_${attachmentReference}`, file);
            }
        });

        const collected = payments.reduce((s, p) => s + p.amount, 0);
        const splitTotal = splits.reduce((s, p) => s + p.amount, 0);
        if (!invoices.length) throw Error('Add at least one invoice before saving.');
        if (!id('prNumber').value.trim()) throw Error('PR number is required.');
        if (!payments.length) throw Error('Add a payment amount before saving.');
        if (invoice - collected - splitTotal > .009) throw Error('Total balance must be zero or an overpayment before saving.');

        const deliveries = [...pendingDeliveryResolutions.values()].map((item, index) => {
            const entry = {
                trip_id: item.tripId,
                invoice_no: item.invoiceNo,
                status: item.status,
                latitude: item.latitude,
                longitude: item.longitude,
            };
            if (item.status === 'delivered') {
                form.append(`delivery_photo_${index}`, item.photo);
            } else {
                entry.reason = item.reason;
            }
            return entry;
        });

        form.set('customer', id('selectedCustomer').value);
        form.set('amount', collected);
        form.set('split_amount', splitTotal);
        form.set('payments', JSON.stringify(payments));
        form.set('splits', JSON.stringify(splits));
        form.set('invoices', JSON.stringify(invoices));
        form.set('pr_number', id('prNumber').value.trim());
        form.set('deliveries', JSON.stringify(deliveries));

        const result = await post('complete_delivery_with_collection', form);
        id('saveConfirmModal')?.classList.remove('active');
        id('collectionRequiredModal')?.classList.remove('active');
        notice(result.message, 'success');

        const next = new URL(window.location.href);
        next.searchParams.delete('customer');
        next.searchParams.set('focusCustomer', '1');
        next.searchParams.set('savedReference', result.reference);
        window.location.href = next.toString();
    } catch (error) {
        if (error.offlineQueued) {
            // No server-assigned reference yet -- stay on the page instead
            // of navigating with an unknown ?savedReference=.
            id('saveConfirmModal')?.classList.remove('active');
            id('collectionRequiredModal')?.classList.remove('active');
            notice(error.message, 'success');
            return;
        }
        notice(error.message, 'error');
    }
}
function updateDeliveryButtonStates(){
    document.querySelectorAll('.confirm-delivery').forEach((button) => {
        const row = button.closest('tr');
        // Rows already confirmed/marked-not-received (staged locally while
        // waiting on a required Collection, see stageDeliveryResolution())
        // must stay locked. Without this check, attaching a photo to *any
        // other* row re-runs this function for every button on the page and
        // re-enables an already-resolved row, since its file input still
        // holds the photo it was confirmed with.
        if (row?.dataset.resolved === '1') { button.disabled = true; return; }
        if (!locationConfirmedForDelivery) { button.disabled = true; return; }
        const hasPhoto = Boolean(row?.querySelector('.store-photo-input')?.files?.length);
        button.disabled = !hasPhoto;
    });
    document.querySelectorAll('.not-delivered-toggle').forEach((button) => {
        const row = button.closest('tr');
        if (row?.dataset.resolved === '1') { button.disabled = true; return; }
        button.disabled = !locationConfirmedForDelivery;
    });
}
async function notDelivered(button){
    const row = button.closest('tr');
    const panel = row?.querySelector('.not-delivered-reason');
    const reasonField = panel?.querySelector('.not-delivered-reason-text');
    const reason = reasonField?.value.trim() || '';
    if (!reason) { notice("Enter a reason before saving.", 'error'); reasonField?.focus(); return; }
    if (!gps) { notice('Waiting for GPS location.', 'error'); return; }

    // Same reasoning as deliver(): customers requiring a Collection stage
    // every invoice resolution locally until they're all done, then save
    // everything together with the Collection.
    if (window.deliveryRequiresCollection) {
        stageDeliveryResolution(button, 'not_received', { reason });
        return;
    }

    try {
        button.disabled = true;
        const result = await post('not_delivered', {
            customer: id('selectedCustomer').value,
            trip_id: button.dataset.tripId,
            invoice_no: button.dataset.invoiceNo,
            reason,
            ...gps,
        });
        row?.remove();
        notice(result.message, 'success');
        if (Number(result.remaining_for_customer) === 0) {
            const next = new URL(window.location.href);
            next.searchParams.delete('customer');
            next.searchParams.set('focusCustomer', '1');
            window.location.href = next.toString();
        }
    } catch (error) {
        if (error.offlineQueued) {
            row?.remove();
            notice(error.message, 'success');
            return;
        }
        button.disabled = false;
        notice(error.message, 'error');
    }
}
function initRouteMap(){
    const el=id('routeMap'); if(!el||!window.L||!Array.isArray(window.deliveryRouteStops))return;
    if(window.deliveryRouteMap){window.deliveryRouteMap.invalidateSize();if(window.deliveryRouteBounds)window.deliveryRouteMap.fitBounds(window.deliveryRouteBounds,{padding:[30,30],maxZoom:16});suggestNearestStop();return;}
    const stops=window.deliveryRouteStops.filter(s=>Number(s.Latitude)&&Number(s.Longitude));
    if(!stops.length){el.closest('.route-map-wrap')?.classList.add('dc-hidden');return;}
    const map=L.map(el);
    window.deliveryRouteMap=map;
    window.deliveryRouteMarkers={};
    window.deliveryRouteStopMeta={};
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19,attribution:'OpenStreetMap'}).addTo(map);
    const latlngs=[];
    stops.forEach(stop=>{
        const lat=Number(stop.Latitude),lng=Number(stop.Longitude);
        latlngs.push([lat,lng]);
        const delivered=(Number(stop.InvoiceCount||0)>0)&&((Number(stop.DeliveredCount||0)+Number(stop.NotDeliveredCount||0))>=Number(stop.InvoiceCount||0));
        const icon=L.divIcon({className:'route-pin'+(delivered?' route-pin--done':''),html:`<span>${stop.DisplaySeq}</span>`,iconSize:[28,28],iconAnchor:[14,14]});
        const address=[stop.Street,stop.Barangay,stop.Municipality,stop.Province].filter(Boolean).join(', ');
        const marker=L.marker([lat,lng],{icon}).addTo(map).bindPopup(`<strong>#${stop.DisplaySeq} Trip ${stop.TripId} &ndash; ${stop.CustomerName}</strong><br>${address}`);
        window.deliveryRouteMarkers[stop.DisplaySeq]=marker;
        window.deliveryRouteStopMeta[stop.DisplaySeq]={lat,lng,delivered,tripId:stop.TripId,customerName:stop.CustomerName};
    });
    window.deliveryRouteBounds=latlngs.length>1?latlngs:[latlngs[0],[latlngs[0][0]+0.001,latlngs[0][1]+0.001]];
    map.fitBounds(window.deliveryRouteBounds,{padding:[30,30],maxZoom:16});
    suggestNearestStop();
    if(latlngs.length<2)return;
    // Fallback: a straight dashed line, shown immediately and replaced by the
    // real driving route below once/if it loads.
    let routeLine=L.polyline(latlngs,{color:'#a71927',weight:3,dashArray:'6,8',opacity:.85}).addTo(map);
    const waypoints=latlngs.map(([lat,lng])=>`${lng},${lat}`).join(';');
    fetch(`https://router.project-osrm.org/route/v1/driving/${waypoints}?overview=full&geometries=geojson`)
        .then(r=>r.json())
        .then(data=>{
            const coords=data.routes?.[0]?.geometry?.coordinates;
            if(!coords)return;
            routeLine.remove();
            routeLine=L.polyline(coords.map(c=>[c[1],c[0]]),{color:'#a71927',weight:4,opacity:.85}).addTo(map);
        })
        .catch(error=>console.warn('Route directions unavailable, showing straight line instead.',error));
}

/** Great-circle distance in meters between two lat/lng points. Used as a
 *  fallback when the driving-distance lookup below is unavailable. */
function haversineMeters(lat1,lng1,lat2,lng2){
    const a=Math.PI/180;
    const h=Math.sin((lat2-lat1)*a/2)**2+Math.cos(lat1*a)*Math.cos(lat2*a)*Math.sin((lng2-lng1)*a/2)**2;
    return 2*6371000*Math.atan2(Math.sqrt(h),Math.sqrt(1-h));
}

/**
 * Real driving distance from `here` to every pending stop, in one request,
 * via OSRM's table (distance-matrix) service -- the same routing engine
 * already used to draw the route line on the map. Returns whichever
 * pending stop is shortest to actually *drive* to (not straight-line), so
 * a stop across the bay/a river doesn't get suggested just because it
 * looks close on the map. Throws if OSRM is unreachable/rate-limited/slow,
 * so the caller can fall back to straight-line distance instead.
 */
async function nearestByDrivingDistance(here,pending){
    if(!pending.length)throw new Error('No pending stops.');
    const coords=[`${here[1]},${here[0]}`,...pending.map(([,stop])=>`${stop.lng},${stop.lat}`)].join(';');
    const destinations=pending.map((_,i)=>i+1).join(';');
    const url=`https://router.project-osrm.org/table/v1/driving/${coords}?sources=0&destinations=${destinations}&annotations=distance`;
    const controller=new AbortController();
    const timeout=setTimeout(()=>controller.abort(),8000);
    let data;
    try{
        const response=await fetch(url,{signal:controller.signal});
        if(!response.ok)throw new Error(`OSRM table request failed (${response.status}).`);
        data=await response.json();
    }finally{
        clearTimeout(timeout);
    }
    const distances=data?.distances?.[0];
    if(!Array.isArray(distances))throw new Error('OSRM table response missing distances.');
    let nearestIndex=null,nearestDistance=Infinity;
    distances.forEach((distance,i)=>{
        if(typeof distance==='number'&&distance<nearestDistance){nearestDistance=distance;nearestIndex=i;}
    });
    if(nearestIndex===null)throw new Error('OSRM could not find a driving route to any pending stop.');
    const[seq]=pending[nearestIndex];
    return{seq,distanceMeters:nearestDistance,mode:'driving'};
}

/** Straight-line fallback -- used only if the driving-distance lookup above
 *  fails (offline, OSRM unavailable/rate-limited, request timed out). */
function nearestByStraightLine(here,pending){
    let nearestSeq=null,nearestDistance=Infinity;
    pending.forEach(([seq,stop])=>{
        const distance=haversineMeters(here[0],here[1],stop.lat,stop.lng);
        if(distance<nearestDistance){nearestDistance=distance;nearestSeq=seq;}
    });
    if(nearestSeq===null)return null;
    return{seq:nearestSeq,distanceMeters:nearestDistance,mode:'straight-line'};
}

/**
 * Rather than always pointing the rider at the next stop in assigned
 * sequence order, this finds whichever *unresolved* stop is closest to the
 * rider's current GPS position by actual driving distance -- e.g. sequence
 * says #2 next, but #3 is actually nearer to drive to right now, so #3 gets
 * suggested instead. Purely a suggestion: it doesn't change
 * SortNum/DisplaySeq or reorder the table, it just highlights the nearest
 * pin and surfaces a "go here next" banner + a "Nearest" tag on that row.
 */
let routeSuggestedSeq=null;
function suggestNearestStop(){
    const banner=id('routeSuggestion');
    const meta=window.deliveryRouteStopMeta;
    if(!banner||!meta)return;

    const pending=Object.entries(meta).filter(([,stop])=>!stop.delivered);
    document.querySelectorAll('.route-nearest-badge').forEach(badge=>badge.classList.add('dc-hidden'));
    Object.keys(window.deliveryRouteMarkers||{}).forEach(seq=>{
        const marker=window.deliveryRouteMarkers[seq];
        const stop=meta[seq];
        if(!marker||!stop)return;
        marker.setIcon(L.divIcon({className:'route-pin'+(stop.delivered?' route-pin--done':''),html:`<span>${seq}</span>`,iconSize:[28,28],iconAnchor:[14,14]}));
    });
    routeSuggestedSeq=null;

    if(!pending.length){
        banner.classList.remove('dc-hidden');
        id('routeSuggestionText').textContent='All assigned stops are resolved -- nothing left to suggest.';
        id('routeSuggestionFocus').disabled=true;
        return;
    }
    if(!navigator.geolocation){
        banner.classList.add('dc-hidden');
        return;
    }

    navigator.geolocation.getCurrentPosition(async position=>{
        const here=[position.coords.latitude,position.coords.longitude];
        let nearest=null;
        try{
            nearest=await nearestByDrivingDistance(here,pending);
        }catch(error){
            console.warn('Driving-distance lookup unavailable, falling back to straight-line distance.',error);
        }
        if(!nearest)nearest=nearestByStraightLine(here,pending);
        if(!nearest)return;

        const{seq:nearestSeq,distanceMeters:nearestDistance,mode}=nearest;
        routeSuggestedSeq=nearestSeq;
        const stop=meta[nearestSeq];
        const distanceLabel=nearestDistance>=1000?`${(nearestDistance/1000).toFixed(1)} km`:`${Math.round(nearestDistance)} m`;
        const modeLabel=mode==='driving'?'by road':'straight-line, driving distance unavailable';
        banner.classList.remove('dc-hidden');
        id('routeSuggestionText').textContent=`Suggested next stop: #${nearestSeq} \u00b7 ${stop.customerName} (Trip ${stop.tripId}) \u2014 about ${distanceLabel} away (${modeLabel})`;
        id('routeSuggestionFocus').disabled=false;

        const marker=window.deliveryRouteMarkers?.[nearestSeq];
        if(marker){
            marker.setIcon(L.divIcon({className:'route-pin route-pin--suggested',html:`<span>${nearestSeq}</span>`,iconSize:[28,28],iconAnchor:[14,14]}));
        }
        document.querySelector(`.route-table tr[data-seq="${nearestSeq}"] .route-nearest-badge`)?.classList.remove('dc-hidden');
        showRiderLocationOnMap(here,nearestSeq);
    },()=>{
        banner.classList.remove('dc-hidden');
        id('routeSuggestionText').textContent='Enable location access to see which unresolved stop is nearest.';
        id('routeSuggestionFocus').disabled=true;
    },{enableHighAccuracy:true,timeout:12000});
}

/**
 * Places a "you are here" marker at the rider's current GPS position on the
 * route map, and draws a real driving-route line connecting it to whichever
 * stop is currently suggested as next (falling back to the next stop in
 * assigned sequence when nothing stands out as nearer -- see
 * suggestNearestStop() above) -- so the rider can see where they are
 * relative to the route, not just the numbered stops on their own. Reuses
 * the position already fetched for that suggestion rather than asking the
 * device for location a second time. Safe to call repeatedly (e.g. every
 * time the suggestion refreshes): it moves the existing marker/line instead
 * of stacking up duplicates.
 */
function showRiderLocationOnMap(here,targetSeq){
    const map=window.deliveryRouteMap;
    if(!map)return;

    if(window.deliveryRiderMarker){
        window.deliveryRiderMarker.setLatLng(here);
    }else{
        window.deliveryRiderMarker=L.marker(here,{
            icon:L.divIcon({className:'rider-location-marker',iconSize:[20,20],iconAnchor:[10,10]}),
            zIndexOffset:1000,
        }).addTo(map).bindPopup('Your current location');
    }

    if(window.deliveryRiderLine){
        window.deliveryRiderLine.remove();
        window.deliveryRiderLine=null;
    }
    const stop=window.deliveryRouteStopMeta?.[targetSeq];
    if(!stop)return;

    const target=[stop.lat,stop.lng];
    // Fallback: a straight dashed line, shown immediately and replaced by
    // the real driving route below once/if it loads -- same pattern as the
    // main stop-to-stop route line, just in blue (matching the suggested
    // pin's color) to read as "you are here" rather than the assigned
    // stop-to-stop route, which stays red.
    window.deliveryRiderLine=L.polyline([here,target],{color:'#0d6efd',weight:3,dashArray:'4,8',opacity:.85}).addTo(map);
    fetch(`https://router.project-osrm.org/route/v1/driving/${here[1]},${here[0]};${target[1]},${target[0]}?overview=full&geometries=geojson`)
        .then(r=>r.json())
        .then(data=>{
            const coords=data.routes?.[0]?.geometry?.coordinates;
            if(!coords||!window.deliveryRiderLine)return;
            window.deliveryRiderLine.remove();
            window.deliveryRiderLine=L.polyline(coords.map(c=>[c[1],c[0]]),{color:'#0d6efd',weight:4,opacity:.85}).addTo(map);
        })
        .catch(error=>console.warn('Rider-to-stop route directions unavailable, showing straight line instead.',error));
}

/** Pans/zooms the already-open route map to one stop's pin and opens its
 *  popup -- triggered by clicking that stop's customer name in the table
 *  below the map, so you don't have to hunt for the pin yourself. */
function focusRouteStop(displaySeq){
    const map=window.deliveryRouteMap;
    const marker=window.deliveryRouteMarkers?.[displaySeq];
    if(!map||!marker)return;
    id('routeMap')?.scrollIntoView({behavior:'smooth',block:'start'});
    map.setView(marker.getLatLng(),17,{animate:true});
    marker.openPopup();
    const el=marker.getElement();
    if(el){el.classList.add('route-pin--pulse');setTimeout(()=>el.classList.remove('route-pin--pulse'),1500);}
}
document.addEventListener('DOMContentLoaded',()=>{initMap();id('customerSearch')?.addEventListener('input',e=>search(e.target.value.trim()));id('customerSearch')?.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();openCustomer();}});id('customerResults')?.addEventListener('dblclick',openCustomer);id('openCustomer')?.addEventListener('click',openCustomer);id('invoiceSearch')?.addEventListener('input',e=>searchInvoices(e.target.value.trim()));id('invoiceSearch')?.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();findInvoice();}});id('addInvoice')?.addEventListener('click',findInvoice);id('addManualInvoiceRow')?.addEventListener('click',manualInvoiceRow);id('confirmLocation')?.addEventListener('click',async()=>{try{if(!gps)throw Error('Waiting for GPS location.');await post('confirm_location',{customer:id('selectedCustomer').value,module:id('moduleName').value,...gps});notice('Location confirmed. You can now save.','success');id('completeTransaction').disabled=false;}catch(e){notice(e.message,'error');}});id('viewMap')?.addEventListener('click',()=>{id('mapModal').classList.add('active');setTimeout(()=>{window.deliveryCollectionMap?.invalidateSize();if(mapFitTarget)window.deliveryCollectionMap?.fitBounds(mapFitTarget,{padding:[40,40]});},150);});id('closeMap')?.addEventListener('click',()=>id('mapModal').classList.remove('active'));id('viewAging')?.addEventListener('click',()=>{id('agingModal')?.classList.add('active');loadAging();});id('closeAging')?.addEventListener('click',()=>id('agingModal').classList.remove('active'));id('viewCollectionDetails')?.addEventListener('click',async()=>{try{if(!collectionInRange||!gps)throw Error('Customer is out of range. Move within 5 m of the customer.');const result=await post('collection_access',{customer:id('selectedCustomer').value,...gps});id('collectionDetails').classList.remove('dc-hidden');collectionAccessGranted=true;updateCollectionTotals();notice(result.access.reason,'success');}catch(e){notice(e.message,'error');}});id('viewDeliveryDetails')?.addEventListener('click',async()=>{try{if(!collectionInRange||!gps)throw Error('Customer is out of range. Move within the allowed radius of the customer.');await post('confirm_location',{customer:id('selectedCustomer').value,module:'delivery',...gps});id('deliveryDetails').classList.remove('dc-hidden');locationConfirmedForDelivery=true;updateDeliveryButtonStates();notice('Location confirmed. Attach a store photo, then confirm each invoice one at a time.','success');}catch(e){notice(e.message,'error');}});document.addEventListener('change',e=>{if(e.target.classList.contains('store-photo-input'))updateDeliveryButtonStates();});id('addPayment')?.addEventListener('click',paymentRow);id('addSplit')?.addEventListener('click',splitRow);document.addEventListener('input',()=>{summary();updateCollectionTotals();});document.addEventListener('click',e=>{if(e.target.classList.contains('remove-row')||e.target.classList.contains('remove-invoice-row')){e.target.closest('tr').remove();refreshInvoiceTotal();}});if(id('paymentRows')){paymentRow();splitRow();summary();updateCollectionTotals();}id('completeTransaction')?.addEventListener('click',showSaveConfirmation);['closeSaveConfirm','cancelSave'].forEach(key=>id(key)?.addEventListener('click',()=>id('saveConfirmModal').classList.remove('active')));id('saveRequirements')?.addEventListener('click',e=>{if(e.target.closest('a'))id('saveConfirmModal').classList.remove('active');});id('confirmSave')?.addEventListener('click',()=>{if(finalSubmitMode)return submitDeliveriesWithCollection();return pendingDeliveryAfterCollection?saveCollectionThenDeliver():save();});id('closeCollectionRequired')?.addEventListener('click',()=>{id('collectionRequiredModal').classList.remove('active');pendingDeliveryAfterCollection=null;if(finalSubmitMode)id('resumeCollection')?.classList.remove('dc-hidden');});id('resumeCollection')?.addEventListener('click',()=>id('collectionRequiredModal')?.classList.add('active'));});
document.addEventListener('DOMContentLoaded',()=>{
    const params=new URLSearchParams(window.location.search),reference=params.get('savedReference');
    if(reference)notice(`Collection saved successfully. Syntax reference: ${reference}`,'success');
    if(params.get('focusCustomer')==='1')setTimeout(()=>id('customerSearch')?.focus(),0);

    document.querySelectorAll('.confirm-delivery').forEach((button) => {
        button.addEventListener('click', () => deliver(button));
    });
    document.querySelectorAll('.not-delivered-toggle').forEach((button) => {
        button.addEventListener('click', () => {
            const panel = button.closest('tr')?.querySelector('.not-delivered-reason');
            panel?.classList.toggle('dc-hidden');
            if (panel && !panel.classList.contains('dc-hidden')) panel.querySelector('.not-delivered-reason-text')?.focus();
        });
    });
    document.querySelectorAll('.not-delivered-cancel').forEach((button) => {
        button.addEventListener('click', () => {
            const panel = button.closest('.not-delivered-reason');
            panel?.classList.add('dc-hidden');
            const field = panel?.querySelector('.not-delivered-reason-text');
            if (field) field.value = '';
        });
    });
    document.querySelectorAll('.not-delivered-submit').forEach((button) => {
        button.addEventListener('click', () => notDelivered(button));
    });

    id('viewRoute')?.addEventListener('click', () => {
        id('routeModal').classList.add('active');
        setTimeout(initRouteMap, 150);
    });
    id('closeRoute')?.addEventListener('click', () => id('routeModal').classList.remove('active'));
    id('routeSuggestionFocus')?.addEventListener('click', () => { if (routeSuggestedSeq !== null) focusRouteStop(routeSuggestedSeq); });
    id('routeSuggestionRefresh')?.addEventListener('click', suggestNearestStop);
    document.querySelectorAll('.route-focus-customer').forEach((link) => {
        link.addEventListener('click', (e) => {
            e.preventDefault();
            focusRouteStop(link.dataset.seq);
        });
    });
    // A stop's "Open" link is a plain page navigation online (the server
    // needs to render Customer details + Invoices for delivery, GPS check
    // included) -- but offline there's no server to reach, so this steps in
    // with the IndexedDB-backed equivalent instead. Online, the click is
    // left alone and behaves exactly as the href says.
    document.querySelectorAll('.route-open-link').forEach((link) => {
        link.addEventListener('click', (e) => {
            if (!isOffline()) return;
            e.preventDefault();
            const code = link.dataset.customerId;
            const name = link.closest('tr')?.querySelector('.route-focus-customer')?.firstChild?.textContent?.trim();
            id('routeModal')?.classList.remove('active');
            openCustomerOffline(code, name);
        });
    });

    // Deposit Slip upload (Delivery Portal route table): opens a small modal
    // scoped to one Trip + Customer stop; the button itself is only enabled
    // server-side (see delivery/portal.php) once that stop reads "Delivered"
    // and the rider is JKAS.
    document.querySelectorAll('.deposit-slip-btn').forEach((button) => {
        button.addEventListener('click', async () => {
            currentDepositSlipButton = button;
            id('depositSlipTripId').value = button.dataset.tripId;
            id('depositSlipCustomerId').value = button.dataset.customerId;
            id('depositSlipTripLabel').textContent = button.dataset.tripId;
            id('depositSlipCustomerLabel').textContent = button.dataset.customerName;
            id('depositSlipFile').value = '';
            id('depositSlipExisting')?.classList.add('dc-hidden');
            id('depositSlipModal')?.classList.add('active');
            // Show what's already on file (if anything) so the rider can view it before deciding whether to replace it.
            try {
                const info = await post('deposit_slip_info', { trip_id: button.dataset.tripId, customer: button.dataset.customerId });
                if (info.exists && info.url) {
                    id('depositSlipExistingLink').href = info.url;
                    id('depositSlipExistingImg').src = info.url;
                    id('depositSlipExisting')?.classList.remove('dc-hidden');
                }
            } catch (e) { /* Non-fatal: the upload form still works without the preview. */ }
        });
    });
    ['closeDepositSlip', 'cancelDepositSlip'].forEach((key) => id(key)?.addEventListener('click', () => id('depositSlipModal').classList.remove('active')));
    id('confirmDepositSlip')?.addEventListener('click', async () => {
        const button = id('confirmDepositSlip');
        try {
            const file = id('depositSlipFile').files?.[0];
            if (!file) throw Error('Attach a photo of the deposit slip before uploading.');
            button.disabled = true;
            const form = new FormData();
            form.set('trip_id', id('depositSlipTripId').value);
            form.set('customer', id('depositSlipCustomerId').value);
            form.set('deposit_slip', file);
            const result = await post('upload_deposit_slip', form);
            id('depositSlipModal')?.classList.remove('active');
            notice(result.message, 'success');
            markDepositSlipUploaded();
        } catch (error) {
            if (error.offlineQueued) {
                // Saved to the outbox with the photo attached -- mark it
                // uploaded optimistically, same as a normal upload.
                id('depositSlipModal')?.classList.remove('active');
                notice(error.message, 'success');
                markDepositSlipUploaded();
                return;
            }
            notice(error.message, 'error');
        } finally {
            button.disabled = false;
        }
    });
});
})();
