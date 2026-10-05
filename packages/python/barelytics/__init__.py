"""Native, self-hosted aggregate analytics for Python web applications."""
from __future__ import annotations

from datetime import date, datetime, timedelta, timezone
from hashlib import sha256
from ipaddress import ip_address
from pathlib import Path
from urllib.parse import unquote_to_bytes, urlsplit, parse_qs
import json
import os
import re
import sqlite3
from typing import Any, Callable

CONTRACT_VERSION = 1
SCHEMA_VERSION = 2
DIMENSIONS = ("country_collection", "referrer_collection", "browser_collection", "device_collection", "os_collection")
DEFAULT_EXCLUSIONS = ("/admin/*", "/admin.php", "/account/*", "/checkout/*", "/customer/*", "/patient/*", "/profile/*", "/private/*")
DEFAULT_BOTS = ("bot", "crawler", "spider", "slurp", "bingpreview", "headless", "lighthouse", "pagespeed", "semrush", "ahrefsbot", "mj12bot", "dotbot", "facebookexternalhit", "twitterbot", "linkedinbot", "discordbot", "telegrambot", "whatsapp", "petalbot", "yandex", "baiduspider", "bytespider", "duckduckbot", "applebot", "googlebot", "bingbot")
_IDS = re.compile(r"(?<=/)(?:[0-9]{6,}|[0-9A-HJKMNP-TV-Z]{26}|[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}|[0-9a-f]{16,}|[A-Za-z0-9_-]{32,})(?=/|$)", re.I)
_VALID_RETENTION = {30, 90, 180, 365}


def normalize_path(value: str) -> str | None:
    if not isinstance(value, str) or not value or len(value.encode("utf-8", "surrogatepass")) > 512:
        return None
    if any(ord(char) < 32 or ord(char) == 127 for char in value) or not value.startswith("/") or value.startswith("//") or "?" in value or "#" in value:
        return None
    path = re.sub(r"/{2,}", "/", value)
    if len(path.encode("utf-8", "surrogatepass")) > 512 or re.search(r"%(?![0-9a-f]{2})", path, re.I):
        return None
    try:
        decoded = unquote_to_bytes(path).decode("utf-8", "strict")
    except (UnicodeDecodeError, UnicodeEncodeError):
        return None
    if any(ord(char) < 32 or ord(char) == 127 for char in decoded) or any(char in decoded for char in "@?#"):
        return None
    if decoded != path and _IDS.search(decoded):
        return None
    return _IDS.sub(":id", path)


def is_bot(user_agent: str, extra_patterns: tuple[str, ...] | list[str] = ()) -> bool:
    value = user_agent.casefold() if isinstance(user_agent, str) else ""
    return any(item.casefold() in value for item in (*DEFAULT_BOTS, *(p for p in extra_patterns if isinstance(p, str) and p)))


def _excluded(path: str, patterns: list[str]) -> bool:
    for pattern in patterns:
        if pattern.endswith("/*"):
            base = pattern[:-2]
            if path == base or path.startswith(base + "/"):
                return True
        elif path == pattern:
            return True
    return False


def _host(referrer: str) -> str | None:
    if not isinstance(referrer, str) or len(referrer.encode("utf-8", "ignore")) > 2048:
        return None
    try:
        parsed = urlsplit(referrer)
        host = (parsed.hostname or "").lower().rstrip(".")
        if parsed.scheme.lower() not in ("http", "https") or not host or parsed.username is not None or parsed.password is not None:
            return None
        try:
            ip_address(host)
            return None
        except ValueError:
            pass
        if not re.fullmatch(r"(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?", host):
            return None
        return host
    except ValueError:
        return None


def _category(user_agent: str, dimension: str) -> str:
    ua = user_agent.casefold()
    if dimension == "browser":
        if "firefox/" in ua: return "Firefox"
        if "edg/" in ua or "edge/" in ua: return "Edge"
        if "chrome/" in ua: return "Chrome"
        if "safari/" in ua: return "Safari"
    elif dimension == "device":
        if "ipad" in ua or "tablet" in ua: return "Tablet"
        if any(x in ua for x in ("mobile", "android", "iphone")): return "Mobile"
        return "Desktop"
    elif dimension == "os":
        for term, label in (("windows", "Windows"), ("android", "Android"), ("iphone", "iOS"), ("ipad", "iOS"), ("mac os", "macOS"), ("linux", "Linux")):
            if term in ua: return label
    return "Other"


class Barelytics:
    def __init__(self, data_directory: str | os.PathLike[str], *, retention_days: int = 180, path_exclusions: list[str] | None = None, bot_patterns: list[str] | None = None):
        directory = Path(data_directory).expanduser().resolve()
        directory.mkdir(mode=0o700, parents=True, exist_ok=True)
        self.database_path = directory / "analytics.sqlite"
        self.db = sqlite3.connect(self.database_path, timeout=1, isolation_level=None, check_same_thread=False)
        self.db.row_factory = sqlite3.Row
        self.db.execute("PRAGMA busy_timeout=1000")
        try: self.db.execute("PRAGMA journal_mode=WAL")
        except sqlite3.Error: pass
        try: self.database_path.chmod(0o600)
        except OSError: pass
        self._migrate()
        if path_exclusions is not None:
            values = sorted({x for x in path_exclusions if isinstance(x, str) and x.startswith("/") and len(x) <= 200})
            self._set("path_exclusions", "\n".join(values))
        if bot_patterns is not None:
            self._set("bot_patterns", "\n".join(x for x in bot_patterns if isinstance(x, str) and 0 < len(x) <= 200))
        self.set_retention(retention_days)

    def _migrate(self) -> None:
        self.db.executescript("""
        CREATE TABLE IF NOT EXISTS schema_migrations(version INTEGER PRIMARY KEY, applied_at TEXT NOT NULL);
        CREATE TABLE IF NOT EXISTS settings(key TEXT PRIMARY KEY,value TEXT NOT NULL);
        CREATE TABLE IF NOT EXISTS pageviews_daily(day TEXT NOT NULL,path TEXT NOT NULL,country TEXT NOT NULL DEFAULT 'XX',views INTEGER NOT NULL DEFAULT 0,PRIMARY KEY(day,path,country));
        CREATE TABLE IF NOT EXISTS referrers_daily(day TEXT NOT NULL,path TEXT NOT NULL,referrer_host TEXT NOT NULL,views INTEGER NOT NULL DEFAULT 0,PRIMARY KEY(day,path,referrer_host));
        CREATE TABLE IF NOT EXISTS dimensions_daily(day TEXT NOT NULL,path TEXT NOT NULL,dimension TEXT NOT NULL,value TEXT NOT NULL,views INTEGER NOT NULL DEFAULT 0,PRIMARY KEY(day,path,dimension,value));
        CREATE TABLE IF NOT EXISTS privacy_configuration_history(timestamp TEXT NOT NULL,profile TEXT NOT NULL,effective_configuration_json TEXT NOT NULL,configuration_hash TEXT NOT NULL,application_version TEXT NOT NULL,schema_version INTEGER NOT NULL);
        """)
        self.db.execute("BEGIN IMMEDIATE")
        try:
            now = _now()
            for version in (1, SCHEMA_VERSION): self.db.execute("INSERT OR IGNORE INTO schema_migrations VALUES (?,?)", (version, now))
            for key in DIMENSIONS: self._set(key, self._get(key, "0") if self._get(key, "0") in ("0", "1") else "0")
            self._set("retention_days", self._get("retention_days", "180"))
            self._set("path_exclusions", self._get("path_exclusions", "\n".join(DEFAULT_EXCLUSIONS)))
            self._set("bot_patterns", self._get("bot_patterns", ""))
            self.db.execute("COMMIT")
        except Exception:
            self.db.execute("ROLLBACK")
            raise

    def _get(self, key: str, default: str) -> str:
        row = self.db.execute("SELECT value FROM settings WHERE key=?", (key,)).fetchone()
        return row["value"] if row else default

    def _set(self, key: str, value: str | int) -> None:
        self.db.execute("INSERT INTO settings(key,value) VALUES (?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value", (key, str(value)))

    def _config(self) -> dict[str, Any]:
        config: dict[str, Any] = {key: self._get(key, "0") == "1" for key in DIMENSIONS}
        raw_retention = self._get("retention_days", "180")
        config["retention_days"] = int(raw_retention) if raw_retention.isdigit() and int(raw_retention) in _VALID_RETENTION else 180
        config["path_exclusions"] = sorted(x for x in self._get("path_exclusions", "\n".join(DEFAULT_EXCLUSIONS)).splitlines() if x.startswith("/") and len(x) <= 200)
        config["bot_patterns"] = [x for x in self._get("bot_patterns", "").splitlines() if 0 < len(x) <= 200]
        config["profile"] = "extended" if any(config[key] for key in DIMENSIONS) else "strict"
        return config

    def track_page_view(self, path: str, *, user_agent: str = "", country: str = "", referrer: str = "") -> bool:
        normalized = normalize_path(path)
        config = self._config()
        if normalized is None or _excluded(normalized, config["path_exclusions"]) or is_bot(user_agent, config["bot_patterns"]): return False
        country_value = country.upper() if config["country_collection"] and re.fullmatch(r"[A-Za-z]{2}", country or "") else "XX"
        today = datetime.now(timezone.utc).date().isoformat()
        try:
            self.db.execute("BEGIN IMMEDIATE")
            self.db.execute("INSERT INTO pageviews_daily(day,path,country,views) VALUES (?,?,?,1) ON CONFLICT(day,path,country) DO UPDATE SET views=views+1", (today, normalized, country_value))
            host = _host(referrer) if config["referrer_collection"] else None
            if host:
                self.db.execute("INSERT INTO referrers_daily(day,path,referrer_host,views) VALUES (?,?,?,1) ON CONFLICT(day,path,referrer_host) DO UPDATE SET views=views+1", (today, normalized, host))
            for key, name in (("browser_collection", "browser"), ("device_collection", "device"), ("os_collection", "os")):
                if config[key]:
                    self.db.execute("INSERT INTO dimensions_daily(day,path,dimension,value,views) VALUES (?,?,?,?,1) ON CONFLICT(day,path,dimension,value) DO UPDATE SET views=views+1", (today, normalized, name, _category(user_agent, name)))
            self.db.execute("COMMIT")
            return True
        except sqlite3.Error:
            try: self.db.execute("ROLLBACK")
            except sqlite3.Error: pass
            return False

    def update_privacy(self, values: dict[str, bool], confirmation: bool = False) -> str:
        effective = {key: values.get(key) is True for key in DIMENSIONS}
        if any(effective.values()) and confirmation is not True:
            raise ValueError("Explicit acknowledgement is required to enable optional dimensions.")
        self.db.execute("BEGIN IMMEDIATE")
        try:
            for key, value in effective.items(): self._set(key, "1" if value else "0")
            self._record_history()
            self.db.execute("COMMIT")
        except Exception:
            self.db.execute("ROLLBACK")
            raise
        return self._config()["profile"]

    def return_to_strict_mode(self) -> None:
        self.update_privacy({}, False)

    def set_retention(self, days: int) -> None:
        value = days if days in _VALID_RETENTION else 180
        self._set("retention_days", value)
        self._record_history()

    def _record_history(self) -> None:
        config = self._config()
        serialized = json.dumps(config, sort_keys=True, separators=(",", ":"))
        fingerprint = sha256(json.dumps({"contract_version": CONTRACT_VERSION, "schema_version": SCHEMA_VERSION, "configuration": config}, sort_keys=True, separators=(",", ":")).encode()).hexdigest()
        self.db.execute("INSERT INTO privacy_configuration_history VALUES (?,?,?,?,?,?)", (_now(), config["profile"], serialized, fingerprint, "1.0.0", SCHEMA_VERSION))

    def dashboard(self, period_days: int = 30) -> dict[str, Any]:
        period = period_days if period_days in _VALID_RETENTION else 30
        today = datetime.now(timezone.utc).date()
        start = (today - timedelta(days=period - 1)).isoformat()
        end = today.isoformat()
        total = self.db.execute("SELECT COALESCE(SUM(views),0) AS n FROM pageviews_daily WHERE day BETWEEN ? AND ?", (start, end)).fetchone()["n"]
        by_day = [dict(x) for x in self.db.execute("SELECT day,SUM(views) AS views FROM pageviews_daily WHERE day BETWEEN ? AND ? GROUP BY day ORDER BY day", (start, end))]
        by_page = [dict(x) for x in self.db.execute("SELECT path,SUM(views) AS views FROM pageviews_daily WHERE day BETWEEN ? AND ? GROUP BY path ORDER BY views DESC,path LIMIT 500", (start, end))]
        config = self._config()
        return {"total": total, "by_day": by_day, "by_page": by_page, "retention_days": config["retention_days"], "profile": config["profile"]}

    def admin_data(self, resource: str, query: dict[str, Any] | None = None) -> dict[str, Any]:
        query = query or {}
        config = self._config()
        accepted_periods = {7, 30, 90, 180, 365}
        try: requested_period = int(query.get("period", 30))
        except (TypeError, ValueError): requested_period = 30
        period = min(requested_period if requested_period in accepted_periods else 30, config["retention_days"])
        today = datetime.now(timezone.utc).date()
        start, end = (today - timedelta(days=period - 1)).isoformat(), today.isoformat()
        bucket = query.get("bucket") if query.get("bucket") in ("day", "week", "month") else "day"
        group = {"day": "day", "week": "strftime('%Y-W%W',day)", "month": "substr(day,1,7)"}[bucket]
        try: page = max(1, min(10000, int(query.get("page", 1))))
        except (TypeError, ValueError): page = 1
        try: per_page = max(1, min(50, int(query.get("per_page", 50))))
        except (TypeError, ValueError): per_page = 50
        offset = (page - 1) * per_page
        if resource == "privacy": return config
        if resource == "dashboard":
            total = self.db.execute("SELECT COALESCE(SUM(views),0) FROM pageviews_daily WHERE day BETWEEN ? AND ?", (start, end)).fetchone()[0]
            active = self.db.execute("SELECT COUNT(DISTINCT path) FROM pageviews_daily WHERE day BETWEEN ? AND ?", (start, end)).fetchone()[0]
            timeline = [dict(x) for x in self.db.execute(f"SELECT {group} AS period,SUM(views) AS views FROM pageviews_daily WHERE day BETWEEN ? AND ? GROUP BY period ORDER BY period", (start, end))]
            top = [dict(x) for x in self.db.execute("SELECT path,SUM(views) AS views FROM pageviews_daily WHERE day BETWEEN ? AND ? GROUP BY path ORDER BY views DESC,path LIMIT 10", (start, end))]
            return {"total": total, "active_pages": active, "daily_average": round(total / period, 1), "timeline": timeline, "top_pages": top, "period": period, "bucket": bucket}
        if resource in ("pages", "daily"):
            select = "path" if resource == "pages" else "day,path"
            count_query = "SELECT COUNT(*) FROM (SELECT " + select + " FROM pageviews_daily WHERE day BETWEEN ? AND ? GROUP BY " + select + ")"
            total_rows = self.db.execute(count_query, (start, end)).fetchone()[0]
            sql = ("SELECT path,SUM(views) AS views FROM pageviews_daily WHERE day BETWEEN ? AND ? GROUP BY path ORDER BY views DESC,path" if resource == "pages" else "SELECT day,path,SUM(views) AS views FROM pageviews_daily WHERE day BETWEEN ? AND ? GROUP BY day,path ORDER BY day DESC,views DESC,path") + " LIMIT ? OFFSET ?"
            rows = [dict(x) for x in self.db.execute(sql, (start, end, per_page, offset))]
            return {"rows": rows, "total_rows": total_rows, "page": page, "per_page": per_page, "pages": (total_rows + per_page - 1) // per_page, "period": period}
        if resource == "page":
            path = normalize_path(query.get("path", ""))
            if path is None: raise ValueError("Invalid page path.")
            total = self.db.execute("SELECT COALESCE(SUM(views),0) FROM pageviews_daily WHERE path=? AND day BETWEEN ? AND ?", (path, start, end)).fetchone()[0]
            timeline = [dict(x) for x in self.db.execute(f"SELECT {group} AS period,SUM(views) AS views FROM pageviews_daily WHERE path=? AND day BETWEEN ? AND ? GROUP BY period ORDER BY period", (path, start, end))]
            return {"path": path, "total": total, "timeline": timeline, "period": period, "bucket": bucket}
        if resource == "dimensions":
            mappings = {"country": ("country_collection", "pageviews_daily", "country"), "referrer": ("referrer_collection", "referrers_daily", "referrer_host"), "browser": ("browser_collection", "dimensions_daily", "value"), "device": ("device_collection", "dimensions_daily", "value"), "os": ("os_collection", "dimensions_daily", "value")}
            dimension = query.get("dimension", "country")
            if dimension not in mappings: raise ValueError("Unsupported dimension.")
            enabled_key, table, column = mappings[dimension]
            if not config[enabled_key]: return {"dimension": dimension, "label": dimension, "enabled": False, "rows": [], "total_rows": 0, "page": 1, "pages": 1}
            where, params = ("dimension=? AND day BETWEEN ? AND ?", (dimension, start, end)) if table == "dimensions_daily" else ("day BETWEEN ? AND ?", (start, end))
            total_rows = self.db.execute(f"SELECT COUNT(*) FROM (SELECT {column} FROM {table} WHERE {where} GROUP BY {column})", params).fetchone()[0]
            rows = [dict(x) for x in self.db.execute(f"SELECT {column} AS value,SUM(views) AS views FROM {table} WHERE {where} GROUP BY {column} ORDER BY views DESC,value LIMIT ? OFFSET ?", (*params, per_page, offset))]
            return {"dimension": dimension, "label": dimension, "enabled": True, "rows": rows, "total_rows": total_rows, "page": page, "per_page": per_page, "pages": (total_rows + per_page - 1) // per_page}
        if resource == "system": return {"application": "Barelytics Python", "runtime": os.sys.version.split()[0], "schema_version": SCHEMA_VERSION, "current_schema_version": SCHEMA_VERSION, "migration_required": False, "capabilities": {"cleanup": True, "delete_all": True, "logout": False}}
        if resource == "audit": return self.audit()
        if resource == "integration": return {"instructions": "Mount the WSGI adapter behind the host application's administrator authorization and CSRF middleware.", "javascript_snippet": "from barelytics import Barelytics, create_wsgi_app"}
        raise ValueError("Unknown administration resource.")

    def update_admin_settings(self, values: dict[str, Any]) -> dict[str, Any]:
        if not isinstance(values, dict): raise ValueError("Invalid settings.")
        retention = values.get("retention_days")
        if isinstance(retention, bool) or retention not in _VALID_RETENTION: raise ValueError("Invalid retention period.")
        def parse_lines(name: str, limit: int, path_mode: bool) -> list[str]:
            raw = values.get(name, "")
            if not isinstance(raw, str) or len(raw.encode("utf-8")) > limit: raise ValueError("Invalid settings.")
            lines = sorted(set(line.strip() for line in raw.splitlines() if line.strip()))
            if any(len(line) > 200 or (path_mode and (not line.startswith("/") or "?" in line or "#" in line)) for line in lines): raise ValueError("Invalid settings.")
            return lines
        exclusions, bots = parse_lines("path_exclusions", 4000, True), parse_lines("bot_patterns", 2000, False)
        privacy = {key: values.get(key) is True for key in DIMENSIONS}
        if any(privacy.values()) and values.get("confirmation") != "yes": raise ValueError("Confirm optional dimension collection.")
        self.db.execute("BEGIN IMMEDIATE")
        try:
            for key, enabled in privacy.items(): self._set(key, "1" if enabled else "0")
            self._set("retention_days", retention); self._set("path_exclusions", "\n".join(exclusions)); self._set("bot_patterns", "\n".join(bots)); self._record_history(); self.db.execute("COMMIT")
        except Exception:
            self.db.execute("ROLLBACK"); raise
        return self._config()

    def delete_all(self) -> None:
        self.db.execute("BEGIN IMMEDIATE")
        try:
            for table in ("pageviews_daily", "referrers_daily", "dimensions_daily"): self.db.execute(f"DELETE FROM {table}")
            self.db.execute("COMMIT")
        except sqlite3.Error:
            self.db.execute("ROLLBACK"); raise

    def audit(self) -> dict[str, Any]:
        config = self._config()
        fingerprint = sha256(json.dumps({"contract_version": CONTRACT_VERSION, "schema_version": SCHEMA_VERSION, "configuration": config}, sort_keys=True, separators=(",", ":")).encode()).hexdigest()
        tables = {x["name"] for x in self.db.execute("SELECT name FROM sqlite_master WHERE type='table'")}
        forbidden = any(re.search(r"visitor|session|fingerprint|event|identity", name, re.I) for name in tables)
        checks = {
            "profile_is_known": config["profile"] in ("strict", "extended"),
            "dimensions_fail_closed": all(isinstance(config[x], bool) for x in DIMENSIONS),
            "retention_is_valid": config["retention_days"] in _VALID_RETENTION,
            "aggregate_schema": {"pageviews_daily", "schema_migrations", "privacy_configuration_history"} <= tables,
            "no_identity_or_event_tables": not forbidden,
            "no_ip_column": all("ip" not in col["name"].lower() for col in self.db.execute("PRAGMA table_info(pageviews_daily)")),
            "no_third_party_analytics": True,
            "path_normalization_active": normalize_path("/users/123456") == "/users/:id",
            "private_exclusions_active": _excluded("/admin/example", config["path_exclusions"]),
        }
        return {"application": "Barelytics Python", "contract_version": CONTRACT_VERSION, "schema_version": SCHEMA_VERSION, "profile": config["profile"], "fingerprint": fingerprint, "configuration": config, "checks": checks, "result": "PASS" if all(checks.values()) else "FAIL"}

    def cleanup(self, limit: int = 1000) -> bool:
        limit = max(1, min(1000, limit if isinstance(limit, int) else 1000))
        cutoff = (datetime.now(timezone.utc).date() - timedelta(days=self._config()["retention_days"])).isoformat()
        try:
            self.db.execute("BEGIN IMMEDIATE")
            complete = True
            for table in ("pageviews_daily", "referrers_daily", "dimensions_daily"):
                count = self.db.execute(f"SELECT COUNT(*) AS n FROM (SELECT rowid FROM {table} WHERE day<? ORDER BY day LIMIT ?)", (cutoff, limit)).fetchone()["n"]
                self.db.execute(f"DELETE FROM {table} WHERE rowid IN (SELECT rowid FROM {table} WHERE day<? ORDER BY day LIMIT ?)", (cutoff, limit))
                if count == limit: complete = False
            self._set("last_cleanup_at", _now())
            self.db.execute("COMMIT")
            return complete
        except sqlite3.Error:
            try: self.db.execute("ROLLBACK")
            except sqlite3.Error: pass
            return False

    def close(self) -> None:
        self.db.close()


def create_wsgi_app(analytics: Barelytics, *, authorize: Callable[[dict[str, Any]], bool] | None = None, verify_csrf: Callable[[dict[str, Any], str], bool] | None = None, csrf_token: Callable[[dict[str, Any]], str] | None = None, admin_path: str = "/barelytics/admin"):
    """Return a dependency-free WSGI collector. Optional admin route requires host auth + CSRF callbacks."""
    def app(environ, start_response):
        method = environ.get("REQUEST_METHOD", "GET").upper()
        route = environ.get("PATH_INFO", "/")
        if route.endswith("/track"):
            if method != "POST": return _wsgi(start_response, "405 Method Not Allowed", b"", [("Allow", "POST")])
            try: length = int(environ.get("CONTENT_LENGTH") or "0")
            except ValueError: return _wsgi(start_response, "400 Bad Request", b"")
            if length > 8192: return _wsgi(start_response, "413 Payload Too Large", b"")
            raw = environ["wsgi.input"].read(min(length, 8193))
            if len(raw) > 8192: return _wsgi(start_response, "413 Payload Too Large", b"")
            try: payload = json.loads(raw.decode("utf-8"))
            except (ValueError, UnicodeDecodeError): return _wsgi(start_response, "400 Bad Request", b"")
            if not isinstance(payload, dict) or set(payload) != {"path"} or not isinstance(payload["path"], str) or normalize_path(payload["path"]) is None:
                return _wsgi(start_response, "400 Bad Request", b"")
            analytics.track_page_view(payload["path"], user_agent=environ.get("HTTP_USER_AGENT", ""))
            return _wsgi(start_response, "204 No Content", b"")
        mount_path = admin_path.rstrip("/") or "/barelytics/admin"
        script_name = environ.get("SCRIPT_NAME", "").rstrip("/")
        if route == mount_path or route.startswith(mount_path + "/"):
            admin_suffix = route[len(mount_path):]
        elif script_name == mount_path:
            admin_suffix = route or "/"
        else:
            admin_suffix = None
        if admin_suffix is not None:
            if authorize is None or verify_csrf is None or csrf_token is None or not authorize(environ): return _wsgi(start_response, "403 Forbidden", b"")
            suffix = admin_suffix
            if method == "GET" and suffix in ("", "/", "/index.html"):
                if suffix == "":
                    payload = json.dumps({"ok": True, "data": {"dashboard": analytics.dashboard(), "audit": analytics.audit()}, "error": None}).encode()
                    return _wsgi(start_response, "200 OK", payload, [("Content-Type", "application/json; charset=utf-8"), ("Cache-Control", "no-store")])
                ui_root = Path(__file__).resolve().parent / "admin-ui"
                if not ui_root.is_dir(): ui_root = Path(__file__).resolve().parents[3] / "public/barelytics/admin-ui"
                try: content = (ui_root / "index.html").read_bytes()
                except OSError: return _wsgi(start_response, "503 Service Unavailable", b"")
                return _wsgi(start_response, "200 OK", content, [("Content-Type", "text/html; charset=utf-8"), ("Cache-Control", "no-store")])
            if method == "GET" and suffix.startswith("/admin-ui/"):
                name = suffix.rsplit("/", 1)[-1]
                if name not in ("app.js", "admin.css", "config.js", "brand-mark.png"): return _wsgi(start_response, "404 Not Found", b"")
                ui_root = Path(__file__).resolve().parent / "admin-ui"
                if not ui_root.is_dir(): ui_root = Path(__file__).resolve().parents[3] / "public/barelytics/admin-ui"
                try: content = (ui_root / name).read_bytes()
                except OSError: return _wsgi(start_response, "503 Service Unavailable", b"")
                if name == "config.js": content = b'window.BARELYTICS_ADMIN_CONFIG = { apiBase: "api/" };'
                mime = "text/javascript; charset=utf-8" if name.endswith(".js") else "image/png" if name.endswith(".png") else "text/css; charset=utf-8"
                return _wsgi(start_response, "200 OK", content, [("Content-Type", mime), ("Cache-Control", "no-store")])
            if suffix.startswith("/api/"):
                resource = suffix[len("/api/"):].strip("/")
                params = {key: values[-1] for key, values in parse_qs(environ.get("QUERY_STRING", ""), keep_blank_values=True).items()}
                def respond(status: str, data: Any = None, code: str | None = None, message: str | None = None):
                    ok = status.startswith("2")
                    payload = {"ok": ok, "data": data if ok else None, "error": None if ok else {"code": code or "request_failed", "message": message or "The administration request failed."}}
                    return _wsgi(start_response, status, json.dumps(payload, ensure_ascii=False).encode(), [("Content-Type", "application/json; charset=utf-8"), ("Cache-Control", "no-store")])
                try:
                    if method == "GET":
                        data = {"authenticated": True, "csrf": csrf_token(environ) if csrf_token else "", "retention_days": analytics.admin_data("privacy")["retention_days"], "capabilities": {"logout": False, "delete_all": True}} if resource == "session" else analytics.admin_data(resource, params)
                        return respond("200 OK", data)
                    if method != "POST": return respond("405 Method Not Allowed", code="method_not_allowed", message="Method not allowed.")
                    length = int(environ.get("CONTENT_LENGTH") or "0")
                    if length > 8192: return respond("413 Payload Too Large", code="too_large", message="Request is too large.")
                    body = json.loads(environ["wsgi.input"].read(length).decode("utf-8"))
                    if not isinstance(body, dict) or not verify_csrf(environ, body.get("csrf", "")): return respond("403 Forbidden", code="csrf", message="Request verification failed.")
                    if resource == "privacy": data = analytics.update_admin_settings(body)
                    elif resource == "strict": analytics.return_to_strict_mode(); data = analytics._config()
                    elif resource == "cleanup": data = {"complete": analytics.cleanup()}
                    elif resource == "delete-all" and body.get("confirmation") == "DELETE": analytics.delete_all(); data = {"deleted": True}
                    else: return respond("400 Bad Request", code="invalid_action", message="The administration action is not supported.")
                    return respond("200 OK", data)
                except (ValueError, TypeError, KeyError, json.JSONDecodeError): return respond("400 Bad Request", code="invalid_request", message="The request is invalid.")
                except Exception: return respond("500 Internal Server Error", code="internal_error", message="The administration request failed.")
            if method == "GET": return _wsgi(start_response, "404 Not Found", b"")
            if method != "POST": return _wsgi(start_response, "405 Method Not Allowed", b"", [("Allow", "GET, POST")])
            try:
                length = int(environ.get("CONTENT_LENGTH") or "0")
                if length > 8192: return _wsgi(start_response, "413 Payload Too Large", b"")
                body = json.loads(environ["wsgi.input"].read(length).decode("utf-8"))
                if not verify_csrf(environ, body.get("csrf", "")): return _wsgi(start_response, "403 Forbidden", b"")
                if body.get("action") == "strict": analytics.return_to_strict_mode()
                elif body.get("action") == "privacy": analytics.update_privacy(body.get("values", {}), body.get("confirmation") is True)
                elif body.get("action") == "cleanup": analytics.cleanup()
                else: return _wsgi(start_response, "400 Bad Request", b"")
                return _wsgi(start_response, "204 No Content", b"")
            except (ValueError, TypeError, KeyError): return _wsgi(start_response, "400 Bad Request", b"")
        return _wsgi(start_response, "404 Not Found", b"")
    return app


def _wsgi(start_response, status: str, body: bytes, headers: list[tuple[str, str]] | None = None):
    start_response(status, [("Content-Length", str(len(body))), ("X-Content-Type-Options", "nosniff"), ("Referrer-Policy", "no-referrer"), ("Content-Security-Policy", "default-src 'self'; script-src 'self'; style-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'"), *(headers or [])])
    return [body]


def _now() -> str:
    return datetime.now(timezone.utc).isoformat(timespec="seconds")


__all__ = ["Barelytics", "CONTRACT_VERSION", "SCHEMA_VERSION", "DIMENSIONS", "DEFAULT_EXCLUSIONS", "DEFAULT_BOTS", "normalize_path", "is_bot", "create_wsgi_app"]
