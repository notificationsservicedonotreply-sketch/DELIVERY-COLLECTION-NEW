<?php requireBootstrapped(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
<meta name="theme-color" content="#8b1621">
<link rel="manifest" href="../manifest.json">
<link rel="apple-touch-icon" href="assets/images/pwa-icon-apple-touch.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="MARS DC">
<script>window.PWA_BASE_PREFIX = '../';</script>
<!-- Scopes the offline IndexedDB store to this user (see offline-core.js);
     never used for authentication itself, only for local cache isolation
     on shared devices. -->
<script>window.MARS_USER_ID = <?= json_encode((string) ($_SESSION['userID'] ?? '')) ?>;</script>

<title>MARS COLLECTION AND DELIVERY SYSTEM</title>

<!-- Warm up the connection to the FontAwesome CDN before we actually need
     its stylesheet below -- this overlaps the DNS lookup + TLS handshake
     with everything else the browser is doing, instead of paying that
     round-trip serially right when it reaches the <link> tag. On a slow/
     high-latency mobile connection this alone can shave a meaningful chunk
     off first paint, since that stylesheet is render-blocking. -->
<link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
<link rel="dns-prefetch" href="https://cdn.jsdelivr.net">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@7.3.1/css/all.min.css">

<?php
/**
 * Performance: only load the page-specific CSS each page actually needs,
 * mirroring the same $pageScripts-driven approach footer.php already uses
 * for JS. Every page used to load leaflet.css (a map library) and
 * CustomerTab.css (verified unused anywhere in the app) regardless of
 * whether it had any use for them -- that's dead weight and an extra
 * render-blocking request on every single page load.
 *
 * dashboard.css, dashboard_card.css, delivery-collection.css, and
 * red-theme.css stay global: dashboard_card.css carries the shared modal
 * styles (.custom-modal, .modal-header, etc.) used almost everywhere, and
 * delivery-collection.css is needed on 9 of the app's 10 page types, so
 * scoping either out would save little and risks missing a usage.
 */
$pageScripts = $pageScripts ?? [];
$styleMap = [
    'map' => ['assets/leaflet.css'],
    // Split from the 'user-management' JS bundle on purpose: pages like the
    // Delivery Portal and Trip List Assign use the shared .status-pill
    // styling from user-management.css but have no use for the Add/Edit
    // User modal's JS, so pulling in user-management.js there would just be
    // extra unused script to download and parse.
    'status-pill' => ['assets/user-management.css'],
    'user-management' => ['assets/user-management.css'],
    // Trip List Assign and Customer Profile both use .status-pill and the
    // shared .user-management-table/.user-modal-content classes but have no
    // use for user-management.js's Add/Edit User modal wiring -- so they
    // request the CSS via these keys instead of pulling in the JS bundle.
    'trip-list-assign' => ['assets/user-management.css'],
    'customer-profile' => ['assets/user-management.css'],
];
$stylesToLoad = [];
foreach ($pageScripts as $key) {
    if (isset($styleMap[$key])) {
        $stylesToLoad = array_merge($stylesToLoad, $styleMap[$key]);
    }
}
$stylesToLoad = array_unique($stylesToLoad);
foreach ($stylesToLoad as $style):
?>
<link rel="stylesheet" href="<?= adminAsset($style) ?>">
<?php endforeach; ?>
<link rel="stylesheet" href="<?= adminAsset('assets/dashboard.css') ?>">
<link rel="stylesheet" href="<?= adminAsset('assets/dashboard_card.css') ?>">
<link rel="stylesheet" href="<?= adminAsset('assets/delivery-collection.css') ?>">
<link rel="stylesheet" href="<?= adminAsset('assets/red-theme.css') ?>">
<link rel="stylesheet" href="<?= adminAsset('assets/offline-indicator.css') ?>">
<link rel="shortcut icon" href="assets/images/favicon2.ico" />
</head>
<body>
<div class="overlay" id="overlay" onclick="toggleMenu()"></div>
