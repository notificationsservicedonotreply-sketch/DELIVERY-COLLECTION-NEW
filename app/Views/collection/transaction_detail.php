<?php requireBootstrapped(); ?>
<main class="main">
    <?php $pageHeading = 'Dashboard'; require APP_ROOT . '/app/Views/layouts/navigation.php'; ?>

    <div class="card transaction-detail-card">
        <div class="transaction-detail-heading">
            <div>
                <h1>Collection Syntax Details</h1>
                <p>Saved collection transaction details.</p>
            </div>
            <a class="btn btn-blue transaction-back" href="<?= Router::url('Collection-Transactions') ?>"><i class="fa-solid fa-arrow-left"></i> Back to transactions</a>
        </div>

        <?php if (!$transaction): ?>
            <div class="notice error">Collection transaction not found.</div>
        <?php else: ?>
            <section class="detail-header-grid">
                <div class="detail-field"><span>PR #</span><strong><?= htmlspecialchars($transaction['PRNUMBER']) ?></strong></div>
                <div class="detail-field"><span>Salesman ID</span><strong><?= htmlspecialchars($transaction['SALESMAN']) ?></strong></div>
                <div class="detail-field detail-wide"><span>Customer name</span><strong><?= htmlspecialchars($transaction['CustomerName']) ?></strong></div>
                <div class="detail-field detail-wide"><span>Address</span><strong><?= htmlspecialchars($transaction['Address'] ?? '-') ?></strong></div>
                <div class="detail-field"><span>Syntax date</span><strong><?= htmlspecialchars(date('m/d/Y', strtotime($transaction['SyntaxDate'] ?: $transaction['DateReceived']))) ?></strong></div>
            </section>

            <section class="detail-section">
                <div class="detail-section-title"><span>Invoice list</span><strong>Total amount <?= number_format((float) $invoiceTotal, 2) ?></strong></div>
                <div class="table-wrapper"><table class="table detail-table dynamic-table" data-no-search><thead><tr><th>Invoice no.</th><th>Amount</th></tr></thead><tbody>
                    <?php if (!$transaction['invoices']): ?><tr><td colspan="2">No invoices recorded.</td></tr>
                    <?php else: foreach ($transaction['invoices'] as $invoice): ?><tr><td data-label="Invoice no."><?= htmlspecialchars($invoice['INVOICENO']) ?></td><td data-label="Amount"><?= number_format((float) $invoice['AMOUNT'], 2) ?></td></tr><?php endforeach; endif; ?>
                </tbody></table></div>
            </section>

            <section class="detail-section">
                <div class="detail-section-title"><span>Category balance</span><strong>Total amount <?= number_format((float) $categoryTotal, 2) ?></strong></div>
                <div class="table-wrapper"><table class="table detail-table dynamic-table" data-no-search><thead><tr><th>Category</th><th>Reference</th><th>Amount</th><th>Action</th></tr></thead><tbody>
                    <?php if (!$transaction['categories']): ?><tr><td colspan="4">No category balance recorded.</td></tr>
                    <?php else:
                        foreach ($transaction['categories'] as $category):
                            $catAttachRef = $category['ATTACH_REFID'] ?? '';
                            $hasImagesResult = hasImages($catAttachRef, $attachRefToImages);
                            $imagesJson = $hasImagesResult ? json_encode($attachRefToImages[$catAttachRef]) : '[]';
                    ?>
                    <tr>
                        <td data-label="Category"><?= htmlspecialchars($category['CategoryName']) ?></td>
                        <td data-label="Reference"><?= htmlspecialchars($category['OTHERREF'] ?: '-') ?></td>
                        <td data-label="Amount"><?= number_format((float) $category['AMOUNT'], 2) ?></td>
                        <td data-label="Action">
                            <button type="button"
                                class="btn btn-blue transaction-view view-attachment"
                                data-images='<?= htmlspecialchars($imagesJson, ENT_QUOTES, 'UTF-8') ?>'
                                data-image-label="Split attachment"
                                <?= !$hasImagesResult ? 'disabled title="No split image found"' : '' ?>
                            >
                            <i class="fa-solid fa-image"></i> View image</button>
                        </td>
                    </tr>
                    <?php
                        endforeach;
                    endif;
                    ?>
                </tbody></table></div>
            </section>

            <section class="detail-section">
                <div class="detail-section-title"><span>Collection payment details</span><strong>Total amount <?= number_format((float) $paymentTotal, 2) ?></strong></div>
                <div class="table-wrapper"><table class="table detail-table dynamic-table" data-no-search><thead><tr><th>Payment type</th><th>Bank initial</th><th>Check number</th><th>Amount</th><th>Action</th></tr></thead><tbody>
                    <?php if (!$transaction['payments']): ?><tr><td colspan="5">No payment details recorded.</td></tr>
                    <?php else:
                        foreach ($transaction['payments'] as $payment):
                            $payAttachRef = $payment['ATTACH_REFID'] ?? '';
                            $hasImagesResult = hasImages($payAttachRef, $attachRefToImages);
                            $imagesJson = $hasImagesResult ? json_encode($attachRefToImages[$payAttachRef]) : '[]';
                    ?>
                    <tr>
                        <td data-label="Payment type"><?= htmlspecialchars($payment['PAYMENTTYPE']) ?></td>
                        <td data-label="Bank initial"><?= htmlspecialchars($payment['BANKINITIAL'] ?: '-') ?></td>
                        <td data-label="Check number"><?= htmlspecialchars($payment['CHECKNUMBER'] ?: '-') ?></td>
                        <td data-label="Amount"><?= number_format((float) $payment['AMOUNT'], 2) ?></td>
                        <td data-label="Action">
                            <button type="button"
                                class="btn btn-blue transaction-view view-attachment"
                                data-images='<?= htmlspecialchars($imagesJson, ENT_QUOTES, 'UTF-8') ?>'
                                data-image-label="Payment attachment"
                                <?= !$hasImagesResult ? 'disabled title="No payment image found"' : '' ?>
                            >
                            <i class="fa-solid fa-image"></i> View image</button>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody></table></div>
            </section>
        <?php endif; ?>
    </div>
</main>

<!-- Attachment preview modal -->
<div class="custom-modal attachment-modal" id="attachmentModal" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="attachmentModalTitle">
    <div class="custom-modal-content">
        <div class="modal-header">
            <h2 id="attachmentModalTitle">Image preview</h2>
            <button type="button" class="close-btn" id="closeAttachmentModal" aria-label="Close image preview">&times;</button>
        </div>
        <div class="attachment-modal-toolbar">
            <button type="button" class="btn btn-gray" id="previousImage" aria-label="Previous image"><i class="fa-solid fa-chevron-left"></i></button>
            <button type="button" class="btn btn-gray" id="zoomOutImage" aria-label="Zoom out"><i class="fa-solid fa-minus"></i></button>
            <span id="imageZoomLevel">100%</span>
            <button type="button" class="btn btn-blue" id="zoomInImage" aria-label="Zoom in"><i class="fa-solid fa-plus"></i></button>
            <button type="button" class="btn btn-gray" id="nextImage" aria-label="Next image"><i class="fa-solid fa-chevron-right"></i></button>
        </div>
        <div class="modal-body">
            <div class="attachment-image-stage" id="attachmentImageStage">
                <img id="attachmentPreviewImage" src="" alt="Selected attachment preview">
            </div>
        </div>
    </div>
</div>
