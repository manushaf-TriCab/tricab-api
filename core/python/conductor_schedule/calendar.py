"""Machine calendars: a weekly pattern repeated forward, minus the plant shutdown; duration is consumed only inside
working intervals (an interval with efficiency e yields e productive hours per wall hour). Contract 4.6."""

from __future__ import annotations

from datetime import date, datetime, timedelta
from typing import Dict, List, Sequence, Tuple

from . import config
from .history import Calendars, Interval, week_pattern
from .issues import IssueLog
from .settings import split_intervals


class CalendarError(Exception):
    pass


class MachineCalendar:
    def __init__(self, pattern: Dict[int, Sequence[Interval]], shutdown: Sequence[Tuple[date, date]] = ()):
        self.pattern = {k: sorted(v) for k, v in pattern.items()}
        self.shutdown = list(shutdown)

    def is_shutdown(self, d: date) -> bool:
        return any(a <= d <= b for a, b in self.shutdown)

    def intervals(self, d: date) -> List[Tuple[datetime, datetime, float]]:
        if self.is_shutdown(d):
            return []
        mid = datetime(d.year, d.month, d.day)
        return [(mid + timedelta(seconds=s), mid + timedelta(seconds=e), eff)
                for s, e, eff in self.pattern.get(d.weekday(), ()) if e > s and eff > 0]

    def hours_per_week(self) -> float:
        """Productive hours in the repeated week (efficiency applied), shutdown ignored."""
        return sum((e - s) / 3600.0 * eff for v in self.pattern.values() for s, e, eff in v)

    def productive_hours(self, a: datetime, b: datetime) -> float:
        """Productive hours available in [a, b), shutdown honoured."""
        total, d = 0.0, a.date()
        while d <= b.date():
            for s, e, eff in self.intervals(d):
                lo, hi = max(s, a), min(e, b)
                if hi > lo:
                    total += (hi - lo).total_seconds() / 3600.0 * eff
            d += timedelta(days=1)
        return total

    def run(self, ready: datetime, work_h: float) -> Tuple[datetime, datetime]:
        """Start and end of `work_h` productive hours begun no earlier than `ready`, working only in intervals.
        Zero work starts and ends at the first working instant at or after `ready`."""
        if not self.pattern or not any(self.pattern.values()):
            raise CalendarError("calendar has no working intervals")
        remaining = work_h
        start = None
        d = ready.date()
        limit = d + timedelta(days=config.MAX_SCHEDULE_DAYS)
        while d <= limit:
            for s, e, eff in self.intervals(d):
                if e <= ready:
                    continue
                lo = max(s, ready)
                if start is None:
                    start = lo
                    if remaining <= 1e-12:
                        return start, start
                cap = (e - lo).total_seconds() / 3600.0 * eff
                if cap + 1e-12 >= remaining:
                    return start, lo + timedelta(hours=remaining / eff)
                remaining -= cap
                ready = e
            d += timedelta(days=1)
        raise CalendarError("work does not fit within %d days" % config.MAX_SCHEDULE_DAYS)


def override_pattern(days, intervals, efficiency: float = 1.0) -> Dict[int, List[Interval]]:
    """settings.shifts entry to a weekly pattern; intervals ending at or before their start cross midnight (4.6)."""
    return {d: [(s * 60, e * 60, efficiency) for s, e in sorted(spans)]
            for d, spans in split_intervals(days, intervals).items()}


def make_calendars(cals: Calendars, today: date, settings: dict, issues: IssueLog):
    """({machine: MachineCalendar}, {machine: {name, calendar, source, efficiency}}) for the 12 machines."""
    shutdown = [(date.fromisoformat(r["from"]), date.fromisoformat(r["to"])) for r in settings["shutdown"]]
    shifts, eff = settings["shifts"], settings["efficiency"]
    patterns: Dict[str, Dict[int, List[Interval]]] = {}
    out: Dict[str, MachineCalendar] = {}
    meta: Dict[str, dict] = {}
    for m in config.MACHINES:
        f = float(eff.get(m, 1.0))
        base = cals.machine_calendar.get(m)
        if m in shifts:
            pat = override_pattern(shifts[m]["days"], shifts[m]["intervals"], f)
            hrs = sum((e - s) / 3600.0 * x for v in pat.values() for s, e, x in v)
            issues.add(m, "shift_override", f"settings shift pattern in place of the Dynamics calendar: {hrs:.1f} productive h/week")
            source = "override"
        else:
            if base is None:
                raise CalendarError(f"no calendar row for machine {m} in work-calendars.csv")
            if base not in patterns:
                p, monday = week_pattern(cals.days.get(base, {}), today)
                if not monday:
                    raise CalendarError(f"calendar {base} has no working intervals before {today}")
                patterns[base] = p
                hrs = sum((e - s) / 3600.0 * x for v in p.values() for s, e, x in v)
                issues.add(base, "calendar_pattern", f"weekly pattern copied from the week of {monday}: {hrs:.1f} "
                           "productive h/week; only the settings shutdown is excluded, other holidays are not")
            pat = {d: [(s, e, x * f) for s, e, x in v] for d, v in patterns[base].items()}
            source = "dynamics"
        if f < 1.0:
            issues.add(m, "efficiency_applied", f"machine efficiency {f:.0%} applied to every interval")
        out[m] = MachineCalendar(pat, shutdown)
        meta[m] = {"name": cals.machine_name.get(m, m), "calendar": base or m, "source": source, "efficiency": f}
    return out, meta
