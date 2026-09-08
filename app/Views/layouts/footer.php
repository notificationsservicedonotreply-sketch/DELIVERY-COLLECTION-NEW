<?php
requireBootstrapped();
/**
 * Performance: only load the JS each page actually needs, instead of every
 * page loading all eight bundles. $pageScripts is set by the controller
 * (e.g. ['map', 'delivery-collection'] for the delivery/collection portals).
 * jquery and backToTop are used on every admin page, so they always load.
 */
$pageScripts = $pageScripts ?? [];
$scriptMap = [
    'map' => ['assets/leaflet.js', 'assets/CustomerMap.js'],
    'delivery-collection' => ['assets/delivery-collection.js'],
    'customer-tab' => ['assets/CustomerTab.js'],
    'dashboard' => ['assets/dashboard.js'],
    'user-management' => ['assets/user-management.js'],
    'trip-list-assign' => ['assets/trip-list-assign.js'],
    'customer-profile' => ['assets/customer-profile.js'],
    'attachment-viewer' => ['assets/attachment-viewer.js'],
];
$toLoad = ['assets/jquery-3.7.1.min.js'];
foreach ($pageScripts as $key) {
    if (isset($scriptMap[$key])) {
        $toLoad = array_merge($toLoad, $scriptMap[$key]);
    }
}
$toLoad[] = 'assets/backToTop.js';
$toLoad[] = 'assets/pwa-install.js';
$toLoad[] = 'assets/sidebar-toggle.js';
$toLoad[] = 'assets/dynamic-table.js';
?>
<?php foreach ($toLoad as $script): ?>
<script src="<?= adminAsset($script) ?>" defer></script>
<?php endforeach; ?>

</body>

<button id="backToTop" onclick="scrollToTop()">
    <i class="fa-solid fa-chevron-up" aria-hidden="true"></i>
</button>
</html>
