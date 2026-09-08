<?php requireBootstrapped(); ?>
<main class="main">
    <?php $pageHeading = 'Dashboard'; require APP_ROOT . '/app/Views/layouts/navigation.php'; ?>

    <style>
        .route-map-wrap{margin-top:14px}
        #routeMap{height:45vh;min-height:280px;border-radius:10px;overflow:hidden;border:1px solid #ead7d9}
        .route-pin{background:#a71927;color:#fff;border-radius:50%;width:28px;height:28px;display:flex;align-items:center;justify-content:center;font-weight:bold;font-size:13px;border:2px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,.35)}
        .route-pin--done{background:#198754}
        .route-pin--suggested{background:#0d6efd;box-shadow:0 0 0 4px rgba(13,110,253,.35),0 2px 6px rgba(0,0,0,.35);animation:route-pin-suggested-pulse 1.6s ease-in-out infinite}
        @keyframes route-pin-suggested-pulse{0%,100%{box-shadow:0 0 0 4px rgba(13,110,253,.35),0 2px 6px rgba(0,0,0,.35)}50%{box-shadow:0 0 0 9px rgba(13,110,253,.12),0 2px 6px rgba(0,0,0,.35)}}
        .route-suggestion{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-top:12px;padding:12px 14px;border:1px solid #bcd6ff;border-radius:10px;background:#eef5ff;color:#0a3d91}
        .route-suggestion-text{display:flex;align-items:center;gap:8px;font-weight:600;font-size:13.5px}
        .route-suggestion-text i{color:#0d6efd}
        .route-suggestion-actions{display:flex;gap:8px;flex-wrap:wrap}
        .route-nearest-badge{display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:999px;background:#eef5ff;color:#0d6efd;font-weight:700;font-size:11px;white-space:nowrap}
        @media (max-width:720px){
            .route-suggestion{flex-direction:column;align-items:stretch;text-align:center}
            .route-suggestion-actions{justify-content:center}
        }
        .route-pin--pulse{animation:route-pin-pulse 1.5s ease-out 1}
        @keyframes route-pin-pulse{0%{box-shadow:0 0 0 0 rgba(167,25,39,.65)}70%{box-shadow:0 0 0 16px rgba(167,25,39,0)}100%{box-shadow:0 0 0 0 rgba(167,25,39,0)}}
        .rider-location-marker{width:20px;height:20px;border-radius:50%;background:#0d6efd;border:3px solid #fff;box-shadow:0 0 0 4px rgba(13,110,253,.35),0 2px 8px rgba(0,0,0,.4);animation:rider-location-pulse 2s ease-in-out infinite}
        @keyframes rider-location-pulse{0%,100%{box-shadow:0 0 0 4px rgba(13,110,253,.35),0 2px 8px rgba(0,0,0,.4)}50%{box-shadow:0 0 0 10px rgba(13,110,253,.15),0 2px 8px rgba(0,0,0,.4)}}
        .route-focus-customer{background:none;border:none;padding:0;color:#0d6efd;font-weight:600;text-align:left;cursor:pointer;text-decoration:underline;text-decoration-style:dotted;font-size:inherit}
        .route-focus-customer:hover{color:#0958c9}
        .route-seq-badge{display:inline-block;min-width:22px;padding:2px 6px;border-radius:999px;background:#a71927;color:#fff;font-weight:bold;text-align:center;font-size:12px}
        .delivery-action-cell{display:flex;flex-direction:column;gap:6px;min-width:180px}
        @media (min-width:721px){
            .delivery-action-cell{flex-direction:row;flex-wrap:wrap;align-items:center}
            .delivery-action-cell .not-delivered-reason{flex-basis:100%}
        }
        .not-delivered-reason{margin-top:6px;background:#fff5f5;border:1px solid #f3c2c2;border-radius:8px;padding:8px}
        .not-delivered-reason-text{width:100%;resize:vertical;margin-bottom:6px}
        .not-delivered-reason-actions{display:flex;gap:6px;justify-content:flex-end}
        tr.row-not-delivered{opacity:.6}
        tr.row-pending-delivered{background:#f3fbf6}
        /* On mobile the action cell stretches every child to full width by
           default (flex-direction:column). The badge/"Change" pairing used
           to opt "Change" out of that with align-self:flex-start, which left
           it small and left-aligned while Confirm/Not received stayed full
           width -- inconsistent from a tap-target standpoint. Only apply
           that narrower, left-aligned treatment once the cell switches to a
           row on desktop; on mobile it now stretches like the other
           buttons. */
        .resolution-pending-badge{align-self:center}
        @media (min-width:721px){
            .resolution-undo{align-self:center}
        }
        /* Compact "unsaved" indicator: was a full-width text banner
           ("Marked delivered (unsaved)") that ate most of the row and was
           the main reason "Change" got pushed onto its own line on desktop.
           Now a small pill with the detail in a tooltip -- hover on desktop,
           tap-to-focus on mobile/touch. */
        .status-chip{position:relative;display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:999px;font-size:11px;font-weight:800;white-space:nowrap;cursor:help}
        .status-chip--delivered{background:#e6f4ea;color:#146c43}
        .status-chip--not-received{background:#fdecea;color:#a71927}
        .status-chip[data-tooltip]:hover::after,
        .status-chip[data-tooltip]:focus::after{
            content:attr(data-tooltip);
            position:absolute;
            bottom:calc(100% + 7px);
            left:50%;
            transform:translateX(-50%);
            background:#1f2937;
            color:#fff;
            padding:6px 9px;
            border-radius:6px;
            font-size:11px;
            font-weight:600;
            white-space:normal;
            width:max-content;
            max-width:220px;
            line-height:1.35;
            z-index:20;
            box-shadow:0 4px 10px rgba(0,0,0,.25);
        }
        .status-chip[data-tooltip]:hover::before,
        .status-chip[data-tooltip]:focus::before{
            content:'';
            position:absolute;
            bottom:calc(100% + 2px);
            left:50%;
            transform:translateX(-50%);
            border:5px solid transparent;
            border-top-color:#1f2937;
            z-index:20;
        }
        .deposit-slip-btn--uploaded{background:#146c43}
        .deposit-slip-uploaded-badge{margin-left:5px;padding:1px 7px;border-radius:999px;background:rgba(255,255,255,.28);font-size:10px;font-weight:800;letter-spacing:.02em;text-transform:uppercase}
        /* Route table's "Open"/"Deposit Slip" pair (only 2 short buttons,
           never needs the reason-textarea wrapping the invoice-table action
           cell needs) should always sit side by side -- the table already
           scrolls horizontally on narrow screens, so that's the fallback
           instead of stacking. Specificity here intentionally beats both the
           row/column media queries above and dashboard.css's mobile
           .dynamic-table td[data-label="Action"] column rule. */
        .route-table td.delivery-action-cell{display:flex !important;flex-direction:row !important;flex-wrap:nowrap !important;gap:8px;align-items:center;justify-content:center;min-width:0}
        .route-table td.delivery-action-cell > *{flex:0 0 auto;white-space:nowrap}
        .route-modal-content{width:85%;max-width:none}
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
                    <small class="form-help">Numbers show visiting order across all your assigned trips. The line follows actual driving directions between stops. The pulsing blue dot is your current location, connected to the suggested next stop.</small>
                </div>
                <div id="routeSuggestion" class="route-suggestion dc-hidden">
                    <div class="route-suggestion-text">
                        <i class="fa-solid fa-location-arrow" aria-hidden="true"></i>
                        <span id="routeSuggestionText"></span>
                    </div>
                    <div class="route-suggestion-actions">
                        <button type="button" id="routeSuggestionFocus" class="btn btn-blue">
                            <i class="fa-solid fa-crosshairs" aria-hidden="true"></i> Focus
                        </button>
                        <button type="button" id="routeSuggestionRefresh" class="btn btn-gray">
                            <i class="fa-solid fa-rotate" aria-hidden="true"></i> Refresh
                        </button>
                    </div>
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
                                // Deposit Slip upload is gated on rider type
                                // alone: only a JKAS rider can upload one,
                                // once the stop is fully resolved -- whether
                                // that resolution ended in "Delivered" or
                                // "Delivered (N not received)" no longer
                                // matters.
                                $hasDepositSlip = (int) ($stop['HasDepositSlip'] ?? 0) === 1;
                                $canUploadDepositSlip = $isDone && $isJkasRider;
                                $depositSlipTitle = !$isJkasRider
                                    ? 'Deposit slip upload is only available for JKAS riders.'
                                    : ($canUploadDepositSlip
                                        ? ($hasDepositSlip ? 'Deposit slip already uploaded — tap to view or replace it' : 'Upload the deposit slip for this delivery')
                                        : 'Available once this stop is fully delivered');
                            ?>
                            <tr class="<?= $isCurrent ? 'route-row-current' : '' ?>" data-seq="<?= htmlspecialchars((string) $stop['DisplaySeq']) ?>" data-done="<?= $isDone ? '1' : '0' ?>">
                                <td data-label="#"><span class="route-seq-badge"><?= htmlspecialchars((string) $stop['DisplaySeq']) ?></span> <span class="route-nearest-badge dc-hidden"><i class="fa-solid fa-location-arrow" aria-hidden="true"></i> Nearest</span></td>
                                <td data-label="Trip"><?= htmlspecialchars((string) $stop['TripId']) ?></td>
                                <td data-label="Customer"><button type="button" class="route-focus-customer" data-seq="<?= htmlspecialchars((string) $stop['DisplaySeq']) ?>"><?= htmlspecialchars((string) $stop['CustomerName']) ?> <small>(<?= htmlspecialchars((string) $stop['CustomerId']) ?>)</small></button></td>
                                <td data-label="Address"><?= htmlspecialchars($stopAddress) ?></td>
                                <td data-label="Status"><span class="status-pill <?= $isDone ? 'status-pill--on' : 'status-pill--off' ?>"><?= $statusLabel ?></span></td>
                                <td data-label="Action" class="delivery-action-cell">
                                    <a class="btn btn-blue" href="?page=Delivery-Portal&customer=<?= urlencode((string) $stop['CustomerId']) ?>"><i class="fa-solid fa-arrow-right" aria-hidden="true"></i> Open</a>
                                    <button
                                        type="button"
                                        class="btn <?= $hasDepositSlip ? 'btn-green deposit-slip-btn--uploaded' : 'btn-gray' ?> deposit-slip-btn"
                                        data-trip-id="<?= htmlspecialchars((string) $stop['TripId'], ENT_QUOTES) ?>"
                                        data-customer-id="<?= htmlspecialchars((string) $stop['CustomerId'], ENT_QUOTES) ?>"
                                        data-customer-name="<?= htmlspecialchars((string) $stop['CustomerName'], ENT_QUOTES) ?>"
                                        <?= $canUploadDepositSlip ? '' : 'disabled' ?>
                                        title="<?= htmlspecialchars($depositSlipTitle, ENT_QUOTES) ?>"
                                    ><i class="fa-solid <?= $hasDepositSlip ? 'fa-circle-check' : 'fa-receipt' ?>" aria-hidden="true"></i> Deposit Slip<?= $hasDepositSlip ? ' <span class="deposit-slip-uploaded-badge">Uploaded</span>' : '' ?></button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Upload Deposit Slip Modal -->
    <div class="custom-modal" id="depositSlipModal" role="dialog" aria-modal="true" aria-labelledby="depositSlipTitle">
        <div class="custom-modal-content confirmation-modal">
            <div class="modal-header">
                <h2 id="depositSlipTitle"><i class="fa-solid fa-receipt" aria-hidden="true"></i> Upload Deposit Slip</h2>
                <button class="close-btn" id="closeDepositSlip" aria-label="Close">&times;</button>
            </div>
            <div class="modal-body">
                <p>Upload the deposit slip for <strong id="depositSlipCustomerLabel"></strong> (Trip <span id="depositSlipTripLabel"></span>).</p>
                <input type="hidden" id="depositSlipTripId">
                <input type="hidden" id="depositSlipCustomerId">
                <div id="depositSlipExisting" class="info-box dc-hidden">
                    <label>Already uploaded</label>
                    <a id="depositSlipExistingLink" href="#" target="_blank" rel="noopener">
                        <img id="depositSlipExistingImg" src="" alt="Current deposit slip" class="deposit-slip-preview">
                    </a>
                    <small class="form-help">Choosing a new photo below will replace this as the current deposit slip.</small>
                </div>
                <div class="info-box">
                    <label for="depositSlipFile">Deposit slip photo <span class="required-star" aria-label="required">*</span></label>
                    <input
                        type="file"
                        id="depositSlipFile"
                        accept="image/jpeg,image/png,image/gif,image/webp"
                        capture="environment"
                    >
                </div>
                <div class="footer-actions">
                    <button id="cancelDepositSlip" class="btn btn-blue"><i class="fa-solid fa-xmark" aria-hidden="true"></i> Cancel</button>
                    <button id="confirmDepositSlip" class="btn btn-green"><i class="fa-solid fa-upload" aria-hidden="true"></i> Upload</button>
                </div>
            </div>
        </div>
    </div>
    <style>
        .deposit-slip-preview{max-width:220px;width:100%;border-radius:8px;border:1px solid #ead7d9;display:block;margin-top:6px}
    </style>
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
            // When true, a Collection must be saved (via the embedded modal
            // below) before any "Confirm delivery" button on this page will
            // actually complete the delivery.
            window.deliveryRequiresCollection = <?= $requiresCollection ? 'true' : 'false' ?>;
            <?php if ($requiresCollection): ?>
            window.collectionCategories = <?= json_encode($categories) ?>;
            <?php endif; ?>
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
            <?php if ($requiresCollection): ?>
            <div class="footer-actions">
                <button id="resumeCollection" type="button" class="btn btn-blue dc-hidden"><i class="fa-solid fa-hand-holding-dollar" aria-hidden="true"></i> Resume collection</button>
            </div>
            <?php endif; ?>
        </div>
</div>

        <?php if ($requiresCollection): ?>
        <!-- Collection Portal Modal (required before delivery can be confirmed
             for this rider/customer: UserList.SType = 'JKAS' or
             Customers.SellingType IS NULL). Every invoice is resolved
             locally first ("temporary only"); once all are resolved this
             modal opens automatically, pre-filled with the confirmed
             invoices, and the final submit saves both together. -->
        <div class="custom-modal" id="collectionRequiredModal" role="dialog" aria-modal="true" aria-labelledby="collectionRequiredTitle">
            <div class="custom-modal-content route-modal-content">
                <div class="modal-header">
                    <h2 id="collectionRequiredTitle"><i class="fa-solid fa-hand-holding-dollar" aria-hidden="true"></i> Collection required before delivery</h2>
                    <button class="close-btn" id="closeCollectionRequired" aria-label="Close">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="notice info">This customer requires a collection. Confirm every invoice above, then record the collection here to finish this delivery.</div>

                    <div id="collectionDetails">
                        <!-- Step 1: PR Number -->
                        <div id="collectionStep1" class="card workflow-step pr-number-card">
                            <div class="card-title">
                                <span class="step-number">1</span> PR number <span class="required-star" aria-label="required">*</span>
                            </div>
                            <div class="grid">
                                <div class="info-box">
                                    <input id="prNumber" class="input" required placeholder="Enter PR number" aria-describedby="prNumberHelp">
                                    <small id="prNumberHelp" class="form-help">Required before the collection can be saved.</small>
                                </div>
                            </div>
                        </div>

                        <!-- Step 2: Invoices -->
                        <div id="collectionStep2" class="card workflow-step">
                            <div class="card-title">
                                <span class="step-number">2</span> Invoices with outstanding balance
                                <span class="required-star" aria-label="required">*</span>
                            </div>
                            <div class="portal-actions">
                                <div class="customer-picker" style="position:relative;">
                                    <label for="invoiceSearch">
                                        Search invoice number <span class="required-star" aria-label="required">*</span>
                                    </label>
                                    <input id="invoiceSearch" class="input" autocomplete="off" placeholder="Type at least 2 characters">
                                    <div id="invoiceResults" class="customer-results dc-hidden" style="position:absolute; top:100%; left:0; right:0; z-index:5000; margin-top:4px; background:#fff; border-radius:8px; box-shadow:0 8px 20px rgba(0,0,0,.15); max-height:280px; overflow-y:auto; overscroll-behavior:contain; -webkit-overflow-scrolling:touch; touch-action:pan-y; scroll-behavior:smooth;" role="listbox" aria-label="Invoice search results"></div>
                                    <small id="invoiceSearchHint">Enter an invoice number, then press Enter or select Add invoice.</small>
                                </div>
                                <button id="addInvoice" class="btn btn-blue" type="button"><i class="fa-solid fa-search" aria-hidden="true"></i> Search invoice</button>
                                <button id="addManualInvoiceRow" class="btn btn-blue" type="button"><i class="fa-solid fa-pen-to-square" aria-hidden="true"></i> Add Invoice Manual row</button>
                            </div>
                            <br />
                            <div class="table-wrapper">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th></th>
                                            <th>Invoice</th>
                                            <th>Delivery date</th>
                                            <th>Department</th>
                                            <th>Balance</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody id="invoiceRows">
                                        <tr>
                                            <td colspan="6">Search for an invoice number.</td>
                                        </tr>
                                    </tbody>
                                    <tfoot>
                                        <tr class="table-total">
                                            <td colspan="5">Total selected invoices</td>
                                            <td id="totalOutstandingInvoices">0.00</td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>

                        <!-- Step 3: Collection Details -->
                        <div id="collectionStep3" class="card workflow-step">
                            <div class="card-title">
                                <span class="step-number">3</span> Collection details
                            </div>
                            <p class="form-help">
                                <span class="required-star">*</span> Add at least one payment amount. For Cash, bank initial, check number, and attachment are unavailable. For PDC, all fields are available.
                            </p>
                            <div class="table-wrapper">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Payment type</th>
                                            <th>Bank initial</th>
                                            <th>Check no.</th>
                                            <th>Attachment</th>
                                            <th>Amount <span class="required-star" aria-label="required">*</span></th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody id="paymentRows"></tbody>
                                    <tfoot>
                                        <tr class="table-total">
                                            <td colspan="4">Total collection details</td>
                                            <td id="totalCollectionDetails">0.00</td>
                                            <td></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                            <button id="addPayment" class="btn btn-blue"><i class="fa-solid fa-plus" aria-hidden="true"></i> Add payment row</button>
                        </div>

                        <!-- Step 4: Split Balance -->
                        <div id="collectionStep4" class="card workflow-step">
                            <div class="card-title">
                                <span class="step-number">4</span> Split balance / attachments
                            </div>
                            <p class="form-help">
                                If you enter a split amount, select its category. Categories marked <span class="required-star">*</span> need an attachment.
                            </p>
                            <div class="table-wrapper">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Category</th>
                                            <th>Amount</th>
                                            <th>Reference</th>
                                            <th>Attachment</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody id="splitRows"></tbody>
                                    <tfoot>
                                        <tr class="table-total">
                                            <td>Total split balance</td>
                                            <td id="totalSplitBalance">0.00</td>
                                            <td colspan="3"></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                            <button id="addSplit" class="btn btn-blue"><i class="fa-solid fa-plus" aria-hidden="true"></i> Add split row</button>
                        </div>

                        <!-- Step 5: Summary -->
                        <div id="collectionStep5" class="card workflow-step summary-card">
                            <div class="card-title">
                                <span class="step-number">5</span> Summary
                            </div>
                            <div class="summary-row">
                                <span>Total invoice</span>
                                <strong id="summaryInvoice">0.00</strong>
                            </div>
                            <div class="summary-row">
                                <span>Split amount</span>
                                <strong id="summarySplit">- 0.00</strong>
                            </div>
                            <div class="summary-row">
                                <span>Total collected amount</span>
                                <strong id="summaryCollected">- 0.00</strong>
                            </div>
                            <div class="summary-row total">
                                <span>Total balance</span>
                                <strong id="summaryBalance">0.00</strong>
                            </div>
                            <div class="footer-actions">
                                <button id="completeTransaction" class="btn btn-green" disabled><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> Save collection &amp; complete delivery</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Save Confirmation Modal (shared: confirms the collection about to
             be saved, whether from the standalone Collection Portal or from
             this embedded pre-delivery flow) -->
        <div class="custom-modal" id="saveConfirmModal" role="dialog" aria-modal="true" aria-labelledby="saveConfirmTitle">
            <div class="custom-modal-content confirmation-modal">
                <div class="modal-header">
                    <h2 id="saveConfirmTitle"><i class="fa-solid fa-circle-check" aria-hidden="true"></i> Save collection?</h2>
                    <button class="close-btn" id="closeSaveConfirm">&times;</button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to save this collection?</p>
                    <div id="saveRequirements" class="save-requirements" aria-live="polite">
                        <!-- Dynamic content -->
                    </div>
                    <div class="footer-actions">
                        <button id="cancelSave" class="btn btn-blue"><i class="fa-solid fa-xmark" aria-hidden="true"></i> No</button>
                        <button id="confirmSave" class="btn btn-green"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> Yes, save collection</button>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

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
