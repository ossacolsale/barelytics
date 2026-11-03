using System.Text.Json;
using System.Security.Claims;
using System.Text.Encodings.Web;
using System.Net.Http.Json;
using Barelytics;
using Microsoft.AspNetCore.Builder;
using Microsoft.AspNetCore.Authentication;
using Microsoft.AspNetCore.Antiforgery;
using Microsoft.AspNetCore.DataProtection;
using Microsoft.AspNetCore.Http;
using Microsoft.AspNetCore.TestHost;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.Logging;
using Microsoft.Data.Sqlite;
using Microsoft.Extensions.Options;

var root = Path.GetFullPath(Path.Combine(AppContext.BaseDirectory, "../../../../../../../"));
var vectors = JsonDocument.Parse(File.ReadAllText(Path.Combine(root, "spec/test-vectors/path-normalization.json"))).RootElement;
foreach (var vector in vectors.EnumerateArray())
{
    var inputNode = vector.GetProperty("input");
    var input = inputNode.ValueKind == JsonValueKind.Object ? inputNode.GetProperty("prefix").GetString() + string.Concat(Enumerable.Repeat(inputNode.GetProperty("repeat").GetString(), inputNode.GetProperty("count").GetInt32())) : inputNode.GetString();
    var expected = vector.GetProperty("expected").ValueKind == JsonValueKind.Null ? null : vector.GetProperty("expected").GetString();
    if (BarelyticsStore.NormalizePath(input) != expected) throw new Exception($"Path vector failed: {input}");
}
var botVectors = JsonDocument.Parse(File.ReadAllText(Path.Combine(root, "spec/test-vectors/bot-filtering.json"))).RootElement;
foreach (var vector in botVectors.EnumerateArray())
{
    var extra = vector.TryGetProperty("additional_patterns", out var patterns) ? patterns.EnumerateArray().Select(x => x.GetString()!).ToArray() : [];
    if (BarelyticsStore.IsBot(vector.GetProperty("user_agent").GetString(), extra) != vector.GetProperty("bot").GetBoolean()) throw new Exception("Bot vector failed.");
}
foreach (var v in JsonDocument.Parse(File.ReadAllText(Path.Combine(root, "spec/test-vectors/automation.json"))).RootElement.EnumerateArray())
{
    var extra = v.TryGetProperty("additional_patterns", out var patterns) ? patterns.EnumerateArray().Select(x => x.GetString()!).ToArray() : [];
    var actual = AutomationClassifier.Classify(v.GetProperty("user_agent").GetString(), extra);
    if (actual.Category != v.GetProperty("category").GetString() || actual.Family != v.GetProperty("family").GetString() || actual.Identity != v.GetProperty("identity").GetString() || actual.Automation != v.GetProperty("automation").GetBoolean() || actual.Verified) throw new Exception("Automation vector failed.");
}
var data = Path.Combine(Path.GetTempPath(), "barelytics-dotnet-" + Guid.NewGuid().ToString("N"));
try
{
    using var analytics = new BarelyticsStore(new BarelyticsOptions(data));
    if (analytics.Audit().Profile != "strict" || analytics.Audit().Result != "PASS") throw new Exception("Default audit failed: " + JsonSerializer.Serialize(analytics.Audit()));
    if (!analytics.TrackPageView("/article") || analytics.TrackPageView("/private/data") || analytics.TrackPageView("/article", "Googlebot")) throw new Exception("Strict collector behavior failed.");
    if (analytics.Dashboard().Total != 1 || analytics.Dashboard().ByPage.Single().Path != "/article") throw new Exception("Aggregate dashboard failed.");
    if ((long)analytics.AdminData("dashboard", new Dictionary<string, string> { ["period"] = "30", ["bucket"] = "day" })["active_pages"]! != 1) throw new Exception("Admin overview contract failed.");
    if ((bool)analytics.AdminData("dimensions", new Dictionary<string, string> { ["dimension"] = "country" })["enabled"]!) throw new Exception("Disabled dimension state was not reported.");
    try { analytics.UpdatePrivacy(new Dictionary<string, bool> { ["country_collection"] = true }, false); throw new Exception("Extended mode did not require confirmation."); } catch (InvalidOperationException) { }
    analytics.UpdatePrivacy(new Dictionary<string, bool> { ["country_collection"] = true, ["referrer_collection"] = true, ["browser_collection"] = true, ["device_collection"] = true, ["os_collection"] = true }, true);
    if (analytics.Audit().Profile != "extended") throw new Exception("Extended profile failed.");
    if (!analytics.TrackPageView("/extended", "Mozilla/5.0 Chrome/124 Windows", "it", "https://example.test/article")) throw new Exception("Extended aggregate write failed.");
    using (var dimensions = new SqliteConnection($"Data Source={analytics.DatabasePath}"))
    {
        dimensions.Open();
        using var count = dimensions.CreateCommand();
        count.CommandText = "SELECT COUNT(*) FROM dimensions_daily WHERE path='/extended'";
        if (Convert.ToInt32(count.ExecuteScalar()) != 3) throw new Exception("Optional dimensions were not written.");
        count.CommandText = "SELECT COUNT(*) FROM referrers_daily WHERE path='/extended'";
        if (Convert.ToInt32(count.ExecuteScalar()) != 1) throw new Exception("Referrer host aggregate was not written.");
        count.CommandText = "SELECT country FROM pageviews_daily WHERE path='/extended'";
        if (Convert.ToString(count.ExecuteScalar()) != "IT") throw new Exception("Country aggregate was not written.");
        count.CommandText = "CREATE TRIGGER fail_dimension BEFORE INSERT ON dimensions_daily BEGIN SELECT RAISE(ABORT, 'fixture'); END"; count.ExecuteNonQuery();
        if (analytics.TrackPageView("/interrupted")) throw new Exception("Failed dimension write was accepted.");
        count.CommandText = "SELECT COUNT(*) FROM pageviews_daily WHERE path='/interrupted'";
        if (Convert.ToInt32(count.ExecuteScalar()) != 0) throw new Exception("Interrupted aggregate was partially committed.");
        count.CommandText = "DROP TRIGGER fail_dimension"; count.ExecuteNonQuery();

    }
    analytics.TrackPageView("/automation", "GPTBot/1");
    analytics.TrackPageView("/private/bot", "GPTBot/1");
    if ((long)analytics.AdminData("automation", new Dictionary<string,string>())["total"]! != 2) throw new Exception("Separate automation aggregation failed.");
    analytics.TrackPageView("/one", "Mozilla/5.0 Firefox/1 Windows", "it", "https://one.test/private?q=secret");
    analytics.TrackPageView("/two", "Mozilla/5.0 Chrome/1 Android Mobile", "us", "https://two.test/private?q=secret");
    foreach (var (dimension, expected) in new[] { ("country", "IT"), ("referrer", "one.test"), ("browser", "Firefox"), ("device", "Desktop"), ("os", "Windows") })
    {
        var filtered = analytics.AdminData("dimensions", new Dictionary<string,string> { ["dimension"] = dimension, ["path"] = "/one" });
        var rows = (List<Dictionary<string,object?>>)filtered["rows"]!;
        if (rows.Count != 1 || (string)rows[0]["value"]! != expected || (long)rows[0]["views"]! != 1) throw new Exception("Page dimension isolation failed.");
    }
    analytics.ReturnToStrictMode();
    if (!analytics.Audit().HistoricalDimensions.SequenceEqual(new[] { "country", "referrer", "browser", "device", "os" })) throw new Exception("Historical aggregates were confused with current collection.");

    analytics.SetRetention(30);
    using (var raw = new SqliteConnection($"Data Source={analytics.DatabasePath}"))
    {
        raw.Open();
        using var insert = raw.CreateCommand();
        insert.CommandText = "INSERT INTO pageviews_daily(day,path,country,views) VALUES ('2000-01-01','/expired','XX',4); UPDATE settings SET value='invalid-cleanup-fixture' WHERE key='last_cleanup_at'";
        insert.ExecuteNonQuery();
    }
    if (analytics.Audit().Profile != "strict" || !analytics.TrackPageView("/automatic-cleanup")) throw new Exception("Strict reset or cleanup failed.");
    using (var raw = new SqliteConnection($"Data Source={analytics.DatabasePath}"))
    {
        raw.Open();
        using var count = raw.CreateCommand();
        count.CommandText = "SELECT COUNT(*) FROM pageviews_daily WHERE path='/expired'";
        if (Convert.ToInt32(count.ExecuteScalar()) != 0) throw new Exception("Retention failed to delete expired aggregates.");
        count.CommandText = "SELECT COUNT(*) FROM schema_migrations";
        if (Convert.ToInt32(count.ExecuteScalar()) != 3) throw new Exception("Migration versions were not applied exactly once.");
    }
    using (var migratedAgain = new BarelyticsStore(new BarelyticsOptions(data)))
        if (migratedAgain.Audit().SchemaVersion != 3) throw new Exception("Migration is not repeatable.");
}
finally { try { Directory.Delete(data, true); } catch { } }

var legacyData = Path.Combine(Path.GetTempPath(), "barelytics-dotnet-legacy-" + Guid.NewGuid().ToString("N"));
Directory.CreateDirectory(legacyData);
try
{
    using var raw = new SqliteConnection($"Data Source={Path.Combine(legacyData,"analytics.sqlite")}"); raw.Open();
    using var command = raw.CreateCommand(); command.CommandText = File.ReadAllText(Path.Combine(root,"spec/test-vectors/legacy-v2.sql")); command.ExecuteNonQuery();
    using var legacy = new BarelyticsStore(new BarelyticsOptions(legacyData));
    if (legacy.Configuration().RetentionDays != 30) throw new Exception("Persisted retention was overwritten.");
    command.CommandText = "SELECT views FROM pageviews_daily WHERE path='/legacy'";
    if (Convert.ToInt64(command.ExecuteScalar()) != 7) throw new Exception("Legacy aggregates were lost.");
    command.CommandText = "SELECT value FROM settings WHERE key='admin_password_hash'";
    if ((string)command.ExecuteScalar()! != "fixture-unchanged-credential") throw new Exception("Legacy credentials were changed.");
    command.CommandText = "CREATE TABLE request_log(user_agent TEXT)"; command.ExecuteNonQuery();
    if (legacy.Audit().Result != "FAIL") throw new Exception("Unapproved raw-data table passed audit.");
    command.CommandText = "DROP TABLE request_log"; command.ExecuteNonQuery();
    if (legacy.Audit().Result != "PASS") throw new Exception("Valid aggregate schema failed audit.");
    command.CommandText = "INSERT INTO schema_migrations VALUES (99,'fixture')"; command.ExecuteNonQuery();
    var rejected = false;
    try { using var future = new BarelyticsStore(new BarelyticsOptions(legacyData)); } catch (InvalidOperationException) { rejected = true; }
    if (!rejected) throw new Exception("Future schema was accepted.");
}
finally { try { Directory.Delete(legacyData, true); } catch { } }

var webData = Path.Combine(Path.GetTempPath(), "barelytics-dotnet-web-" + Guid.NewGuid().ToString("N"));
using (var webStore = new BarelyticsStore(new BarelyticsOptions(webData)))
{
    var builder = WebApplication.CreateBuilder();
    builder.Logging.ClearProviders();
    builder.WebHost.UseTestServer();
    builder.Services.AddAntiforgery();
    builder.Services.AddDataProtection().UseEphemeralDataProtectionProvider();
    builder.Services.AddAuthentication("BarelyticsTest").AddScheme<AuthenticationSchemeOptions, BarelyticsTestAuthHandler>("BarelyticsTest", _ => { });
    builder.Services.AddAuthorization(options => options.AddPolicy("BarelyticsAdmin", policy => policy.RequireAuthenticatedUser()));
    await using var app = builder.Build();
    app.UseAuthentication();
    app.UseAuthorization();
    app.MapGet("/csrf", (HttpContext context, IAntiforgery antiforgery) => Results.Text(antiforgery.GetAndStoreTokens(context).RequestToken!));
    app.MapBarelyticsTrack(webStore);
    app.MapBarelyticsAdmin(webStore, app.Services.GetRequiredService<IAntiforgery>(), "BarelyticsAdmin");
    await app.StartAsync();
    using var client = app.GetTestClient();
    foreach (var v in JsonDocument.Parse(File.ReadAllText(Path.Combine(root, "spec/test-vectors/protocol.json"))).RootElement.EnumerateArray())
    {
        webStore.UpdatePrivacy(new Dictionary<string,bool> { ["referrer_collection"] = v.TryGetProperty("referrer_collection", out var flag) && flag.ValueKind == JsonValueKind.True }, true);
        byte[] raw;
        if (v.TryGetProperty("raw_hex", out var hex)) raw = Convert.FromHexString(hex.GetString()!);
        else if (v.TryGetProperty("body_bytes", out var size)) raw = Enumerable.Repeat((byte)'x', size.GetInt32()).ToArray();
        else raw = System.Text.Encoding.UTF8.GetBytes(v.TryGetProperty("body", out var body) ? body.ValueKind == JsonValueKind.String ? body.GetString()! : body.GetRawText() : "{}");
        if (v.TryGetProperty("body_size", out var padded)) raw = raw.Concat(Enumerable.Repeat((byte)' ', padded.GetInt32() - raw.Length)).ToArray();
        using var tracking = new HttpRequestMessage(new HttpMethod(v.GetProperty("method").GetString()!), "/barelytics/track") { Content = new ByteArrayContent(raw) };
        var contentType = v.TryGetProperty("content_type", out var type) ? type.GetString()! : "application/json";
        if (contentType.Length > 0) tracking.Content.Headers.TryAddWithoutValidation("Content-Type", contentType);
        tracking.Headers.TryAddWithoutValidation("User-Agent", "Mozilla/5.0");
        tracking.Headers.TryAddWithoutValidation("Referer", "https://beacon.test/current?private");
        using var observed = await client.SendAsync(tracking);
        if ((int)observed.StatusCode != v.GetProperty("status").GetInt32()) throw new Exception("Collector vector failed: " + v.GetRawText() + " actual: " + observed.StatusCode);
        if (observed.Headers.CacheControl?.NoStore != true || observed.Headers.Contains("Set-Cookie")) throw new Exception("Collector cache/cookie contract failed.");
    }
    webStore.ReturnToStrictMode();

    if ((await client.GetAsync("/barelytics/admin")).StatusCode != System.Net.HttpStatusCode.Unauthorized) throw new Exception("Admin authorization did not reject an anonymous request.");
    client.DefaultRequestHeaders.Add("X-Admin", "yes");
    if ((await client.GetAsync("/barelytics/admin")).StatusCode != System.Net.HttpStatusCode.Redirect) throw new Exception("Admin base path did not redirect to its slash-normalized UI route.");
    var uiResponse = await client.GetAsync("/barelytics/admin/");
    if (uiResponse.StatusCode != System.Net.HttpStatusCode.OK || !(await uiResponse.Content.ReadAsStringAsync()).Contains("Analytics administration")) throw new Exception("Shared admin UI was inaccessible.");
    var logoResponse = await client.GetAsync("/barelytics/admin/admin-ui/brand-mark.png");
    var logoBytes = await logoResponse.Content.ReadAsByteArrayAsync();
    if (logoResponse.StatusCode != System.Net.HttpStatusCode.OK || logoResponse.Content.Headers.ContentType?.MediaType != "image/png" || !logoBytes.AsSpan().StartsWith(new byte[] { 137, 80, 78, 71, 13, 10, 26, 10 })) throw new Exception("Shared admin brand mark was inaccessible.");
    var tokenResponse = await client.GetAsync("/barelytics/admin/api/session");
    using var tokenJson = JsonDocument.Parse(await tokenResponse.Content.ReadAsStringAsync());
    var requestToken = tokenJson.RootElement.GetProperty("data").GetProperty("csrf").GetString()!;
    var cookie = tokenResponse.Headers.GetValues("Set-Cookie").First().Split(';')[0];
    var privacyBody = new Dictionary<string, object> { ["country_collection"] = true, ["retention_days"] = 180, ["path_exclusions"] = string.Join('\n', BarelyticsStore.DefaultExclusions), ["bot_patterns"] = "", ["confirmation"] = "yes" };
    var rejected = new HttpRequestMessage(HttpMethod.Post, "/barelytics/admin/api/privacy") { Content = JsonContent.Create(privacyBody) };
    rejected.Headers.Add("Cookie", cookie);
    if ((await client.SendAsync(rejected)).StatusCode != System.Net.HttpStatusCode.Forbidden) throw new Exception("Admin route accepted a missing antiforgery token.");
    var request = new HttpRequestMessage(HttpMethod.Post, "/barelytics/admin/api/privacy") { Content = JsonContent.Create(privacyBody) };
    request.Headers.Add("Cookie", cookie);
    request.Headers.Add("RequestVerificationToken", requestToken);
    if ((await client.SendAsync(request)).StatusCode != System.Net.HttpStatusCode.OK || webStore.Audit().Profile != "extended") throw new Exception("Antiforgery-protected opt-in failed.");
}
try { Directory.Delete(webData, true); } catch { }
Console.WriteLine("Barelytics .NET contract and SQLite tests passed.");

public sealed class BarelyticsTestAuthHandler(IOptionsMonitor<AuthenticationSchemeOptions> options, ILoggerFactory logger, UrlEncoder encoder)
    : AuthenticationHandler<AuthenticationSchemeOptions>(options, logger, encoder)
{
    protected override Task<AuthenticateResult> HandleAuthenticateAsync()
    {
        if (Request.Headers["X-Admin"] != "yes") return Task.FromResult(AuthenticateResult.NoResult());
        var identity = new ClaimsIdentity([new Claim(ClaimTypes.Name, "admin")], Scheme.Name);
        var principal = new ClaimsPrincipal(identity);
        return Task.FromResult(AuthenticateResult.Success(new AuthenticationTicket(principal, Scheme.Name)));
    }
}
