/*
================================================================================
  RFQ documents: the uploaded files an RFQ was read from (plan 2.5 / 3.3).

  STATUS: NOT APPLIED to any shared database. Do not run against tricab-mel or
  production without the owner's explicit approval. Rerunnable: it creates
  what is missing and changes nothing that exists.

  A platform addition beside the live MySQL shape (architecture doc §6.5): the
  source kept uploads as files in a spool directory and never recorded them,
  so there is no source table to follow. One row per stored file; the bytes
  are in the private blob container named on the row, under a generated key.

    - `id` is a 32-hex id like every other RFQ table; its UUID form is the
      document's public id (`rfq.document.v1`). The blob path is never sent
      to a browser.
    - `file_name` is display metadata only, as uploaded; it is never a path.
    - `sha256` identifies the content (duplicate uploads, migration checks).
    - `deleted_at` marks bytes removed under a retention policy; the row stays
      so the draft's history still names the file.

  Reader jobs name their document in `rfq_reader_jobs.spool_path` as
  `rfq-document:<id>`. The source's jobs hold a filesystem path there, and the
  platform worker claims only its own prefix.
================================================================================
*/

IF SCHEMA_ID(N'rfq') IS NULL
    EXEC(N'CREATE SCHEMA [rfq]');
GO

IF OBJECT_ID(N'[rfq].[rfq_documents]', N'U') IS NULL
BEGIN
    CREATE TABLE [rfq].[rfq_documents] (
        [id]              CHAR(32)       NOT NULL,
        [draft_id]        CHAR(32)       NOT NULL,
        [created_at]      NVARCHAR(40)   NOT NULL,
        [uploader_email]  NVARCHAR(255)  NULL,
        [file_name]       NVARCHAR(512)  NOT NULL,
        [content_type]    NVARCHAR(128)  NULL,
        [kind]            NVARCHAR(16)   NOT NULL,
        [byte_size]       INT            NOT NULL,
        [sha256]          CHAR(64)       NOT NULL,
        [blob_container]  NVARCHAR(64)   NOT NULL,
        [blob_path]       NVARCHAR(512)  NOT NULL,
        [deleted_at]      NVARCHAR(40)   NULL,
        CONSTRAINT [PK_rfq_documents] PRIMARY KEY CLUSTERED ([id]),
        CONSTRAINT [FK_rfq_documents_draft] FOREIGN KEY ([draft_id])
            REFERENCES [rfq].[rfq_draft_quotes] ([id]) ON DELETE CASCADE
    );
    CREATE NONCLUSTERED INDEX [rfq_documents_draft] ON [rfq].[rfq_documents]([draft_id], [created_at]);
    CREATE NONCLUSTERED INDEX [rfq_documents_sha256] ON [rfq].[rfq_documents]([sha256]);
END;
GO
