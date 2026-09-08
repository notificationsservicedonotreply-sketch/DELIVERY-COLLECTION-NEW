<?php

/** Uses the supplied SyntaxDatabase tables for delivery and collection. */
class DeliveryCollectionRepository
{
    private $pdo;

    /** Per-request memoization caches. These values don't change mid-request,
     *  so we avoid re-hitting the database every time a caller asks again. */
    private ?float $radiusCache = null;
    private ?float $deliveryRadiusCache = null;
    private array $locationLockCache = [];
    private array $customerUnlockedCache = [];
    private ?bool $unlockTableExistsCache = null;
    private array $databaseNameColumnCache = [];

    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    /**
     * Whether $table has a DATABASENAME column, used to scope customer/invoice
     * search to the signed-in user's own DATABASENAME (a multi-branch/company
     * partition within this one SQL Server database). Checked per table
     * rather than assumed, so a table that doesn't have this column yet
     * degrades gracefully (unscoped) instead of throwing a fatal SQL error.
     */
    private function hasDatabaseNameColumn(string $table): bool
    {
        if (!array_key_exists($table, $this->databaseNameColumnCache)) {
            $stmt = $this->pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = :table AND COLUMN_NAME = 'DATABASENAME'");
            $stmt->execute([':table' => $table]);
            $this->databaseNameColumnCache[$table] = (bool) $stmt->fetchColumn();
        }
        return $this->databaseNameColumnCache[$table];
    }

    /** GPS validation radius (meters) for the Collection Portal. Editable
     *  from Settings > Delivery Radius -- see SystemSettingsRepository,
     *  which this delegates to (also the source of the safe-default value
     *  used if the setting is missing/invalid). */
    public function radius()
    {
        if ($this->radiusCache !== null) return $this->radiusCache;
        return $this->radiusCache = (new SystemSettingsRepository($this->pdo))->deliveryCollectionRadiusMeters();
    }

    /** GPS validation radius (meters) for the Delivery Portal specifically
     *  -- separate from the Collection Portal's radius() above, since each
     *  portal has its own configurable value in Settings > Delivery Radius. */
    public function deliveryRadius()
    {
        if ($this->deliveryRadiusCache !== null) return $this->deliveryRadiusCache;
        return $this->deliveryRadiusCache = (new SystemSettingsRepository($this->pdo))->deliveryRadiusMeters();
    }

    /** Shared by both the Collection and Delivery portals — always returns at most 20 rows.
     *  Prefix matches (CustomerID/CustomerName LIKE 'query%') are sargable and can use an
     *  index seek, so they're ranked first and returned cheaply. The slower leading-wildcard
     *  substring match only runs to fill any remaining slots up to 20, and is skipped entirely
     *  once 20 prefix matches are already found.
     *  Pair this with: CREATE INDEX IX_Customers_Search ON Customers (CustomerName) INCLUDE (CustomerID);
     *  and the same on CustomerID if it isn't already the clustered/primary key. */
    public function searchCustomers($query, string $dbName = '')
    {
        $query = trim($query);
        $useDbFilter = $dbName !== '' && $this->hasDatabaseNameColumn('Customers');
        $dbFilter1 = $useDbFilter ? ' AND DATABASENAME = :dbname1' : '';
        $dbFilter2 = $useDbFilter ? ' AND DATABASENAME = :dbname2' : '';
        $stmt = $this->pdo->prepare("
            SELECT TOP 20 code, name
            FROM (
                SELECT TOP 20 CustomerID AS code, CustomerName AS name, 1 AS MatchRank
                FROM Customers
                WHERE (CustomerID LIKE :prefix1 OR CustomerName LIKE :prefix2){$dbFilter1}

                UNION ALL

                SELECT TOP 20 CustomerID AS code, CustomerName AS name, 2 AS MatchRank
                FROM Customers
                WHERE (CustomerID LIKE :contains1 OR CustomerName LIKE :contains2)
                  AND NOT (CustomerID LIKE :prefix3 OR CustomerName LIKE :prefix4){$dbFilter2}
            ) Matches
            ORDER BY MatchRank, name
        ");
        $params = [
            ':prefix1' => $query . '%',
            ':prefix2' => $query . '%',
            ':contains1' => '%' . $query . '%',
            ':contains2' => '%' . $query . '%',
            ':prefix3' => $query . '%',
            ':prefix4' => $query . '%',
        ];
        if ($useDbFilter) {
            $params[':dbname1'] = $dbName;
            $params[':dbname2'] = $dbName;
        }
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function CheckNUmberValidation($checknumber)
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) 
            FROM CollectionSyntaxDtl 
            WHERE CHECKNUMBER = :checknumber
        ");

        $stmt->execute([
            ':checknumber' => trim($checknumber)
        ]);

        return (int) $stmt->fetchColumn();
    }


     public function customer($code)
    {
        $stmt = $this->pdo->prepare("SELECT CustomerID AS code, CustomerName AS name, Street, Barangay, Municipality, Province, Latitude, Longitude FROM Customers WHERE CustomerID = :code");
        $stmt->execute([':code' => trim($code)]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** All trip IDs assigned to the delivery user, in a stable display order. */
    public function assignedTrips(string $userId): array
    {
        // Only trips still marked active (Status = 1). Once every invoice in a
        // trip is resolved, syncTripStatus() flips this to 0 -- without this
        // filter, completed trips would keep showing in the route map and the
        // "assigned trips" count forever.
        $stmt = $this->pdo->prepare("SELECT DISTINCT TRIPID FROM TriplistAssign WHERE USERID = :user AND Status = 1 ORDER BY TRIPID");
        $stmt->execute([':user' => $userId]);
        return array_values(array_filter(array_map(
            static fn ($tripId) => trim((string) $tripId),
            $stmt->fetchAll(PDO::FETCH_COLUMN)
        ), static fn ($tripId) => $tripId !== ''));
    }

    /** Customer search scoped to trips assigned to the signed-in delivery user. */
    public function searchAssignedDeliveryCustomers(string $userId, string $query, string $dbName = ''): array
    {
        $dbFilter = ($dbName !== '' && $this->hasDatabaseNameColumn('Customers')) ? ' AND C.DATABASENAME = :dbname' : '';
        $stmt = $this->pdo->prepare("SELECT TOP 20 C.CustomerID AS code, C.CustomerName AS name
            FROM Customers C
            WHERE EXISTS (
                SELECT 1 FROM TripInvoice I
                INNER JOIN TriplistAssign A ON A.TRIPID = I.TRIPID AND A.USERID = :user AND A.Status = 1
                WHERE I.CUSTOMERID = C.CustomerID AND I.DeliveredDate IS NULL AND I.NotDeliveredReason IS NULL
            ) AND (C.CustomerID LIKE :prefix OR C.CustomerName LIKE :prefix){$dbFilter}
            ORDER BY C.CustomerName, C.CustomerID");
        $params = [':user' => $userId, ':prefix' => trim($query) . '%'];
        if ($dbFilter !== '') $params[':dbname'] = $dbName;
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Pending invoices across every trip assigned to this user, ordered for one-by-one confirmation. */
    public function assignedDeliveryInvoices(string $userId, string $customerCode): array
    {
        $stmt = $this->pdo->prepare("SELECT I.TripID, I.InvoiceNo, I.DrNo, I.CustomerID, I.TotalCrtns
            FROM TripInvoice I
            INNER JOIN TriplistAssign A ON A.TRIPID = I.TripID AND A.Status = 1
            WHERE A.USERID = :user AND I.CUSTOMERID = :customer AND I.DeliveredDate IS NULL AND I.NotDeliveredReason IS NULL
            ORDER BY I.TripID, I.InvoiceNo");
        $stmt->execute([':user' => $userId, ':customer' => $customerCode]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function completeAssignedDelivery(string $userId, string $tripId, string $invoiceNo, string $customerId, float $latitude, float $longitude, array $storePhoto): array
    {
        $this->pdo->beginTransaction();
        try {
            $this->applyDeliveryConfirmation($userId, $tripId, $invoiceNo, $customerId, $latitude, $longitude, $storePhoto);

            $count = $this->pdo->prepare("SELECT COUNT(*) FROM TripInvoice I INNER JOIN TriplistAssign A ON A.TRIPID = I.TRIPID WHERE A.USERID = :user AND I.CUSTOMERID = :customer AND I.DeliveredDate IS NULL AND I.NotDeliveredReason IS NULL");
            $count->execute([':user' => $userId, ':customer' => $customerId]);
            $remaining = (int) $count->fetchColumn();

            $this->syncTripStatus($tripId, $userId);

            $this->pdo->commit();
            return ['remaining' => $remaining];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    /**
     * Transaction-agnostic core of completeAssignedDelivery(): updates the
     * TripInvoice row and stores the proof-of-delivery photo, but leaves
     * transaction management (and the "remaining"/trip-status bookkeeping)
     * to the caller. Shared by the single-invoice path above and by
     * saveDeliveriesWithCollection(), which resolves several invoices and
     * saves a Collection in one all-or-nothing transaction.
     */
    private function applyDeliveryConfirmation(string $userId, string $tripId, string $invoiceNo, string $customerId, float $latitude, float $longitude, array $storePhoto): void
    {
        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) throw new RuntimeException('Invalid GPS coordinates.');

        $update = $this->pdo->prepare("UPDATE I SET I.DeliveredDate = GETDATE(), I.Latitude = :latitude, I.Longitude = :longitude
            OUTPUT INSERTED.ID
            FROM TripInvoice I
            WHERE I.TripID = :trip AND I.InvoiceNo = :invoice AND I.CustomerID = :customer
              AND I.DeliveredDate IS NULL AND I.NotDeliveredReason IS NULL
              AND EXISTS (SELECT 1 FROM TriplistAssign A WHERE A.USERID = :user AND A.TRIPID = I.TripID)");
        $update->execute([':latitude' => $latitude, ':longitude' => $longitude, ':trip' => $tripId, ':invoice' => $invoiceNo, ':customer' => $customerId, ':user' => $userId]);
        $tripInvoiceId = $update->fetchColumn();
        if ($tripInvoiceId === false) throw new RuntimeException("Invoice {$invoiceNo} is no longer pending or is not assigned to you.");

        // Proof-of-delivery photo, stored the same way collection payment/split
        // attachments are: on disk under Uploads/, plus a binary copy in
        // FileAttachment. Referenced by the TripInvoice row's own ID so it can
        // never collide with a CollectionSyntaxDtl/Category attachment reference.
        $this->saveDeliveryPhoto((string) $tripInvoiceId, $storePhoto);
    }

    /**
     * Records why a scheduled delivery wasn't received (customer absent,
     * store closed, refused, etc.) as an alternative resolution to actually
     * delivering it. Requires the rider's GPS location, same as a real
     * delivery confirmation, and a non-empty reason. Once every invoice in
     * the trip is either delivered or marked not-received, the trip's
     * TriplistAssign.Status is flipped to False -- see syncTripStatus().
     */
    public function markDeliveryNotReceived(string $userId, string $tripId, string $invoiceNo, string $customerId, float $latitude, float $longitude, string $reason): array
    {
        $this->pdo->beginTransaction();
        try {
            $this->applyNotDeliveredResolution($userId, $tripId, $invoiceNo, $customerId, $latitude, $longitude, $reason);

            $count = $this->pdo->prepare("SELECT COUNT(*) FROM TripInvoice I INNER JOIN TriplistAssign A ON A.TRIPID = I.TRIPID WHERE A.USERID = :user AND I.CUSTOMERID = :customer AND I.DeliveredDate IS NULL AND I.NotDeliveredReason IS NULL");
            $count->execute([':user' => $userId, ':customer' => $customerId]);
            $remaining = (int) $count->fetchColumn();

            $this->syncTripStatus($tripId, $userId);

            $this->pdo->commit();
            return ['remaining' => $remaining];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    /**
     * Transaction-agnostic core of markDeliveryNotReceived() -- see
     * applyDeliveryConfirmation() for why this split exists.
     */
    private function applyNotDeliveredResolution(string $userId, string $tripId, string $invoiceNo, string $customerId, float $latitude, float $longitude, string $reason): void
    {
        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) throw new RuntimeException('Invalid GPS coordinates.');
        $reason = trim($reason);
        if ($reason === '') throw new RuntimeException('A reason is required.');
        if (mb_strlen($reason) > 255) $reason = mb_substr($reason, 0, 255);

        $update = $this->pdo->prepare("UPDATE I SET I.NotDeliveredReason = :reason, I.NotDeliveredDate = GETDATE(), I.Latitude = :latitude, I.Longitude = :longitude
            OUTPUT INSERTED.ID
            FROM TripInvoice I
            WHERE I.TripID = :trip AND I.InvoiceNo = :invoice AND I.CustomerID = :customer
              AND I.DeliveredDate IS NULL AND I.NotDeliveredReason IS NULL
              AND EXISTS (SELECT 1 FROM TriplistAssign A WHERE A.USERID = :user AND A.TRIPID = I.TripID)");
        $update->execute([':reason' => $reason, ':latitude' => $latitude, ':longitude' => $longitude, ':trip' => $tripId, ':invoice' => $invoiceNo, ':customer' => $customerId, ':user' => $userId]);
        if ($update->fetchColumn() === false) throw new RuntimeException("Invoice {$invoiceNo} is no longer pending or is not assigned to you.");
    }

    /**
     * Delivery Portal "requires collection" flow: every invoice for the stop
     * is first resolved locally in the browser (delivered / not received --
     * "temporary only", nothing written to the DB yet). Once every invoice
     * has been resolved, the rider records the Collection, and this method
     * writes BOTH the delivery resolutions and the collection in a single
     * all-or-nothing transaction -- so a problem partway through (e.g. an
     * invoice that changed state elsewhere, or a bad payment row) leaves
     * every TripInvoice row untouched instead of confirming some deliveries
     * without a matching collection.
     *
     * $deliveries: list of
     *   ['trip_id'=>, 'invoice_no'=>, 'customer_id'=>, 'latitude'=>, 'longitude'=>,
     *    'status'=>'delivered'|'not_received', 'reason'=>? (not_received), 'store_photo'=>? (delivered)]
     */
    public function saveDeliveriesWithCollection(
        string $userId,
        array $deliveries,
        $customer,
        $salesman,
        $prNumber,
        $amount,
        $splitAmount,
        array $payments,
        array $splits,
        array $attachments,
        array $invoiceNumbers
    ): array {
        if (!$deliveries) throw new RuntimeException('No delivery confirmations were provided.');

        $this->pdo->beginTransaction();
        try {
            $affectedTrips = [];

            foreach ($deliveries as $delivery) {
                $tripId = trim((string) ($delivery['trip_id'] ?? ''));
                $invoiceNo = trim((string) ($delivery['invoice_no'] ?? ''));
                $customerId = trim((string) ($delivery['customer_id'] ?? $customer));
                $latitude = (float) ($delivery['latitude'] ?? 0);
                $longitude = (float) ($delivery['longitude'] ?? 0);
                $status = (string) ($delivery['status'] ?? '');

                if ($tripId === '' || $invoiceNo === '') throw new RuntimeException('Invalid delivery confirmation data.');

                if ($status === 'delivered') {
                    $storePhoto = $delivery['store_photo'] ?? null;
                    if (!is_array($storePhoto)) throw new RuntimeException("A store photo is required for invoice {$invoiceNo}.");
                    $this->applyDeliveryConfirmation($userId, $tripId, $invoiceNo, $customerId, $latitude, $longitude, $storePhoto);
                } elseif ($status === 'not_received') {
                    $this->applyNotDeliveredResolution($userId, $tripId, $invoiceNo, $customerId, $latitude, $longitude, (string) ($delivery['reason'] ?? ''));
                } else {
                    throw new RuntimeException("Unknown delivery status for invoice {$invoiceNo}.");
                }

                $affectedTrips[$tripId] = true;
            }

            foreach (array_keys($affectedTrips) as $tripId) {
                $this->syncTripStatus($tripId, $userId);
            }

            $refId = $this->persistCollectionRecords($customer, $salesman, $prNumber, $amount, $splitAmount, $payments, $splits, $attachments, $invoiceNumbers);

            $this->pdo->commit();
            return ['reference' => $refId, 'processed' => count($deliveries)];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    /**
     * Flips TriplistAssign.Status from True to False once every invoice in
     * the trip has been resolved (delivered or marked not-received). Called
     * inside the same transaction as each resolution, so the trip closes
     * itself out the moment its last stop is handled -- no separate "end
     * trip" step for the rider.
     */
    private function syncTripStatus(string $tripId, string $userId): void
    {
        $remaining = $this->pdo->prepare("SELECT COUNT(*) FROM TripInvoice WHERE TripID = :trip AND DeliveredDate IS NULL AND NotDeliveredReason IS NULL");
        $remaining->execute([':trip' => $tripId]);
        if ((int) $remaining->fetchColumn() === 0) {
            $this->pdo->prepare("UPDATE TriplistAssign SET Status = 0 WHERE TRIPID = :trip AND USERID = :user")
                ->execute([':trip' => $tripId, ':user' => $userId]);
        }
    }

    private function saveDeliveryPhoto(string $tripInvoiceId, array $file): void
    {
        $attachRef = 'delivery_' . $tripInvoiceId;
        $absolutePath = APP_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string) $file['filepath']);
        $imageData = (is_file($absolutePath) && is_readable($absolutePath)) ? file_get_contents($absolutePath) : null;

        $stmt = $this->pdo->prepare("
            INSERT INTO FileAttachment (ATTACH_REFID_DTL, FILE_NAME, FILE_PATH, FILE_TYPE, FILE_SIZE, IMAGE_ATTACH)
            VALUES (:ref, :name, :path, :type, :size, :image)
        ");
        $stmt->bindParam(':ref', $attachRef, PDO::PARAM_STR);
        $stmt->bindParam(':name', $file['filename'], PDO::PARAM_STR);
        $stmt->bindParam(':path', $file['filepath'], PDO::PARAM_STR);
        $stmt->bindParam(':type', $file['filetype'], PDO::PARAM_STR);
        $stmt->bindParam(':size', $file['filesize'], PDO::PARAM_INT);
        $stmt->bindParam(':image', $imageData, PDO::PARAM_LOB, 0, PDO::SQLSRV_ENCODING_BINARY);
        $stmt->execute();
    }

    /**
     * Saves a rider-uploaded deposit slip for one delivery stop (a
     * TripID + CustomerID pair, which may cover several invoices), the same
     * way store-delivery photos are saved: on disk under Uploads/, plus a
     * binary copy in FileAttachment. Only allowed for a JKAS rider once
     * every invoice assigned to that rider for that trip/customer has been
     * resolved (delivered OR not received) -- matches the "Deposit Slip"
     * button's enabled state in delivery/portal.php -- re-checked here since
     * the client-side disabled state is not itself a security boundary.
     */
    public function saveDepositSlip(string $userId, string $tripId, string $customerId, array $file): void
    {
        if (!$this->isJkasRider($userId)) {
            throw new RuntimeException('Deposit slip upload is only available for JKAS riders.');
        }

        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) AS Total,
                   SUM(CASE WHEN I.DeliveredDate IS NOT NULL THEN 1 ELSE 0 END) AS Delivered,
                   SUM(CASE WHEN I.NotDeliveredReason IS NOT NULL THEN 1 ELSE 0 END) AS NotDelivered
            FROM TripInvoice I
            INNER JOIN TriplistAssign A ON A.TRIPID = I.TripID
            WHERE A.USERID = :user AND I.TripID = :trip AND I.CustomerID = :customer
        ");
        $stmt->execute([':user' => $userId, ':trip' => $tripId, ':customer' => $customerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $total = (int) ($row['Total'] ?? 0);
        $delivered = (int) ($row['Delivered'] ?? 0);
        $notDelivered = (int) ($row['NotDelivered'] ?? 0);
        $resolved = $delivered + $notDelivered;

        if ($total === 0) throw new RuntimeException('This delivery stop was not found or is not assigned to you.');
        if ($resolved < $total) {
            throw new RuntimeException('A deposit slip can only be uploaded once every invoice for this stop has been resolved (delivered or not received).');
        }

        $attachRef = 'depositslip_' . $tripId . '_' . $customerId;
        $absolutePath = APP_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string) $file['filepath']);
        $imageData = (is_file($absolutePath) && is_readable($absolutePath)) ? file_get_contents($absolutePath) : null;

        $stmt = $this->pdo->prepare("
            INSERT INTO FileAttachment (ATTACH_REFID_DTL, FILE_NAME, FILE_PATH, FILE_TYPE, FILE_SIZE, IMAGE_ATTACH)
            VALUES (:ref, :name, :path, :type, :size, :image)
        ");
        $stmt->bindParam(':ref', $attachRef, PDO::PARAM_STR);
        $stmt->bindParam(':name', $file['filename'], PDO::PARAM_STR);
        $stmt->bindParam(':path', $file['filepath'], PDO::PARAM_STR);
        $stmt->bindParam(':type', $file['filetype'], PDO::PARAM_STR);
        $stmt->bindParam(':size', $file['filesize'], PDO::PARAM_INT);
        $stmt->bindParam(':image', $imageData, PDO::PARAM_LOB, 0, PDO::SQLSRV_ENCODING_BINARY);
        $stmt->execute();
    }

    /**
     * Ordered delivery stops across every trip assigned to the rider: one row
     * per (trip, SortNum, customer), joined with Customers for the
     * name/address to display, and aggregated so a customer with several
     * invoices in the same trip still shows as a single stop. Relies on
     * TripInvoice.SortNum, which is already populated by whatever
     * assigns/plans the trip. Trips are ordered by TripID, and stops within
     * each trip keep their own SortNum -- the controller adds a
     * continuous 1, 2, 3... display number across all of them for the map.
     */
    public function deliveryRoute(array $tripIds): array
    {
        if (!$tripIds) return [];
        $placeholders = implode(',', array_fill(0, count($tripIds), '?'));
        $stmt = $this->pdo->prepare("
            SELECT
                I.TripID AS TripId,
                I.SortNum AS SequenceNo,
                I.CustomerID AS CustomerId,
                C.CustomerName AS CustomerName,
                C.Street, C.Barangay, C.Municipality, C.Province,
                C.Latitude, C.Longitude,
                COUNT(*) AS InvoiceCount,
                SUM(CASE WHEN I.DeliveredDate IS NOT NULL THEN 1 ELSE 0 END) AS DeliveredCount,
                SUM(CASE WHEN I.NotDeliveredReason IS NOT NULL THEN 1 ELSE 0 END) AS NotDeliveredCount,
                CASE WHEN EXISTS (
                    SELECT 1 FROM FileAttachment FA
                    WHERE FA.ATTACH_REFID_DTL = CONCAT('depositslip_', I.TripID, '_', I.CustomerID)
                ) THEN 1 ELSE 0 END AS HasDepositSlip
            FROM TripInvoice I
            INNER JOIN Customers C ON C.CustomerID = I.CustomerID
            WHERE I.TripID IN ({$placeholders})
            GROUP BY I.TripID, I.SortNum, I.CustomerID, C.CustomerName, C.Street, C.Barangay, C.Municipality, C.Province, C.Latitude, C.Longitude
            ORDER BY I.TripID, I.SortNum
        ");
        $stmt->execute($tripIds);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Returns collection-eligible invoices only: outstanding and not previously collected. */
    public function collectionInvoices($customerCode, $query = '')
    {
        $sql = "SELECT I.REFID AS InvoiceNo, I.REFID AS DrNo, I.BALANCE AS Balance, I.DELIVERYDATE AS DeliveryDate, I.DEPARTMENT, I.DATABASENAME
                FROM InvoiceList I
                WHERE I.CUSTOMERID = :customer
                  AND I.BALANCE > 0
                  AND NOT EXISTS (SELECT 1 FROM CollectionSyntaxInvDtl C WHERE C.INVOICENO = I.REFID)";
        $params = [':customer' => trim($customerCode)];
        if (trim($query) !== '') {
            $sql .= ' AND I.REFID LIKE :query';
            $params[':query'] = '%' . trim($query) . '%';
        }
        $sql .= ' ORDER BY I.INVOICEDATE, I.REFID';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Searches the invoice list while reporting invoices already recorded for collection. */
    public function searchCollectionInvoices($customerCode, $query, string $dbName = '')
    {
        $sql = "SELECT I.REFID AS InvoiceNo, I.REFID AS DrNo, I.BALANCE AS Balance, I.DELIVERYDATE AS DeliveryDate, I.DEPARTMENT, I.DATABASENAME,
            CASE WHEN EXISTS (SELECT 1 FROM CollectionSyntaxInvDtl C WHERE C.INVOICENO = I.REFID) THEN CAST(1 AS bit) ELSE CAST(0 AS bit) END AS AlreadyCollected
            FROM InvoiceList I WHERE I.CUSTOMERID = :customer AND I.REFID LIKE :query";
        $params = [':customer' => trim($customerCode), ':query' => '%' . trim($query) . '%'];
        if ($dbName !== '') {
            $sql .= ' AND I.DATABASENAME = :dbname';
            $params[':dbname'] = $dbName;
        }
        $sql .= ' ORDER BY I.INVOICEDATE, I.REFID';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Looks up InvoiceList details for a specific list of invoice numbers in
     * one round trip (used once every TripInvoice row for a stop has been
     * confirmed delivered, to pre-fill the "Invoices with outstanding
     * balance" step instead of querying one invoice at a time).
     */
    public function collectionInvoicesByNumbers(string $customerCode, array $invoiceNumbers, string $dbName = ''): array
    {
        $invoiceNumbers = array_values(array_unique(array_filter(array_map('trim', $invoiceNumbers), fn($v) => $v !== '')));
        if (!$invoiceNumbers) return [];

        $placeholders = [];
        $params = [':customer' => trim($customerCode)];
        foreach ($invoiceNumbers as $i => $invoiceNo) {
            $key = ":inv{$i}";
            $placeholders[] = $key;
            $params[$key] = $invoiceNo;
        }

        $sql = "SELECT I.REFID AS InvoiceNo, I.REFID AS DrNo, I.BALANCE AS Balance, I.DELIVERYDATE AS DeliveryDate, I.DEPARTMENT, I.DATABASENAME,
            CASE WHEN EXISTS (SELECT 1 FROM CollectionSyntaxInvDtl C WHERE C.INVOICENO = I.REFID) THEN CAST(1 AS bit) ELSE CAST(0 AS bit) END AS AlreadyCollected
            FROM InvoiceList I WHERE I.CUSTOMERID = :customer AND I.REFID IN (" . implode(',', $placeholders) . ')';
        if ($dbName !== '') {
            $sql .= ' AND I.DATABASENAME = :dbname';
            $params[':dbname'] = $dbName;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function collectionInvoiceExists(string $invoiceNumber): bool
    {
        $stmt = $this->pdo->prepare('SELECT TOP 1 1 FROM CollectionSyntaxInvDtl WHERE INVOICENO = :invoice');
        $stmt->execute([':invoice' => $invoiceNumber]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Aging of Accounts Receivable for one customer, bucketed by how many
     * days past due each outstanding InvoiceList row is.
     *
     * InvoiceList has no due-date column, so the due date is assumed to be
     * standard Net 30 terms (InvoiceDate + 30 days). Update this if the
     * business has a real payment-terms source to read from instead.
     *
     * The displayed "Date" column uses DeliveryDate when it is present and
     * later than InvoiceDate (a late delivery), otherwise it falls back to
     * InvoiceDate.
     */
    public function agingReceivables(string $customerCode): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT REFID, INVOICEDATE, DELIVERYDATE, SALESMANID, BALANCE
             FROM InvoiceList
             WHERE CUSTOMERID = :customer AND BALANCE > 0
             ORDER BY INVOICEDATE, REFID"
        );
        $stmt->execute([':customer' => trim($customerCode)]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $today = new DateTimeImmutable('today');
        $buckets = ['current' => 0.0, 'past30' => 0.0, 'past60' => 0.0, 'past90' => 0.0, 'past120' => 0.0, 'past150' => 0.0];
        $items = [];

        foreach ($rows as $row) {
            $invoiceDate = new DateTimeImmutable((string) $row['INVOICEDATE']);

            // DeliveryDate can be blank/null, and when a delivery is late it
            // can also fall after the invoice date. Use DeliveryDate only
            // when it is present and later than InvoiceDate; otherwise fall
            // back to InvoiceDate.
            $deliveryDateRaw = $row['DELIVERYDATE'] ?? null;
            $deliveryDate = $deliveryDateRaw !== null && trim((string) $deliveryDateRaw) !== ''
                ? new DateTimeImmutable((string) $deliveryDateRaw)
                : null;
            $effectiveDate = ($deliveryDate !== null && $deliveryDate > $invoiceDate) ? $deliveryDate : $invoiceDate;

            $dueDate = $invoiceDate->modify('+30 days'); // Net 30 assumption -- see method doc comment.
            $daysPastDue = (int) floor(($today->getTimestamp() - $dueDate->getTimestamp()) / 86400);
            $balance = (float) $row['BALANCE'];

            if ($daysPastDue <= 0) {
                $bucket = 'current';
            } elseif ($daysPastDue <= 30) {
                $bucket = 'past30';
            } elseif ($daysPastDue <= 60) {
                $bucket = 'past60';
            } elseif ($daysPastDue <= 90) {
                $bucket = 'past90';
            } elseif ($daysPastDue <= 120) {
                $bucket = 'past120';
            } else {
                $bucket = 'past150';
            }

            $buckets[$bucket] += $balance;

            $items[] = [
                'refid' => (string) $row['REFID'],
                'date' => $effectiveDate->format('m/d/Y'),
                'due_date' => $dueDate->format('m/d/Y'),
                'salesman' => (string) $row['SALESMANID'],
                'balance' => $balance,
                'bucket' => $bucket,
            ];
        }

        return [
            'items' => $items,
            'totals' => $buckets + ['total' => array_sum($buckets)],
        ];
    }

    public function locationLockForUser($userId): bool
    {
        $userId = trim((string) $userId);
        if (array_key_exists($userId, $this->locationLockCache)) return $this->locationLockCache[$userId];
        $stmt = $this->pdo->prepare('SELECT LocationLock FROM UserList WHERE USERID = :user');
        $stmt->execute([':user' => $userId]);
        return $this->locationLockCache[$userId] = (bool) $stmt->fetchColumn();
    }

    /**
     * Whether a delivery for this rider/customer combination must have a
     * Collection recorded before it can be confirmed. True when either:
     *  - the rider's UserList.SType is 'JKAS', or
     *  - the customer's Customers.SellingType is NULL/blank (not yet classified).
     */
    public function deliveryRequiresCollection(string $userId, string $customerCode): bool
    {
        $customerCode = trim($customerCode);
        if (trim($userId) === '' || $customerCode === '') return false;

        $isJkas = $this->isJkasRider($userId);

        $stmt = $this->pdo->prepare('SELECT SellingType FROM Customers WHERE CustomerID = :customer');
        $stmt->execute([':customer' => $customerCode]);
        $sellingType = $stmt->fetchColumn();
        $sellingTypeIsNull = $sellingType === false || $sellingType === null || trim((string) $sellingType) === '';

        return $isJkas || $sellingTypeIsNull;
    }

    /**
     * Whether this rider's UserList.SType is 'JKAS'. This is also the only
     * rider type allowed to upload a stop's deposit slip -- a distinct rule
     * from deliveryRequiresCollection() above, which additionally covers
     * customers with no SellingType set yet.
     */
    public function isJkasRider(string $userId): bool
    {
        $userId = trim($userId);
        if ($userId === '') return false;
        $stmt = $this->pdo->prepare('SELECT SType FROM UserList WHERE USERID = :user');
        $stmt->execute([':user' => $userId]);
        $sType = $stmt->fetchColumn();
        return is_string($sType) && strcasecmp(trim($sType), 'JKAS') === 0;
    }

    public function categories()
    {
        return $this->pdo->query("SELECT catid, CATEGORY AS CategoryName, Req_attach AS RequiresAttachment FROM SplitCategory ORDER BY CATEGORY")->fetchAll(PDO::FETCH_ASSOC);
    }

    public function collectionCount($customerCode)
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM CollectionSyntaxHdr WHERE CUSTOMERID = :customer");
        $stmt->execute([':customer' => $customerCode]);
        return (int) $stmt->fetchColumn();
    }

    /** Returns saved collection transactions for the transaction-history screen. */
    public function collectionTransactions(string $scope, string $search, string $fromDate, string $toDate, bool $unconfirmedOnly): array
    {
        $sql = "SELECT H.PRNUMBER, H.CUSTOMERID, COALESCE(C.CustomerName, H.CUSTOMERID) AS CustomerName,
                       H.SALESMAN, H.DateReceived, H.PR_CONFIRMED, H.SYNTAXREFERENCE,
                       COALESCE(P.TotalAmount, 0) AS Amount
                FROM CollectionSyntaxHdr H
                LEFT JOIN Customers C ON C.CustomerID = H.CUSTOMERID
                OUTER APPLY (SELECT SUM(AMOUNT) AS TotalAmount FROM CollectionSyntaxDtl WHERE REFID = H.SYNTAXREFERENCE) P
                WHERE 1 = 1";
        $params = [];

        // Dates are optional. Applying an implicit current-month range made
        // valid saved records appear to be missing from transaction history.
        if ($fromDate !== '') {
            $sql .= ' AND H.DateReceived >= :fromDate';
            $params[':fromDate'] = $fromDate;
        }
        if ($toDate !== '') {
            $sql .= ' AND H.DateReceived < DATEADD(day, 1, :toDate)';
            $params[':toDate'] = $toDate;
        }

        if ($scope === 'own') {
            // A salesman scope must be an exact match: LIKE would allow a
            // user with ID "12" to see transactions owned by "123".
            $sql .= ' AND H.SALESMAN = :salesman';
            $params[':salesman'] = $search;
        } elseif ($scope === 'salesman' && $search !== '') {
            $sql .= ' AND H.SALESMAN LIKE :search';
            $params[':search'] = '%' . $search . '%';
        } elseif ($scope === 'customer' && $search !== '') {
            $sql .= ' AND (H.CUSTOMERID LIKE :search OR C.CustomerName LIKE :search1)';
            $params[':search'] = '%' . $search . '%';
            $params[':search1'] = '%' . $search . '%';
        }

        if ($unconfirmedOnly) $sql .= " AND ISNULL(H.PR_CONFIRMED, '') <> 'Y'";
        $sql .= ' ORDER BY H.DateReceived DESC, H.PRNUMBER DESC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Returns the full read-only detail for a saved collection reference. */
    public function collectionTransactionDetail(string $reference): ?array
    {
        $header = $this->pdo->prepare("SELECT
            H.PRNUMBER,
            H.SALESMAN,
            H.SYNTAXREFERENCE,
            H.DateReceived,
            H.SyntaxDate,
            COALESCE(C.CustomerName, H.CUSTOMERID) AS CustomerName,
            STUFF(
                CASE WHEN NULLIF(C.Street, '') IS NOT NULL THEN ', ' + C.Street ELSE '' END +
                CASE WHEN NULLIF(C.Barangay, '') IS NOT NULL THEN ', ' + C.Barangay ELSE '' END +
                CASE WHEN NULLIF(C.Municipality, '') IS NOT NULL THEN ', ' + C.Municipality ELSE '' END +
                CASE WHEN NULLIF(C.Province, '') IS NOT NULL THEN ', ' + C.Province ELSE '' END,
                1, 2, ''
            ) AS Address
        FROM CollectionSyntaxHdr H
        LEFT JOIN Customers C
            ON C.CustomerID = H.CUSTOMERID
        WHERE H.SYNTAXREFERENCE = :reference;");
        $header->execute([':reference' => $reference]);
        $transaction = $header->fetch(PDO::FETCH_ASSOC);
        if (!$transaction) return null;

        $invoices = $this->pdo->prepare('SELECT INVOICENO, AMOUNT FROM CollectionSyntaxInvDtl WHERE REFID = :reference ORDER BY ID');
        $invoices->execute([':reference' => $reference]);

        $categories = $this->pdo->prepare("SELECT 
            COALESCE(S.CATEGORY, CAST(C.CATID AS varchar(20))) AS CategoryName, 
            C.AMOUNT, 
            C.OTHERREF,
            C.ATTACH_REFID
            FROM CollectionSyntaxCategory C 
            LEFT JOIN SplitCategory S ON S.catid = C.CATID 
            WHERE C.REFID = :reference ORDER BY C.ID");
        $categories->execute([':reference' => $reference]);

        // Get payments with their attachments
        $payments = $this->pdo->prepare('SELECT
            CS.ID,
            CS.REFID,
            CS.PAYMENTTYPE,
            CS.BANKINITIAL,
            CS.CHECKNUMBER,
            CS.ATTACH_REFID,
            CS.AMOUNT
        FROM CollectionSyntaxDtl AS CS
        WHERE CS.REFID = :reference');
        $payments->execute([':reference' => $reference]);
        $paymentResults = $payments->fetchAll(PDO::FETCH_ASSOC);

        // Collect all ATTACH_REFID from both categories and payments
        $attachRefs = [];
        $categoryResults = $categories->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($categoryResults as $cat) {
            if (!empty($cat['ATTACH_REFID'])) {
                $attachRefs[] = $cat['ATTACH_REFID'];
            }
        }
        
        foreach ($paymentResults as $pay) {
            if (!empty($pay['ATTACH_REFID'])) {
                $attachRefs[] = $pay['ATTACH_REFID'];
            }
        }

        // Get all attachments for these ATTACH_REFIDs
        $attachments = [];
        if (!empty($attachRefs)) {
            $placeholders = implode(',', array_fill(0, count($attachRefs), '?'));
            $attachmentsQuery = $this->pdo->prepare("SELECT 
                PK_ATTACH, 
                ATTACH_REFID_DTL, 
                FILE_NAME, 
                FILE_PATH, 
                FILE_SIZE, 
                FILE_TYPE, 
                DATE_CREATED
            FROM FileAttachment 
            WHERE ATTACH_REFID_DTL IN ($placeholders) 
            ORDER BY DATE_CREATED DESC, PK_ATTACH DESC");
            $attachmentsQuery->execute($attachRefs);
            $attachments = $attachmentsQuery->fetchAll(PDO::FETCH_ASSOC);
        }

        $transaction['invoices'] = $invoices->fetchAll(PDO::FETCH_ASSOC);
        $transaction['categories'] = $categoryResults;
        $transaction['payments'] = $paymentResults;
        $transaction['attachments'] = $attachments;
        
        return $transaction;
    }

    /**
     * Resolved delivery history (delivered or marked not-received) -- the
     * delivery-side equivalent of collectionTransactions(). Pending/unresolved
     * invoices live in the Delivery Portal itself, not here.
     */
    public function deliveryTransactions(string $scope, string $search, string $fromDate, string $toDate, bool $notDeliveredOnly): array
    {
        $resolvedDateExpr = 'COALESCE(I.DeliveredDate, I.NotDeliveredDate)';
        $sql = "SELECT
                    I.TripID, I.InvoiceNo, I.CustomerID,
                    COALESCE(C.CustomerName, I.CustomerID) AS CustomerName,
                    I.TotalCrtns, I.DeliveredDate, I.NotDeliveredReason, I.NotDeliveredDate,
                    A.USERID AS RiderID, {$resolvedDateExpr} AS ResolvedDate
                FROM TripInvoice I
                INNER JOIN TriplistAssign A ON A.TRIPID = I.TripID
                LEFT JOIN Customers C ON C.CustomerID = I.CustomerID
                WHERE (I.DeliveredDate IS NOT NULL OR I.NotDeliveredReason IS NOT NULL)";
        $params = [];

        // Dates are optional, same reasoning as collectionTransactions(): an
        // implicit default range would make valid records look missing.
        if ($fromDate !== '') {
            $sql .= " AND {$resolvedDateExpr} >= :fromDate";
            $params[':fromDate'] = $fromDate;
        }
        if ($toDate !== '') {
            $sql .= " AND {$resolvedDateExpr} < DATEADD(day, 1, :toDate)";
            $params[':toDate'] = $toDate;
        }

        if ($scope === 'own') {
            // Exact match: LIKE would let rider "AAA1" see rider "AAA12"'s history.
            $sql .= ' AND A.USERID = :rider';
            $params[':rider'] = $search;
        } elseif ($scope === 'rider' && $search !== '') {
            $sql .= ' AND A.USERID LIKE :search';
            $params[':search'] = '%' . $search . '%';
        } elseif ($scope === 'customer' && $search !== '') {
            $sql .= ' AND (I.CustomerID LIKE :search OR C.CustomerName LIKE :search1)';
            $params[':search'] = '%' . $search . '%';
            $params[':search1'] = '%' . $search . '%';
        }

        if ($notDeliveredOnly) $sql .= ' AND I.NotDeliveredReason IS NOT NULL';
        $sql .= " ORDER BY {$resolvedDateExpr} DESC, I.TripID DESC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Full read-only detail for one resolved delivery, including the store photo if one was attached. */
    public function deliveryTransactionDetail(string $tripId, string $invoiceNo): ?array
    {
        $stmt = $this->pdo->prepare("SELECT
                I.ID, I.TripID, I.InvoiceNo, I.DrNo, I.CustomerID,
                COALESCE(C.CustomerName, I.CustomerID) AS CustomerName,
                STUFF(
                    CASE WHEN NULLIF(C.Street, '') IS NOT NULL THEN ', ' + C.Street ELSE '' END +
                    CASE WHEN NULLIF(C.Barangay, '') IS NOT NULL THEN ', ' + C.Barangay ELSE '' END +
                    CASE WHEN NULLIF(C.Municipality, '') IS NOT NULL THEN ', ' + C.Municipality ELSE '' END +
                    CASE WHEN NULLIF(C.Province, '') IS NOT NULL THEN ', ' + C.Province ELSE '' END,
                    1, 2, ''
                ) AS Address,
                I.TotalCrtns, I.DeliveredDate, I.NotDeliveredReason, I.NotDeliveredDate,
                I.latitude AS DeliveryLatitude, I.Longitude AS DeliveryLongitude,
                A.USERID AS RiderID, COALESCE(U.NAME, A.USERID) AS RiderName
            FROM TripInvoice I
            INNER JOIN TriplistAssign A ON A.TRIPID = I.TripID
            LEFT JOIN Customers C ON C.CustomerID = I.CustomerID
            LEFT JOIN UserList U ON U.USERID = A.USERID
            WHERE I.TripID = :trip AND I.InvoiceNo = :invoice
              AND (I.DeliveredDate IS NOT NULL OR I.NotDeliveredReason IS NOT NULL)");
        $stmt->execute([':trip' => $tripId, ':invoice' => $invoiceNo]);
        $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$transaction) return null;

        // Store photo, if the delivery was confirmed with one -- see
        // saveDeliveryPhoto(), which writes it under this same reference.
        $photo = $this->pdo->prepare("SELECT TOP 1 FILE_NAME, FILE_PATH FROM FileAttachment WHERE ATTACH_REFID_DTL = :ref ORDER BY DATE_CREATED DESC, PK_ATTACH DESC");
        $photo->execute([':ref' => 'delivery_' . $transaction['ID']]);
        $transaction['storePhoto'] = $photo->fetch(PDO::FETCH_ASSOC) ?: null;

        // Deposit slip, if one was uploaded for this stop -- shared across
        // every invoice on this Trip+Customer, see saveDepositSlip().
        $transaction['depositSlip'] = $this->depositSlipFile((string) $transaction['TripID'], (string) $transaction['CustomerID']);

        return $transaction;
    }

    /** Most recently uploaded deposit slip for one delivery stop (Trip + Customer), or null if none yet -- see saveDepositSlip(). */
    public function depositSlipFile(string $tripId, string $customerId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT TOP 1 FILE_NAME, FILE_PATH, DATE_CREATED FROM FileAttachment WHERE ATTACH_REFID_DTL = :ref ORDER BY DATE_CREATED DESC, PK_ATTACH DESC");
        $stmt->execute([':ref' => 'depositslip_' . $tripId . '_' . $customerId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function collectionAccess($customerCode, $locationLock, $latitude, $longitude)
    {
        if (!(bool) $locationLock) return ['allowed' => true, 'reason' => 'Location lock is disabled. You can view this customer’s collection details.', 'distance' => null, 'override' => 'location_lock_disabled'];
        if ($this->customerUnlocked($customerCode)) return ['allowed' => true, 'reason' => 'This customer is unlocked for collection viewing.', 'distance' => null, 'override' => 'customer_unlocked'];
        $distance = $this->verifyLocation($customerCode, $latitude, $longitude);
        return ['allowed' => true, 'reason' => 'Salesman is within the allowed customer radius.', 'distance' => $distance, 'override' => null];
    }

    public function collectionLocationRequired($customerCode, $locationLock): bool
    {
        return (bool) $locationLock && !$this->customerUnlocked($customerCode);
    }

    public function customerUnlocked($customerCode): bool
    {
        $customerCode = trim((string) $customerCode);
        if (array_key_exists($customerCode, $this->customerUnlockedCache)) {
            return $this->customerUnlockedCache[$customerCode];
        }

        if ($this->unlockTableExistsCache === null) {
            $this->unlockTableExistsCache = (bool) $this->pdo->query("SELECT OBJECT_ID(N'dbo.CustomerUnlockList', N'U')")->fetchColumn();
        }
        if (!$this->unlockTableExistsCache) return $this->customerUnlockedCache[$customerCode] = false;

        $stmt = $this->pdo->prepare("SELECT TOP 1 1 FROM CustomerUnlockList WHERE CUSTOMERID = :customer");
        $stmt->execute([':customer' => $customerCode]);
        return $this->customerUnlockedCache[$customerCode] = (bool) $stmt->fetchColumn();
    }

    public function verifyLocation($customerCode, $latitude, $longitude)
    {
        $customer = $this->customer($customerCode);
        if (!$customer || !is_numeric($customer['Latitude']) || !is_numeric($customer['Longitude'])) throw new RuntimeException('Customer coordinates are not configured.');
        $distance = $this->distanceMeters((float) $latitude, (float) $longitude, (float) $customer['Latitude'], (float) $customer['Longitude']);
        if ($distance > $this->radius()) throw new RuntimeException('Customer is out of range.');
        return $distance;
    }

    public function saveCollection($customer, $salesman, $prNumber, $amount, $splitAmount, array $payments, array $splits, array $attachments, array $invoiceNumbers)
    {
        $this->pdo->beginTransaction();
        try {
            $refId = $this->persistCollectionRecords($customer, $salesman, $prNumber, $amount, $splitAmount, $payments, $splits, $attachments, $invoiceNumbers);
            $this->pdo->commit();
            return $refId;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Transaction-agnostic core of saveCollection(): validates the requested
     * invoices/payments/splits and writes CollectionSyntaxHdr/InvDtl/Dtl/
     * Category/FileAttachment rows, but leaves transaction management to the
     * caller. Shared by saveCollection() (Collection Portal, standalone) and
     * saveDeliveriesWithCollection() (Delivery Portal, combined with the
     * delivery resolutions in the same transaction).
     */
    private function persistCollectionRecords($customer, $salesman, $prNumber, $amount, $splitAmount, array $payments, array $splits, array $attachments, array $invoiceNumbers)
    {
        $requestedInvoices = [];
        foreach ($invoiceNumbers as $invoice) {
            $number = trim((string) (is_array($invoice) ? ($invoice['invoice_no'] ?? '') : $invoice));
            if ($number === '') continue;
            $requestedInvoices[$number] = is_array($invoice) ? $invoice : ['invoice_no' => $number];
        }

        if (!$requestedInvoices) throw new RuntimeException('Search for and select at least one outstanding invoice.');
        $eligibleInvoices = $this->collectionInvoices($customer);
        $eligibleByNumber = [];

        foreach ($eligibleInvoices as $invoice) $eligibleByNumber[(string) $invoice['InvoiceNo']] = $invoice;
        $invoices = [];

        foreach ($requestedInvoices as $invoiceNumber => $request) {
            if (isset($eligibleByNumber[$invoiceNumber])) {
                $invoices[] = $eligibleByNumber[$invoiceNumber];
                continue;
            }

            if (empty($request['manual']) || $this->collectionInvoiceExists($invoiceNumber)) throw new RuntimeException('One or more selected invoices are already collected or no longer available for collection.');

            $manualAmount = filter_var($request['amount'] ?? null, FILTER_VALIDATE_FLOAT);
            if ($manualAmount === false || $manualAmount <= 0) throw new RuntimeException('Enter a valid amount for each manual invoice.');
            $invoices[] = ['InvoiceNo' => $invoiceNumber, 'Balance' => (float) $manualAmount];
        }

        $balance = 0.0;
        foreach ($invoices as $invoice) $balance += (float) $invoice['Balance'];
        $paymentTotal = 0.0;
        foreach ($payments as $payment) $paymentTotal += (float) ($payment['amount'] ?? 0);
        $categoryTotal = 0.0;
        foreach ($splits as $split) $categoryTotal += (float) ($split['amount'] ?? 0);
        if (!$invoices) throw new RuntimeException('There are no outstanding invoices for this customer.');
        if (abs($paymentTotal - $amount) > 0.009 || abs($categoryTotal - $splitAmount) > 0.009) throw new RuntimeException('Collection totals do not match the entered rows.');
        if (($amount + $splitAmount) < $balance - 0.009) throw new RuntimeException('Collection plus split balance must cover the outstanding invoice balance.');
        if (trim($prNumber) === '') throw new RuntimeException('PR number is required.');


        $refId = $prNumber . $customer . $salesman;

        $header = $this->pdo->prepare("
                INSERT INTO CollectionSyntaxHdr
                (
                    PRNUMBER,
                    CUSTOMERID,
                    SALESMAN,
                    SYNTAXREFERENCE,
                    DateReceived,
                    SyntaxDate
                )
                VALUES
                (
                    :pr,
                    :customer,
                    :salesman,
                    :ref,
                    CAST(GETDATE() AS DATE),
                    GETDATE()
                )
            ");

            $header->execute([
                ':pr' => $prNumber,
                ':customer' => $customer,
                ':salesman' => $salesman,
                ':ref' => $refId
            ]);

            $invoiceDetail = $this->pdo->prepare("
                INSERT INTO CollectionSyntaxInvDtl
                (
                    REFID,
                    INVOICENO,
                    AMOUNT
                )
                VALUES
                (
                    :ref,
                    :invoice,
                    :amount
                )
            ");

            foreach ($invoices as $invoice) {
                $invoiceDetail->execute([
                    ':ref' => $refId,
                    ':invoice' => $invoice['InvoiceNo'],
                    ':amount' => $invoice['Balance']
                ]);
            }

            $payment = $this->pdo->prepare("
                INSERT INTO CollectionSyntaxDtl
                (
                    REFID,
                    PAYMENTTYPE,
                    BANKINITIAL,
                    CHECKNUMBER,
                    ATTACH_REFID,
                    AMOUNT
                )
                OUTPUT INSERTED.ID
                VALUES
                (
                    :ref,
                    :type,
                    :bank,
                    :check,
                    :attachRef,
                    :amount
                )
            ");
            
            $attachmentDetailNames = [];

            foreach ($payments as $entry) {

                if (!in_array($entry['type'] ?? '', ['Cash', 'PDC'], true)) {
                    throw new RuntimeException('Invalid payment type.');
                }

                if ((float)$entry['amount'] <= 0) {
                    continue;
                }

                if (
                    $entry['type'] === 'PDC' &&
                    (
                        trim($entry['bank']) == '' ||
                        trim($entry['check']) == ''
                    )
                ) {
                    throw new RuntimeException('Each PDC payment requires bank initial and check number.');
                }

                $GetChecknumber = $this->CheckNUmberValidation($entry['check']);

                if ($GetChecknumber > 0) {
                    throw new RuntimeException(
                        'The check number is already existing: ' . trim($entry['check'])
                    );
                
                }

                $payment->execute([
                    ':ref' => $refId,
                    ':type' => $entry['type'],
                    ':bank' => $entry['type'] === 'PDC' ? ($entry['bank'] ?: null) : null,
                    ':check' => $entry['type'] === 'PDC' ? ($entry['check'] ?: null) : null,
                    ':attachRef' => null,  // Will be updated after we get the ID
                    ':amount' => $entry['amount']
                ]);

                $paymentId = (int) $payment->fetchColumn();
                
                // Reference is exactly the CollectionSyntaxDtl.ID, prefixed so it can't be
                // confused with a CollectionSyntaxCategory.ID in the shared FileAttachment table.
                $paymentAttachRef = 'payment_' . $paymentId;
                
                // Update the ATTACH_REFID with the generated unique ID
                $updatePaymentAttach = $this->pdo->prepare("
                    UPDATE CollectionSyntaxDtl 
                    SET ATTACH_REFID = :attachRef 
                    WHERE ID = :id
                ");
                
                $updatePaymentAttach->execute([
                    ':attachRef' => $paymentAttachRef,
                    ':id' => $paymentId
                ]);
                
                // Store the attachment reference for file processing
                $attachmentReference = (string) ($entry['attachment_reference'] ?? '');
                if ($attachmentReference !== '') {
                    $attachmentDetailNames[$attachmentReference] = $paymentAttachRef;
                }
            }

            $category = $this->pdo->prepare("
                INSERT INTO CollectionSyntaxCategory
                (
                    REFID,
                    CATID,
                    AMOUNT,
                    OTHERREF,
                    ATTACH_REFID
                )
                OUTPUT INSERTED.ID
                VALUES
                (
                    :ref,
                    :cat,
                    :amount,
                    :otherref,
                    :attachRef
                )
            ");

            foreach ($splits as $split) {

                if ((float)$split['amount'] <= 0) {
                    continue;
                }

                $category->execute([
                    ':ref' => $refId,
                    ':cat' => (int)$split['catid'],
                    ':amount' => $split['amount'],
                    ':otherref' => $split['reference'],
                    ':attachRef' => null  // Will be updated after we get the ID
                ]);

                $categoryId = (int) $category->fetchColumn();
                
                // Reference is exactly the CollectionSyntaxCategory.ID, prefixed so it can't be
                // confused with a CollectionSyntaxDtl.ID in the shared FileAttachment table.
                $categoryAttachRef = 'split_' . $categoryId;
                
                // Update the ATTACH_REFID with the generated unique ID
                $updateCategoryAttach = $this->pdo->prepare("
                    UPDATE CollectionSyntaxCategory 
                    SET ATTACH_REFID = :attachRef 
                    WHERE ID = :id
                ");
                
                $updateCategoryAttach->execute([
                    ':attachRef' => $categoryAttachRef,
                    ':id' => $categoryId
                ]);

                // Store the attachment reference for file processing
                $attachmentReference = (string) ($split['attachment_reference'] ?? '');
                if ($attachmentReference !== '') {
                    $attachmentDetailNames[$attachmentReference] = $categoryAttachRef;
                }
            }

            $attachment = $this->pdo->prepare("
                INSERT INTO FileAttachment
                (
                    ATTACH_REFID_DTL,
                    FILE_NAME,
                    FILE_PATH,
                    FILE_TYPE,
                    FILE_SIZE,
                    IMAGE_ATTACH
                )
                VALUES
                (
                    :ref,
                    :name,
                    :path,
                    :type,
                    :size,
                    :image
                )
            ");

            foreach ($attachments as $file) {
                // Process the file
                $attachmentReference = (string)($file['attachment_reference'] ?? '');
                $detailRef = $attachmentDetailNames[$attachmentReference] ?? null;

                if ($detailRef !== null) {

                    $randomFilename = preg_replace(
                        '/^(payment|split)_/i',
                        '',
                        (string)$file['filename']
                    );

                    $renamedFilename = $detailRef . '_' . $randomFilename;

                    $pathPrefix = substr(
                        (string)$file['filepath'],
                        0,
                        strrpos((string)$file['filepath'], '/') + 1
                    );

                    $oldAbsolutePath = APP_ROOT . DIRECTORY_SEPARATOR .
                        str_replace('/', DIRECTORY_SEPARATOR, (string)$file['filepath']);

                    $newAbsolutePath = APP_ROOT . DIRECTORY_SEPARATOR .
                        str_replace('/', DIRECTORY_SEPARATOR, $pathPrefix . $renamedFilename);

                    if (!is_file($oldAbsolutePath) || !rename($oldAbsolutePath, $newAbsolutePath)) {
                        throw new RuntimeException('Unable to name the uploaded attachment.');
                    }

                    $file['filename'] = $renamedFilename;
                    $file['filepath'] = $pathPrefix . $renamedFilename;
                }

                // Read image as binary
                $absolutePath = APP_ROOT . DIRECTORY_SEPARATOR . 
                    str_replace('/', DIRECTORY_SEPARATOR, (string)$file['filepath']);
                
                $imageData = null;
                if (is_file($absolutePath) && is_readable($absolutePath)) {
                    $imageData = file_get_contents($absolutePath);
                }

                // Bind all parameters
                $attachment->bindParam(':ref', $detailRef, PDO::PARAM_STR);
                $attachment->bindParam(':name', $file['filename'], PDO::PARAM_STR);
                $attachment->bindParam(':path', $file['filepath'], PDO::PARAM_STR);
                $attachment->bindParam(':type', $file['filetype'], PDO::PARAM_STR);
                $attachment->bindParam(':size', $file['filesize'], PDO::PARAM_INT);
                $attachment->bindParam(':image', $imageData, PDO::PARAM_LOB, 0, PDO::SQLSRV_ENCODING_BINARY);
                
                $attachment->execute();
            }

            return $refId;
    }

    private function distanceMeters($lat1, $lng1, $lat2, $lng2)
    {
        $radius = 6371000.0; $latDelta = deg2rad($lat2 - $lat1); $lngDelta = deg2rad($lng2 - $lng1);
        $a = sin($latDelta / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($lngDelta / 2) ** 2;
        return 2 * $radius * atan2(sqrt($a), sqrt(1 - $a));
    }

    private function prepareUtf8($sql)
    {
        if (defined('PDO::SQLSRV_ATTR_ENCODING') && defined('PDO::SQLSRV_ENCODING_UTF8')) {
            return $this->pdo->prepare($sql, [constant('PDO::SQLSRV_ATTR_ENCODING') => constant('PDO::SQLSRV_ENCODING_UTF8')]);
        }
        return $this->pdo->prepare($sql);
    }
}
