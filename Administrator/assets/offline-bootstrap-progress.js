/**
 * offline-bootstrap-progress.js
 * ------------------------------
 * A small "Preparing Offline Mode" overlay shown the first time
 * mars.offline.bootstrap() pulls Customers/Invoices/Trip assignments/
 * Deliveries/Collections down into IndexedDB after a fresh login (see
 * dashboard.js, the only page that calls bootstrap() automatically). Not
 * shown on later, already-cached logins -- those bootstrap() calls are near
 * instant and a flashing progress screen would just be noise.
 *
 * Purely a progress *display*: all the real work (fetching, storing) is
 * still done by bootstrap() in offline-core.js, which this only listens to
 * via mars.offline.onBootstrapProgress().
 */
(function () {
    'use strict';

    if (!window.mars || !window.mars.offline) return;

    let overlay = null;
    let listEl = null;
    let statusEl = null;
    const rows = new Map(); // label -> {row, fill, pct}

    function build(groupLabels) {
        overlay = document.createElement('div');
        overlay.className = 'mars-bootstrap-progress';
        overlay.setAttribute('role', 'status');
        overlay.setAttribute('aria-live', 'polite');

        const card = document.createElement('div');
        card.className = 'mars-bootstrap-progress-card';

        const title = document.createElement('div');
        title.className = 'mars-bootstrap-progress-title';
        title.textContent = 'Preparing Offline Mode';
        card.append(title);

        listEl = document.createElement('div');
        listEl.className = 'mars-bootstrap-progress-list';
        groupLabels.forEach((label) => {
            const row = document.createElement('div');
            row.className = 'mars-bootstrap-progress-row';

            const name = document.createElement('span');
            name.className = 'mars-bootstrap-progress-name';
            name.textContent = label;

            const track = document.createElement('span');
            track.className = 'mars-bootstrap-progress-track';
            const fill = document.createElement('span');
            fill.className = 'mars-bootstrap-progress-fill';
            track.append(fill);

            const pct = document.createElement('span');
            pct.className = 'mars-bootstrap-progress-pct';
            pct.textContent = '0%';

            row.append(name, track, pct);
            listEl.append(row);
            rows.set(label, { row, fill, pct });
        });
        card.append(listEl);

        statusEl = document.createElement('div');
        statusEl.className = 'mars-bootstrap-progress-status';
        statusEl.textContent = 'Downloading your data for offline use…';
        card.append(statusEl);

        overlay.append(card);
        document.body.append(overlay);
        // Next frame, so the CSS transition actually animates in.
        requestAnimationFrame(() => overlay.classList.add('is-visible'));
    }

    function markDone(label) {
        const entry = rows.get(label);
        if (!entry) return;
        entry.fill.style.width = '100%';
        entry.pct.textContent = '100%';
        entry.row.classList.add('is-done');
    }

    function finish() {
        if (!overlay) return;
        statusEl.textContent = 'Offline data ready ✓';
        statusEl.classList.add('is-done');
        setTimeout(() => {
            overlay.classList.remove('is-visible');
            setTimeout(() => { overlay?.remove(); overlay = null; }, 300);
        }, 900);
    }

    // Group labels are known upfront (offline-core.js's
    // BOOTSTRAP_PROGRESS_GROUPS) -- duplicated here just as display labels,
    // not as a data dependency, so this file stays a pure listener.
    const GROUP_LABELS = ['Customers', 'Invoices', 'Trip assignments', 'Deliveries', 'Collections'];

    let built = false;
    window.mars.offline.onBootstrapProgress((progress) => {
        if (progress.done) {
            finish();
            return;
        }
        if (!progress.label || progress.index === 0) return; // "Starting…" tick -- nothing to render yet
        if (!built) { build(GROUP_LABELS); built = true; }
        markDone(progress.label);
        // Every group at/after this one that hasn't reported yet still
        // shows 0% -- nothing to do, they're already rendered that way.
    });

    // Only worth showing on a genuinely first-time bootstrap (no cached
    // data yet at all) -- a warm re-bootstrap on an already-offline-ready
    // device finishes in well under a second and the overlay would just be
    // an unwanted flash. dashboard.js still calls bootstrap() every time;
    // this only decides whether to *show* progress for that call.
    window.mars.offline.getBootstrapMeta().then((meta) => {
        if (meta) window.mars.offline.onBootstrapProgress = window.mars.offline.onBootstrapProgress; // no-op, kept for clarity
    });
})();
