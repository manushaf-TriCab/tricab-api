/*
================================================================================
  Seed: Customer-facing roles + permissions (Stage 3).

  - Idempotent / safe to re-run
  - Does not hard-code RoleId or PermissionId values
  - Reuses ACCOUNT.LOGIN when present
  - Creates CUSTOMER_USER and CUSTOMER_ADMIN
  - Does not grant USER.* / ROLE.* / PERMISSION.* / AUDIT.* admin catalog
  - Does not modify EMPLOYEE or GUEST permission sets
  - Does not assign roles to users (sample user assignment is seed-customer-sample-data)
  - Also maps the customer catalog to ADMINISTRATOR when that role exists (Admin 2)

  DEVELOPMENT seed — review before production.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRY
    BEGIN TRANSACTION;

    DECLARE @PermissionsInserted INT = 0;
    DECLARE @PermissionsReused INT = 0;
    DECLARE @CustomerUserMappingsInserted INT = 0;
    DECLARE @CustomerUserMappingsExisted INT = 0;
    DECLARE @CustomerAdminMappingsInserted INT = 0;
    DECLARE @CustomerAdminMappingsExisted INT = 0;
    DECLARE @AdministratorMappingsInserted INT = 0;
    DECLARE @AdministratorMappingsExisted INT = 0;

    DECLARE @CustomerUserRoleAction NVARCHAR(40) = N'already_existed';
    DECLARE @CustomerAdminRoleAction NVARCHAR(40) = N'already_existed';
    DECLARE @AdministratorMappingNote NVARCHAR(80) = N'skipped_role_missing';

    /* ------------------------------------------------------------------
       Full permission catalog for Stage 3
       ------------------------------------------------------------------ */
    DECLARE @PermissionCatalog TABLE (
        [PermissionCode] NVARCHAR(150) NOT NULL PRIMARY KEY,
        [PermissionName] NVARCHAR(200) NOT NULL,
        [Description] NVARCHAR(500) NOT NULL,
        [ModuleCode] NVARCHAR(100) NOT NULL
    );

    INSERT INTO @PermissionCatalog (
        [PermissionCode], [PermissionName], [Description], [ModuleCode]
    )
    VALUES
        (N'CUSTOMER.READ', N'Read customers', N'View customer company records.', N'customer'),
        (N'CUSTOMER.UPDATE', N'Update customers', N'Update customer company profile and commercial details.', N'customer'),
        (N'CUSTOMER.CONTACT.READ', N'Read customer contacts', N'View contacts belonging to a customer.', N'customer'),
        (N'CUSTOMER.CONTACT.CREATE', N'Create customer contacts', N'Create contacts for a customer company.', N'customer'),
        (N'CUSTOMER.CONTACT.UPDATE', N'Update customer contacts', N'Update customer contact profile details.', N'customer'),
        (N'CUSTOMER.CONTACT.STATUS.UPDATE', N'Update contact status', N'Activate or deactivate customer contacts.', N'customer'),
        (N'CUSTOMER.USER.READ', N'Read customer users', N'View portal users linked to a customer.', N'customer'),
        (N'CUSTOMER.USER.INVITE', N'Invite customer users', N'Invite or create portal users for customer contacts.', N'customer'),
        (N'CUSTOMER.USER.LINK', N'Link customer users', N'Link an existing portal user to a customer contact.', N'customer'),
        (N'CUSTOMER.USER.UNLINK', N'Unlink customer users', N'Unlink a portal user from a customer contact.', N'customer'),
        (N'CUSTOMER.PORTAL_ACCESS.MANAGE', N'Manage portal access', N'Enable, disable, or change portal access for customer contacts.', N'customer'),
        (N'PRODUCT.READ', N'Read products', N'View product catalogue information.', N'product'),
        (N'QUOTE.READ', N'Read quotes', N'View quotes for linked customers.', N'quote'),
        (N'QUOTE.CREATE', N'Create quotes', N'Create quotes for linked customers.', N'quote'),
        (N'ORDER.READ', N'Read orders', N'View orders for linked customers.', N'order'),
        (N'DOCUMENT.READ', N'Read documents', N'View documents for linked customers.', N'document');

    /* Ensure ACCOUNT.LOGIN exists (required by CUSTOMER_* roles) */
    DECLARE @LoginPermissionId BIGINT = NULL;
    DECLARE @LoginPermissionIsActive BIT = NULL;

    SELECT TOP (1)
        @LoginPermissionId = p.[PermissionId],
        @LoginPermissionIsActive = p.[IsActive]
    FROM [identity].[Permissions] p
    WHERE UPPER(p.[PermissionCode]) = N'ACCOUNT.LOGIN'
    ORDER BY p.[PermissionId];

    IF @LoginPermissionId IS NULL
    BEGIN
        INSERT INTO [identity].[Permissions] (
            [PermissionCode], [PermissionName], [Description], [ModuleCode], [IsActive]
        )
        VALUES (
            N'ACCOUNT.LOGIN',
            N'Log in to TriCab',
            N'Allows a user to sign in to the TriCab platform.',
            N'identity',
            1
        );
        SET @LoginPermissionId = SCOPE_IDENTITY();
        SET @LoginPermissionIsActive = 1;
        SET @PermissionsInserted = @PermissionsInserted + 1;
    END
    ELSE
    BEGIN
        SET @PermissionsReused = @PermissionsReused + 1;
    END;

    IF @LoginPermissionId IS NULL OR @LoginPermissionIsActive = 0
    BEGIN
        THROW 53001, N'ACCOUNT.LOGIN permission is missing or inactive.', 1;
    END;

    /* Insert / reuse catalog permissions */
    DECLARE @PermissionCode NVARCHAR(150);
    DECLARE @PermissionName NVARCHAR(200);
    DECLARE @PermissionDescription NVARCHAR(500);
    DECLARE @ModuleCode NVARCHAR(100);
    DECLARE @ResolvedPermissionId BIGINT;
    DECLARE @ResolvedPermissionIsActive BIT;
    DECLARE @StoredPermissionCode NVARCHAR(150);
    DECLARE @InactivePermissionMessage NVARCHAR(400);

    DECLARE permission_cursor CURSOR LOCAL FAST_FORWARD FOR
        SELECT [PermissionCode], [PermissionName], [Description], [ModuleCode]
        FROM @PermissionCatalog
        ORDER BY [PermissionCode];

    OPEN permission_cursor;
    FETCH NEXT FROM permission_cursor INTO
        @PermissionCode, @PermissionName, @PermissionDescription, @ModuleCode;

    WHILE @@FETCH_STATUS = 0
    BEGIN
        SET @ResolvedPermissionId = NULL;
        SET @ResolvedPermissionIsActive = NULL;
        SET @StoredPermissionCode = NULL;

        SELECT TOP (1)
            @ResolvedPermissionId = p.[PermissionId],
            @ResolvedPermissionIsActive = p.[IsActive],
            @StoredPermissionCode = p.[PermissionCode]
        FROM [identity].[Permissions] p
        WHERE UPPER(p.[PermissionCode]) = UPPER(@PermissionCode)
        ORDER BY p.[PermissionId];

        IF @ResolvedPermissionId IS NULL
        BEGIN
            INSERT INTO [identity].[Permissions] (
                [PermissionCode], [PermissionName], [Description], [ModuleCode], [IsActive]
            )
            VALUES (
                @PermissionCode, @PermissionName, @PermissionDescription, @ModuleCode, 1
            );
            SET @PermissionsInserted = @PermissionsInserted + 1;
        END
        ELSE
        BEGIN
            SET @PermissionsReused = @PermissionsReused + 1;
            IF @ResolvedPermissionIsActive = 0
            BEGIN
                SET @InactivePermissionMessage =
                    N'Permission exists but IsActive = 0. Refusing to continue: '
                    + ISNULL(@StoredPermissionCode, @PermissionCode);
                THROW 53002, @InactivePermissionMessage, 1;
            END;
        END;

        FETCH NEXT FROM permission_cursor INTO
            @PermissionCode, @PermissionName, @PermissionDescription, @ModuleCode;
    END;

    CLOSE permission_cursor;
    DEALLOCATE permission_cursor;

    /* ------------------------------------------------------------------
       Roles
       ------------------------------------------------------------------ */
    DECLARE @CustomerUserRoleId BIGINT = NULL;
    DECLARE @CustomerUserRoleIsActive BIT = NULL;
    DECLARE @CustomerAdminRoleId BIGINT = NULL;
    DECLARE @CustomerAdminRoleIsActive BIT = NULL;
    DECLARE @AdministratorRoleId BIGINT = NULL;
    DECLARE @AdministratorRoleIsActive BIT = NULL;

    SELECT TOP (1)
        @CustomerUserRoleId = r.[RoleId],
        @CustomerUserRoleIsActive = r.[IsActive]
    FROM [identity].[Roles] r
    WHERE UPPER(r.[RoleCode]) = N'CUSTOMER_USER'
    ORDER BY r.[RoleId];

    IF @CustomerUserRoleId IS NULL
    BEGIN
        INSERT INTO [identity].[Roles] (
            [RoleCode], [RoleName], [Description], [IsSystemRole], [IsActive]
        )
        VALUES (
            N'CUSTOMER_USER',
            N'Customer User',
            N'Standard customer portal access for a linked company contact.',
            1,
            1
        );
        SET @CustomerUserRoleId = SCOPE_IDENTITY();
        SET @CustomerUserRoleIsActive = 1;
        SET @CustomerUserRoleAction = N'inserted';
    END
    ELSE IF @CustomerUserRoleIsActive = 0
    BEGIN
        THROW 53003, N'CUSTOMER_USER role exists but is inactive. Reactivate manually if intended.', 1;
    END;

    SELECT TOP (1)
        @CustomerAdminRoleId = r.[RoleId],
        @CustomerAdminRoleIsActive = r.[IsActive]
    FROM [identity].[Roles] r
    WHERE UPPER(r.[RoleCode]) = N'CUSTOMER_ADMIN'
    ORDER BY r.[RoleId];

    IF @CustomerAdminRoleId IS NULL
    BEGIN
        INSERT INTO [identity].[Roles] (
            [RoleCode], [RoleName], [Description], [IsSystemRole], [IsActive]
        )
        VALUES (
            N'CUSTOMER_ADMIN',
            N'Customer Administrator',
            N'Customer portal administrator for a linked company; manage contacts and portal access.',
            1,
            1
        );
        SET @CustomerAdminRoleId = SCOPE_IDENTITY();
        SET @CustomerAdminRoleIsActive = 1;
        SET @CustomerAdminRoleAction = N'inserted';
    END
    ELSE IF @CustomerAdminRoleIsActive = 0
    BEGIN
        THROW 53004, N'CUSTOMER_ADMIN role exists but is inactive. Reactivate manually if intended.', 1;
    END;

    SELECT TOP (1)
        @AdministratorRoleId = r.[RoleId],
        @AdministratorRoleIsActive = r.[IsActive]
    FROM [identity].[Roles] r
    WHERE UPPER(r.[RoleCode]) = N'ADMINISTRATOR'
    ORDER BY r.[RoleId];

    IF @AdministratorRoleId IS NOT NULL AND @AdministratorRoleIsActive = 1
    BEGIN
        SET @AdministratorMappingNote = N'mapped';
    END
    ELSE IF @AdministratorRoleId IS NOT NULL
    BEGIN
        SET @AdministratorMappingNote = N'skipped_inactive';
    END;

    /* ------------------------------------------------------------------
       Role → permission mapping sets
       ------------------------------------------------------------------ */
    DECLARE @CustomerUserPermissions TABLE ([PermissionCode] NVARCHAR(150) NOT NULL PRIMARY KEY);
    INSERT INTO @CustomerUserPermissions ([PermissionCode]) VALUES
        (N'ACCOUNT.LOGIN'),
        (N'CUSTOMER.READ'),
        (N'CUSTOMER.CONTACT.READ'),
        (N'PRODUCT.READ'),
        (N'QUOTE.READ'),
        (N'ORDER.READ'),
        (N'DOCUMENT.READ');

    DECLARE @CustomerAdminPermissions TABLE ([PermissionCode] NVARCHAR(150) NOT NULL PRIMARY KEY);
    INSERT INTO @CustomerAdminPermissions ([PermissionCode])
    SELECT [PermissionCode] FROM @CustomerUserPermissions
    UNION
    SELECT v.[PermissionCode]
    FROM (VALUES
        (N'CUSTOMER.UPDATE'),
        (N'CUSTOMER.CONTACT.CREATE'),
        (N'CUSTOMER.CONTACT.UPDATE'),
        (N'CUSTOMER.CONTACT.STATUS.UPDATE'),
        (N'CUSTOMER.USER.READ'),
        (N'CUSTOMER.USER.INVITE'),
        (N'CUSTOMER.USER.LINK'),
        (N'CUSTOMER.USER.UNLINK'),
        (N'CUSTOMER.PORTAL_ACCESS.MANAGE'),
        (N'QUOTE.CREATE')
    ) AS v([PermissionCode]);

    /* Map helper via shared logic for each role */
    DECLARE @MapRoleId BIGINT;
    DECLARE @MapPermissionId BIGINT;
    DECLARE @MapPermissionCode NVARCHAR(150);
    DECLARE @ExistingMappingIsActive BIT;
    DECLARE @MapCounterMode NVARCHAR(30);
    DECLARE @InactiveMappingMessage NVARCHAR(400);

    /* ---- CUSTOMER_USER ---- */
    DECLARE customer_user_map_cursor CURSOR LOCAL FAST_FORWARD FOR
        SELECT p.[PermissionId], p.[PermissionCode]
        FROM [identity].[Permissions] p
        INNER JOIN @CustomerUserPermissions s
            ON UPPER(p.[PermissionCode]) = UPPER(s.[PermissionCode])
        WHERE p.[IsActive] = 1
        ORDER BY p.[PermissionCode];

    OPEN customer_user_map_cursor;
    FETCH NEXT FROM customer_user_map_cursor INTO @MapPermissionId, @MapPermissionCode;

    WHILE @@FETCH_STATUS = 0
    BEGIN
        SET @ExistingMappingIsActive = NULL;
        SELECT TOP (1) @ExistingMappingIsActive = rp.[IsActive]
        FROM [identity].[RolePermissions] rp
        WHERE rp.[RoleId] = @CustomerUserRoleId AND rp.[PermissionId] = @MapPermissionId
        ORDER BY rp.[RolePermissionId];

        IF @ExistingMappingIsActive IS NULL
        BEGIN
            INSERT INTO [identity].[RolePermissions] ([RoleId], [PermissionId], [IsActive])
            VALUES (@CustomerUserRoleId, @MapPermissionId, 1);
            SET @CustomerUserMappingsInserted = @CustomerUserMappingsInserted + 1;
        END
        ELSE IF @ExistingMappingIsActive = 0
        BEGIN
            SET @InactiveMappingMessage =
                N'RolePermission mapping exists but IsActive = 0. Refusing to reactivate: CUSTOMER_USER → '
                + @MapPermissionCode;
            THROW 53005, @InactiveMappingMessage, 1;
        END
        ELSE
        BEGIN
            SET @CustomerUserMappingsExisted = @CustomerUserMappingsExisted + 1;
        END;

        FETCH NEXT FROM customer_user_map_cursor INTO @MapPermissionId, @MapPermissionCode;
    END;

    CLOSE customer_user_map_cursor;
    DEALLOCATE customer_user_map_cursor;

    /* ---- CUSTOMER_ADMIN ---- */
    DECLARE customer_admin_map_cursor CURSOR LOCAL FAST_FORWARD FOR
        SELECT p.[PermissionId], p.[PermissionCode]
        FROM [identity].[Permissions] p
        INNER JOIN @CustomerAdminPermissions s
            ON UPPER(p.[PermissionCode]) = UPPER(s.[PermissionCode])
        WHERE p.[IsActive] = 1
        ORDER BY p.[PermissionCode];

    OPEN customer_admin_map_cursor;
    FETCH NEXT FROM customer_admin_map_cursor INTO @MapPermissionId, @MapPermissionCode;

    WHILE @@FETCH_STATUS = 0
    BEGIN
        SET @ExistingMappingIsActive = NULL;
        SELECT TOP (1) @ExistingMappingIsActive = rp.[IsActive]
        FROM [identity].[RolePermissions] rp
        WHERE rp.[RoleId] = @CustomerAdminRoleId AND rp.[PermissionId] = @MapPermissionId
        ORDER BY rp.[RolePermissionId];

        IF @ExistingMappingIsActive IS NULL
        BEGIN
            INSERT INTO [identity].[RolePermissions] ([RoleId], [PermissionId], [IsActive])
            VALUES (@CustomerAdminRoleId, @MapPermissionId, 1);
            SET @CustomerAdminMappingsInserted = @CustomerAdminMappingsInserted + 1;
        END
        ELSE IF @ExistingMappingIsActive = 0
        BEGIN
            SET @InactiveMappingMessage =
                N'RolePermission mapping exists but IsActive = 0. Refusing to reactivate: CUSTOMER_ADMIN → '
                + @MapPermissionCode;
            THROW 53006, @InactiveMappingMessage, 1;
        END
        ELSE
        BEGIN
            SET @CustomerAdminMappingsExisted = @CustomerAdminMappingsExisted + 1;
        END;

        FETCH NEXT FROM customer_admin_map_cursor INTO @MapPermissionId, @MapPermissionCode;
    END;

    CLOSE customer_admin_map_cursor;
    DEALLOCATE customer_admin_map_cursor;

    /* ---- ADMINISTRATOR gets full Stage 3 catalog (Admin 2 operators) ---- */
    IF @AdministratorRoleId IS NOT NULL AND @AdministratorRoleIsActive = 1
    BEGIN
        DECLARE admin_map_cursor CURSOR LOCAL FAST_FORWARD FOR
            SELECT p.[PermissionId], p.[PermissionCode]
            FROM [identity].[Permissions] p
            INNER JOIN @PermissionCatalog c
                ON UPPER(p.[PermissionCode]) = UPPER(c.[PermissionCode])
            WHERE p.[IsActive] = 1
            ORDER BY p.[PermissionCode];

        OPEN admin_map_cursor;
        FETCH NEXT FROM admin_map_cursor INTO @MapPermissionId, @MapPermissionCode;

        WHILE @@FETCH_STATUS = 0
        BEGIN
            SET @ExistingMappingIsActive = NULL;
            SELECT TOP (1) @ExistingMappingIsActive = rp.[IsActive]
            FROM [identity].[RolePermissions] rp
            WHERE rp.[RoleId] = @AdministratorRoleId AND rp.[PermissionId] = @MapPermissionId
            ORDER BY rp.[RolePermissionId];

            IF @ExistingMappingIsActive IS NULL
            BEGIN
                INSERT INTO [identity].[RolePermissions] ([RoleId], [PermissionId], [IsActive])
                VALUES (@AdministratorRoleId, @MapPermissionId, 1);
                SET @AdministratorMappingsInserted = @AdministratorMappingsInserted + 1;
            END
            ELSE IF @ExistingMappingIsActive = 0
            BEGIN
                SET @InactiveMappingMessage =
                    N'RolePermission mapping exists but IsActive = 0. Refusing to reactivate: ADMINISTRATOR → '
                    + @MapPermissionCode;
                THROW 53007, @InactiveMappingMessage, 1;
            END
            ELSE
            BEGIN
                SET @AdministratorMappingsExisted = @AdministratorMappingsExisted + 1;
            END;

            FETCH NEXT FROM admin_map_cursor INTO @MapPermissionId, @MapPermissionCode;
        END;

        CLOSE admin_map_cursor;
        DEALLOCATE admin_map_cursor;
    END;

    /* Validation: CUSTOMER_USER / CUSTOMER_ADMIN have all required active mappings */
    IF EXISTS (
        SELECT 1 FROM @CustomerUserPermissions s
        WHERE NOT EXISTS (
            SELECT 1
            FROM [identity].[Permissions] p
            INNER JOIN [identity].[RolePermissions] rp
                ON rp.[PermissionId] = p.[PermissionId]
               AND rp.[RoleId] = @CustomerUserRoleId
               AND rp.[IsActive] = 1
            WHERE UPPER(p.[PermissionCode]) = UPPER(s.[PermissionCode])
              AND p.[IsActive] = 1
        )
    )
    BEGIN
        THROW 53008, N'CUSTOMER_USER is missing one or more required active permission mappings.', 1;
    END;

    IF EXISTS (
        SELECT 1 FROM @CustomerAdminPermissions s
        WHERE NOT EXISTS (
            SELECT 1
            FROM [identity].[Permissions] p
            INNER JOIN [identity].[RolePermissions] rp
                ON rp.[PermissionId] = p.[PermissionId]
               AND rp.[RoleId] = @CustomerAdminRoleId
               AND rp.[IsActive] = 1
            WHERE UPPER(p.[PermissionCode]) = UPPER(s.[PermissionCode])
              AND p.[IsActive] = 1
        )
    )
    BEGIN
        THROW 53009, N'CUSTOMER_ADMIN is missing one or more required active permission mappings.', 1;
    END;

    /* Guard: CUSTOMER roles must not receive internal admin catalog permissions */
    IF EXISTS (
        SELECT 1
        FROM [identity].[RolePermissions] rp
        INNER JOIN [identity].[Permissions] p ON p.[PermissionId] = rp.[PermissionId]
        WHERE rp.[RoleId] IN (@CustomerUserRoleId, @CustomerAdminRoleId)
          AND rp.[IsActive] = 1
          AND (
                UPPER(p.[PermissionCode]) LIKE N'USER.%'
             OR UPPER(p.[PermissionCode]) LIKE N'ROLE.%'
             OR UPPER(p.[PermissionCode]) LIKE N'PERMISSION.%'
             OR UPPER(p.[PermissionCode]) LIKE N'AUDIT.%'
          )
    )
    BEGIN
        THROW 53010, N'CUSTOMER_USER/CUSTOMER_ADMIN unexpectedly has USER/ROLE/PERMISSION/AUDIT permissions.', 1;
    END;

    SELECT
        @CustomerUserRoleAction AS [CustomerUserRoleAction],
        @CustomerUserRoleId AS [CustomerUserRoleId],
        @CustomerAdminRoleAction AS [CustomerAdminRoleAction],
        @CustomerAdminRoleId AS [CustomerAdminRoleId],
        @PermissionsInserted AS [PermissionsInserted],
        @PermissionsReused AS [PermissionsReused],
        @CustomerUserMappingsInserted AS [CustomerUserMappingsInserted],
        @CustomerUserMappingsExisted AS [CustomerUserMappingsExisted],
        @CustomerAdminMappingsInserted AS [CustomerAdminMappingsInserted],
        @CustomerAdminMappingsExisted AS [CustomerAdminMappingsExisted],
        @AdministratorMappingNote AS [AdministratorMappingNote],
        @AdministratorMappingsInserted AS [AdministratorMappingsInserted],
        @AdministratorMappingsExisted AS [AdministratorMappingsExisted];

    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF CURSOR_STATUS('local', 'permission_cursor') >= -1
    BEGIN
        CLOSE permission_cursor;
        DEALLOCATE permission_cursor;
    END;

    IF CURSOR_STATUS('local', 'customer_user_map_cursor') >= -1
    BEGIN
        CLOSE customer_user_map_cursor;
        DEALLOCATE customer_user_map_cursor;
    END;

    IF CURSOR_STATUS('local', 'customer_admin_map_cursor') >= -1
    BEGIN
        CLOSE customer_admin_map_cursor;
        DEALLOCATE customer_admin_map_cursor;
    END;

    IF CURSOR_STATUS('local', 'admin_map_cursor') >= -1
    BEGIN
        CLOSE admin_map_cursor;
        DEALLOCATE admin_map_cursor;
    END;

    IF @@TRANCOUNT > 0
    BEGIN
        ROLLBACK TRANSACTION;
    END;

    DECLARE @ErrorMessage NVARCHAR(4000) = ERROR_MESSAGE();
    DECLARE @ErrorSeverity INT = ERROR_SEVERITY();
    DECLARE @ErrorState INT = ERROR_STATE();

    RAISERROR(@ErrorMessage, @ErrorSeverity, @ErrorState);
END CATCH;
