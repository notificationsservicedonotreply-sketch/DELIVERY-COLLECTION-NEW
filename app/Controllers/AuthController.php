<?php
declare(strict_types=1);

/** Login page, login handling, and logout. */
class AuthController
{
    /** Renders the login page (was Inc/sp-login.php). */
    public function showLogin(): void
    {
        requireBootstrapped();
        csrfToken();

        $loginError = $_SESSION['login_error'] ?? '';
        unset($_SESSION['login_error']);

        require APP_ROOT . '/app/Views/auth/login.php';
    }

    /** Handles the login POST (was Ajax/ajax_login.php). */
    public function login(): void
    {
        requireBootstrapped();
        global $pdo;

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->loginError('Invalid request method.');
        }

        if (
            empty($_POST['csrf_token']) ||
            empty($_SESSION['csrf_token']) ||
            !hash_equals((string) $_SESSION['csrf_token'], (string) $_POST['csrf_token'])
        ) {
            $this->loginError('Your session has expired. Please reload the login page and try again.');
        }

        $userIDRaw = trim((string) ($_POST['userID'] ?? ''));
        $passwordRaw = (string) ($_POST['password'] ?? '');

        if ($userIDRaw === '' || $passwordRaw === '') {
            $this->loginError('Please enter both userID and password.');
        }

        $waitSeconds = loginLockSecondsRemaining($pdo, $userIDRaw);
        if ($waitSeconds > 0) {
            $this->loginError("Too many failed attempts. Please try again in {$waitSeconds} second(s).");
        }

        $model = new UserAccessModel($pdo);

        if ($model->login($userIDRaw, $passwordRaw)) {
            session_regenerate_id(true);
            $_SESSION['initiated'] = true;
            clearLoginAttempts($pdo, $userIDRaw);
            unset($_SESSION['csrf_token']);
            // This handler is only ever invoked via POST to Ajax/ajax_login.php,
            // so the redirect must be resolved relative to that URL (one level
            // deep from the repo root) -- not the current file's location.
            header('Location: ../Administrator/');
            exit;
        }

        recordFailedLogin($pdo, $userIDRaw);
        $this->loginError('Invalid userID or password.');
    }

    /**
     * Lightweight session/CSRF heartbeat used by offline-core.js:
     *  - polled every 20s while a page is open, purely client-side, to
     *    detect "server reachable again" without waiting for the person to
     *    manually retry anything;
     *  - called once before replaying the offline outbox, since a CSRF
     *    token can go stale during a long offline stretch and every queued
     *    write needs a fresh one to be accepted.
     * Deliberately does NOT require a logged-in session -- the login page
     * itself has no use for this, but a session that expired *while
     * offline* still needs to be told "you're logged out" rather than have
     * every ping fail outright.
     */
    public function ping(): void
    {
        requireBootstrapped();
        header('Content-Type: application/json; charset=utf-8');

        $loggedIn = (($_SESSION['login'] ?? '') === '1') && !empty($_SESSION['userID']);
        if ($loggedIn) {
            // Keep the 30-minute inactivity window (see Router::dispatch())
            // from expiring just because the person is offline reading a
            // cached page rather than actively clicking around.
            $_SESSION['last_activity'] = time();
        }

        echo json_encode([
            'loggedIn' => $loggedIn,
            'csrfToken' => csrfToken(),
        ]);
    }

    /** Handles logout (was Ajax/ajax_logout.php and the 'Logout' page route). */
    public static function logout(): void
    {
        requireBootstrapped();

        $_SESSION = [];
        session_destroy();

        if (ini_get('session.use_cookies')) {
            $cookieParams = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $cookieParams['path'],
                $cookieParams['domain'],
                $cookieParams['secure'],
                $cookieParams['httponly']
            );
        }

        header('Location: ../');
        exit;
    }

    private function loginError(string $message): void
    {
        $_SESSION['login_error'] = $message;
        // Redirect to a known, fixed path rather than trusting HTTP_REFERER, which
        // browsers may omit or a crafted request may forge.
        header('Location: ../');
        exit;
    }
}
