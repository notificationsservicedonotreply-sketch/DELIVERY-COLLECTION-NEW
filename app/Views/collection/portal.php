<?php requireBootstrapped(); ?>
<main class="main">
    <?php $pageHeading = 'Dashboard'; require APP_ROOT . '/app/Views/layouts/navigation.php'; ?>

    <style>
        /* Mirrors the same fix in delivery/portal.php: .modal-body is defined
           in two stylesheets with conflicting rules (dashboard.css sets
           overflow:auto, dashboard_card.css -- loaded later, meant for the
           image-attachment viewer -- sets overflow:hidden). The later rule
           wins by load order, which silently clips any modal content taller
           than the visible area. Scoped override so the Aging modal (which
           can have many rows) actually scrolls. */
        .route-modal-content{width:85%;max-width:none}
        .route-modal-content .modal-body{display:block;overflow-y:auto;overflow-x:hidden;max-height:80vh;flex:none;min-height:0}
        /* Aging modal on mobile: each invoice only ever falls into one aging
           bucket, so the other 5 bucket cells are blank for that row. Hiding
           empty cells keeps the mobile card view to what's actually there
           instead of 6 "PAST X DAYS:" lines with nothing next to them. */
        @media (max-width:720px){ #agingTable td[data-label]:empty{ display:none; } }
    </style>

    <!-- Hero Section -->
    <div class="card portal-hero">
        <div>
            <h1>Collection Portal</h1>
            <p>Search a customer, validate the collection access rule, then record the payment.</p>
        </div>
        <span class="portal-status">
            Salesman: <?= htmlspecialchars($_SESSION['SALESMANID'] ?? '') ?>
        </span>
    </div>

    <!-- Message -->
    <div id="portalMessage" class="notice info <?= $customer ? 'dc-hidden' : '' ?>">
        Search for and select a customer to begin.
    </div>
    <br />

    <!-- Customer Search -->
    <div class="card customer-search-card">
        <div class="card-title">Search customer</div>
        <div class="portal-actions">
            <div class="customer-picker" style="position:relative;">
                <input id="pageToken" type="hidden" value="Collection-Portal">
                <input id="customerSearch" class="input" autocomplete="off" placeholder="Type Customer ID or Customer Name">
                <div id="customerResults" class="customer-results dc-hidden" style="position:absolute; top:100%; left:0; right:0; z-index:5000; margin-top:4px; background:#fff; border-radius:8px; box-shadow:0 8px 20px rgba(0,0,0,.15); max-height:280px; overflow-y:auto; overscroll-behavior:contain; -webkit-overflow-scrolling:touch; touch-action:pan-y; scroll-behavior:smooth;" role="listbox" aria-label="Customer search results"></div>
                <small id="customerSearchHint">Type at least 2 characters to search.</small>
            </div>
            <button id="openCustomer" class="btn btn-green"><i class="fa-solid fa-arrow-right" aria-hidden="true"></i> View customer</button>
        </div>
    </div>

    <?php if ($customer): ?>
        <!-- JavaScript Configuration -->
        <script>
            window.collectionCategories = <?= json_encode($categories) ?>;
            window.collectionLocationRequired = <?= $collectionLocationRequired ? 'true' : 'false' ?>;
            window.collectionAccessMessage = <?= json_encode(
                !$locationLock
                    ? 'Location restriction is disabled for this customer. You can now view the collection details.'
                    : ($customerUnlocked ? 'This customer is unlocked for collection viewing.' : '')
            ) ?>;
        </script>

        <input type="hidden" id="selectedCustomer" value="<?= htmlspecialchars($customer['code']) ?>">
        <input type="hidden" id="moduleName" value="collection">
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
            <div id="collectionRangeNotice" class="notice info">
                Checking your distance from the customer…
            </div>
            <div class="footer-actions">
                <button id="viewMap" class="btn btn-blue"><i class="fa-solid fa-map-location-dot" aria-hidden="true"></i> View map</button>
                <button id="viewCollectionDetails" class="btn btn-green dc-hidden"><i class="fa-solid fa-hand-holding-dollar" aria-hidden="true"></i> Proceed To Collection</button>
                <button id="viewAging" class="btn btn-gray"><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i> Aging</button>
            </div>
        </div>

        <!-- Collection Details -->
        <div id="collectionDetails" class="dc-hidden">

            <!-- Step 1: PR Number -->
            <div id="collectionStep1" class="card workflow-step pr-number-card">
                <div class="card-title">
                    <span class="step-number">1</span> PR number <span class="required-star" aria-label="required">*</span>
                </div>
                <div class="grid">
                    <div class="info-box">
                        <label for="prNumber">
                        </label>
                        <input id="prNumber" class="input" required placeholder="Enter PR number" aria-describedby="prNumberHelp">
                        <small id="prNumberHelp" class="form-help">Required before the collection can be saved.</small>
                    </div>
                </div>
            </div>

            <!-- Step 2: Invoices -->
            <div id="collectionStep2" class="card workflow-step">
                <div class="card-title">
                    <span class="step-number">2</span>
                    Invoices with outstanding balance
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
                    <button id="completeTransaction" class="btn btn-green" disabled><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> Save collection</button>
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

        <!-- Aging of Accounts Receivable Modal -->
        <div class="custom-modal" id="agingModal" role="dialog" aria-modal="true" aria-labelledby="agingModalTitle">
            <div class="custom-modal-content route-modal-content">
                <div class="modal-header">
                    <h2 id="agingModalTitle"><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i> Aging of Accounts Receivable</h2>
                    <button class="close-btn" id="closeAging" aria-label="Close">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="table-wrapper">
                        <table class="table dynamic-table" id="agingTable">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Due Date</th>
                                    <th>RefID</th>
                                    <th>Salesman</th>
                                    <th>Current</th>
                                    <th>Past 30 Days</th>
                                    <th>Past 60 Days</th>
                                    <th>Past 90 Days</th>
                                    <th>Past 120 Day</th>
                                    <th>Past 150 Day</th>
                                    <th>Total</th>
                                </tr>
                            </thead>
                            <tbody id="agingRows">
                                <tr><td colspan="11">Loading…</td></tr>
                            </tbody>
                            <tfoot>
                                <tr class="table-total">
                                    <td colspan="4"><strong>Total</strong></td>
                                    <td id="agingTotalCurrent" data-label="Current">0.00</td>
                                    <td id="agingTotalPast30" data-label="Past 30 Days">0.00</td>
                                    <td id="agingTotalPast60" data-label="Past 60 Days">0.00</td>
                                    <td id="agingTotalPast90" data-label="Past 90 Days">0.00</td>
                                    <td id="agingTotalPast120" data-label="Past 120 Day">0.00</td>
                                    <td id="agingTotalPast150" data-label="Past 150 Day">0.00</td>
                                    <td id="agingTotalGrand" data-label="Grand Total">0.00</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Save Confirmation Modal -->
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
</main>
