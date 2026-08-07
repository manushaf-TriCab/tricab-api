/*
================================================================================
  Seed: cable-catalog reference data for the catalog-load source mapping.

  Adds the vocabulary that the MySQL `tc` catalog tables (style_header,
  style_standard, standard, application_spec) need before any style can be
  loaded as a [product].[Products] row. Creates no products, variants, media
  or prices -- vocabulary and crosswalk rows only.

  Companion doc: tricab-core/docs/product-catalog-load-source-mapping.md
  Source of the values: catalog-load/sql/01_schema.sql (standards seed),
  catalog-load/sql/02_style_header.sql, catalog-load/sql/05_application_spec_patch.sql.

  Runs after seed-product-reference-data.sql, which owns the base vocabulary
  (statuses, media types, document types, currencies, regions, markets and the
  five specification groups). This script only adds what those do not cover.

  Idempotent by code columns. Safe to re-run. One transaction.

  DEVELOPMENT seed -- review before production.

  NOTE ON ONE MUTATION: section 3 changes the existing SpecificationDefinition
  'STANDARD' from text to multi-select. That is deliberate and explained inline;
  it is the only pre-existing row this script modifies. Placeholder values
  already sitting on it are counted and reported, never deleted.

  Target: the Azure SQL `tc` at tricab-mel.database.windows.net -- NOT the
  MySQL `tc` that catalog-load writes to. Same name, different databases.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRY
    BEGIN TRANSACTION;

    /* Degree sign built from its code point so this file stays pure ASCII and
       survives sqlcmd regardless of the client code page. */
    DECLARE @DegC NVARCHAR(10) = NCHAR(176) + N'C';

    /* ------------------------------------------------------------------
       1. Specification group

       The five existing groups (electrical, mechanical, construction,
       compliance, packaging) have no home for an operating temperature
       range, which every style_header row carries.
       ------------------------------------------------------------------ */
    MERGE [product].[SpecificationGroups] AS t
    USING (VALUES
        (N'thermal', N'Thermal', N'Temperature ratings and thermal limits.', 25)
    ) AS s ([GroupCode], [GroupName], [Description], [DisplayOrder])
    ON t.[GroupCode] = s.[GroupCode]
    WHEN NOT MATCHED THEN
        INSERT ([GroupCode], [GroupName], [Description], [DisplayOrder], [IsActive])
        VALUES (s.[GroupCode], s.[GroupName], s.[Description], s.[DisplayOrder], 1);

    DECLARE @GrpThermal      BIGINT = (SELECT [SpecificationGroupId] FROM [product].[SpecificationGroups] WHERE [GroupCode] = N'thermal');
    DECLARE @GrpConstruction BIGINT = (SELECT [SpecificationGroupId] FROM [product].[SpecificationGroups] WHERE [GroupCode] = N'construction');

    /* ------------------------------------------------------------------
       2. New specification definitions

       TEMP-RANGE     style_header.temp_min_c / temp_max_c. The schema's
                      'range' data type plus ValueMinDecimal/ValueMaxDecimal
                      holds both endpoints in one row, so the two source
                      columns do not need two definitions.

       DEFAULT-COLOUR style_header.default_colour. Declared single-select,
                      but its options are NOT seeded here -- see section 5
                      and the mapping doc. Nothing can populate it yet.
       ------------------------------------------------------------------ */
    MERGE [product].[SpecificationDefinitions] AS t
    USING (VALUES
        (N'TEMP-RANGE',     N'Operating temperature range', N'Minimum and maximum continuous conductor temperature.', N'range',         @DegC, @GrpThermal,      60, 1, 1),
        (N'DEFAULT-COLOUR', N'Default sheath colour',       N'Default outer sheath colour for the style.',            N'single-select', NULL,  @GrpConstruction, 70, 1, 0)
    ) AS s ([DefinitionCode], [DefinitionName], [Description], [DataTypeCode], [Unit], [SpecificationGroupId], [DisplayOrder], [IsFilterable], [IsComparable])
    ON t.[DefinitionCode] = s.[DefinitionCode]
    WHEN NOT MATCHED THEN
        INSERT ([DefinitionCode], [DefinitionName], [Description], [DataTypeCode], [Unit],
                [SpecificationGroupId], [DisplayOrder], [IsFilterable], [IsSearchable], [IsComparable],
                [ScopeCode], [IsRequired], [IsActive])
        VALUES (s.[DefinitionCode], s.[DefinitionName], s.[Description], s.[DataTypeCode], s.[Unit],
                s.[SpecificationGroupId], s.[DisplayOrder], s.[IsFilterable], 0, s.[IsComparable],
                N'product', 0, 1);

    /* ------------------------------------------------------------------
       3. STANDARD: text -> multi-select

       This is the one pre-existing definition the script changes, and the
       change is what makes the standards mapping possible at all.

       style_standard holds 226 marine + 1229 CableBuilder link rows -- eight
       to ten standards per style. As a 'text' definition the UNIQUE
       (ProductId, SpecificationDefinitionId) constraint permits exactly one
       row per product, which forces the standards back into one comma-joined
       string. That string is the defect catalog-load/sql/01_schema.sql:21-24
       exists to undo ('IEC 60332-3-22,  AS/NZS 3808' with a double space).

       IsFilterable is set at the same time so "which cables meet IEC 60754-2"
       is answerable, which is the stated reason for holding the links as rows.

       Placeholder ValueText already on this definition is counted and
       reported below, not deleted -- a multi-select row carrying only
       ValueText and no option link is inconsistent and needs a decision.
       ------------------------------------------------------------------ */
    DECLARE @StandardOrphanValues INT = (
        SELECT COUNT(*)
        FROM [product].[ProductSpecificationValues] v
        JOIN [product].[SpecificationDefinitions] d
          ON d.[SpecificationDefinitionId] = v.[SpecificationDefinitionId]
        WHERE d.[DefinitionCode] = N'STANDARD'
          AND v.[ValueText] IS NOT NULL
          AND v.[DeletedAtUtc] IS NULL
    );

    UPDATE [product].[SpecificationDefinitions]
    SET [DataTypeCode] = N'multi-select',
        [IsFilterable] = 1,
        [SpecificationGroupId] = COALESCE(
            [SpecificationGroupId],
            (SELECT [SpecificationGroupId] FROM [product].[SpecificationGroups] WHERE [GroupCode] = N'compliance')),
        [UpdatedAtUtc] = SYSUTCDATETIME()
    WHERE [DefinitionCode] = N'STANDARD'
      AND [DataTypeCode] <> N'multi-select';

    DECLARE @StandardDefId BIGINT = (
        SELECT [SpecificationDefinitionId]
        FROM [product].[SpecificationDefinitions]
        WHERE [DefinitionCode] = N'STANDARD'
    );

    IF @StandardDefId IS NULL
        RAISERROR(N'SpecificationDefinition STANDARD not found. Run seed-product-catalogue-sample.sql (or the definition seed that creates it) first.', 16, 1);

    /* ------------------------------------------------------------------
       4. Standards as specification options

       The 21 entries seeded by catalog-load/sql/01_schema.sql, verbatim.
       Only publications confirmed real are in that list; the two '1/2'
       entries are the source's shorthand for a pair and are carried here
       with verified = 0 upstream. See the mapping doc, "carried-over data
       quality".

       OptionLabel  the exact token that prints on a datasheet
       OptionValue  the publication title (SpecificationOptions has no
                    separate title column)
       OptionCode   a slug; '/' becomes '_' so 'IEC 60331-1/2' cannot
                    collide with the real standard 'IEC 60332-1-2'

       'body' (IEC / AS-NZS / NEK) has no column here. DisplayOrder is
       banded by body instead -- AS/NZS 10-99, IEC 100-899, NEK 900+ -- so
       datasheet order groups the way the published lists do.
       ------------------------------------------------------------------ */
    MERGE [product].[SpecificationOptions] AS t
    USING (VALUES
        (N'AS-NZS-1125',   N'AS/NZS 1125',    N'Conductors in insulated electric cables and flexible cords',        10),
        (N'AS-NZS-1429-1', N'AS/NZS 1429.1',  N'Electric cables - polymeric insulated, 1.9/3.3 kV to 19/33 kV',     20),
        (N'AS-NZS-3808',   N'AS/NZS 3808',    N'Insulating and sheathing materials for electric cables',            30),
        (N'IEC-60228',     N'IEC 60228',      N'Conductors of insulated cables',                                   100),
        (N'IEC-60092-350', N'IEC 60092-350',  N'Shipboard power cables - general construction and test methods',    110),
        (N'IEC-60092-353', N'IEC 60092-353',  N'Shipboard power cables - single and multicore, 0.6/1 kV',           120),
        (N'IEC-60092-360', N'IEC 60092-360',  N'Shipboard - insulating and sheathing materials',                    130),
        (N'IEC-60092-376', N'IEC 60092-376',  N'Shipboard - cables for control and instrumentation circuits',       140),
        (N'IEC-60331-1',   N'IEC 60331-1',    N'Circuit integrity under fire - cables over 0.6/1.0 kV',             200),
        (N'IEC-60331-2',   N'IEC 60331-2',    N'Circuit integrity under fire - cables up to 0.6/1.0 kV',            210),
        (N'IEC-60331-21',  N'IEC 60331-21',   N'Circuit integrity - procedures for cables up to 0.6/1.0 kV',        220),
        (N'IEC-60331-1_2', N'IEC 60331-1/2',  N'Source shorthand for IEC 60331-1 and -2 -- split pending review',   230),
        (N'IEC-60332-1',   N'IEC 60332-1',    N'Flame propagation - single insulated wire or cable',                300),
        (N'IEC-60332-1-2', N'IEC 60332-1-2',  N'Flame propagation - single cable, 1 kW pre-mixed flame',            310),
        (N'IEC-60332-3-22',N'IEC 60332-3-22', N'Flame spread - vertical bunched cables, category A',                320),
        (N'IEC-60754-1',   N'IEC 60754-1',    N'Halogen acid gas evolved during combustion',                        400),
        (N'IEC-60754-2',   N'IEC 60754-2',    N'Acidity and conductivity of gases evolved',                         410),
        (N'IEC-61034-1',   N'IEC 61034-1',    N'Smoke density - test apparatus',                                    500),
        (N'IEC-61034-2',   N'IEC 61034-2',    N'Smoke density - test procedure and requirements',                   510),
        (N'IEC-61034-1_2', N'IEC 61034-1/2',  N'Source shorthand for IEC 61034-1 and -2 -- split pending review',   520),
        (N'NEK-TS606-ED6', N'NEK TS606 Ed6',  N'Cables for offshore installations',                                 900)
    ) AS s ([OptionCode], [OptionLabel], [OptionValue], [DisplayOrder])
    ON  t.[SpecificationDefinitionId] = @StandardDefId
    AND t.[OptionCode] = s.[OptionCode]
    WHEN NOT MATCHED THEN
        INSERT ([SpecificationDefinitionId], [OptionCode], [OptionLabel], [OptionValue], [DisplayOrder], [IsActive])
        VALUES (@StandardDefId, s.[OptionCode], s.[OptionLabel], s.[OptionValue], s.[DisplayOrder], 1);

    /* ------------------------------------------------------------------
       5. DEFAULT-COLOUR options

       The full 14-code jacket colour legend, supplied 2026-08-07. This
       resolves the GR ambiguity that previously blocked the seed: GR is
       green, and grey is the separate code GY.

       Seeded in full rather than just the five codes the 25 marine styles
       use (BK, RE, BL, GR, OR), because this is the published controlled
       vocabulary and the remaining codes will be needed as more styles land.

       EA and RB are bi-colour on the legend -- half green/half yellow and
       half red/half blue. Their labels say so; a single swatch colour would
       misrepresent them.

       DisplayOrder follows the legend's own reading order across the grid,
       so a dropdown matches the printed sheet.

       Note this settles the *labels* only. Whether style_header.default_colour
       is authoritative is a separate open question -- see the mapping doc,
       open question 4, and the field-mapping note in section 9.
       ------------------------------------------------------------------ */
    DECLARE @ColourDefId BIGINT = (
        SELECT [SpecificationDefinitionId]
        FROM [product].[SpecificationDefinitions]
        WHERE [DefinitionCode] = N'DEFAULT-COLOUR'
    );

    MERGE [product].[SpecificationOptions] AS t
    USING (VALUES
        (N'BK', N'Black',           10),
        (N'BL', N'Blue',            20),
        (N'BR', N'Brown',           30),
        (N'EA', N'Green / Yellow',  40),
        (N'GR', N'Green',           50),
        (N'GY', N'Grey',            60),
        (N'OR', N'Orange',          70),
        (N'RE', N'Red',             80),
        (N'WH', N'White',           90),
        (N'YE', N'Yellow',         100),
        (N'PI', N'Pink',           110),
        (N'PU', N'Purple',         120),
        (N'LB', N'Light Blue',     130),
        (N'RB', N'Red / Blue',     140)
    ) AS s ([OptionCode], [OptionLabel], [DisplayOrder])
    ON  t.[SpecificationDefinitionId] = @ColourDefId
    AND t.[OptionCode] = s.[OptionCode]
    WHEN NOT MATCHED THEN
        INSERT ([SpecificationDefinitionId], [OptionCode], [OptionLabel], [OptionValue], [DisplayOrder], [IsActive])
        VALUES (@ColourDefId, s.[OptionCode], s.[OptionLabel], s.[OptionCode], s.[DisplayOrder], 1);

    /* ------------------------------------------------------------------
       6. Applications

       The six boolean facets on application_spec become rows here, which
       is the structural win of this target: the source carries one column
       per facet, so adding one costs an ALTER TABLE.

       OFFSHORE is the facet catalog-load could not add. The source
       distinguishes shipboard from offshore throughout;
       05_application_spec_patch.sql has the ALTER and seven UPDATEs written
       but commented out at its foot because MySQL needed a schema change to
       accept them. Here it is just another row.

       Per-style assignment of OFFSHORE is still unagreed -- this seeds the
       vocabulary, not the links. The seven styles the source marks offshore
       are listed in the mapping doc.
       ------------------------------------------------------------------ */
    MERGE [product].[Applications] AS t
    USING (VALUES
        (N'MARINE',          N'Marine and shipboard',  N'Shipboard and general marine installations.',                                        100),
        (N'OFFSHORE',        N'Offshore',              N'Offshore platforms and installations. Facet absent from legacy application_spec.',   110),
        (N'FIRE-RES',        N'Fire resistant',        N'Circuit integrity required under fire conditions.',                                  120),
        (N'VFD',             N'Variable frequency drive', N'Variable frequency / variable speed drive and motor circuits.',                    130),
        (N'INSTRUMENTATION', N'Instrumentation',       N'Instrumentation, signal and measurement circuits.',                                  140),
        (N'CONTROL',         N'Power and control',     N'Power, control and lighting circuits.',                                              150),
        (N'TELECOM',         N'Telecommunications',    N'Telecommunication, alarm and emergency systems.',                                    160)
    ) AS s ([ApplicationCode], [ApplicationName], [Description], [DisplayOrder])
    ON t.[ApplicationCode] = s.[ApplicationCode]
    WHEN NOT MATCHED THEN
        INSERT ([ApplicationCode], [ApplicationName], [Description], [DisplayOrder], [IsActive])
        VALUES (s.[ApplicationCode], s.[ApplicationName], s.[Description], s.[DisplayOrder], 1);

    /* ------------------------------------------------------------------
       7. Product categories

       Functional axis only, under the existing CABLE family. FLEX-POWER and
       CTRL-MULTI already cover power and control; these three cover what
       the marine styles need and CableBuilder's 'standard' values imply.

       Marine and offshore are deliberately NOT categories -- they are
       Applications (section 6) and Markets. Mixing market into the category
       tree is what makes a taxonomy unmaintainable, and this schema already
       separates the axes.

       ProductSeries is left alone. Nothing in the source maps cleanly onto
       a third level, and inventing one now would have to be undone. See the
       mapping doc, open question 3.
       ------------------------------------------------------------------ */
    DECLARE @CableFamilyId BIGINT = (
        SELECT [ProductFamilyId] FROM [product].[ProductFamilies] WHERE [FamilyCode] = N'CABLE'
    );

    IF @CableFamilyId IS NULL
        RAISERROR(N'ProductFamily CABLE not found. Run seed-product-catalogue-sample.sql first.', 16, 1);

    MERGE [product].[ProductCategories] AS t
    USING (VALUES
        (N'INSTRUMENTATION', N'Instrumentation and Signal', N'Screened instrumentation, signal and telecom cables.', 30),
        (N'VFD',             N'VFD / VSD',                  N'Variable frequency and variable speed drive cables.',  40),
        (N'SINGLE-CORE',     N'Single Insulated',           N'Single insulated power and switchboard/panel wire.',   50)
    ) AS s ([CategoryCode], [CategoryName], [Description], [DisplayOrder])
    ON t.[CategoryCode] = s.[CategoryCode]
    WHEN NOT MATCHED THEN
        INSERT ([ProductFamilyId], [CategoryCode], [CategoryName], [Description], [DisplayOrder], [IsActive])
        VALUES (@CableFamilyId, s.[CategoryCode], s.[CategoryName], s.[Description], s.[DisplayOrder], 1);

    /* ------------------------------------------------------------------
       8. Option aliases for the migration engine

       [migration].[SpecificationOptionAliases] is how the engine resolves a
       legacy string to an option without auto-creating one (Phase 12 rule:
       known alias only, no auto-create). Seeding the 21 exact standard_id
       values means every style_standard link row resolves on the first pass.

       NormalizedLegacyValue is the uppercased, internally-collapsed form, so
       the sources' whitespace damage ('IEC 60332-3-22,  AS/NZS 3808') still
       matches after the loader splits on comma and trims.

       The colour branch differs in one way that matters: the legacy value is
       the two-character code (BK), not the label (Black), because that is
       what style_header.default_colour stores.
       ------------------------------------------------------------------ */
    MERGE [migration].[SpecificationOptionAliases] AS t
    USING (
        SELECT
            N'tc'                  AS [SourceSystem],
            N'STANDARD'            AS [DefinitionCode],
            o.[OptionLabel]        AS [LegacyValue],
            UPPER(o.[OptionLabel]) AS [NormalizedLegacyValue],
            o.[PublicId]           AS [TargetOptionPublicId],
            o.[OptionCode]         AS [TargetOptionCode],
            N'Seeded from catalog-load/sql/01_schema.sql standards seed.' AS [Notes]
        FROM [product].[SpecificationOptions] o
        WHERE o.[SpecificationDefinitionId] = @StandardDefId

        UNION ALL

        SELECT
            N'tc',
            N'DEFAULT-COLOUR',
            o.[OptionCode],
            UPPER(o.[OptionCode]),
            o.[PublicId],
            o.[OptionCode],
            N'Seeded from the jacket colour legend, supplied 2026-08-07.'
        FROM [product].[SpecificationOptions] o
        WHERE o.[SpecificationDefinitionId] = @ColourDefId
    ) AS s
    ON  t.[SourceSystem] = s.[SourceSystem]
    AND t.[DefinitionCode] = s.[DefinitionCode]
    AND t.[NormalizedLegacyValue] = s.[NormalizedLegacyValue]
    WHEN NOT MATCHED THEN
        INSERT ([SourceSystem], [DefinitionCode], [LegacyValue], [NormalizedLegacyValue],
                [TargetOptionPublicId], [TargetOptionCode], [StatusCode], [Notes])
        VALUES (s.[SourceSystem], s.[DefinitionCode], s.[LegacyValue], s.[NormalizedLegacyValue],
                s.[TargetOptionPublicId], s.[TargetOptionCode], N'mapped', s.[Notes]);

    /* ------------------------------------------------------------------
       9. Specification field mappings

       The column-level crosswalk the engine reads. Rows with StatusCode
       'needs-review' are gaps recorded on purpose -- they make the missing
       target visible in the Admin UI instead of in a failed load.

       TransformationRule is free text; the engine defaults it to 'text' and
       does not yet enforce a vocabulary (AzureSqlMigrationBatchRepository.php:828).
       The values used here are proposed, and listed in the mapping doc.
       ------------------------------------------------------------------ */
    MERGE [migration].[SpecificationFieldMappings] AS t
    USING (VALUES
        (N'tc', N'style_header',       N'voltage_code',    N'VOLTAGE',        N'product', N'text',                 NULL,  N'V',  N'mapped',
            N'descript type 7201 code, stored verbatim. Promote to single-select once the code list is agreed -- see mapping doc open question 1.'),
        (N'tc', N'style_header',       N'temp_min_c',      N'TEMP-RANGE',     N'product', N'range-min',            N'C',  N'C',  N'mapped',
            N'SMALLINT degrees C into ValueMinDecimal.'),
        (N'tc', N'style_header',       N'temp_max_c',      N'TEMP-RANGE',     N'product', N'range-max',            N'C',  N'C',  N'mapped',
            N'SMALLINT degrees C into ValueMaxDecimal. Same target definition as temp_min_c.'),
        (N'tc', N'style_standard',     N'standard_id',     N'STANDARD',       N'product', N'multi-option-lookup',  NULL,  NULL,  N'mapped',
            N'Resolve via SpecificationOptionAliases. style_standard.seq is NOT preserved -- see mapping doc gap 2.'),
        (N'tc', N'style_header',       N'default_colour',  N'DEFAULT-COLOUR', N'product', N'option-lookup',        NULL,  NULL,  N'needs-review',
            N'Options seeded and labels confirmed (jacket colour legend, 2026-08-07). Still needs-review for provenance only: build_marine_load.py:175 derives this from the trailing two characters of design_example, which README.md:106 calls illustrative. Flip to mapped once the source column is confirmed authoritative.'),
        (N'tc', N'style_construction', N'layer_type',      NULL,              N'product', N'text',                 NULL,  NULL,  N'needs-review',
            N'No target. Ordered layer stack has no shape in product.*; proposed ProductConstructionLayers table in mapping doc gap 1.'),
        (N'tc', N'style_construction', N'material',        NULL,              N'product', N'text',                 NULL,  NULL,  N'needs-review',
            N'No target. See layer_type.')
    ) AS s ([SourceSystem], [SourceTable], [SourceField], [TargetDefinitionCode], [TargetScopeCode],
            [TransformationRule], [SourceUnit], [TargetUnit], [StatusCode], [Notes])
    ON  t.[SourceSystem] = s.[SourceSystem]
    AND t.[SourceTable]  = s.[SourceTable]
    AND t.[SourceField]  = s.[SourceField]
    WHEN NOT MATCHED THEN
        INSERT ([SourceSystem], [SourceTable], [SourceField], [TargetSpecificationDefinitionPublicId],
                [TargetDefinitionCode], [TargetScopeCode], [TransformationRule],
                [SourceUnit], [TargetUnit], [StatusCode], [Notes])
        VALUES (s.[SourceSystem], s.[SourceTable], s.[SourceField],
                (SELECT d.[PublicId] FROM [product].[SpecificationDefinitions] d WHERE d.[DefinitionCode] = s.[TargetDefinitionCode]),
                s.[TargetDefinitionCode], s.[TargetScopeCode], s.[TransformationRule],
                s.[SourceUnit], s.[TargetUnit], s.[StatusCode], s.[Notes]);

    /* The MERGE above is insert-only on purpose: these rows are meant to be
       edited by a human in the Admin UI, and a re-run must not overwrite that
       work. The one exception is the default_colour note, which was written
       while the colour labels were still unknown and is now stale. Refresh it
       only while it is still in its seeded state -- MappedByUserId NULL means
       nobody has touched it. */
    UPDATE [migration].[SpecificationFieldMappings]
    SET [Notes] = N'Options seeded and labels confirmed (jacket colour legend, 2026-08-07). '
                + N'Still needs-review for provenance only: build_marine_load.py:175 derives this '
                + N'from the trailing two characters of design_example, which README.md:106 calls '
                + N'illustrative. Flip to mapped once the source column is confirmed authoritative.',
        [UpdatedAtUtc] = SYSUTCDATETIME()
    WHERE [SourceSystem] = N'tc'
      AND [SourceTable]  = N'style_header'
      AND [SourceField]  = N'default_colour'
      AND [StatusCode]   = N'needs-review'
      AND [MappedByUserId] IS NULL
      AND [Notes] LIKE N'BLOCKED:%';

    /* ------------------------------------------------------------------
       10. Classification mappings -- recorded as unresolved

       application_spec is the only style-level classifier the source has,
       and it cannot decide a category on its own: [product].[Products] holds
       exactly one ProductCategoryId, while a style can carry several facets
       at once. Style FK is fire + instrumentation + control + telecom.

       These rows are seeded 'needs-review' with no target so the collision
       surfaces in the Admin UI. Phase 12 section 4 is explicit that this
       class of ambiguity stops and reports rather than picking a winner.
       ------------------------------------------------------------------ */
    MERGE [migration].[ClassificationMappings] AS t
    USING (VALUES
        (N'tc', N'application_spec', N'marine',          N'Facet, not a category. Maps to Application MARINE. No category implication.'),
        (N'tc', N'application_spec', N'fire',            N'Facet, not a category. Maps to Application FIRE-RES. Cuts across all categories.'),
        (N'tc', N'application_spec', N'vfd',             N'Candidate category VFD, but co-occurs with control on some styles.'),
        (N'tc', N'application_spec', N'instrumentation', N'Candidate category INSTRUMENTATION, but co-occurs with control and telecom on FK, FZ, FE, RB.'),
        (N'tc', N'application_spec', N'control',         N'Candidate category CTRL-MULTI or FLEX-POWER. Ambiguous between the two.'),
        (N'tc', N'application_spec', N'telecom',         N'Facet only. No dedicated category; overlaps INSTRUMENTATION.')
    ) AS s ([SourceSystem], [LegacyTaxonomy], [LegacyClassificationValue], [Notes])
    ON  t.[SourceSystem] = s.[SourceSystem]
    AND t.[LegacyTaxonomy] = s.[LegacyTaxonomy]
    AND t.[LegacyClassificationValue] = s.[LegacyClassificationValue]
    WHEN NOT MATCHED THEN
        INSERT ([SourceSystem], [LegacyTaxonomy], [LegacyClassificationValue],
                [TargetFamilyPublicId], [TargetCategoryPublicId], [TargetSeriesPublicId], [StatusCode], [Notes])
        VALUES (s.[SourceSystem], s.[LegacyTaxonomy], s.[LegacyClassificationValue],
                NULL, NULL, NULL, N'needs-review', s.[Notes]);

    COMMIT TRANSACTION;

    /* ------------------------------------------------------------------
       Verification
       ------------------------------------------------------------------ */
    SELECT
        (SELECT COUNT(*) FROM [product].[SpecificationGroups])                                                   AS SpecGroupCount,
        (SELECT COUNT(*) FROM [product].[SpecificationDefinitions] WHERE [DeletedAtUtc] IS NULL)                  AS SpecDefinitionCount,
        (SELECT COUNT(*) FROM [product].[SpecificationOptions] o
          JOIN [product].[SpecificationDefinitions] d ON d.[SpecificationDefinitionId] = o.[SpecificationDefinitionId]
          WHERE d.[DefinitionCode] = N'STANDARD')                                                                AS StandardOptionCount,
        (SELECT COUNT(*) FROM [product].[SpecificationOptions] o
          JOIN [product].[SpecificationDefinitions] d ON d.[SpecificationDefinitionId] = o.[SpecificationDefinitionId]
          WHERE d.[DefinitionCode] = N'DEFAULT-COLOUR')                                                          AS ColourOptionCount,
        (SELECT COUNT(*) FROM [product].[Applications] WHERE [DeletedAtUtc] IS NULL)                              AS ApplicationCount,
        (SELECT COUNT(*) FROM [product].[ProductCategories] WHERE [DeletedAtUtc] IS NULL)                         AS CategoryCount,
        (SELECT COUNT(*) FROM [migration].[SpecificationOptionAliases])                                           AS OptionAliasCount,
        (SELECT COUNT(*) FROM [migration].[SpecificationFieldMappings])                                           AS FieldMappingCount,
        (SELECT COUNT(*) FROM [migration].[SpecificationFieldMappings] WHERE [StatusCode] = N'needs-review')      AS FieldMappingNeedsReview,
        (SELECT COUNT(*) FROM [migration].[ClassificationMappings] WHERE [StatusCode] = N'needs-review')          AS ClassificationNeedsReview,
        @StandardOrphanValues                                                                                     AS StandardTextValuesToClear;

    /* Placeholder STANDARD values stranded by the text -> multi-select change.
       Listed, not deleted. Each needs either an option link or removal. */
    SELECT p.[ProductName], v.[ProductSpecificationValueId], v.[ValueText]
    FROM [product].[ProductSpecificationValues] v
    JOIN [product].[SpecificationDefinitions] d ON d.[SpecificationDefinitionId] = v.[SpecificationDefinitionId]
    JOIN [product].[Products] p ON p.[ProductId] = v.[ProductId]
    WHERE d.[DefinitionCode] = N'STANDARD'
      AND v.[ValueText] IS NOT NULL
      AND v.[DeletedAtUtc] IS NULL
    ORDER BY p.[ProductName];
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
    DECLARE @ErrorMessage NVARCHAR(4000) = ERROR_MESSAGE();
    DECLARE @ErrorSeverity INT = ERROR_SEVERITY();
    DECLARE @ErrorState INT = ERROR_STATE();
    RAISERROR(@ErrorMessage, @ErrorSeverity, @ErrorState);
END CATCH;
