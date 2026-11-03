# Generated from the RFQ parser's tests/test_mail_loop_guard.py by tools/sync_rfq_parser.py.
# Change the source or the tool's declared patches, never this file.
"""The parser must never ingest its own mail.

`docs/PHASE2_CLOSE.md` §6: `is_own_notification` matched the assignment-notification
subject tag and nothing else, so a draft-review *reply* landing back in the inbox was
read as a new RFQ. It fired once on 2026-09-01 across five emails. With
`MAIL_REPLY_ENABLED` live the new draft is replied to in turn, so this is a loop rather
than a wasted poll -- and with `MAIL_AUTO_PUSH_4JS` live each turn of it can raise a
quote in 4js.

Three guards, tested here as the negative properties they are: our notifications, our
tagged replies, and anything at all sent by the mailbox itself.
"""

from __future__ import annotations

import unittest

from rfq_parser.mail_ingest import (
    NOTIFICATION_SUBJECT_TAG,
    REPLY_SUBJECT_TAG,
    is_own_mail,
    is_own_notification,
    reply_subject,
)

MAILBOX = "quotegen@tricab.com"


def message(subject: str, sender: str = "clairem@tricab.com") -> dict:
    return {"subject": subject, "from": {"emailAddress": {"address": sender}}}


class ReplySubjectTests(unittest.TestCase):
    def test_the_tag_sits_behind_the_reply_prefix(self):
        self.assertEqual(reply_subject("14033 Magnum"), "RE: [RFQ-Reply] 14033 Magnum")
        self.assertEqual(reply_subject("RE: 14033 Magnum"), "RE: [RFQ-Reply] 14033 Magnum")

    def test_an_existing_prefix_is_kept_rather_than_stacked(self):
        # Whatever prefix the subject arrived with is passed through untouched, and the
        # tag goes behind it. No prefix is added to a subject that already has one.
        for prefix in ("Re: ", "FW: ", "AW: ", "RE: RE: "):
            with self.subTest(prefix=prefix):
                tagged = reply_subject(f"{prefix}CS Gas")
                self.assertEqual(tagged, f"{prefix}{REPLY_SUBJECT_TAG} CS Gas")

    def test_tagging_is_idempotent(self):
        # The worker replies to a thread it may already have replied to.
        for subject in ("14033 Magnum", "RE: [RFQ-Reply] 14033 Magnum", "[RFQ-Reply] CS Gas"):
            with self.subTest(subject=subject):
                once = reply_subject(subject)
                self.assertEqual(reply_subject(once), once)
                self.assertEqual(once.lower().count(REPLY_SUBJECT_TAG.lower()), 1)

    def test_a_reply_we_send_is_recognised_as_ours_when_it_comes_back(self):
        sent = reply_subject("14033 Magnum")
        self.assertEqual(is_own_mail(message(f"RE: {sent}", "someone@external.com"),
                                     mailbox=MAILBOX), "rfq_reply")


class OwnMailTests(unittest.TestCase):
    def test_a_real_rfq_from_a_rep_is_not_ours(self):
        self.assertEqual(is_own_mail(message("Quote request - 40m cable"), mailbox=MAILBOX), "")

    def test_our_assignment_notification_and_answers_to_it(self):
        tagged = f"{NOTIFICATION_SUBJECT_TAG} Assigned to you: Acme Pty"
        for subject in (tagged, f"Re: {tagged}", f"Undeliverable: {tagged}"):
            with self.subTest(subject=subject):
                self.assertEqual(
                    is_own_mail(message(subject, "mailer-daemon@external.com"), mailbox=MAILBOX),
                    "notification",
                )

    def test_anything_the_mailbox_itself_sent_is_ours(self):
        # The catch-all: it holds when the subject has been rewritten past recognition,
        # which is the case neither tag can cover.
        self.assertEqual(is_own_mail(message("14033 Magnum", MAILBOX), mailbox=MAILBOX), "self_sent")

    def test_the_sender_check_is_case_insensitive(self):
        self.assertEqual(
            is_own_mail(message("14033 Magnum", "QuoteGen@TriCab.com"), mailbox="quotegen@tricab.com"),
            "self_sent",
        )

    def test_without_a_mailbox_the_tags_still_hold(self):
        # A caller that cannot name its mailbox loses only the coarsest guard.
        self.assertEqual(is_own_mail(message("14033 Magnum", MAILBOX)), "")
        self.assertEqual(is_own_mail(message(f"{REPLY_SUBJECT_TAG} x", MAILBOX)), "rfq_reply")

    def test_the_boolean_wrapper_still_answers_for_old_callers(self):
        self.assertTrue(is_own_notification({"subject": "[RFQ] Assigned to you: x"}))
        self.assertFalse(is_own_notification({"subject": "Quote request - 40m cable"}))


if __name__ == "__main__":
    unittest.main()
