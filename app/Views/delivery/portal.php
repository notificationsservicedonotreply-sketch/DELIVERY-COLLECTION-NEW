<?php requireBootstrapped(); ?>
<main class="main">
    <?php $pageHeading = 'Dashboard'; require APP_ROOT . '/app/Views/layouts/navigation.php'; ?>

    <style>
        .route-map-wrap{margin-top:14px}
        #routeMap{height:45vh;min-height:280px;border-radius:10px;overflow:hidden;border:1px solid #ead7d9}
        .route-pin{background:#a71927;color:#fff;border-radius:50%;width:28px;height:28px;display:flex;align-items:center;justify-content:center;font-weight:bold;font-size:13px;border:2px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,.35)}
        .route-pin--done{background:#198754}
        .route-pin--pulse{animation:route-pin-pulse 1.5s ease-out 1}
        @keyframes route-pin-pulse{0%{box-shadow:0 0 0 0 rgba(167,25,39,.65)}70%{box-shadow:0 0 0 16px rgba(167,25,39,0)}100%{box-shadow:0 0 0 0 rgba(167,25,39,0)}}
        .route-focus-customer{background:none;border:none;padding:0;color:#0d6efd;font-weight:600;text-align:left;cursor:pointer;text-decoration:underline;text-decoration-style:dotted;font-size:inherit}
        .route-focus-customer:hover{color:#0958c9}
        .route-seq-badge{display:inline-block;min-width:22px;padding:2px 6px;border-radius:999px;background:#a71927;color:#fff;font-weight:bold;text-align:center;font-size:12px}
        .delivery-action-cell{display:flex;flex-direction:column;gap:6px;min-width:180px}
        .not-delivered-reason{margin-top:6px;background:#fff5f5;border:1px solid #f3c2c2;border-radius:8px;padding:8px}
        .not-delivered-reason-text{width:100%;resize:vertical;margin-bottom:6px}
        .not-delivered-reason-actions{display:flex;gap:6px;justify-content:flex-end}
        tr.row-not-delivered{opacity:.6}
        .route-modal-content{max-width:920px;width:94vw}
        /* .modal-body is defined in two stylesheets with conflicting rules
           (dashboard.css sets overflow:auto, dashboard_card.css -- loaded
           later, meant for the image-attachment viewer -- sets
           overflow:hidden). The later rule wins by load order, which
           silently clips any modal content taller than the visible area.
           Scoped override so this modal actually scrolls. */
        .route-modal-content .modal-body{display:block;overflow-y:auto;overflow-x:hidden;max-height:80vh;flex:none;min-height:0}
        .route-count-badge{display:inline-flex;align-items:center;justify-content:center;min-width:20px;height:20px;padding:0 6px;margin-left:6px;border-radius:999px;background:#fff;color:#a71927;font-size:11px;font-weight:bold}
        .portal-actions{flex-wrap:wrap;gap:10px}
        .customer-picker{flex:1 1 260px}
    </style>

    <!-- Hero Section -->
    <div class="card portal-hero">
        <div>
            <h1>Delivery Portal</h1>
            <p>Confirm customer location before marking scheduled invoices as delivered.</p>
        </div>
        <span class="portal-status">
            <?= $tripIds ? count($tripIds) . ' assigned trip' . (count($tripIds) === 1 ? '' : 's') : 'No delivery trip schedule' ?>
        </span>
    </div>

    <!-- Message -->
    <div id="portalMessage" class="notice info <?= $customer ? 'dc-hidden' : '' ?>">
        Search for and select a customer to begin.
    </div>
     <br />

    <script>window.deliveryRouteStops = <?= json_encode($route) ?>;</script>

    <!-- Customer Selection -->
    <div class="card customer-search-card">
        <div class="card-title">Search customer</div>
        <div class="portal-actions">
            <div class="customer-picker" style="position:relative;">
                <input id="pageToken" type="hidden" value="Delivery-Portal">
                <input id="customerSearch" class="input" autocomplete="off" placeholder="Type Customer ID or Customer Name">
                <div id="customerResults" class="customer-results dc-hidden" style="position:absolute; top:100%; left:0; right:0; z-index:5000; margin-top:4px; background:#fff; border-radius:8px; box-shadow:0 8px 20px rgba(0,0,0,.15); max-height:280px; overflow-y:auto; overscroll-behavior:contain; -webkit-overflow-scrolling:touch; touch-action:pan-y; scroll-behavior:smooth;" role="listbox" aria-label="Customer search results"></div>
                <small id="customerSearchHint">Type at least 2 characters to search.</small>
            </div>
            <button id="openCustomer" class="btn btn-green"><i class="fa-solid fa-arrow-right" aria-hidden="true"></i> View customer</button>
            <?php if ($route): ?>
            <button id="viewRoute" type="button" class="btn btn-blue">
                <i class="fa-solid fa-route" aria-hidden="true"></i> Delivery route
                <span class="route-count-badge"><?= count($route) ?></span>
            </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Delivery Route Modal -->
    <?php if ($route): ?>
    <div class="custom-modal" id="routeModal">
        <div class="custom-modal-content route-modal-content">
            <div class="modal-header">
                <h2><i class="fa-solid fa-route" aria-hidden="true"></i> Delivery route (<?= count($tripIds) ?> trip<?= count($tripIds) === 1 ? '' : 's' ?>: <?= htmlspecialchars(implode(', ', $tripIds)) ?>)</h2>
                <button class="close-btn" id="closeRoute" aria-label="Close">&times;</button>
            </div>
            <div class="modal-body">
                <div class="route-map-wrap">
                    <div id="routeMap"></div>
                    <small class="form-help">Numbers show visiting order across all your assigned trips. The line follows actual driving directions between stops.</small>
                </div>
                <br />
                <div class="table-wrapper">
                    <table class="table route-table dynamic-table">
                        <thead>
                            <tr>
                                <th data-sortable>#</th>
                                <th data-sortable>Trip</th>
                                <th data-sortable>Customer</th>
                                <th data-sortable>Address</th>
                                <th data-sortable>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($route as $stop):
                                $stopAddress = implode(', ', array_filter([
                                    $stop['Street'] ?? '', $stop['Barangay'] ?? '', $stop['Municipality'] ?? '', $stop['Province'] ?? '',
                                ]));
                                $delivered = (int) ($stop['DeliveredCount'] ?? 0);
                                $notDelivered = (int) ($stop['NotDeliveredCount'] ?? 0);
                                $totalInv = (int) ($stop['InvoiceCount'] ?? 0);
                                $resolved = $delivered + $notDelivered;
                                $isDone = $totalInv > 0 && $resolved >= $totalInv;
                                $isCurrent = $customer && (string) $stop['CustomerId'] === (string) $customer['code'];
                                $statusLabel = $isDone
                                    ? ($notDelivered > 0 ? "Delivered ({$notDelivered} not received)" : 'Delivered')
                                    : "{$resolved}/{$totalInv} resolved";
                            ?>
                            <tr class="<?= $isCurrent ? 'route-row-current' : '' ?>">
                                <td data-label="#"><span class="route-seq-badge"><?= htmlspecialchars((string) $stop['DisplaySeq']) ?></span></td>
                                <td data-label="Trip"><?= htmlspecialchars((string) $stop['TripId']) ?></td>
                                <td data-label="Customer"><button type="button" class="route-focus-customer" data-seq="<?= htmlspecialchars((string) $stop['DisplaySeq']) ?>"><?= htmlspecialchars((string) $stop['CustomerName']) ?> <small>(<?= htmlspecialchars((string) $stop['CustomerId']) ?>)</small></button></td>
                                <td data-label="Address"><?= htmlspecialchars($stopAddress) ?></td>
                                <td data-label="Status"><span class="status-pill <?= $isDone ? 'status-pill--on' : 'status-pill--off' ?>"><?= $statusLabel ?></span></td>
                                <td data-label="Action"><a class="btn btn-blue" href="?page=Delivery-Portal&customer=<?= urlencode((string) $stop['CustomerId']) ?>"><i class="fa-solid fa-arrow-right" aria-hidden="true"></i> Open</a></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Customer Details -->
    <?php if ($customer): ?>
          <!-- JavaScript Configuration -->
        <script>
            window.collectionLocationRequired = <?= $collectionLocationRequired ? 'true' : 'false' ?>;
            window.collectionAccessMessage = <?= json_encode(
                !$locationLock
                    ? 'Location restriction is disabled for this customer. You can now view the delivery details.'
                    : ($customerUnlocked ? 'This customer is unlocked for invoice for delivery viewing.' : '')
            ) ?>;
        </script>

        <input type="hidden" id="selectedCustomer" value="<?= htmlspecialchars($customer['code']) ?>">
        <input type="hidden" id="moduleName" value="delivery">
        <input type="hidden" id="invoiceBalance" value="0">

        <!-- Customer Details -->
        <div class="card customer-card">
            <div class="card-title">Customer details</div>
            <div class="grid">
                <div class="info-box">
                    <label>Customer ID</label>
                    <div class="value"><?= htmlspecialchars($customer['code']) ?></div>
                </div>
                <div class="info-box">
                    <label>Customer Name</label>
                    <div class="value"><?= htmlspecialchars($customer['name']) ?></div>
                </div>
                <div class="info-box">
                    <label>Address</label>
                    <div class="value"><?= htmlspecialchars($address) ?></div>
                </div>
                <div class="info-box">
                    <label>Latitude / Longitude</label>
                    <div class="value"><?= htmlspecialchars($customer['Latitude']) ?>, <?= htmlspecialchars($customer['Longitude']) ?></div>
                </div>
            </div>
            <div id="deliveryRangeNotice" class="notice info">
                Checking your distance from the customer…
            </div>
            <div class="footer-actions">
                <button id="viewMap" class="btn btn-blue"><i class="fa-solid fa-map-location-dot" aria-hidden="true"></i> View map</button>
                <button id="viewDeliveryDetails" class="btn btn-green dc-hidden"><i class="fa-solid fa-truck" aria-hidden="true"></i> Proceed To Delivery</button>
            </div>
        </div>

          <!-- Invoices -->
        <div id="deliveryDetails" class="dc-hidden">
        <div class="card">
            <div class="card-title">Invoices for delivery</div>
            <div class="table-wrapper">
                <table class="table dynamic-table">
                    <thead>
                        <tr>
                            <th data-sortable>Trip ID</th>
                            <th data-sortable>Invoice No.</th>
                            <th data-sortable>DR No.</th>
                            <th data-sortable>Total Cartons</th>
                            <th>Store photo <span class="required-star" aria-label="required">*</span></th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$invoices): ?>
                            <tr>
                                <td colspan="6">No pending invoices assigned to you for this customer.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($invoices as $invoice): ?>
                                <tr data-delivery-invoice>
                                    <td data-label="Trip ID"><?= htmlspecialchars($invoice['TripID']) ?></td>
                                    <td data-label="Invoice No."><?= htmlspecialchars($invoice['InvoiceNo']) ?></td>
                                    <td data-label="DR No."><?= htmlspecialchars($invoice['DrNo'] ?? '') ?></td>
                                    <td data-label="Total Cartons"><?= htmlspecialchars((string) ($invoice['TotalCrtns'] ?? '')) ?></td>
                                    <td data-label="Store photo">
                                        <input
                                            type="file"
                                            class="store-photo-input"
                                            accept="image/jpeg,image/png,image/gif,image/webp"
                                            capture="environment"
                                            required
                                        >
                                    </td>
                                    <td class="delivery-action-cell" data-label="Action">
                                        <button
                                            type="button"
                                            class="btn btn-green confirm-delivery"
                                            data-trip-id="<?= htmlspecialchars($invoice['TripID'], ENT_QUOTES) ?>"
                                            data-invoice-no="<?= htmlspecialchars($invoice['InvoiceNo'], ENT_QUOTES) ?>"
                                            disabled
                                        ><i class="fa-solid fa-circle-check" aria-hidden="true"></i> Confirm delivery</button>
                                        <button
                                            type="button"
                                            class="btn btn-red not-delivered-toggle"
                                            data-trip-id="<?= htmlspecialchars($invoice['TripID'], ENT_QUOTES) ?>"
                                            data-invoice-no="<?= htmlspecialchars($invoice['InvoiceNo'], ENT_QUOTES) ?>"
                                        ><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i> Not received</button>
                                        <div class="not-delivered-reason dc-hidden">
                                            <textarea class="input not-delivered-reason-text" rows="2" maxlength="255" placeholder="Why wasn't this delivery received? e.g. store closed, customer not around, refused delivery"></textarea>
                                            <div class="not-delivered-reason-actions">
                                                <button type="button" class="btn btn-gray not-delivered-cancel">Cancel</button>
                                                <button
                                                    type="button"
                                                    class="btn btn-red not-delivered-submit"
                                                    data-trip-id="<?= htmlspecialchars($invoice['TripID'], ENT_QUOTES) ?>"
                                                    data-invoice-no="<?= htmlspecialchars($invoice['InvoiceNo'], ENT_QUOTES) ?>"
                                                >Save reason</button>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
</div>
         <!-- Map Modal -->
        <div class="custom-modal" id="mapModal">
            <div class="custom-modal-content">
                <div class="modal-header">
                    <h2><i class="fa-solid fa-location-dot" aria-hidden="true"></i> Customer and salesman location</h2>
                    <button class="close-btn" id="closeMap" aria-label="Close">&times;</button>
                </div>
                <div class="modal-body">
                    <div id="dcMap"
                         class="dc-map"
                         data-lat="<?= htmlspecialchars($customer['Latitude']) ?>"
                         data-lng="<?= htmlspecialchars($customer['Longitude']) ?>"
                         data-radius="<?= $radius ?>"
                         data-customer="<?= htmlspecialchars($customer['name']) ?>"
                         data-salesman="<?= htmlspecialchars($_SESSION['SALESMANID'] ?? '') ?>">
                    </div>
                    <div id="mapRangeNotice" class="notice info">
                        <div>Allowed radius: <?= $radius ?> m.</div>
                        <div>Your distance from the customer: <strong id="distanceMeters">Waiting for GPS</strong>.</div>
                        <strong id="rangeState">Waiting for GPS</strong>
                    </div>
                    <a id="directionsLink" class="btn btn-blue" target="_blank" rel="noopener"><i class="fa-solid fa-diamond-turn-right" aria-hidden="true"></i> Open directions</a>
                </div>
            </div>
        </div>
    <?php endif; ?>
</main>
