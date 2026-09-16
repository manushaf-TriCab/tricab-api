# Log Notes — Phase 1

Customer-centric **Log Notes** module. User-facing name: Log Notes. Domain: `LogNote`. Permissions: `CUSTOMER_NOTE.*`.

## 4JS

**4JS sync intentionally disabled pending explicit integration approval.**

`CustomerNoteExternalSync` exists as an integration boundary. Phase 1 implementation is `DisabledCustomerNoteExternalSync` and `CreateLogNoteService` does not invoke it. Saving a Log Note succeeds independently of 4JS.

## AI summarise

`POST /api/v1/log-notes/summarise` returns a summarised body. It does not persist. The user reviews/edits, then `POST /api/v1/log-notes` with `isAiGenerated: true` when AI was used.

Provider: Azure Foundry when configured, otherwise a deterministic fallback (`Summary: …`).

## Dictation

Dictation is a client concern. Web uses the existing Web Speech API hook. Mobile reuses `expo-speech-recognition`. No new native packages. AI summarisation does not depend on dictation.

## Migration

`LegacyCustCallId` is reserved. Legacy data migration is not run by schema deployment.

