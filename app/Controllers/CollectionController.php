<?php
declare(strict_types=1);

class CollectionController
{
    public function portal(): void
    {
        requireBootstrapped();
        global $pdo;

        $repo = new DeliveryCollectionRepository($pdo);
        $customerCode = trim($_GET['customer'] ?? '');
        $customer = $customerCode ? $repo->customer($customerCode) : null;
        $radius = $repo->radius();
        $locationLock = $repo->locationLockForUser((string) ($_SESSION['userID'] ?? ''));
        $customerUnlocked = $customer ? $repo->customerUnlocked($customerCode) : false;
        $collectionLocationRequired = $customer ? $repo->collectionLocationRequired($customerCode, $locationLock) : true;
        $categories = $repo->categories();
        $address = $customer
            ? implode(', ', array_filter([
                $customer['Street'],
                $customer['Barangay'],
                $customer['Municipality'],
                $customer['Province'],
            ]))
            : '';

        View::render('collection/portal', [
            'customer' => $customer,
            'radius' => $radius,
            'customerUnlocked' => $customerUnlocked,
            'collectionLocationRequired' => $collectionLocationRequired,
            'locationLock' => $locationLock,
            'categories' => $categories,
            'address' => $address,
        ], ['map', 'delivery-collection']);
    }

    public function transactions(): void
    {
        requireBootstrapped();
        global $pdo;

        $repo = new DeliveryCollectionRepository($pdo);
        $canManageAllTransactions = hasModuleAccess('Collection-Transactions-Admin');
        $scope = $canManageAllTransactions ? ($_GET['scope'] ?? 'salesman') : 'own';
        if (!in_array($scope, ['salesman', 'customer', 'all', 'own'], true)) $scope = 'salesman';

        $search = $canManageAllTransactions
            ? trim((string) ($_GET['search'] ?? ($scope === 'salesman' ? ($_SESSION['SALESMANID'] ?? '') : '')))
            : (string) ($_SESSION['SALESMANID'] ?? '');
        $fromDate = trim((string) ($_GET['from'] ?? ''));
        $toDate = trim((string) ($_GET['to'] ?? ''));
        $unconfirmedOnly = isset($_GET['unconfirmed']);
        // Do not display records simply by opening the page. The list is loaded only
        // after the user explicitly presses Search, even when no filter is selected.
        $hasSearched = isset($_GET['search_records']);
        $transactions = $hasSearched
            ? $repo->collectionTransactions($scope, $search, $fromDate, $toDate, $unconfirmedOnly)
            : [];

        View::render('collection/transactions', [
            'canManageAllTransactions' => $canManageAllTransactions,
            'scope' => $scope,
            'search' => $search,
            'fromDate' => $fromDate,
            'toDate' => $toDate,
            'unconfirmedOnly' => $unconfirmedOnly,
            'hasSearched' => $hasSearched,
            'transactions' => $transactions,
        ], []);
    }

    public function transactionDetail(): void
    {
        requireBootstrapped();
        global $pdo;

        $repo = new DeliveryCollectionRepository($pdo);
        $reference = trim((string) ($_GET['ref'] ?? ''));
        $transaction = $reference !== '' ? $repo->collectionTransactionDetail($reference) : null;

        if ($transaction && !hasModuleAccess('Collection-Transactions-Admin')
            && !hash_equals((string) ($_SESSION['SALESMANID'] ?? ''), (string) $transaction['SALESMAN'])) {
            http_response_code(403);
            exit('You can only view your own transaction records.');
        }

        if (!$transaction) {
            http_response_code(404);
        }

        $invoiceTotal = array_sum(array_column($transaction['invoices'] ?? [], 'AMOUNT'));
        $categoryTotal = array_sum(array_column($transaction['categories'] ?? [], 'AMOUNT'));
        $paymentTotal = array_sum(array_column($transaction['payments'] ?? [], 'AMOUNT'));

        // Group attachments by their reference type.
        $attachmentImages = ['split' => [], 'payment' => []];
        $attachRefToType = [];
        $attachRefToImages = [];

        foreach ($transaction['categories'] ?? [] as $category) {
            if (!empty($category['ATTACH_REFID'])) {
                $attachRefToType[$category['ATTACH_REFID']] = 'split';
                $attachRefToImages[$category['ATTACH_REFID']] = [];
            }
        }

        foreach ($transaction['payments'] ?? [] as $payment) {
            if (!empty($payment['ATTACH_REFID'])) {
                $attachRefToType[$payment['ATTACH_REFID']] = 'payment';
                $attachRefToImages[$payment['ATTACH_REFID']] = [];
            }
        }

        foreach ($transaction['attachments'] ?? [] as $attachment) {
            $attachRefId = (string) ($attachment['ATTACH_REFID_DTL'] ?? '');
            $filename = (string) ($attachment['FILE_NAME'] ?? '');
            $filePath = str_replace('\\', '/', ltrim((string) ($attachment['FILE_PATH'] ?? ''), '/\\'));

            if ($filePath === '' || strpos($filePath, '..') !== false) continue;

            if (isset($attachRefToType[$attachRefId])) {
                $imageData = [
                    'url' => '../' . implode('/', array_map('rawurlencode', explode('/', $filePath))),
                    'name' => $filename,
                ];
                $attachRefToImages[$attachRefId][] = $imageData;
                $type = $attachRefToType[$attachRefId];
                $attachmentImages[$type][] = $imageData;
            }
        }

        View::render('collection/transaction_detail', [
            'transaction' => $transaction,
            'invoiceTotal' => $invoiceTotal,
            'categoryTotal' => $categoryTotal,
            'paymentTotal' => $paymentTotal,
            'attachRefToImages' => $attachRefToImages,
        ], ['attachment-viewer']);
    }
}

if (!function_exists('hasImages')) {
    /** Whether an ATTACH_REFID has at least one associated image. */
    function hasImages($attachRefId, $attachRefToImages)
    {
        return !empty($attachRefId) && isset($attachRefToImages[$attachRefId]) && count($attachRefToImages[$attachRefId]) > 0;
    }
}
