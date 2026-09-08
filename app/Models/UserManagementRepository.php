<?php
declare(strict_types=1);

class UserManagementRepository
{
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function users(): array
    {
        $statement = $this->pdo->query("SELECT U.PK, U.USERID, U.IMEI, U.NAME, U.SALESMANID, U.LocationLock,
            ISNULL(MAX(CASE WHEN A.MODULE = 'Delivery-Portal' AND A.ACCESS = 1 THEN 1 ELSE 0 END), 0) AS CanDelivery,
            ISNULL(MAX(CASE WHEN A.MODULE = 'Collection-Portal' AND A.ACCESS = 1 THEN 1 ELSE 0 END), 0) AS CanCollection,
            ISNULL(MAX(CASE WHEN A.MODULE = 'Collection-Transactions' AND A.ACCESS = 1 THEN 1 ELSE 0 END), 0) AS CanTransactions,
            ISNULL(MAX(CASE WHEN A.MODULE = 'Collection-Transactions-Admin' AND A.ACCESS = 1 THEN 1 ELSE 0 END), 0) AS CanManageTransactions,
            ISNULL(MAX(CASE WHEN A.MODULE = 'Delivery-Transactions' AND A.ACCESS = 1 THEN 1 ELSE 0 END), 0) AS CanDeliveryTransactions,
            ISNULL(MAX(CASE WHEN A.MODULE = 'Delivery-Transactions-Admin' AND A.ACCESS = 1 THEN 1 ELSE 0 END), 0) AS CanManageDeliveryTransactions,
            ISNULL(MAX(CASE WHEN A.MODULE = 'Trip-List-Assign' AND A.ACCESS = 1 THEN 1 ELSE 0 END), 0) AS CanTriplistAssign,
            ISNULL(MAX(CASE WHEN A.MODULE = 'Customer-Profile' AND A.ACCESS = 1 THEN 1 ELSE 0 END), 0) AS CanCustomerProfile
            FROM UserList U LEFT JOIN UserAccess A ON A.USERID = U.USERID
            GROUP BY U.PK, U.USERID, U.IMEI, U.NAME, U.SALESMANID, U.LocationLock
            ORDER BY U.USERID");
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function save(array $input): void
    {
        $pk = filter_var($input['pk'] ?? null, FILTER_VALIDATE_INT);
        $userId = trim((string) ($input['user_id'] ?? ''));
        $name = trim((string) ($input['name'] ?? ''));
        $salesmanId = trim((string) ($input['salesman_id'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        $imei = trim((string) ($input['imei'] ?? ''));
        $locationLock = !empty($input['location_lock']) ? 1 : 0;
        if ($userId === '' || $name === '' || $salesmanId === '') throw new RuntimeException('User ID, name, and salesman ID are required.');
        if (!$pk && strlen($password) < 10) throw new RuntimeException('A new user password must contain at least 10 characters.');

        // Validate against the deployed schema to avoid generic SQL Server
        // truncation errors when an older database is still in use.
        $this->assertFitsColumn('USERID', $userId, 'User ID');
        $this->assertFitsColumn('NAME', $name, 'Full name');
        $this->assertFitsColumn('SALESMANID', $salesmanId, 'Salesman ID');
        $this->assertFitsColumn('IMEI', $imei, 'IMEI / device ID');

        $this->pdo->beginTransaction();
        try {
            if ($pk) {
                $existingUser = $this->pdo->prepare('SELECT USERID FROM UserList WHERE PK = :pk');
                $existingUser->execute([':pk' => $pk]);
                $previousUserId = (string) $existingUser->fetchColumn();
                if ($previousUserId === '') throw new RuntimeException('User was not found.');
                $sql = 'UPDATE UserList SET USERID = :user, NAME = :name, SALESMANID = :salesman, IMEI = :imei, LocationLock = :lock';
                $params = [':user' => $userId, ':name' => $name, ':salesman' => $salesmanId, ':imei' => $imei, ':lock' => $locationLock, ':pk' => $pk];
                if ($password !== '') {
                    if (strlen($password) < 10) throw new RuntimeException('Password must contain at least 10 characters.');
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $this->assertFitsColumn('PASSWORD', $hash, 'Password hash', 'Run database/user_roles_permissions.sql to update UserList.PASSWORD to varchar(255).');
                    $sql .= ', PASSWORD = :password';
                    $params[':password'] = $hash;
                }
                $sql .= ' WHERE PK = :pk';
                $statement = $this->pdo->prepare($sql);
                $statement->execute($params);
                if ($previousUserId !== $userId) {
                    $moveAccess = $this->pdo->prepare('UPDATE UserAccess SET USERID = :newUser WHERE USERID = :oldUser');
                    $moveAccess->execute([':newUser' => $userId, ':oldUser' => $previousUserId]);
                }
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $this->assertFitsColumn('PASSWORD', $hash, 'Password hash', 'Run database/user_roles_permissions.sql to update UserList.PASSWORD to varchar(255).');
                $statement = $this->pdo->prepare('INSERT INTO UserList (USERID, PASSWORD, IMEI, NAME, SALESMANID, LocationLock) VALUES (:user, :password, :imei, :name, :salesman, :lock)');
                $statement->execute([':user' => $userId, ':password' => $hash, ':imei' => $imei, ':name' => $name, ':salesman' => $salesmanId, ':lock' => $locationLock]);
            }
            $this->saveModuleAccess($userId, $input);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function delete(int $pk, string $currentUserId): void
    {
        $lookup = $this->pdo->prepare('SELECT USERID FROM UserList WHERE PK = :pk');
        $lookup->execute([':pk' => $pk]);
        $userId = (string) $lookup->fetchColumn();
        if ($userId === '') throw new RuntimeException('User was not found.');
        if (hash_equals($currentUserId, $userId)) throw new RuntimeException('You cannot delete your own account.');
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('DELETE FROM UserAccess WHERE USERID = :user')->execute([':user' => $userId]);
            $this->pdo->prepare('DELETE FROM UserList WHERE PK = :pk')->execute([':pk' => $pk]);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    private function saveModuleAccess(string $userId, array $input): void
    {
        $modules = [
            'Delivery-Portal' => 'can_delivery',
            'Collection-Portal' => 'can_collection',
            'Collection-Transactions' => 'can_transactions',
            'Collection-Transactions-Admin' => 'can_manage_transactions',
            'Delivery-Transactions' => 'can_delivery_transactions',
            'Delivery-Transactions-Admin' => 'can_manage_delivery_transactions',
            'Trip-List-Assign' => 'can_triplist_assign',
            'Customer-Profile' => 'can_customer_profile',
        ];
        foreach ($modules as $module => $field) {
            $statement = $this->pdo->prepare("UPDATE UserAccess SET ACCESS = :accessUpdate WHERE USERID = :userUpdate AND MODULE = :moduleUpdate; IF @@ROWCOUNT = 0 INSERT INTO UserAccess (USERID, MODULE, ACCESS) VALUES (:userInsert, :moduleInsert, :accessInsert);");
            $access = !empty($input[$field]) ? 1 : 0;
            $statement->execute([':accessUpdate' => $access, ':userUpdate' => $userId, ':moduleUpdate' => $module, ':userInsert' => $userId, ':moduleInsert' => $module, ':accessInsert' => $access]);
        }
    }

    private function assertFitsColumn(string $column, string $value, string $label, string $hint = ''): void
    {
        $statement = $this->pdo->prepare("SELECT CHARACTER_MAXIMUM_LENGTH
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = 'dbo' AND TABLE_NAME = 'UserList' AND COLUMN_NAME = :column");
        $statement->execute([':column' => $column]);
        $maxLength = $statement->fetchColumn();

        if ($maxLength !== false && $maxLength !== null && (int) $maxLength > 0 && strlen($value) > (int) $maxLength) {
            $message = sprintf('%s is too long for the current database column (maximum %d characters).', $label, (int) $maxLength);
            throw new RuntimeException($hint === '' ? $message : $message . ' ' . $hint);
        }
    }
}
