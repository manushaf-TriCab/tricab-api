/*
================================================================================
  Onboarding demo data — SYNTHETIC ONLY, for looking at the screens locally.

  Not part of rebuild-local-db.ps1 and not for any production database.
  Remove with remove-onboarding-demo-data.sql.

  Creates:
    - 2 published training modules (with questions), 2 published policies,
      1 DRAFT policy (to show a draft is not offered for allocation), and
      2 forms whose fields are all classified C2 (so they can be submitted);
    - 3 synthetic people at @example.test in status `pending` (AllowsLogin = 0,
      so nobody can sign in as them) — one of them a leaver;
    - an induction record for @OwnerUserId, so the owner can see both the HR
      console and their own /onboarding;
    - assignments across the statuses, with the evidence rows that go with
      them (presentation progress, a draft, a submission, an acknowledgement).

  Everything it creates carries a `demo-` slug, an @example.test address, or
  the Position `Demo profile`, which is how the removal script finds it.

  Idempotent: does nothing if demo content already exists.
================================================================================
*/
SET NOCOUNT ON;
SET XACT_ABORT ON;

DECLARE @OwnerUserId BIGINT = 2;   -- manushaf@tricab.com on tricab-mel

IF EXISTS (SELECT 1 FROM [onboarding].[TrainingModules] WHERE [Slug] LIKE N'demo-%')
BEGIN
    PRINT N'Demo data already present; nothing done.';
    RETURN;
END;

IF NOT EXISTS (SELECT 1 FROM [identity].[Users] WHERE [UserId] = @OwnerUserId)
BEGIN
    THROW 51000, N'Owner user not found.', 1;
END;

BEGIN TRANSACTION;

DECLARE @Pending BIGINT = (SELECT [UserStatusId] FROM [identity].[UserStatuses] WHERE [StatusCode] = N'pending');

/* ------------------------------------------------------------ people */
INSERT INTO [identity].[Users] ([EmailAddress], [NormalizedEmailAddress], [DisplayName], [UserStatusId])
VALUES (N'demo.alex.ng@example.test',     N'demo.alex.ng@example.test',     N'Alex Ng (demo)',     @Pending),
       (N'demo.priya.shah@example.test',  N'demo.priya.shah@example.test',  N'Priya Shah (demo)',  @Pending),
       (N'demo.jordan.lee@example.test',  N'demo.jordan.lee@example.test',  N'Jordan Lee (demo)',  @Pending);

DECLARE @Alex BIGINT   = (SELECT [UserId] FROM [identity].[Users] WHERE [EmailAddress] = N'demo.alex.ng@example.test');
DECLARE @Priya BIGINT  = (SELECT [UserId] FROM [identity].[Users] WHERE [EmailAddress] = N'demo.priya.shah@example.test');
DECLARE @Jordan BIGINT = (SELECT [UserId] FROM [identity].[Users] WHERE [EmailAddress] = N'demo.jordan.lee@example.test');

INSERT INTO [onboarding].[TraineeProfiles] ([UserId], [Position], [Department], [Branch], [StartDate], [InvitedAtUtc], [InvitedByUserId], [DepartedAtUtc], [DepartureRecordedByUserId])
VALUES (@OwnerUserId, N'Demo profile', N'Operations', N'Dandenong', DATEADD(DAY, 14, CAST(SYSUTCDATETIME() AS DATE)), NULL, NULL, NULL, NULL),
       (@Alex,   N'Storeperson',       N'Warehouse', N'Dandenong', DATEADD(DAY, 7,  CAST(SYSUTCDATETIME() AS DATE)), SYSUTCDATETIME(), @OwnerUserId, NULL, NULL),
       (@Priya,  N'Customer service',  N'Sales',     N'Brisbane',  DATEADD(DAY, -10, CAST(SYSUTCDATETIME() AS DATE)), SYSUTCDATETIME(), @OwnerUserId, NULL, NULL),
       (@Jordan, N'Forklift operator', N'Warehouse', N'Perth',     DATEADD(DAY, -90, CAST(SYSUTCDATETIME() AS DATE)), SYSUTCDATETIME(), @OwnerUserId,
                 DATEADD(DAY, -30, SYSUTCDATETIME()), @OwnerUserId);

/* ------------------------------------------------------------ training */
INSERT INTO [onboarding].[TrainingModules] ([Slug], [Title], [Summary])
VALUES (N'demo-site-induction', N'Warehouse site induction (demo)', N'How the floor works, where not to walk, and how to lift without hurting yourself.'),
       (N'demo-forklift-awareness', N'Forklift awareness (demo)', N'For anybody who works near moving plant.');

DECLARE @Induction BIGINT = (SELECT [TrainingModuleId] FROM [onboarding].[TrainingModules] WHERE [Slug] = N'demo-site-induction');
DECLARE @Forklift BIGINT  = (SELECT [TrainingModuleId] FROM [onboarding].[TrainingModules] WHERE [Slug] = N'demo-forklift-awareness');

INSERT INTO [onboarding].[TrainingModuleVersions] ([TrainingModuleId], [VersionNumber], [PresentationManifestJson], [RequiredSlideCount], [PassMarkPercent], [IsPublished], [PublishedAtUtc], [PublishedByUserId])
VALUES (@Induction, 1, N'[{"id":"welcome","title":"Welcome to the floor"},{"id":"hazards","title":"Hazards and exclusion zones"},{"id":"lifting","title":"Lifting safely"},{"id":"sign-off","title":"Before you start"}]', 4, 80, 1, SYSUTCDATETIME(), @OwnerUserId),
       (@Forklift,  1, N'[{"id":"intro","title":"Why forklifts are dangerous"},{"id":"zones","title":"Pedestrian zones"},{"id":"eye-contact","title":"Make eye contact"}]', 3, 100, 1, SYSUTCDATETIME(), @OwnerUserId);

DECLARE @InductionV BIGINT = (SELECT [TrainingModuleVersionId] FROM [onboarding].[TrainingModuleVersions] WHERE [TrainingModuleId] = @Induction);
DECLARE @ForkliftV BIGINT  = (SELECT [TrainingModuleVersionId] FROM [onboarding].[TrainingModuleVersions] WHERE [TrainingModuleId] = @Forklift);

INSERT INTO [onboarding].[Questions] ([TrainingModuleVersionId], [Ordinal], [Prompt], [OptionsJson], [CorrectOptionKey], [CorrectReason])
VALUES (@InductionV, 1, N'Where may you walk while a forklift is operating?',
        N'[{"key":"a","label":"Anywhere, if you are quick"},{"key":"b","label":"Only in the marked walkways"}]', N'b',
        N'Walkways are the only place a driver expects a person to be.'),
       (@InductionV, 2, N'What do you do before lifting a drum over 20 kg?',
        N'[{"key":"a","label":"Ask for a second person or a lifting aid"},{"key":"b","label":"Lift it with a straight back"}]', N'a',
        N'Technique does not make an unsafe weight safe.'),
       (@ForkliftV, 1, N'A forklift is reversing towards you. What first?',
        N'[{"key":"a","label":"Wave and keep walking"},{"key":"b","label":"Stop, and wait for the driver to see you"}]', N'b',
        N'A driver who has not seen you cannot avoid you.');

/* ------------------------------------------------------------ policies */
INSERT INTO [onboarding].[Policies] ([Slug], [Title])
VALUES (N'demo-whs-policy', N'Workplace health and safety (demo)'),
       (N'demo-code-of-conduct', N'Code of conduct (demo)'),
       (N'demo-leave-policy', N'Leave policy — draft (demo)');

DECLARE @Whs BIGINT     = (SELECT [PolicyId] FROM [onboarding].[Policies] WHERE [Slug] = N'demo-whs-policy');
DECLARE @Conduct BIGINT = (SELECT [PolicyId] FROM [onboarding].[Policies] WHERE [Slug] = N'demo-code-of-conduct');
DECLARE @Leave BIGINT   = (SELECT [PolicyId] FROM [onboarding].[Policies] WHERE [Slug] = N'demo-leave-policy');

INSERT INTO [onboarding].[PolicyVersions] ([PolicyId], [VersionNumber], [ContentJson], [EffectiveFrom], [IsPublished], [PublishedAtUtc], [PublishedByUserId])
VALUES (@Whs, 1, N'{"blocks":[{"type":"heading","text":"Purpose"},{"type":"paragraph","text":"Everyone at TriCab goes home safe. This is a demonstration policy with no force."},{"type":"list","items":["Wear the PPE your area requires","Report every hazard and near miss","Stop work if it is unsafe"]}],"declaration":"I have read and understood the Workplace health and safety policy (demo), version 1."}',
        DATEFROMPARTS(2026, 7, 1), 1, SYSUTCDATETIME(), @OwnerUserId),
       (@Conduct, 1, N'{"blocks":[{"type":"paragraph","text":"Treat people with respect, and tell somebody when something is wrong. A demonstration policy."}],"declaration":"I agree to follow the Code of conduct (demo)."}',
        DATEFROMPARTS(2026, 1, 1), 1, SYSUTCDATETIME(), @OwnerUserId),
       (@Leave, 1, N'{"blocks":[{"type":"paragraph","text":"Not yet published."}]}', NULL, 0, NULL, NULL);

DECLARE @WhsV BIGINT     = (SELECT [PolicyVersionId] FROM [onboarding].[PolicyVersions] WHERE [PolicyId] = @Whs);
DECLARE @ConductV BIGINT = (SELECT [PolicyVersionId] FROM [onboarding].[PolicyVersions] WHERE [PolicyId] = @Conduct);

/* ------------------------------------------------------------ forms */
INSERT INTO [onboarding].[FormDefinitions] ([Slug], [Title], [AccessGroup])
VALUES (N'demo-starter-details', N'Starter details (demo)', N'hr'),
       (N'demo-uniform-request', N'Uniform request (demo)', N'hr');

DECLARE @Starter BIGINT = (SELECT [FormDefinitionId] FROM [onboarding].[FormDefinitions] WHERE [Slug] = N'demo-starter-details');
DECLARE @Uniform BIGINT = (SELECT [FormDefinitionId] FROM [onboarding].[FormDefinitions] WHERE [Slug] = N'demo-uniform-request');

INSERT INTO [onboarding].[FormDefinitionVersions] ([FormDefinitionId], [VersionNumber], [SchemaJson], [IsPublished], [PublishedAtUtc], [PublishedByUserId])
VALUES (@Starter, 1, N'{"fields":[{"path":"preferredName","label":"Preferred name","type":"text","required":true},{"path":"startDate","label":"Start date","type":"date","required":true,"hint":"The date on your offer letter."},{"path":"parking","label":"Do you need a parking space?","type":"select","options":[{"key":"yes","label":"Yes"},{"key":"no","label":"No"}]},{"path":"notes","label":"Anything HR should know","type":"textarea"},{"path":"confirm","label":"The details above are correct","type":"checkbox","required":true}]}',
        1, SYSUTCDATETIME(), @OwnerUserId),
       (@Uniform, 1, N'{"fields":[{"path":"shirtSize","label":"Shirt size","type":"select","required":true,"options":[{"key":"s","label":"Small"},{"key":"m","label":"Medium"},{"key":"l","label":"Large"}]},{"path":"bootSize","label":"Boot size","type":"number","required":true,"min":1,"max":20}]}',
        1, SYSUTCDATETIME(), @OwnerUserId);

DECLARE @StarterV BIGINT = (SELECT [FormDefinitionVersionId] FROM [onboarding].[FormDefinitionVersions] WHERE [FormDefinitionId] = @Starter);
DECLARE @UniformV BIGINT = (SELECT [FormDefinitionVersionId] FROM [onboarding].[FormDefinitionVersions] WHERE [FormDefinitionId] = @Uniform);

/* Every field explicitly ordinary (C2), so a submission splits cleanly. */
INSERT INTO [onboarding_hr].[FieldClassifications] ([FormDefinitionVersionId], [FieldPath], [RetentionClassId], [ClassifiedByUserId])
VALUES (@StarterV, N'preferredName', 2, @OwnerUserId), (@StarterV, N'startDate', 2, @OwnerUserId),
       (@StarterV, N'parking', 2, @OwnerUserId),       (@StarterV, N'notes', 2, @OwnerUserId),
       (@StarterV, N'confirm', 2, @OwnerUserId),
       (@UniformV, N'shirtSize', 2, @OwnerUserId),     (@UniformV, N'bootSize', 2, @OwnerUserId);

/* ------------------------------------------------------------ assignments */
DECLARE @Today DATE = CAST(SYSUTCDATETIME() AS DATE);

-- Owner: the three kinds, one of them started.
INSERT INTO [onboarding].[Assignments] ([UserId], [ItemType], [TrainingModuleVersionId], [Status], [AllocatedByUserId], [DueDate], [StartedAtUtc])
VALUES (@OwnerUserId, N'training', @InductionV, N'in_progress', @OwnerUserId, DATEADD(DAY, 10, @Today), SYSUTCDATETIME());
DECLARE @OwnerInduction BIGINT = SCOPE_IDENTITY();
INSERT INTO [onboarding].[PresentationProgress] ([AssignmentId], [TrainingModuleVersionId], [SlideIdsJson], [ReachedSlideCount], [ReachedFinalSlide], [LastSeenAtUtc])
VALUES (@OwnerInduction, @InductionV, N'["welcome","hazards"]', 2, 0, SYSUTCDATETIME());

INSERT INTO [onboarding].[Assignments] ([UserId], [ItemType], [PolicyVersionId], [AllocatedByUserId])
VALUES (@OwnerUserId, N'policy', @WhsV, @OwnerUserId);
INSERT INTO [onboarding].[Assignments] ([UserId], [ItemType], [FormDefinitionVersionId], [AllocatedByUserId], [DueDate])
VALUES (@OwnerUserId, N'form', @StarterV, @OwnerUserId, DATEADD(DAY, 5, @Today));

-- Alex: untouched work (unticking withdraws it), and a form handed in.
INSERT INTO [onboarding].[Assignments] ([UserId], [ItemType], [TrainingModuleVersionId], [AllocatedByUserId], [DueDate])
VALUES (@Alex, N'training', @InductionV, @OwnerUserId, DATEADD(DAY, 7, @Today));
INSERT INTO [onboarding].[Assignments] ([UserId], [ItemType], [PolicyVersionId], [AllocatedByUserId])
VALUES (@Alex, N'policy', @ConductV, @OwnerUserId);
INSERT INTO [onboarding].[Assignments] ([UserId], [ItemType], [FormDefinitionVersionId], [Status], [AllocatedByUserId], [StartedAtUtc], [SubmittedAtUtc])
VALUES (@Alex, N'form', @UniformV, N'submitted', @OwnerUserId, SYSUTCDATETIME(), SYSUTCDATETIME());
DECLARE @AlexUniform BIGINT = SCOPE_IDENTITY();
INSERT INTO [onboarding].[FormSubmissions] ([AssignmentId], [UserId], [FormDefinitionVersionId], [RevisionNumber], [SubmittedDataJson])
VALUES (@AlexUniform, @Alex, @UniformV, 1, N'{"shirtSize":"m","bootSize":9}');

-- Priya: finished work (unticking keeps it), and a draft in progress.
INSERT INTO [onboarding].[Assignments] ([UserId], [ItemType], [TrainingModuleVersionId], [Status], [AllocatedByUserId], [StartedAtUtc], [CompletedAtUtc])
VALUES (@Priya, N'training', @ForkliftV, N'completed', @OwnerUserId, DATEADD(DAY, -5, SYSUTCDATETIME()), DATEADD(DAY, -4, SYSUTCDATETIME()));
DECLARE @PriyaForklift BIGINT = SCOPE_IDENTITY();
INSERT INTO [onboarding].[PresentationProgress] ([AssignmentId], [TrainingModuleVersionId], [SlideIdsJson], [ReachedSlideCount], [ReachedFinalSlide], [LastSeenAtUtc])
VALUES (@PriyaForklift, @ForkliftV, N'["intro","zones","eye-contact"]', 3, 1, DATEADD(DAY, -4, SYSUTCDATETIME()));

INSERT INTO [onboarding].[Assignments] ([UserId], [ItemType], [PolicyVersionId], [Status], [AllocatedByUserId], [StartedAtUtc], [CompletedAtUtc])
VALUES (@Priya, N'policy', @WhsV, N'completed', @OwnerUserId, DATEADD(DAY, -3, SYSUTCDATETIME()), DATEADD(DAY, -3, SYSUTCDATETIME()));
DECLARE @PriyaWhs BIGINT = SCOPE_IDENTITY();
INSERT INTO [onboarding].[PolicyAcknowledgements] ([AssignmentId], [UserId], [PolicyVersionId], [DeclarationText])
VALUES (@PriyaWhs, @Priya, @WhsV, N'I have read and understood the Workplace health and safety policy (demo), version 1.');

INSERT INTO [onboarding].[Assignments] ([UserId], [ItemType], [FormDefinitionVersionId], [Status], [AllocatedByUserId], [StartedAtUtc])
VALUES (@Priya, N'form', @StarterV, N'in_progress', @OwnerUserId, SYSUTCDATETIME());
DECLARE @PriyaStarter BIGINT = SCOPE_IDENTITY();
INSERT INTO [onboarding].[FormDrafts] ([AssignmentId], [DraftDataJson])
VALUES (@PriyaStarter, N'{"preferredName":"Priya"}');

-- Jordan (left 30 days ago): one module part-way through.
INSERT INTO [onboarding].[Assignments] ([UserId], [ItemType], [TrainingModuleVersionId], [Status], [AllocatedByUserId], [StartedAtUtc])
VALUES (@Jordan, N'training', @InductionV, N'in_progress', @OwnerUserId, DATEADD(DAY, -40, SYSUTCDATETIME()));

COMMIT TRANSACTION;
PRINT N'Onboarding demo data created.';
