package io.barelytics;

import jakarta.servlet.ServletException;
import jakarta.servlet.http.HttpServlet;
import jakarta.servlet.http.HttpServletRequest;
import jakarta.servlet.http.HttpServletResponse;
import java.io.IOException;
import java.io.InputStream;
import java.nio.charset.StandardCharsets;
import java.sql.SQLException;
import java.util.LinkedHashMap;
import java.util.Map;
import java.util.Objects;
import java.util.function.BiPredicate;
import java.util.function.Function;
import java.util.function.Predicate;

/** Framework-neutral shared admin UI/API servlet. The host supplies identity and CSRF callbacks. */
public final class BarelyticsAdminServlet extends HttpServlet {
    private final BarelyticsStore store;
    private final Predicate<HttpServletRequest> authorize;
    private final BiPredicate<HttpServletRequest,String> verifyCsrf;
    private final Function<HttpServletRequest,String> csrfToken;
    public BarelyticsAdminServlet(BarelyticsStore store,Predicate<HttpServletRequest> authorize,BiPredicate<HttpServletRequest,String> verifyCsrf,Function<HttpServletRequest,String> csrfToken){this.store=Objects.requireNonNull(store);this.authorize=Objects.requireNonNull(authorize);this.verifyCsrf=Objects.requireNonNull(verifyCsrf);this.csrfToken=Objects.requireNonNull(csrfToken);}

    @Override protected void doGet(HttpServletRequest request,HttpServletResponse response)throws IOException{
        security(response);if(!authorized(request,response))return;String path=request.getPathInfo();if(path==null||path.isEmpty()){response.sendRedirect(request.getRequestURI()+"/");return;}if(path.equals("/")){asset("index.html",response);return;}
        if(path.equals("/admin-ui/app.js")||path.equals("/admin-ui/admin.css")){asset(path.substring(path.lastIndexOf('/')+1),response);return;}
        if(path.equals("/admin-ui/config.js")){send(response,200,"text/javascript; charset=utf-8","window.BARELYTICS_ADMIN_CONFIG = { apiBase: 'api/' };");return;}
        if(!path.startsWith("/api/")){error(response,404,"not_found","Resource not found.");return;}
        String resource=path.substring(5);try{
            Object data;
            if(resource.equals("session"))data=Map.of("authenticated",true,"csrf",csrfToken.apply(request),"retention_days",store.configuration().retentionDays(),"capabilities",Map.of("logout",false,"delete_all",true));
            else{Map<String,String> query=new LinkedHashMap<>();request.getParameterMap().forEach((k,v)->query.put(k,v.length==0?"":v[v.length-1]));data=store.adminData(resource,query);}
            json(response,200,success(data));
        }catch(IllegalArgumentException e){error(response,400,"invalid_request","The request is invalid.");}catch(Exception e){error(response,500,"internal_error","The administration request failed.");}
    }
    @Override protected void doPost(HttpServletRequest request,HttpServletResponse response)throws IOException{
        security(response);if(!authorized(request,response))return;if(request.getContentLengthLong()>8192){error(response,413,"too_large","Request is too large.");return;}
        byte[] bytes=request.getInputStream().readNBytes(8193);if(bytes.length>8192){error(response,413,"too_large","Request is too large.");return;}
        try{
            Object parsed=BarelyticsJson.parse(new String(bytes,StandardCharsets.UTF_8));if(!(parsed instanceof Map<?,?> raw)){error(response,400,"invalid_request","The request is invalid.");return;}
            @SuppressWarnings("unchecked") Map<String,Object> input=(Map<String,Object>)raw;Object token=input.get("csrf");if(!(token instanceof String s)||!verifyCsrf.test(request,s)){error(response,403,"csrf","Request verification failed.");return;}
            String path=request.getPathInfo();if(path==null||!path.startsWith("/api/")){error(response,404,"not_found","Resource not found.");return;}String resource=path.substring(5);Object data;
            if(resource.equals("privacy"))data=store.updateAdminSettings(input);
            else if(resource.equals("strict")){store.returnToStrictMode();data=store.adminData("privacy",Map.of());}
            else if(resource.equals("cleanup"))data=Map.of("complete",store.cleanup(1000));
            else if(resource.equals("delete-all")&&"DELETE".equals(input.get("confirmation"))){store.deleteAll();data=Map.of("deleted",true);}
            else{error(response,400,"invalid_action","The administration action is not supported.");return;}
            json(response,200,success(data));
        }catch(IllegalArgumentException e){error(response,400,"invalid_request","The request is invalid.");}catch(Exception e){error(response,500,"internal_error","The administration request failed.");}
    }
    @Override protected void doHead(HttpServletRequest request,HttpServletResponse response)throws IOException{security(response);if(!authorized(request,response))return;response.setStatus(405);response.setHeader("Allow","GET, POST");}
    private boolean authorized(HttpServletRequest request,HttpServletResponse response)throws IOException{try{if(authorize.test(request))return true;}catch(RuntimeException ignored){}error(response,403,"forbidden","Administrator access required.");return false;}
    private void asset(String name,HttpServletResponse response)throws IOException{String type=name.endsWith(".html")?"text/html; charset=utf-8":name.endsWith(".css")?"text/css; charset=utf-8":"text/javascript; charset=utf-8";try(InputStream in=getClass().getResourceAsStream("/admin-ui/"+name)){if(in==null){error(response,503,"ui_unavailable","Administration UI is unavailable.");return;}send(response,200,type,new String(in.readAllBytes(),StandardCharsets.UTF_8));} }
    private static void security(HttpServletResponse r){r.setHeader("Cache-Control","no-store");r.setHeader("X-Content-Type-Options","nosniff");r.setHeader("Referrer-Policy","no-referrer");r.setHeader("Content-Security-Policy","default-src 'self'; script-src 'self'; style-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'");}
    private static void send(HttpServletResponse r,int status,String type,String body)throws IOException{r.setStatus(status);r.setContentType(type);r.setCharacterEncoding("UTF-8");r.getWriter().write(body);}
    private static Map<String,Object> success(Object data){Map<String,Object> b=new LinkedHashMap<>();b.put("ok",true);b.put("data",data);b.put("error",null);return b;}
    private static void json(HttpServletResponse r,int status,Object value)throws IOException{send(r,status,"application/json; charset=utf-8",BarelyticsJson.encode(value));}
    private static void error(HttpServletResponse r,int status,String code,String message)throws IOException{Map<String,Object> e=new LinkedHashMap<>();e.put("code",code);e.put("message",message);Map<String,Object> b=new LinkedHashMap<>();b.put("ok",false);b.put("data",null);b.put("error",e);json(r,status,b);}
}
