/* Run once in SyntaxDatabase. Converts collection-save text columns to Unicode. */
USE [SyntaxDatabase];
GO

BEGIN TRANSACTION;
BEGIN TRY
    ALTER TABLE dbo.CollectionSyntaxHdr DROP CONSTRAINT PK_CollectionSyntaxHdr;

    ALTER TABLE dbo.CollectionSyntaxHdr ALTER COLUMN PRNUMBER nvarchar(50) NOT NULL;
    ALTER TABLE dbo.CollectionSyntaxHdr ALTER COLUMN CUSTOMERID nvarchar(50) NOT NULL;
    ALTER TABLE dbo.CollectionSyntaxHdr ALTER COLUMN SALESMAN nvarchar(50) NOT NULL;
    ALTER TABLE dbo.CollectionSyntaxHdr ALTER COLUMN SYNTAXREFERENCE nvarchar(50) NOT NULL;
    ALTER TABLE dbo.CollectionSyntaxHdr ALTER COLUMN PR_CONFIRMED nvarchar(50) NULL;
    ALTER TABLE dbo.CollectionSyntaxHdr ADD CONSTRAINT PK_CollectionSyntaxHdr PRIMARY KEY CLUSTERED (PRNUMBER, SALESMAN, CUSTOMERID);

    ALTER TABLE dbo.CollectionSyntaxDtl ALTER COLUMN REFID nvarchar(50) NOT NULL;
    ALTER TABLE dbo.CollectionSyntaxDtl ALTER COLUMN PAYMENTTYPE nvarchar(50) NOT NULL;
    ALTER TABLE dbo.CollectionSyntaxDtl ALTER COLUMN BANKINITIAL nvarchar(50) NULL;
    ALTER TABLE dbo.CollectionSyntaxDtl ALTER COLUMN CHECKNUMBER nvarchar(50) NULL;
    ALTER TABLE dbo.CollectionSyntaxInvDtl ALTER COLUMN REFID nvarchar(50) NOT NULL;
    ALTER TABLE dbo.CollectionSyntaxInvDtl ALTER COLUMN INVOICENO nvarchar(50) NOT NULL;
    ALTER TABLE dbo.CollectionSyntaxCategory ALTER COLUMN REFID nvarchar(50) NOT NULL;
    ALTER TABLE dbo.CollectionSyntaxCategory ALTER COLUMN OTHERREF nvarchar(200) NULL;
    ALTER TABLE dbo.FileAttachment ALTER COLUMN ATTACH_REFID_DTL nvarchar(50) NOT NULL;

    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
    THROW;
END CATCH;
GO
