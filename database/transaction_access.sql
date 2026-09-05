/* UserAccess module rules used by the application:
   Collection-Transactions: salesman may view only their own records.
   Collection-Transactions-Admin: administrator may view all salesmen records.
   Use User Management to assign either checkbox. */
USE SyntaxDatabase;
GO

DECLARE @UserId varchar(50) = 'ADMIN_USERID'; -- replace before running

UPDATE dbo.UserAccess
SET ACCESS = 1
WHERE USERID = @UserId AND MODULE = 'Collection-Transactions-Admin';

IF @@ROWCOUNT = 0
    INSERT dbo.UserAccess (USERID, MODULE, ACCESS)
    VALUES (@UserId, 'Collection-Transactions-Admin', 1);
GO
