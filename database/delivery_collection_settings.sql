/* SyntaxDatabase configuration only. Your supplied schema already contains the application tables. */
USE [SyntaxDatabase];
GO

MERGE dbo.SystemSettings AS target
USING (SELECT CAST('DeliveryCollectionRadiusMeters' AS nvarchar(50)) AS SettingKey) AS source
ON target.SettingKey = source.SettingKey
WHEN MATCHED THEN UPDATE SET DecimalValue = ISNULL(target.DecimalValue, 5), UpdatedAt = SYSUTCDATETIME()
WHEN NOT MATCHED THEN INSERT (SettingKey, DecimalValue, TextValue, UpdatedAt)
VALUES ('DeliveryCollectionRadiusMeters', 5, N'Default GPS validation radius in meters', SYSUTCDATETIME());
GO

/* Change only with operations approval:
UPDATE dbo.SystemSettings SET DecimalValue = 10, UpdatedAt = SYSUTCDATETIME()
WHERE SettingKey = 'DeliveryCollectionRadiusMeters'; */
