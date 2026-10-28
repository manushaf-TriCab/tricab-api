<?php

declare(strict_types=1);

namespace TriCab\Core\Analytics\Domain;

/**
 * What a client reports. Only view and heartbeat are qualifying activity: they count towards daily,
 * module and live totals. Background and end only change session state, so a backgrounded app or a
 * closed tab stops counting as live immediately instead of after the live window.
 */
enum ActivitySignalType: string
{
    /** The user opened a route or screen. Counted as a module view. */
    case View = 'view';

    /** The user is still interacting (input within the client's idle window) on the current route or screen. */
    case Heartbeat = 'heartbeat';

    /** The tab was hidden or the app moved to the background or became inactive. */
    case Background = 'background';

    /** Sign-out, tab close, or app termination where the client can detect it. */
    case End = 'end';

    public function isQualifying(): bool
    {
        return $this === self::View || $this === self::Heartbeat;
    }
}
