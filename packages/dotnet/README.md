# Barelytics for ASP.NET Core

Native SQLite runtime for ASP.NET Core MVC, Razor Pages, and Minimal APIs. Targets .NET 10 LTS and .NET 8 LTS while .NET 8 remains supported. It uses `Microsoft.Data.Sqlite`; EF Core is not required.

```csharp
var builder = WebApplication.CreateBuilder(args);
builder.Services.AddSingleton(new BarelyticsStore(new BarelyticsOptions("/var/lib/my-site/barelytics")));
builder.Services.AddAntiforgery();
builder.Services.AddAuthorization(options => options.AddPolicy("BarelyticsAdmin", policy => policy.RequireAuthenticatedUser().RequireRole("Administrator")));
var app = builder.Build();
app.UseAuthorization();
app.MapBarelyticsTrack(app.Services.GetRequiredService<BarelyticsStore>());
app.MapBarelyticsAdmin(app.Services.GetRequiredService<BarelyticsStore>(), app.Services.GetRequiredService<Microsoft.AspNetCore.Antiforgery.IAntiforgery>(), "BarelyticsAdmin");
app.Run();
```

For server-side tracking, call `store.TrackPageView(HttpContext.Request.Path, HttpContext.Request.Headers.UserAgent)` only from a route that renders HTML. Use the browser script or the server-side call, not both. Do not register global tracking middleware for APIs, assets, or health checks.

The database is `analytics.sqlite` in the configured directory; put it outside `wwwroot`. The store configures WAL when available and a one-second busy timeout. Strict Mode defaults to normalized daily path aggregates with all optional dimensions off. `MapBarelyticsAdmin` serves the shared zero-build UI and JSON API under the configured route, protected by the host authorization policy and ASP.NET Core antiforgery. The API provides overview, page and day × page tables, per-page timelines, dimensions, privacy controls, cleanup, delete-all, system status, and audit. Enabling any dimension requires `confirmation: "yes"` in the JSON body. The host owns user identity; Barelytics does not create an account store. There are no analytics cookies or IDs, IP persistence, event history, or external telemetry.

ASP.NET Core MVC: call `TrackPageView` in a selected HTML action or use `MapBarelyticsTrack`. Razor Pages: call it from the chosen page handler, not every handler. Minimal APIs: call it only in HTML-producing endpoints. Run the no-third-party test harness with `dotnet run --project test/Barelytics.ContractTests`.
