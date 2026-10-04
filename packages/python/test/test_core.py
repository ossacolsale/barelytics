import json
import tempfile
import unittest
from pathlib import Path
from wsgiref.util import setup_testing_defaults
from io import BytesIO
from urllib.parse import urlsplit

from barelytics import Barelytics, create_wsgi_app, is_bot, normalize_path

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
            self.assertEqual(app.db.execute("SELECT COUNT(*) FROM schema_migrations").fetchone()[0], 2)
            app.close()
            migrated_again = Barelytics(directory)
            self.assertEqual(migrated_again.audit()["schema_version"], 2)
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

    @staticmethod
    def request(app, method, body, path="/barelytics/track", extra=None):
        parsed = urlsplit(path)
        environ = {}
        setup_testing_defaults(environ)
        environ.update({"REQUEST_METHOD": method, "PATH_INFO": parsed.path, "QUERY_STRING": parsed.query, "CONTENT_LENGTH": str(len(body)), "wsgi.input": BytesIO(body), **(extra or {})})
        result = {}
        payload = b"".join(app(environ, lambda status, headers: result.update(status=status, headers=dict(headers))))
        return result["status"], result["headers"], payload


if __name__ == "__main__":
    unittest.main()
