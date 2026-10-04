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
                try(var versions=count.executeQuery("SELECT COUNT(*) FROM schema_migrations")){assertTrue(versions.next());assertEquals(2,versions.getInt(1));}
            }
        }
        try(BarelyticsStore migratedAgain=new BarelyticsStore(temp)){assertEquals("PASS",migratedAgain.audit().result());}
    }

    private static String unescape(String s){StringBuilder o=new StringBuilder();for(int i=0;i<s.length();i++){char c=s.charAt(i);if(c!='\\'){o.append(c);continue;}char e=s.charAt(++i);switch(e){case 'u'-> {o.append((char)Integer.parseInt(s.substring(i+1,i+5),16));i+=4;}case 'n'->o.append('\n');case 'r'->o.append('\r');case 't'->o.append('\t');case 'b'->o.append('\b');case 'f'->o.append('\f');default->o.append(e);}}return o.toString();}
}
