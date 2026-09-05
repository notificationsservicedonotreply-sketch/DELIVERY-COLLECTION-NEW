<?php
declare(strict_types=1);

class DashboardController
{
    public function index(): void
    {
        requireBootstrapped();
        View::render('dashboard/index', [], ['dashboard']);
    }

    /** JSON stats for the dashboard cards (was Ajax/ajax_dashboard.php). */
    public function stats(): void
    {
        requireBootstrapped();
        global $pdo;
        header('Content-Type: application/json; charset=utf-8');

        if (($_SESSION['login'] ?? '') !== '1' || empty($_SESSION['userID'])) {
            http_response_code(401);
            echo json_encode(['error' => true, 'message' => 'Please sign in again.']);
            return;
        }

        try {
            // Regular riders/salesmen see only their own numbers; anyone with an
            // "admin" module on either side (they can already see everyone's
            // transactions on those pages) sees the system-wide totals instead.
            $canSeeAll = hasModuleAccess('Collection-Transactions-Admin')
                || hasModuleAccess('Delivery-Transactions-Admin')
                || hasModuleAccess('User-Management');

            $totalUsers = (int) $pdo->query('SELECT COUNT(*) FROM UserList')->fetchColumn();

            if ($canSeeAll) {
                $totalTransactions = (int) $pdo->query('SELECT COUNT(*) FROM CollectionSyntaxHdr')->fetchColumn();
                $totalCollections = (int) $pdo->query("SELECT COUNT(*) FROM CollectionSyntaxHdr WHERE ISNULL(PR_CONFIRMED, '') = 'Y'")->fetchColumn();
                $totalDeliveries = (int) $pdo->query('SELECT COUNT(*) FROM TripInvoice WHERE DeliveredDate IS NOT NULL')->fetchColumn();
            } else {
                $salesmanId = (string) ($_SESSION['SALESMANID'] ?? '');
                $userId = (string) ($_SESSION['userID'] ?? '');

                $txnStmt = $pdo->prepare('SELECT COUNT(*) FROM CollectionSyntaxHdr WHERE SALESMAN = :salesman');
                $txnStmt->execute([':salesman' => $salesmanId]);
                $totalTransactions = (int) $txnStmt->fetchColumn();

                $collectedStmt = $pdo->prepare("SELECT COUNT(*) FROM CollectionSyntaxHdr WHERE SALESMAN = :salesman AND ISNULL(PR_CONFIRMED, '') = 'Y'");
                $collectedStmt->execute([':salesman' => $salesmanId]);
                $totalCollections = (int) $collectedStmt->fetchColumn();

                $deliveredStmt = $pdo->prepare('SELECT COUNT(*) FROM TripInvoice I INNER JOIN TriplistAssign A ON A.TRIPID = I.TripID WHERE A.USERID = :user AND I.DeliveredDate IS NOT NULL');
                $deliveredStmt->execute([':user' => $userId]);
                $totalDeliveries = (int) $deliveredStmt->fetchColumn();
            }

            echo json_encode([
                'totalUsers' => $totalUsers,
                'totalTransactions' => $totalTransactions,
                'totalCollections' => $totalCollections,
                'totalDeliveries' => $totalDeliveries,
                'scope' => $canSeeAll ? 'all' : 'own',
                'requests' => [],
            ]);
        } catch (Throwable $error) {
            error_log('Dashboard error: ' . $error->getMessage());
            http_response_code(500);
            echo json_encode(['error' => true, 'message' => 'Unable to load dashboard data.']);
        }
    }
}
