/* Run once on SyntaxDatabase. This migration is idempotent and keeps UserList
   available for existing delivery integrations. New application users live in
   AppUsers and receive permissions through roles, not hard-coded UI checks. */
USE SyntaxDatabase;
GO

IF OBJECT_ID(N'dbo.AppUsers', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.AppUsers (
        UserId int IDENTITY(1,1) NOT NULL PRIMARY KEY,
        Username nvarchar(50) NOT NULL UNIQUE,
        PasswordHash nvarchar(255) NOT NULL,
        FullName nvarchar(120) NOT NULL,
        IsActive bit NOT NULL CONSTRAINT DF_AppUsers_IsActive DEFAULT (1),
        CreatedAt datetime2 NOT NULL CONSTRAINT DF_AppUsers_CreatedAt DEFAULT (sysutcdatetime())
    );
END;
GO

IF OBJECT_ID(N'dbo.AppRoles', N'U') IS NULL
CREATE TABLE dbo.AppRoles (
    RoleId int IDENTITY(1,1) NOT NULL PRIMARY KEY,
    RoleName nvarchar(50) NOT NULL UNIQUE,
    Description nvarchar(200) NULL
);
GO

IF OBJECT_ID(N'dbo.AppPermissions', N'U') IS NULL
CREATE TABLE dbo.AppPermissions (
    PermissionId int IDENTITY(1,1) NOT NULL PRIMARY KEY,
    PermissionKey nvarchar(80) NOT NULL UNIQUE,
    Description nvarchar(200) NULL
);
GO

IF OBJECT_ID(N'dbo.AppUserRoles', N'U') IS NULL
CREATE TABLE dbo.AppUserRoles (
    UserId int NOT NULL,
    RoleId int NOT NULL,
    CONSTRAINT PK_AppUserRoles PRIMARY KEY (UserId, RoleId),
    CONSTRAINT FK_AppUserRoles_User FOREIGN KEY (UserId) REFERENCES dbo.AppUsers(UserId),
    CONSTRAINT FK_AppUserRoles_Role FOREIGN KEY (RoleId) REFERENCES dbo.AppRoles(RoleId)
);
GO

IF OBJECT_ID(N'dbo.AppRolePermissions', N'U') IS NULL
CREATE TABLE dbo.AppRolePermissions (
    RoleId int NOT NULL,
    PermissionId int NOT NULL,
    CONSTRAINT PK_AppRolePermissions PRIMARY KEY (RoleId, PermissionId),
    CONSTRAINT FK_AppRolePermissions_Role FOREIGN KEY (RoleId) REFERENCES dbo.AppRoles(RoleId),
    CONSTRAINT FK_AppRolePermissions_Permission FOREIGN KEY (PermissionId) REFERENCES dbo.AppPermissions(PermissionId)
);
GO

IF NOT EXISTS (SELECT 1 FROM dbo.AppRoles WHERE RoleName = N'Administrator')
INSERT dbo.AppRoles (RoleName, Description) VALUES (N'Administrator', N'Full application access');
IF NOT EXISTS (SELECT 1 FROM dbo.AppRoles WHERE RoleName = N'Delivery Driver')
INSERT dbo.AppRoles (RoleName, Description) VALUES (N'Can access assigned delivery trips only');
IF NOT EXISTS (SELECT 1 FROM dbo.AppRoles WHERE RoleName = N'Collector')
INSERT dbo.AppRoles (RoleName, Description) VALUES (N'Can record collections');
GO

IF NOT EXISTS (SELECT 1 FROM dbo.AppPermissions WHERE PermissionKey = N'delivery.confirm')
INSERT dbo.AppPermissions (PermissionKey, Description) VALUES (N'delivery.confirm', N'Confirm assigned delivery invoices');
IF NOT EXISTS (SELECT 1 FROM dbo.AppPermissions WHERE PermissionKey = N'collection.create')
INSERT dbo.AppPermissions (PermissionKey, Description) VALUES (N'collection.create', N'Create collection transactions');
IF NOT EXISTS (SELECT 1 FROM dbo.AppPermissions WHERE PermissionKey = N'users.manage')
INSERT dbo.AppPermissions (PermissionKey, Description) VALUES (N'users.manage', N'Manage users, roles and permissions');
GO

/* Before converting UserList passwords to password_hash(), first increase the
   PASSWORD column to varchar(255). Never store a password or hash in source control. */
IF COL_LENGTH(N'dbo.UserList', N'PASSWORD') IS NOT NULL
    ALTER TABLE dbo.UserList ALTER COLUMN PASSWORD varchar(255) NOT NULL;
GO
