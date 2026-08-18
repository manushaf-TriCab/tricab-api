# Phase 9 constructor signature changes (quick ref)

See full wiring checklist: [product-phase9-NOTES-for-api.md](./product-phase9-NOTES-for-api.md)

## `ProductMediaSummary`
Added optional (defaults): `uploadStatusCode='active'`, `scanStatusCode='not_required'`, `byteSize`, `contentType`, `originalFileName`, `widthPx`, `heightPx`, `thumbnailUrl`, `storagePath`.

## `ProductDocumentSummary`
Added optional (defaults): `uploadStatusCode='active'`, `scanStatusCode='not_required'`, `byteSize`, `contentType`, `originalFileName`, `fileExtension`, `storagePath`.

Existing 8-argument call sites remain source-compatible. tricab-api serializers should populate the new fields where available.
