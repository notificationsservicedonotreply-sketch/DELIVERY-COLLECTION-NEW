<?php
declare(strict_types=1);

/** Shared session, CSRF, and access-control helpers for browser-facing endpoints. */

/** Every Controller/Model/View file calls this first to refuse direct access
 *  (i.e. someone requesting the file's own URL instead of going through the
 *  front controller / Ajax entry point, which is where auth + CSRF happen). */
function requireBootstrapped(): void
{
    if (!defined('APP_BOOTSTRAPPED')) {
        http_response_code(403);
        exit('Forbidden.');
    }
}

function startSecureSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'httponly' => true,
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'samesite' => 'Lax',
    ]);
    session_start();
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['csrf_token'];
}

function requireCsrfToken(): void
{
    $provided = (string) ($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($provided === '' || empty($_SESSION['csrf_token']) || !hash_equals((string) $_SESSION['csrf_token'], $provided)) {
        throw new RuntimeException('Your session has expired. Reload the page and try again.');
    }
}

/** Requires an authenticated session; returns the logged-in userID or throws. */
function requireLoggedInUser(): string
{
    $userId = (string) ($_SESSION['userID'] ?? '');
    if ($userId === '' || ($_SESSION['login'] ?? '') !== '1') {
        throw new RuntimeException('Please sign in again.');
    }
    return $userId;
}

/** Whether the logged-in user (per session, set at login) may see/use the given module. */
function hasModuleAccess(string $module): bool
{
    return !empty($_SESSION['moduleAccess'][$module]);
}

/** Salesmen see only their own records; transaction administrators see all records. */
function canAccessCollectionTransactions(): bool
{
    if (hasModuleAccess('Collection-Transactions-Admin')) return true;

    return hasModuleAccess('Collection-Transactions')
        && trim((string) ($_SESSION['SALESMANID'] ?? '')) !== '';
}

/** Same idea as canAccessCollectionTransactions(), but delivery history is
 *  scoped by the rider's own USERID (via TriplistAssign), not SALESMANID. */
function canAccessDeliveryTransactions(): bool
{
    if (hasModuleAccess('Delivery-Transactions-Admin')) return true;

    return hasModuleAccess('Delivery-Transactions')
        && trim((string) ($_SESSION['userID'] ?? '')) !== '';
}

/**
 * Centralized page guard. Every protected route in Router.php declares which
 * module it requires (or null for "logged in is enough", e.g. the
 * dashboard). This closes the previous gap where Delivery-Portal and
 * Collection-Portal checked login but not module access.
 */
function requireModuleAccess(?string $module): void
{
    // Callers in this app only reach here from Router::dispatch(), which has
    // already redirected unauthenticated requests -- this check is kept as a
    // defensive second layer in case this function is ever called elsewhere.
    if (empty($_SESSION['login']) || $_SESSION['login'] !== '1') {
        header('Location: ../');
        exit;
    }
    if ($module !== null && !hasModuleAccess($module)) {
        http_response_code(403);
        exit('Access denied.');
    }
}

// ---------------------------------------------------------------------
// Brute-force login throttling.
//
// Primary: DB-backed, keyed by hash(IP + userID), so it survives cookie
// clearing and works across devices. Falls back to the original
// session-based counter if the optional LoginAttempts table (see
// database/login_attempts.sql) hasn't been created yet.
// ---------------------------------------------------------------------

function loginAttemptKey(string $userId): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    return hash('sha256', $ip . '|' . strtolower(trim($userId)));
}

function loginAttemptsTableExists(PDO $pdo): bool
{
    static $exists = null;
    if ($exists !== null) return $exists;
    try {
        $pdo->query('SELECT TOP 1 1 FROM LoginAttempts');
        $exists = true;
    } catch (Throwable $e) {
        $exists = false;
    }
    return $exists;
}

/** Returns seconds remaining if locked out, or 0 if the attempt may proceed. */
function loginLockSecondsRemaining(PDO $pdo, string $userId): int
{
    if (loginAttemptsTableExists($pdo)) {
        $stmt = $pdo->prepare('SELECT LockedUntil FROM LoginAttempts WHERE AttemptKey = :k');
        $stmt->execute([':k' => loginAttemptKey($userId)]);
        $lockedUntil = $stmt->fetchColumn();
        if ($lockedUntil) {
            $remaining = strtotime((string) $lockedUntil) - time();
            return $remaining > 0 ? $remaining : 0;
        }
        return 0;
    }

    // Fallback: session-based (resets if cookies are cleared).
    if (!empty($_SESSION['login_locked_until']) && time() < $_SESSION['login_locked_until']) {
        return $_SESSION['login_locked_until'] - time();
    }
    return 0;
}

function recordFailedLogin(PDO $pdo, string $userId, int $maxAttempts = 5, int $lockSeconds = 60): void
{
    if (loginAttemptsTableExists($pdo)) {
        $key = loginAttemptKey($userId);
        // PDO's SQL Server (ODBC) driver sends every execute()-array parameter
        // as nvarchar unless told otherwise, and DATEADD's numeric argument
        // rejects that outright ("Argument data type nvarchar is invalid for
        // argument 2 of dateadd function"). CAST inside the SQL is the fix
        // that doesn't depend on how each param happens to get bound.
        $stmt = $pdo->prepare('
            MERGE LoginAttempts AS target
            USING (SELECT :k AS AttemptKey) AS src
            ON target.AttemptKey = src.AttemptKey
            WHEN MATCHED THEN UPDATE SET FailCount = FailCount + 1, LastAttempt = GETDATE(),
                LockedUntil = CASE WHEN FailCount + 1 >= CAST(:max AS INT) THEN DATEADD(SECOND, CAST(:lock AS INT), GETDATE()) ELSE LockedUntil END
            WHEN NOT MATCHED THEN INSERT (AttemptKey, FailCount, LastAttempt) VALUES (:k2, 1, GETDATE());
        ');
        $stmt->bindValue(':k', $key, PDO::PARAM_STR);
        $stmt->bindValue(':k2', $key, PDO::PARAM_STR);
        $stmt->bindValue(':max', $maxAttempts, PDO::PARAM_INT);
        $stmt->bindValue(':lock', $lockSeconds, PDO::PARAM_INT);
        $stmt->execute();
        return;
    }

    $_SESSION['login_attempts'] = ($_SESSION['login_attempts'] ?? 0) + 1;
    if ($_SESSION['login_attempts'] >= $maxAttempts) {
        $_SESSION['login_locked_until'] = time() + $lockSeconds;
        $_SESSION['login_attempts'] = 0;
    }
}

function clearLoginAttempts(PDO $pdo, string $userId): void
{
    if (loginAttemptsTableExists($pdo)) {
        $pdo->prepare('DELETE FROM LoginAttempts WHERE AttemptKey = :k')->execute([':k' => loginAttemptKey($userId)]);
    }
    unset($_SESSION['login_attempts'], $_SESSION['login_locked_until']);
}