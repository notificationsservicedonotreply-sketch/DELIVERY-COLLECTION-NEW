<?php
declare(strict_types=1);

/**
 * Serves the one-shot data bundle offline-core.js mirrors into IndexedDB
 * (was Ajax/ajax_offline_bootstrap.php). Called automatically right after
 * landing on the Dashboard following login, and again whenever the person
 * uses "Sync now" / the offline indicator's data hasn't been refreshed
 * recently -- see mars.offline.bootstrap() in offline-core.js.
 *
 * Read-only and idempotent: this never writes anything, so it's safe to
 * call as often as needed while online with no side effects other than
 * refreshing what's cached on the device.
 */
class OfflineDataController
{
    public function bootstrap(): void
    {
        requireBootstrapped();
        global $pdo;
        header('Content-Type: application/json; charset=utf-8');

        try {
            $userId = requireLoggedInUser();
            $dbName = (string) ($_SESSION['DATABASENAME'] ?? '');

            $repository = new DeliveryCollectionRepository($pdo);
            $tables = $repository->offlineBootstrapData($userId, $dbName);

            // Customer Profile (admin CRUD over the full, unscoped Customers
            // table -- see CustomerRepository) is only mirrored for users who
            // can actually open that page. Everyone else's bootstrap payload
            // stays exactly as before: shipping 50k+ rows to every rider's
            // device on every login would be wasteful and pointless for
            // riders, who never see this page.
            $tables['customerProfile'] = hasModuleAccess('Customer-Profile')
                ? (new CustomerRepository($pdo))->all()
                : [];

            echo json_encode([
                'success' => true,
                'generatedAt' => time(),
                'tables' => $tables,
            ]);
        } catch (Throwable $error) {
            $isUnauthenticated = $error->getMessage() === 'Please sign in again.';
            http_response_code($isUnauthenticated ? 401 : 500);
            echo json_encode(['success' => false, 'message' => $error->getMessage()]);
        }
    }
}
