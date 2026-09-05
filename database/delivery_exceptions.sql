-- REQUIRED for the delivery-exception ("not received") feature and for
-- trips to auto-close when every stop is resolved.
--
-- Adds two columns to TripInvoice so a rider can record why a delivery
-- wasn't received (customer absent, store closed, refused, etc.) as an
-- alternative to marking it delivered. An invoice with either DeliveredDate
-- or NotDeliveredReason set counts as "resolved" -- once every invoice in a
-- trip is resolved, that TriplistAssign row's Status is automatically
-- flipped from True to False.
--
-- Run this once before deploying the updated app code. Without it, the
-- delivery-confirmation endpoint will fail outright (it queries these
-- columns), not just the new "not received" button.

IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('TripInvoice') AND name = 'NotDeliveredReason')
BEGIN
    ALTER TABLE TripInvoice ADD NotDeliveredReason NVARCHAR(255) NULL;
END

IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('TripInvoice') AND name = 'NotDeliveredDate')
BEGIN
    ALTER TABLE TripInvoice ADD NotDeliveredDate DATETIME NULL;
END
