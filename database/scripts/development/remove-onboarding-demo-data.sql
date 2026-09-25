/*
================================================================================
  Removes what seed-onboarding-demo-data.sql created — and only that.

  Found by marker: `demo-` slugs, @example.test addresses beginning `demo.`,
  and the owner's profile with Position `Demo profile`.

  Two things are deliberately NOT removed:

    - Audit rows. [audit].[OnboardingStatusEvents], [OnboardingAdminEvents] and
      [OnboardingDocumentAccessEvents] are append-only and this script does not
      disable their triggers. Anything you did while clicking round the demo
      stays on record.
    - A synthetic user who is named in one of those audit rows. The row's
      foreign key keeps them. They stay in status `pending`, which cannot sign
      in, so they are inert.

  The evidence tables' immutability triggers (FormSubmissions,
  PolicyAcknowledgements, AttemptAnswers, RestrictedSubmissionData) are disabled inside the transaction
  for the duration of the demo-row deletes and re-enabled before it commits.
  If anything fails, the whole transaction — trigger state included — rolls back.
================================================================================
*/
SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRANSACTION;

DECLARE @Users TABLE ([UserId] BIGINT PRIMARY KEY);
INSERT INTO @Users SELECT [UserId] FROM [identity].[Users] WHERE [EmailAddress] LIKE N'demo.%@example.test';

DECLARE @Assignments TABLE ([AssignmentId] BIGINT PRIMARY KEY);
INSERT INTO @Assignments
SELECT a.[AssignmentId]
FROM [onboarding].[Assignments] a
LEFT JOIN [onboarding].[TrainingModuleVersions] tmv ON tmv.[TrainingModuleVersionId] = a.[TrainingModuleVersionId]
LEFT JOIN [onboarding].[TrainingModules] tm ON tm.[TrainingModuleId] = tmv.[TrainingModuleId]
LEFT JOIN [onboarding].[PolicyVersions] pv ON pv.[PolicyVersionId] = a.[PolicyVersionId]
LEFT JOIN [onboarding].[Policies] p ON p.[PolicyId] = pv.[PolicyId]
LEFT JOIN [onboarding].[FormDefinitionVersions] fdv ON fdv.[FormDefinitionVersionId] = a.[FormDefinitionVersionId]
LEFT JOIN [onboarding].[FormDefinitions] fd ON fd.[FormDefinitionId] = fdv.[FormDefinitionId]
WHERE COALESCE(tm.[Slug], p.[Slug], fd.[Slug]) LIKE N'demo-%'
   OR a.[UserId] IN (SELECT [UserId] FROM @Users);

DISABLE TRIGGER [onboarding].[TR_FormSubmissions_Immutable] ON [onboarding].[FormSubmissions];
DISABLE TRIGGER [onboarding].[TR_PolicyAcknowledgements_Immutable] ON [onboarding].[PolicyAcknowledgements];
DISABLE TRIGGER [onboarding].[TR_AttemptAnswers_Immutable] ON [onboarding].[AttemptAnswers];
DISABLE TRIGGER [onboarding_hr].[TR_RestrictedSubmissionData_Immutable] ON [onboarding_hr].[RestrictedSubmissionData];

DELETE aa FROM [onboarding].[AttemptAnswers] aa
    JOIN [onboarding].[Attempts] at ON at.[AttemptId] = aa.[AttemptId]
    WHERE at.[AssignmentId] IN (SELECT [AssignmentId] FROM @Assignments);
DELETE FROM [onboarding].[Attempts] WHERE [AssignmentId] IN (SELECT [AssignmentId] FROM @Assignments);
DELETE FROM [onboarding].[PresentationProgress] WHERE [AssignmentId] IN (SELECT [AssignmentId] FROM @Assignments);
DELETE FROM [onboarding].[PolicyAcknowledgements] WHERE [AssignmentId] IN (SELECT [AssignmentId] FROM @Assignments);
DELETE FROM [onboarding].[FormDrafts] WHERE [AssignmentId] IN (SELECT [AssignmentId] FROM @Assignments);
DELETE rsd FROM [onboarding_hr].[RestrictedSubmissionData] rsd
    JOIN [onboarding].[FormSubmissions] fs ON fs.[PublicId] = rsd.[FormSubmissionPublicId]
    WHERE fs.[AssignmentId] IN (SELECT [AssignmentId] FROM @Assignments);
DELETE FROM [onboarding].[FormSubmissions] WHERE [AssignmentId] IN (SELECT [AssignmentId] FROM @Assignments);
DELETE FROM [onboarding].[DocumentReferences] WHERE [AssignmentId] IN (SELECT [AssignmentId] FROM @Assignments);

ENABLE TRIGGER [onboarding].[TR_FormSubmissions_Immutable] ON [onboarding].[FormSubmissions];
ENABLE TRIGGER [onboarding].[TR_PolicyAcknowledgements_Immutable] ON [onboarding].[PolicyAcknowledgements];
ENABLE TRIGGER [onboarding].[TR_AttemptAnswers_Immutable] ON [onboarding].[AttemptAnswers];
ENABLE TRIGGER [onboarding_hr].[TR_RestrictedSubmissionData_Immutable] ON [onboarding_hr].[RestrictedSubmissionData];

DELETE FROM [onboarding].[Assignments] WHERE [AssignmentId] IN (SELECT [AssignmentId] FROM @Assignments);

/* Completion rows for demo items, whoever holds them. */
DELETE cp FROM [onboarding].[CompletionProjection] cp
    WHERE cp.[UserId] IN (SELECT [UserId] FROM @Users)
       OR cp.[ItemPublicId] IN (
            SELECT [PublicId] FROM [onboarding].[TrainingModules] WHERE [Slug] LIKE N'demo-%'
            UNION ALL SELECT [PublicId] FROM [onboarding].[Policies] WHERE [Slug] LIKE N'demo-%'
            UNION ALL SELECT [PublicId] FROM [onboarding].[FormDefinitions] WHERE [Slug] LIKE N'demo-%');

DELETE fc FROM [onboarding_hr].[FieldClassifications] fc
    JOIN [onboarding].[FormDefinitionVersions] v ON v.[FormDefinitionVersionId] = fc.[FormDefinitionVersionId]
    JOIN [onboarding].[FormDefinitions] d ON d.[FormDefinitionId] = v.[FormDefinitionId]
    WHERE d.[Slug] LIKE N'demo-%';
DELETE q FROM [onboarding].[Questions] q
    JOIN [onboarding].[TrainingModuleVersions] v ON v.[TrainingModuleVersionId] = q.[TrainingModuleVersionId]
    JOIN [onboarding].[TrainingModules] m ON m.[TrainingModuleId] = v.[TrainingModuleId]
    WHERE m.[Slug] LIKE N'demo-%';
DELETE v FROM [onboarding].[TrainingModuleVersions] v
    JOIN [onboarding].[TrainingModules] m ON m.[TrainingModuleId] = v.[TrainingModuleId] WHERE m.[Slug] LIKE N'demo-%';
DELETE FROM [onboarding].[TrainingModules] WHERE [Slug] LIKE N'demo-%';
DELETE v FROM [onboarding].[PolicyVersions] v
    JOIN [onboarding].[Policies] p ON p.[PolicyId] = v.[PolicyId] WHERE p.[Slug] LIKE N'demo-%';
DELETE FROM [onboarding].[Policies] WHERE [Slug] LIKE N'demo-%';
DELETE v FROM [onboarding].[FormDefinitionVersions] v
    JOIN [onboarding].[FormDefinitions] d ON d.[FormDefinitionId] = v.[FormDefinitionId] WHERE d.[Slug] LIKE N'demo-%';
DELETE FROM [onboarding].[FormDefinitions] WHERE [Slug] LIKE N'demo-%';

DELETE FROM [onboarding].[OnboardingWriteIdempotency]
    WHERE [ActorKey] IN (SELECT N'user:' + CAST([UserId] AS NVARCHAR(20)) FROM @Users);
DELETE FROM [onboarding].[NotificationLog] WHERE [UserId] IN (SELECT [UserId] FROM @Users);
DELETE FROM [onboarding_hr].[PayrollDetails] WHERE [UserId] IN (SELECT [UserId] FROM @Users);
DELETE FROM [onboarding_hr].[RestrictedPersonalDetails] WHERE [UserId] IN (SELECT [UserId] FROM @Users);
DELETE FROM [onboarding_hr].[EmergencyContacts] WHERE [UserId] IN (SELECT [UserId] FROM @Users);
DELETE FROM [identity].[UserEmailAddresses] WHERE [UserId] IN (SELECT [UserId] FROM @Users);
DELETE FROM [onboarding].[TraineeProfiles]
    WHERE [UserId] IN (SELECT [UserId] FROM @Users)
       OR ([Position] = N'Demo profile');

DELETE u FROM [identity].[Users] u
    WHERE u.[UserId] IN (SELECT [UserId] FROM @Users)
      AND NOT EXISTS (SELECT 1 FROM [audit].[OnboardingStatusEvents] e WHERE e.[SubjectUserId] = u.[UserId] OR e.[ActorUserId] = u.[UserId])
      AND NOT EXISTS (SELECT 1 FROM [audit].[OnboardingAdminEvents] e WHERE e.[SubjectUserId] = u.[UserId] OR e.[ActorUserId] = u.[UserId])
      AND NOT EXISTS (SELECT 1 FROM [audit].[OnboardingDocumentAccessEvents] e WHERE e.[SubjectUserId] = u.[UserId] OR e.[ActorUserId] = u.[UserId]);

COMMIT TRANSACTION;
PRINT N'Onboarding demo data removed.';
