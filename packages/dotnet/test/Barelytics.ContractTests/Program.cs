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
    }
    analytics.ReturnToStrictMode();
    analytics.SetRetention(30);
    using (var raw = new SqliteConnection($"Data Source={analytics.DatabasePath}"))
    {
        raw.Open();
        using var insert = raw.CreateCommand();
        insert.CommandText = "INSERT INTO pageviews_daily(day,path,country,views) VALUES ('2000-01-01','/expired','XX',4)";
        insert.ExecuteNonQuery();
    }
    if (analytics.Audit().Profile != "strict" || !analytics.Cleanup()) throw new Exception("Strict reset or cleanup failed.");
    using (var raw = new SqliteConnection($"Data Source={analytics.DatabasePath}"))
    {
        raw.Open();
        using var count = raw.CreateCommand();
        count.CommandText = "SELECT COUNT(*) FROM pageviews_daily WHERE path='/expired'";
        if (Convert.ToInt32(count.ExecuteScalar()) != 0) throw new Exception("Retention failed to delete expired aggregates.");
        count.CommandText = "SELECT COUNT(*) FROM schema_migrations";
        if (Convert.ToInt32(count.ExecuteScalar()) != 2) throw new Exception("Migration versions were not applied exactly once.");
    }
    using (var migratedAgain = new BarelyticsStore(new BarelyticsOptions(data)))
        if (migratedAgain.Audit().SchemaVersion != 2) throw new Exception("Migration is not repeatable.");
}
finally { try { Directory.Delete(data, true); } catch { } }

var webData = Path.Combine(Path.GetTempPath(), "barelytics-dotnet-web-" + Guid.NewGuid().ToString("N"));
using (var webStore = new BarelyticsStore(new BarelyticsOptions(webData)))
{
    var builder = WebApplication.CreateBuilder();
    builder.WebHost.UseTestServer();
    builder.Services.AddAntiforgery();
    builder.Services.AddDataProtection().UseEphemeralDataProtectionProvider();
    builder.Services.AddAuthentication("BarelyticsTest").AddScheme<AuthenticationSchemeOptions, BarelyticsTestAuthHandler>("BarelyticsTest", _ => { });
    builder.Services.AddAuthorization(options => options.AddPolicy("BarelyticsAdmin", policy => policy.RequireAuthenticatedUser()));
    await using var app = builder.Build();
    app.UseAuthentication();
    app.UseAuthorization();
    app.MapGet("/csrf", (HttpContext context, IAntiforgery antiforgery) => Results.Text(antiforgery.GetAndStoreTokens(context).RequestToken!));
    app.MapBarelyticsAdmin(webStore, app.Services.GetRequiredService<IAntiforgery>(), "BarelyticsAdmin");
    await app.StartAsync();
    using var client = app.GetTestClient();
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
