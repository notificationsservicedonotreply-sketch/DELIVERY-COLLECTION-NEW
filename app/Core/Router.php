<?php
declare(strict_types=1);

/**
 * Central front-controller route table for the Administrator area.
 *
 * Replaces the old per-page pattern where each page file (delivery_portal.php,
 * collection_portal.php, ...) was individually responsible for remembering to
 * check module access -- which was inconsistently applied (Delivery-Portal and
 * Collection-Portal checked *login* but not the module grant). Access control
 * now happens in exactly one place before any Controller/Model code runs.
 *
 * The old app also wrapped the "page" query value in a symmetric cipher using
 * a key that was hardcoded in source (see UserAccessModel.php docblock for why
 * that added no real protection). Routes below use plain page keys instead --
 * the real security is the session check + module-access check below, not
 * obfuscating which page name was requested.
 */
final class Router
{
    /** page-key => [controllerClass, method, moduleOrNull, accessCheckerFnOrNull] */
    private const ROUTES = [
        'Dashboard' => [DashboardController::class, 'index', null, null],
        'Delivery-Portal' => [DeliveryController::class, 'portal', 'Delivery-Portal', null],
        'Trip-List-Assign' => [TripListAssignController::class, 'index', 'Trip-List-Assign', null],
        'Customer-Profile' => [CustomerProfileController::class, 'index', 'Customer-Profile', null],
        'Delivery-Transactions' => [DeliveryController::class, 'transactions', null, 'canAccessDeliveryTransactions'],
        'Delivery-Transaction-Details' => [DeliveryController::class, 'transactionDetail', null, 'canAccessDeliveryTransactions'],
        'Collection-Portal' => [CollectionController::class, 'portal', 'Collection-Portal', null],
        'Collection-Transactions' => [CollectionController::class, 'transactions', null, 'canAccessCollectionTransactions'],
        'Collection-Transaction-Details' => [CollectionController::class, 'transactionDetail', null, 'canAccessCollectionTransactions'],
        'User-Management' => [UserManagementController::class, 'index', 'User-Management', null],
        // No module required: this is a personal per-device preference, not
        // access to business data -- any logged-in user may see it.
        'Device-Settings' => [SettingsController::class, 'index', null, null],
    ];

    public static function dispatch(string $page): void
    {
        // Unrecognized/blank page (including garbage left over from an old
        // bookmarked ?page=<ciphertext> link) falls back to the dashboard --
        // identical to the previous app's default: case, so no old link 404s.
        if ($page === 'Logout') {
            AuthController::logout();
            return;
        }

        [$controllerClass, $method, $module, $checker] = self::ROUTES[$page] ?? self::ROUTES['Dashboard'];

        if (empty($_SESSION['login']) || $_SESSION['login'] !== '1') {
            session_destroy();
            header('Location: ../');
            exit;
        }

        // Session-fixation prevention: regenerate once per authenticated session.
        if (!isset($_SESSION['initiated'])) {
            session_regenerate_id(true);
            $_SESSION['initiated'] = true;
        }

        // Expire inactive authenticated sessions after 30 minutes.
        if (!empty($_SESSION['last_activity']) && time() - (int) $_SESSION['last_activity'] > 1800) {
            $_SESSION = [];
            session_destroy();
            header('Location: ../');
            exit;
        }
        $_SESSION['last_activity'] = time();

        // System Maintenance: while enabled, every page is replaced with a
        // maintenance notice for everyone *except* whoever can manage users
        // (see SettingsController's docblock for why that's the chosen
        // "admin" proxy) -- so the person who turned it on can always turn
        // it back off, and never gets locked out along with everyone else.
        // Reads defensively default to "off" (see SystemSettingsRepository),
        // so a settings-table hiccup can never accidentally take the whole
        // app down.
        global $pdo;
        $maintenance = new SystemSettingsRepository($pdo);
        if ($maintenance->isMaintenanceMode() && !hasModuleAccess('User-Management')) {
            View::render('maintenance/index', [
                'message' => $maintenance->maintenanceMessage(),
            ], []);
            return;
        }

        if ($checker !== null) {
            if (!call_user_func($checker)) {
                http_response_code(403);
                exit('Access denied.');
            }
        } else {
            requireModuleAccess($module);
        }

        /** @var callable $callable */
        $callable = [new $controllerClass(), $method];
        call_user_func($callable);
    }

    /** Same lookup used by Views to build sidebar/navigation links. */
    public static function url(string $page): string
    {
        return '?page=' . urlencode($page);
    }
}
