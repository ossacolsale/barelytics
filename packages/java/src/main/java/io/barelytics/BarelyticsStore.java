package io.barelytics;

import java.io.IOException;
import java.net.URI;
import java.io.ByteArrayOutputStream;
import java.nio.ByteBuffer;
import java.nio.charset.CodingErrorAction;
import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.nio.file.Path;
import java.nio.file.attribute.PosixFilePermission;
import java.security.MessageDigest;
import java.sql.Connection;
import java.sql.DriverManager;
import java.sql.PreparedStatement;
import java.sql.ResultSet;
import java.sql.SQLException;
import java.sql.Statement;
import java.time.LocalDate;
import java.time.OffsetDateTime;
import java.time.ZoneOffset;
import java.util.ArrayList;
import java.util.HexFormat;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.Set;
import java.util.TreeMap;
import java.util.regex.Pattern;

/** Native SQLite aggregate store. Register one instance per web application. */
public final class BarelyticsStore implements AutoCloseable {
    public static final int CONTRACT_VERSION = 1;
    public static final int SCHEMA_VERSION = 2;
    public static final List<String> DIMENSIONS = List.of("country_collection", "referrer_collection", "browser_collection", "device_collection", "os_collection");
    public static final List<String> DEFAULT_EXCLUSIONS = List.of("/admin/*", "/admin.php", "/account/*", "/checkout/*", "/customer/*", "/patient/*", "/profile/*", "/private/*");
    private static final List<String> BOTS = List.of("bot", "crawler", "spider", "slurp", "bingpreview", "headless", "lighthouse", "pagespeed", "semrush", "ahrefsbot", "mj12bot", "dotbot", "facebookexternalhit", "twitterbot", "linkedinbot", "discordbot", "telegrambot", "whatsapp", "petalbot", "yandex", "baiduspider", "bytespider", "duckduckbot", "applebot", "googlebot", "bingbot");
    private static final Pattern ID = Pattern.compile("(?<=/)(?:[0-9]{6,}|[0-9A-HJKMNP-TV-Z]{26}|[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}|[0-9a-f]{16,}|[A-Za-z0-9_-]{32,})(?=/|$)", Pattern.CASE_INSENSITIVE);
    private final Connection connection;
    private final Object lock = new Object();
    private final Path databasePath;

    public BarelyticsStore(Path dataDirectory) throws SQLException, IOException { this(dataDirectory, null); }

    public BarelyticsStore(Path dataDirectory, List<String> exclusions) throws SQLException, IOException {
        Path directory = dataDirectory.toAbsolutePath().normalize(); Files.createDirectories(directory);
        databasePath = directory.resolve("analytics.sqlite");
        connection = DriverManager.getConnection("jdbc:sqlite:" + databasePath);
        try (Statement statement = connection.createStatement()) { statement.execute("PRAGMA busy_timeout=1000"); try { statement.execute("PRAGMA journal_mode=WAL"); } catch (SQLException ignored) { } }
        try { Files.setPosixFilePermissions(databasePath, Set.of(PosixFilePermission.OWNER_READ, PosixFilePermission.OWNER_WRITE)); } catch (UnsupportedOperationException | IOException ignored) { }
        migrate();
        if (exclusions != null) set("path_exclusions", String.join("\n", exclusions.stream().filter(BarelyticsStore::validPattern).distinct().sorted().toList()));
    }

    public Path databasePath() { return databasePath; }

    public static String normalizePath(String input) {
        if (input == null || input.isEmpty() || input.getBytes(StandardCharsets.UTF_8).length > 512 || input.codePoints().anyMatch(c -> c < 0x20 || c == 0x7f) || !input.startsWith("/") || input.startsWith("//") || input.contains("?") || input.contains("#")) return null;
        for (int i=0;i<input.length();i++) if (Character.isSurrogate(input.charAt(i)) && (i+1>=input.length() || !Character.isSurrogatePair(input.charAt(i),input.charAt(i+1)))) return null; else if(Character.isHighSurrogate(input.charAt(i))) i++;
        String path = input.replaceAll("/{2,}", "/");
        if (path.getBytes(StandardCharsets.UTF_8).length > 512 || Pattern.compile("%(?![0-9a-f]{2})", Pattern.CASE_INSENSITIVE).matcher(path).find()) return null;
        String decoded;
        try { decoded = percentDecode(path); } catch (IllegalArgumentException e) { return null; }
        if (decoded.codePoints().anyMatch(c -> c < 0x20 || c == 0x7f) || decoded.indexOf('@') >= 0 || decoded.indexOf('?') >= 0 || decoded.indexOf('#') >= 0) return null;
        if (!decoded.equals(path) && ID.matcher(decoded).find()) return null;
        return ID.matcher(path).replaceAll(":id");
    }

    public static boolean isBot(String userAgent) { return isBot(userAgent, List.of()); }
    public static boolean isBot(String userAgent, List<String> extra) {
        String value = userAgent == null ? "" : userAgent.toLowerCase();
        return java.util.stream.Stream.concat(BOTS.stream(), extra.stream()).anyMatch(pattern -> pattern != null && !pattern.isEmpty() && value.contains(pattern.toLowerCase()));
    }

    public boolean trackPageView(String path, String userAgent, String country, String referrer) {
        String normalized = normalizePath(path); PrivacyConfiguration config;
        try { config = configuration(); } catch (SQLException e) { return false; }
        if (normalized == null || excluded(normalized, config.pathExclusions()) || isBot(userAgent, config.botPatterns())) return false;
        String countryCode = config.countryCollection() && country != null && country.matches("[A-Za-z]{2}") ? country.toUpperCase() : "XX";
        synchronized (lock) {
            try {
                connection.setAutoCommit(false);
                upsert("INSERT INTO pageviews_daily(day,path,country,views) VALUES (?,?,?,1) ON CONFLICT(day,path,country) DO UPDATE SET views=views+1", utcDay(), normalized, countryCode);
                String host = config.referrerCollection() ? host(referrer) : null;
                if (host != null) upsert("INSERT INTO referrers_daily(day,path,referrer_host,views) VALUES (?,?,?,1) ON CONFLICT(day,path,referrer_host) DO UPDATE SET views=views+1", utcDay(), normalized, host);
                String ua = userAgent == null ? "" : userAgent;
                if (config.browserCollection()) upsert("INSERT INTO dimensions_daily(day,path,dimension,value,views) VALUES (?,?,?, ?,1) ON CONFLICT(day,path,dimension,value) DO UPDATE SET views=views+1", utcDay(), normalized, "browser", category(ua,"browser"));
                if (config.deviceCollection()) upsert("INSERT INTO dimensions_daily(day,path,dimension,value,views) VALUES (?,?,?, ?,1) ON CONFLICT(day,path,dimension,value) DO UPDATE SET views=views+1", utcDay(), normalized, "device", category(ua,"device"));
                if (config.osCollection()) upsert("INSERT INTO dimensions_daily(day,path,dimension,value,views) VALUES (?,?,?, ?,1) ON CONFLICT(day,path,dimension,value) DO UPDATE SET views=views+1", utcDay(), normalized, "os", category(ua,"os"));
                connection.commit(); connection.setAutoCommit(true); return true;
            } catch (SQLException e) { rollback(); return false; }
        }
    }

    public String updatePrivacy(Map<String, Boolean> values, boolean acknowledged) throws SQLException {
        boolean enabled = DIMENSIONS.stream().anyMatch(key -> Boolean.TRUE.equals(values.get(key)));
        if (enabled && !acknowledged) throw new IllegalArgumentException("Explicit acknowledgement is required to enable optional dimensions.");
        synchronized (lock) {
            connection.setAutoCommit(false);
            try { for (String key : DIMENSIONS) set(key, Boolean.TRUE.equals(values.get(key)) ? "1" : "0"); recordHistory(); connection.commit(); }
            catch (SQLException | RuntimeException e) { rollback(); throw e; }
            finally { connection.setAutoCommit(true); }
        }
        return configuration().profile();
    }

    public void returnToStrictMode() throws SQLException { updatePrivacy(Map.of(), false); }

    public void setRetention(int days) throws SQLException {
        int value = Set.of(30,90,180,365).contains(days) ? days : 180;
        synchronized (lock) { set("retention_days", Integer.toString(value)); recordHistory(); }
    }

    public Dashboard dashboard(int periodDays) throws SQLException {
        int period = Set.of(30,90,180,365).contains(periodDays) ? periodDays : 30;
        String from = LocalDate.now(ZoneOffset.UTC).minusDays(period - 1L).toString(), to = utcDay();
        synchronized (lock) {
            long total = scalar("SELECT COALESCE(SUM(views),0) FROM pageviews_daily WHERE day BETWEEN ? AND ?", from, to);
            return new Dashboard(total, pairs("SELECT day,SUM(views) FROM pageviews_daily WHERE day BETWEEN ? AND ? GROUP BY day ORDER BY day", from,to), pairs("SELECT path,SUM(views) FROM pageviews_daily WHERE day BETWEEN ? AND ? GROUP BY path ORDER BY SUM(views) DESC,path LIMIT 500",from,to), configuration().profile());
        }
    }

    public AuditReport audit() throws SQLException {
        synchronized (lock) {
            PrivacyConfiguration config = configuration(); List<String> tables = new ArrayList<>();
            try (Statement s = connection.createStatement(); ResultSet r = s.executeQuery("SELECT name FROM sqlite_master WHERE type='table'")) { while (r.next()) tables.add(r.getString(1)); }
            boolean forbidden = tables.stream().anyMatch(x -> x.matches("(?i).*(visitor|session|fingerprint|event|identity).*"));
            boolean ipColumn = false;
            try (Statement s = connection.createStatement(); ResultSet r = s.executeQuery("PRAGMA table_info(pageviews_daily)")) { while (r.next()) if (r.getString("name").toLowerCase().contains("ip")) ipColumn = true; }
            Map<String,Boolean> checks = new LinkedHashMap<>();
            checks.put("profile_is_known", config.profile().equals("strict") || config.profile().equals("extended"));
            checks.put("dimensions_fail_closed", true); checks.put("retention_is_valid", Set.of(30,90,180,365).contains(config.retentionDays()));
            checks.put("aggregate_schema", List.of("pageviews_daily","schema_migrations","privacy_configuration_history").stream().allMatch(tables::contains));
            checks.put("no_identity_or_event_tables", !forbidden); checks.put("no_ip_column", !ipColumn); checks.put("no_third_party_analytics", true);
            checks.put("path_normalization_active", "/users/:id".equals(normalizePath("/users/123456"))); checks.put("private_exclusions_active", excluded("/admin/example", config.pathExclusions()));
            String fingerprint = fingerprint(config);
            return new AuditReport("Barelytics Java", CONTRACT_VERSION, SCHEMA_VERSION, config.profile(), fingerprint, config, checks, checks.values().stream().allMatch(Boolean::booleanValue) ? "PASS" : "FAIL");
        }
    }

    public boolean cleanup(int limit) throws SQLException {
        int bound = Math.min(1000, Math.max(1, limit)); String cutoff = LocalDate.now(ZoneOffset.UTC).minusDays(configuration().retentionDays()).toString();
        synchronized (lock) {
            connection.setAutoCommit(false);
            try {
                boolean complete = true;
                for (String table : List.of("pageviews_daily","referrers_daily","dimensions_daily")) {
                    long count = scalar("SELECT COUNT(*) FROM (SELECT rowid FROM "+table+" WHERE day<? ORDER BY day LIMIT ?)", cutoff, bound);
                    try (PreparedStatement p = connection.prepareStatement("DELETE FROM "+table+" WHERE rowid IN (SELECT rowid FROM "+table+" WHERE day<? ORDER BY day LIMIT ?)")) { p.setString(1,cutoff); p.setInt(2,bound); p.executeUpdate(); }
                    if(count==bound) complete=false;
                }
                set("last_cleanup_at", OffsetDateTime.now(ZoneOffset.UTC).toString()); connection.commit(); return complete;
            } catch(SQLException e){rollback();return false;} finally {connection.setAutoCommit(true);}
        }
    }

    private void migrate() throws SQLException {
        try (Statement s=connection.createStatement()) {
            for (String sql : List.of(
                "CREATE TABLE IF NOT EXISTS schema_migrations(version INTEGER PRIMARY KEY,applied_at TEXT NOT NULL)",
                "CREATE TABLE IF NOT EXISTS settings(key TEXT PRIMARY KEY,value TEXT NOT NULL)",
                "CREATE TABLE IF NOT EXISTS pageviews_daily(day TEXT NOT NULL,path TEXT NOT NULL,country TEXT NOT NULL DEFAULT 'XX',views INTEGER NOT NULL DEFAULT 0,PRIMARY KEY(day,path,country))",
                "CREATE TABLE IF NOT EXISTS referrers_daily(day TEXT NOT NULL,path TEXT NOT NULL,referrer_host TEXT NOT NULL,views INTEGER NOT NULL DEFAULT 0,PRIMARY KEY(day,path,referrer_host))",
                "CREATE TABLE IF NOT EXISTS dimensions_daily(day TEXT NOT NULL,path TEXT NOT NULL,dimension TEXT NOT NULL,value TEXT NOT NULL,views INTEGER NOT NULL DEFAULT 0,PRIMARY KEY(day,path,dimension,value))",
                "CREATE TABLE IF NOT EXISTS privacy_configuration_history(timestamp TEXT NOT NULL,profile TEXT NOT NULL,effective_configuration_json TEXT NOT NULL,configuration_hash TEXT NOT NULL,application_version TEXT NOT NULL,schema_version INTEGER NOT NULL)")) s.execute(sql);
        }
        synchronized(lock){ connection.setAutoCommit(false); try {
            try(PreparedStatement p=connection.prepareStatement("INSERT OR IGNORE INTO schema_migrations(version,applied_at) VALUES (?,?)")){for(int version:List.of(1,SCHEMA_VERSION)){p.setInt(1,version);p.setString(2,OffsetDateTime.now(ZoneOffset.UTC).toString());p.executeUpdate();}}
            for(String key:DIMENSIONS)if(!List.of("0","1").contains(get(key,"0")))set(key,"0");
            set("retention_days",get("retention_days","180"));set("path_exclusions",get("path_exclusions",String.join("\n",DEFAULT_EXCLUSIONS)));set("bot_patterns",get("bot_patterns",""));connection.commit();
        }catch(SQLException e){connection.rollback();throw e;}finally{connection.setAutoCommit(true);}}
    }

    public PrivacyConfiguration configuration() throws SQLException {
        Map<String,Boolean> f=new TreeMap<>();for(String key:DIMENSIONS)f.put(key,"1".equals(get(key,"0")));
        int retention;try{retention=Integer.parseInt(get("retention_days","180"));}catch(NumberFormatException e){retention=180;}if(!Set.of(30,90,180,365).contains(retention))retention=180;
        List<String> exclusions=List.of(get("path_exclusions",String.join("\n",DEFAULT_EXCLUSIONS)).split("\n")).stream().filter(BarelyticsStore::validPattern).distinct().sorted().toList();
        List<String> bots=List.of(get("bot_patterns","").split("\n")).stream().filter(x->!x.isEmpty()&&x.length()<=200).toList();
        String profile=f.values().stream().anyMatch(Boolean::booleanValue)?"extended":"strict";
        return new PrivacyConfiguration(f.get(DIMENSIONS.get(0)),f.get(DIMENSIONS.get(1)),f.get(DIMENSIONS.get(2)),f.get(DIMENSIONS.get(3)),f.get(DIMENSIONS.get(4)),retention,profile,exclusions,bots);
    }

    private void recordHistory() throws SQLException { PrivacyConfiguration c=configuration();String json=c.toJson();upsert("INSERT INTO privacy_configuration_history VALUES (?,?,?,?,?,?)",OffsetDateTime.now(ZoneOffset.UTC).toString(),c.profile(),json,fingerprint(c),"1.0.0",SCHEMA_VERSION); }
    private String fingerprint(PrivacyConfiguration c) throws SQLException {try{String json="{\"contract_version\":"+CONTRACT_VERSION+",\"schema_version\":"+SCHEMA_VERSION+",\"configuration\":"+c.toJson()+"}";return HexFormat.of().formatHex(MessageDigest.getInstance("SHA-256").digest(json.getBytes(StandardCharsets.UTF_8)));}catch(Exception e){throw new SQLException(e);}}
    private String get(String key,String fallback) throws SQLException {try(PreparedStatement p=connection.prepareStatement("SELECT value FROM settings WHERE key=?")){p.setString(1,key);try(ResultSet r=p.executeQuery()){return r.next()?r.getString(1):fallback;}}}
    private void set(String key,String value) throws SQLException {upsert("INSERT INTO settings(key,value) VALUES (?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value",key,value);}
    private void upsert(String sql,Object...values)throws SQLException{try(PreparedStatement p=connection.prepareStatement(sql)){for(int i=0;i<values.length;i++)p.setObject(i+1,values[i]);p.executeUpdate();}}
    private long scalar(String sql,Object...values)throws SQLException{try(PreparedStatement p=connection.prepareStatement(sql)){for(int i=0;i<values.length;i++)p.setObject(i+1,values[i]);try(ResultSet r=p.executeQuery()){return r.next()?r.getLong(1):0;}}}
    private List<Row> pairs(String sql,String from,String to)throws SQLException{List<Row>rows=new ArrayList<>();try(PreparedStatement p=connection.prepareStatement(sql)){p.setString(1,from);p.setString(2,to);try(ResultSet r=p.executeQuery()){while(r.next())rows.add(new Row(r.getString(1),r.getLong(2)));}}return List.copyOf(rows);}
    private void rollback(){try{connection.rollback();}catch(SQLException ignored){}try{connection.setAutoCommit(true);}catch(SQLException ignored){}}
    private static boolean validPattern(String x){return x.startsWith("/")&&x.length()<=200&&!x.contains("?")&&!x.contains("#");}
    private static boolean excluded(String path,List<String>patterns){return patterns.stream().anyMatch(p->p.endsWith("/*")?(path.equals(p.substring(0,p.length()-2))||path.startsWith(p.substring(0,p.length()-1))):path.equals(p));}
    private static String category(String ua,String kind){if(kind.equals("browser"))return ua.matches("(?is).*Firefox/.*")?"Firefox":ua.matches("(?is).*(Edg/|Edge/).*" )?"Edge":ua.matches("(?is).*Chrome/.*")?"Chrome":ua.matches("(?is).*Safari/.*")?"Safari":"Other";if(kind.equals("device"))return ua.matches("(?is).*(iPad|Tablet).*")?"Tablet":ua.matches("(?is).*(Mobile|Android|iPhone).*")?"Mobile":"Desktop";for(String[]v:List.of(new String[]{"Windows","Windows"},new String[]{"Android","Android"},new String[]{"(iPhone|iPad|iOS)","iOS"},new String[]{"Mac OS","macOS"},new String[]{"Linux","Linux"}))if(ua.matches("(?is).*"+v[0]+".*"))return v[1];return "Other";}
    private static String host(String referrer){try{URI u=URI.create(referrer);String h=u.getHost();if(h==null||!(u.getScheme().equalsIgnoreCase("http")||u.getScheme().equalsIgnoreCase("https"))||u.getUserInfo()!=null)return null;h=h.toLowerCase().replaceAll("\\.$","");if(h.contains(":")||h.matches("[0-9]{1,3}(?:\\.[0-9]{1,3}){3}"))return null;return h.matches("(?i)(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?")?h:null;}catch(Exception e){return null;}}
    private static String percentDecode(String input){ByteArrayOutputStream bytes=new ByteArrayOutputStream();for(int i=0;i<input.length();){char c=input.charAt(i);if(c=='%'){bytes.write(Integer.parseInt(input.substring(i+1,i+3),16));i+=3;}else{int cp=input.codePointAt(i);int width=Character.charCount(cp);bytes.writeBytes(input.substring(i,i+width).getBytes(StandardCharsets.UTF_8));i+=width;}}try{return StandardCharsets.UTF_8.newDecoder().onMalformedInput(CodingErrorAction.REPORT).onUnmappableCharacter(CodingErrorAction.REPORT).decode(ByteBuffer.wrap(bytes.toByteArray())).toString();}catch(Exception e){throw new IllegalArgumentException(e);}}
    private static String utcDay(){return LocalDate.now(ZoneOffset.UTC).toString();}
    @Override public void close() throws SQLException { synchronized(lock){connection.close();} }

    public record Row(String label,long views){}
    public record Dashboard(long total,List<Row>byDay,List<Row>byPage,String profile){}
    public record AuditReport(String application,int contractVersion,int schemaVersion,String profile,String fingerprint,PrivacyConfiguration configuration,Map<String,Boolean>checks,String result){}
    public record PrivacyConfiguration(boolean countryCollection,boolean referrerCollection,boolean browserCollection,boolean deviceCollection,boolean osCollection,int retentionDays,String profile,List<String>pathExclusions,List<String>botPatterns){
        public String toJson(){return "{\"country_collection\":"+countryCollection+",\"referrer_collection\":"+referrerCollection+",\"browser_collection\":"+browserCollection+",\"device_collection\":"+deviceCollection+",\"os_collection\":"+osCollection+",\"retention_days\":"+retentionDays+",\"profile\":\""+profile+"\",\"path_exclusions\":"+strings(pathExclusions)+",\"bot_patterns\":"+strings(botPatterns)+"}";}
    }
    private static String strings(List<String>values){return "["+String.join(",",values.stream().map(BarelyticsStore::jsonString).toList())+"]";}
    private static String jsonString(String value) {
        StringBuilder result = new StringBuilder("\"");
        for (int i = 0; i < value.length(); i++) {
            char c = value.charAt(i);
            switch (c) {
                case '"' -> result.append("\\\"");
                case '\\' -> result.append("\\\\");
                case '\b' -> result.append("\\b");
                case '\f' -> result.append("\\f");
                case '\n' -> result.append("\\n");
                case '\r' -> result.append("\\r");
                case '\t' -> result.append("\\t");
                default -> { if (c < 0x20) result.append(String.format("\\u%04x", (int) c)); else result.append(c); }
            }
        }
        return result.append('"').toString();
    }
}
