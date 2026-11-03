package io.barelytics;

import static org.junit.jupiter.api.Assertions.*;

import java.nio.file.Files;
import java.nio.file.Path;
import java.util.List;
import java.util.Map;
import java.util.regex.Matcher;
import java.util.regex.Pattern;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.api.io.TempDir;

class BarelyticsContractTest {
    @TempDir Path temp;

    @Test void sharedAdminBrandMarkIsPackaged() throws Exception {
        try(var image=BarelyticsAdminServlet.class.getResourceAsStream("/admin-ui/brand-mark.png")) {
            assertNotNull(image);
            assertArrayEquals(new byte[]{(byte)0x89,0x50,0x4e,0x47,0x0d,0x0a,0x1a,0x0a}, image.readNBytes(8));
        }
    }

    @Test void sharedPathVectors() throws Exception {
        Path file=Path.of("../..","spec/test-vectors/path-normalization.json").toAbsolutePath().normalize();
        Pattern vector=Pattern.compile("\\{\\\"input\\\":\\\"((?:\\\\.|[^\\\"\\\\])*)\\\",\\\"expected\\\":(null|\\\"(?:\\\\.|[^\\\"])*\\\")");
        for(String line:Files.readAllLines(file)) {
            if(line.contains("\"input\":{\"prefix\"")) { assertNull(BarelyticsStore.normalizePath("/"+"a".repeat(512))); continue; }
            Matcher m=vector.matcher(line);if(!m.find())continue;
            String input=unescape(m.group(1));String expected=m.group(2).equals("null")?null:unescape(m.group(2).substring(1,m.group(2).length()-1));
            assertEquals(expected,BarelyticsStore.normalizePath(input),input);
        }
    }

    @Test void sharedBotVectors() throws Exception {
        Path file=Path.of("../..","spec/test-vectors/bot-filtering.json").toAbsolutePath().normalize();
        for(String line:Files.readAllLines(file)) {
            if(line.contains("GOOGLEBOT/2.1"))assertTrue(BarelyticsStore.isBot("Mozilla compatible GOOGLEBOT/2.1"));
            if(line.contains("MyTestRobot"))assertTrue(BarelyticsStore.isBot("MyTestRobot",List.of("robot")));
            if(line.contains("X11; Linux"))assertFalse(BarelyticsStore.isBot("Mozilla/5.0 (X11; Linux x86_64)"));
            if(line.contains("Lighthouse"))assertTrue(BarelyticsStore.isBot("Mozilla/5.0 Lighthouse"));
        }
    }

    @Test void strictAggregatesDimensionsAuditAndRetention() throws Exception {
        try(BarelyticsStore store=new BarelyticsStore(temp)) {
            assertEquals("PASS",store.audit().result());
            assertEquals("strict",store.configuration().profile());
            assertTrue(store.trackPageView("/article","Mozilla/5.0 Chrome/120 Windows",null,null));
            assertFalse(store.trackPageView("/private/record",null,null,null));
            assertFalse(store.trackPageView("/article","Googlebot",null,null));
            assertEquals(1,store.dashboard(30).total());
            assertEquals(1L,store.adminData("dashboard",Map.of("period","30","bucket","day")).get("active_pages"));
            assertEquals(false,store.adminData("dimensions",Map.of("dimension","country")).get("enabled"));
            assertEquals("/article",((Map<?,?>)((List<?>)store.adminData("pages",Map.of()).get("rows")).getFirst()).get("path"));
            assertThrows(IllegalArgumentException.class,()->store.updatePrivacy(Map.of("country_collection",true),false));
            store.updatePrivacy(Map.of("country_collection",true,"referrer_collection",true,"browser_collection",true,"device_collection",true,"os_collection",true),true);
            assertEquals("extended",store.configuration().profile());
            assertTrue(store.trackPageView("/extended","Mozilla/5.0 Chrome/120 Windows","it","https://example.test/path"));
            try(var raw=java.sql.DriverManager.getConnection("jdbc:sqlite:"+store.databasePath());var query=raw.createStatement()){
                try(var dimensions=query.executeQuery("SELECT COUNT(*) FROM dimensions_daily WHERE path='/extended'")){assertTrue(dimensions.next());assertEquals(3,dimensions.getInt(1));}
                try(var referrer=query.executeQuery("SELECT COUNT(*) FROM referrers_daily WHERE path='/extended'")){assertTrue(referrer.next());assertEquals(1,referrer.getInt(1));}
                try(var country=query.executeQuery("SELECT country FROM pageviews_daily WHERE path='/extended'")){assertTrue(country.next());assertEquals("IT",country.getString(1));}
            }
            store.returnToStrictMode();assertEquals("strict",store.configuration().profile());
            assertEquals("PASS",store.audit().result());
            store.setRetention(30);
            try(var raw=java.sql.DriverManager.getConnection("jdbc:sqlite:"+store.databasePath());var insert=raw.prepareStatement("INSERT INTO pageviews_daily(day,path,country,views) VALUES (?,?,?,?)")){
                insert.setString(1,"2000-01-01");insert.setString(2,"/expired");insert.setString(3,"XX");insert.setInt(4,4);insert.executeUpdate();
            }
            assertTrue(store.cleanup(1000));
            try(var raw=java.sql.DriverManager.getConnection("jdbc:sqlite:"+store.databasePath());var count=raw.createStatement()){
                try(var expired=count.executeQuery("SELECT COUNT(*) FROM pageviews_daily WHERE path='/expired'")){assertTrue(expired.next());assertEquals(0,expired.getInt(1));}
                try(var versions=count.executeQuery("SELECT COUNT(*) FROM schema_migrations")){assertTrue(versions.next());assertEquals(3,versions.getInt(1));}
            }
        }
        try(BarelyticsStore migratedAgain=new BarelyticsStore(temp)){assertEquals("PASS",migratedAgain.audit().result());}
    }

    @Test void automationVectorsAndLifecycle() throws Exception {
        var vectors = (List<?>) BarelyticsJson.parse(Files.readString(Path.of("../..", "spec/test-vectors/automation.json")));
        for (Object item : vectors) {
            var v = (Map<?,?>) item;
            @SuppressWarnings("unchecked") var extra = v.containsKey("additional_patterns") ? (List<String>)v.get("additional_patterns") : List.<String>of();
            var actual = AutomationClassifier.classify((String)v.get("user_agent"), extra);
            assertEquals(v.get("category"), actual.category()); assertEquals(v.get("family"), actual.family());
            assertEquals(v.get("identity"), actual.identity()); assertEquals(v.get("automation"), actual.automation()); assertFalse(actual.verified());
        }
        try (var store = new BarelyticsStore(temp)) {
            assertFalse(store.trackPageView("/article", "GPTBot/1", null, null));
            assertFalse(store.trackPageView("/private/report", "GPTBot/1", null, null));
            assertEquals(1L, store.adminData("automation", Map.of()).get("total"));
            assertEquals(0, store.dashboard(30).total());
            try (var raw = java.sql.DriverManager.getConnection("jdbc:sqlite:"+store.databasePath()); var q = raw.createStatement()) {
                q.execute("INSERT INTO automation_daily VALUES ('2000-01-01','/old','ai','openai',2)");
                q.execute("UPDATE settings SET value='invalid-cleanup-fixture' WHERE key='last_cleanup_at'");
                assertTrue(store.trackPageView("/fresh", "Mozilla/5.0", null, null));
                try (var rows = q.executeQuery("SELECT COUNT(*) FROM automation_daily WHERE day='2000-01-01'")) { assertTrue(rows.next()); assertEquals(0, rows.getInt(1)); }
            }
            store.updatePrivacy(Map.of("browser_collection",true),true);
            try (var raw = java.sql.DriverManager.getConnection("jdbc:sqlite:"+store.databasePath()); var q = raw.createStatement()) {
                q.execute("CREATE TRIGGER fail_dimension BEFORE INSERT ON dimensions_daily BEGIN SELECT RAISE(ABORT, 'fixture'); END");
                assertFalse(store.trackPageView("/interrupted", "Mozilla/5.0", null, null));
                try (var rows = q.executeQuery("SELECT COUNT(*) FROM pageviews_daily WHERE path='/interrupted'")) { assertTrue(rows.next()); assertEquals(0,rows.getInt(1)); }
                q.execute("DROP TRIGGER fail_dimension");
            }
            store.deleteAll(); assertEquals(0L, store.adminData("automation", Map.of()).get("total"));
        }
    }

    @Test void sharedHttpProtocolAndDimensionFilters() throws Exception {
        try (var store = new BarelyticsStore(temp)) {
            var servlet = new BarelyticsTrackServlet(store);
            var vectors = (List<?>)BarelyticsJson.parse(Files.readString(Path.of("../..", "spec/test-vectors/protocol.json")));
            for (Object item : vectors) {
                var v = (Map<?,?>)item;
                store.updatePrivacy(Map.of("referrer_collection", Boolean.TRUE.equals(v.get("referrer_collection"))), true);
                byte[] raw = v.containsKey("raw_hex") ? java.util.HexFormat.of().parseHex((String)v.get("raw_hex")) : v.containsKey("body_bytes") ? "x".repeat(((Number)v.get("body_bytes")).intValue()).getBytes(java.nio.charset.StandardCharsets.UTF_8) : (v.get("body") instanceof String text ? text : BarelyticsJson.encode(v.containsKey("body") ? v.get("body") : Map.of())).getBytes(java.nio.charset.StandardCharsets.UTF_8);
                if (v.containsKey("body_size")) { int old = raw.length; raw = java.util.Arrays.copyOf(raw, ((Number)v.get("body_size")).intValue()); java.util.Arrays.fill(raw, old, raw.length, (byte)' '); }
                byte[] bytes = raw;
                var input = new java.io.ByteArrayInputStream(bytes);
                var stream = new jakarta.servlet.ServletInputStream() {
                    public int read() { return input.read(); }
                    public boolean isFinished() { return input.available() == 0; }
                    public boolean isReady() { return true; }
                    public void setReadListener(jakarta.servlet.ReadListener listener) { }
                };
                var status = new java.util.concurrent.atomic.AtomicInteger(200);
                Map<String,String> headers = new java.util.HashMap<>();
                var request = (jakarta.servlet.http.HttpServletRequest)java.lang.reflect.Proxy.newProxyInstance(getClass().getClassLoader(), new Class[]{jakarta.servlet.http.HttpServletRequest.class}, (proxy, method, args) -> switch (method.getName()) {
                    case "getMethod" -> v.get("method"); case "getContentLengthLong" -> (long)bytes.length;
                    case "getContentType" -> v.containsKey("content_type") ? v.get("content_type") : "application/json";
                    case "getInputStream" -> stream;
                    case "getHeader" -> "User-Agent".equals(args[0]) ? "Mozilla/5.0" : "Referer".equals(args[0]) ? "https://beacon.test/current?private" : null;
                    default -> null;
                });
                var response = (jakarta.servlet.http.HttpServletResponse)java.lang.reflect.Proxy.newProxyInstance(getClass().getClassLoader(), new Class[]{jakarta.servlet.http.HttpServletResponse.class}, (proxy, method, args) -> {
                    if (method.getName().equals("setStatus")) status.set((Integer)args[0]);
                    if (method.getName().equals("setHeader")) headers.put((String)args[0], (String)args[1]);
                    return null;
                });
                servlet.service(request, response);
                assertEquals(((Number)v.get("status")).intValue(), status.get(), v.toString());
                assertEquals("no-store", headers.get("Cache-Control")); assertFalse(headers.containsKey("Set-Cookie"));
            }
            store.updatePrivacy(Map.of("country_collection",true,"referrer_collection",true,"browser_collection",true,"device_collection",true,"os_collection",true),true);
            store.trackPageView("/one", "Mozilla/5.0 Firefox/1 Windows", "it", "https://one.test/private?q=secret");
            store.trackPageView("/two", "Mozilla/5.0 Chrome/1 Android Mobile", "us", "https://two.test/private?q=secret");
            for (var entry : Map.of("country","IT","referrer","one.test","browser","Firefox","device","Desktop","os","Windows").entrySet()) {
                var rows = (List<?>)store.adminData("dimensions", Map.of("dimension",entry.getKey(),"path","/one")).get("rows");
                assertEquals(1,rows.size()); assertEquals(entry.getValue(),((Map<?,?>)rows.getFirst()).get("value"));
                assertThrows(IllegalArgumentException.class, () -> store.adminData("dimensions",Map.of("dimension",entry.getKey(),"path","https://bad.test")));
            }
            store.returnToStrictMode(); assertEquals(List.of("country","referrer","browser","device","os"),store.historicalDimensions());
        }
    }

    @Test void legacyUpgradePreservesDataAndRejectsFutureSchema() throws Exception {
        try (var raw = java.sql.DriverManager.getConnection("jdbc:sqlite:"+temp.resolve("analytics.sqlite")); var q = raw.createStatement()) {
            for (String sql : Files.readString(Path.of("../..", "spec/test-vectors/legacy-v2.sql")).split(";")) if (!sql.isBlank()) q.execute(sql);
            try (var store = new BarelyticsStore(temp)) {
                assertEquals(30, store.configuration().retentionDays());
                try (var rows = q.executeQuery("SELECT views FROM pageviews_daily WHERE path='/legacy'")) { assertTrue(rows.next()); assertEquals(7,rows.getInt(1)); }
                try (var rows = q.executeQuery("SELECT value FROM settings WHERE key='admin_password_hash'")) { assertTrue(rows.next()); assertEquals("fixture-unchanged-credential",rows.getString(1)); }
                q.execute("CREATE TABLE request_log(user_agent TEXT)"); assertEquals("FAIL",store.audit().result());
                q.execute("DROP TABLE request_log"); assertEquals("PASS",store.audit().result());
                q.execute("INSERT INTO schema_migrations VALUES (99,'fixture')");
                assertThrows(java.sql.SQLException.class, () -> new BarelyticsStore(temp));
                try (var rows = q.executeQuery("SELECT views FROM pageviews_daily WHERE path='/legacy'")) { assertTrue(rows.next()); assertEquals(7,rows.getInt(1)); }
            }
        }
    }

    private static String unescape(String s){StringBuilder o=new StringBuilder();for(int i=0;i<s.length();i++){char c=s.charAt(i);if(c!='\\'){o.append(c);continue;}char e=s.charAt(++i);switch(e){case 'u'-> {o.append((char)Integer.parseInt(s.substring(i+1,i+5),16));i+=4;}case 'n'->o.append('\n');case 'r'->o.append('\r');case 't'->o.append('\t');case 'b'->o.append('\b');case 'f'->o.append('\f');default->o.append(e);}}return o.toString();}
}
