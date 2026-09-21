/*
================================================================================
  Phase P — [identity].[UserEmailAddresses]

  Every address a user has proven control of, and what that address is for.

  Why this exists: [identity].[Users] carries one address, unique across the
  table, and IdentityResolutionService compared a provider's verified email
  against that single value. A person who holds both a personal address and a
  TriCab mailbox could therefore use exactly one of them — changing the primary
  to fix one login broke the other. That is not an edge case: a pre-start
  candidate is invited on a personal address and issued a mailbox on their
  first day, and warehouse operators are never issued one at all.

  [identity].[Users].[EmailAddress] stays as the primary/display address and
  keeps its unique constraint. This table is the resolution surface.

  Backfill below inserts one row per existing user with Purpose = NULL —
  "nobody has classified this yet". Unclassified addresses resolve exactly as
  they did before this table existed, so applying this script changes no
  behaviour on its own. ProviderBindingPolicy only applies once a purpose has
  actually been recorded, which is a deliberate choice: guessing a purpose from
  the email domain would refuse a first-time Microsoft sign-in for every
  contractor and every onmicrosoft.com account.

  Idempotent. Creates no users. Grants nothing.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRY
    BEGIN TRANSACTION;

    IF OBJECT_ID(N'[identity].[UserEmailAddresses]', N'U') IS NULL
    BEGIN
        CREATE TABLE [identity].[UserEmailAddresses] (
            [UserEmailAddressId]      BIGINT IDENTITY(1,1) NOT NULL,
            [UserId]                  BIGINT NOT NULL,
            [EmailAddress]            NVARCHAR(256) NOT NULL,
            [NormalizedEmailAddress]  NVARCHAR(256) NOT NULL,
            /* 'personal' | 'corporate' | NULL = not yet classified.
               Mirrors TriCab\Core\Identity\Domain\ValueObject\EmailAddressPurpose. */
            [Purpose]                 NVARCHAR(20) NULL,
            [IsActive]                BIT NOT NULL
                CONSTRAINT DF_identity_UserEmailAddresses_IsActive DEFAULT (1),
            [VerifiedAtUtc]           DATETIME2(7) NULL,
            [CreatedAtUtc]            DATETIME2(7) NOT NULL
                CONSTRAINT DF_identity_UserEmailAddresses_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
            [UpdatedAtUtc]            DATETIME2(7) NOT NULL
                CONSTRAINT DF_identity_UserEmailAddresses_UpdatedAtUtc DEFAULT (SYSUTCDATETIME()),
            CONSTRAINT PK_identity_UserEmailAddresses
                PRIMARY KEY CLUSTERED ([UserEmailAddressId]),
            /* One address belongs to one person. This is what keeps
               findAllByVerifiedEmail() from ever returning two users, and what
               makes "not found" and "belongs to someone else" the same answer. */
            CONSTRAINT UQ_identity_UserEmailAddresses_Normalized
                UNIQUE ([NormalizedEmailAddress]),
            CONSTRAINT CK_identity_UserEmailAddresses_Purpose
                CHECK ([Purpose] IS NULL OR [Purpose] IN (N'personal', N'corporate')),
            CONSTRAINT CK_identity_UserEmailAddresses_NotEmpty
                CHECK (LEN(LTRIM(RTRIM([EmailAddress]))) > 0),
            CONSTRAINT FK_identity_UserEmailAddresses_User
                FOREIGN KEY ([UserId]) REFERENCES [identity].[Users] ([UserId])
        );
    END;

    /* Worklist index: "every address this person holds". */
    IF NOT EXISTS (
        SELECT 1 FROM sys.indexes
        WHERE name = N'IX_identity_UserEmailAddresses_UserId'
          AND object_id = OBJECT_ID(N'[identity].[UserEmailAddresses]')
    )
    BEGIN
        CREATE NONCLUSTERED INDEX [IX_identity_UserEmailAddresses_UserId]
            ON [identity].[UserEmailAddresses] ([UserId])
            INCLUDE ([NormalizedEmailAddress], [Purpose], [IsActive]);
    END;

    /* ------------------------------------------------------------------
       Backfill: one row per existing user, unclassified.
       Re-runnable — only inserts addresses that are absent.
       ------------------------------------------------------------------ */
    INSERT INTO [identity].[UserEmailAddresses]
        ([UserId], [EmailAddress], [NormalizedEmailAddress], [Purpose], [IsActive], [VerifiedAtUtc])
    SELECT
        u.[UserId],
        u.[EmailAddress],
        u.[NormalizedEmailAddress],
        NULL,
        1,
        SYSUTCDATETIME()
    FROM [identity].[Users] u
    WHERE NOT EXISTS (
        SELECT 1 FROM [identity].[UserEmailAddresses] a
        WHERE a.[NormalizedEmailAddress] = u.[NormalizedEmailAddress]
    );

    SELECT
        (SELECT COUNT(*) FROM [identity].[Users]) AS [Users],
        (SELECT COUNT(*) FROM [identity].[UserEmailAddresses]) AS [Addresses],
        (SELECT COUNT(*) FROM [identity].[UserEmailAddresses] WHERE [Purpose] IS NULL) AS [Unclassified];

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
