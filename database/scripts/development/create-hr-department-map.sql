/*
================================================================================
  HR Employees 4.1 — [hr].[DepartmentMap] and [audit].[HrDepartmentMapEvents].

  STATUS: NOT APPLIED to any shared database. Do not run against tricab-mel or
  production without the owner's explicit approval.

  Payroll holds departments and factories in mixed schemes (EXTRUDER, OpsExtru,
  "Brisbane Sales", blanks, test values). The mirror keeps the raw value; this
  table is where HR gives each raw value a clean name:

    MapKind   'department' or 'factory'
    RawValue  the raw value, trimmed (matched case-insensitively by the
              database collation, the same way the directory facets group)
    CleanName the department name, or for a factory the site name
    CostCentre optional, for either kind

  A raw value with no row is unmapped and shows as itself, so nothing
  disappears from the directory filters. Rows are written only by the HR
  department map page (API PUT /api/v1/hr/department-map); the sync never
  touches this table. RawValue is deliberately not a foreign key: HR may map a
  value before, or after, it leaves payroll.

  Every change (created / updated / cleared) writes one row to
  [audit].[HrDepartmentMapEvents] in the same transaction, with the old and new
  names. The audit table is append-only (INSTEAD OF UPDATE, DELETE trigger),
  shaped on [audit].[HrRestrictedReadEvents].

  Run AFTER create-hr-employees-schema.sql (schema [hr]) and
  create-identity-schema.sql (FKs to [identity].[Users]). Apply BEFORE the API
  that reads the map: the directory queries join it. Written in GO-separated
  batches because CREATE TRIGGER must begin its batch. Idempotent.
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

IF OBJECT_ID(N'[hr].[DepartmentMap]', N'U') IS NULL
BEGIN
    CREATE TABLE [hr].[DepartmentMap] (
        [MapKind]         NVARCHAR(20)  NOT NULL,
        [RawValue]        NVARCHAR(100) NOT NULL,
        [CleanName]       NVARCHAR(100) NOT NULL,
        [CostCentre]      NVARCHAR(40)  NULL,
        [UpdatedByUserId] BIGINT        NOT NULL,
        [UpdatedAtUtc]    DATETIMEOFFSET NOT NULL
            CONSTRAINT [DF_hr_DepartmentMap_UpdatedAtUtc] DEFAULT (SYSDATETIMEOFFSET()),
        CONSTRAINT [PK_hr_DepartmentMap] PRIMARY KEY CLUSTERED ([MapKind], [RawValue]),
        CONSTRAINT [CK_hr_DepartmentMap_Kind] CHECK ([MapKind] IN (N'department', N'factory')),
        CONSTRAINT [CK_hr_DepartmentMap_RawValue_Trimmed]
            CHECK (LEN([RawValue]) > 0 AND [RawValue] = LTRIM(RTRIM([RawValue]))),
        CONSTRAINT [CK_hr_DepartmentMap_CleanName_NotEmpty] CHECK (LEN(LTRIM(RTRIM([CleanName]))) > 0),
        CONSTRAINT [FK_hr_DepartmentMap_UpdatedBy]
            FOREIGN KEY ([UpdatedByUserId]) REFERENCES [identity].[Users]([UserId]) ON DELETE NO ACTION
    );
END
GO

IF OBJECT_ID(N'[audit].[HrDepartmentMapEvents]', N'U') IS NULL
BEGIN
    CREATE TABLE [audit].[HrDepartmentMapEvents] (
        [HrDepartmentMapEventId] BIGINT IDENTITY(1,1) NOT NULL,
        [PublicId]               UNIQUEIDENTIFIER NOT NULL
            CONSTRAINT [DF_audit_HrDepartmentMapEvents_PublicId] DEFAULT (NEWSEQUENTIALID()),
        [ActorUserId]            BIGINT NOT NULL,
        [MapKind]                NVARCHAR(20)  NOT NULL,
        /* Not a foreign key: an audit row must outlive any later change to the map. */
        [RawValue]               NVARCHAR(100) NOT NULL,
        [Action]                 NVARCHAR(20)  NOT NULL,
        [OldCleanName]           NVARCHAR(100) NULL,
        [OldCostCentre]          NVARCHAR(40)  NULL,
        [NewCleanName]           NVARCHAR(100) NULL,
        [NewCostCentre]          NVARCHAR(40)  NULL,
        [PermissionCode]         NVARCHAR(80)  NOT NULL,
        [RequestCorrelationId]   NVARCHAR(100) NULL,
        [IpAddress]              NVARCHAR(64)  NULL,
        [UserAgent]              NVARCHAR(500) NULL,
        [CreatedAtUtc]           DATETIMEOFFSET NOT NULL
            CONSTRAINT [DF_audit_HrDepartmentMapEvents_CreatedAtUtc] DEFAULT (SYSDATETIMEOFFSET()),
        CONSTRAINT [PK_audit_HrDepartmentMapEvents] PRIMARY KEY CLUSTERED ([HrDepartmentMapEventId]),
        CONSTRAINT [UQ_audit_HrDepartmentMapEvents_PublicId] UNIQUE ([PublicId]),
        CONSTRAINT [CK_audit_HrDepartmentMapEvents_Action] CHECK ([Action] IN (N'created', N'updated', N'cleared')),
        CONSTRAINT [FK_audit_HrDepartmentMapEvents_ActorUser]
            FOREIGN KEY ([ActorUserId]) REFERENCES [identity].[Users]([UserId]) ON DELETE NO ACTION
    );

    /* "History of this code", newest first. */
    CREATE NONCLUSTERED INDEX [IX_audit_HrDepartmentMapEvents_Raw]
        ON [audit].[HrDepartmentMapEvents]([MapKind], [RawValue], [CreatedAtUtc] DESC);

    /* "What has this person changed", newest first. */
    CREATE NONCLUSTERED INDEX [IX_audit_HrDepartmentMapEvents_Actor]
        ON [audit].[HrDepartmentMapEvents]([ActorUserId], [CreatedAtUtc] DESC);
END
GO

IF OBJECT_ID(N'[audit].[TR_HrDepartmentMapEvents_AppendOnly]', N'TR') IS NOT NULL
    DROP TRIGGER [audit].[TR_HrDepartmentMapEvents_AppendOnly];
GO
CREATE TRIGGER [audit].[TR_HrDepartmentMapEvents_AppendOnly]
ON [audit].[HrDepartmentMapEvents]
INSTEAD OF UPDATE, DELETE
AS
BEGIN
    SET NOCOUNT ON;
    THROW 50008, N'audit.HrDepartmentMapEvents is append-only.', 1;
END;
GO

PRINT N'HR department map created/verified.';
GO
