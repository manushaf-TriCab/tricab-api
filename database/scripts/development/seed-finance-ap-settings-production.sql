/*
================================================================================
  PRODUCTION settings lists for Tri-AP (Phase 6, T6.17): the companies an invoice
  is billed to, cost centres and account categories.

  Written 10 Oct 2026. STATUS: NOT APPLIED. Applied to tricab-mel by Manusha or
  Jeff, never by a builder. The local database uses seed-finance-ap-sample-local.sql
  instead.

  - Idempotent / safe to re-run: a row that is missing is inserted. A row that
    exists (by name, code or position) is left exactly as it is, so an edit made
    in Tri-AP's Settings is never undone
  - Run after create-finance-invoice-schema.sql
  - Touches [finance].[invoice_companies], [invoice_cost_centres] and
    [invoice_account_categories] only. No invoice, email, file or person is
    migrated (the clean start of 8 Oct 2026)

  The rows are the lists as they stood in Tri-AP on AWS, read on 10 Oct 2026 from
  the live database (/opt/invoice-flow/server/data/invoices.sqlite3), with the
  source's ids: 7 companies, 26 cost centres and 82 account categories.

  Signing limits are NOT here (Manusha, 10 Oct 2026): they name people and their
  dollar authority, so their rows are kept out of the repository and applied from
  a file Manusha holds. Until that is run, [finance].[invoice_signing_limits] is
  empty and the approval dialog shows no signing authority.

  Left out on purpose: the one inactive company row, "TRICAB (AUSTRALIA) PTY LTD".
  It is a switched-off duplicate of "TriCab (Australia) Pty Ltd", and the two names
  differ only in case, which this database's unique key on the name treats as equal.

  The companies matter most: the extraction prompt names them, so a scan before
  this script runs can match no invoice to an entity.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRY
    BEGIN TRANSACTION;

    IF OBJECT_ID(N'[finance].[invoice_companies]', N'U') IS NULL
       OR OBJECT_ID(N'[finance].[invoice_cost_centres]', N'U') IS NULL
       OR OBJECT_ID(N'[finance].[invoice_account_categories]', N'U') IS NULL
    BEGIN
        THROW 54251, N'Tri-AP''s tables do not exist. Run create-finance-invoice-schema.sql first.', 1;
    END;

    /* ------------------------------------------------------------------
       Companies
       ------------------------------------------------------------------ */
    DECLARE @Companies TABLE (
        [id]           CHAR(32)       NOT NULL,
        [code]         NVARCHAR(50)   NOT NULL,
        [name]         NVARCHAR(200)  NOT NULL PRIMARY KEY,
        [sites]        NVARCHAR(2000) NOT NULL,
        [former_names] NVARCHAR(2000) NOT NULL
    );
    INSERT INTO @Companies ([id], [code], [name], [sites], [former_names])
    VALUES
        ('26ced7794fbb2ab3db4f2a9005d1eaf2', N'IC0003', N'Sparky Warehouse Australia Pty Ltd', N'', N''),
        ('dd22f34f171ddfbac01006d532996dd8', N'IC0004', N'Tricab (New Zealand) Ltd', N'', N''),
        ('a399495c7718fe20cbcd163f55e63600', N'IC0005', N'Tricab (USA) Distribution', N'', N''),
        ('09fcdeb73f35907631b5b0434a7907e1', N'IC0006', N'Tricab (USA) Operations', N'', N''),
        ('1e3a2d6ed7d8460195627b1e13bc6ef1', N'IC0007', N'TriCab Asia SDN. BHD..', N'', N''),
        ('40eef5df0dc18c2ab6408cb77b2d8571', N'IC0008', N'TriCab Cables Asia Pte. Ltd', N'', N''),
        ('c6424c5299b271f0913c2f356f7b2db4', N'TCA', N'TriCab (Australia) Pty Ltd', N'Somerton, Moonah, Hobart, Pooraka, Forrestfield, Port Melbourne', N'Triangle Cables Pty Ltd, Triangle Cable Pty Ltd, Triangle Cables');

    INSERT INTO [finance].[invoice_companies] ([id], [code], [name], [sites], [former_names], [is_active])
    SELECT v.[id], v.[code], v.[name], v.[sites], v.[former_names], 1
    FROM @Companies v
    WHERE NOT EXISTS (SELECT 1 FROM [finance].[invoice_companies] e WHERE e.[name] = v.[name] OR e.[id] = v.[id]);

    /* ------------------------------------------------------------------
       Cost centres
       ------------------------------------------------------------------ */
    DECLARE @CostCentres TABLE (
        [id]      CHAR(32)      NOT NULL,
        [code_no] NVARCHAR(50)  NOT NULL,
        [code]    NVARCHAR(100) NOT NULL PRIMARY KEY,
        [name]    NVARCHAR(200) NOT NULL
    );
    INSERT INTO @CostCentres ([id], [code_no], [code], [name])
    VALUES
        ('b2f9cb61a581485e962b252fdb4fe499', N'120', N'TCA-NSW', N'NSW Branch'),
        ('40012cca7d6b4b1b89cf335d4afcce7a', N'130', N'TCA-VIC', N'VIC Branch'),
        ('92de12e5203844ba9a046faf3be28a26', N'140', N'TCA-QLD', N'QLD Branch'),
        ('8aab49222db24ade9134ff474d14d808', N'150', N'TCA-SA', N'SA & NT Branch'),
        ('23a559bd5d12473387e6c6df120cf122', N'160', N'TCA-WA', N'WA Branch'),
        ('6c59636ae1204fa9939f6777736696b8', N'170', N'TCA-TAS', N'TAS Branch'),
        ('75857850739c4eed8c5426e2a0d1071a', N'180', N'TCNZ', N'New Zealand Branch'),
        ('be2bdcc10e234bdb81f02e74edb7bac5', N'181', N'TCSG', N'Singapore Branch'),
        ('c75882536f41465d9427f57631bbc927', N'185', N'TCUS', N'USA Branch'),
        ('0e06b9f303974aed89ca214c97b1a163', N'190', N'SWH-Trading', N'Sparky Warehouse'),
        ('27d92c9e352e4ebbb007d50d6d785b7a', N'510', N'PM-CopperRod', N'Copper Rod'),
        ('e8c2ee527eb04169865b60de9ba233e7', N'520', N'PM-Conductor', N'Conductor'),
        ('c9cf5e9cab4045699a08dae99017b06f', N'530', N'PM-LayUp', N'Lay Up'),
        ('b9a20f82528047eba337f62ea4ed13fd', N'540', N'PM-Extrusion', N'Extrusion'),
        ('f7c43e949a5f44a78e9fa95b82e68da3', N'550', N'PM-Printing', N'Printing Line'),
        ('d102fa53ead44961b2b467540ec1e62a', N'560', N'PM-Compound', N'Compound'),
        ('09a91f870e284366939023a7c194f046', N'610', N'PM-SupplyChain', N'Supply Chain'),
        ('593b6d20f96b4a3eb12421fd58467f19', N'620', N'PM-Maintenance', N'Maintenance'),
        ('7bbcc7f7c7314ca7a706f506c726ea19', N'630', N'PM-HR', N'Human Resources'),
        ('fa2bb8c47da649fba2c47660c2aea1ef', N'640', N'PM-Quality', N'Quality Control'),
        ('dca79f94e5f74138aa1f6e2a14773b4c', N'690', N'PM-Administration', N'Administration'),
        ('ed5216a3eedc42b0913b28054913f6a5', N'710', N'PM-TSD', N'Technical Services Department'),
        ('4a4016ac60c54d459e577d81f4e243b7', N'715', N'PM-RnD', N'Research & Development'),
        ('6bdee507bbee44d8b2d5af7b704576d1', N'810', N'PM-Finance', N'Finance'),
        ('ab94d990f2cd44f0807a671d26591c58', N'820', N'PM-IT', N'Information Technology'),
        ('566cced2551143e2bd2874d0be325529', N'890', N'PM-HeadOffice', N'Head Office');

    INSERT INTO [finance].[invoice_cost_centres] ([id], [code_no], [code], [name], [is_active])
    SELECT v.[id], v.[code_no], v.[code], v.[name], 1
    FROM @CostCentres v
    WHERE NOT EXISTS (SELECT 1 FROM [finance].[invoice_cost_centres] e WHERE e.[code] = v.[code] OR e.[id] = v.[id]);

    /* ------------------------------------------------------------------
       Account categories
       ------------------------------------------------------------------ */
    DECLARE @AccountCategories TABLE (
        [id]   CHAR(32)      NOT NULL,
        [code] NVARCHAR(100) NOT NULL PRIMARY KEY,
        [name] NVARCHAR(200) NOT NULL
    );
    INSERT INTO @AccountCategories ([id], [code], [name])
    VALUES
        ('610cfcd7ae614d7d827c10fe844496f2', N'60100', N'Salaries & Wages'),
        ('0ccba238b9c24d7788d0e6ea0056a93a', N'6010001', N'Salaries & Wages'),
        ('b2abd5fbaafa4ec1ab93001f8a9c705d', N'60110', N'Contract Labour'),
        ('6b2d2e11610b4ab2b91b274c22248193', N'60115', N'Overtime Wages'),
        ('f81da8c5a67a4bd8ac80d65aad8c5fa0', N'60120', N'Allowances'),
        ('41735ed618704885a6739b3abeea138a', N'60125', N'Annual Leave Expense'),
        ('3da6c63257904b3bb90dc107e6188013', N'60130', N'LSL Expense'),
        ('e0b00da9dc194349b4308717b61c073f', N'60135', N'Superannuation'),
        ('5243a2e8e5474229acba2a3077ccb5a7', N'60140', N'Workers Comp Reimbursements'),
        ('9479b1649986416e897ef77bea9709a6', N'60145', N'Fringe Benefits Tax'),
        ('848ef99c8d17409d96c19139bdc6e406', N'60150', N'Payroll Tax'),
        ('d333535594f04727bd974aa6827c00ab', N'60155', N'Workers'' Compensation'),
        ('fecc3ef87c86472d96f2ec45f38f06e3', N'60160', N'Profit Share - Branches'),
        ('0d131cbe57ab44aca6d95c5b8d3f2262', N'60165', N'Profit Share - Management'),
        ('ba6d0f8b644c4d859eb1f5ab961409cd', N'60170', N'Employment Costs Other'),
        ('0d997e5e094f4fd2b2661eb78ee66958', N'60171', N'Employee Dental, Life and Disability'),
        ('4d0738919e844981abeddb2c98feda46', N'60172', N'Employee Health Insurance'),
        ('a4e64e53b656476fbd481fef0dda0e68', N'61100', N'Freight - Branches'),
        ('cae72d9e2f2241f7909de0713cf3964c', N'61200', N'Freight - Customers'),
        ('10751b26f6ea4042867e6deacc18bd49', N'61300', N'Project Freight'),
        ('da88040c645d425cbc0fa415f76aeb94', N'62050', N'Warehouse Charges'),
        ('c02a161fc1ff495892434760046536a5', N'62100', N'Packing Expenses'),
        ('b7b4cc8e88eb4e1390b56193f17e7825', N'62150', N'Safety Supplies'),
        ('73990b469aac40a08426fffa9e22e09b', N'62160', N'Materials & Supplies'),
        ('5679283eb008445bbe45e71dfc311ed3', N'62200', N'Hire Charges'),
        ('7877b9250d4847dea97cf8c4c19d576a', N'62250', N'Quality Control & Testing'),
        ('40037a5a613a48a6a6679becc6d50c2f', N'62310', N'R & M - General'),
        ('133e9968744b4d41a1618b43c965002f', N'62320', N'R & M - Equipment'),
        ('29cd67fe5ac44848a5517a80db14cec1', N'62330', N'R & M - Electrical'),
        ('babdc9ffdf7b43c9adb5aaf1c9b5c7fb', N'62340', N'Maintenance Agreements'),
        ('7483d52c7fd24428985e4a36cebd7ccf', N'62350', N'Waste Disposal'),
        ('bf7029e9d5ce48ff87b22ab68529272b', N'63100', N'Rent'),
        ('1738124bea054923a8ce5e54d386823a', N'63200', N'Rent Outgoings'),
        ('fac06ad180254eecbe5ffcae48f98ace', N'63250', N'Body Corporate Expenses'),
        ('c0e54a79e1974047bb73174734d3259b', N'63510', N'Telephone'),
        ('0d3a5ce91099444d99b19a7e399d487e', N'63520', N'Electricity'),
        ('bf93cee15cce4169bbf1743d7a8e79a0', N'63530', N'Gas'),
        ('63af1859a5f341239057717494803b4a', N'63540', N'Rates & Taxes'),
        ('7ab560039aa74253865f31d23f8edbfb', N'63550', N'Water'),
        ('ea5a3765920e42899e3759a468e28a98', N'64100', N'Petrol & Oil'),
        ('9c77e1c111c24942a37982d7e3c2fc61', N'64150', N'KM Reimbursement'),
        ('4c42dbead8a14ea2935e0cf7107fcd38', N'64200', N'Motor Vehicle Lease'),
        ('66afed8170994ccd80ad6128fa196802', N'64300', N'Motor Vehicle - R & M'),
        ('cfed7efc948946808ca73a7cee381dc8', N'64400', N'Motor Vehicle - Other'),
        ('4b4bf68c695d4f498504c0bdfce0ebcb', N'64500', N'Insurance'),
        ('e822e79c6b6641b883fe7d18e54d5cb6', N'64600', N'Insurance - Trade Credit'),
        ('6212dc42e22a4da68eca4b9bbed0fe59', N'65110', N'Bad Debts'),
        ('b34211d9d0e14e0ba35f11a868b6fca0', N'65120', N'Cleaning'),
        ('db85336ea6d24a46927b34a10192a8af', N'65200', N'Computer & Information'),
        ('b7064961d5764d79966206e3290003c7', N'65240', N'Computer Licences'),
        ('35d29077e773401291d8849dc8fd27db', N'65300', N'Fees & Permits'),
        ('198d4b2407a64faf8cebb2cab3455b08', N'65310', N'Fines & penalties'),
        ('ea117bbd7ee244a5988e1c67d05b205a', N'65320', N'Office Expenses'),
        ('68e7305967f5474386876971598be5d0', N'65325', N'Postage'),
        ('e1e985e90f124fc29c860a50404418d9', N'65330', N'Printing & Stationery'),
        ('7eebfddfa8044882a269d2b1d9c63345', N'65350', N'Subscriptions'),
        ('713318ecf8694c708b38ec2d1eaefa78', N'65400', N'Relocation Expenses'),
        ('4ba9b489b42340e9a05bdda575a10ad5', N'65410', N'Security'),
        ('4ae7fe1f53064becbb3bc12c21ff436b', N'65500', N'Seminars & Training'),
        ('4916408a381944f5924d0aa1aba85f82', N'65550', N'Staff Amenities'),
        ('a32b4b8c6cad4554b7e4c3959d1f6866', N'65600', N'Uniforms'),
        ('fff99ce0fab14c729b5529e228321f8a', N'65700', N'Recruiting Expense'),
        ('32a027699c2743a891826ed45d04c3ce', N'65800', N'Employment Expenses'),
        ('fbac942e90ee4a26b2880c39ae0f0548', N'66100', N'Advertising'),
        ('2d8ab27929f44339953f8b02ba68a440', N'66200', N'Promotional & Marketing'),
        ('24d0b57eff464400b92f307402a43674', N'66300', N'Trade Show Expenses'),
        ('7fedd183edb44f4bbbd1db05c64c136b', N'66550', N'Travel - Airfares'),
        ('b6f2b15c1fca4d0aaf85871cf3997f5c', N'66560', N'Travel - Accommodation & other costs'),
        ('638df7b7ce424db9abc48843e5ffddee', N'66700', N'Meal Entertainment'),
        ('c213e57dcb2e4a349d4efdb5b4b04b5d', N'66750', N'Other Entertainment'),
        ('02c365b949c64c51891d4edabeff1ea9', N'67175', N'Accountancy Fees'),
        ('ad840b37e2f542c082adc10c067b9ee9', N'67177', N'Certifications & Approvals'),
        ('9a094036b795411e8228bb124b6fbdca', N'67180', N'Consulting Fees'),
        ('a1a3dc31421142798d6e36904edfb76c', N'67185', N'Legal & Professional Services'),
        ('09542ec6c3d543a9987e750e59c3bf3e', N'67190', N'Trademarks'),
        ('9a82d043c9d7453aa06fb19ddd3b4730', N'67195', N'Bad Debts Collection Fees'),
        ('1f00c0667b484769bc1dd352f262175d', N'67500', N'Finance Charge - Stock'),
        ('d9fc389e915446fc8a4ce3feefc83696', N'67600', N'Finance Charge - Shared Services'),
        ('3175281278b34295a0225a157e0efd55', N'68100', N'Research & Development'),
        ('6d4609a0712e4601a8a8006d665c60c3', N'68200', N'Charitable Donations'),
        ('81c978aed61d426e8bc654cfc572ca90', N'68250', N'Other Operating Expenses'),
        ('81b1bf3f34a24352ad005ece4b535214', N'68300', N'Other Taxes');

    INSERT INTO [finance].[invoice_account_categories] ([id], [code], [name], [is_active])
    SELECT v.[id], v.[code], v.[name], 1
    FROM @AccountCategories v
    WHERE NOT EXISTS (SELECT 1 FROM [finance].[invoice_account_categories] e WHERE e.[code] = v.[code] OR e.[id] = v.[id]);

    /* Every row is there, by its natural key. */
    IF (SELECT COUNT(*) FROM @Companies v JOIN [finance].[invoice_companies] e ON e.[name] = v.[name]) <> 7
       OR (SELECT COUNT(*) FROM @CostCentres v JOIN [finance].[invoice_cost_centres] e ON e.[code] = v.[code]) <> 26
       OR (SELECT COUNT(*) FROM @AccountCategories v JOIN [finance].[invoice_account_categories] e ON e.[code] = v.[code]) <> 82
    BEGIN
        THROW 54252, N'Not every Tri-AP settings row is in place. Nothing was changed.', 1;
    END;

    SELECT
        (SELECT COUNT(*) FROM [finance].[invoice_companies])          AS [Companies],
        (SELECT COUNT(*) FROM [finance].[invoice_cost_centres])       AS [CostCentres],
        (SELECT COUNT(*) FROM [finance].[invoice_account_categories]) AS [AccountCategories];

    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0
    BEGIN
        ROLLBACK TRANSACTION;
    END;

    DECLARE @ErrorMessage NVARCHAR(4000) = ERROR_MESSAGE();
    DECLARE @ErrorSeverity INT = ERROR_SEVERITY();
    DECLARE @ErrorState INT = ERROR_STATE();

    RAISERROR(@ErrorMessage, @ErrorSeverity, @ErrorState);
END CATCH;
