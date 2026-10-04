"""Native, self-hosted aggregate analytics for Python web applications."""
from __future__ import annotations

from datetime import date, datetime, timedelta, timezone
from hashlib import sha256
from ipaddress import ip_address
from pathlib import Path
from urllib.parse import unquote_to_bytes, urlsplit
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


def create_wsgi_app(analytics: Barelytics, *, authorize: Callable[[dict[str, Any]], bool] | None = None, verify_csrf: Callable[[dict[str, Any], str], bool] | None = None):
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
        if route.endswith("/admin"):
            if authorize is None or verify_csrf is None or not authorize(environ): return _wsgi(start_response, "403 Forbidden", b"")
            if method == "GET": return _wsgi(start_response, "200 OK", json.dumps({"dashboard": analytics.dashboard(), "audit": analytics.audit()}).encode(), [("Content-Type", "application/json; charset=utf-8"), ("Cache-Control", "no-store")])
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
    start_response(status, [("Content-Length", str(len(body))), *(headers or [])])
    return [body]


def _now() -> str:
    return datetime.now(timezone.utc).isoformat(timespec="seconds")


__all__ = ["Barelytics", "CONTRACT_VERSION", "SCHEMA_VERSION", "DIMENSIONS", "DEFAULT_EXCLUSIONS", "DEFAULT_BOTS", "normalize_path", "is_bot", "create_wsgi_app"]
