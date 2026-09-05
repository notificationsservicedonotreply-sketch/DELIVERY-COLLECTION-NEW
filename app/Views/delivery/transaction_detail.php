<?php requireBootstrapped(); ?>
<main class="main">
    <?php $pageHeading = 'Dashboard'; require APP_ROOT . '/app/Views/layouts/navigation.php'; ?>

    <div class="card transaction-detail-card">
        <div class="transaction-detail-heading">
            <div>
                <h1>Delivery Details</h1>
                <p>Saved delivery transaction details.</p>
            </div>
            <a class="btn btn-blue transaction-back" href="<?= Router::url('Delivery-Transactions') ?>"><i class="fa-solid fa-arrow-left"></i> Back to transactions</a>
        </div>

        <?php if (!$transaction): ?>
            <div class="notice error">Delivery transaction not found.</div>
        <?php else:
            $isDelivered = $transaction['DeliveredDate'] !== null;
            $resolvedDate = $isDelivered ? $transaction['DeliveredDate'] : $transaction['NotDeliveredDate'];
        ?>
            <section class="detail-header-grid">
                <div class="detail-field"><span>Trip ID</span><strong><?= htmlspecialchars($transaction['TripID']) ?></strong></div>
                <div class="detail-field"><span>Invoice No.</span><strong><?= htmlspecialchars($transaction['InvoiceNo']) ?></strong></div>
                <div class="detail-field"><span>DR No.</span><strong><?= htmlspecialchars($transaction['DrNo'] ?? '-') ?></strong></div>
                <div class="detail-field"><span>Total cartons</span><strong><?= htmlspecialchars((string) ($transaction['TotalCrtns'] ?? '-')) ?></strong></div>
                <div class="detail-field detail-wide"><span>Customer name</span><strong><?= htmlspecialchars($transaction['CustomerName']) ?></strong></div>
                <div class="detail-field detail-wide"><span>Address</span><strong><?= htmlspecialchars($transaction['Address'] ?: '-') ?></strong></div>
                <div class="detail-field"><span>Rider</span><strong><?= htmlspecialchars($transaction['RiderName']) ?> (<?= htmlspecialchars($transaction['RiderID']) ?>)</strong></div>
                <div class="detail-field"><span><?= $isDelivered ? 'Delivered' : 'Not delivered' ?> on</span><strong><?= htmlspecialchars(date('m/d/Y g:i A', strtotime((string) $resolvedDate))) ?></strong></div>
                <div class="detail-field"><span>Status</span><strong><span class="confirmation-badge <?= $isDelivered ? 'confirmed' : 'pending' ?>"><?= $isDelivered ? 'Delivered' : 'Not delivered' ?></span></strong></div>
                <?php if (!empty($transaction['DeliveryLatitude']) && !empty($transaction['DeliveryLongitude'])): ?>
                <div class="detail-field"><span>GPS at confirmation</span><strong><?= htmlspecialchars($transaction['DeliveryLatitude']) ?>, <?= htmlspecialchars($transaction['DeliveryLongitude']) ?></strong></div>
                <?php endif; ?>
            </section>

            <?php if (!$isDelivered): ?>
            <section class="detail-section">
                <div class="detail-section-title"><span>Reason not delivered</span></div>
                <p class="not-delivered-detail-reason"><?= nl2br(htmlspecialchars((string) $transaction['NotDeliveredReason'])) ?></p>
            </section>
            <?php endif; ?>

            <section class="detail-section">
                <div class="detail-section-title"><span>Store photo</span></div>
                <?php if ($storePhotoUrl): ?>
                    <a href="<?= htmlspecialchars($storePhotoUrl) ?>" target="_blank" rel="noopener">
                        <img src="<?= htmlspecialchars($storePhotoUrl) ?>" alt="Store photo" class="store-photo-preview">
                    </a>
                <?php else: ?>
                    <p>No store photo was attached to this delivery.</p>
                <?php endif; ?>
            </section>

            <section class="detail-section">
                <div class="detail-section-title"><span>Deposit slip</span></div>
                <?php if ($depositSlipUrl): ?>
                    <a href="<?= htmlspecialchars($depositSlipUrl) ?>" target="_blank" rel="noopener">
                        <img src="<?= htmlspecialchars($depositSlipUrl) ?>" alt="Deposit slip" class="store-photo-preview">
                    </a>
                <?php else: ?>
                    <p>No deposit slip has been uploaded for this stop yet.</p>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </div>
</main>

<style>
    .not-delivered-detail-reason{background:#fff5f5;border:1px solid #f3c2c2;border-radius:8px;padding:12px;margin:0}
    .store-photo-preview{max-width:320px;width:100%;border-radius:10px;border:1px solid #ead7d9;display:block}
</style>
