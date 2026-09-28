# Generated from the RFQ parser's matcher/quote_readiness.py by tools/sync_rfq_parser.py.
# Change the source or the tool's declared patches, never this file.
"""Pure quote preflight shared by persistence and 4js submission.

This module performs no I/O. Inclusion, numeric fields and review eligibility have
one definition; unknown products/technical choices are still resolved upstream.
"""
from __future__ import annotations

import math

REGION_PROFILE = {
    'australia': {'debtor': 'TCA', 'currency': 'AUD'},
    'newzealand': {'debtor': 'TCNZ', 'currency': 'NZD'},
    'usa': {'debtor': 'TCUS', 'currency': 'USD'},
    'asia': {'debtor': 'TCASI', 'currency': 'USD'},
}
SUPPORTED_CURRENCIES = ('AUD', 'NZD', 'USD', 'SGD')
_REGION_ALIASES = {
    'au': 'australia', 'aus': 'australia', 'australia': 'australia',
    'nz': 'newzealand', 'newzealand': 'newzealand', 'new zealand': 'newzealand',
    'us': 'usa', 'usa': 'usa', 'america': 'usa', 'asia': 'asia', 'asi': 'asia',
}
REQUIRED_HEADER_FIELDS = ('customer_id', 'contact', 'author', 'salesoffice', 'payterms', 'region')


# --- what a warning on a row actually means --------------------------------------
#
# These prefixes were defined in `matcher/pipeline.py` and copied as string literals into
# `static/app.js`, under a comment reading "change both or neither". That is the drift
# this project keeps paying for, and it is what Batch 2's "structured technical/quantity
# contradiction codes to replace reliance on parser status" is about: the *server*
# classifies, once, and says so in a code the browser can switch on.
#
# They live here rather than in pipeline because this module is the one both the push
# path and the editor already share, and it imports nothing. pipeline imports them back.

#: Quantity moved by a rule about how the cable is supplied. Cleared and recomputed when
#: a line is re-priced, because they describe the current quantity.
MAKE_MOQ_WARNING_PREFIXES = (
    'MAKE cable quantity rounded to MOQ multiple:',
    'MAKE cable priced at MOQ:',
)
PREPACK_WARNING_PREFIXES = ('Prepack cable quoted as whole spools:',)
QUANTITY_WARNING_PREFIXES = MAKE_MOQ_WARNING_PREFIXES + PREPACK_WARNING_PREFIXES

#: The attachment reader's two §4 flags. Deliberately NOT in QUANTITY_WARNING_PREFIXES:
#: that list is cleared when a quantity is recomputed, and a count read out of the row's
#: prose does not stop being an inference because somebody re-priced the line.
COUNT_INFERENCE_WARNING_PREFIX = "Quantity multiplied by a count read from the row's text:"
DOUBLE_COUNT_WARNING_PREFIX = 'Two line items on this row claim the same cell:'
READER_INFERENCE_WARNING_PREFIXES = (COUNT_INFERENCE_WARNING_PREFIX,
                                     DOUBLE_COUNT_WARNING_PREFIX)

#: prefix -> (code, severity). `note` is a routine default the rep is told about and does
#: not have to act on; `fix_required` is a contradiction that blocks the sync.
#:
#: The two reader flags are `fix_required` on purpose. A count multiplied out of prose,
#: and two line items claiming one cell, are both quantity claims nothing re-checked --
#: `attachment_validate` can re-open a cell and cannot re-derive an inference about it --
#: and a wrong quantity is the most expensive error this system makes.
WARNING_CODES = (
    ("Quantity basis unclear:", "quantity_basis_unclear", "fix_required"),
    (MAKE_MOQ_WARNING_PREFIXES[0], 'quantity_rounded_to_moq', 'note'),
    (MAKE_MOQ_WARNING_PREFIXES[1], 'quantity_priced_at_moq', 'note'),
    (PREPACK_WARNING_PREFIXES[0], 'quantity_prepack_spools', 'note'),
    (COUNT_INFERENCE_WARNING_PREFIX, 'quantity_count_inferred', 'fix_required'),
    (DOUBLE_COUNT_WARNING_PREFIX, 'quantity_double_counted', 'fix_required'),
)

#: Known resolver statuses, translated into sales-facing reasons.
#: needs_clarification is advisory; unresolved technical choices still block sync.
STATUS_CODES = {
    'needs_clarification': ('technical_needs_clarification',
                            'Check the suggested product against the request. This check does not block syncing.'),
    'unresolved': ('technical_unresolved',
                   'The parser could not decide what this line is. Choose a product.'),
    'needs_review': ('technical_needs_review',
                     'The parser is unsure about this line. Confirm or change the product.'),
    'ambiguous': ('technical_ambiguous',
                  'More than one product fits this line. Choose one.'),
    'no_match': ('technical_no_match',
                 'Nothing in the catalog matches this line. Choose a product.'),
    'accessory': ('accessory_review', 'Accessories are quoted for review, not matched.'),
    'not_cable': ('not_cable', 'This line does not describe a cable.'),
    'admin': ('non_commercial', 'Heading or note text, not a line to quote.'),
}


def warning_code(note):
    """`(code, severity)` for one warning string, or None if it is just prose.

    Prefix-matched, because that is how these warnings are written -- the prefix is the
    class and the tail is the numbers. Matching here, once, is the point: the browser
    switches on the code instead of re-implementing the prefix list.
    """
    text = str(note or '')
    for prefix, code, severity in WARNING_CODES:
        if text.startswith(prefix):
            return code, severity
    return None


#: Preflight issue keys that `row_contradictions` already emits a code for. Anything
#: outside this set becomes `blocked_other`, so a blocking rule added later is visible
#: to a browser reading codes instead of silently absent from it.
ACCOUNTED_ISSUES = frozenset({
    'missing_product', 'unresolved_line', 'invalid_quantity_or_price', 'pricing_pending',
})


# --- what the sales view paints ---------------------------------------------------
#
# `severity` above answers "does this block the sync". That is not the same question as
# "what colour is this row", and the two were being conflated: `row_contradictions`
# marks `quantity_count_inferred` as `fix_required` while a row's `state` is derived
# only from quote-level blockers, so a line could show a `Note` pill directly above a
# red "Fix required" box. One value has to decide both, and it has to be computed here
# rather than in the browser, or the pill and the tint go on disagreeing.
#
# Measured on the 18 rows of reports/go-live/draft-proof.json: every row was `note`,
# and 119 of the 123 coded reasons were `assumed_field`. Painting from `state` paints
# every row, which is the same as painting none -- so an assumption must not contribute
# a colour at all. A row whose only reasons are assumptions is plain and reads Ready.

#: Amber. The quantity is not what was asked for, or nothing re-checked the line against
#: its source. The rep has to look, but the sync is not blocked.
SALES_CHECK_CODES = frozenset({
    'technical_needs_clarification',
    'quantity_count_inferred', 'quantity_double_counted', 'quantity_prepack_spools',
    'source_ungrounded',
})

#: `fix_required` here, never a colour on a sales row. The sales view hides cost, margin
#: and sell price by decision (docs/SIMPLE_VIEW_HANDOFF.md §2), so the rep has no control
#: that clears either of these -- flagging one queues work nobody in that view can do.
#: The same reasoning already keeps price-only warnings out of the browser's `needsYou`.
SALES_SILENT_CODES = frozenset({'pricing_pending', 'price_invalid'})

#: Worst-first, so a row takes the strongest colour any one of its reasons earns.
SALES_DISPLAY_RANK = {'fix_required': 2, 'check': 1, 'note': 0}


def sales_display(code, severity):
    """How one reason paints in the sales view: `fix_required`, `check` or `note`.

    Ordered deliberately. A code in `SALES_CHECK_CODES` is amber whatever its blocking
    severity says -- the two reader flags are `fix_required` because a wrong quantity is
    the most expensive error this system makes, and they are still amber on screen
    because the rep's job there is to check a number, not to unblock a sync.
    """
    if code in SALES_CHECK_CODES:
        return 'check'
    if code in SALES_SILENT_CODES:
        return 'note'
    return 'fix_required' if severity == 'fix_required' else 'note'


def sales_severity(codes):
    """One row's colour: `fix_required`, `check` or `ready`.

    `ready` rather than `note`, because at row level the absence of anything to act on
    is the whole statement -- and any number of assumed fields leaves a row there.
    """
    worst = 'note'
    for entry in codes or ():
        display = entry.get('display') or sales_display(entry.get('code'), entry.get('severity'))
        if SALES_DISPLAY_RANK.get(display, 0) > SALES_DISPLAY_RANK[worst]:
            worst = display
    return 'ready' if worst == 'note' else worst


def row_contradictions(row, issues):
    """Structured reasons this row is not ready, and structured notes about it.

    Returns a list of `{code, severity, detail}`, most severe first. `severity` is
    `fix_required` (a contradiction: the sync is blocked) or `note` (a routine default
    the rep is told about and need not act on).

    This replaces reading the parser's status string in the browser. A status is the
    parser's own vocabulary and it has grown terms over time; a code is a contract, and
    an unrecognised one degrades to "there is something to fix here" rather than to
    "looks fine", which is the direction that matters.

    `issues` is this row's entries from the preflight, as dicts. Their `issue` keys are
    what decides whether a blocker is already accounted for -- matched on the key rather
    than on the prose, because the prose is written for a rep and gets reworded.
    """
    codes = []
    issue_keys = {str(issue.get('issue') or '') for issue in issues}
    if 'pricing_pending' in issue_keys:
        codes.append({'code': 'pricing_pending', 'severity': 'fix_required',
                      'detail': 'Wait for pricing to finish before syncing.'})
    status = str(row.get('final_status') or row.get('status') or '').strip().lower()

    if not product_code(row):
        codes.append({'code': 'product_unresolved', 'severity': 'fix_required',
                      'detail': 'Choose a product code for this line.'})
    if status in STATUS_CODES:
        code, detail = STATUS_CODES[status]
        severity = 'note' if status in {'not_cable', 'admin', 'needs_clarification'} else 'fix_required'
        codes.append({'code': code, 'severity': severity, 'detail': detail})
    elif status and status not in {'matched', 'approved'}:
        # An unknown status is a contradiction we have no name for, not an absence of one.
        codes.append({'code': 'technical_unknown_status', 'severity': 'fix_required',
                      'detail': f'The parser reported an unrecognised state ({status}). '
                                'Confirm the product before syncing.'})

    if quote_quantity(row) is None:
        codes.append({'code': 'quantity_invalid', 'severity': 'fix_required',
                      'detail': 'This line needs a length greater than 0.'})
    if reviewed_price(row) is None:
        codes.append({'code': 'price_invalid', 'severity': 'fix_required',
                      'detail': 'This line needs a price greater than 0.'})

    source_type = (row.get('source') or {}).get('input_type')
    if source_type in {'vision_ocr', 'reader_ungrounded'}:
        codes.append({'code': 'source_ungrounded', 'severity': 'note',
                      'detail': 'Read from an image or scan; nothing re-checked it '
                                'against the source. Compare this line with the document.'})

    for note in row.get('warnings', []) or []:
        matched = warning_code(note)
        if matched:
            codes.append({'code': matched[0], 'severity': matched[1], 'detail': str(note)})
    for field in row.get('inferred_fields', []) or []:
        codes.append({'code': 'assumed_field', 'severity': 'note',
                      'detail': f'Assumed {field}'})

    # Anything the preflight blocked on that no code above accounts for. Without this a
    # new blocking rule would be invisible to a browser that has switched to codes.
    for issue in issues:
        if str(issue.get('issue') or '') not in ACCOUNTED_ISSUES:
            codes.append({'code': 'blocked_other', 'severity': 'fix_required',
                          'detail': str(issue.get('reason') or 'This line cannot be synced.')})

    # `display` rides on every entry so the browser never re-derives it: the sales view
    # shows a `fix_required`/`check` reason inline and collapses a `note`, and it must
    # make that cut on the same rule that decided the row's colour.
    for entry in codes:
        entry['display'] = sales_display(entry['code'], entry['severity'])
    # Worst first, by how it reads on screen rather than by blocking severity -- an amber
    # reason the rep must act on should not sort below a silent `price_invalid`.
    codes.sort(key=lambda entry: -SALES_DISPLAY_RANK.get(entry['display'], 0))
    # One entry per (code, detail): the same warning can arrive twice on a re-priced row.
    seen, unique = set(), []
    for entry in codes:
        key = (entry['code'], entry['detail'])
        if key not in seen:
            seen.add(key)
            unique.append(entry)
    return unique


def region_profile(region):
    return REGION_PROFILE.get(_REGION_ALIASES.get(str(region or '').strip().lower()))


def normalize_currency(currency, region=None):
    value = str(currency or '').strip().upper()
    if value:
        return value if value in SUPPORTED_CURRENCIES else None
    profile = region_profile(region)
    return profile['currency'] if profile else None


def positive_number(value):
    if isinstance(value, bool):
        return None
    try:
        number = float(value)
    except (TypeError, ValueError, OverflowError):
        return None
    return number if math.isfinite(number) and number > 0 else None


def first_present(*values):
    # Zero/negative/NaN are answers to validate, never permission to try a stale alias.
    return next((value for value in values if value is not None and value != ''), None)


def product_code(row):
    # A deliberately cleared final code must not resurrect the selected code.
    value = row['final_product_code'] if 'final_product_code' in row else row.get('selected_product_code')
    return str(value or '').strip()


def quote_quantity(row):
    length = row.get('length') or {}
    cuts = positive_number(length.get('number_of_lengths'))
    canonical_each = positive_number(length.get('length_each_m'))
    canonical_total = positive_number(length.get('total_length_m'))
    editable_each = positive_number(length.get('length_each'))
    # Defensive counterpart to the browser repair: a stale draft may contain the
    # already-totalled metres as its editable per-cut value, producing N x (N x L).
    # The parser's canonical aliases retain the original N x L and are safe to use
    # only when they agree with each other and show this exact corruption pattern.
    if (cuts and cuts > 1 and canonical_each and canonical_total and editable_each):
        if canonical_each * cuts == canonical_total and editable_each == canonical_total:
            return canonical_total
    return positive_number(first_present(length.get('total_length'), length.get('total_length_m'), row.get('total_length')))


def reviewed_price(row):
    pricing = row.get('pricing') or {}
    return positive_number(first_present(row.get('final_unit_price'), row.get('final_price'),
                                         pricing.get('sell_price'), row.get('unit_price')))


def exclusion_reason(row):
    if row.get('directive_excluded'):
        reason = str(row.get('directive_exclusion_reason') or '').strip()
        return 'excluded by a directive' + (f': {reason}' if reason else '')
    if row.get('disposition') == 'excluded':
        return str(row.get('exclusion_reason') or 'Intentionally excluded from this quote')
    # Headings/noise without a product are not commercial requirements. An unresolved
    # requested item is NEVER in this set, and a contradictory coded heading is blocked.
    status = str(row.get('final_status') or row.get('status') or '').strip().lower()
    if status in {'admin', 'not_cable'} and not product_code(row):
        return 'Non-commercial source content'
    return None


def row_push_block_reason(row, *, reviewed=False):
    excluded = exclusion_reason(row)
    if excluded:
        return excluded
    status = str(row.get('final_status') or row.get('status') or '').strip().lower()
    source = row.get('source') or {}
    if not reviewed and source.get('input_type') == 'vision_ocr' and status != 'approved':
        return 'vision OCR row needs approval before syncing'
    if not reviewed and source.get('input_type') == 'reader_ungrounded' and status != 'approved':
        return 'read from a picture or scan; needs approval before syncing'
    if status not in {'', 'matched', 'approved', 'needs_clarification'}:
        return f'status is {status}; resolve the line before syncing'
    return None


def row_reference(row, index):
    return {'row_number': index + 1, 'row_id': row.get('_id') or row.get('line_uid') or row.get('_draft_row_id'),
            'code': product_code(row)}


def reader_state_issues(reader_state):
    """Blocking issues from the state of this draft's background document reads.

    A read that is queued, running, failed or still offering a proposal all mean the same
    thing about a sync: the rows in front of the rep are not yet the rows this RFQ has.

    `acquire_sync_lock` already refuses while a read is queued or running, and that stays
    the backstop -- it holds a row lock, so it is the only check that cannot be raced.
    What it does not cover is the other two, and they are the quiet ones:

    - **A failed read** means a document the rep attached produced no lines and nobody
      knows what was in it. Syncing quotes the RFQ *without* that file, silently, and the
      only sign is a note on a panel. `_fail` puts the reason on the job; this makes it
      stop the sync rather than decorate it.
    - **An outstanding proposal** means terra re-read a file and is offering replacement
      rows the rep has neither accepted nor dismissed. `apply_reader_rows` deliberately
      never merges -- two rows for one requirement is an invisible double count -- so
      syncing while a proposal stands quotes rows the machine has already superseded.

    Pure, like everything else here: the caller reads the jobs and passes a summary.
    `None` means "not known", which is not the same as "nothing pending" and so blocks
    nothing -- the email worker has no reader jobs of its own to consider.
    """
    if not reader_state:
        return []
    issues = []
    in_progress = int(reader_state.get('queued') or 0) + int(reader_state.get('running') or 0)
    if in_progress:
        issues.append({
            'issue': 'reads_in_progress',
            'reason': (f'{in_progress} document(s) are still being read. Wait for the '
                       'resulting lines before syncing.'),
        })
    for failed in reader_state.get('failed') or []:
        name = str(failed.get('file_name') or 'a document')
        note = str(failed.get('note') or '').strip()
        issues.append({
            'issue': 'read_failed',
            'file_name': name,
            'reason': (f'{name} could not be read'
                       + (f': {note}' if note else '')
                       + '. Add its lines by hand, or remove the file, before syncing.'),
        })
    for pending in reader_state.get('proposals') or []:
        name = str(pending.get('file_name') or 'a document')
        issues.append({
            'issue': 'read_proposal_pending',
            'file_name': name,
            'reason': (f'A re-read of {name} is offering replacement lines. Accept or '
                       'dismiss it before syncing.'),
        })
    return issues


def quote_readiness(rows, quote_details, *, reviewed=False, reader_state=None):
    details = quote_details or {}
    issues, excluded = [], []
    included_count = 0
    # Reader state first: it is about the whole quote, not a row, and it is the reason a
    # quote that looks complete may not be.
    issues.extend(reader_state_issues(reader_state))
    for field in REQUIRED_HEADER_FIELDS:
        if not str(details.get(field) or '').strip():
            issues.append({'issue': 'missing_header', 'field': field, 'reason': f'Missing required field: {field}'})
    if details.get('region') and not region_profile(details['region']):
        issues.append({'issue': 'invalid_region', 'field': 'region', 'reason': f"Unknown region: {details['region']}"})
    if details.get('currency') and not normalize_currency(details['currency'], details.get('region')):
        issues.append({'issue': 'invalid_currency', 'field': 'currency', 'reason': f"Unsupported currency: {details['currency']}"})
    for index, row in enumerate(rows or []):
        ref = row_reference(row, index)
        reason = exclusion_reason(row)
        if reason:
            excluded.append({**ref, 'reason': reason})
            continue
        included_count += 1
        if not product_code(row):
            issues.append({**ref, 'issue': 'missing_product', 'reason': 'Choose a product code for this line.'})
        reason = row_push_block_reason(row, reviewed=reviewed)
        if reason:
            issues.append({**ref, 'issue': 'unresolved_line', 'reason': reason})
        quantity, price = quote_quantity(row), reviewed_price(row)
        # These values are formatted to five decimals for 4js. Reject values that
        # become zero on the wire as well as NaN/Infinity and ordinary invalid input.
        if quantity is None or price is None or float(f'{quantity:.5f}') <= 0 or float(f'{price:.5f}') <= 0:
            issues.append({**ref, 'issue': 'invalid_quantity_or_price',
                           'reason': 'quantity and price must both be greater than 0 (check length and pricing)'})
        if (row.get('pricing') or {}).get('status') in {'pending', 'loading'}:
            issues.append({**ref, 'issue': 'pricing_pending', 'reason': 'Wait for pricing to finish before syncing.'})
    if not included_count:
        issues.append({'issue': 'empty_quote', 'reason': 'No included line items to sync.'})
    states = []
    for index, row in enumerate(rows or []):
        ref = row_reference(row, index)
        row_issues = [issue for issue in issues if issue.get('row_number') == index + 1]
        blockers = [issue['reason'] for issue in row_issues]
        notes = list(dict.fromkeys(str(note) for note in row.get('warnings', []) if note))
        notes += [f"Assumed {field}" for field in row.get('inferred_fields', [])]
        if (row.get('source') or {}).get('input_type') in {'vision_ocr', 'reader_ungrounded'}:
            notes.append('Read from an image or scan; compare this line with the source.')
        excluded_reason = exclusion_reason(row)
        state = 'excluded' if excluded_reason else 'fix_required' if blockers else 'note' if notes else 'ready'
        # `codes` is the structured form of the same judgement, so the browser switches
        # on a contract instead of re-matching the parser's warning prefixes and status
        # strings. `notes` stays for the rep-facing prose.
        codes = [] if excluded_reason else row_contradictions(row, row_issues)
        # `state` answers "can this quote be synced"; `severity` answers "what does this
        # row look like to a rep". They are different questions and used to be the same
        # value read two ways -- see "what the sales view paints" above.
        states.append({**ref, 'state': state, 'notes': notes, 'blocking_issues': blockers,
                       'exclusion_reason': excluded_reason, 'codes': codes,
                       'severity': 'excluded' if excluded_reason else sales_severity(codes)})
    return {'ready': not issues, 'blocking_issues': issues, 'excluded': excluded,
            'included_count': included_count, 'excluded_count': len(excluded), 'row_states': states}
