/*
================================================================================
  HR Employees 2.5 — [audit].[HrRestrictedReadEvents]: who read whose
  restricted details.

  STATUS: NOT APPLIED to any shared database. Do not run against tricab-mel or
  production without the owner's explicit approval.

  Every API response that includes the restricted block of an employee (date of
  birth, home address, personal contact, leave balances) writes one row here,
  before the block is returned, so a failed write fails the request. No
  existing audit table fits: [audit].[OnboardingDocumentAccessEvents] is
  onboarding documents by assignment, and [audit].[OnboardingAdminEvents] and
  [audit].[UserManagementEvents] key their subject on identity.Users, while an
  employee is keyed by payroll code and may have no platform user.

  Shaped on [audit].[OnboardingAdminEvents]: BIGINT identity PK, a PublicId,
  append-only through an INSTEAD OF UPDATE, DELETE trigger. It holds identifiers
  only (actor, employee code, route); never a restricted value.

  Run AFTER create-identity-schema.sql (FK to [identity].[Users]). Written in
  GO-separated batches because CREATE TRIGGER must begin its batch. Idempotent.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;
GO

IF NOT EXISTS (SELECT 1 FROM sys.schemas WHERE name = N'audit')
BEGIN
    EXEC(N'CREATE SCHEMA [audit]');
END
GO

IF OBJECT_ID(N'[audit].[HrRestrictedReadEvents]', N'U') IS NULL
BEGIN
    BEGIN TRY
        BEGIN TRANSACTION;

        CREATE TABLE [audit].[HrRestrictedReadEvents] (
            [HrRestrictedReadEventId] BIGINT IDENTITY(1,1) NOT NULL,
            [PublicId]                UNIQUEIDENTIFIER NOT NULL
                CONSTRAINT [DF_audit_HrRestrictedReadEvents_PublicId] DEFAULT (NEWSEQUENTIALID()),
            [ActorUserId]             BIGINT NOT NULL,
            /* [hr].[Employees].[EmployeeCode]. Not a foreign key: an audit row
               must outlive any later change to the mirror. */
            [EmployeeCode]            NVARCHAR(20) NOT NULL,
            /* The route that returned the block, e.g. `GET /api/v1/hr/employees/{code}`. */
            [Endpoint]                NVARCHAR(200) NOT NULL,
            [PermissionCode]          NVARCHAR(80) NOT NULL,
            [RequestCorrelationId]    NVARCHAR(100) NULL,
            [IpAddress]               NVARCHAR(64) NULL,
            [UserAgent]               NVARCHAR(500) NULL,
            [CreatedAtUtc]            DATETIMEOFFSET NOT NULL
                CONSTRAINT [DF_audit_HrRestrictedReadEvents_CreatedAtUtc] DEFAULT (SYSDATETIMEOFFSET()),
            CONSTRAINT [PK_audit_HrRestrictedReadEvents] PRIMARY KEY CLUSTERED ([HrRestrictedReadEventId]),
            CONSTRAINT [UQ_audit_HrRestrictedReadEvents_PublicId] UNIQUE ([PublicId]),
            CONSTRAINT [CK_audit_HrRestrictedReadEvents_EmployeeCode_NotEmpty]
                CHECK (LEN(LTRIM(RTRIM([EmployeeCode]))) > 0),
            CONSTRAINT [FK_audit_HrRestrictedReadEvents_ActorUser]
                FOREIGN KEY ([ActorUserId]) REFERENCES [identity].[Users]([UserId]) ON DELETE NO ACTION
        );

        /* "Who has looked at this person's details", newest first. */
        CREATE NONCLUSTERED INDEX [IX_audit_HrRestrictedReadEvents_Employee]
            ON [audit].[HrRestrictedReadEvents]([EmployeeCode], [CreatedAtUtc] DESC);

        /* "What has this person looked at", newest first. */
        CREATE NONCLUSTERED INDEX [IX_audit_HrRestrictedReadEvents_Actor]
            ON [audit].[HrRestrictedReadEvents]([ActorUserId], [CreatedAtUtc] DESC);

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH;
END
GO

IF OBJECT_ID(N'[audit].[TR_HrRestrictedReadEvents_AppendOnly]', N'TR') IS NOT NULL
    DROP TRIGGER [audit].[TR_HrRestrictedReadEvents_AppendOnly];
GO
CREATE TRIGGER [audit].[TR_HrRestrictedReadEvents_AppendOnly]
ON [audit].[HrRestrictedReadEvents]
INSTEAD OF UPDATE, DELETE
AS
BEGIN
    SET NOCOUNT ON;
    THROW 50007, N'audit.HrRestrictedReadEvents is append-only.', 1;
END;
GO

PRINT N'HR restricted read audit created/verified.';
GO
