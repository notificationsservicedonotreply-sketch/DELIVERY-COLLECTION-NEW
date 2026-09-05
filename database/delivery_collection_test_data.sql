/* Test records for the supplied SyntaxDatabase structure. Remove or change before production use. */
USE SyntaxDatabase;
GO
IF NOT EXISTS (SELECT 1 FROM Customers WHERE CustomerID = 'TEST-DC-001')
INSERT INTO Customers (CustomerID, CustomerName, Street, Barangay, Municipality, Province, Latitude, Longitude)
VALUES ('TEST-DC-001', 'MARS Test Customer', 'Test Street', 'Test Barangay', 'Cebu City', 'Cebu', '10.3157000', '123.8854000');
IF NOT EXISTS (SELECT 1 FROM InvoiceList WHERE REFID = 'TEST-INV-10000')
INSERT INTO InvoiceList (REFID,CUSTOMERID,INVOICEDATE,BALANCE,DEPARTMENT,DELIVERYDATE,SALESMANID,DATABASENAME)
VALUES ('TEST-INV-10000','TEST-DC-001',CAST(GETDATE() AS date),10000,'TEST',CAST(GETDATE() AS date),'TEST','SyntaxDatabase');
IF NOT EXISTS (SELECT 1 FROM SplitCategory WHERE catid = 1)
INSERT INTO SplitCategory (CATEGORY,catid,Req_attach) VALUES ('BAD ORDER',1,1);
IF NOT EXISTS (SELECT 1 FROM SplitCategory WHERE catid = 2)
INSERT INTO SplitCategory (CATEGORY,catid,Req_attach) VALUES ('EWT',2,1);
GO
