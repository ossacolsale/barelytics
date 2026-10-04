using System.Text.Json;
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
        group.MapGet("", () => Results.Json(new { dashboard = store.Dashboard(), audit = store.Audit() }, new JsonSerializerOptions { PropertyNamingPolicy = JsonNamingPolicy.CamelCase }));
        group.MapPost("privacy", async (HttpContext context, Dictionary<string, bool> values) =>
        {
            try { await antiforgery.ValidateRequestAsync(context); }
            catch { return Results.StatusCode(StatusCodes.Status400BadRequest); }
            try { return Results.Ok(new { profile = store.UpdatePrivacy(values, context.Request.Headers["X-Barelytics-Extended-Acknowledgement"] == "yes") }); }
            catch (InvalidOperationException) { return Results.BadRequest(); }
        });
        group.MapPost("strict", async (HttpContext context) =>
        {
            try { await antiforgery.ValidateRequestAsync(context); }
            catch { return Results.StatusCode(StatusCodes.Status400BadRequest); }
            store.ReturnToStrictMode(); return Results.NoContent();
        });
        group.MapPost("cleanup", async (HttpContext context) =>
        {
            try { await antiforgery.ValidateRequestAsync(context); }
            catch { return Results.StatusCode(StatusCodes.Status400BadRequest); }
            return Results.Ok(new { complete = store.Cleanup() });
        });
        return endpoints;
    }
}
