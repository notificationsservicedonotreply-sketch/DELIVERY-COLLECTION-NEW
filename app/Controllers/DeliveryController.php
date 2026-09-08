<?php
declare(strict_types=1);

class DeliveryController
{
    public function portal(): void
    {
        requireBootstrapped();
        global $pdo;

        $repo = new DeliveryCollectionRepository($pdo);
        $customerCode = trim($_GET['customer'] ?? '');
        $customer = $customerCode ? $repo->customer($customerCode) : null;
        $radius = $repo->deliveryRadius();
        $locationLock = $repo->locationLockForUser((string) ($_SESSION['userID'] ?? ''));
        $customerUnlocked = $customer ? $repo->customerUnlocked($customerCode) : false;
        $collectionLocationRequired = $customer ? $repo->collectionLocationRequired($customerCode, $locationLock) : true;

        $invoices = $customer ? $repo->assignedDeliveryInvoices((string) ($_SESSION['userID'] ?? ''), $customerCode) : [];
        $tripIds = $repo->assignedTrips((string) ($_SESSION['userID'] ?? ''));
        // Some riders (UserList.SType = 'JKAS') or customers not yet classified
        // (Customers.SellingType IS NULL) must have a Collection recorded
        // before a delivery to them can be confirmed. When true, the Delivery
        // Portal embeds the Collection Portal as a modal in front of "Confirm
        // delivery".
        $requiresCollection = $customer
            ? $repo->deliveryRequiresCollection((string) ($_SESSION['userID'] ?? ''), $customerCode)
            : false;
        // Deposit-slip upload is gated on rider type alone (JKAS), separate
        // from $requiresCollection above -- see DeliveryCollectionRepository::isJkasRider().
        $isJkasRider = $repo->isJkasRider((string) ($_SESSION['userID'] ?? ''));
        $categories = $requiresCollection ? $repo->categories() : [];
        // Route/sequence now spans every trip assigned to the rider, not just
        // the first one -- stops are grouped by TripID (in trip order), and
        // each stop also gets a continuous 1, 2, 3... DisplaySeq across all
        // trips combined, since that's the number shown on the map pins.
        $route = $repo->deliveryRoute($tripIds);
        foreach ($route as $i => &$stop) {
            $stop['DisplaySeq'] = $i + 1;
        }
        unset($stop);
        $address = $customer
            ? implode(', ', array_filter([
                $customer['Street'],
                $customer['Barangay'],
                $customer['Municipality'],
                $customer['Province'],
            ]))
            : '';

        View::render('delivery/portal', [
            'customer' => $customer,
            'radius' => $radius,
            'customerUnlocked' => $customerUnlocked,
            'collectionLocationRequired' => $collectionLocationRequired,
            'locationLock' => $locationLock,
            'invoices' => $invoices,
            'tripIds' => $tripIds,
            'route' => $route,
            'address' => $address,
            'requiresCollection' => $requiresCollection,
            'isJkasRider' => $isJkasRider,
            'categories' => $categories,
        ], ['map', 'delivery-collection', 'status-pill']);
    }

    public function transactions(): void
    {
        requireBootstrapped();
        global $pdo;

        $repo = new DeliveryCollectionRepository($pdo);
        $canManageAllTransactions = hasModuleAccess('Delivery-Transactions-Admin');
        $scope = $canManageAllTransactions ? ($_GET['scope'] ?? 'rider') : 'own';
        if (!in_array($scope, ['rider', 'customer', 'all', 'own'], true)) $scope = 'rider';

        $search = $canManageAllTransactions
            ? trim((string) ($_GET['search'] ?? ($scope === 'rider' ? ($_SESSION['userID'] ?? '') : '')))
            : (string) ($_SESSION['userID'] ?? '');
        $fromDate = trim((string) ($_GET['from'] ?? ''));
        $toDate = trim((string) ($_GET['to'] ?? ''));
        $notDeliveredOnly = isset($_GET['not_delivered']);
        // Same reasoning as Collection-Transactions: don't display records
        // just from opening the page -- only after Search is pressed.
        $hasSearched = isset($_GET['search_records']);
        $transactions = $hasSearched
            ? $repo->deliveryTransactions($scope, $search, $fromDate, $toDate, $notDeliveredOnly)
            : [];

        View::render('delivery/transactions', [
            'canManageAllTransactions' => $canManageAllTransactions,
            'scope' => $scope,
            'search' => $search,
            'fromDate' => $fromDate,
            'toDate' => $toDate,
            'notDeliveredOnly' => $notDeliveredOnly,
            'hasSearched' => $hasSearched,
            'transactions' => $transactions,
        ], []);
    }

    public function transactionDetail(): void
    {
        requireBootstrapped();
        global $pdo;

        $repo = new DeliveryCollectionRepository($pdo);
        $tripId = trim((string) ($_GET['trip'] ?? ''));
        $invoiceNo = trim((string) ($_GET['invoice'] ?? ''));
        $transaction = ($tripId !== '' && $invoiceNo !== '') ? $repo->deliveryTransactionDetail($tripId, $invoiceNo) : null;

        if ($transaction && !hasModuleAccess('Delivery-Transactions-Admin')
            && !hash_equals((string) ($_SESSION['userID'] ?? ''), (string) $transaction['RiderID'])) {
            http_response_code(403);
            exit('You can only view your own delivery records.');
        }

        if (!$transaction) {
            http_response_code(404);
        }

        // Same path-safety handling as CollectionController::transactionDetail():
        // normalize separators, reject anything with a traversal segment, and
        // URL-encode each path segment individually.
        $storePhotoUrl = null;
        if ($transaction && $transaction['storePhoto']) {
            $filePath = str_replace('\\', '/', ltrim((string) ($transaction['storePhoto']['FILE_PATH'] ?? ''), '/\\'));
            if ($filePath !== '' && strpos($filePath, '..') === false) {
                $storePhotoUrl = '../' . implode('/', array_map('rawurlencode', explode('/', $filePath)));
            }
        }

        $depositSlipUrl = null;
        if ($transaction && $transaction['depositSlip']) {
            $filePath = str_replace('\\', '/', ltrim((string) ($transaction['depositSlip']['FILE_PATH'] ?? ''), '/\\'));
            if ($filePath !== '' && strpos($filePath, '..') === false) {
                $depositSlipUrl = '../' . implode('/', array_map('rawurlencode', explode('/', $filePath)));
            }
        }

        View::render('delivery/transaction_detail', [
            'transaction' => $transaction,
            'storePhotoUrl' => $storePhotoUrl,
            'depositSlipUrl' => $depositSlipUrl,
        ], []);
    }
}
