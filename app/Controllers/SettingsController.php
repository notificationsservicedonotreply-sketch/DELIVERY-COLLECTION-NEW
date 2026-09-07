<?php
declare(strict_types=1);

/**
 * Per-device app settings (install-as-app, full-screen preference) plus,
 * for admins only: the Delivery Radius settings (Delivery + Collection
 * portal GPS validation radius) and the System Maintenance toggle. The
 * device settings stay client-side (localStorage, via pwa-install.js) --
 * they describe how this one browser/device behaves. The other two are
 * server-side, system-wide settings (see SystemSettingsRepository), so
 * they're restricted to whoever has User-Management access -- reused here
 * as the "admin" proxy rather than introducing a whole new permission,
 * since anyone trusted to manage other users' access is a reasonable proxy
 * for who should be able to change GPS geofencing or take the app down for
 * maintenance. Without this gate, any delivery rider could open Settings
 * and loosen their own location requirement, or lock everyone else out.
 */
class SettingsController
{
    public function index(): void
    {
        requireBootstrapped();
        global $pdo;

        $isAdmin = hasModuleAccess('User-Management');
        $canManageMaintenance = $isAdmin;
        $canManageRadius = $isAdmin;

        $maintenance = null;
        $radius = null;
        if ($isAdmin) {
            $repository = new SystemSettingsRepository($pdo);
            $maintenance = [
                'on' => $repository->isMaintenanceMode(),
                'message' => $repository->maintenanceMessage(),
            ];
            $radius = [
                'delivery' => $repository->deliveryRadiusMeters(),
                'collection' => $repository->deliveryCollectionRadiusMeters(),
            ];
        }

        View::render('settings/device', [
            'canManageMaintenance' => $canManageMaintenance,
            'maintenance' => $maintenance,
            'canManageRadius' => $canManageRadius,
            'radius' => $radius,
        ], []);
    }

    /** JSON API: save either the Delivery Radius settings or the System
     *  Maintenance toggle + message, distinguished by the "action" field
     *  (both post to this same endpoint, since both live on this page). */
    public function api(): void
    {
        requireBootstrapped();
        global $pdo;
        header('Content-Type: application/json; charset=utf-8');

        try {
            if (($_SESSION['login'] ?? '') !== '1' || !hasModuleAccess('User-Management')) {
                throw new RuntimeException('You are not allowed to change this setting.');
            }
            requireCsrfToken();
            $repository = new SystemSettingsRepository($pdo);
            $action = (string) ($_POST['action'] ?? '');

            if ($action === 'save_radius') {
                $deliveryRadius = (float) ($_POST['delivery_radius'] ?? 0);
                $collectionRadius = (float) ($_POST['collection_radius'] ?? 0);
                $repository->setRadiusSettings($deliveryRadius, $collectionRadius);
            } else {
                $on = !empty($_POST['maintenance_on']);
                $message = (string) ($_POST['maintenance_message'] ?? '');
                $repository->setMaintenanceMode($on, $message);
            }
            echo json_encode(['success' => true]);
        } catch (Throwable $error) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => $error->getMessage()]);
        }
    }
}
