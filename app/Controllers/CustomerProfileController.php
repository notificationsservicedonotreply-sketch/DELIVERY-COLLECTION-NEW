<?php
declare(strict_types=1);

class CustomerProfileController
{
    /** Default/max rows per page the page's own picker offers -- kept in
     *  sync with the <select> options in customerprofile/index.php. */
    private const DEFAULT_PAGE_SIZE = 100;

    public function index(): void
    {
        requireBootstrapped();
        // No data query here anymore -- with 50k+ customers, loading the
        // whole table into the page was the bug. The page fetches its
        // first page of rows itself via the 'list' action below, the
        // moment its JS runs, the same as every later page/search does.
        View::render('customerprofile/index', [], ['customer-profile', 'status-pill']);
    }

    /** JSON API for list/save/delete. */
    public function api(): void
    {
        requireBootstrapped();
        global $pdo;
        header('Content-Type: application/json; charset=utf-8');

        try {
            if (($_SESSION['login'] ?? '') !== '1' || !hasModuleAccess('Customer-Profile')) {
                throw new RuntimeException('You are not allowed to manage customer profiles.');
            }
            requireCsrfToken();
            $repository = new CustomerRepository($pdo);
            $action = (string) ($_POST['action'] ?? '');
            $payload = ['success' => true];

            if ($action === 'save') {
                $repository->save($_POST);
            } elseif ($action === 'delete') {
                $pk = filter_var($_POST['pk'] ?? null, FILTER_VALIDATE_INT);
                if (!$pk) {
                    throw new RuntimeException('Customer is required.');
                }
                $repository->delete($pk);
            } elseif ($action === 'list') {
                $page = max(1, (int) ($_POST['page'] ?? 1));
                $pageSize = (int) ($_POST['page_size'] ?? self::DEFAULT_PAGE_SIZE);
                $search = (string) ($_POST['search'] ?? '');
                $payload['rows'] = $repository->page($page, $pageSize, $search);
                $payload['total'] = $repository->count($search);
                $payload['page'] = $page;
                $payload['pageSize'] = $pageSize;
            } else {
                throw new RuntimeException('Unsupported request.');
            }
            echo json_encode($payload);
        } catch (Throwable $error) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => $error->getMessage()]);
        }
    }
}
