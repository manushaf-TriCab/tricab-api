/*
================================================================================
  Phase 5.4 — policy sign-off: [onboarding].[PolicyAcknowledgements].[DeclarationJson].

  STATUS: NOT APPLIED to any shared database. Do not run against tricab-mel or
  production without the owner's explicit approval.

  Owner decision, 24 Sep 2026: the signature (or the Code of Conduct's
  declaration) is part of acknowledging the policy and is stored on the
  acknowledgement row, not kept as a separate form.

    [DeclarationJson]  NULL for a policy that is only acknowledged. For the
                       seven that are signed, `{"kind": "sign_off" |
                       "code_of_conduct", "values": {...}}`: the name, the
                       drawn signature as a PNG data URL, the date the server
                       wrote, and for the Code of Conduct the role and
                       conflict-of-interest answers. Ordinary HR data (C2), as
                       the signed forms' answers are. The shape is checked by
                       PolicyDeclaration in core, which is the only writer.

  The row stays append-only: TR_PolicyAcknowledgements_Immutable is untouched,
  and the column is written only by the INSERT that records the acknowledgement.

  Additive only: one new nullable column. Nothing is dropped, renamed or
  rewritten, and existing acknowledgements keep NULL. Guarded and idempotent.
  Until it is applied, policies read as before; acknowledging one of the seven
  signed policies fails and names this script.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;
GO

BEGIN TRY
    BEGIN TRANSACTION;

    IF COL_LENGTH(N'[onboarding].[PolicyAcknowledgements]', N'DeclarationJson') IS NULL
        ALTER TABLE [onboarding].[PolicyAcknowledgements] ADD [DeclarationJson] NVARCHAR(MAX) NULL;

    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
    THROW;
END CATCH;
GO

/* A CHECK needs the column to exist when the batch compiles, so it has its own. */
IF NOT EXISTS (SELECT 1 FROM sys.check_constraints WHERE name = N'CK_onboarding_PolicyAcknowledgements_DeclarationJson')
    ALTER TABLE [onboarding].[PolicyAcknowledgements] ADD CONSTRAINT [CK_onboarding_PolicyAcknowledgements_DeclarationJson]
        CHECK ([DeclarationJson] IS NULL OR ISJSON([DeclarationJson]) = 1);
GO

/* Verification — read-only. */
SELECT c.name AS [Column], TYPE_NAME(c.user_type_id) AS [Type], c.max_length AS [MaxLength], c.is_nullable AS [IsNullable]
FROM sys.columns c
WHERE c.object_id = OBJECT_ID(N'[onboarding].[PolicyAcknowledgements]')
  AND c.name = N'DeclarationJson';
GO
