package io.barelytics;

import java.lang.reflect.Array;
import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.Map;

/** Small bounded JSON codec used by the framework-neutral admin servlet. */
final class BarelyticsJson {
    private BarelyticsJson() {}
    static Object parse(String input) { Parser p=new Parser(input);Object value=p.value();p.space();if(p.i!=input.length())throw new IllegalArgumentException();return value; }
    static String encode(Object value) { StringBuilder out=new StringBuilder();write(out,value);return out.toString(); }
    private static void write(StringBuilder out,Object value){
        if(value==null){out.append("null");return;}if(value instanceof String s){quote(out,s);return;}if(value instanceof Number||value instanceof Boolean){out.append(value);return;}
        if(value instanceof Map<?,?> map){out.append('{');boolean first=true;for(var entry:map.entrySet()){if(!first)out.append(',');first=false;quote(out,String.valueOf(entry.getKey()));out.append(':');write(out,entry.getValue());}out.append('}');return;}
        if(value instanceof Iterable<?> values){out.append('[');boolean first=true;for(Object item:values){if(!first)out.append(',');first=false;write(out,item);}out.append(']');return;}
        if(value.getClass().isArray()){out.append('[');for(int i=0;i<Array.getLength(value);i++){if(i>0)out.append(',');write(out,Array.get(value,i));}out.append(']');return;}
        quote(out,String.valueOf(value));
    }
    private static void quote(StringBuilder out,String s){out.append('"');for(int i=0;i<s.length();i++){char c=s.charAt(i);switch(c){case '"'->out.append("\\\"");case '\\'->out.append("\\\\");case '\b'->out.append("\\b");case '\f'->out.append("\\f");case '\n'->out.append("\\n");case '\r'->out.append("\\r");case '\t'->out.append("\\t");default->{if(c<0x20)out.append(String.format("\\u%04x",(int)c));else out.append(c);}}}out.append('"');}
    private static final class Parser{
        final String s;int i;Parser(String s){this.s=s;}void space(){while(i<s.length()&&Character.isWhitespace(s.charAt(i)))i++;}
        Object value(){space();if(i>=s.length())throw new IllegalArgumentException();char c=s.charAt(i);if(c=='{')return object();if(c=='[')return array();if(c=='"')return string();if(c=='t'&&literal("true"))return true;if(c=='f'&&literal("false"))return false;if(c=='n'&&literal("null"))return null;return number();}
        boolean literal(String word){if(s.startsWith(word,i)){i+=word.length();return true;}return false;}
        Map<String,Object> object(){i++;space();Map<String,Object> m=new LinkedHashMap<>();if(take('}'))return m;while(true){space();if(i>=s.length()||s.charAt(i)!='"')throw new IllegalArgumentException();String k=string();space();if(!take(':'))throw new IllegalArgumentException();m.put(k,value());space();if(take('}'))return m;if(!take(','))throw new IllegalArgumentException();}}
        ArrayList<Object> array(){i++;space();ArrayList<Object>a=new ArrayList<>();if(take(']'))return a;while(true){a.add(value());space();if(take(']'))return a;if(!take(','))throw new IllegalArgumentException();}}
        String string(){i++;StringBuilder b=new StringBuilder();while(i<s.length()){char c=s.charAt(i++);if(c=='"')return b.toString();if(c=='\\'){if(i>=s.length())throw new IllegalArgumentException();char e=s.charAt(i++);switch(e){case '"','\\','/'->b.append(e);case 'b'->b.append('\b');case 'f'->b.append('\f');case 'n'->b.append('\n');case 'r'->b.append('\r');case 't'->b.append('\t');case 'u'->{if(i+4>s.length())throw new IllegalArgumentException();try{b.append((char)Integer.parseInt(s.substring(i,i+4),16));}catch(NumberFormatException x){throw new IllegalArgumentException();}i+=4;}default->throw new IllegalArgumentException();}}else{if(c<0x20)throw new IllegalArgumentException();b.append(c);}}throw new IllegalArgumentException();}
        Number number(){int start=i;if(i<s.length()&&s.charAt(i)=='-')i++;while(i<s.length()&&Character.isDigit(s.charAt(i)))i++;boolean decimal=false;if(i<s.length()&&s.charAt(i)=='.'){decimal=true;i++;while(i<s.length()&&Character.isDigit(s.charAt(i)))i++;}if(i<s.length()&&(s.charAt(i)=='e'||s.charAt(i)=='E')){decimal=true;i++;if(i<s.length()&&(s.charAt(i)=='+'||s.charAt(i)=='-'))i++;while(i<s.length()&&Character.isDigit(s.charAt(i)))i++;}if(i==start)throw new IllegalArgumentException();String n=s.substring(start,i);try{return decimal?Double.parseDouble(n):Long.parseLong(n);}catch(NumberFormatException e){throw new IllegalArgumentException();}}
        boolean take(char c){if(i<s.length()&&s.charAt(i)==c){i++;return true;}return false;}
    }
}
