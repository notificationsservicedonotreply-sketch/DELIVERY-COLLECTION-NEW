<?php requireBootstrapped(); ?>
<main class="main">
    <?php $pageHeading = 'Dashboard'; require APP_ROOT . '/app/Views/layouts/navigation.php'; ?>

    <div class="settings-tabs" role="tablist">
        <button type="button" class="settings-tab active" data-tab="device" role="tab" aria-selected="true">
            <i class="fa-solid fa-mobile-screen-button" aria-hidden="true"></i> Device
        </button>
        <?php if ($canManageRadius): ?>
        <button type="button" class="settings-tab" data-tab="radius" role="tab" aria-selected="false">
            <i class="fa-solid fa-ruler-combined" aria-hidden="true"></i> Delivery Radius
        </button>
        <?php endif; ?>
        <?php if ($canManageMaintenance): ?>
        <button type="button" class="settings-tab" data-tab="maintenance" role="tab" aria-selected="false">
            <i class="fa-solid fa-screwdriver-wrench" aria-hidden="true"></i> Maintenance
        </button>
        <?php endif; ?>
    </div>

    <!-- Device -->
    <div class="settings-panel active" data-panel="device">
        <div class="card settings-card">
            <div class="card-title"><i class="fa-solid fa-mobile-screen-button" aria-hidden="true"></i> Mobile Display</div>

            <label class="settings-checkbox-row">
                <input type="checkbox" data-fullscreen-toggle>
                <span>Use full screen on mobile devices</span>
            </label>
            <small class="form-help settings-indent">The first tap after reopening enters full screen in supported browsers.</small>

            <div class="settings-divider"></div>

            <div class="settings-label">Install app</div>
            <button type="button" class="btn btn-outline-blue" data-pwa-install>
                <i class="fa-solid fa-mobile-screen-button" aria-hidden="true"></i> Install on this device
            </button>
            <p id="pwaInstallHint" class="form-help">Open this page on the phone, tablet, or computer you want to use, then tap Install.</p>
            <p id="pwaSecurityNotice" class="form-help pwa-security-notice" style="display:none"></p>

            <!--
                Chrome/Edge only fire the one-tap install button after their
                own engagement heuristics are met, and suppress it for a
                while after a prior dismissal -- no website can force that
                prompt to appear. So instead of leaving people stuck when
                the button above says "not available right now", show the
                manual steps for every platform up front, always visible.
            -->
            <div id="pwaManualSteps" class="pwa-manual-steps">
                <div class="pwa-manual-group">
                    <div class="pwa-manual-title"><i class="fa-brands fa-android" aria-hidden="true"></i> Android (Chrome)</div>
                    <ol>
                        <li>Tap the <strong>&#8942;</strong> menu (top right of the browser).</li>
                        <li>Tap <strong>"Add to Home screen"</strong> or <strong>"Install app"</strong>.</li>
                        <li>Confirm by tapping <strong>Install</strong>.</li>
                    </ol>
                </div>
                <div class="pwa-manual-group">
                    <div class="pwa-manual-title"><i class="fa-brands fa-apple" aria-hidden="true"></i> iPhone / iPad (Safari)</div>
                    <ol>
                        <li>Tap the <strong>Share</strong> icon (square with an arrow) in the toolbar.</li>
                        <li>Scroll down and tap <strong>"Add to Home Screen"</strong>.</li>
                        <li>Tap <strong>Add</strong> in the top right.</li>
                    </ol>
                    <small class="form-help">Must be opened in Safari &mdash; Chrome on iOS cannot install to the Home Screen.</small>
                </div>
                <div class="pwa-manual-group">
                    <div class="pwa-manual-title"><i class="fa-solid fa-desktop" aria-hidden="true"></i> Desktop (Chrome / Edge)</div>
                    <ol>
                        <li>Look for the install icon <strong>&#8853;</strong> at the right side of the address bar.</li>
                        <li>If you don't see it, click the <strong>&#8942;</strong> menu &rarr; <strong>"Install MARS Delivery &amp; Collection..."</strong> (Chrome) or <strong>"Apps" &rarr; "Install this site as an app"</strong> (Edge).</li>
                        <li>Confirm by clicking <strong>Install</strong>.</li>
                    </ol>
                </div>
            </div>

            <div class="footer-actions settings-save-row">
                <button type="button" class="btn btn-green" id="saveDeviceSettings">
                    <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> Save settings
                </button>
            </div>
        </div>
    </div>

    <?php if ($canManageRadius): ?>
    <!-- Delivery Radius (admins only) -->
    <div class="settings-panel" data-panel="radius">
        <div class="card settings-card">
            <div class="card-title"><i class="fa-solid fa-ruler-combined" aria-hidden="true"></i> Delivery Radius</div>
            <small class="form-help">How close (in meters) a rider's GPS must be to a customer's saved location before they can confirm a stop. The Delivery and Collection portals each have their own value.</small>

            <div class="settings-divider"></div>

            <label class="settings-label" for="deliveryRadiusInput">Delivery Portal radius (meters)</label>
            <input
                class="input"
                type="number"
                id="deliveryRadiusInput"
                min="1"
                step="1"
                style="width:100%"
                value="<?= htmlspecialchars((string) $radius['delivery']) ?>"
            >

            <div style="height:16px"></div>

            <label class="settings-label" for="collectionRadiusInput">Collection Portal radius (meters)</label>
            <input
                class="input"
                type="number"
                id="collectionRadiusInput"
                min="1"
                step="1"
                style="width:100%"
                value="<?= htmlspecialchars((string) $radius['collection']) ?>"
            >

            <div class="footer-actions settings-save-row">
                <button type="button" class="btn btn-green" id="saveRadiusSettings">
                    <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> Save radius settings
                </button>
            </div>
            <p id="radiusSaveHint" class="form-help"></p>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($canManageMaintenance): ?>
    <!-- System Maintenance (admins only) -->
    <div class="settings-panel" data-panel="maintenance">
        <div class="card settings-card">
            <div class="card-title"><i class="fa-solid fa-screwdriver-wrench" aria-hidden="true"></i> System Maintenance</div>
            <small class="form-help">While on, every user except admins (User Management access) sees a maintenance notice instead of the app.</small>

            <label class="settings-checkbox-row" style="margin-top:14px">
                <input type="checkbox" id="maintenanceModeToggle" <?= $maintenance['on'] ? 'checked' : '' ?>>
                <span>System Maintenance <strong id="maintenanceStatusLabel" style="color:<?= $maintenance['on'] ? '#a11b27' : '#198754' ?>"><?= $maintenance['on'] ? 'ON' : 'OFF' ?></strong></span>
            </label>

            <div class="settings-divider"></div>

            <label class="settings-label" for="maintenanceMessage">Message shown to users</label>
            <textarea
                class="input"
                id="maintenanceMessage"
                rows="3"
                style="width:100%;resize:vertical;font:inherit"
            ><?= htmlspecialchars($maintenance['message']) ?></textarea>

            <div class="footer-actions settings-save-row">
                <button type="button" class="btn btn-green" id="saveMaintenanceSettings">
                    <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> Save maintenance settings
                </button>
            </div>
            <p id="maintenanceSaveHint" class="form-help"></p>
        </div>
    </div>
    <?php endif; ?>
</main>

<style>
    .settings-card{max-width:520px}
    .settings-checkbox-row{display:flex;align-items:center;gap:10px;font-weight:600;margin-top:6px}
    .settings-checkbox-row input{width:18px;height:18px}
    .settings-indent{display:block;margin-left:28px;margin-top:2px}
    .settings-divider{height:1px;background:#ead7d9;margin:18px 0}
    .settings-label{display:block;font-weight:600;color:#8b1621;margin-bottom:8px}
    .settings-save-row{margin-top:16px}
    .btn-outline-blue{background:#fff;color:#0d6efd;border:1px solid #0d6efd;font-weight:600}
    .btn-outline-blue.btn-installed{background:#eafaf1;color:#198754;border:1px solid #b7e4c7;cursor:default}

    .pwa-manual-steps{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:14px;margin-top:16px}
    .pwa-manual-group{background:#faf5f6;border:1px solid #ead7d9;border-radius:10px;padding:12px 14px}
    .pwa-manual-title{font-weight:700;color:#8b1621;margin-bottom:8px;display:flex;align-items:center;gap:8px}
    .pwa-manual-group ol{margin:0;padding-left:18px}
    .pwa-manual-group li{margin-bottom:4px;font-size:13.5px;line-height:1.4}
    .pwa-manual-group li:last-child{margin-bottom:0}
    .pwa-manual-group small{display:block;margin-top:8px}
    .pwa-security-notice{background:#fff4e5;border:1px solid #f3d29b;border-radius:8px;padding:10px 12px;margin-top:10px;color:#7a4a00 !important}
    .pwa-security-notice code{background:#fff;border:1px solid #eadfca;border-radius:4px;padding:1px 5px;font-size:12.5px}

    /* Tabs -- self-contained here rather than borrowed from another page's
       stylesheet, so this page can't be affected by another file's load
       order/caching (see the Trip List Assign header icon fix for why). */
    .settings-tabs{display:flex;gap:4px;margin-bottom:20px;border-bottom:2px solid #f1dfe1;overflow-x:auto;-webkit-overflow-scrolling:touch}
    .settings-tab{flex:0 0 auto;padding:11px 20px;border:none;background:none;font-weight:700;font-size:14px;color:#78686b;cursor:pointer;border-bottom:3px solid transparent;margin-bottom:-2px;white-space:nowrap;transition:.15s}
    .settings-tab:hover{color:#a71927}
    .settings-tab.active{color:#a71927;border-bottom-color:#a71927}
    .settings-panel{display:none}
    .settings-panel.active{display:block}
</style>

<script>
document.querySelectorAll('.settings-tab').forEach((tab) => {
    tab.addEventListener('click', () => {
        document.querySelectorAll('.settings-tab').forEach((t) => { t.classList.remove('active'); t.setAttribute('aria-selected', 'false'); });
        document.querySelectorAll('.settings-panel').forEach((p) => p.classList.remove('active'));
        tab.classList.add('active');
        tab.setAttribute('aria-selected', 'true');
        document.querySelector(`.settings-panel[data-panel="${tab.dataset.tab}"]`)?.classList.add('active');
    });
});

document.getElementById('saveDeviceSettings')?.addEventListener('click', () => {
    // The checkbox already writes to localStorage the instant it's toggled
    // (see pwa-install.js) -- this button exists so the flow reads the same
    // as every other settings screen in the app, and gives clear feedback.
    const el = document.getElementById('pwaInstallHint');
    const original = el.textContent;
    el.textContent = 'Saved.';
    el.style.color = '#198754';
    setTimeout(() => { el.textContent = original; el.style.color = ''; }, 2000);
});

document.getElementById('saveRadiusSettings')?.addEventListener('click', async () => {
    const hint = document.getElementById('radiusSaveHint');
    const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const data = new FormData();
    data.set('action', 'save_radius');
    data.set('csrf_token', token);
    data.set('delivery_radius', document.getElementById('deliveryRadiusInput').value);
    data.set('collection_radius', document.getElementById('collectionRadiusInput').value);
    try {
        const response = await fetch('../Ajax/ajax_settings.php', { method: 'POST', body: data, credentials: 'same-origin' });
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.message || 'Save failed.');
        hint.textContent = 'Saved.';
        hint.style.color = '#198754';
    } catch (error) {
        hint.textContent = error.message;
        hint.style.color = '#a11b27';
    }
    setTimeout(() => { hint.textContent = ''; }, 4000);
});

document.getElementById('maintenanceModeToggle')?.addEventListener('change', (event) => {
    const label = document.getElementById('maintenanceStatusLabel');
    label.textContent = event.target.checked ? 'ON' : 'OFF';
    label.style.color = event.target.checked ? '#a11b27' : '#198754';
});

document.getElementById('saveMaintenanceSettings')?.addEventListener('click', async () => {
    const hint = document.getElementById('maintenanceSaveHint');
    const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const data = new FormData();
    data.set('action', 'save_maintenance');
    data.set('csrf_token', token);
    data.set('maintenance_on', document.getElementById('maintenanceModeToggle').checked ? '1' : '');
    data.set('maintenance_message', document.getElementById('maintenanceMessage').value);
    try {
        const response = await fetch('../Ajax/ajax_settings.php', { method: 'POST', body: data, credentials: 'same-origin' });
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.message || 'Save failed.');
        hint.textContent = 'Saved. This takes effect immediately for other users.';
        hint.style.color = '#198754';
    } catch (error) {
        hint.textContent = error.message;
        hint.style.color = '#a11b27';
    }
    setTimeout(() => { hint.textContent = ''; }, 4000);
});
</script>
