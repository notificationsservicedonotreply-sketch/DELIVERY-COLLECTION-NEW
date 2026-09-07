<?php
declare(strict_types=1);

class TripListAssignController
{
    public function index(): void
    {
        requireBootstrapped();
        global $pdo;

        $repository = new TripListAssignRepository($pdo);
        View::render('tripassign/index', [
            'assignments' => $repository->all(),
            'riders' => $repository->riders(),
            'tripIdSuggestions' => $repository->tripIdSuggestions(),
        ], ['trip-list-assign']);
    }

    /** JSON API for save/delete (mirrors UserManagementController::api()). */
    public function api(): void
    {
        requireBootstrapped();
        global $pdo;
        header('Content-Type: application/json; charset=utf-8');

        try {
            if (($_SESSION['login'] ?? '') !== '1' || !hasModuleAccess('Trip-List-Assign')) {
                throw new RuntimeException('You are not allowed to manage trip assignments.');
            }
            requireCsrfToken();
            $repository = new TripListAssignRepository($pdo);
            $action = (string) ($_POST['action'] ?? '');
            if ($action === 'save') {
                $repository->save($_POST);
            } elseif ($action === 'delete') {
                $repository->delete((string) ($_POST['user_id'] ?? ''), (string) ($_POST['trip_id'] ?? ''));
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
