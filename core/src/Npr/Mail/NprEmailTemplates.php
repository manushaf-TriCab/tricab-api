<?php

declare(strict_types=1);

namespace TriCab\Core\Npr\Mail;

/**
 * Plain, short messages: port of the npr-portal's EmailTemplateService, with
 * the links pointing at tricab.ai (`/npr/...`, `/npr/team/...`,
 * `/admin/tsd/npr/...`), where a staff sign-in is required. No request data is
 * ever put in a URL, only the public id.
 */
final class NprEmailTemplates
{
    private const SALES = 'Sales';
    private const MANAGER = 'Manager';
    private const TSD = 'TSD';

    public function __construct(private readonly string $baseUrl)
    {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{subject: string, html: string, text: string}
     */
    public function render(string $event, array $payload): array
    {
        $number = self::str($payload, 'requestNumber', 'NPR');
        $title = self::str($payload, 'title', 'Untitled request');
        $customer = self::str($payload, 'customer', '') !== '' ? self::str($payload, 'customer', '') : 'Customer';
        $urgency = strtoupper(self::str($payload, 'urgency', 'standard'));
        $actor = self::str($payload, 'actor', 'Someone');
        $summary = self::str($payload, 'summary', '');
        $id = rawurlencode(self::str($payload, 'publicId', ''));
        $details = $this->details($event, $payload, $actor);

        $subject = sprintf('[%s] %s - %s', $number, $details['headline'], $customer);

        $links = [];
        foreach ($details['links'] as [$label, $path]) {
            $links[] = [$label, rtrim($this->baseUrl, '/') . sprintf($path, $id)];
        }

        $text = implode("\n", array_filter([
            $details['headline'],
            '',
            $details['lead'],
            $details['next'],
            '',
            sprintf('Request: %s (%s), %s, urgency %s', $number, $title, $customer, $urgency),
            $summary !== '' ? $details['summaryTitle'] . ': ' . $summary : null,
            '',
            ...array_map(static fn (array $l): string => sprintf('Open (%s): %s', $l[0], $l[1]), $links),
            '',
            'Sign-in with your TriCab account is required. This is an automatic message from the NPR workflow.',
        ], static fn (?string $line): bool => $line !== null));

        $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<div style="font-family:Segoe UI,Arial,sans-serif;font-size:14px;color:#1f2937">'
            . '<h2 style="font-size:16px;margin:0 0 12px">' . $h($details['headline']) . '</h2>'
            . '<p style="margin:0 0 8px">' . $h($details['lead']) . '</p>'
            . '<p style="margin:0 0 12px">' . $h($details['next']) . '</p>'
            . '<p style="margin:0 0 12px"><strong>' . $h($number) . '</strong> ' . $h($title) . '<br>'
            . $h($customer) . ', urgency ' . $h($urgency) . '</p>'
            . ($summary !== '' ? '<p style="margin:0 0 12px"><strong>' . $h($details['summaryTitle']) . ':</strong> ' . $h($summary) . '</p>' : '')
            . implode('', array_map(
                static fn (array $l): string => '<p style="margin:0 0 6px"><a href="' . $h($l[1]) . '">Open in TriCab (' . $h($l[0]) . ')</a></p>',
                $links,
            ))
            . '<p style="margin:12px 0 0;color:#6b7280;font-size:12px">Sign-in with your TriCab account is required. This is an automatic message from the NPR workflow.</p>'
            . '</div>';

        return ['subject' => $subject, 'html' => $html, 'text' => $text];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{headline: string, lead: string, next: string, summaryTitle: string, links: list<array{string, string}>}
     */
    private function details(string $event, array $payload, string $actor): array
    {
        $revision = (int) ($payload['revision'] ?? 1);
        $sales = [self::SALES, '/npr/%s'];
        $manager = [self::MANAGER, '/npr/team/%s'];
        $tsd = [self::TSD, '/admin/tsd/npr/%s'];
        $outcome = strtoupper(self::str($payload, 'outcome', 'DECIDED'));
        $owner = self::str($payload, 'tsdOwner', 'a TSD engineer');

        return match ($event) {
            'sales_submitted' => [
                'headline' => 'Action required: endorsement needed',
                'lead' => "A new product request was submitted by {$actor} and needs your endorsement before it goes to TSD.",
                'next' => 'Review the requirements and the commercial case, then endorse or return it with feedback.',
                'summaryTitle' => 'Submission',
                'links' => [$manager],
            ],
            'sales_resubmitted' => [
                'headline' => 'Action required: amended request resubmitted',
                'lead' => "{$actor} amended the returned request and resubmitted it (revision {$revision}).",
                'next' => 'Check the changes, then endorse or return it again.',
                'summaryTitle' => 'Amendment',
                'links' => [$manager],
            ],
            'manager_returned' => [
                'headline' => 'Request returned to Sales',
                'lead' => "{$actor} returned this request with notes.",
                'next' => 'Update the request and resubmit it for endorsement.',
                'summaryTitle' => 'Manager feedback',
                'links' => [$sales],
            ],
            'manager_rejected' => [
                'headline' => 'Request rejected',
                'lead' => "{$actor} rejected this request. It is cancelled.",
                'next' => 'No further action is needed.',
                'summaryTitle' => 'Reason',
                'links' => [$sales],
            ],
            'manager_endorsed' => [
                'headline' => 'Request endorsed and received by TSD',
                'lead' => "{$actor} endorsed this request. It is now in the TSD queue.",
                'next' => 'TSD acknowledges receipt within 3 working days.',
                'summaryTitle' => 'Endorsement',
                'links' => [$tsd],
            ],
            'tsd_acknowledged' => [
                'headline' => 'TSD acknowledged the request',
                'lead' => "{$actor} acknowledged receipt of this request.",
                'next' => 'TSD is now assessing technical feasibility.',
                'summaryTitle' => 'Receipt',
                'links' => [$sales, $manager],
            ],
            'tsd_assigned' => [
                'headline' => 'TSD engineer assigned',
                'lead' => "{$owner} is now the TSD owner of this request.",
                'next' => 'The engineering assessment is under way.',
                'summaryTitle' => 'Assignment',
                'links' => [$sales, $manager],
            ],
            'assessment_saved' => [
                'headline' => 'TSD assessment updated',
                'lead' => "{$actor} updated the technical assessment.",
                'next' => 'The assessment is being finalised for the screening decision.',
                'summaryTitle' => 'Update',
                'links' => [$sales, $manager],
            ],
            'tsd_returned' => [
                'headline' => 'Clarification needed from Sales',
                'lead' => "{$actor} (TSD) returned this request to Sales.",
                'next' => 'Review the questions, update the request and resubmit it for endorsement.',
                'summaryTitle' => 'TSD return reason',
                'links' => [$sales],
            ],
            'screening_requested' => [
                'headline' => 'Action required: screening decision',
                'lead' => "{$actor} completed the TSD assessment and sent this request for a screening decision.",
                'next' => 'Review it and record Approved, Deferred or Rejected.',
                'summaryTitle' => 'Screening request',
                'links' => [$tsd],
            ],
            'screening_decided' => [
                'headline' => "Screening outcome: {$outcome}",
                'lead' => "{$actor} recorded the screening outcome as {$outcome}.",
                'next' => 'The rationale and any conditions are on the request.',
                'summaryTitle' => 'Decision',
                'links' => [$sales, $manager, $tsd],
            ],
            default => [
                'headline' => 'Request updated',
                'lead' => "{$actor} updated this request.",
                'next' => 'Open the request to see what changed.',
                'summaryTitle' => 'Update',
                'links' => [$sales],
            ],
        };
    }

    /** @param array<string, mixed> $payload */
    private static function str(array $payload, string $key, string $fallback): string
    {
        $value = $payload[$key] ?? null;

        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : $fallback;
    }
}
