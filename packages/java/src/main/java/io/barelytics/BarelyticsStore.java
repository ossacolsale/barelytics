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
    public static final int CONTRACT_VERSION = 2;
    public static final int SCHEMA_VERSION = 3;
    public static final List<String> DIMENSIONS = List.of("country_collection", "referrer_collection", "browser_collection", "device_collection", "os_collection");
    public static final List<String> DEFAULT_EXCLUSIONS = List.of("/admin/*", "/admin.php", "/account/*", "/checkout/*", "/customer/*", "/patient/*", "/profile/*", "/private/*");
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
        try { migrate(); } catch (SQLException | RuntimeException e) { try { connection.close(); } catch (SQLException ignored) { } throw e; }
        if (exclusions != null) set("path_exclusions", String.join("\n", exclusions.stream().filter(BarelyticsStore::validPattern).distinct().sorted().toList()));
        recordHistory();
    }

    public Path databasePath() { return databasePath; }

    public static String normalizePath(String input) {
        if (input == null || input.isEmpty() || input.getBytes(StandardCharsets.UTF_8).length > 512 || input.codePoints().anyMatch(c -> c < 0x20 || c == 0x7f) || !input.startsWith("/") || input.startsWith("//") || input.contains("?") || input.contains("#")) return null;
        for (int i=0;i<input.length();i++) if (Character.isSurrogate(input.charAt(i)) && (i+1>=input.length() || !Character.isSurrogatePair(input.charAt(i),input.charAt(i+1)))) return null; else if(Character.isHighSurrogate(input.charAt(i))) i++;
        String path = input.replaceAll("/{2,}", "/");
        if (path.getBytes(StandardCharsets.UTF_8).length > 512 || Pattern.compile("%(?![0-9a-f]{2})", Pattern.CASE_INSENSITIVE).matcher(path).find()) return null;
        String decoded;
        try { decoded = percentDecode(path); } catch (IllegalArgumentException e) { return null; }
        if (decoded.codePoints().anyMatch(c -> c < 0x20 || c == 0x7f) || decoded.indexOf('@') >= 0 || decoded.indexOf('?') >= 0 || decoded.indexOf('#') >= 0 || decoded.indexOf('\\') >= 0 || decoded.indexOf('%') >= 0 || decoded.chars().filter(c -> c == '/').count() != path.chars().filter(c -> c == '/').count() || Pattern.compile("(?:^|/)\\.{1,2}(?:/|$)").matcher(decoded).find()) return null;
        if (!decoded.equals(path) && ID.matcher(decoded).find()) return null;
        return ID.matcher(path).replaceAll(":id");
    }

    public static boolean isBot(String userAgent) { return isBot(userAgent, List.of()); }
    public static boolean isBot(String userAgent, List<String> extra) {
        return AutomationClassifier.classify(userAgent, extra).automation();
    }

    public boolean trackPageView(String path, String userAgent, String country, String referrer) {
        String day = utcDay();
        synchronized (lock) {
            try {
                String normalized = normalizePath(path);
                PrivacyConfiguration config = configuration();
                if (normalized == null || excluded(normalized, config.pathExclusions())) return false;
                var classification = AutomationClassifier.classify(userAgent, config.botPatterns());
                connection.setAutoCommit(false);
                if (classification.automation()) {
                    upsert("INSERT INTO automation_daily(day,path,category,family,requests) VALUES (?,?,?,?,1) ON CONFLICT(day,path,category,family) DO UPDATE SET requests=requests+1", day, normalized, classification.category(), classification.family());
                } else {
                    String countryCode = config.countryCollection() && country != null && country.matches("[A-Za-z]{2}") ? country.toUpperCase(java.util.Locale.ROOT) : "XX";
                    upsert("INSERT INTO pageviews_daily(day,path,country,views) VALUES (?,?,?,1) ON CONFLICT(day,path,country) DO UPDATE SET views=views+1", day, normalized, countryCode);
                    String host = config.referrerCollection() ? host(referrer) : null;
                    if (host != null) upsert("INSERT INTO referrers_daily(day,path,referrer_host,views) VALUES (?,?,?,1) ON CONFLICT(day,path,referrer_host) DO UPDATE SET views=views+1", day, normalized, host);
                    String ua = userAgent == null ? "" : userAgent;
                    if (config.browserCollection()) upsert("INSERT INTO dimensions_daily(day,path,dimension,value,views) VALUES (?,?,?,?,1) ON CONFLICT(day,path,dimension,value) DO UPDATE SET views=views+1", day, normalized, "browser", category(ua,"browser"));
                    if (config.deviceCollection()) upsert("INSERT INTO dimensions_daily(day,path,dimension,value,views) VALUES (?,?,?,?,1) ON CONFLICT(day,path,dimension,value) DO UPDATE SET views=views+1", day, normalized, "device", category(ua,"device"));
                    if (config.osCollection()) upsert("INSERT INTO dimensions_daily(day,path,dimension,value,views) VALUES (?,?,?,?,1) ON CONFLICT(day,path,dimension,value) DO UPDATE SET views=views+1", day, normalized, "os", category(ua,"os"));
                }
                connection.commit(); connection.setAutoCommit(true);
                cleanupIfDue();
                return !classification.automation();
            } catch (SQLException | RuntimeException e) { rollback(); return false; }
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
        synchronized (lock) {
            connection.setAutoCommit(false);
            try { set("retention_days", Integer.toString(value)); recordHistory(); connection.commit(); }
            catch (SQLException | RuntimeException e) { rollback(); throw e; }
            finally { connection.setAutoCommit(true); }
        }
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
            checks.put("aggregate_schema", schemaValid(tables));
            checks.put("no_identity_or_event_tables", !forbidden); checks.put("no_ip_column", !ipColumn); checks.put("no_third_party_analytics", true);
            checks.put("path_normalization_active", "/users/:id".equals(normalizePath("/users/123456"))); checks.put("private_exclusions_active", excluded("/admin/example", config.pathExclusions()));
            String fingerprint = fingerprint(config);
            return new AuditReport("Barelytics Java", CONTRACT_VERSION, SCHEMA_VERSION, config.profile(), fingerprint, config, checks, checks.values().stream().allMatch(Boolean::booleanValue) ? "PASS" : "FAIL");
        }
    }

    private void cleanupIfDue() {
        try {
            String text = get("last_cleanup_at", "");
            OffsetDateTime last = null;
            try { if (!text.isEmpty()) last = OffsetDateTime.parse(text.contains("T") ? text : text.replace(' ', 'T') + "Z"); }
            catch (java.time.format.DateTimeParseException ignored) { }
            var age = last == null ? null : java.time.Duration.between(last, OffsetDateTime.now(ZoneOffset.UTC));
            if (age != null && !age.isNegative() && age.toHours() < 24 && !get("cleanup_pending", "0").equals("1")) return;
            cleanup(1000);
        } catch (SQLException | RuntimeException ignored) { }
    }

    private boolean schemaValid(List<String> tables) throws SQLException {
        Map<String,Set<String>> expected = Map.ofEntries(
            Map.entry("pageviews_daily", Set.of("day","path","country","views")),
            Map.entry("referrers_daily", Set.of("day","path","referrer_host","views")),
            Map.entry("dimensions_daily", Set.of("day","path","dimension","value","views")),
            Map.entry("automation_daily", Set.of("day","path","category","family","requests")),
            Map.entry("settings", Set.of("key","value")),
            Map.entry("schema_migrations", Set.of("version","applied_at")),
            Map.entry("privacy_configuration_history", Set.of("timestamp","profile","effective_configuration_json","configuration_hash","application_version","schema_version"))
        );
        if (!tables.containsAll(expected.keySet())) return false;
        for (String table : tables) {
            if (table.startsWith("sqlite_")) continue;
            if (!expected.containsKey(table)) return false;
            var columns = new java.util.HashSet<String>();
            for (var row : adminRows("PRAGMA table_info(\"" + table.replace("\"", "\"\"") + "\")")) columns.add((String)row.get("name"));
            if (!columns.equals(expected.get(table))) return false;
        }
        return true;
    }

    public List<Map<String,Object>> configurationHistory() throws SQLException { return adminRows("SELECT timestamp,profile,effective_configuration_json,configuration_hash,application_version,schema_version FROM privacy_configuration_history ORDER BY rowid DESC LIMIT 100"); }

    public List<String> historicalDimensions() throws SQLException {
        synchronized (lock) {
            PrivacyConfiguration config = configuration(); List<String> result = new ArrayList<>();
            if (!config.countryCollection() && scalar("SELECT COUNT(*) FROM (SELECT 1 FROM pageviews_daily WHERE country<>'XX' LIMIT 1)") > 0) result.add("country");
            if (!config.referrerCollection() && scalar("SELECT COUNT(*) FROM (SELECT 1 FROM referrers_daily LIMIT 1)") > 0) result.add("referrer");
            for (String name : List.of("browser","device","os")) if (!enabled(config,name+"_collection") && scalar("SELECT COUNT(*) FROM (SELECT 1 FROM dimensions_daily WHERE dimension=? LIMIT 1)", name) > 0) result.add(name);
            return List.copyOf(result);
        }
    }

    public boolean cleanup(int limit) throws SQLException {
        int bound = Math.min(1000, Math.max(1, limit)); String cutoff = LocalDate.now(ZoneOffset.UTC).minusDays(configuration().retentionDays()).toString();
        synchronized (lock) {
            connection.setAutoCommit(false);
            try {
                boolean complete = true;
                for (String table : List.of("pageviews_daily","referrers_daily","dimensions_daily","automation_daily")) {
                    long count = scalar("SELECT COUNT(*) FROM (SELECT rowid FROM "+table+" WHERE day<? ORDER BY day LIMIT ?)", cutoff, bound);
                    try (PreparedStatement p = connection.prepareStatement("DELETE FROM "+table+" WHERE rowid IN (SELECT rowid FROM "+table+" WHERE day<? ORDER BY day LIMIT ?)")) { p.setString(1,cutoff); p.setInt(2,bound); p.executeUpdate(); }
                    if(count==bound) complete=false;
                }
                set("last_cleanup_at", OffsetDateTime.now(ZoneOffset.UTC).toString()); set("cleanup_pending", complete ? "0" : "1"); connection.commit(); return complete;
            } catch(SQLException e){rollback();return false;} finally {connection.setAutoCommit(true);}
        }
    }

    public Map<String,Object> adminData(String resource, Map<String,String> query) throws SQLException {
        synchronized (lock) {
            PrivacyConfiguration config=configuration();
            int requested;try{requested=Integer.parseInt(query.getOrDefault("period","30"));}catch(NumberFormatException e){requested=30;}
            int period=List.of(7,30,90,180,365).contains(requested)?Math.min(requested,config.retentionDays()):Math.min(30,config.retentionDays());
            String from=LocalDate.now(ZoneOffset.UTC).minusDays(period-1L).toString(),to=utcDay();
            String bucket=query.getOrDefault("bucket","day");if(!List.of("day","week","month").contains(bucket))bucket="day";
            String group=switch(bucket){case "week"->"strftime('%Y-W%W',day)";case "month"->"substr(day,1,7)";default->"day";};
            int page,perPage;try{page=Math.max(1,Math.min(10000,Integer.parseInt(query.getOrDefault("page","1"))));}catch(NumberFormatException e){page=1;}try{perPage=Math.max(1,Math.min(50,Integer.parseInt(query.getOrDefault("per_page","50"))));}catch(NumberFormatException e){perPage=50;}
            int offset=(page-1)*perPage;
            if (resource.equals("automation")) {
                long total = adminScalar("SELECT COALESCE(SUM(requests),0) FROM automation_daily WHERE day BETWEEN ? AND ?", from, to);
                long count = adminScalar("SELECT COUNT(*) FROM (SELECT path FROM automation_daily WHERE day BETWEEN ? AND ? GROUP BY path)", from, to);
                Map<String,Object> result = new LinkedHashMap<>();
                result.put("total",total); result.put("verified",false); result.put("period",period); result.put("page",page); result.put("per_page",perPage); result.put("total_rows",count); result.put("pages",(int)Math.ceil((double)count/perPage));
                result.put("timeline",adminRows("SELECT "+group+" AS period,SUM(requests) AS requests FROM automation_daily WHERE day BETWEEN ? AND ? GROUP BY period ORDER BY period",from,to));
                result.put("categories",adminRows("SELECT category,SUM(requests) AS requests FROM automation_daily WHERE day BETWEEN ? AND ? GROUP BY category ORDER BY requests DESC,category",from,to));
                result.put("families",adminRows("SELECT category,family,SUM(requests) AS requests FROM automation_daily WHERE day BETWEEN ? AND ? GROUP BY category,family ORDER BY requests DESC,category,family",from,to));
                result.put("rows",adminRows("SELECT path,SUM(requests) AS requests FROM automation_daily WHERE day BETWEEN ? AND ? GROUP BY path ORDER BY requests DESC,path LIMIT ? OFFSET ?",from,to,perPage,offset));
                return result;
            }
            if(resource.equals("privacy"))return privacyMap(config);
            if(resource.equals("dashboard")){
                long total=adminScalar("SELECT COALESCE(SUM(views),0) FROM pageviews_daily WHERE day BETWEEN ? AND ?",from,to);
                long active=adminScalar("SELECT COUNT(DISTINCT path) FROM pageviews_daily WHERE day BETWEEN ? AND ?",from,to);
                return Map.of("total",total,"active_pages",active,"daily_average",Math.round((double)total/period*10.0)/10.0,"timeline",adminRows("SELECT "+group+" AS period,SUM(views) AS views FROM pageviews_daily WHERE day BETWEEN ? AND ? GROUP BY period ORDER BY period",from,to),"top_pages",adminRows("SELECT path,SUM(views) AS views FROM pageviews_daily WHERE day BETWEEN ? AND ? GROUP BY path ORDER BY views DESC,path LIMIT 10",from,to),"period",period,"bucket",bucket);
            }
            if(resource.equals("pages")||resource.equals("daily")){
                String grouped=resource.equals("pages")?"path":"day,path";long count=adminScalar("SELECT COUNT(*) FROM (SELECT "+grouped+" FROM pageviews_daily WHERE day BETWEEN ? AND ? GROUP BY "+grouped+")",from,to);
                String order=resource.equals("pages")?"views DESC,path":"day DESC,views DESC,path";
                return Map.of("rows",adminRows("SELECT "+grouped+",SUM(views) AS views FROM pageviews_daily WHERE day BETWEEN ? AND ? GROUP BY "+grouped+" ORDER BY "+order+" LIMIT ? OFFSET ?",from,to,perPage,offset),"total_rows",count,"page",page,"per_page",perPage,"pages",(int)Math.ceil((double)count/perPage),"period",period);
            }
            if(resource.equals("page")){
                String path=normalizePath(query.getOrDefault("path",""));if(path==null)throw new IllegalArgumentException("Invalid page path.");
                return Map.of("path",path,"total",adminScalar("SELECT COALESCE(SUM(views),0) FROM pageviews_daily WHERE path=? AND day BETWEEN ? AND ?",path,from,to),"timeline",adminRows("SELECT "+group+" AS period,SUM(views) AS views FROM pageviews_daily WHERE path=? AND day BETWEEN ? AND ? GROUP BY period ORDER BY period",path,from,to),"period",period,"bucket",bucket);
            }
            if(resource.equals("dimensions")){
                String pagePath = query.containsKey("path") ? normalizePath(query.get("path")) : null;
                if (query.containsKey("path") && pagePath == null) throw new IllegalArgumentException("Invalid page path.");
                String dimension=query.getOrDefault("dimension","country");Map<String,String[]> map=Map.of("country",new String[]{"country_collection","pageviews_daily","country"},"referrer",new String[]{"referrer_collection","referrers_daily","referrer_host"},"browser",new String[]{"browser_collection","dimensions_daily","value"},"device",new String[]{"device_collection","dimensions_daily","value"},"os",new String[]{"os_collection","dimensions_daily","value"});
                String[] spec=map.get(dimension);if(spec==null)throw new IllegalArgumentException("Unsupported dimension.");boolean enabled=enabled(config,spec[0]);
                if(!enabled)return Map.of("dimension",dimension,"label",dimension,"enabled",false,"rows",List.of(),"total_rows",0,"page",1,"pages",1);
                String where=spec[1].equals("dimensions_daily")?"dimension=? AND day BETWEEN ? AND ?":"day BETWEEN ? AND ?";Object[] args=spec[1].equals("dimensions_daily")?new Object[]{dimension,from,to}:new Object[]{from,to};
                if (query.containsKey("path")) { where += " AND path=?"; args = java.util.Arrays.copyOf(args,args.length+1); args[args.length-1] = pagePath; }
                long count=adminScalar("SELECT COUNT(*) FROM (SELECT "+spec[2]+" FROM "+spec[1]+" WHERE "+where+" GROUP BY "+spec[2]+")",args);
                Object[] rowArgs=java.util.Arrays.copyOf(args,args.length+2);rowArgs[args.length]=perPage;rowArgs[args.length+1]=offset;
                return Map.of("dimension",dimension,"label",dimension,"enabled",true,"rows",adminRows("SELECT "+spec[2]+" AS value,SUM(views) AS views FROM "+spec[1]+" WHERE "+where+" GROUP BY "+spec[2]+" ORDER BY views DESC,value LIMIT ? OFFSET ?",rowArgs),"total_rows",count,"page",page,"per_page",perPage,"pages",(int)Math.ceil((double)count/perPage));
            }
            if(resource.equals("system"))return Map.of("application","Barelytics Java","runtime",System.getProperty("java.version"),"schema_version",SCHEMA_VERSION,"current_schema_version",SCHEMA_VERSION,"migration_required",false,"capabilities",Map.of("cleanup",true,"delete_all",true,"logout",false));
            if(resource.equals("audit")){AuditReport report=audit();return Map.of("application",report.application(),"contract_version",report.contractVersion(),"schema_version",report.schemaVersion(),"profile",report.profile(),"fingerprint",report.fingerprint(),"configuration",privacyMap(report.configuration()),"historical_dimensions",historicalDimensions(),"configuration_history",configurationHistory(),"checks",report.checks(),"result",report.result());}
            if(resource.equals("integration"))return Map.of("instructions","Mount BarelyticsAdminServlet and supply host authorization and CSRF callbacks.","javascript_snippet","new BarelyticsAdminServlet(store, authorizeAdmin, verifyCsrf, csrfToken)");
            throw new IllegalArgumentException("Unknown administration resource.");
        }
    }

    public Map<String,Object> updateAdminSettings(Map<String,Object> input)throws SQLException{
        int retention;try{retention=((Number)input.get("retention_days")).intValue();}catch(Exception e){throw new IllegalArgumentException("Invalid retention period.");}if(!Set.of(30,90,180,365).contains(retention))throw new IllegalArgumentException("Invalid retention period.");
        List<String> paths=settingLines(input.get("path_exclusions"),4000,true),bots=settingLines(input.get("bot_patterns"),2000,false);
        boolean extended=DIMENSIONS.stream().anyMatch(k->Boolean.TRUE.equals(input.get(k)));if(extended&&!"yes".equals(input.get("confirmation")))throw new IllegalArgumentException("Confirm optional dimension collection.");
        synchronized(lock){connection.setAutoCommit(false);try{for(String key:DIMENSIONS)set(key,Boolean.TRUE.equals(input.get(key))?"1":"0");set("retention_days",Integer.toString(retention));set("path_exclusions",String.join("\n",paths));set("bot_patterns",String.join("\n",bots));recordHistory();connection.commit();}catch(SQLException|RuntimeException e){connection.rollback();throw e;}finally{connection.setAutoCommit(true);}}
        return privacyMap(configuration());
    }

    public void deleteAll()throws SQLException{synchronized(lock){connection.setAutoCommit(false);try(Statement s=connection.createStatement()){for(String table:List.of("pageviews_daily","referrers_daily","dimensions_daily","automation_daily"))s.executeUpdate("DELETE FROM "+table);connection.commit();}catch(SQLException e){connection.rollback();throw e;}finally{connection.setAutoCommit(true);}}}

    private long adminScalar(String sql,Object...args)throws SQLException{synchronized(lock){try(PreparedStatement p=connection.prepareStatement(sql)){for(int i=0;i<args.length;i++)p.setObject(i+1,args[i]);try(ResultSet r=p.executeQuery()){return r.next()?r.getLong(1):0;}}}}
    private long scalar(String sql,Object...values)throws SQLException{try(PreparedStatement p=connection.prepareStatement(sql)){for(int i=0;i<values.length;i++)p.setObject(i+1,values[i]);try(ResultSet r=p.executeQuery()){return r.next()?r.getLong(1):0;}}}
    private List<Map<String,Object>> adminRows(String sql,Object...args)throws SQLException{synchronized(lock){try(PreparedStatement p=connection.prepareStatement(sql)){for(int i=0;i<args.length;i++)p.setObject(i+1,args[i]);try(ResultSet r=p.executeQuery()){List<Map<String,Object>> rows=new ArrayList<>();while(r.next()){Map<String,Object> row=new LinkedHashMap<>();for(int i=1;i<=r.getMetaData().getColumnCount();i++)row.put(r.getMetaData().getColumnLabel(i),r.getObject(i));rows.add(row);}return rows;}}}}
    private static boolean enabled(PrivacyConfiguration c,String key){return switch(key){case "country_collection"->c.countryCollection();case "referrer_collection"->c.referrerCollection();case "browser_collection"->c.browserCollection();case "device_collection"->c.deviceCollection();default->c.osCollection();};}
    private static Map<String,Object> privacyMap(PrivacyConfiguration c){Map<String,Object> m=new LinkedHashMap<>();m.put("automation_collection",true);m.put("daily_reach_available",false);m.put("daily_page_reach_available",false);m.put("daily_page_reach_enabled",false);m.put("reporting_day","UTC");m.put("country_collection",c.countryCollection());m.put("referrer_collection",c.referrerCollection());m.put("browser_collection",c.browserCollection());m.put("device_collection",c.deviceCollection());m.put("os_collection",c.osCollection());m.put("retention_days",c.retentionDays());m.put("profile",c.profile());m.put("path_exclusions",c.pathExclusions());m.put("bot_patterns",c.botPatterns());return m;}
    private static List<String> settingLines(Object value,int max,boolean paths){if(!(value instanceof String raw)||raw.getBytes(StandardCharsets.UTF_8).length>max)throw new IllegalArgumentException("Invalid settings.");List<String> lines=raw.lines().map(String::trim).filter(x->!x.isEmpty()).distinct().sorted().toList();if(lines.stream().anyMatch(x->x.length()>200||(paths&&(!x.startsWith("/")||x.contains("?")||x.contains("#")))))throw new IllegalArgumentException("Invalid settings.");return lines;}

    private void migrate() throws SQLException {
        synchronized(lock){ connection.setAutoCommit(false); try {
        try (Statement s=connection.createStatement()) {
            for (String sql : List.of(
                "CREATE TABLE IF NOT EXISTS schema_migrations(version INTEGER PRIMARY KEY,applied_at TEXT NOT NULL)",
                "CREATE TABLE IF NOT EXISTS automation_daily(day TEXT NOT NULL,path TEXT NOT NULL,category TEXT NOT NULL,family TEXT NOT NULL,requests INTEGER NOT NULL DEFAULT 0,PRIMARY KEY(day,path,category,family))",
                "CREATE TABLE IF NOT EXISTS settings(key TEXT PRIMARY KEY,value TEXT NOT NULL)",
                "CREATE TABLE IF NOT EXISTS pageviews_daily(day TEXT NOT NULL,path TEXT NOT NULL,country TEXT NOT NULL DEFAULT 'XX',views INTEGER NOT NULL DEFAULT 0,PRIMARY KEY(day,path,country))",
                "CREATE TABLE IF NOT EXISTS referrers_daily(day TEXT NOT NULL,path TEXT NOT NULL,referrer_host TEXT NOT NULL,views INTEGER NOT NULL DEFAULT 0,PRIMARY KEY(day,path,referrer_host))",
                "CREATE TABLE IF NOT EXISTS dimensions_daily(day TEXT NOT NULL,path TEXT NOT NULL,dimension TEXT NOT NULL,value TEXT NOT NULL,views INTEGER NOT NULL DEFAULT 0,PRIMARY KEY(day,path,dimension,value))",
                "CREATE TABLE IF NOT EXISTS privacy_configuration_history(timestamp TEXT NOT NULL,profile TEXT NOT NULL,effective_configuration_json TEXT NOT NULL,configuration_hash TEXT NOT NULL,application_version TEXT NOT NULL,schema_version INTEGER NOT NULL)")) s.execute(sql);
        }
            if (scalar("SELECT COALESCE(MAX(version),0) FROM schema_migrations") > SCHEMA_VERSION) throw new SQLException("The database requires a newer Barelytics runtime.");
            try(PreparedStatement p=connection.prepareStatement("INSERT OR IGNORE INTO schema_migrations(version,applied_at) VALUES (?,?)")){for(int version:List.of(1,2,SCHEMA_VERSION)){p.setInt(1,version);p.setString(2,OffsetDateTime.now(ZoneOffset.UTC).toString());p.executeUpdate();}}
            for(String key:DIMENSIONS)if(!List.of("0","1").contains(get(key,"0")))set(key,"0");
            set("retention_days",get("retention_days","180"));set("path_exclusions",get("path_exclusions",String.join("\n",DEFAULT_EXCLUSIONS)));set("bot_patterns",get("bot_patterns",""));connection.commit();
        }catch(SQLException e){connection.rollback();throw e;}finally{connection.setAutoCommit(true);}}
    }

    public PrivacyConfiguration configuration() throws SQLException {
        synchronized (lock) {
            Map<String,Boolean> f=new TreeMap<>();for(String key:DIMENSIONS)f.put(key,"1".equals(get(key,"0")));
            int retention;try{retention=Integer.parseInt(get("retention_days","180"));}catch(NumberFormatException e){retention=180;}if(!Set.of(30,90,180,365).contains(retention))retention=180;
            List<String> exclusions=List.of(get("path_exclusions",String.join("\n",DEFAULT_EXCLUSIONS)).split("\n")).stream().filter(BarelyticsStore::validPattern).distinct().sorted().toList();
            List<String> bots=List.of(get("bot_patterns","").split("\n")).stream().filter(x->!x.isEmpty()&&x.length()<=200).toList();
            String profile=f.values().stream().anyMatch(Boolean::booleanValue)?"extended":"strict";
            return new PrivacyConfiguration(f.get(DIMENSIONS.get(0)),f.get(DIMENSIONS.get(1)),f.get(DIMENSIONS.get(2)),f.get(DIMENSIONS.get(3)),f.get(DIMENSIONS.get(4)),retention,profile,exclusions,bots);
        }
    }

    private void recordHistory() throws SQLException { PrivacyConfiguration c=configuration();String json=c.toJson();if(fingerprint(c).equals(getLatestFingerprint()))return;upsert("INSERT INTO privacy_configuration_history VALUES (?,?,?,?,?,?)",OffsetDateTime.now(ZoneOffset.UTC).toString(),c.profile(),json,fingerprint(c),"1.2.1",SCHEMA_VERSION); }
    private String getLatestFingerprint() throws SQLException { try (var s = connection.createStatement(); var r = s.executeQuery("SELECT configuration_hash FROM privacy_configuration_history ORDER BY rowid DESC LIMIT 1")) { return r.next() ? r.getString(1) : ""; } }
    private String fingerprint(PrivacyConfiguration c) throws SQLException {try{String json="{\"contract_version\":"+CONTRACT_VERSION+",\"schema_version\":"+SCHEMA_VERSION+",\"configuration\":"+c.toJson()+"}";return HexFormat.of().formatHex(MessageDigest.getInstance("SHA-256").digest(json.getBytes(StandardCharsets.UTF_8)));}catch(Exception e){throw new SQLException(e);}}
    private String get(String key,String fallback) throws SQLException {try(PreparedStatement p=connection.prepareStatement("SELECT value FROM settings WHERE key=?")){p.setString(1,key);try(ResultSet r=p.executeQuery()){return r.next()?r.getString(1):fallback;}}}
    private void set(String key,String value) throws SQLException {upsert("INSERT INTO settings(key,value) VALUES (?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value",key,value);}
    private void upsert(String sql,Object...values)throws SQLException{try(PreparedStatement p=connection.prepareStatement(sql)){for(int i=0;i<values.length;i++)p.setObject(i+1,values[i]);p.executeUpdate();}}
    private List<Row> pairs(String sql,String from,String to)throws SQLException{List<Row>rows=new ArrayList<>();try(PreparedStatement p=connection.prepareStatement(sql)){p.setString(1,from);p.setString(2,to);try(ResultSet r=p.executeQuery()){while(r.next())rows.add(new Row(r.getString(1),r.getLong(2)));}}return List.copyOf(rows);}
    private void rollback(){try{connection.rollback();}catch(SQLException ignored){}try{connection.setAutoCommit(true);}catch(SQLException ignored){}}
    private static boolean validPattern(String x){return x.startsWith("/")&&x.length()<=200&&!x.contains("?")&&!x.contains("#");}
    private static boolean excluded(String input,List<String>patterns){String path=percentDecode(input);return patterns.stream().anyMatch(p->p.endsWith("/*")?(path.equals(p.substring(0,p.length()-2))||path.startsWith(p.substring(0,p.length()-1))):path.equals(p));}
    private static String category(String ua,String kind){if(ua.isBlank())return "Unknown";if(kind.equals("browser"))return ua.matches("(?is).*Firefox/.*")?"Firefox":ua.matches("(?is).*(Edg/|Edge/).*" )?"Edge":ua.matches("(?is).*Chrome/.*")?"Chrome":ua.matches("(?is).*Safari/.*")?"Safari":"Other";if(kind.equals("device"))return ua.matches("(?is).*(iPad|Tablet).*")?"Tablet":ua.matches("(?is).*(Mobile|Android|iPhone).*")?"Mobile":"Desktop";for(String[]v:List.of(new String[]{"Windows","Windows"},new String[]{"Android","Android"},new String[]{"(iPhone|iPad|iOS)","iOS"},new String[]{"Mac OS","macOS"},new String[]{"Linux","Linux"}))if(ua.matches("(?is).*"+v[0]+".*"))return v[1];return "Other";}
    public static boolean validReferrerHostname(String value) { return value != null && !value.matches("(?:[0-9]+|0x[0-9a-f]+)(?:\\.(?:[0-9]+|0x[0-9a-f]+))*") && value.matches("(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?"); }
    private static String host(String referrer){if(referrer==null||referrer.length()>2048||referrer.getBytes(StandardCharsets.UTF_8).length>2048)return null;try{URI u=URI.create(referrer);String h=u.getHost();if(h==null||!(u.getScheme().equalsIgnoreCase("http")||u.getScheme().equalsIgnoreCase("https"))||u.getUserInfo()!=null)return null;h=h.toLowerCase(java.util.Locale.ROOT).replaceAll("\\.$","");if(h.contains(":")||h.matches("[0-9]{1,3}(?:\\.[0-9]{1,3}){3}"))return null;return validReferrerHostname(h)?h:null;}catch(Exception e){return null;}}
    private static String percentDecode(String input){ByteArrayOutputStream bytes=new ByteArrayOutputStream();for(int i=0;i<input.length();){char c=input.charAt(i);if(c=='%'){bytes.write(Integer.parseInt(input.substring(i+1,i+3),16));i+=3;}else{int cp=input.codePointAt(i);int width=Character.charCount(cp);bytes.writeBytes(input.substring(i,i+width).getBytes(StandardCharsets.UTF_8));i+=width;}}try{return StandardCharsets.UTF_8.newDecoder().onMalformedInput(CodingErrorAction.REPORT).onUnmappableCharacter(CodingErrorAction.REPORT).decode(ByteBuffer.wrap(bytes.toByteArray())).toString();}catch(Exception e){throw new IllegalArgumentException(e);}}
    private static String utcDay(){return LocalDate.now(ZoneOffset.UTC).toString();}
    @Override public void close() throws SQLException { synchronized(lock){connection.close();} }

    public record Row(String label,long views){}
    public record Dashboard(long total,List<Row>byDay,List<Row>byPage,String profile){}
    public record AuditReport(String application,int contractVersion,int schemaVersion,String profile,String fingerprint,PrivacyConfiguration configuration,Map<String,Boolean>checks,String result){}
    public record PrivacyConfiguration(boolean countryCollection,boolean referrerCollection,boolean browserCollection,boolean deviceCollection,boolean osCollection,int retentionDays,String profile,List<String>pathExclusions,List<String>botPatterns){
        public boolean automationCollection(){return true;}
        public boolean dailyReachAvailable(){return false;}
        public String reportingDay(){return "UTC";}
        public String toJson(){return "{\"automation_collection\":true,\"daily_reach_available\":false,\"daily_page_reach_available\":false,\"daily_page_reach_enabled\":false,\"reporting_day\":\"UTC\",\"country_collection\":"+countryCollection+",\"referrer_collection\":"+referrerCollection+",\"browser_collection\":"+browserCollection+",\"device_collection\":"+deviceCollection+",\"os_collection\":"+osCollection+",\"retention_days\":"+retentionDays+",\"profile\":\""+profile+"\",\"path_exclusions\":"+strings(pathExclusions)+",\"bot_patterns\":"+strings(botPatterns)+"}";}
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
