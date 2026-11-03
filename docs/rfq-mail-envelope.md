# `rfq-mail-envelope.v1`: one email, as the RFQ parser reads it

Platform contract for RFQ mail intake (plan `platform/plans/RFQ-MAIL-INTAKE-PLAN.md`, M2). The PHP mail reader
(M3/M6) builds an envelope from a Microsoft Graph message and sends it to the RFQ parser as the `email` input of
`POST /v1/parse` (contract `rfq-parser.v1`). The parser reads the body and every attachment in one pass and answers
with the quote lines, the source accounting and what it learned about the message.

- Python: `core/python/rfq_parser/mail_envelope.py` (contract, validator, normalisation),
  `mail_parse.py` (body to work items, merging), `mail_ingest.py` (the source's mail reading, generated),
  `contract.py` (`_parse_email`).
- JSON Schema: `core/python/rfq_parser/data/rfq-mail-envelope.v1.schema.json`.
- PHP: `ParseRequest::forEmail()`, `RfqParserClient::parseEmail()`, `ParseResponse::$email`.
- Synthetic fixtures (invented people, `example.com`): `core/python/tests/rfq_parser/fixtures/mail/*.json`.

## What a producer sends (the raw envelope)

Field names are camelCase, like the rest of `rfq-parser.v1`. Send the Graph values as they are; do **not** convert
the body to text, split the forwarded chain or find the sender in PHP. The parser does that with the same code as the
old RFQ application, so the two agree.

| Field | Type | Required | From Graph (`GET /users/{mailbox}/messages/{id}`) |
|---|---|---|---|
| `contract` | `"rfq-mail-envelope.v1"` | yes | (constant; `ParseRequest::forEmail` adds it) |
| `internetMessageId` | string, 1 to 998 | yes | `internetMessageId`, angle brackets optional. The dedupe key. |
| `messageId` | string or null | no | `id` (use `Prefer: IdType="ImmutableId"`) |
| `conversationId` | string or null | no | `conversationId` |
| `receivedAt` | ISO 8601 string with a zone, or null | no | `receivedDateTime` |
| `mailbox` | string or null | no | the mailbox being read, e.g. `melbourne@tricab.com`. Without it the parser cannot recognise mail the mailbox sent itself. Send it. |
| `subject` | string | no (default `""`) | `subject` |
| `sender` | `{name, address}` | yes | `from.emailAddress` (not `sender`, which is the delegate) |
| `toRecipients` | list of `{name, address}` | no | `toRecipients[].emailAddress` |
| `ccRecipients` | list of `{name, address}` | no | `ccRecipients[].emailAddress` |
| `body` | `{contentType, content}` | yes | `body`. `contentType` is `html` or `text` (Graph's `"html"`/`"text"`, lower-case), `content` the string untouched. |
| `documents` | list | no (default `[]`) | the message's **file** attachments, in the shape below |

`address` is the only required key of an address object. Unknown fields anywhere are **refused** (a misspelt key
would otherwise lose data silently); the answer names the field, never the content.

### `documents[]`

The existing `/v1/parse` `documents[]` shape, plus two optional fields:

| Field | Type | Notes |
|---|---|---|
| `documentPublicId` | string | PHP's id for the attachment. Unique within the envelope. Echoed back in the answer's `documents[]`. |
| `fileName` | string | Graph `name`. Display metadata; the extension picks the reader. |
| `contentType` | string | Graph `contentType` (may be `application/octet-stream`; the bytes decide). |
| `contentBase64` | string | Graph `contentBytes`. |
| `isInline` | bool (default `false`) | Graph `isInline`. Inline images are pasted pictures or decoration; see below. |
| `contentId` | string, optional | Graph `contentId`. |

Send **file attachments only** (`@odata.type` ends `fileAttachment`). Skip item and reference attachments.
Inline images go in `documents[]` with `isInline: true`; do not try to decide which are decoration.

### PHP

```php
$response = $parser->parseEmail($correlationId, [
    'internetMessageId' => $message['internetMessageId'],
    'messageId'         => $message['id'],
    'conversationId'    => $message['conversationId'],
    'receivedAt'        => $message['receivedDateTime'],
    'mailbox'           => 'melbourne@tricab.com',
    'subject'           => $message['subject'],
    'sender'            => ['name' => $from['name'], 'address' => $from['address']],
    'toRecipients'      => $to,    // [['name' => ..., 'address' => ...], ...]
    'ccRecipients'      => $cc,
    'body'              => ['contentType' => $message['body']['contentType'], 'content' => $message['body']['content']],
    'documents'         => [       // `bytes` is the raw file; forEmail() encodes it
        ['documentPublicId' => $id, 'fileName' => $name, 'contentType' => $type, 'bytes' => $bytes, 'isInline' => false],
    ],
], allowModel: false);
```

`RfqParserClient::parseEmail(CorrelationId $correlationId, array $envelope, bool $allowModel): ParseResponse` throws a
typed `RfqParserFailure` like every other call. `ParseRequest::forEmail` refuses only what is structurally unusable;
the parser is the authority on the rest.

**Limits** (the document limits, per attachment): at most 10 attachments worth reading, 20 MB per document, 8 MB per
image. PHP enforces the sizes before sending, and a total of 17 MB of attachments per email (the parser's request body
is capped at 25 MB, and base64 adds a third). The envelope may carry up to 40 `documents` because decorative inline
images are filtered before the count of 10 is applied. **If a message does not fit, the reader omits the attachment
itself and records that it did**: an attachment dropped without a trace is a file nobody read. Provider calls are off
unless `allowModel` is true, as for every parse.

## What the parser adds (the normalised envelope)

`mail_envelope.normalise()` returns the envelope with these derived fields. They are accepted on input but always
recomputed, never trusted. The answer to `/v1/parse` carries the useful ones under `email`.

| Field | Content |
|---|---|
| `body.text` | Clean text: HTML flattened as the old application flattens it, each top-level table kept as one line per row with cells joined by ` | `, in place. For people and the classifier (M4). |
| `body.tables` | Every table in the HTML body as `rows[cells[string]]`, row and column spans expanded. |
| `forwardedChain.parts[]` | The body split into the message and each message it forwards or quotes: `{index, kind: "top"\|"forwarded", from: {name,address}\|null, sentAt, to, subject, text}`. Part 0 is what the sender wrote (`from` is the envelope sender). Later parts open at a forwarded-message marker, an Outlook header block or an "On ... wrote:" line. `sentAt`, `to` and `subject` are the header's own strings. |
| `forwardedChain.originalSender` | `{name, address}` or null: the customer. The old application's rule: the first external `From:` line, else the first external address in the body, else a typed `EMAIL:` label. "External" means not `@tricab.com`. |
| `dedupeKey` | `internetMessageId` without angle brackets, lower-cased (`messageId` if there is none). The key for the audit/dedupe store (M3.3). |

## What `/v1/parse` does with it

Send exactly one of `text`, `documents`, `email`.

1. **Loop guards.** A message that is the parser's own mail is refused with `invalid_request` (HTTP 422) and a message
   naming why: `notification` (an assignment notice or an answer to one, subject tag `[RFQ]`), `rfq_reply` (our draft
   reply, tag `[RFQ-Reply]`) or `self_sent` (the sender is `mailbox`). These are the old application's `is_own_mail`.
   Auto-replies, bounces, bulk and calendar mail are **not** recognised here: that pre-filter is M3.4 and uses headers
   the envelope does not carry.
2. **Body.** Read as the old application reads it: HTML table rows become lines from their cells; the text with the
   tables removed goes through the line reader; a layout table that yields nothing leaves the text to be read whole.
   Each text line says which chain part it came from (`lines[].source.chain_part`), when that can be told exactly:
   a reply carries the quoted earlier message, and its lines are read again. Lines have `source.file_name = "email body"`.
3. **Attachments.** Each goes through the same reader a plain `documents[]` request uses. Inline images that look
   like decoration (a logo or signature: under 25 KB, or a name like `outlook-*` / `*.gif`) are ignored and listed;
   the rest are pasted schedules and are images for the reader (`documents[].readerRoute`, `inline: true`).
   `RFQ_PROCESS_INLINE_IMAGES=0` turns inline images off; `RFQ_MIN_INLINE_IMAGE_BYTES` moves the floor.
   An attachment of an unsupported type (`.p7s`, `.ics`, a zip) is left out and listed in `email.skippedAttachments`.
   A supported one that cannot be read (too large, corrupt, out of time) is left out, noted and counted as an
   **unaccounted** input, so the parse reads `partial`, never complete. The body is still parsed.
4. **Merge.** Attachments are read first and win. A body line is dropped when an attachment already has it (the same
   product at the same total length, or the same text). A later attachment loses lines only when it is a copy of
   earlier ones (90% of its lines repeat). Identical lines inside one source are all kept. Each dropped line moves
   from `kept` to `skipped` in the coverage, with the reason, so the counts still add up.
5. **Accounting.** Every body work item is one input row of the file `email body`. Body text the line reader could not
   account for (not a quote line, not a greeting, header, signature or disclaimer) is counted as skipped rows with
   the reason `email text that was not read as a quote line`, and the first 50 are listed in `email.body.residue`.
   An email with no lines at all (not an RFQ) is a complete parse of zero lines: deciding that is the classifier's job.

### The answer

The ordinary `rfq-parser.v1` parse answer (`lines`, `coverage`, `engine`, `provider`, `degraded`, `notes`,
`documents`, `elapsedMs`) plus:

```json
"email": {
  "dedupeKey": "abc@mail.example.com",
  "originalSender": {"name": "Jane Doe", "address": "jane.doe@customer-example.com"},
  "forwardedParts": 1,
  "customerLabels": {"customer": "...", "customer_ref": "...", "delivery_address": "..."},
  "body": {"tableCount": 1, "lineCount": 3, "residueCount": 0, "residue": []},
  "ignoredInline": ["image002.png"],
  "skippedAttachments": [{"fileName": "smime.p7s", "reason": "..."}],
  "unreadableAttachments": 0,
  "duplicatesDropped": 0
}
```

`ParseResponse::$email` holds it (empty for text and documents parses). `customerLabels` are the rep's typed
`Customer:` / `Customer Code:` / `Contact:` / `Email:` / `Customer Ref:` / `Delivery Address:` template lines
(`CREATE QUOTE`); resolving them to a customer is the API's.

## Who builds it: the draft path (3 Nov)

`RfqEmailDraftCreator` (api `src/Mail/Drafts`) builds the envelope when a draft is made, behind
`RFQ_MAIL_DRAFTS_ENABLED` (with `RFQ_MAIL_MODE=live`), for the reader's own `rfq` mail and the inbox's "Create draft".
It asks Exchange now, with **GET only** (`MailBodyFetcher::envelopeSource()`: the message with its body and both
recipient lists, then the attachment list; then each attachment it will send). Nothing is marked read, moved or changed,
and nothing from the body is kept in `[mail]`.

- `body` is Graph's, untouched; the parser flattens it and splits the chain. `sender` is the Graph `from`.
- Files first, then inline images, at most 10 documents in all (the parser's per-parse limit), 20 MB per document,
  8 MB per image, 17 MB in total. What does not fit is left off and written to the draft's
  `email_meta.attachments_not_read`.
- Inline images under 25 KB (`RFQ_MIN_INLINE_IMAGE_BYTES`) and every `.gif` are not downloaded: they are decoration.
  A format the parser cannot read (zip, `.p7s`, `.ics`) is not downloaded either.
- The draft still keeps the body as `email-body.txt` and the attachments as source documents, but the parser is
  given **one** email input, not those files. `documentPublicId` is `email-N` (N = the file's position, the body
  being `email-1`), so a reader route in the answer names the stored file.
- If the parser refuses the attachments (not an outage), the draft is made again from the body alone and the refusal is
  noted. If it refuses the body (the parser's own mail) no draft is made.
- The answer's `email` block (original sender, forwarded parts, `Customer:` labels, skipped attachments) is kept in
  `email_meta.parse.email`.

## Not in M2

The audit and dedupe store, the pre-filter (M3); the model read of an email body (a reader job, `/v1/read`); the
subject-keyword import of a TriCab quote (ALIFLEX / REPRICE). See `core/python/rfq_parser/_unported.py`.
