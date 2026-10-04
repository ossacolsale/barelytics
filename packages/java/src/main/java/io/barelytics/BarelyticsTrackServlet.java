package io.barelytics;

import jakarta.servlet.ServletException;
import jakarta.servlet.http.HttpServlet;
import jakarta.servlet.http.HttpServletRequest;
import jakarta.servlet.http.HttpServletResponse;
import java.io.IOException;
import java.nio.charset.StandardCharsets;
import java.sql.SQLException;
import java.util.regex.Matcher;
import java.util.regex.Pattern;

/** Explicitly map this servlet to POST /barelytics/track; it does not install itself globally. */
public final class BarelyticsTrackServlet extends HttpServlet {
    private static final Pattern BODY = Pattern.compile("\\s*\\{\\s*\"path\"\\s*:\\s*\"((?:[^\"\\\\]|\\\\.)*)\"\\s*}\\s*", Pattern.DOTALL);
    private final BarelyticsStore store;
    public BarelyticsTrackServlet(BarelyticsStore store) { this.store = store; }

    @Override protected void doPost(HttpServletRequest request, HttpServletResponse response) throws IOException {
        if (request.getContentLengthLong() > 8192) { response.setStatus(413); return; }
        byte[] bytes = request.getInputStream().readNBytes(8193);
        if (bytes.length > 8192) { response.setStatus(413); return; }
        String json = new String(bytes, StandardCharsets.UTF_8); Matcher matcher = BODY.matcher(json);
        if (!matcher.matches()) { response.setStatus(400); return; }
        String path;
        try { path = unescape(matcher.group(1)); } catch (IllegalArgumentException e) { response.setStatus(400); return; }
        if (BarelyticsStore.normalizePath(path) == null) { response.setStatus(400); return; }
        try { store.trackPageView(path, request.getHeader("User-Agent"), null, null); response.setStatus(204); }
        catch (RuntimeException e) { response.setStatus(204); }
    }

    @Override protected void doGet(HttpServletRequest request, HttpServletResponse response) { response.setHeader("Allow", "POST"); response.setStatus(405); }
    private static String unescape(String value) {
        StringBuilder out = new StringBuilder();
        for (int i=0;i<value.length();i++) {
            char c=value.charAt(i); if(c!='\\'){out.append(c);continue;} if(++i>=value.length())throw new IllegalArgumentException();
            char e=value.charAt(i); switch(e){case '"','\\','/'->out.append(e);case 'b'->out.append('\b');case 'f'->out.append('\f');case 'n'->out.append('\n');case 'r'->out.append('\r');case 't'->out.append('\t');case 'u'->{if(i+4>=value.length())throw new IllegalArgumentException();try{out.append((char)Integer.parseInt(value.substring(i+1,i+5),16));}catch(NumberFormatException ex){throw new IllegalArgumentException();}i+=4;}default->throw new IllegalArgumentException();}
        } return out.toString();
    }
}
