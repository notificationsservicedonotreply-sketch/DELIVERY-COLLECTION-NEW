<?php requireBootstrapped(); ?>
<main class="main">
    <?php $pageHeading = 'Dashboard'; require APP_ROOT . '/app/Views/layouts/navigation.php'; ?>

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
        <p id="pwaInstallHint" class="form-help">Open this page on the phone or tablet you want to use, then tap Install.</p>
    </div>

    <div class="footer-actions settings-save-row">
        <button type="button" class="btn btn-green" id="saveDeviceSettings"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> Save settings</button>
    </div>
</main>

<style>
    .settings-card{max-width:520px}
    .settings-checkbox-row{display:flex;align-items:center;gap:10px;font-weight:600;margin-top:6px}
    .settings-checkbox-row input{width:18px;height:18px}
    .settings-indent{display:block;margin-left:28px;margin-top:2px}
    .settings-divider{height:1px;background:#ead7d9;margin:18px 0}
    .settings-label{font-weight:600;color:#8b1621;margin-bottom:8px}
    .settings-save-row{margin-top:16px}
    .btn-outline-blue{background:#fff;color:#0d6efd;border:1px solid #0d6efd;font-weight:600}
    .btn-outline-blue.btn-installed{background:#eafaf1;color:#198754;border:1px solid #b7e4c7;cursor:default}
</style>

<script>
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
</script>
