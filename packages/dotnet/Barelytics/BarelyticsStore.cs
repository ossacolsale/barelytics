using System.Net;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using System.Text.RegularExpressions;
using Microsoft.Data.Sqlite;

namespace Barelytics;

public sealed record BarelyticsOptions(string DataDirectory, int RetentionDays = 180, IReadOnlyList<string>? PathExclusions = null, IReadOnlyList<string>? BotPatterns = null);
public sealed record PrivacyConfiguration(bool CountryCollection, bool ReferrerCollection, bool BrowserCollection, bool DeviceCollection, bool OsCollection, int RetentionDays, string Profile, IReadOnlyList<string> PathExclusions, IReadOnlyList<string> BotPatterns);
public sealed record AuditReport(string Application, int ContractVersion, int SchemaVersion, string Profile, string Fingerprint, PrivacyConfiguration Configuration, IReadOnlyDictionary<string, bool> Checks, string Result);

/// <summary>SQLite reference-style runtime store. Register one instance per application.</summary>
public sealed class BarelyticsStore : IDisposable
{
    public const int ContractVersion = 1;
    public const int SchemaVersion = 2;
    public static readonly string[] Dimensions = ["country_collection", "referrer_collection", "browser_collection", "device_collection", "os_collection"];
    public static readonly string[] DefaultExclusions = ["/admin/*", "/admin.php", "/account/*", "/checkout/*", "/customer/*", "/patient/*", "/profile/*", "/private/*"];
    private static readonly string[] DefaultBots = ["bot", "crawler", "spider", "slurp", "bingpreview", "headless", "lighthouse", "pagespeed", "semrush", "ahrefsbot", "mj12bot", "dotbot", "facebookexternalhit", "twitterbot", "linkedinbot", "discordbot", "telegrambot", "whatsapp", "petalbot", "yandex", "baiduspider", "bytespider", "duckduckbot", "applebot", "googlebot", "bingbot"];
    private static readonly HashSet<int> Retentions = [30, 90, 180, 365];
    private static readonly Regex Ids = new(@"(?<=/)(?:[0-9]{6,}|[0-9A-HJKMNP-TV-Z]{26}|[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}|[0-9a-f]{16,}|[A-Za-z0-9_-]{32,})(?=/|$)", RegexOptions.IgnoreCase | RegexOptions.CultureInvariant | RegexOptions.Compiled);
    private readonly SqliteConnection _db;
    private readonly object _gate = new();
    public string DatabasePath { get; }

    public BarelyticsStore(BarelyticsOptions options)
    {
        var directory = Path.GetFullPath(options.DataDirectory);
        Directory.CreateDirectory(directory);
        DatabasePath = Path.Combine(directory, "analytics.sqlite");
        _db = new SqliteConnection(new SqliteConnectionStringBuilder { DataSource = DatabasePath, Mode = SqliteOpenMode.ReadWriteCreate, DefaultTimeout = 1 }.ToString());
        _db.Open();
        Exec("PRAGMA busy_timeout=1000");
        try { using var command = _db.CreateCommand(); command.CommandText = "PRAGMA journal_mode=WAL"; command.ExecuteScalar(); } catch (SqliteException) { }
        try { if (!OperatingSystem.IsWindows()) File.SetUnixFileMode(DatabasePath, UnixFileMode.UserRead | UnixFileMode.UserWrite); } catch (Exception) { }
        Migrate();
        if (options.PathExclusions is not null) Set("path_exclusions", string.Join('\n', options.PathExclusions.Where(ValidPattern).Distinct().Order()));
        if (options.BotPatterns is not null) Set("bot_patterns", string.Join('\n', options.BotPatterns.Where(x => x.Length is > 0 and <= 200).Distinct()));
        SetRetention(options.RetentionDays);
    }

    public static string? NormalizePath(string? path)
    {
        if (string.IsNullOrEmpty(path) || Encoding.UTF8.GetByteCount(path) > 512 || path.Any(c => c < 0x20 || c == 0x7f) || !path.StartsWith('/') || path.StartsWith("//") || path.Contains('?') || path.Contains('#')) return null;
        path = Regex.Replace(path, "/{2,}", "/");
        if (Encoding.UTF8.GetByteCount(path) > 512 || Regex.IsMatch(path, @"%(?![0-9a-f]{2})", RegexOptions.IgnoreCase)) return null;
        string decoded;
        try { decoded = Uri.UnescapeDataString(path); } catch (UriFormatException) { return null; }
        if (decoded.Any(c => c < 0x20 || c == 0x7f) || decoded.IndexOfAny(['@', '?', '#']) >= 0) return null;
        if (!string.Equals(decoded, path, StringComparison.Ordinal) && Ids.IsMatch(decoded)) return null;
        return Ids.Replace(path, ":id");
    }

    public static bool IsBot(string? userAgent, IEnumerable<string>? additional = null)
    {
        var ua = userAgent ?? string.Empty;
        return DefaultBots.Concat(additional ?? []).Any(pattern => pattern.Length > 0 && ua.Contains(pattern, StringComparison.OrdinalIgnoreCase));
    }

    public bool TrackPageView(string? path, string? userAgent = null, string? country = null, string? referrer = null)
    {
        var normalized = NormalizePath(path);
        var config = Configuration();
        if (normalized is null || Excluded(normalized, config.PathExclusions) || IsBot(userAgent, config.BotPatterns)) return false;
        var countryValue = config.CountryCollection && Regex.IsMatch(country ?? "", "^[A-Za-z]{2}$") ? country!.ToUpperInvariant() : "XX";
        lock (_gate)
        {
            using var transaction = _db.BeginTransaction(deferred: false);
            try
            {
                Execute(transaction, "INSERT INTO pageviews_daily(day,path,country,views) VALUES ($day,$path,$country,1) ON CONFLICT(day,path,country) DO UPDATE SET views=views+1", ("$day", UtcDay()), ("$path", normalized), ("$country", countryValue));
                if (config.ReferrerCollection && Host(referrer) is { } host) Execute(transaction, "INSERT INTO referrers_daily(day,path,referrer_host,views) VALUES ($day,$path,$host,1) ON CONFLICT(day,path,referrer_host) DO UPDATE SET views=views+1", ("$day", UtcDay()), ("$path", normalized), ("$host", host));
                var ua = userAgent ?? "";
                foreach (var (enabled, dimension) in new[] { (config.BrowserCollection, "browser"), (config.DeviceCollection, "device"), (config.OsCollection, "os") })
                    if (enabled) Execute(transaction, "INSERT INTO dimensions_daily(day,path,dimension,value,views) VALUES ($day,$path,$dimension,$value,1) ON CONFLICT(day,path,dimension,value) DO UPDATE SET views=views+1", ("$day", UtcDay()), ("$path", normalized), ("$dimension", dimension), ("$value", Category(ua, dimension)));
                transaction.Commit(); return true;
            }
            catch (SqliteException) { transaction.Rollback(); return false; }
        }
    }

    public string UpdatePrivacy(IReadOnlyDictionary<string, bool> values, bool acknowledged)
    {
        var enabled = Dimensions.ToDictionary(k => k, k => values.TryGetValue(k, out var value) && value);
        if (enabled.Values.Any(x => x) && !acknowledged) throw new InvalidOperationException("Explicit acknowledgement is required to enable optional dimensions.");
        lock (_gate)
        {
            using var tx = _db.BeginTransaction(deferred: false);
            try { foreach (var (key, value) in enabled) Set(tx, key, value ? "1" : "0"); RecordHistory(tx); tx.Commit(); }
            catch { tx.Rollback(); throw; }
        }
        return Configuration().Profile;
    }

    public void ReturnToStrictMode() => UpdatePrivacy(new Dictionary<string, bool>(), false);

    public void SetRetention(int days)
    {
        var value = Retentions.Contains(days) ? days : 180;
        lock (_gate) { Set("retention_days", value.ToString()); using var tx = _db.BeginTransaction(); RecordHistory(tx); tx.Commit(); }
    }

    public (long Total, IReadOnlyList<(string Day, long Views)> ByDay, IReadOnlyList<(string Path, long Views)> ByPage, string Profile) Dashboard(int periodDays = 30)
    {
        var period = Retentions.Contains(periodDays) ? periodDays : 30;
        var from = DateTime.UtcNow.Date.AddDays(-(period - 1)).ToString("yyyy-MM-dd");
        var to = UtcDay();
        lock (_gate)
        {
            var total = Convert.ToInt64(Scalar("SELECT COALESCE(SUM(views),0) FROM pageviews_daily WHERE day BETWEEN $from AND $to", ("$from", from), ("$to", to)));
            var byDay = ReadPair("SELECT day,SUM(views) FROM pageviews_daily WHERE day BETWEEN $from AND $to GROUP BY day ORDER BY day", "$from", from, "$to", to);
            var byPage = ReadPair("SELECT path,SUM(views) FROM pageviews_daily WHERE day BETWEEN $from AND $to GROUP BY path ORDER BY SUM(views) DESC,path LIMIT 500", "$from", from, "$to", to);
            return (total, byDay, byPage, Configuration().Profile);
        }
    }

    public AuditReport Audit()
    {
        lock (_gate)
        {
            var config = Configuration();
            var tables = new HashSet<string>(StringComparer.Ordinal);
            using (var cmd = _db.CreateCommand()) { cmd.CommandText = "SELECT name FROM sqlite_master WHERE type='table'"; using var reader = cmd.ExecuteReader(); while (reader.Read()) tables.Add(reader.GetString(0)); }
            var forbidden = tables.Any(x => Regex.IsMatch(x, "visitor|session|fingerprint|event|identity", RegexOptions.IgnoreCase));
            var ipColumn = false;
            using (var cmd = _db.CreateCommand()) { cmd.CommandText = "PRAGMA table_info(pageviews_daily)"; using var reader = cmd.ExecuteReader(); while (reader.Read()) if (reader.GetString(1).Contains("ip", StringComparison.OrdinalIgnoreCase)) ipColumn = true; }
            var checks = new Dictionary<string, bool> {
                ["profile_is_known"] = config.Profile is "strict" or "extended", ["dimensions_fail_closed"] = true,
                ["retention_is_valid"] = Retentions.Contains(config.RetentionDays), ["aggregate_schema"] = new[] { "pageviews_daily", "schema_migrations", "privacy_configuration_history" }.All(tables.Contains),
                ["no_identity_or_event_tables"] = !forbidden, ["no_ip_column"] = !ipColumn, ["no_third_party_analytics"] = true,
                ["path_normalization_active"] = NormalizePath("/users/123456") == "/users/:id", ["private_exclusions_active"] = Excluded("/admin/example", config.PathExclusions)
            };
            var fingerprint = Fingerprint(config);
            return new("Barelytics .NET", ContractVersion, SchemaVersion, config.Profile, fingerprint, config, checks, checks.Values.All(x => x) ? "PASS" : "FAIL");
        }
    }

    public bool Cleanup(int limit = 1000)
    {
        var bound = Math.Clamp(limit, 1, 1000);
        var cutoff = DateTime.UtcNow.Date.AddDays(-Configuration().RetentionDays).ToString("yyyy-MM-dd");
        lock (_gate)
        {
            using var tx = _db.BeginTransaction(deferred: false);
            try
            {
                var complete = true;
                foreach (var table in new[] { "pageviews_daily", "referrers_daily", "dimensions_daily" })
                {
                    var n = Convert.ToInt64(Scalar(tx, $"SELECT COUNT(*) FROM (SELECT rowid FROM {table} WHERE day<$cutoff ORDER BY day LIMIT $limit)", ("$cutoff", cutoff), ("$limit", bound)));
                    Execute(tx, $"DELETE FROM {table} WHERE rowid IN (SELECT rowid FROM {table} WHERE day<$cutoff ORDER BY day LIMIT $limit)", ("$cutoff", cutoff), ("$limit", bound));
                    if (n == bound) complete = false;
                }
                Set(tx, "last_cleanup_at", DateTime.UtcNow.ToString("O")); tx.Commit(); return complete;
            }
            catch (SqliteException) { tx.Rollback(); return false; }
        }
    }

    private void Migrate()
    {
        Exec("CREATE TABLE IF NOT EXISTS schema_migrations(version INTEGER PRIMARY KEY,applied_at TEXT NOT NULL); CREATE TABLE IF NOT EXISTS settings(key TEXT PRIMARY KEY,value TEXT NOT NULL); CREATE TABLE IF NOT EXISTS pageviews_daily(day TEXT NOT NULL,path TEXT NOT NULL,country TEXT NOT NULL DEFAULT 'XX',views INTEGER NOT NULL DEFAULT 0,PRIMARY KEY(day,path,country)); CREATE TABLE IF NOT EXISTS referrers_daily(day TEXT NOT NULL,path TEXT NOT NULL,referrer_host TEXT NOT NULL,views INTEGER NOT NULL DEFAULT 0,PRIMARY KEY(day,path,referrer_host)); CREATE TABLE IF NOT EXISTS dimensions_daily(day TEXT NOT NULL,path TEXT NOT NULL,dimension TEXT NOT NULL,value TEXT NOT NULL,views INTEGER NOT NULL DEFAULT 0,PRIMARY KEY(day,path,dimension,value)); CREATE TABLE IF NOT EXISTS privacy_configuration_history(timestamp TEXT NOT NULL,profile TEXT NOT NULL,effective_configuration_json TEXT NOT NULL,configuration_hash TEXT NOT NULL,application_version TEXT NOT NULL,schema_version INTEGER NOT NULL)");
        lock (_gate)
        {
            using var tx = _db.BeginTransaction(deferred: false);
            try
            {
                foreach (var version in new[] { 1, SchemaVersion }) Execute(tx, "INSERT OR IGNORE INTO schema_migrations(version,applied_at) VALUES ($version,$at)", ("$version", version), ("$at", DateTime.UtcNow.ToString("O")));
                foreach (var key in Dimensions) if (Get(tx, key, "0") is not ("0" or "1")) Set(tx, key, "0");
                Set(tx, "retention_days", Get(tx, "retention_days", "180")); Set(tx, "path_exclusions", Get(tx, "path_exclusions", string.Join('\n', DefaultExclusions))); Set(tx, "bot_patterns", Get(tx, "bot_patterns", ""));
                tx.Commit();
            }
            catch { tx.Rollback(); throw; }
        }
    }

    public PrivacyConfiguration Configuration(SqliteTransaction? transaction = null)
    {
        lock (_gate)
        {
            var flags = Dimensions.ToDictionary(k => k, k => Get(transaction, k, "0") == "1");
            var retentionText = Get(transaction, "retention_days", "180");
            var retention = int.TryParse(retentionText, out var parsed) && Retentions.Contains(parsed) ? parsed : 180;
            var paths = Get(transaction, "path_exclusions", string.Join('\n', DefaultExclusions)).Split('\n').Where(ValidPattern).Distinct().Order().ToArray();
            var bots = Get(transaction, "bot_patterns", "").Split('\n').Where(x => x.Length is > 0 and <= 200).ToArray();
            return new(flags[Dimensions[0]], flags[Dimensions[1]], flags[Dimensions[2]], flags[Dimensions[3]], flags[Dimensions[4]], retention, flags.Values.Any(x => x) ? "extended" : "strict", paths, bots);
        }
    }

    private string Fingerprint(PrivacyConfiguration config) => Convert.ToHexString(SHA256.HashData(JsonSerializer.SerializeToUtf8Bytes(new SortedDictionary<string, object?> { ["contract_version"] = ContractVersion, ["schema_version"] = SchemaVersion, ["configuration"] = config }))).ToLowerInvariant();
    private void RecordHistory(SqliteTransaction tx)
    {
        var config = Configuration(tx); var json = JsonSerializer.Serialize(config); var fingerprint = Fingerprint(config);
        Execute(tx, "INSERT INTO privacy_configuration_history VALUES ($at,$profile,$config,$hash,'1.0.0',$schema)", ("$at", DateTime.UtcNow.ToString("O")), ("$profile", config.Profile), ("$config", json), ("$hash", fingerprint), ("$schema", SchemaVersion));
    }
    private string Get(string key, string fallback) => ToSetting(Scalar("SELECT value FROM settings WHERE key=$key", ("$key", key)), fallback);
    private string Get(SqliteTransaction? tx, string key, string fallback) => ToSetting(tx is null ? Scalar("SELECT value FROM settings WHERE key=$key", ("$key", key)) : Scalar(tx, "SELECT value FROM settings WHERE key=$key", ("$key", key)), fallback);
    private static string ToSetting(object? value, string fallback) => value is null or DBNull ? fallback : Convert.ToString(value) ?? fallback;
    private void Set(string key, string value) { using var cmd = _db.CreateCommand(); cmd.CommandText = "INSERT INTO settings(key,value) VALUES ($key,$value) ON CONFLICT(key) DO UPDATE SET value=excluded.value"; cmd.Parameters.AddWithValue("$key", key); cmd.Parameters.AddWithValue("$value", value); cmd.ExecuteNonQuery(); }
    private void Set(SqliteTransaction tx, string key, string value) => Execute(tx, "INSERT INTO settings(key,value) VALUES ($key,$value) ON CONFLICT(key) DO UPDATE SET value=excluded.value", ("$key", key), ("$value", value));
    private object? Scalar(string sql, params (string Name, object Value)[] args) { using var cmd = _db.CreateCommand(); cmd.CommandText = sql; foreach (var (name, value) in args) cmd.Parameters.AddWithValue(name, value); return cmd.ExecuteScalar(); }
    private object? Scalar(SqliteTransaction tx, string sql, params (string Name, object Value)[] args) { using var cmd = _db.CreateCommand(); cmd.Transaction = tx; cmd.CommandText = sql; foreach (var (name, value) in args) cmd.Parameters.AddWithValue(name, value); return cmd.ExecuteScalar(); }
    private void Exec(string sql) { using var cmd = _db.CreateCommand(); cmd.CommandText = sql; cmd.ExecuteNonQuery(); }
    private static void Execute(SqliteTransaction tx, string sql, params (string Name, object Value)[] args) { using var cmd = tx.Connection!.CreateCommand(); cmd.Transaction = tx; cmd.CommandText = sql; foreach (var (name, value) in args) cmd.Parameters.AddWithValue(name, value); cmd.ExecuteNonQuery(); }
    private List<(string, long)> ReadPair(string sql, string p1, string v1, string p2, string v2) { using var cmd = _db.CreateCommand(); cmd.CommandText = sql; cmd.Parameters.AddWithValue(p1, v1); cmd.Parameters.AddWithValue(p2, v2); using var r = cmd.ExecuteReader(); var rows = new List<(string, long)>(); while (r.Read()) rows.Add((r.GetString(0), r.GetInt64(1))); return rows; }
    private static string UtcDay() => DateTime.UtcNow.ToString("yyyy-MM-dd");
    private static bool ValidPattern(string x) => x.StartsWith('/') && x.Length <= 200 && !x.Contains('?') && !x.Contains('#');
    private static bool Excluded(string path, IReadOnlyList<string> patterns) => patterns.Any(p => p.EndsWith("/*") ? path == p[..^2] || path.StartsWith(p[..^1], StringComparison.Ordinal) : path == p);
    private static string Category(string ua, string kind)
    {
        if (kind == "browser") return Regex.IsMatch(ua, "Firefox/", RegexOptions.IgnoreCase) ? "Firefox" : Regex.IsMatch(ua, "Edg/|Edge/", RegexOptions.IgnoreCase) ? "Edge" : Regex.IsMatch(ua, "Chrome/", RegexOptions.IgnoreCase) ? "Chrome" : Regex.IsMatch(ua, "Safari/", RegexOptions.IgnoreCase) ? "Safari" : "Other";
        if (kind == "device") return Regex.IsMatch(ua, "iPad|Tablet", RegexOptions.IgnoreCase) ? "Tablet" : Regex.IsMatch(ua, "Mobile|Android|iPhone", RegexOptions.IgnoreCase) ? "Mobile" : "Desktop";
        foreach (var (pattern, label) in new[] { ("Windows", "Windows"), ("Android", "Android"), ("iPhone|iPad|iOS", "iOS"), ("Mac OS", "macOS"), ("Linux", "Linux") }) if (Regex.IsMatch(ua, pattern, RegexOptions.IgnoreCase)) return label;
        return "Other";
    }
    private static string? Host(string? value)
    {
        if (!Uri.TryCreate(value, UriKind.Absolute, out var uri) || uri.Scheme is not ("http" or "https") || !string.IsNullOrEmpty(uri.UserInfo) || IPAddress.TryParse(uri.Host, out _)) return null;
        var host = uri.Host.TrimEnd('.').ToLowerInvariant(); return Regex.IsMatch(host, @"^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$", RegexOptions.IgnoreCase) ? host : null;
    }
    public void Dispose() => _db.Dispose();
}
