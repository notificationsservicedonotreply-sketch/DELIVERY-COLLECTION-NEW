<?php
declare(strict_types=1);

/**
 * Shared AJAX API for both the Delivery and Collection portals
 * (was Ajax/ajax_delivery_collection.php). Behavior is unchanged from the
 * original file; only the location moved and requireLoggedInUser() now
 * lives in app/Core/Security.php so other controllers can share it too.
 */
class DeliveryCollectionApiController
{
    public function handle(): void
    {
        requireBootstrapped();
        global $pdo;
        header('Content-Type: application/json; charset=utf-8');

        try {
            $userId = requireLoggedInUser();
            requireCsrfToken();
            $repository = new DeliveryCollectionRepository($pdo);
            $action = (string) ($_POST['action'] ?? '');

            switch ($action) {
                case 'customers':
                    $this->searchCustomers($repository);
                    break;
                case 'confirm_location':
                    $this->confirmLocation($repository, $userId);
                    break;
                case 'collection_access':
                    $this->collectionAccess($repository, $userId);
                    break;
                case 'collection_invoices':
                    $this->collectionInvoices($repository);
                    break;
                case 'aging_receivables':
                    $this->agingReceivables($repository);
                    break;
                case 'complete_delivery':
                    $this->completeDelivery($repository, $userId);
                    break;
                case 'upload_deposit_slip':
                    $this->uploadDepositSlip($repository, $userId);
                    break;
                case 'deposit_slip_info':
                    $this->depositSlipInfo($repository);
                    break;
                case 'not_delivered':
                    $this->markNotDelivered($repository, $userId);
                    break;
                case 'complete_collection':
                    $this->completeCollection($repository, $userId);
                    break;
                default:
                    throw new RuntimeException('Unsupported request.');
            }
        } catch (Throwable $error) {
            $isUnauthenticated = $error->getMessage() === 'Please sign in again.';
            http_response_code($isUnauthenticated ? 401 : 400);
            echo json_encode(['success' => false, 'message' => $error->getMessage()]);
        }
    }

    private function searchCustomers(DeliveryCollectionRepository $repository): void
    {
        $query = trim((string) ($_POST['q'] ?? ''));
        if (strlen($query) < 2) throw new RuntimeException('Enter at least two characters.');
        $module = (string) ($_POST['module'] ?? '');
        $dbName = (string) ($_SESSION['DATABASENAME'] ?? '');
        $items = $module === 'delivery'
            ? $repository->searchAssignedDeliveryCustomers((string) $_SESSION['userID'], $query, $dbName)
            : $repository->searchCustomers($query, $dbName);
        echo json_encode(['success' => true, 'items' => $items]);
    }

    private function confirmLocation(DeliveryCollectionRepository $repository, string $userId): void
    {
        $customer = trim((string) ($_POST['customer'] ?? ''));
        $module = (string) ($_POST['module'] ?? '');
        $latitude = filter_var($_POST['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
        $longitude = filter_var($_POST['longitude'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($customer === '' || !in_array($module, ['delivery', 'collection'], true)) throw new RuntimeException('Invalid location confirmation.');

        $locationLock = $repository->locationLockForUser($userId);
        $locationRequired = $repository->collectionLocationRequired($customer, $locationLock);

        $distance = null;
        if ($locationRequired) {
            if ($latitude === false || $longitude === false) throw new RuntimeException('A valid GPS location is required.');
            $distance = $repository->verifyLocation($customer, (float) $latitude, (float) $longitude);
        }

        $_SESSION['dc_location_' . $module . '_' . $customer] = time();
        echo json_encode(['success' => true, 'distance' => $distance !== null ? round($distance, 2) : null]);
    }

    private function collectionAccess(DeliveryCollectionRepository $repository, string $userId): void
    {
        $customer = trim((string) ($_POST['customer'] ?? ''));
        $latitude = filter_var($_POST['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
        $longitude = filter_var($_POST['longitude'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($customer === '') throw new RuntimeException('Select a customer first.');
        $locationLock = $repository->locationLockForUser($userId);
        $locationRequired = $repository->collectionLocationRequired($customer, $locationLock);
        if ($locationRequired && ($latitude === false || $longitude === false)) throw new RuntimeException('Salesman GPS location is required.');
        $access = $repository->collectionAccess($customer, $locationLock, (float) ($latitude ?: 0), (float) ($longitude ?: 0));
        $_SESSION['dc_location_collection_' . $customer] = time();
        echo json_encode(['success' => true, 'access' => $access]);
    }

    private function completeDelivery(DeliveryCollectionRepository $repository, string $userId): void
    {
        $customer = trim((string) ($_POST['customer'] ?? ''));
        $tripId = trim((string) ($_POST['trip_id'] ?? ''));
        $invoiceNo = trim((string) ($_POST['invoice_no'] ?? ''));
        $latitude = filter_var($_POST['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
        $longitude = filter_var($_POST['longitude'] ?? null, FILTER_VALIDATE_FLOAT);
        $this->requireRecentLocation('delivery', $customer);
        if ($tripId === '' || $invoiceNo === '' || $latitude === false || $longitude === false) throw new RuntimeException('Invalid delivery confirmation.');
        $storePhoto = $this->uploadedImage('store_photo', 'Delivery');
        if ($storePhoto === null) throw new RuntimeException('A photo of the store is required to confirm delivery.');
        $result = $repository->completeAssignedDelivery($userId, $tripId, $invoiceNo, $customer, (float) $latitude, (float) $longitude, $storePhoto);
        echo json_encode(['success' => true, 'message' => 'Invoice marked as delivered.', 'remaining_for_customer' => $result['remaining']]);
    }

    /** Records why a scheduled delivery wasn't received, as an alternative to completeDelivery(). */
    private function markNotDelivered(DeliveryCollectionRepository $repository, string $userId): void
    {
        $customer = trim((string) ($_POST['customer'] ?? ''));
        $tripId = trim((string) ($_POST['trip_id'] ?? ''));
        $invoiceNo = trim((string) ($_POST['invoice_no'] ?? ''));
        $reason = trim((string) ($_POST['reason'] ?? ''));
        $latitude = filter_var($_POST['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
        $longitude = filter_var($_POST['longitude'] ?? null, FILTER_VALIDATE_FLOAT);
        $this->requireRecentLocation('delivery', $customer);
        if ($tripId === '' || $invoiceNo === '' || $latitude === false || $longitude === false) throw new RuntimeException('Invalid request.');
        if ($reason === '') throw new RuntimeException('Enter a reason why this delivery was not received.');
        $result = $repository->markDeliveryNotReceived($userId, $tripId, $invoiceNo, $customer, (float) $latitude, (float) $longitude, $reason);
        echo json_encode(['success' => true, 'message' => 'Recorded as not delivered.', 'remaining_for_customer' => $result['remaining']]);
    }

    /** Uploads a rider-supplied deposit slip for one delivery stop (Trip + Customer), enabled client-side once every invoice there is Delivered -- re-checked here in the repository. */
    private function uploadDepositSlip(DeliveryCollectionRepository $repository, string $userId): void
    {
        $tripId = trim((string) ($_POST['trip_id'] ?? ''));
        $customer = trim((string) ($_POST['customer'] ?? ''));
        if ($tripId === '' || $customer === '') throw new RuntimeException('Invalid deposit slip upload.');
        $file = $this->uploadedImage('deposit_slip', 'Delivery');
        if ($file === null) throw new RuntimeException('A photo of the deposit slip is required.');
        $repository->saveDepositSlip($userId, $tripId, $customer, $file);
        echo json_encode(['success' => true, 'message' => 'Deposit slip uploaded.']);
    }

    /** Whether a deposit slip already exists for this stop, and a viewable URL for it, so the upload modal can show/replace it instead of assuming there's nothing there yet. */
    private function depositSlipInfo(DeliveryCollectionRepository $repository): void
    {
        $tripId = trim((string) ($_POST['trip_id'] ?? ''));
        $customer = trim((string) ($_POST['customer'] ?? ''));
        if ($tripId === '' || $customer === '') throw new RuntimeException('Invalid request.');
        $file = $repository->depositSlipFile($tripId, $customer);
        echo json_encode(['success' => true, 'exists' => $file !== null, 'url' => $this->safeFileUrl($file['FILE_PATH'] ?? null)]);
    }

    /** Same path-safety handling used for store-photo/deposit-slip URLs elsewhere: normalize separators, reject anything with a traversal segment, and URL-encode each path segment individually. */
    private function safeFileUrl(?string $filePath): ?string
    {
        if ($filePath === null) return null;
        $filePath = str_replace('\\', '/', ltrim($filePath, '/\\'));
        if ($filePath === '' || strpos($filePath, '..') !== false) return null;
        return '../' . implode('/', array_map('rawurlencode', explode('/', $filePath)));
    }


    private function completeCollection(DeliveryCollectionRepository $repository, string $userId): void
    {
        $customer = trim((string) ($_POST['customer'] ?? ''));
        $amount = $this->validAmount('amount');
        $splitAmount = $this->validAmount('split_amount');
        $locationLock = $repository->locationLockForUser($userId);
        if ($repository->collectionLocationRequired($customer, $locationLock)) $this->requireRecentLocation('collection', $customer);
        $splits = json_decode($_POST['splits'] ?? '[]', true);
        if (!is_array($splits)) throw new RuntimeException('Invalid split balance data.');
        $payments = json_decode($_POST['payments'] ?? '[]', true);
        if (!is_array($payments) || !$payments) throw new RuntimeException('Add at least one payment row.');
        $invoices = json_decode($_POST['invoices'] ?? '[]', true);
        if (!is_array($invoices) || !$invoices) throw new RuntimeException('Search for and select at least one outstanding invoice.');
        $attachments = $this->uploadedAttachments();
        $reference = $repository->saveCollection(
            $customer,
            (string) ($_SESSION['SALESMANID'] ?? $userId),
            trim((string) ($_POST['pr_number'] ?? '')),
            $amount,
            $splitAmount,
            $payments,
            $splits,
            $attachments,
            $invoices
        );
        echo json_encode(['success' => true, 'message' => 'Collection saved. Syntax reference: ' . $reference, 'reference' => $reference]);
    }

    private function collectionInvoices(DeliveryCollectionRepository $repository): void
    {
        $customer = trim((string) ($_POST['customer'] ?? ''));
        $query = trim((string) ($_POST['q'] ?? ''));
        if ($customer === '') throw new RuntimeException('Select a customer first.');
        if (strlen($query) < 2) throw new RuntimeException('Enter at least two characters of the invoice number.');
        // CollectionSyntaxInvDtl takes precedence: a collected invoice must never be offered again.
        $alreadyCollected = $repository->collectionInvoiceExists($query);
        $dbName = (string) ($_SESSION['DATABASENAME'] ?? '');
        $items = $alreadyCollected ? [] : $repository->searchCollectionInvoices($customer, $query, $dbName);
        echo json_encode(['success' => true, 'items' => $items, 'already_collected' => $alreadyCollected]);
    }

    private function agingReceivables(DeliveryCollectionRepository $repository): void
    {
        $customer = trim((string) ($_POST['customer'] ?? ''));
        if ($customer === '') throw new RuntimeException('Select a customer first.');
        $data = $repository->agingReceivables($customer);
        echo json_encode(['success' => true] + $data);
    }

    private function uploadedAttachments(): array
    {
        $attachments = [];

        foreach ($_FILES as $name => $file) {
            if (
                (strpos($name, 'payment_attachment_') !== 0 &&
                 strpos($name, 'split_attachment_') !== 0) ||
                $file['error'] === UPLOAD_ERR_NO_FILE
            ) {
                continue;
            }

            if ($file['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException('File upload failed.');
            }
            // Maximum file size: 5 MB
            if ($file['size'] > 5 * 1024 * 1024) {
                throw new RuntimeException('Maximum file size is 5 MB.');
            }

            // Allow only image files
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->file($file['tmp_name']);

            $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

            if (
                !in_array($mimeType, $allowedMimeTypes, true) ||
                !in_array($extension, $allowedExtensions, true)
            ) {
                throw new RuntimeException(
                    'Only image files (JPG, JPEG, PNG, GIF, WEBP) are allowed. Please check your attached file. Thank you!'
                );
            }
            $type = strpos($name, 'payment_attachment_') === 0 ? 'payment' : 'split';
            $attachmentReference = substr($name, strlen($type . '_attachment_'));

            $filename = uniqid($type . '_', true) . '.' . $extension;

            $relativePath = sprintf(
                'Uploads/Collection/%s/%s/%s/%s/',
                date('Y'),
                date('m'),
                date('d'),
                $type
            );

            $absolutePath = APP_ROOT . DIRECTORY_SEPARATOR . $relativePath;

            if (!is_dir($absolutePath)) {
                // 0755, not 0777: owner read/write/execute, group+world read/execute only.
                mkdir($absolutePath, 0755, true);
            }

            $destination = $absolutePath . $filename;

            if (!move_uploaded_file($file['tmp_name'], $destination)) {
                throw new RuntimeException('Unable to save uploaded file.');
            }

            $attachments[] = [
                'type' => $type,
                'attachment_reference' => $attachmentReference,
                'filename' => $filename,
                'filepath' => $relativePath . $filename,
                'filesize' => $file['size'],
                'filetype' => $file['type'],
            ];
        }

        return $attachments;
    }

    /** Validates and stores a single required image upload (used for the delivery store photo). Returns null if no file was sent. */
    private function uploadedImage(string $fieldName, string $subfolder): ?array
    {
        if (!isset($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        $file = $_FILES[$fieldName];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('File upload failed.');
        }
        if ($file['size'] > 5 * 1024 * 1024) {
            throw new RuntimeException('Maximum file size is 5 MB.');
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);
        $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($mimeType, $allowedMimeTypes, true) || !in_array($extension, $allowedExtensions, true)) {
            throw new RuntimeException('Only image files (JPG, JPEG, PNG, GIF, WEBP) are allowed for the store photo.');
        }

        $filename = uniqid(strtolower($subfolder) . '_', true) . '.' . $extension;
        $relativePath = sprintf('Uploads/%s/%s/%s/%s/', $subfolder, date('Y'), date('m'), date('d'));
        $absolutePath = APP_ROOT . DIRECTORY_SEPARATOR . $relativePath;

        if (!is_dir($absolutePath)) {
            mkdir($absolutePath, 0755, true);
        }

        $destination = $absolutePath . $filename;
        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            throw new RuntimeException('Unable to save the uploaded store photo.');
        }

        return [
            'filename' => $filename,
            'filepath' => $relativePath . $filename,
            'filesize' => $file['size'],
            'filetype' => $file['type'],
        ];
    }

    private function validAmount(string $field): float
    {
        $amount = filter_var($_POST[$field] ?? null, FILTER_VALIDATE_FLOAT);
        if ($amount === false || $amount < 0) throw new RuntimeException('Enter valid collection amounts.');
        return (float) $amount;
    }

    private function requireRecentLocation(string $module, string $customer): void
    {
        $confirmedAt = (int) ($_SESSION['dc_location_' . $module . '_' . $customer] ?? 0);
        if ($customer === '' || $confirmedAt === 0 || time() - $confirmedAt > 1800) {
            throw new RuntimeException('Confirm your location at the customer site before proceeding.');
        }
    }
}
