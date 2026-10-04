using System.Text.Json;
using System.Reflection;
using Microsoft.AspNetCore.Antiforgery;
using Microsoft.AspNetCore.Builder;
using Microsoft.AspNetCore.Http;
using Microsoft.AspNetCore.Routing;

namespace Barelytics;

public static class AspNetCoreExtensions
{
    public static IEndpointRouteBuilder MapBarelyticsTrack(this IEndpointRouteBuilder endpoints, BarelyticsStore store, string pattern = "/barelytics/track")
    {
        endpoints.MapMethods(pattern, ["POST"], async context =>
        {
            if (context.Request.ContentLength > 8192) { context.Response.StatusCode = 413; return; }
            using var memory = new MemoryStream();
            var buffer = new byte[2048]; int count;
            while ((count = await context.Request.Body.ReadAsync(buffer, context.RequestAborted)) > 0)
            {
                if (memory.Length + count > 8192) { context.Response.StatusCode = 413; return; }
                await memory.WriteAsync(buffer.AsMemory(0, count), context.RequestAborted);
            }
            try
            {
                using var doc = JsonDocument.Parse(memory.ToArray());
                if (doc.RootElement.ValueKind != JsonValueKind.Object || doc.RootElement.EnumerateObject().Count() != 1 || !doc.RootElement.TryGetProperty("path", out var path) || path.ValueKind != JsonValueKind.String || BarelyticsStore.NormalizePath(path.GetString()) is null) { context.Response.StatusCode = 400; return; }
                _ = store.TrackPageView(path.GetString(), context.Request.Headers.UserAgent.ToString());
                context.Response.StatusCode = 204;
            }
            catch (JsonException) { context.Response.StatusCode = 400; }
            catch { context.Response.StatusCode = 204; }
        });
        return endpoints;
    }

    public static IEndpointRouteBuilder MapBarelyticsAdmin(this IEndpointRouteBuilder endpoints, BarelyticsStore store, IAntiforgery antiforgery, string adminPolicy, string pattern = "/barelytics/admin")
    {
        var group = endpoints.MapGroup(pattern).RequireAuthorization(adminPolicy);
        group.AddEndpointFilter(async (context, next) =>
        {
            context.HttpContext.Response.Headers["Cache-Control"] = "no-store";
            context.HttpContext.Response.Headers["X-Content-Type-Options"] = "nosniff";
            context.HttpContext.Response.Headers["Referrer-Policy"] = "no-referrer";
            context.HttpContext.Response.Headers["Content-Security-Policy"] = "default-src 'self'; script-src 'self'; style-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'";
            return await next(context);
        });
        group.MapGet("", (HttpContext context) =>
        {
            var path = context.Request.Path.Value ?? pattern;
            return path.EndsWith('/') ? UiContent("index.html") : Results.Redirect(path + "/");
        });
        group.MapGet("admin-ui/{asset}", (string asset) => asset is "app.js" or "admin.css" or "config.js" ? UiContent(asset) : Results.NotFound());
        group.MapGet("api/{resource}", (string resource, HttpContext context) =>
        {
            if (resource == "session")
            {
                var tokens = antiforgery.GetAndStoreTokens(context);
                return Envelope(new { authenticated = true, csrf = tokens.RequestToken, retention_days = store.Configuration().RetentionDays, capabilities = new { logout = false, delete_all = true } });
            }
            try { return Envelope(store.AdminData(resource, context.Request.Query.ToDictionary(x => x.Key, x => x.Value.ToString()))); }
            catch (ArgumentException) { return Error(StatusCodes.Status400BadRequest, "invalid_request", "The request is invalid."); }
            catch { return Error(StatusCodes.Status500InternalServerError, "internal_error", "The administration request failed."); }
        });
        group.MapPost("api/{resource}", async (string resource, HttpContext context) =>
        {
            try { await antiforgery.ValidateRequestAsync(context); }
            catch { return Error(StatusCodes.Status403Forbidden, "csrf", "Request verification failed."); }
            try
            {
                if (context.Request.ContentLength > 8192) return Error(StatusCodes.Status413PayloadTooLarge, "too_large", "Request is too large.");
                using var bodyStream = new MemoryStream(); var buffer = new byte[2048]; int read;
                while ((read = await context.Request.Body.ReadAsync(buffer, context.RequestAborted)) > 0)
                {
                    if (bodyStream.Length + read > 8192) return Error(StatusCodes.Status413PayloadTooLarge, "too_large", "Request is too large.");
                    await bodyStream.WriteAsync(buffer.AsMemory(0, read), context.RequestAborted);
                }
                using var document = JsonDocument.Parse(bodyStream.ToArray());
                var body = document.RootElement;
                if (body.ValueKind != JsonValueKind.Object) return Error(StatusCodes.Status400BadRequest, "invalid_request", "The request is invalid.");
                object? result = resource switch
                {
                    "privacy" => store.UpdateAdminSettings(body),
                    "strict" => Strict(store),
                    "cleanup" => new { complete = store.Cleanup() },
                    "delete-all" when body.TryGetProperty("confirmation", out var confirmation) && confirmation.GetString() == "DELETE" => DeleteAll(store),
                    _ => throw new ArgumentException("Invalid administration action.")
                };
                return Envelope(result);
            }
            catch (JsonException) { return Error(StatusCodes.Status400BadRequest, "invalid_json", "The request body is invalid."); }
            catch (ArgumentException) { return Error(StatusCodes.Status400BadRequest, "invalid_request", "The request is invalid."); }
            catch { return Error(StatusCodes.Status500InternalServerError, "internal_error", "The administration request failed."); }
        });
        return endpoints;
    }

    private static IResult UiContent(string name)
    {
        var resource = Assembly.GetExecutingAssembly().GetManifestResourceNames().FirstOrDefault(x => x.EndsWith(".AdminUi." + name, StringComparison.Ordinal));
        if (resource is null) return Error(StatusCodes.Status503ServiceUnavailable, "ui_unavailable", "Administration UI is unavailable.");
        using var stream = Assembly.GetExecutingAssembly().GetManifestResourceStream(resource);
        if (stream is null) return Error(StatusCodes.Status503ServiceUnavailable, "ui_unavailable", "Administration UI is unavailable.");
        using var reader = new StreamReader(stream); var content = reader.ReadToEnd();
        if (name == "config.js") content = "window.BARELYTICS_ADMIN_CONFIG = { apiBase: 'api/' };";
        var type = name.EndsWith(".js", StringComparison.Ordinal) ? "text/javascript; charset=utf-8" : name.EndsWith(".css", StringComparison.Ordinal) ? "text/css; charset=utf-8" : "text/html; charset=utf-8";
        return Results.Content(content, type);
    }

    private static IResult Envelope(object? data) => Results.Json(new { ok = true, data, error = (object?)null });
    private static IResult Error(int status, string code, string message) => Results.Json(new { ok = false, data = (object?)null, error = new { code, message } }, statusCode: status);
    private static object Strict(BarelyticsStore store) { store.ReturnToStrictMode(); return store.AdminData("privacy", new Dictionary<string, string>()); }
    private static object DeleteAll(BarelyticsStore store) { store.DeleteAll(); return new { deleted = true }; }
}
