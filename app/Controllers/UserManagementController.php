<?php
declare(strict_types=1);

class UserManagementController
{
    public function index(): void
    {
        requireBootstrapped();
        global $pdo;

        $users = (new UserManagementRepository($pdo))->users();
        View::render('users/management', ['users' => $users], ['user-management']);
    }

    /** JSON API for save/delete (was Ajax/ajax_user_management.php). */
    public function api(): void
    {
        requireBootstrapped();
        global $pdo;
        header('Content-Type: application/json; charset=utf-8');

        try {
            if (($_SESSION['login'] ?? '') !== '1' || !hasModuleAccess('User-Management')) {
                throw new RuntimeException('You are not allowed to manage users.');
            }
            requireCsrfToken();
            $repository = new UserManagementRepository($pdo);
            $action = (string) ($_POST['action'] ?? '');
            if ($action === 'save') {
                $repository->save($_POST);
            } elseif ($action === 'delete') {
                $repository->delete((int) ($_POST['pk'] ?? 0), (string) $_SESSION['userID']);
            } else {
                throw new RuntimeException('Unsupported request.');
            }
            echo json_encode(['success' => true]);
        } catch (Throwable $error) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => $error->getMessage()]);
        }
    }
}
