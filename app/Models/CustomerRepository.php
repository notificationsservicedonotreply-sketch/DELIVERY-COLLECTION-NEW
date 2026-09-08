<?php
declare(strict_types=1);

/**
 * Customer master directory (Customers: PK_cust, CustomerID, CustomerName,
 * Street, Barangay, Municipality, Province, Latitude, Longitude,
 * SellingType). This is the same table the Delivery/Collection portals
 * already search against (see DeliveryCollectionRepository) -- this
 * repository is what lets that data actually be added/edited/deleted from
 * the admin side, the same way TripListAssignRepository does for
 * TriplistAssign.
 *
 * PK_cust is the table's surrogate identity key (not purely sequential in
 * the live data -- see the sample export this was built from), so edits and
 * deletes are keyed off it, the same shape as UserManagementRepository's
 * UserList.PK, rather than off the human-assigned CustomerID (which the
 * form still lets you change).
 */
class CustomerRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Every customer, sorted for a stable, name-first listing.
     *
     * IMPORTANT: not used by the page anymore -- with 50k+ rows in the live
     * Customers table, shipping the entire table to the browser in one HTML
     * response was the actual bug (a "log"/frozen page on load). The page
     * now calls page()/count() below instead, which only ever pull the
     * current 100-row page from SQL Server. This method is kept only for
     * any other caller that genuinely needs the full list in memory --
     * don't wire this back into the Customer Profile page.
     */
    public function all(): array
    {
        $stmt = $this->pdo->query('
            SELECT PK_cust, CustomerID, CustomerName, Street, Barangay, Municipality, Province, Latitude, Longitude, SellingType
            FROM Customers
            ORDER BY CustomerName, CustomerID
        ');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Hard ceiling on requested page size -- keeps a manipulated request
     *  (e.g. page_size=100000 in the POST body) from forcing one giant
     *  query anyway. Matches the largest option the page's own picker
     *  offers (25/50/100/200 -- see customerprofile/index.php). */
    private const MAX_PAGE_SIZE = 200;

    /**
     * One page of customers, optionally filtered by $search across every
     * display column (CustomerID, CustomerName, Street, Barangay,
     * Municipality, Province, SellingType). This is what makes search work
     * across all 50k+ rows instead of only the ones already on screen --
     * the database does the filtering, not the browser.
     *
     * Uses positional (?) placeholders throughout, not named (:name) ones.
     * pdo_sqlsrv (the driver this app connects with -- see Database.php's
     * "sqlsrv:" DSN) does its own client-side rewriting of named
     * placeholders into positional ones before sending the query to SQL
     * Server, and that rewriter is unreliable once a query has several
     * repeated LIKE ... ESCAPE clauses plus OFFSET/FETCH placeholders in
     * the same statement -- it was throwing "SQLSTATE[HY093]: Invalid
     * parameter number: parameter was not defined" here even though every
     * name was spelled correctly and bound exactly once. Positional ?
     * markers skip that rewriting step entirely and are what pdo_sqlsrv
     * supports most reliably.
     */
    public function page(int $page, int $pageSize, string $search = ''): array
    {
        $page = max(1, $page);
        $pageSize = max(1, min(self::MAX_PAGE_SIZE, $pageSize));
        $offset = ($page - 1) * $pageSize;
        $search = trim($search);

        $sql = 'SELECT PK_cust, CustomerID, CustomerName, Street, Barangay, Municipality, Province, Latitude, Longitude, SellingType FROM Customers';
        $likeValues = [];
        if ($search !== '') {
            $sql .= ' WHERE ' . $this->searchClause();
            $likeValues = $this->searchParams($search);
        }
        $sql .= ' ORDER BY CustomerName, CustomerID OFFSET ? ROWS FETCH NEXT ? ROWS ONLY';

        $stmt = $this->pdo->prepare($sql);
        $position = 1;
        foreach ($likeValues as $value) {
            $stmt->bindValue($position++, $value, PDO::PARAM_STR);
        }
        $stmt->bindValue($position++, $offset, PDO::PARAM_INT);
        $stmt->bindValue($position, $pageSize, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Total rows matching $search (or the whole table if blank) -- lets
     *  the page render "Showing X to Y of Z" and build page-number buttons
     *  without ever pulling the actual rows for that count. */
    public function count(string $search = ''): int
    {
        $search = trim($search);
        $sql = 'SELECT COUNT(*) FROM Customers';
        $params = [];
        if ($search !== '') {
            $sql .= ' WHERE ' . $this->searchClause();
            $params = $this->searchParams($search);
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /** Positional (?) placeholders -- see page()'s docblock for why not
     *  named ones. Order here must match searchParams()'s return order. */
    private function searchClause(): string
    {
        return "(CustomerID LIKE ? ESCAPE '\\' OR CustomerName LIKE ? ESCAPE '\\'
            OR Street LIKE ? ESCAPE '\\' OR Barangay LIKE ? ESCAPE '\\'
            OR Municipality LIKE ? ESCAPE '\\' OR Province LIKE ? ESCAPE '\\'
            OR SellingType LIKE ? ESCAPE '\\')";
    }

    /** Same escaped %needle% value repeated once per column searchClause()
     *  checks, in the same left-to-right order as its ? placeholders. */
    private function searchParams(string $search): array
    {
        return array_fill(0, 7, $this->likeParam($search));
    }

    /** Escapes LIKE wildcard characters in the user's own search text, so a
     *  literal % or _ (not unusual in a street address) is matched as a
     *  literal character instead of acting as a wildcard. */
    private function likeParam(string $search): string
    {
        $escaped = str_replace(['\\', '%', '_', '['], ['\\\\', '\\%', '\\_', '\\['], $search);
        return '%' . $escaped . '%';
    }

    /** Creates a new customer, or updates one identified by pk. */
    public function save(array $input): void
    {
        $pk = filter_var($input['pk'] ?? null, FILTER_VALIDATE_INT);
        $customerId = trim((string) ($input['customer_id'] ?? ''));
        $customerName = trim((string) ($input['customer_name'] ?? ''));
        $street = trim((string) ($input['street'] ?? ''));
        $barangay = trim((string) ($input['barangay'] ?? ''));
        $municipality = trim((string) ($input['municipality'] ?? ''));
        $province = trim((string) ($input['province'] ?? ''));
        $sellingType = trim((string) ($input['selling_type'] ?? ''));

        if ($customerId === '' || $customerName === '') {
            throw new RuntimeException('Customer ID and Customer Name are required.');
        }

        $latitude = $this->parseCoordinate($input['latitude'] ?? '', 'Latitude');
        $longitude = $this->parseCoordinate($input['longitude'] ?? '', 'Longitude');
        $sellingType = $sellingType === '' ? null : $sellingType;

        // Validate against the deployed schema, mirroring
        // UserManagementRepository::assertFitsColumn() -- so a value that's
        // too long for a differently-provisioned database fails with a
        // clear message instead of a generic SQL Server truncation error.
        $this->assertFitsColumn('CustomerID', $customerId, 'Customer ID');
        $this->assertFitsColumn('CustomerName', $customerName, 'Customer name');
        $this->assertFitsColumn('Street', $street, 'Street');
        $this->assertFitsColumn('Barangay', $barangay, 'Barangay');
        $this->assertFitsColumn('Municipality', $municipality, 'Municipality');
        $this->assertFitsColumn('Province', $province, 'Province');
        if ($sellingType !== null) {
            $this->assertFitsColumn('SellingType', $sellingType, 'Selling type');
        }

        if ($pk) {
            $existing = $this->pdo->prepare('SELECT CustomerID FROM Customers WHERE PK_cust = :pk');
            $existing->execute([':pk' => $pk]);
            $previousCustomerId = $existing->fetchColumn();
            if ($previousCustomerId === false) {
                throw new RuntimeException('Customer was not found.');
            }
            if ((string) $previousCustomerId !== $customerId) {
                $this->assertCustomerIdAvailable($customerId, $pk);
            }
            $stmt = $this->pdo->prepare('
                UPDATE Customers
                SET CustomerID = :customerId, CustomerName = :customerName, Street = :street,
                    Barangay = :barangay, Municipality = :municipality, Province = :province,
                    Latitude = :latitude, Longitude = :longitude, SellingType = :sellingType
                WHERE PK_cust = :pk
            ');
            $stmt->execute([
                ':customerId' => $customerId, ':customerName' => $customerName, ':street' => $street,
                ':barangay' => $barangay, ':municipality' => $municipality, ':province' => $province,
                ':latitude' => $latitude, ':longitude' => $longitude, ':sellingType' => $sellingType,
                ':pk' => $pk,
            ]);
        } else {
            $this->assertCustomerIdAvailable($customerId, null);
            $stmt = $this->pdo->prepare('
                INSERT INTO Customers (CustomerID, CustomerName, Street, Barangay, Municipality, Province, Latitude, Longitude, SellingType)
                VALUES (:customerId, :customerName, :street, :barangay, :municipality, :province, :latitude, :longitude, :sellingType)
            ');
            $stmt->execute([
                ':customerId' => $customerId, ':customerName' => $customerName, ':street' => $street,
                ':barangay' => $barangay, ':municipality' => $municipality, ':province' => $province,
                ':latitude' => $latitude, ':longitude' => $longitude, ':sellingType' => $sellingType,
            ]);
        }
    }

    public function delete(int $pk): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM Customers WHERE PK_cust = :pk');
        $stmt->execute([':pk' => $pk]);
        if ($stmt->rowCount() === 0) {
            throw new RuntimeException('Customer was not found.');
        }
    }

    private function parseCoordinate($raw, string $label): ?float
    {
        $value = trim((string) ($raw ?? ''));
        if ($value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            throw new RuntimeException("{$label} must be a number.");
        }
        return (float) $value;
    }

    private function assertCustomerIdAvailable(string $customerId, ?int $excludePk): void
    {
        $sql = 'SELECT 1 FROM Customers WHERE CustomerID = :customerId';
        $params = [':customerId' => $customerId];
        if ($excludePk !== null) {
            $sql .= ' AND PK_cust <> :pk';
            $params[':pk'] = $excludePk;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        if ($stmt->fetchColumn()) {
            throw new RuntimeException('That Customer ID is already in use.');
        }
    }

    private function assertFitsColumn(string $column, string $value, string $label): void
    {
        if ($value === '') {
            return;
        }
        $statement = $this->pdo->prepare("SELECT CHARACTER_MAXIMUM_LENGTH
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = 'dbo' AND TABLE_NAME = 'Customers' AND COLUMN_NAME = :column");
        $statement->execute([':column' => $column]);
        $maxLength = $statement->fetchColumn();

        if ($maxLength !== false && $maxLength !== null && (int) $maxLength > 0 && strlen($value) > (int) $maxLength) {
            throw new RuntimeException(sprintf('%s is too long for the current database column (maximum %d characters).', $label, (int) $maxLength));
        }
    }
}
