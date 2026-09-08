/* System Maintenance feature -- see app/Models/SystemSettingsRepository.php.
   Uses the same SystemSettings key-value table as
   delivery_collection_settings.sql. The app upserts these rows itself the
   first time an admin saves the Settings > System Maintenance section, so
   running this script isn't required -- it's here so the rows (and their
   default OFF state) are documented and can be pre-seeded if you'd rather
   not rely on the app's first save. */
USE [SyntaxDatabase];
GO

MERGE dbo.SystemSettings AS target
USING (SELECT CAST('MaintenanceMode' AS nvarchar(50)) AS SettingKey) AS source
ON target.SettingKey = source.SettingKey
WHEN MATCHED THEN UPDATE SET DecimalValue = ISNULL(target.DecimalValue, 0), UpdatedAt = SYSUTCDATETIME()
WHEN NOT MATCHED THEN INSERT (SettingKey, DecimalValue, TextValue, UpdatedAt)
VALUES ('MaintenanceMode', 0, NULL, SYSUTCDATETIME());
GO

MERGE dbo.SystemSettings AS target
USING (SELECT CAST('MaintenanceMessage' AS nvarchar(50)) AS SettingKey) AS source
ON target.SettingKey = source.SettingKey
WHEN MATCHED THEN UPDATE SET TextValue = ISNULL(target.TextValue, N'The system is currently undergoing scheduled maintenance. Please check back shortly.'), UpdatedAt = SYSUTCDATETIME()
WHEN NOT MATCHED THEN INSERT (SettingKey, DecimalValue, TextValue, UpdatedAt)
VALUES ('MaintenanceMessage', NULL, N'The system is currently undergoing scheduled maintenance. Please check back shortly.', SYSUTCDATETIME());
GO

/* To turn maintenance mode on/off directly from SQL instead of the Settings
   page (e.g. if you're locked out and need a DBA to flip it back):
UPDATE dbo.SystemSettings SET DecimalValue = 1, UpdatedAt = SYSUTCDATETIME() WHERE SettingKey = 'MaintenanceMode'; -- ON
UPDATE dbo.SystemSettings SET DecimalValue = 0, UpdatedAt = SYSUTCDATETIME() WHERE SettingKey = 'MaintenanceMode'; -- OFF
*/
