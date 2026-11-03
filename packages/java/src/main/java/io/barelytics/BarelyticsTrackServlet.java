package io.barelytics;

import jakarta.servlet.http.HttpServlet;
import jakarta.servlet.http.HttpServletRequest;
import jakarta.servlet.http.HttpServletResponse;
import java.io.IOException;
import java.nio.ByteBuffer;
import java.nio.charset.CodingErrorAction;
import java.nio.charset.StandardCharsets;
import java.util.Map;

/** Explicitly map this servlet to /barelytics/track. */
public final class BarelyticsTrackServlet extends HttpServlet {
    private final BarelyticsStore store;
    public BarelyticsTrackServlet(BarelyticsStore store) { this.store = store; }

    @Override protected void service(HttpServletRequest request, HttpServletResponse response) throws jakarta.servlet.ServletException, IOException {
        response.setHeader("Cache-Control", "no-store");
        response.setHeader("X-Content-Type-Options", "nosniff");
        if (!"POST".equals(request.getMethod())) { response.setHeader("Allow", "POST"); response.setStatus(405); return; }
        super.service(request, response);
    }

    @Override protected void doPost(HttpServletRequest request, HttpServletResponse response) throws IOException {
        if (request.getContentLengthLong() > 8192) { response.setStatus(413); return; }
        String type = request.getContentType();
        if (type == null || !type.split(";", 2)[0].trim().equalsIgnoreCase("application/json")) { response.setStatus(415); return; }
        byte[] bytes = request.getInputStream().readNBytes(8193);
        if (bytes.length > 8192) { response.setStatus(413); return; }
        Payload payload;
        try { payload = collectorPayload(bytes); } catch (IllegalArgumentException e) { response.setStatus(400); return; }
        try {
            if (payload.version() == 2 && !store.configuration().referrerCollection()) { response.setStatus(400); return; }
            store.trackPageView(payload.path(), request.getHeader("User-Agent"), null, payload.referrerHost() == null ? "" : "https://" + payload.referrerHost());
        }
        catch (java.sql.SQLException | RuntimeException ignored) { /* Analytics cannot break host responses. */ }
        response.setStatus(204);
    }

    record Payload(String path, int version, String referrerHost) {}
    static Payload collectorPayload(byte[] bytes) {
        try {
            String json = StandardCharsets.UTF_8.newDecoder().onMalformedInput(CodingErrorAction.REPORT).decode(ByteBuffer.wrap(bytes)).toString();
            if (!(BarelyticsJson.parse(json) instanceof Map<?, ?> body)) throw new IllegalArgumentException();
            boolean extended = body.keySet().equals(java.util.Set.of("path", "version", "referrer_host")) && body.get("version") instanceof Number version && version.doubleValue() == 2;
            if (!body.keySet().equals(java.util.Set.of("path")) && !extended) throw new IllegalArgumentException();
            if (!(body.get("path") instanceof String path) || BarelyticsStore.normalizePath(path) == null) throw new IllegalArgumentException();
            Object host = body.get("referrer_host");
            if (extended && host != null && (!(host instanceof String value) || !validHostname(value))) throw new IllegalArgumentException();
            return new Payload(path, extended ? 2 : 1, (String) host);
        } catch (java.nio.charset.CharacterCodingException e) { throw new IllegalArgumentException(e); }
    }
    private static boolean validHostname(String value) {
        return BarelyticsStore.validReferrerHostname(value);
    }
}
