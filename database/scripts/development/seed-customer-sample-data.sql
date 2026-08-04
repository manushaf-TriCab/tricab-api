/*
================================================================================
  DEVELOPMENT-ONLY sample seed: Customer / Contact foundation (Stage 2).

  Idempotent / safe to re-run.
  Does NOT migrate WordPress passwords or activation keys.
  Does NOT create fake Entra oid/tenant ExternalIdentity rows.
  ExternalIdentity for email OTP is left to first successful OTP verification.

  Seeds:
    - 3 customers (Melbourne / Sydney / Brisbane sample companies)
    - 2 contacts per customer
    - 1 portal user: customer.admin@example.com (no username/password)
    - CustomerContactUsers link for Melbourne primary contact
    - CUSTOMER_ADMIN role assignment IF that role already exists (Stage 3)
    - One migration.LegacyCustomerUserMappings sample row (status created-user)

  Requires:
    - [customer] / [migration] tables (create-customer-schema.sql)
    - [identity].[Users] / [identity].[UserStatuses]
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRY
    BEGIN TRANSACTION;

    IF OBJECT_ID(N'[customer].[Customers]', N'U') IS NULL
        OR OBJECT_ID(N'[customer].[CustomerContacts]', N'U') IS NULL
        OR OBJECT_ID(N'[customer].[CustomerContactUsers]', N'U') IS NULL
    BEGIN
        THROW 52001, N'Customer schema missing. Run create-customer-schema.sql first.', 1;
    END;

    DECLARE @ActiveStatusId SMALLINT = NULL;
    SELECT @ActiveStatusId = [UserStatusId]
    FROM [identity].[UserStatuses]
    WHERE [StatusCode] = N'active';

    IF @ActiveStatusId IS NULL
    BEGIN
        THROW 52002, N'identity.UserStatuses is missing StatusCode=active.', 1;
    END;

    /* ------------------------------------------------------------------
       Customers (keyed by CustomerCode for idempotency)
       ------------------------------------------------------------------ */
    DECLARE @MelbourneCustomerId BIGINT;
    DECLARE @SydneyCustomerId BIGINT;
    DECLARE @BrisbaneCustomerId BIGINT;

    IF NOT EXISTS (SELECT 1 FROM [customer].[Customers] WHERE [CustomerCode] = N'MEL-ELEC-001')
    BEGIN
        INSERT INTO [customer].[Customers] (
            [LegacyCustomerId], [CustomerCode], [CustomerName], [StatusCode],
            [AddressLine1], [Suburb], [Postcode], [CountryCode],
            [MarketCode], [SalesRepresentative], [TerritoryCode], [PhoneNumber], [IsActive]
        )
        VALUES (
            900001, N'MEL-ELEC-001', N'Melbourne Electrical Supplies', N'active',
            N'12 Flinders Street', N'Melbourne', N'3000', N'AU',
            N'ELECTRICAL', N'Sample Rep VIC', N'VIC-METRO', N'+61 3 9000 1001', 1
        );
    END;

    SELECT @MelbourneCustomerId = [CustomerId]
    FROM [customer].[Customers]
    WHERE [CustomerCode] = N'MEL-ELEC-001';

    IF NOT EXISTS (SELECT 1 FROM [customer].[Customers] WHERE [CustomerCode] = N'SYD-IND-001')
    BEGIN
        INSERT INTO [customer].[Customers] (
            [LegacyCustomerId], [CustomerCode], [CustomerName], [StatusCode],
            [AddressLine1], [Suburb], [Postcode], [CountryCode],
            [MarketCode], [SalesRepresentative], [TerritoryCode], [PhoneNumber], [IsActive]
        )
        VALUES (
            900002, N'SYD-IND-001', N'Sydney Industrial Cables', N'active',
            N'88 George Street', N'Sydney', N'2000', N'AU',
            N'INDUSTRIAL', N'Sample Rep NSW', N'NSW-METRO', N'+61 2 9000 2002', 1
        );
    END;

    SELECT @SydneyCustomerId = [CustomerId]
    FROM [customer].[Customers]
    WHERE [CustomerCode] = N'SYD-IND-001';

    IF NOT EXISTS (SELECT 1 FROM [customer].[Customers] WHERE [CustomerCode] = N'BNE-ENR-001')
    BEGIN
        INSERT INTO [customer].[Customers] (
            [LegacyCustomerId], [CustomerCode], [CustomerName], [StatusCode],
            [AddressLine1], [Suburb], [Postcode], [CountryCode],
            [MarketCode], [SalesRepresentative], [TerritoryCode], [PhoneNumber], [IsActive]
        )
        VALUES (
            900003, N'BNE-ENR-001', N'Brisbane Energy Systems', N'active',
            N'45 Queen Street', N'Brisbane', N'4000', N'AU',
            N'ENERGY', N'Sample Rep QLD', N'QLD-METRO', N'+61 7 9000 3003', 1
        );
    END;

    SELECT @BrisbaneCustomerId = [CustomerId]
    FROM [customer].[Customers]
    WHERE [CustomerCode] = N'BNE-ENR-001';

    IF @MelbourneCustomerId IS NULL OR @SydneyCustomerId IS NULL OR @BrisbaneCustomerId IS NULL
    BEGIN
        THROW 52003, N'Sample customers could not be resolved after seed.', 1;
    END;

    /* ------------------------------------------------------------------
       Contacts — 2 per customer (idempotent on CustomerId + NormalizedEmail)
       ------------------------------------------------------------------ */
    DECLARE @MelbournePrimaryContactId BIGINT;
    DECLARE @SamplePortalEmail NVARCHAR(320) = N'customer.admin@example.com';
    DECLARE @SamplePortalEmailNorm NVARCHAR(320) = N'customer.admin@example.com';

    -- Melbourne
    IF NOT EXISTS (
        SELECT 1 FROM [customer].[CustomerContacts]
        WHERE [CustomerId] = @MelbourneCustomerId AND [NormalizedEmailAddress] = @SamplePortalEmailNorm
    )
    BEGIN
        INSERT INTO [customer].[CustomerContacts] (
            [CustomerId], [LegacyContactId], [DisplayName], [EmailAddress], [NormalizedEmailAddress],
            [PositionTitle], [PhoneNumber], [MobileNumber], [IsPrimaryContact], [IsActive], [PortalAccessStatusCode]
        )
        VALUES (
            @MelbourneCustomerId, 910001, N'Alex Customer', @SamplePortalEmail, @SamplePortalEmailNorm,
            N'Procurement Manager', N'+61 3 9000 1010', N'+61 400 000 101', 1, 1, N'active'
        );
    END;

    IF NOT EXISTS (
        SELECT 1 FROM [customer].[CustomerContacts]
        WHERE [CustomerId] = @MelbourneCustomerId AND [NormalizedEmailAddress] = N'sam.buyer@example.com'
    )
    BEGIN
        INSERT INTO [customer].[CustomerContacts] (
            [CustomerId], [LegacyContactId], [DisplayName], [EmailAddress], [NormalizedEmailAddress],
            [PositionTitle], [PhoneNumber], [MobileNumber], [IsPrimaryContact], [IsActive], [PortalAccessStatusCode]
        )
        VALUES (
            @MelbourneCustomerId, 910002, N'Sam Buyer', N'sam.buyer@example.com', N'sam.buyer@example.com',
            N'Buyer', N'+61 3 9000 1011', N'+61 400 000 102', 0, 1, N'not-invited'
        );
    END;

    SELECT @MelbournePrimaryContactId = [CustomerContactId]
    FROM [customer].[CustomerContacts]
    WHERE [CustomerId] = @MelbourneCustomerId AND [NormalizedEmailAddress] = @SamplePortalEmailNorm;

    -- Sydney
    IF NOT EXISTS (
        SELECT 1 FROM [customer].[CustomerContacts]
        WHERE [CustomerId] = @SydneyCustomerId AND [NormalizedEmailAddress] = N'jordan.ops@example.com'
    )
    BEGIN
        INSERT INTO [customer].[CustomerContacts] (
            [CustomerId], [LegacyContactId], [DisplayName], [EmailAddress], [NormalizedEmailAddress],
            [PositionTitle], [PhoneNumber], [MobileNumber], [IsPrimaryContact], [IsActive], [PortalAccessStatusCode]
        )
        VALUES (
            @SydneyCustomerId, 920001, N'Jordan Ops', N'jordan.ops@example.com', N'jordan.ops@example.com',
            N'Operations Lead', N'+61 2 9000 2010', N'+61 400 000 201', 1, 1, N'invited'
        );
    END;

    IF NOT EXISTS (
        SELECT 1 FROM [customer].[CustomerContacts]
        WHERE [CustomerId] = @SydneyCustomerId AND [NormalizedEmailAddress] = N'taylor.store@example.com'
    )
    BEGIN
        INSERT INTO [customer].[CustomerContacts] (
            [CustomerId], [LegacyContactId], [DisplayName], [EmailAddress], [NormalizedEmailAddress],
            [PositionTitle], [PhoneNumber], [MobileNumber], [IsPrimaryContact], [IsActive], [PortalAccessStatusCode]
        )
        VALUES (
            @SydneyCustomerId, 920002, N'Taylor Store', N'taylor.store@example.com', N'taylor.store@example.com',
            N'Store Supervisor', N'+61 2 9000 2011', N'+61 400 000 202', 0, 1, N'not-invited'
        );
    END;

    -- Brisbane
    IF NOT EXISTS (
        SELECT 1 FROM [customer].[CustomerContacts]
        WHERE [CustomerId] = @BrisbaneCustomerId AND [NormalizedEmailAddress] = N'casey.energy@example.com'
    )
    BEGIN
        INSERT INTO [customer].[CustomerContacts] (
            [CustomerId], [LegacyContactId], [DisplayName], [EmailAddress], [NormalizedEmailAddress],
            [PositionTitle], [PhoneNumber], [MobileNumber], [IsPrimaryContact], [IsActive], [PortalAccessStatusCode]
        )
        VALUES (
            @BrisbaneCustomerId, 930001, N'Casey Energy', N'casey.energy@example.com', N'casey.energy@example.com',
            N'Project Engineer', N'+61 7 9000 3010', N'+61 400 000 301', 1, 1, N'suspended'
        );
    END;

    IF NOT EXISTS (
        SELECT 1 FROM [customer].[CustomerContacts]
        WHERE [CustomerId] = @BrisbaneCustomerId AND [NormalizedEmailAddress] = N'riley.admin@example.com'
    )
    BEGIN
        INSERT INTO [customer].[CustomerContacts] (
            [CustomerId], [LegacyContactId], [DisplayName], [EmailAddress], [NormalizedEmailAddress],
            [PositionTitle], [PhoneNumber], [MobileNumber], [IsPrimaryContact], [IsActive], [PortalAccessStatusCode]
        )
        VALUES (
            @BrisbaneCustomerId, 930002, N'Riley Admin', N'riley.admin@example.com', N'riley.admin@example.com',
            N'Office Administrator', N'+61 7 9000 3011', N'+61 400 000 302', 0, 1, N'declined'
        );
    END;

    IF @MelbournePrimaryContactId IS NULL
    BEGIN
        THROW 52004, N'Melbourne primary contact (customer.admin@example.com) could not be resolved.', 1;
    END;

    /* ------------------------------------------------------------------
       Portal user: customer.admin@example.com
       No username, password, or ExternalIdentity.
       ------------------------------------------------------------------ */
    DECLARE @PortalUserId BIGINT = NULL;
    DECLARE @PortalUserAction NVARCHAR(40) = N'already_existed';

    SELECT @PortalUserId = [UserId]
    FROM [identity].[Users]
    WHERE [NormalizedEmailAddress] = @SamplePortalEmailNorm;

    IF @PortalUserId IS NULL
    BEGIN
        INSERT INTO [identity].[Users] (
            [EmailAddress],
            [NormalizedEmailAddress],
            [DisplayName],
            [UserStatusId]
        )
        VALUES (
            @SamplePortalEmail,
            @SamplePortalEmailNorm,
            N'Alex Customer',
            @ActiveStatusId
        );

        SET @PortalUserId = SCOPE_IDENTITY();
        SET @PortalUserAction = N'inserted';
    END;

    /* ------------------------------------------------------------------
       Link contact ↔ user
       ------------------------------------------------------------------ */
    DECLARE @LinkAction NVARCHAR(40) = N'already_existed';

    IF NOT EXISTS (
        SELECT 1
        FROM [customer].[CustomerContactUsers]
        WHERE [CustomerContactId] = @MelbournePrimaryContactId
          AND [UserId] = @PortalUserId
    )
    BEGIN
        INSERT INTO [customer].[CustomerContactUsers] (
            [CustomerContactId],
            [UserId],
            [IsPrimary],
            [IsActive]
        )
        VALUES (
            @MelbournePrimaryContactId,
            @PortalUserId,
            1,
            1
        );
        SET @LinkAction = N'inserted';
    END
    ELSE
    BEGIN
        UPDATE [customer].[CustomerContactUsers]
        SET
            [IsPrimary] = 1,
            [IsActive] = 1,
            [UnlinkedAtUtc] = NULL
        WHERE [CustomerContactId] = @MelbournePrimaryContactId
          AND [UserId] = @PortalUserId;
        SET @LinkAction = N'reactivated';
    END;

    /* ------------------------------------------------------------------
       CUSTOMER_ADMIN role — only if Stage 3 seed already created it
       ------------------------------------------------------------------ */
    DECLARE @CustomerAdminRoleId BIGINT = NULL;
    DECLARE @RoleAssignAction NVARCHAR(80) = N'skipped_role_missing_run_stage3';

    SELECT @CustomerAdminRoleId = [RoleId]
    FROM [identity].[Roles]
    WHERE UPPER([RoleCode]) = N'CUSTOMER_ADMIN';

    IF @CustomerAdminRoleId IS NOT NULL
    BEGIN
        IF NOT EXISTS (
            SELECT 1 FROM [identity].[UserRoles]
            WHERE [UserId] = @PortalUserId AND [RoleId] = @CustomerAdminRoleId
        )
        BEGIN
            INSERT INTO [identity].[UserRoles] ([UserId], [RoleId], [IsActive])
            VALUES (@PortalUserId, @CustomerAdminRoleId, 1);
            SET @RoleAssignAction = N'inserted';
        END
        ELSE
        BEGIN
            UPDATE [identity].[UserRoles]
            SET [IsActive] = 1, [ExpiresAt] = NULL
            WHERE [UserId] = @PortalUserId AND [RoleId] = @CustomerAdminRoleId;
            SET @RoleAssignAction = N'reactivated';
        END;
    END;

    /* ------------------------------------------------------------------
       Sample migration mapping row (no WordPress password/activation key)
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[migration].[LegacyCustomerUserMappings]', N'U') IS NOT NULL
       AND NOT EXISTS (
            SELECT 1
            FROM [migration].[LegacyCustomerUserMappings]
            WHERE [LegacyEmailAddress] = @SamplePortalEmail
              AND [MigrationStatusCode] = N'created-user'
       )
    BEGIN
        INSERT INTO [migration].[LegacyCustomerUserMappings] (
            [LegacyWordPressUserId],
            [LegacyUserLogin],
            [LegacyEmailAddress],
            [LegacyCustomerId],
            [LegacyContactRowId],
            [CustomerId],
            [CustomerContactId],
            [UserId],
            [MigrationStatusCode],
            [MigrationNotes],
            [MigratedAtUtc]
        )
        VALUES (
            NULL,
            N'customer.admin',
            @SamplePortalEmail,
            900001,
            NULL,
            @MelbourneCustomerId,
            @MelbournePrimaryContactId,
            @PortalUserId,
            N'created-user',
            N'DEVELOPMENT seed only — synthetic sample; not a WordPress migration.',
            SYSUTCDATETIME()
        );
    END;

    COMMIT TRANSACTION;

    SELECT
        N'seed-customer-sample-data' AS [Script],
        @PortalUserAction AS [PortalUserAction],
        @LinkAction AS [ContactUserLinkAction],
        @RoleAssignAction AS [CustomerAdminRoleAction],
        @MelbourneCustomerId AS [MelbourneCustomerId],
        @SydneyCustomerId AS [SydneyCustomerId],
        @BrisbaneCustomerId AS [BrisbaneCustomerId],
        @MelbournePrimaryContactId AS [MelbournePrimaryContactId],
        @PortalUserId AS [PortalUserId],
        @SamplePortalEmail AS [PortalUserEmail];
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
