/* Run once in SyntaxDatabase to allow location-range exceptions for selected customers. */
USE [SyntaxDatabase];
GO

IF OBJECT_ID(N'dbo.CustomerUnlockList', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.CustomerUnlockList (
        ID int IDENTITY(1,1) NOT NULL PRIMARY KEY,
        CUSTOMERID varchar(50) NOT NULL,
        CreatedAt datetime2 NOT NULL CONSTRAINT DF_CustomerUnlockList_CreatedAt DEFAULT SYSUTCDATETIME(),
        CONSTRAINT UQ_CustomerUnlockList_CustomerID UNIQUE (CUSTOMERID)
    );
END
GO

/* Example: unlock only this customer.
INSERT INTO dbo.CustomerUnlockList (CUSTOMERID) VALUES ('Cust1'); */
