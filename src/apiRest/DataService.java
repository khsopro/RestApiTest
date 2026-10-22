package apiRest;

import java.util.*;
import java.text.ParseException;
import java.time.Instant;
import lotus.domino.*;

import com.ibm.icu.text.SimpleDateFormat;

import org.json.JSONArray;
import org.json.JSONObject;

public class DataService {

    /**
     * Fingerabdruck des Ergebnisses fuer den ETag: durchlaeuft dieselben
     * Eintraege wie getData(), liest je Eintrag aber nur getLastModified()
     * statt die Felder zu konvertieren und JSON aufzubauen. Erkennt damit auch
     * replizierte Aenderungen (anders als Database.getLastModified()) und ist
     * trotz zweitem View-Durchlauf deutlich guenstiger als der volle JSON-Aufbau.
     */
    public static String getCacheKey(
        Session session,
        Map<String, Object> config
    ) throws Exception {

        Database db = session.getCurrentDatabase();

        String viewName = (String) config.get("view");

        View view = db.getView(viewName);
        view.setAutoUpdate(false);

        ViewNavigator nav = view.createViewNav();
        ViewEntry entry = nav.getFirst();

        long latest = 0;
        int count = 0;

        while(entry != null) {

            if(entry.isCategory()) {
                entry = nav.getNext(entry);
                continue;
            }

            Document doc = entry.getDocument();
            DateTime modified = doc.getLastModified();
            long modifiedMillis = modified.toJavaDate().getTime();
            modified.recycle();
            doc.recycle();

            if(modifiedMillis > latest) {
                latest = modifiedMillis;
            }

            ViewEntry tmp = nav.getNext(entry);
            entry.recycle();
            entry = tmp;

            count++;
        }

        view.recycle();
        db.recycle();

        return viewName + "|" + count + "|" + latest;
    }

    /**
     * Baut das komplette JSON des Endpoints - ohne Limit und ohne start, wie
     * der ApiCacheAgent. Paging/Sortierung passieren in ApiServiceBean. Bei
     * "arr" wird das gruppierte Objekt per groupsToJson() geschrieben, damit
     * die Reihenfolge der Bereiche der View entspricht (org.json JSONObject
     * haelt die Schluessel in einer HashMap und wuerfelt sie durcheinander).
     */
    public static String getData(
        Session session,
        Map<String, Object> config
    ) throws Exception {

        Database db = session.getCurrentDatabase();

        String viewName = (String) config.get("view");
        String jsonType = (String) config.get("art");

        if(jsonType == null) jsonType = "obj";

        View view = db.getView(viewName);
        view.setAutoUpdate(false);

        ViewNavigator nav = view.createViewNav();
        ViewEntry entry = nav.getFirst();

        JSONArray flat = new JSONArray();
        Map<String, JSONArray> groups = new LinkedHashMap<>();

        @SuppressWarnings("unchecked")
        List<Map<String, Object>> fields =
            (List<Map<String, Object>>) config.get("fields");

        int categoryCol = config.containsKey("titlecol")
                ? ((Number) config.get("titlecol")).intValue()
                : -1;

        while(entry != null) {

            if(entry.isCategory()) {
                entry = nav.getNext(entry);
                continue;
            }

            Vector values = entry.getColumnValues();

            // --- Kategorie ---
            String category = "default";
            if(categoryCol >= 0 && categoryCol < values.size() && values.get(categoryCol) != null) {
                category = values.get(categoryCol).toString();
            }

            // --- Objekt ---
            JSONObject obj = new JSONObject();

            for(Map<String, Object> f : fields) {

                int col = ((Number) f.get("column")).intValue();
                String json = (String) f.get("json");
                String type = (String) f.get("type");

                Object raw = (col < values.size()) ? values.get(col) : null;

                obj.put(json, convertValue(raw, type));
            }

         // --- flat ---
            if("obj".equals(jsonType) || "both".equals(jsonType)) {
                flat.put(obj);
            }

            // --- grouped ---
            if("arr".equals(jsonType) || "both".equals(jsonType)) {

                JSONArray arr = groups.get(category);

                if(arr == null) {
                    arr = new JSONArray();
                    groups.put(category, arr);
                }

                arr.put(obj);
            }


            ViewEntry tmp = nav.getNext(entry);
            entry.recycle();
            entry = tmp;
        }

        view.recycle();
        db.recycle();

        // --- Ergebnis ---
        if("arr".equals(jsonType)) {
            return groupsToJson(groups);
        }

        if("both".equals(jsonType)) {
            return "{\"obj\":" + flat.toString() + ",\"arr\":" + groupsToJson(groups) + "}";
        }

        return flat.toString();
    }

    private static String groupsToJson(Map<String, JSONArray> groups) {
        StringBuilder sb = new StringBuilder("{");
        for(Map.Entry<String, JSONArray> e : groups.entrySet()) {
            if(sb.length() > 1) {
                sb.append(',');
            }
            sb.append(JSONObject.quote(e.getKey())).append(':').append(e.getValue().toString());
        }
        return sb.append('}').toString();
    }




    // -------- VALUE CONVERSION --------
    private static Object convertValue(Object raw, String type)
    		throws NotesException {

    		    // Vor null- und MultiValue-Pruefung: auch eine leere oder
    		    // mehrwertige Spalte soll ein Array von Objekten ergeben.
    		    if("objlist".equals(type)) {
    		        return toObjList(raw);
    		    }

    		    if(raw == null) {
    		        return JSONObject.NULL;
    		    }

    		    // 👉 MultiValue (Domino)
    		    if(raw instanceof Vector) {
    		        JSONArray arr = new JSONArray();
    		        for(Object v : (Vector) raw) {
    		            arr.put(v != null ? v.toString() : JSONObject.NULL);
    		        }
    		        return arr;
    		    }

    		    switch(type) {

    		        case "jsonarray":
    		            try {
    		                return new JSONArray(raw.toString());
    		            } catch (Exception e) {
    		                return new JSONArray(); // fallback
    		            }

    		        case "jsonobject":
    		            try {
    		                return new JSONObject(raw.toString());
    		            } catch (Exception e) {
    		                return new JSONObject();
    		            }

    		        case "number":
    		            if(raw instanceof Number) {
    		                return ((Number) raw).doubleValue();
    		            }
    		            return Double.parseDouble(raw.toString());

    		        case "boolean":
    		            if(raw instanceof Number) {
    		                return ((Number) raw).intValue() == 1;
    		            }
    		            return Boolean.parseBoolean(raw.toString());

    		        case "date":
    		            if(raw instanceof DateTime) {
    		                return ((DateTime) raw).getGMTTime();
    		            }
    		            return raw.toString();

    		        case "unixdate":

    		        	if(raw instanceof DateTime) {
    		                return ((DateTime) raw).toJavaDate().getTime()/1000;
    		            } else if(raw instanceof String) {
    		            	try{
    		            		Instant Instant = java.time.Instant.parse((String)raw);
    		            		return Instant.getEpochSecond();
    		            	} catch(Exception e) {
    		            		SimpleDateFormat sdf = new SimpleDateFormat("dd.MM.yyyy HH:mm:ss");
    		            		java.util.Date date = null;
								try {
									date = sdf.parse((String) raw);
								} catch (ParseException e1) {
									// TODO Automatisch generierter Erfassungsblock
									e1.printStackTrace();
								}

    		            		return date.toInstant().getEpochSecond();
    		            	}
    		            }

    		            return raw.toString();

    		        case "string":
    		        default:
    		            return raw.toString();
    		    }
    		}

    /**
     * "titel~Intranet#link~www.intranet.de|titel~Suche#link~www.google.com"
     * -> [{"titel":"Intranet","link":"www.intranet.de"},{"titel":"Suche","link":"www.google.com"}]
     * "|" trennt die Objekte, "#" die Felder, "~" Name und Wert (nur am
     * ersten "~", der Wert darf also selbst "~" enthalten). Bei einer
     * mehrwertigen Spalte ist jeder Wert ein weiteres Objekt.
     */
    private static JSONArray toObjList(Object raw) {
        JSONArray list = new JSONArray();

        if(raw == null) {
            return list;
        }

        String text;
        if(raw instanceof Vector) {
            StringBuilder sb = new StringBuilder();
            for(Object v : (Vector) raw) {
                if(v != null) {
                    sb.append(v.toString()).append('|');
                }
            }
            text = sb.toString();
        } else {
            text = raw.toString();
        }

        for(String record : text.split("\\|")) {
            JSONObject obj = new JSONObject();

            for(String pair : record.split("#")) {
                int sep = pair.indexOf('~');
                if(sep > 0) {
                    obj.put(pair.substring(0, sep).trim(), pair.substring(sep + 1).trim());
                }
            }

            if(obj.length() > 0) {
                list.put(obj);
            }
        }

        return list;
    }

    private static double parseDouble(Object val) {
        try {
            return Double.parseDouble(val.toString());
        } catch(Exception e) {
            return 0;
        }
    }
}
