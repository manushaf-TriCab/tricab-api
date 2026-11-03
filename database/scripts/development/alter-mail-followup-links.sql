/*
================================================================================
  RFQ mail intake M6 "drafts on": follow-up links (RFQ plan 6.2, inbox plan I4.3).
  Additive to create-mail-schema.sql; run after it.

  STATUS: NOT APPLIED to any shared database. Apply to tricab-mel BEFORE the API
  change that writes follow-up links is deployed (api feat/rfq-mail-drafts).
  Applying it is Manusha's or Jeff's. Nothing in it reads or changes a row, and
  until RFQ_MAIL_MODE=live with RFQ_MAIL_DRAFTS_ENABLED=true nothing writes the
  new values, so the API can be deployed first only if drafts stay off.

  A follow-up message in a conversation that already has a draft is joined to
  that draft. That needs three changes to [mail]:

    1. [message_links].[kind] may also be N'rfq_followup' (CK_mail_message_links_kind).
    2. [activity].[kind] may also be N'followup_linked' (CK_mail_activity_kind).
    3. UX_mail_message_links_draft becomes a filtered index on kind = N'rfq_draft'
       (a follow-up carries the draft's public id, so the old index would refuse
       it), and UX_mail_message_links_followup makes a message's follow-up link
       unique, so a message can be linked once however often it is processed.

  create-mail-schema.sql now creates all three. This script is for a database
  that ran it before this change; on one that already has them it does nothing,
  so it is safe to run any number of times. It changes no data.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;
GO

IF OBJECT_ID(N'[mail].[message_links]', N'U') IS NULL
    OR OBJECT_ID(N'[mail].[activity]', N'U') IS NULL
BEGIN
    RAISERROR(N'The [mail] schema is missing. Run create-mail-schema.sql first.', 16, 1);
    SET NOEXEC ON;
END;
GO

BEGIN TRY
    BEGIN TRANSACTION;

    -- 1. message_links.kind
    IF NOT EXISTS (
        SELECT 1 FROM sys.check_constraints
         WHERE [name] = N'CK_mail_message_links_kind'
           AND [parent_object_id] = OBJECT_ID(N'[mail].[message_links]')
           AND [definition] LIKE N'%rfq_followup%'
    )
    BEGIN
        IF EXISTS (SELECT 1 FROM sys.check_constraints WHERE [name] = N'CK_mail_message_links_kind' AND [parent_object_id] = OBJECT_ID(N'[mail].[message_links]'))
        BEGIN
            ALTER TABLE [mail].[message_links] DROP CONSTRAINT [CK_mail_message_links_kind];
        END;
        ALTER TABLE [mail].[message_links] WITH CHECK
            ADD CONSTRAINT [CK_mail_message_links_kind] CHECK ([kind] IN (N'rfq_draft', N'rfq_followup'));
    END;

    -- 2. activity.kind
    IF NOT EXISTS (
        SELECT 1 FROM sys.check_constraints
         WHERE [name] = N'CK_mail_activity_kind'
           AND [parent_object_id] = OBJECT_ID(N'[mail].[activity]')
           AND [definition] LIKE N'%followup_linked%'
    )
    BEGIN
        IF EXISTS (SELECT 1 FROM sys.check_constraints WHERE [name] = N'CK_mail_activity_kind' AND [parent_object_id] = OBJECT_ID(N'[mail].[activity]'))
        BEGIN
            ALTER TABLE [mail].[activity] DROP CONSTRAINT [CK_mail_activity_kind];
        END;
        ALTER TABLE [mail].[activity] WITH CHECK
            ADD CONSTRAINT [CK_mail_activity_kind]
            CHECK ([kind] IN (N'received', N'classified', N'skipped', N'label_confirmed', N'label_corrected', N'draft_created', N'ignored', N'followup_linked'));
    END;

    -- 3. the draft index becomes filtered; the follow-up index is new
    IF EXISTS (
        SELECT 1 FROM sys.indexes
         WHERE [name] = N'UX_mail_message_links_draft'
           AND [object_id] = OBJECT_ID(N'[mail].[message_links]')
           AND [has_filter] = 0
    )
    BEGIN
        DROP INDEX [UX_mail_message_links_draft] ON [mail].[message_links];
    END;

    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE [name] = N'UX_mail_message_links_draft' AND [object_id] = OBJECT_ID(N'[mail].[message_links]'))
    BEGIN
        CREATE UNIQUE INDEX [UX_mail_message_links_draft]
            ON [mail].[message_links] ([thread_id], [rfq_public_id])
            WHERE [kind] = N'rfq_draft';
    END;

    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE [name] = N'UX_mail_message_links_followup' AND [object_id] = OBJECT_ID(N'[mail].[message_links]'))
    BEGIN
        CREATE UNIQUE INDEX [UX_mail_message_links_followup]
            ON [mail].[message_links] ([message_id])
            WHERE [kind] = N'rfq_followup';
    END;

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
GO

SET NOEXEC OFF;
GO

SELECT
    (SELECT COUNT(*) FROM sys.check_constraints
      WHERE [name] IN (N'CK_mail_message_links_kind', N'CK_mail_activity_kind')
        AND ([definition] LIKE N'%rfq_followup%' OR [definition] LIKE N'%followup_linked%')) AS [FollowupConstraints],
    (SELECT COUNT(*) FROM sys.indexes
      WHERE [name] IN (N'UX_mail_message_links_draft', N'UX_mail_message_links_followup')
        AND [object_id] = OBJECT_ID(N'[mail].[message_links]')
        AND [has_filter] = 1) AS [FilteredIndexes];
GO
