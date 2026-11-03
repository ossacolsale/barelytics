import json
import tempfile
import unittest
from pathlib import Path
from wsgiref.util import setup_testing_defaults
from io import BytesIO
from urllib.parse import urlsplit

from barelytics import Barelytics, create_wsgi_app, classify_user_agent, is_bot, normalize_path

ROOT = Path(__file__).resolve().parents[3]


class BarelyticsTests(unittest.TestCase):
    def test_shared_path_vectors(self):
        vectors = json.loads((ROOT / "spec/test-vectors/path-normalization.json").read_text())
        for vector in vectors:
            value = vector["input"]
            if isinstance(value, dict): value = value["prefix"] + value["repeat"] * value["count"]
            self.assertEqual(normalize_path(value), vector["expected"], value)

    def test_shared_bot_vectors(self):
        vectors = json.loads((ROOT / "spec/test-vectors/bot-filtering.json").read_text())
        for vector in vectors:
            self.assertEqual(is_bot(vector["user_agent"], vector.get("additional_patterns", [])), vector["bot"])

    def test_automation_vectors_and_aggregate_lifecycle(self):
        vectors = json.loads((ROOT / "spec/test-vectors/automation.json").read_text())
        for v in vectors:
            self.assertEqual(classify_user_agent(v["user_agent"], v.get("additional_patterns", [])), {k: v[k] for k in ("category", "family", "identity", "automation")} | {"verified": False})
        with tempfile.TemporaryDirectory() as directory:
            analytics = Barelytics(directory)
            analytics.track_page_view("/article", user_agent="GPTBot/1")
            analytics.track_page_view("/article", user_agent="a crawler/1")
            analytics.track_page_view("/private/report", user_agent="GPTBot/1")
            report = analytics.admin_data("automation")
            self.assertEqual(report["total"], 2)
            self.assertFalse(report["verified"])
            self.assertEqual(report["rows"], [{"path": "/article", "requests": 2}])
            self.assertEqual(analytics.dashboard()["total"], 0)
            self.assertEqual(analytics.audit()["profile"], "strict")
            analytics.db.execute("INSERT INTO automation_daily VALUES ('2000-01-01','/old','ai','openai',2)")
            analytics.db.execute("UPDATE settings SET value='invalid-cleanup-fixture' WHERE key='last_cleanup_at'")
            self.assertTrue(analytics.track_page_view("/fresh"))
            self.assertEqual(analytics.db.execute("SELECT COUNT(*) FROM automation_daily WHERE day='2000-01-01'").fetchone()[0], 0)
            analytics.delete_all()
            self.assertEqual(analytics.admin_data("automation")["total"], 0)
            analytics.close()

    def test_default_strict_storage_privacy_and_controls(self):
        with tempfile.TemporaryDirectory() as directory:
            app = Barelytics(directory, retention_days=30)
            self.addCleanup(app.close)
            self.assertEqual(app.audit()["profile"], "strict")
            self.assertTrue(app.track_page_view("/article", user_agent="Mozilla/5.0 Chrome/124 Windows"))
            self.assertFalse(app.track_page_view("/private/report"))
            self.assertFalse(app.track_page_view("/article", user_agent="Googlebot"))
            self.assertEqual(app.dashboard()["by_page"], [{"path": "/article", "views": 1}])
            with self.assertRaisesRegex(ValueError, "acknowledgement"):
                app.update_privacy({"country_collection": True})
            app.update_privacy({key: True for key in ("country_collection", "referrer_collection", "browser_collection", "device_collection", "os_collection")}, True)
            app.track_page_view("/extended", user_agent="Mozilla/5.0 Chrome/124 Windows", country="it", referrer="https://example.test/article")
            self.assertEqual(app.db.execute("SELECT COUNT(*) FROM dimensions_daily WHERE path='/extended'").fetchone()[0], 3)
            self.assertEqual(app.db.execute("SELECT COUNT(*) FROM referrers_daily WHERE path='/extended'").fetchone()[0], 1)
            self.assertEqual(app.db.execute("SELECT country FROM pageviews_daily WHERE path='/extended'").fetchone()[0], "IT")
            self.assertEqual(app.audit()["profile"], "extended")
            app.return_to_strict_mode()
            self.assertEqual(app.audit()["profile"], "strict")
            self.assertEqual(app.audit()["result"], "PASS")
            app.db.execute("INSERT INTO pageviews_daily(day,path,country,views) VALUES (?,?,?,?)", ("2000-01-01", "/expired", "XX", 4))
            self.assertTrue(app.cleanup())
            self.assertEqual(app.db.execute("SELECT COUNT(*) FROM pageviews_daily WHERE path=?", ("/expired",)).fetchone()[0], 0)
            self.assertEqual(app.db.execute("SELECT COUNT(*) FROM schema_migrations").fetchone()[0], 3)
            app.close()
            migrated_again = Barelytics(directory)
            self.assertEqual(migrated_again.audit()["schema_version"], 3)
            migrated_again.close()

    def test_wsgi_protocol_statuses(self):
        with tempfile.TemporaryDirectory() as directory:
            analytics = Barelytics(directory)
            app = create_wsgi_app(analytics, authorize=lambda env: env.get("HTTP_X_ADMIN") == "yes", verify_csrf=lambda _env, token: token == "valid-token", csrf_token=lambda _env: "valid-token")
            self.assertEqual(self.request(app, "POST", b'{"path":"/article"}')[0], "204 No Content")
            self.assertEqual(self.request(app, "POST", b'{"path":"/article","visitor_id":"x"}')[0], "400 Bad Request")
            self.assertEqual(self.request(app, "GET", b"")[0], "405 Method Not Allowed")
            self.assertEqual(analytics.dashboard()["total"], 1)
            self.assertEqual(self.request(app, "GET", b"", path="/barelytics/admin")[0], "403 Forbidden")
            self.assertEqual(self.request(app, "GET", b"", path="/barelytics/admin", extra={"HTTP_X_ADMIN": "yes"})[0], "200 OK")
            ui = self.request(app, "GET", b"", path="/barelytics/admin/", extra={"HTTP_X_ADMIN": "yes"})
            self.assertEqual(ui[0], "200 OK")
            self.assertIn(b"BARELYTICS_ADMIN_CONFIG", self.request(app, "GET", b"", path="/barelytics/admin/admin-ui/config.js", extra={"HTTP_X_ADMIN": "yes"})[2])
            logo = self.request(app, "GET", b"", path="/barelytics/admin/admin-ui/brand-mark.png", extra={"HTTP_X_ADMIN": "yes"})
            self.assertEqual(logo[1]["Content-Type"], "image/png")
            self.assertTrue(logo[2].startswith(b"\x89PNG\r\n\x1a\n"))
            dashboard = self.request(app, "GET", b"", path="/barelytics/admin/api/dashboard", extra={"HTTP_X_ADMIN": "yes"})
            self.assertEqual(json.loads(dashboard[2])["data"]["active_pages"], 1)
            dimensions = self.request(app, "GET", b"", path="/barelytics/admin/api/dimensions?dimension=country", extra={"HTTP_X_ADMIN": "yes"})
            self.assertFalse(json.loads(dimensions[2])["data"]["enabled"])
            self.assertEqual(self.request(app, "POST", b'{"action":"strict","csrf":"invalid"}', path="/barelytics/admin", extra={"HTTP_X_ADMIN": "yes"})[0], "403 Forbidden")
            self.assertEqual(self.request(app, "POST", b'{"action":"privacy","values":{"country_collection":true},"confirmation":true,"csrf":"valid-token"}', path="/barelytics/admin", extra={"HTTP_X_ADMIN": "yes"})[0], "204 No Content")
            self.assertEqual(analytics.audit()["profile"], "extended")
            self.assertEqual(self.request(app, "POST", b'{"action":"strict","csrf":"valid-token"}', path="/barelytics/admin", extra={"HTTP_X_ADMIN": "yes"})[0], "204 No Content")
            self.assertEqual(analytics.audit()["profile"], "strict")
            analytics.close()

    def test_transaction_isolation_retention_and_host_failure(self):
        from concurrent.futures import ThreadPoolExecutor
        with tempfile.TemporaryDirectory() as directory:
            analytics = Barelytics(directory, retention_days=30)
            for path in ("/admin", "/%61dmin/report"):
                self.assertFalse(analytics.track_page_view(path))
            analytics.update_privacy({"browser_collection": True}, True)
            analytics.db.execute("CREATE TRIGGER fail_dimension BEFORE INSERT ON dimensions_daily BEGIN SELECT RAISE(ABORT, 'fixture'); END")
            self.assertFalse(analytics.track_page_view("/interrupted"))
            self.assertEqual(analytics.db.execute("SELECT COUNT(*) FROM pageviews_daily").fetchone()[0], 0)
            analytics.db.execute("DROP TRIGGER fail_dimension")
            analytics.db.executemany("INSERT INTO pageviews_daily VALUES ('2000-01-01',?,'XX',1)", [(f"/expired-{i}",) for i in range(1001)])
            self.assertTrue(analytics.track_page_view("/fresh"))
            self.assertEqual(analytics.db.execute("SELECT COUNT(*) FROM pageviews_daily WHERE day='2000-01-01'").fetchone()[0], 1)
            self.assertTrue(analytics.track_page_view("/fresh"))
            self.assertEqual(analytics.db.execute("SELECT COUNT(*) FROM pageviews_daily WHERE day='2000-01-01'").fetchone()[0], 0)
            with ThreadPoolExecutor(max_workers=8) as pool:
                self.assertTrue(all(pool.map(lambda _: analytics.track_page_view("/concurrent"), range(100))))
            self.assertEqual(analytics.db.execute("SELECT SUM(views) FROM pageviews_daily WHERE path='/concurrent'").fetchone()[0], 100)
            analytics.close()
            reopened = Barelytics(directory)
            self.assertEqual(reopened._config()["retention_days"], 30)
            reopened.close()
            self.assertFalse(reopened.track_page_view("/closed"))

    def test_shared_protocol_and_page_dimension_filters(self):
        with tempfile.TemporaryDirectory() as directory:
            analytics = Barelytics(directory)
            app = create_wsgi_app(analytics)
            vectors = json.loads((ROOT / "spec/test-vectors/protocol.json").read_text())
            for index, v in enumerate(vectors):
                analytics.update_privacy({"referrer_collection": v.get("referrer_collection") is True}, True)
                body = bytes.fromhex(v["raw_hex"]) if "raw_hex" in v else (b"x" * v["body_bytes"] if "body_bytes" in v else (v["body"] if isinstance(v.get("body"), str) else json.dumps(v.get("body", {}))).encode())
                if "body_size" in v: body = body.ljust(v["body_size"], b" ")
                status, headers, _ = self.request(app, v["method"], body, extra={"CONTENT_TYPE": v.get("content_type", "application/json"), "HTTP_REFERER": "https://beacon.test/current?private"})
                self.assertEqual(int(status.split()[0]), v["status"], index)
                self.assertEqual(headers["Cache-Control"], "no-store")
                self.assertNotIn("Set-Cookie", headers)
            analytics.update_privacy({key: True for key in ("country_collection", "referrer_collection", "browser_collection", "device_collection", "os_collection")}, True)
            analytics.track_page_view("/one", user_agent="Mozilla/5.0 Firefox/1 Windows", country="it", referrer="https://one.test/private?q=secret")
            analytics.track_page_view("/two", user_agent="Mozilla/5.0 Chrome/1 Android Mobile", country="us", referrer="https://two.test/private?q=secret")
            for dimension, expected in dict(country="IT", referrer="one.test", browser="Firefox", device="Desktop", os="Windows").items():
                self.assertEqual(analytics.admin_data("dimensions", dict(dimension=dimension, path="/one"))["rows"], [dict(value=expected, views=1)])
                with self.assertRaises(ValueError): analytics.admin_data("dimensions", dict(dimension=dimension, path="https://bad.test"))
            self.assertEqual(analytics.db.execute("SELECT COUNT(*) FROM referrers_daily WHERE referrer_host='beacon.test'").fetchone()[0], 0)
            analytics.return_to_strict_mode()
            self.assertEqual(analytics.audit()["historical_dimensions"], ["country", "referrer", "browser", "device", "os"])
            self.assertFalse(analytics._config()["daily_reach_available"])
            analytics.close()

    def test_legacy_upgrade_audit_schema_and_future_guard(self):
        import sqlite3
        with tempfile.TemporaryDirectory() as directory:
            raw = sqlite3.connect(Path(directory) / "analytics.sqlite", isolation_level=None)
            raw.executescript((ROOT / "spec/test-vectors/legacy-v2.sql").read_text())
            app = Barelytics(directory)
            self.assertEqual(app._config()["retention_days"], 30)
            self.assertEqual(raw.execute("SELECT views FROM pageviews_daily WHERE path='/legacy'").fetchone()[0], 7)
            self.assertEqual(raw.execute("SELECT value FROM settings WHERE key='admin_password_hash'").fetchone()[0], 'fixture-unchanged-credential')
            self.assertEqual(raw.execute("SELECT COUNT(*) FROM schema_migrations").fetchone()[0], 3)
            raw.execute("CREATE TABLE request_log(user_agent TEXT)")
            self.assertEqual(app.audit()["result"], "FAIL")
            raw.execute("DROP TABLE request_log")
            self.assertEqual(app.audit()["result"], "PASS")
            raw.execute("INSERT INTO schema_migrations VALUES (99,'fixture')")
            with self.assertRaisesRegex(RuntimeError, "newer"): Barelytics(directory)
            self.assertEqual(raw.execute("SELECT views FROM pageviews_daily WHERE path='/legacy'").fetchone()[0], 7)
            app.close()
            raw.close()

    @staticmethod
    def request(app, method, body, path="/barelytics/track", extra=None):
        parsed = urlsplit(path)
        environ = {}
        setup_testing_defaults(environ)
        environ.update({"REQUEST_METHOD": method, "PATH_INFO": parsed.path, "QUERY_STRING": parsed.query, "CONTENT_LENGTH": str(len(body)), "CONTENT_TYPE": "application/json", "wsgi.input": BytesIO(body), **(extra or {})})
        result = {}
        payload = b"".join(app(environ, lambda status, headers: result.update(status=status, headers=dict(headers))))
        return result["status"], result["headers"], payload


if __name__ == "__main__":
    unittest.main()
