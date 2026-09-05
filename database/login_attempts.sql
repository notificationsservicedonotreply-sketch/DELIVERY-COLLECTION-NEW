-- Optional, but recommended: durable brute-force lockout.
-- Without this table, login throttling falls back to a session-based
-- counter, which resets whenever the attacker clears cookies. This table
-- keys lockouts by a hash of (client IP + attempted userID), which survives
-- across sessions and devices.
--
-- The application detects this table automatically (same pattern already
-- used elsewhere in this codebase, e.g. delivery_collection_customer_unlock.sql)
-- and degrades gracefully to session-only throttling if it is absent.

IF NOT EXISTS (SELECT 1 FROM sys.tables WHERE name = 'LoginAttempts')
BEGIN
    CREATE TABLE LoginAttempts (
        ID INT IDENTITY(1,1) PRIMARY KEY,
        AttemptKey VARCHAR(128) NOT NULL,      -- sha256(ip + '|' + userID)
        FailCount INT NOT NULL DEFAULT 0,
        LockedUntil DATETIME NULL,
        LastAttempt DATETIME NOT NULL DEFAULT GETDATE()
    );

    CREATE UNIQUE INDEX IX_LoginAttempts_Key ON LoginAttempts (AttemptKey);
END
