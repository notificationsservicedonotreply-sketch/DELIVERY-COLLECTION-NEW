<?php
declare(strict_types=1);

/**
 * Reads/writes rows in the SystemSettings key-value table (see
 * database/delivery_collection_settings.sql for the existing
 * DeliveryCollectionRadiusMeters row -- this repository follows the same
 * table/columns, just with different SettingKey values).
 *
 * Every read defensively defaults to "maintenance off" if anything goes
 * wrong (missing row, missing table, DB hiccup) -- a settings-read failure
 * should never be able to accidentally lock every user out of the app.
 */
class SystemSettingsRepository
{
    private const KEY_MODE = 'MaintenanceMode';
    private const KEY_MESSAGE = 'MaintenanceMessage';
    private const DEFAULT_MESSAGE = 'The system is currently undergoing scheduled maintenance. Please check back shortly.';

    private const KEY_DELIVERY_RADIUS = 'DeliveryRadiusMeters';
    private const KEY_COLLECTION_RADIUS = 'DeliveryCollectionRadiusMeters';
    private const DEFAULT_RADIUS_METERS = 100.0;

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function isMaintenanceMode(): bool
    {
        return $this->readDecimal(self::KEY_MODE, 0.0) >= 1.0;
    }

    public function maintenanceMessage(): string
    {
        return $this->readText(self::KEY_MESSAGE, self::DEFAULT_MESSAGE);
    }

    public function setMaintenanceMode(bool $on, string $message): void
    {
        $message = trim($message);
        if ($message === '') {
            $message = self::DEFAULT_MESSAGE;
        }
        $this->upsert(self::KEY_MODE, $on ? 1.0 : 0.0, null);
        $this->upsert(self::KEY_MESSAGE, null, $message);
    }

    /** GPS validation radius (meters) for the Delivery Portal specifically. */
    public function deliveryRadiusMeters(): float
    {
        $value = $this->readDecimal(self::KEY_DELIVERY_RADIUS, self::DEFAULT_RADIUS_METERS);
        return $value > 0 ? $value : self::DEFAULT_RADIUS_METERS;
    }

    /** GPS validation radius (meters) for the Collection Portal specifically. */
    public function deliveryCollectionRadiusMeters(): float
    {
        $value = $this->readDecimal(self::KEY_COLLECTION_RADIUS, self::DEFAULT_RADIUS_METERS);
        return $value > 0 ? $value : self::DEFAULT_RADIUS_METERS;
    }

    public function setRadiusSettings(float $deliveryRadiusMeters, float $collectionRadiusMeters): void
    {
        if ($deliveryRadiusMeters <= 0 || $collectionRadiusMeters <= 0) {
            throw new RuntimeException('Radius values must be greater than zero.');
        }
        $this->upsert(self::KEY_DELIVERY_RADIUS, $deliveryRadiusMeters, null);
        $this->upsert(self::KEY_COLLECTION_RADIUS, $collectionRadiusMeters, 'Default GPS validation radius in meters');
    }

    private function readDecimal(string $key, float $default): float
    {
        try {
            $stmt = $this->pdo->prepare('SELECT DecimalValue FROM SystemSettings WHERE SettingKey = :key');
            $stmt->execute([':key' => $key]);
            $value = $stmt->fetchColumn();
            return ($value === false || $value === null) ? $default : (float) $value;
        } catch (Throwable $error) {
            return $default;
        }
    }

    private function readText(string $key, string $default): string
    {
        try {
            $stmt = $this->pdo->prepare('SELECT TextValue FROM SystemSettings WHERE SettingKey = :key');
            $stmt->execute([':key' => $key]);
            $value = $stmt->fetchColumn();
            return ($value === false || $value === null || trim((string) $value) === '') ? $default : (string) $value;
        } catch (Throwable $error) {
            return $default;
        }
    }

    private function upsert(string $key, ?float $decimalValue, ?string $textValue): void
    {
        $stmt = $this->pdo->prepare('
            MERGE SystemSettings AS target
            USING (SELECT CAST(:key AS nvarchar(50)) AS SettingKey) AS source
            ON target.SettingKey = source.SettingKey
            WHEN MATCHED THEN UPDATE SET
                DecimalValue = :decimalValue,
                TextValue = :textValue,
                UpdatedAt = SYSUTCDATETIME()
            WHEN NOT MATCHED THEN INSERT (SettingKey, DecimalValue, TextValue, UpdatedAt)
            VALUES (:key2, :decimalValue2, :textValue2, SYSUTCDATETIME());
        ');
        $stmt->execute([
            ':key' => $key,
            ':decimalValue' => $decimalValue,
            ':textValue' => $textValue,
            ':key2' => $key,
            ':decimalValue2' => $decimalValue,
            ':textValue2' => $textValue,
        ]);
    }
}
