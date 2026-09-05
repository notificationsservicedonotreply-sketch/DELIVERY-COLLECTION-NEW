/*
 * Test data: 1 test rider assigned 20 delivery stops across 2 trips
 * (10 each), spread around Davao City so the route map/directions feature
 * has something real to render. Everything below is guarded with
 * IF NOT EXISTS, so this file is safe to run more than once.
 *
 * Test login:
 *   UserID:   TEST-RIDER-01
 *   Password: Test@12345
 *
 * To remove this test data later:
 *   DELETE FROM TripInvoice WHERE TripID IN ('TEST-TRIP-01','TEST-TRIP-02');
 *   DELETE FROM TriplistAssign WHERE TRIPID IN ('TEST-TRIP-01','TEST-TRIP-02');
 *   DELETE FROM Customers WHERE CustomerID LIKE 'TEST-DC-%' AND CustomerID <> 'TEST-DC-001';
 *   DELETE FROM UserAccess WHERE USERID = 'TEST-RIDER-01';
 *   DELETE FROM UserList WHERE USERID = 'TEST-RIDER-01';
 *
 * NOTE: this assumes UserList has (USERID, PASSWORD, NAME, SALESMANID,
 * LocationLock, IMEI) -- the columns app/Models/UserAccessModel.php reads.
 * If your UserList table requires other NOT NULL columns too, add them to
 * the INSERT below before running.
 */
USE SyntaxDatabase;
GO

-- ---------------------------------------------------------------------
-- 1 test rider (LocationLock = 0, so you can test without real GPS
-- proximity to each customer).
-- ---------------------------------------------------------------------
IF NOT EXISTS (SELECT 1 FROM UserList WHERE USERID = 'TEST-RIDER-01')
INSERT INTO UserList (USERID, PASSWORD, NAME, SALESMANID, LocationLock, IMEI)
VALUES ('TEST-RIDER-01', '$2y$10$j5D2yXqkjvw/PyHNvT.y0eCRlCpzLrnvMYmYg88bluNP8l7OWL3Ti', 'MARS Test Rider', 'TEST-RIDER-01', 0, '');

-- Set separately (not in the INSERT above) so this script still works
-- whether or not your UserList.DATABASENAME column requires a value --
-- adjust 'MAIN' to match a real DATABASENAME value in your Customers /
-- InvoiceList data if you want the multi-branch customer/invoice search
-- scoping to actually filter something during testing.
IF EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'UserList' AND COLUMN_NAME = 'DATABASENAME')
UPDATE UserList SET DATABASENAME = 'MAIN' WHERE USERID = 'TEST-RIDER-01' AND (DATABASENAME IS NULL OR DATABASENAME = '');

IF NOT EXISTS (SELECT 1 FROM UserAccess WHERE USERID = 'TEST-RIDER-01' AND MODULE = 'Delivery-Portal')
INSERT INTO UserAccess (USERID, MODULE, ACCESS) VALUES ('TEST-RIDER-01', 'Delivery-Portal', 1);

IF NOT EXISTS (SELECT 1 FROM UserAccess WHERE USERID = 'TEST-RIDER-01' AND MODULE = 'Collection-Portal')
INSERT INTO UserAccess (USERID, MODULE, ACCESS) VALUES ('TEST-RIDER-01', 'Collection-Portal', 1);

IF NOT EXISTS (SELECT 1 FROM UserAccess WHERE USERID = 'TEST-RIDER-01' AND MODULE = 'Delivery-Transactions')
INSERT INTO UserAccess (USERID, MODULE, ACCESS) VALUES ('TEST-RIDER-01', 'Delivery-Transactions', 1);

-- ---------------------------------------------------------------------
-- 20 customers spread across Davao City barangays.
-- ---------------------------------------------------------------------
IF NOT EXISTS (SELECT 1 FROM Customers WHERE CustomerID = 'TEST-DC-002')
INSERT INTO Customers (CustomerID, CustomerName, Street, Barangay, Municipality, Province, Latitude, Longitude)
VALUES ('TEST-DC-002', 'DELOS SANTOS, JUAN M.', 'Quimpo Blvd.', 'Ecoland', 'DAVAO CITY', 'DAVAO DEL SUR', '7.0522000', '125.6070000');

IF NOT EXISTS (SELECT 1 FROM Customers WHERE CustomerID = 'TEST-DC-003')
INSERT INTO Customers (CustomerID, CustomerName, Street, Barangay, Municipality, Province, Latitude, Longitude)
VALUES ('TEST-DC-003', 'REYES, MARIA C.', 'Mt. Apo St.', 'Matina', 'DAVAO CITY', 'DAVAO DEL SUR', '7.0644000', '125.6013000');

IF NOT EXISTS (SELECT 1 FROM Customers WHERE CustomerID = 'TEST-DC-004')
INSERT INTO Customers (CustomerID, CustomerName, Street, Barangay, Municipality, Province, Latitude, Longitude)
VALUES ('TEST-DC-004', 'CRUZ, PEDRO B.', 'Roxas Ave.', 'Poblacion', 'DAVAO CITY', 'DAVAO DEL SUR', '7.0731000', '125.6128000');

IF NOT EXISTS (SELECT 1 FROM Customers WHERE CustomerID = 'TEST-DC-005')
INSERT INTO Customers (CustomerID, CustomerName, Street, Barangay, Municipality, Province, Latitude, Longitude)
VALUES ('TEST-DC-005', 'GARCIA, ANA L.', 'JP Laurel Ave.', 'Bajada', 'DAVAO CITY', 'DAVAO DEL SUR', '7.0862000', '125.6118000');

IF NOT EXISTS (SELECT 1 FROM Customers WHERE CustomerID = 'TEST-DC-006')
INSERT INTO Customers (CustomerID, CustomerName, Street, Barangay, Municipality, Province, Latitude, Longitude)
VALUES ('TEST-DC-006', 'SANTOS, RICARDO T.', 'Cabaguio Ave.', 'Buhangin', 'DAVAO CITY', 'DAVAO DEL SUR', '7.1012000', '125.6252000');

IF NOT EXISTS (SELECT 1 FROM Customers WHERE CustomerID = 'TEST-DC-007')
INSERT INTO Customers (CustomerID, CustomerName, Street, Barangay, Municipality, Province, Latitude, Longitude)
VALUES ('TEST-DC-007', 'FLORES, LINDA M.', 'Lanang Blvd.', 'Lanang', 'DAVAO CITY', 'DAVAO DEL SUR', '7.1103000', '125.6465000');

IF NOT EXISTS (SELECT 1 FROM Customers WHERE CustomerID = 'TEST-DC-008')
INSERT INTO Customers (CustomerID, CustomerName, Street, Barangay, Municipality, Province, Latitude, Longitude)
VALUES ('TEST-DC-008', 'RAMOS, EDUARDO S.', 'Sasa Wharf Rd.', 'Sasa', 'DAVAO CITY', 'DAVAO DEL SUR', '7.1204000', '125.6497000');

IF NOT EXISTS (SELECT 1 FROM Customers WHERE CustomerID = 'TEST-DC-009')
INSERT INTO Customers (CustomerID, CustomerName, Street, Barangay, Municipality, Province, Latitude, Longitude)
VALUES ('TEST-DC-009', 'MENDOZA, CARMEN P.', 'Bangkal Rd.', 'Bangkal', 'DAVAO CITY', 'DAVAO DEL SUR', '7.0554000', '125.5978000');

IF NOT EXISTS (SELECT 1 FROM Customers WHERE CustomerID = 'TEST-DC-010')
INSERT INTO Customers (CustomerID, CustomerName, Street, Barangay, Municipality, Province, Latitude, Longitude)
VALUES ('TEST-DC-010', 'TORRES, MANUEL D.', 'Ma-a Rd.', 'Ma-a', 'DAVAO CITY', 'DAVAO DEL SUR', '7.0788000', '125.5893000');

IF NOT EXISTS (SELECT 1 FROM Customers WHERE CustomerID = 'TEST-DC-011')
INSERT INTO Customers (CustomerID, CustomerName, Street, Barangay, Municipality, Province, Latitude, Longitude)
VALUES ('TEST-DC-011', 'AQUINO, ROSA V.', 'Bolton St.', 'Poblacion', 'DAVAO CITY', 'DAVAO DEL SUR', '7.0697000', '125.6104000');

IF NOT EXISTS (SELECT 1 FROM Customers WHERE CustomerID = 'TEST-DC-012')
INSERT INTO Customers (CustomerID, CustomerName, Street, Barangay, Municipality, Province, Latitude, Longitude)
VALUES ('TEST-DC-012', 'DIAZ, FERNANDO R.', 'Angliongto Ave.', 'Bajada', 'DAVAO CITY', 'DAVAO DEL SUR', '7.0839000', '125.6087000');

IF NOT EXISTS (SELECT 1 FROM Customers WHERE CustomerID = 'TEST-DC-013')
INSERT INTO Customers (CustomerID, CustomerName, Street, Barangay, Municipality, Province, Latitude, Longitude)
VALUES ('TEST-DC-013', 'CASTRO, ELENA F.', 'Ilustre St.', 'Poblacion', 'DAVAO CITY', 'DAVAO DEL SUR', '7.0705000', '125.6156000');

IF NOT EXISTS (SELECT 1 FROM Customers WHERE CustomerID = 'TEST-DC-014')
INSERT INTO Customers (CustomerID, CustomerName, Street, Barangay, Municipality, Province, Latitude, Longitude)
VALUES ('TEST-DC-014', 'MORALES, ANTONIO G.', 'Quirino Ave.', 'Talomo', 'DAVAO CITY', 'DAVAO DEL SUR', '7.0432000', '125.5877000');

IF NOT EXISTS (SELECT 1 FROM Customers WHERE CustomerID = 'TEST-DC-015')
INSERT INTO Customers (CustomerID, CustomerName, Street, Barangay, Municipality, Province, Latitude, Longitude)
VALUES ('TEST-DC-015', 'ROMERO, GLORIA H.', 'Toril Public Market Rd.', 'Toril', 'DAVAO CITY', 'DAVAO DEL SUR', '7.0006000', '125.4954000');

IF NOT EXISTS (SELECT 1 FROM Customers WHERE CustomerID = 'TEST-DC-016')
INSERT INTO Customers (CustomerID, CustomerName, Street, Barangay, Municipality, Province, Latitude, Longitude)
VALUES ('TEST-DC-016', 'HERNANDEZ, RAUL K.', 'Agdao Public Market Rd.', 'Agdao', 'DAVAO CITY', 'DAVAO DEL SUR', '7.0891000', '125.6231000');

IF NOT EXISTS (SELECT 1 FROM Customers WHERE CustomerID = 'TEST-DC-017')
INSERT INTO Customers (CustomerID, CustomerName, Street, Barangay, Municipality, Province, Latitude, Longitude)
VALUES ('TEST-DC-017', 'GUTIERREZ, SOFIA N.', 'Bunawan Diversion Rd.', 'Bunawan', 'DAVAO CITY', 'DAVAO DEL SUR', '7.1421000', '125.6532000');

IF NOT EXISTS (SELECT 1 FROM Customers WHERE CustomerID = 'TEST-DC-018')
INSERT INTO Customers (CustomerID, CustomerName, Street, Barangay, Municipality, Province, Latitude, Longitude)
VALUES ('TEST-DC-018', 'VARGAS, MIGUEL O.', 'Catalunan Grande Rd.', 'Catalunan Grande', 'DAVAO CITY', 'DAVAO DEL SUR', '7.0512000', '125.5687000');

IF NOT EXISTS (SELECT 1 FROM Customers WHERE CustomerID = 'TEST-DC-019')
INSERT INTO Customers (CustomerID, CustomerName, Street, Barangay, Municipality, Province, Latitude, Longitude)
VALUES ('TEST-DC-019', 'JIMENEZ, PATRICIA Q.', 'Km 9 Rd.', 'Mintal', 'DAVAO CITY', 'DAVAO DEL SUR', '7.0285000', '125.5432000');

IF NOT EXISTS (SELECT 1 FROM Customers WHERE CustomerID = 'TEST-DC-020')
INSERT INTO Customers (CustomerID, CustomerName, Street, Barangay, Municipality, Province, Latitude, Longitude)
VALUES ('TEST-DC-020', 'PASCUAL, ROBERTO W.', 'Ulas Public Market Rd.', 'Ulas', 'DAVAO CITY', 'DAVAO DEL SUR', '7.0378000', '125.5769000');

IF NOT EXISTS (SELECT 1 FROM Customers WHERE CustomerID = 'TEST-DC-021')
INSERT INTO Customers (CustomerID, CustomerName, Street, Barangay, Municipality, Province, Latitude, Longitude)
VALUES ('TEST-DC-021', 'NAVARRO, ISABEL X.', 'Panacan Junction', 'Panacan', 'DAVAO CITY', 'DAVAO DEL SUR', '7.1298000', '125.6389000');
GO

-- ---------------------------------------------------------------------
-- 2 trips assigned to the test rider (10 stops each).
-- ---------------------------------------------------------------------
IF NOT EXISTS (SELECT 1 FROM TriplistAssign WHERE USERID = 'TEST-RIDER-01' AND TRIPID = 'TEST-TRIP-01')
INSERT INTO TriplistAssign (USERID, TRIPID, Status) VALUES ('TEST-RIDER-01', 'TEST-TRIP-01', 1);

IF NOT EXISTS (SELECT 1 FROM TriplistAssign WHERE USERID = 'TEST-RIDER-01' AND TRIPID = 'TEST-TRIP-02')
INSERT INTO TriplistAssign (USERID, TRIPID, Status) VALUES ('TEST-RIDER-01', 'TEST-TRIP-02', 1);
GO

-- ---------------------------------------------------------------------
-- 20 pending delivery invoices (10 per trip), one per customer above.
-- ---------------------------------------------------------------------
IF NOT EXISTS (SELECT 1 FROM TripInvoice WHERE TripID = 'TEST-TRIP-01' AND InvoiceNo = 'TEST-INV-101')
INSERT INTO TripInvoice (TripID, InvoiceNo, DrNo, CustomerID, TotalCrtns, SortNum)
VALUES ('TEST-TRIP-01', 'TEST-INV-101', 'TEST-DR-101', 'TEST-DC-002', 12, 1);

IF NOT EXISTS (SELECT 1 FROM TripInvoice WHERE TripID = 'TEST-TRIP-01' AND InvoiceNo = 'TEST-INV-102')
INSERT INTO TripInvoice (TripID, InvoiceNo, DrNo, CustomerID, TotalCrtns, SortNum)
VALUES ('TEST-TRIP-01', 'TEST-INV-102', 'TEST-DR-102', 'TEST-DC-003', 8, 2);

IF NOT EXISTS (SELECT 1 FROM TripInvoice WHERE TripID = 'TEST-TRIP-01' AND InvoiceNo = 'TEST-INV-103')
INSERT INTO TripInvoice (TripID, InvoiceNo, DrNo, CustomerID, TotalCrtns, SortNum)
VALUES ('TEST-TRIP-01', 'TEST-INV-103', 'TEST-DR-103', 'TEST-DC-004', 15, 3);

IF NOT EXISTS (SELECT 1 FROM TripInvoice WHERE TripID = 'TEST-TRIP-01' AND InvoiceNo = 'TEST-INV-104')
INSERT INTO TripInvoice (TripID, InvoiceNo, DrNo, CustomerID, TotalCrtns, SortNum)
VALUES ('TEST-TRIP-01', 'TEST-INV-104', 'TEST-DR-104', 'TEST-DC-005', 20, 4);

IF NOT EXISTS (SELECT 1 FROM TripInvoice WHERE TripID = 'TEST-TRIP-01' AND InvoiceNo = 'TEST-INV-105')
INSERT INTO TripInvoice (TripID, InvoiceNo, DrNo, CustomerID, TotalCrtns, SortNum)
VALUES ('TEST-TRIP-01', 'TEST-INV-105', 'TEST-DR-105', 'TEST-DC-006', 6, 5);

IF NOT EXISTS (SELECT 1 FROM TripInvoice WHERE TripID = 'TEST-TRIP-01' AND InvoiceNo = 'TEST-INV-106')
INSERT INTO TripInvoice (TripID, InvoiceNo, DrNo, CustomerID, TotalCrtns, SortNum)
VALUES ('TEST-TRIP-01', 'TEST-INV-106', 'TEST-DR-106', 'TEST-DC-007', 10, 6);

IF NOT EXISTS (SELECT 1 FROM TripInvoice WHERE TripID = 'TEST-TRIP-01' AND InvoiceNo = 'TEST-INV-107')
INSERT INTO TripInvoice (TripID, InvoiceNo, DrNo, CustomerID, TotalCrtns, SortNum)
VALUES ('TEST-TRIP-01', 'TEST-INV-107', 'TEST-DR-107', 'TEST-DC-008', 18, 7);

IF NOT EXISTS (SELECT 1 FROM TripInvoice WHERE TripID = 'TEST-TRIP-01' AND InvoiceNo = 'TEST-INV-108')
INSERT INTO TripInvoice (TripID, InvoiceNo, DrNo, CustomerID, TotalCrtns, SortNum)
VALUES ('TEST-TRIP-01', 'TEST-INV-108', 'TEST-DR-108', 'TEST-DC-009', 9, 8);

IF NOT EXISTS (SELECT 1 FROM TripInvoice WHERE TripID = 'TEST-TRIP-01' AND InvoiceNo = 'TEST-INV-109')
INSERT INTO TripInvoice (TripID, InvoiceNo, DrNo, CustomerID, TotalCrtns, SortNum)
VALUES ('TEST-TRIP-01', 'TEST-INV-109', 'TEST-DR-109', 'TEST-DC-010', 14, 9);

IF NOT EXISTS (SELECT 1 FROM TripInvoice WHERE TripID = 'TEST-TRIP-01' AND InvoiceNo = 'TEST-INV-110')
INSERT INTO TripInvoice (TripID, InvoiceNo, DrNo, CustomerID, TotalCrtns, SortNum)
VALUES ('TEST-TRIP-01', 'TEST-INV-110', 'TEST-DR-110', 'TEST-DC-011', 11, 10);

IF NOT EXISTS (SELECT 1 FROM TripInvoice WHERE TripID = 'TEST-TRIP-02' AND InvoiceNo = 'TEST-INV-201')
INSERT INTO TripInvoice (TripID, InvoiceNo, DrNo, CustomerID, TotalCrtns, SortNum)
VALUES ('TEST-TRIP-02', 'TEST-INV-201', 'TEST-DR-201', 'TEST-DC-012', 7, 1);

IF NOT EXISTS (SELECT 1 FROM TripInvoice WHERE TripID = 'TEST-TRIP-02' AND InvoiceNo = 'TEST-INV-202')
INSERT INTO TripInvoice (TripID, InvoiceNo, DrNo, CustomerID, TotalCrtns, SortNum)
VALUES ('TEST-TRIP-02', 'TEST-INV-202', 'TEST-DR-202', 'TEST-DC-013', 13, 2);

IF NOT EXISTS (SELECT 1 FROM TripInvoice WHERE TripID = 'TEST-TRIP-02' AND InvoiceNo = 'TEST-INV-203')
INSERT INTO TripInvoice (TripID, InvoiceNo, DrNo, CustomerID, TotalCrtns, SortNum)
VALUES ('TEST-TRIP-02', 'TEST-INV-203', 'TEST-DR-203', 'TEST-DC-014', 5, 3);

IF NOT EXISTS (SELECT 1 FROM TripInvoice WHERE TripID = 'TEST-TRIP-02' AND InvoiceNo = 'TEST-INV-204')
INSERT INTO TripInvoice (TripID, InvoiceNo, DrNo, CustomerID, TotalCrtns, SortNum)
VALUES ('TEST-TRIP-02', 'TEST-INV-204', 'TEST-DR-204', 'TEST-DC-015', 16, 4);

IF NOT EXISTS (SELECT 1 FROM TripInvoice WHERE TripID = 'TEST-TRIP-02' AND InvoiceNo = 'TEST-INV-205')
INSERT INTO TripInvoice (TripID, InvoiceNo, DrNo, CustomerID, TotalCrtns, SortNum)
VALUES ('TEST-TRIP-02', 'TEST-INV-205', 'TEST-DR-205', 'TEST-DC-016', 19, 5);

IF NOT EXISTS (SELECT 1 FROM TripInvoice WHERE TripID = 'TEST-TRIP-02' AND InvoiceNo = 'TEST-INV-206')
INSERT INTO TripInvoice (TripID, InvoiceNo, DrNo, CustomerID, TotalCrtns, SortNum)
VALUES ('TEST-TRIP-02', 'TEST-INV-206', 'TEST-DR-206', 'TEST-DC-017', 4, 6);

IF NOT EXISTS (SELECT 1 FROM TripInvoice WHERE TripID = 'TEST-TRIP-02' AND InvoiceNo = 'TEST-INV-207')
INSERT INTO TripInvoice (TripID, InvoiceNo, DrNo, CustomerID, TotalCrtns, SortNum)
VALUES ('TEST-TRIP-02', 'TEST-INV-207', 'TEST-DR-207', 'TEST-DC-018', 17, 7);

IF NOT EXISTS (SELECT 1 FROM TripInvoice WHERE TripID = 'TEST-TRIP-02' AND InvoiceNo = 'TEST-INV-208')
INSERT INTO TripInvoice (TripID, InvoiceNo, DrNo, CustomerID, TotalCrtns, SortNum)
VALUES ('TEST-TRIP-02', 'TEST-INV-208', 'TEST-DR-208', 'TEST-DC-019', 3, 8);

IF NOT EXISTS (SELECT 1 FROM TripInvoice WHERE TripID = 'TEST-TRIP-02' AND InvoiceNo = 'TEST-INV-209')
INSERT INTO TripInvoice (TripID, InvoiceNo, DrNo, CustomerID, TotalCrtns, SortNum)
VALUES ('TEST-TRIP-02', 'TEST-INV-209', 'TEST-DR-209', 'TEST-DC-020', 21, 9);

IF NOT EXISTS (SELECT 1 FROM TripInvoice WHERE TripID = 'TEST-TRIP-02' AND InvoiceNo = 'TEST-INV-210')
INSERT INTO TripInvoice (TripID, InvoiceNo, DrNo, CustomerID, TotalCrtns, SortNum)
VALUES ('TEST-TRIP-02', 'TEST-INV-210', 'TEST-DR-210', 'TEST-DC-021', 22, 10);
GO
