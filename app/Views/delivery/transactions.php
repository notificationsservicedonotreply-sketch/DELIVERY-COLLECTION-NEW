<?php requireBootstrapped(); ?>
<main class="main">
    <?php $pageHeading = 'Dashboard'; require APP_ROOT . '/app/Views/layouts/navigation.php'; ?>

    <div class="card transaction-hero">
        <div>
            <h1>Delivery Transactions</h1>
            <p>Review delivered and not-received invoices.</p>
        </div>
    </div>

    <form class="card transaction-filters" method="get">
        <input type="hidden" name="page" value="Delivery-Transactions">
        <div class="card-title">Search option</div>
        <div class="transaction-filter-grid">
            <?php if ($canManageAllTransactions): ?>
            <div class="scope-options" role="radiogroup" aria-label="Search by">
                <label><input type="radio" name="scope" value="rider" <?= $scope === 'rider' ? 'checked' : '' ?>> Salesman</label>
                <label><input type="radio" name="scope" value="customer" <?= $scope === 'customer' ? 'checked' : '' ?>> Customer</label>
                <label><input type="radio" name="scope" value="all" <?= $scope === 'all' ? 'checked' : '' ?>> All</label>
            </div>
            <?php else: ?>
            <div class="transaction-scope-note"><i class="fa-solid fa-user-shield"></i> Showing only your records (Salesman ID: <?= htmlspecialchars($search) ?>).</div>
            <?php endif; ?>
            <?php if ($canManageAllTransactions): ?>
            <label class="filter-field"><span id="searchLabel"><?= ucfirst($scope) ?></span><input class="input" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search Salesman or customer"></label>
            <?php endif; ?>
            <label class="filter-field">From<input class="input" type="date" name="from" value="<?= htmlspecialchars($fromDate) ?>"></label>
            <label class="filter-field">To<input class="input" type="date" name="to" value="<?= htmlspecialchars($toDate) ?>"></label>
            <label class="check-option"><input type="checkbox" name="not_delivered" <?= $notDeliveredOnly ? 'checked' : '' ?>> Not delivered only</label>
            <button class="btn btn-green" type="submit" name="search_records" value="1"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
        </div>
    </form>

    <div class="card transaction-table-card">
        <div class="transaction-table-header"><div class="card-title">Transactions</div><?php if ($hasSearched): ?><span><?= count($transactions) ?> record(s)</span><?php endif; ?></div>
        <div class="table-wrapper transaction-table-wrapper">
            <table class="table transaction-table dynamic-table">
                <thead><tr><th data-sortable>Trip ID</th><th data-sortable>Invoice No.</th><th data-sortable>Customer name</th><th data-sortable>Salesman</th><th data-sortable>Date</th><th data-sortable>Status</th><th></th></tr></thead>
                <tbody>
                <?php if (!$hasSearched): ?>
                    <tr><td colspan="7" class="transaction-empty">Choose any filters you need, then click Search to load transactions.</td></tr>
                <?php elseif (!$transactions): ?>
                    <tr><td colspan="7" class="transaction-empty">No delivery transactions found for the selected filters.</td></tr>
                <?php else: foreach ($transactions as $transaction):
                    $isDelivered = $transaction['DeliveredDate'] !== null;
                ?>
                    <tr>
                        <td data-label="Trip ID"><?= htmlspecialchars($transaction['TripID']) ?></td>
                        <td data-label="Invoice No."><?= htmlspecialchars($transaction['InvoiceNo']) ?></td>
                        <td data-label="Customer name"><?= htmlspecialchars($transaction['CustomerName']) ?></td>
                        <td data-label="Rider"><?= htmlspecialchars($transaction['RiderID']) ?></td>
                        <td data-label="Date"><?= htmlspecialchars(date('m/d/Y g:i A', strtotime((string) $transaction['ResolvedDate']))) ?></td>
                        <td data-label="Status"><span class="confirmation-badge <?= $isDelivered ? 'confirmed' : 'pending' ?>"><?= $isDelivered ? 'Delivered' : 'Not delivered' ?></span></td>
                        <td data-label="Action"><a class="btn btn-blue transaction-view" href="<?= Router::url('Delivery-Transaction-Details') ?>&trip=<?= urlencode($transaction['TripID']) ?>&invoice=<?= urlencode($transaction['InvoiceNo']) ?>"><i class="fa-solid fa-eye"></i> View</a></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>
