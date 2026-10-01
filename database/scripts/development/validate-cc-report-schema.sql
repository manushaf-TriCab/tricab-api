/*
================================================================================
  Read-only check for create-cc-report-schema.sql.

  SELECT only. Safe to run after the create script has been applied.
  Does not modify data. Does not read legacy MySQL.
================================================================================
*/

SET NOCOUNT ON;

SELECT
    s.name AS SchemaName,
    t.name AS TableName
FROM sys.tables t
JOIN sys.schemas s ON s.schema_id = t.schema_id
WHERE s.name = N'cc_report'
ORDER BY t.name;

SELECT
    c.name AS ColumnName,
    ty.name AS TypeName,
    c.max_length AS MaxLengthBytes,
    c.precision AS PrecisionValue,
    c.scale AS ScaleValue,
    c.is_nullable AS IsNullable,
    c.is_identity AS IsIdentity,
    dc.definition AS DefaultDefinition
FROM sys.columns c
JOIN sys.types ty ON ty.user_type_id = c.user_type_id
LEFT JOIN sys.default_constraints dc ON dc.object_id = c.default_object_id
WHERE c.object_id = OBJECT_ID(N'[cc_report].[Reports]')
ORDER BY c.column_id;

SELECT
    i.name AS IndexName,
    i.is_unique AS IsUnique,
    i.has_filter AS HasFilter,
    i.filter_definition AS FilterDefinition,
    COL_NAME(ic.object_id, ic.column_id) AS ColumnName,
    ic.key_ordinal AS KeyOrdinal
FROM sys.indexes i
JOIN sys.index_columns ic
    ON ic.object_id = i.object_id AND ic.index_id = i.index_id
WHERE i.object_id = OBJECT_ID(N'[cc_report].[Reports]')
  AND i.name IS NOT NULL
ORDER BY i.name, ic.key_ordinal;

SELECT
    cc.name AS ConstraintName,
    cc.definition AS Definition
FROM sys.check_constraints cc
WHERE cc.parent_object_id IN (
    OBJECT_ID(N'[cc_report].[Reports]'),
    OBJECT_ID(N'[cc_report].[Attachments]'),
    OBJECT_ID(N'[cc_report].[Approvals]'),
    OBJECT_ID(N'[cc_report].[NumberSequences]')
)
ORDER BY cc.name;

SELECT
    fk.name AS ForeignKeyName,
    OBJECT_SCHEMA_NAME(fk.parent_object_id) AS FromSchema,
    OBJECT_NAME(fk.parent_object_id) AS FromTable,
    COL_NAME(fc.parent_object_id, fc.parent_column_id) AS FromColumn,
    OBJECT_SCHEMA_NAME(fk.referenced_object_id) AS ToSchema,
    OBJECT_NAME(fk.referenced_object_id) AS ToTable,
    COL_NAME(fc.referenced_object_id, fc.referenced_column_id) AS ToColumn,
    fk.delete_referential_action_desc AS OnDelete,
    fk.update_referential_action_desc AS OnUpdate
FROM sys.foreign_keys fk
JOIN sys.foreign_key_columns fc ON fc.constraint_object_id = fk.object_id
WHERE OBJECT_SCHEMA_NAME(fk.parent_object_id) = N'cc_report'
ORDER BY fk.name;

/* The person-contact feature must still be present and untouched by name. */
SELECT
    t.name AS TableName
FROM sys.tables t
JOIN sys.schemas s ON s.schema_id = t.schema_id
WHERE s.name = N'customer'
  AND t.name IN (N'CustomerContacts', N'CustomerContactUsers')
ORDER BY t.name;
