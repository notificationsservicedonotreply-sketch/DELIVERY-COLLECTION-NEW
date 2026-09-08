<?php
declare(strict_types=1);

/**
 * Authentication and module-access lookups against UserList / UserAccess.
 *
 * Note: earlier versions of this class round-tripped the userID/password
 * through a symmetric-encryption class before comparing them, using a key
 * that was hardcoded in source (and is now public, since this repo was
 * published publicly). That encrypt-then-immediately-decrypt-in-the-same-
 * request added no real protection -- TLS already protects the credentials
 * on the wire, and the "encryption" was reversible by anyone with the
 * source. It has been removed; this class now works with plain values,
 * which is both simpler and equally secure given HTTPS is enforced by
 * bootstrap.php in production.
 */
class UserAccessModel
{
    private PDO $pdo;
    private string $lastError = '';
    private bool $isLoggedIn = false;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * @param string $userID
     * @param string $password
     */
    public function login(string $userID, string $password): bool
    {
        $userID = trim($userID);
        $this->lastError = '';

        $stmt = $this->pdo->prepare(
            'SELECT PK, USERID, PASSWORD, IMEI, NAME, SALESMANID, LocationLock, DBNAME FROM UserList WHERE USERID = :userID'
        );
        $stmt->execute([':userID' => $userID]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // Supports bcrypt/Argon password hashes. Plain-text rows are accepted only
        // while existing accounts are migrated; run the supplied migration promptly.
        $passwordMatches = $user && (
            password_get_info((string) $user['PASSWORD'])['algo'] !== null
                ? password_verify($password, (string) $user['PASSWORD'])
                : hash_equals((string) $user['PASSWORD'], $password)
        );
        if (!$passwordMatches) {
            $this->lastError = 'invalid_credentials';
            return false;
        }

        $this->isLoggedIn = true;
        $_SESSION['userID'] = $user['USERID'];
        $_SESSION['EncoderName'] = $user['NAME'];
        $_SESSION['IMEI'] = trim((string) ($user['IMEI'] ?? ''));
        $_SESSION['LocationLock'] = $user['LocationLock'];
        $_SESSION['SALESMANID'] = $user['SALESMANID'];
        $_SESSION['DATABASENAME'] = trim((string) ($user['DBNAME'] ?? ''));
        $_SESSION['login'] = '1';
        $_SESSION['last_activity'] = time();
        $_SESSION['moduleAccess'] = $this->moduleAccess((string) $user['USERID']);

        return true;
    }

    /**
     * Returns which sidebar/menu modules this user is allowed to see, keyed by
     * MODULE name (e.g. 'Dashboard', 'Delivery-Portal') with a boolean value.
     * A user with no rows in UserAccess gets an empty array -- i.e. no menu
     * items show up. That's a deliberate fail-closed default.
     */
    public function moduleAccess(string $userID): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT TOP (200) USERID, MODULE, ACCESS FROM UserAccess WHERE USERID = :userID'
        );
        $stmt->execute([':userID' => $userID]);

        $access = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $module = trim((string) ($row['MODULE'] ?? ''));
            if ($module === '') continue;
            $access[$module] = $this->isAccessGranted($row['ACCESS'] ?? null);
        }

        return $access;
    }

    /** Normalizes the ACCESS column, whatever shape it's stored as (bit, int, or Y/N text). */
    private function isAccessGranted($value): bool
    {
        if (is_bool($value)) return $value;
        if (is_numeric($value)) return (int) $value === 1;
        $value = strtoupper(trim((string) $value));
        return in_array($value, ['1', 'Y', 'YES', 'TRUE', 'ALLOW', 'ALLOWED'], true);
    }

    /** Why the last login() call failed: 'invalid_credentials', or '' if it succeeded. */
    public function lastError(): string
    {
        return $this->lastError;
    }

    public function logout(): void
    {
        $this->isLoggedIn = false;
        unset(
            $_SESSION['userID'],
            $_SESSION['EncoderName'],
            $_SESSION['login'],
            $_SESSION['IMEI'],
            $_SESSION['LocationLock'],
            $_SESSION['SALESMANID'],
            $_SESSION['DATABASENAME'],
            $_SESSION['moduleAccess']
        );
    }

    public function is_logged_in(): bool
    {
        return $this->isLoggedIn;
    }
}
