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
     * Fingerabdruck des Ergebnisses fuer den ETag: durchlaeuft dieselbe Seite
     * (start/limit) wie getData(), liest je Eintrag aber nur getLastModified()
     * statt die Felder zu konvertieren und JSON aufzubauen. Erkennt damit auch
     * replizierte Aenderungen (anders als Database.getLastModified()) und ist
     * trotz zweitem View-Durchlauf deutlich guenstiger als der volle JSON-Aufbau.
     */
    public static String getCacheKey(
        Session session,
        Map<String, Object> config,
        Map<String, String> params
    ) throws Exception {

        Database db = session.getCurrentDatabase();

        String viewName = (String) config.get("view");

        View view = db.getView(viewName);
        view.setAutoUpdate(false);

        int limit = getLimit(config, params);
        int start = parseInt(params.get("start"), 0);

        ViewNavigator nav = view.createViewNav();
        ViewEntry entry = nav.getNth(start + 1);

        long latest = 0;
        int count = 0;

        while(entry != null && count < limit) {

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

        return viewName + "|" + start + "|" + limit + "|" + count + "|" + latest;
    }

    public static Object getData(
        Session session,
        Map<String, Object> config,
        Map<String, String> params
    ) throws Exception {

        Database db = session.getCurrentDatabase();

        String viewName = (String) config.get("view");
        String jsonType = (String) config.get("art");

        if(jsonType == null) jsonType = "obj";

        View view = db.getView(viewName);
        view.setAutoUpdate(false);

        int limit = getLimit(config, params);
        int start = parseInt(params.get("start"), 0);

        ViewNavigator nav = view.createViewNav();
        ViewEntry entry = nav.getNth(start + 1);

        JSONArray flat = new JSONArray();
        Map<String, JSONArray> groups = new LinkedHashMap<>();

        @SuppressWarnings("unchecked")
        List<Map<String, Object>> fields =
            (List<Map<String, Object>>) config.get("fields");

        int categoryCol = config.containsKey("titlecol")
                ? ((Number) config.get("titlecol")).intValue()
                : -1;

        int count = 0;

        while(entry != null && count < limit) {

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

            count++;
        }

        view.recycle();
        db.recycle();

        // --- Ergebnis ---
        if("obj".equals(jsonType)) {
            return flat;
        }

        if("arr".equals(jsonType)) {
            JSONObject grouped = new JSONObject();
            for(Map.Entry<String, JSONArray> e : groups.entrySet()) {
                grouped.put(e.getKey(), e.getValue());
            }
            return grouped;
        }

        if("both".equals(jsonType)) {
            JSONObject result = new JSONObject();

            JSONObject grouped = new JSONObject();
            for(Map.Entry<String, JSONArray> e : groups.entrySet()) {
                grouped.put(e.getKey(), e.getValue());
            }

            result.put("obj", flat);
            result.put("arr", grouped);

            return result;
        }

        return flat;
    }




    // -------- VALUE CONVERSION --------
    private static Object convertValue(Object raw, String type)
    		throws NotesException {

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

    // -------- LIMIT HANDLING --------
    private static int getLimit(Map<String, Object> config, Map<String, String> params) {

        int def = 5000;
        int max = 20000;

        if(config.containsKey("limit")) {
            Map limitCfg = (Map) config.get("limit");
            def = ((Number) limitCfg.get("default")).intValue();
            max = ((Number) limitCfg.get("max")).intValue();
        }

        int req = parseInt(params.get("limit"), def);
        return Math.min(req, max);
    }

    private static int parseInt(String val, int def) {
        try {
            return val != null ? Integer.parseInt(val) : def;
        } catch(Exception e) {
            return def;
        }
    }

    private static double parseDouble(Object val) {
        try {
            return Double.parseDouble(val.toString());
        } catch(Exception e) {
            return 0;
        }
    }
}
