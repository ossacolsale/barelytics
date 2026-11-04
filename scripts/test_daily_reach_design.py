"""Offline design experiments, not a collector or a production reach feature."""
from datetime import date, datetime, timedelta, timezone
import unittest
from concurrent.futures import ThreadPoolExecutor
from threading import Lock
from zoneinfo import ZoneInfo


def count_and_set(incoming, day):
    return int(incoming != day), day


def acknowledge(incoming, day):
    if incoming == "counted:" + day:
        return 0, incoming
    if incoming == "pending:" + day:
        return 1, "counted:" + day
    return 0, "pending:" + day


class ClientGate:
    """Executable serialized transaction model; real IndexedDB tested separately."""
    def __init__(self):
        self.day = None
        self.lock = Lock()
        self.attempts = 0

    def visit(self, day, available=True, abort=False, delivered=True):
        with self.lock:
            if not available or abort or (self.day is not None and self.day >= day):
                return 0
            self.day = day
        self.attempts += 1  # The marker stays committed even if delivery is lost.
        return int(delivered)


class ClientGateTests(unittest.TestCase):
    def test_concurrent_cooperating_tabs_authorize_one_send(self):
        for tabs in (2, 10, 100):
            gate = ClientGate()
            with ThreadPoolExecutor(max_workers=10) as pool:
                delivered = list(pool.map(lambda _: gate.visit("2026-11-03"), range(tabs)))
            self.assertEqual((sum(delivered), gate.attempts), (1, 1))

    def test_rejected_or_aborted_storage_never_sends_unlike_cookie_design(self):
        for mode in ({"available": False}, {"abort": True}):
            gate = ClientGate()
            self.assertEqual(sum(gate.visit("2026-11-03", **mode) for _ in range(20)), 0)
            self.assertEqual(gate.attempts, 0)
        self.assertEqual(sum(count_and_set(None, "2026-11-03")[0] for _ in range(20)), 20)

    def test_loss_is_not_retried_and_days_do_not_regress(self):
        gate = ClientGate()
        self.assertEqual(gate.visit("2026-11-03", delivered=False), 0)
        self.assertEqual(gate.visit("2026-11-03"), 0)
        self.assertEqual(gate.attempts, 1)
        self.assertEqual(gate.visit("2026-11-04"), 1)
        self.assertEqual(gate.visit("2026-11-03"), 0)
        self.assertEqual(gate.visit("2026-11-04"), 0)

    def test_reset_and_separate_contexts_can_inflate_reports(self):
        gate = ClientGate()
        self.assertEqual(gate.visit("2026-11-03"), 1)
        gate.day = None  # Manual deletion/eviction, not an automatic retry.
        self.assertEqual(gate.visit("2026-11-03"), 1)
        self.assertEqual(ClientGate().visit("2026-11-03"), 1)


class DailyReachDesignTests(unittest.TestCase):
    def test_common_marker_is_identical_and_sequentially_deduplicates(self):
        day = "2026-11-03"
        count_a, marker_a = count_and_set(None, day)
        count_b, marker_b = count_and_set(None, day)
        self.assertEqual((count_a + count_b, marker_a, marker_b), (2, day, day))
        self.assertEqual(count_and_set(marker_a, day)[0], 0)
        self.assertEqual(count_and_set(marker_a, "2026-11-04")[0], 1)

    def test_rejected_cookies_overcount_every_view_without_a_bound(self):
        for views in (1, 20, 1000):
            self.assertEqual(sum(count_and_set(None, "2026-11-03")[0] for _ in range(views)), views)
            self.assertEqual(sum(acknowledge(None, "2026-11-03")[0] for _ in range(views)), 0)

    def test_parallel_first_requests_and_lost_responses_duplicate_counts(self):
        day = "2026-11-03"
        # Both tabs present the same snapshot; SQL serialization cannot fix this.
        self.assertEqual(sum(count_and_set(None, day)[0] for _ in range(2)), 2)
        self.assertEqual(sum(acknowledge("pending:" + day, day)[0] for _ in range(2)), 2)
        # Retrying after losing the Set-Cookie response is indistinguishable.
        self.assertEqual(count_and_set(None, day), count_and_set(None, day))

    def test_acknowledgement_requires_another_request_for_one_page_visitors(self):
        day = "2026-11-03"
        count, pending = acknowledge(None, day)
        self.assertEqual(count, 0)
        count, counted = acknowledge(pending, day)
        self.assertEqual(count, 1)
        self.assertEqual(acknowledge(counted, day)[0], 0)

    def test_fixed_calendar_boundaries_have_23_and_25_hour_days(self):
        zone = ZoneInfo("Europe/Rome")
        for statistical_day, hours in ((date(2026, 3, 28), 23), (date(2026, 10, 24), 25)):
            start = datetime.combine(statistical_day, datetime.min.time(), zone).replace(hour=4)
            end = datetime.combine(statistical_day + timedelta(days=1), datetime.min.time(), zone).replace(hour=4)
            self.assertEqual((end.astimezone(timezone.utc) - start.astimezone(timezone.utc)).total_seconds(), hours * 3600)
        # UTC reporting version 1 remains a fixed 24-hour day.
        start = datetime(2026, 3, 29, tzinfo=timezone.utc)
        self.assertEqual((start + timedelta(days=1) - start).total_seconds(), 86400)


if __name__ == "__main__":
    unittest.main()
