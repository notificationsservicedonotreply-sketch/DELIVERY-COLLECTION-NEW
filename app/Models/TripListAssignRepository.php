<?php
declare(strict_types=1);

/**
 * Which trip IDs are assigned to which delivery riders (TriplistAssign:
 * USERID, TRIPID, Status, TripDate).
 *
 * Status: 1 = active (still in progress), 0 = completed. Completion is
 * normally flipped automatically once every invoice in the trip has been
 * resolved -- see DeliveryCollectionRepository::syncTripStatus() -- but is
 * editable here too, for correcting a trip that was closed/reopened by
 * mistake.
 *
 * There's no surrogate primary key on this table; (USERID, TRIPID) together
 * are the natural key, matching every other query in the app that joins
 * against TriplistAssign.
 */
class TripListAssignRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /** Every trip assignment, most recent first, with the rider's name joined in. */
    public function all(): array
    {
        $stmt = $this->pdo->query('
            SELECT A.USERID, A.TRIPID, A.Status, A.TripDate, U.NAME AS RiderName
            FROM TriplistAssign A
            LEFT JOIN UserList U ON U.USERID = A.USERID
            ORDER BY A.TripDate DESC, A.TRIPID DESC, A.USERID
        ');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Users selectable as the rider in the assign form. */
    public function riders(): array
    {
        $stmt = $this->pdo->query('SELECT USERID, NAME FROM UserList ORDER BY NAME, USERID');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Known trip IDs (from existing assignments and from TripInvoice), for the Trip ID field's autocomplete. */
    public function tripIdSuggestions(): array
    {
        $stmt = $this->pdo->query("
            SELECT DISTINCT TRIPID FROM (
                SELECT TRIPID FROM TriplistAssign
                UNION
                SELECT TripID AS TRIPID FROM TripInvoice
            ) T
            WHERE TRIPID IS NOT NULL AND LTRIM(RTRIM(TRIPID)) <> ''
            ORDER BY TRIPID DESC
        ");
        return array_values(array_filter(array_map(
            static fn ($id) => trim((string) $id),
            $stmt->fetchAll(PDO::FETCH_COLUMN)
        ), static fn ($id) => $id !== ''));
    }

    /** Creates a new assignment, or updates one identified by original_user_id/original_trip_id. */
    public function save(array $input): void
    {
        $userId = trim((string) ($input['user_id'] ?? ''));
        $tripId = trim((string) ($input['trip_id'] ?? ''));
        $status = !empty($input['status']) ? 1 : 0;
        $tripDate = trim((string) ($input['trip_date'] ?? ''));
        $tripDate = $tripDate === '' ? null : $tripDate;

        $originalUserId = trim((string) ($input['original_user_id'] ?? ''));
        $originalTripId = trim((string) ($input['original_trip_id'] ?? ''));
        $isEdit = $originalUserId !== '' && $originalTripId !== '';

        if ($userId === '' || $tripId === '') {
            throw new RuntimeException('User and Trip ID are required.');
        }

        $riderExists = $this->pdo->prepare('SELECT 1 FROM UserList WHERE USERID = :user');
        $riderExists->execute([':user' => $userId]);
        if (!$riderExists->fetchColumn()) {
            throw new RuntimeException('That user does not exist.');
        }

        if ($isEdit) {
            // Renaming either half of the key (reassigning to a different
            // rider, or fixing a mistyped trip ID) could collide with an
            // existing row already sitting at the destination key.
            if ($userId !== $originalUserId || $tripId !== $originalTripId) {
                $clash = $this->pdo->prepare('SELECT 1 FROM TriplistAssign WHERE USERID = :user AND TRIPID = :trip');
                $clash->execute([':user' => $userId, ':trip' => $tripId]);
                if ($clash->fetchColumn()) {
                    throw new RuntimeException('That user is already assigned to that trip.');
                }
            }
            $stmt = $this->pdo->prepare('
                UPDATE TriplistAssign
                SET USERID = :user, TRIPID = :trip, Status = :status, TripDate = :tripDate
                WHERE USERID = :origUser AND TRIPID = :origTrip
            ');
            $stmt->execute([
                ':user' => $userId, ':trip' => $tripId, ':status' => $status, ':tripDate' => $tripDate,
                ':origUser' => $originalUserId, ':origTrip' => $originalTripId,
            ]);
            if ($stmt->rowCount() === 0) {
                throw new RuntimeException('That assignment was not found (it may have already been changed or removed).');
            }
        } else {
            $exists = $this->pdo->prepare('SELECT 1 FROM TriplistAssign WHERE USERID = :user AND TRIPID = :trip');
            $exists->execute([':user' => $userId, ':trip' => $tripId]);
            if ($exists->fetchColumn()) {
                throw new RuntimeException('That user is already assigned to that trip.');
            }
            $stmt = $this->pdo->prepare('
                INSERT INTO TriplistAssign (USERID, TRIPID, Status, TripDate)
                VALUES (:user, :trip, :status, :tripDate)
            ');
            $stmt->execute([':user' => $userId, ':trip' => $tripId, ':status' => $status, ':tripDate' => $tripDate]);
        }
    }

    public function delete(string $userId, string $tripId): void
    {
        $userId = trim($userId);
        $tripId = trim($tripId);
        if ($userId === '' || $tripId === '') {
            throw new RuntimeException('User and Trip ID are required.');
        }
        $stmt = $this->pdo->prepare('DELETE FROM TriplistAssign WHERE USERID = :user AND TRIPID = :trip');
        $stmt->execute([':user' => $userId, ':trip' => $tripId]);
        if ($stmt->rowCount() === 0) {
            throw new RuntimeException('That assignment was not found.');
        }
    }
}
