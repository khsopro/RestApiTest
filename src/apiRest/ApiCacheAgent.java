package apiRest;

import java.io.File;
import java.io.FileOutputStream;
import java.security.MessageDigest;
import java.text.ParseException;
import java.time.Instant;
import java.util.Date;
import java.util.HashMap;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.Vector;

import lotus.domino.*;

import com.ibm.icu.text.SimpleDateFormat;
import com.ibm.commons.util.io.json.*;

import org.json.JSONArray;
import org.json.JSONObject;

/**
 * Scheduled Agent: baut fuer jeden in vwVariableAll definierten Endpoint das
 * JSON einmalig vor (getData() unten, gleiche Logik wie DataService.getData()
 * unter Code/Java) und legt es zusammen mit dem passenden ETag in einem
 * Cache-Dokument ab. ApiServiceBean bleibt davon unberuehrt und bedient
 * weiterhin live aus der View, falls (noch) kein Cache-Dokument existiert.
 *
 * Java-Agents in dieser Domino-Umgebung akzeptieren nur genau eine
 * kompilierte Klasse - weder eine zweite Top-Level-Klasse in derselben Datei
 * noch eine statische verschachtelte Klasse funktionierten (erstere gab zur
 * Laufzeit "NotesContext not initialized for the thread", letztere bereits
 * einen Kompilierfehler). Deshalb ist die Bau-Logik hier direkt als private
 * Methoden auf ApiCacheAgent dupliziert statt die geteilte DataService
 * (Code/Java, von ApiServiceBean genutzt) zu referenzieren. Aendert sich die
 * Feldkonvertierung/JSON-Struktur, muss das an beiden Stellen gepflegt
 * werden.
 */
public class ApiCacheAgent extends AgentBase {

    private static final String CACHE_FORM = "ApiCache";
    private static final String CONFIG_VIEW = "vwVariableAll";
    private static final String DEFAULT_CACHE_VIEW = "vwApiCache";
    private static final String KEEPWEB_SUBDIR = "apicache";

    public void NotesMain() {

        try {
            Session session = getSession();
            AgentContext agentContext = session.getAgentContext();
            Database db = agentContext.getCurrentDatabase();

            // Gezielter Aufruf, z.B. aus einem Publish-Button per
            // agent.RunOnServer(configDoc.NoteID): nur dieses eine
            // Config-Dokument neu bauen statt der kompletten View.
            Document contextDoc = agentContext.getDocumentContext();

            if(contextDoc != null) {
                String endpoint = contextDoc.getItemValueString("Titel").trim().toLowerCase();

                if(!endpoint.isEmpty()) {
                    try {
                        buildAndStore(session, db, endpoint, contextDoc);
                    } catch(Exception e) {
                        System.out.println("ApiCacheAgent: Fehler bei gezieltem Aufruf fuer Endpoint '" + endpoint + "': " + e);
                        e.printStackTrace();
                    }
                } else {
                    System.out.println("ApiCacheAgent: Gezielter Aufruf ohne 'Titel' im uebergebenen Dokument, uebersprungen.");
                    contextDoc.recycle();
                }

                return;
            }

            // Normaler Voll-Durchlauf, z.B. per Zeitplan-Trigger.
            View configView = db.getView(CONFIG_VIEW);
            configView.setAutoUpdate(false);

            ViewNavigator nav = configView.createViewNav();
            ViewEntry entry = nav.getFirst();

            while(entry != null) {

                if(!entry.isCategory()) {

                    Vector values = entry.getColumnValues();
                    String endpoint = (values.size() > 0 && values.get(0) != null)
                        ? values.get(0).toString().trim().toLowerCase()
                        : null;

                    if(endpoint != null && !endpoint.isEmpty()) {
                        try {
                            // Dokument direkt vom Entry holen statt ueber
                            // einen zweiten db.getView(CONFIG_VIEW)-Handle:
                            // Domino gibt bei gleichem Viewnamen oft denselben
                            // gecachten Handle zurueck - ein Recycle davon in
                            // buildAndStore() hat hier die View invalidiert,
                            // aus der nav/entry noch iteriert wurden
                            // ("Entry removed or recycled").
                            Document configDoc = entry.getDocument();
                            buildAndStore(session, db, endpoint, configDoc);
                        } catch(Exception e) {
                            System.out.println("ApiCacheAgent: Fehler bei Endpoint '" + endpoint + "': " + e);
                            e.printStackTrace();
                        }
                    }
                }

                ViewEntry tmp = nav.getNext(entry);
                entry.recycle();
                entry = tmp;
            }

            nav.recycle();
            configView.recycle();

        } catch (Exception e) {
            e.printStackTrace();
        }
    }

    private void buildAndStore(Session session, Database db, String endpoint, Document configDoc) throws Exception {

        if(configDoc == null) {
            return;
        }

        String configJson = configDoc.getItemValueString("ConfigJson");

        @SuppressWarnings("unchecked")
        Map<String, Object> config;

        try {
            config = (Map<String, Object>) JsonParser.fromJson(
                JsonJavaFactory.instanceEx, configJson
            );
        } catch(Exception e) {
            System.out.println("ApiCacheAgent: ConfigJson fuer Endpoint '" + endpoint
                + "' (Dokument " + configDoc.getUniversalID() + ") ist kein gueltiges JSON. "
                + "Laenge=" + (configJson != null ? configJson.length() : -1)
                + " Inhalt='" + configJson + "'");
            configDoc.recycle();
            throw e;
        }

        configDoc.recycle();

        Object data = getData(session, config, new HashMap<String, String>());
        String json = data.toString();
        String etag = hashETag(json);

        Document cacheDoc = findCacheDocument(db, endpoint, config);

        if(cacheDoc == null) {
            cacheDoc = db.createDocument();
            cacheDoc.replaceItemValue("Form", CACHE_FORM);
            cacheDoc.replaceItemValue("Endpoint", endpoint);
        }

        writeJson(session, cacheDoc, json);

        cacheDoc.replaceItemValue("ETag", etag);
        cacheDoc.replaceItemValue("LastBuilt", session.createDateTime(new Date()));
        cacheDoc.save(true, false);
        cacheDoc.recycle();

        // Zusaetzlich, best effort: statische Kopie nach keepweb.d, damit die
        // HCL Domino REST API (Keep) sie direkt unter /keepweb/ ausliefern
        // kann, ganz ohne XPages/ApiServiceBean. Schlaegt das fehl, bricht
        // es den Agent-Lauf fuer diesen Endpoint nicht ab - das Notes-
        // Cache-Dokument oben ist bereits erfolgreich geschrieben.
        writeKeepwebFile(session, endpoint, json);
    }

    /**
     * Schreibt das JSON zusaetzlich als statische Datei nach
     * <DominoDatenverzeichnis>/keepweb.d/apicache/<endpoint>.json, damit es
     * ueber die HCL Domino REST API (Keep) direkt unter /keepweb/ erreichbar
     * ist. Rein additiv und best effort: Fehler (z.B. Verzeichnis nicht
     * beschreibbar) werden nur geloggt, nicht weitergeworfen.
     */
    private void writeKeepwebFile(Session session, String endpoint, String json) {
        try {
            String dataDir = session.getEnvironmentString("Directory", true);

            if(dataDir == null || dataDir.trim().isEmpty()) {
                System.out.println("ApiCacheAgent: Domino-Datenverzeichnis nicht ermittelbar, keepweb.d-Export fuer '"
                    + endpoint + "' uebersprungen.");
                return;
            }

            String safeEndpoint = endpoint.replaceAll("[^a-z0-9_-]", "");

            if(safeEndpoint.isEmpty()) {
                return;
            }

            File dir = new File(dataDir, "keepweb.d" + File.separator + KEEPWEB_SUBDIR);

            if(!dir.exists() && !dir.mkdirs()) {
                System.out.println("ApiCacheAgent: Konnte Verzeichnis '" + dir.getAbsolutePath() + "' nicht anlegen.");
                return;
            }

            File target = new File(dir, safeEndpoint + ".json");

            FileOutputStream out = new FileOutputStream(target);
            try {
                out.write(json.getBytes("UTF-8"));
            } finally {
                out.close();
            }

        } catch(Exception e) {
            System.out.println("ApiCacheAgent: keepweb.d-Export fuer Endpoint '" + endpoint + "' fehlgeschlagen: " + e);
            e.printStackTrace();
        }
    }

    /**
     * Sucht ein vorhandenes Cache-Dokument fuer den Endpoint ueber dieselbe,
     * im Config-JSON konfigurierbare View wie ApiServiceBean.getCacheDocument()
     * (Schluessel "cacheView", Fallback DEFAULT_CACHE_VIEW) - schneller,
     * indexierter Lookup statt datenbankweitem Formel-Scan, und konsistent
     * mit dem XPages-Lesepfad.
     */
    private Document findCacheDocument(Database db, String endpoint, Map<String, Object> config) throws Exception {

        String cacheViewName = (String) config.get("cacheView");
        if(cacheViewName == null || cacheViewName.trim().isEmpty()) {
            cacheViewName = DEFAULT_CACHE_VIEW;
        }

        View cacheView = db.getView(cacheViewName);

        if(cacheView == null) {
            return null;
        }

        cacheView.setAutoUpdate(false);
        Document doc = cacheView.getDocumentByKey(endpoint, true);
        cacheView.recycle();

        return doc;
    }

    /**
     * JSON als MIME-Entity statt klassisches Text-Item, um das ~64KB-Limit
     * klassischer Notes-Textfelder zu umgehen (bei limit bis 20000 kann das
     * JSON deutlich groesser werden).
     */
    private void writeJson(Session session, Document doc, String json) throws Exception {

        Item existing = doc.getFirstItem("Json");
        if(existing != null) {
            existing.remove();
        }

        Stream stream = session.createStream();
        stream.writeText(json);

        MIMEEntity body = doc.createMIMEEntity("Json");
        body.setContentFromText(stream, "application/json; charset=UTF-8", MIMEEntity.ENC_NONE);

        stream.close();
    }

    private String hashETag(String content) {
        try {
            MessageDigest md = MessageDigest.getInstance("MD5");
            byte[] hash = md.digest(content.getBytes("UTF-8"));

            StringBuilder hex = new StringBuilder(hash.length * 2 + 2);
            for(byte b : hash) {
                hex.append(String.format("%02x", b));
            }

            return "\"" + hex.toString() + "\"";

        } catch (Exception e) {
            return null;
        }
    }

    // -------- DATENAUFBAU (Kopie der Logik aus DataService, siehe Klassenkommentar) --------

    private Object getData(
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

            String category = "default";
            if(categoryCol >= 0 && categoryCol < values.size() && values.get(categoryCol) != null) {
                category = values.get(categoryCol).toString();
            }

            JSONObject obj = new JSONObject();

            for(Map<String, Object> f : fields) {

                int col = ((Number) f.get("column")).intValue();
                String json = (String) f.get("json");
                String type = (String) f.get("type");

                Object raw = (col < values.size()) ? values.get(col) : null;

                obj.put(json, convertValue(raw, type));
            }

            if("obj".equals(jsonType) || "both".equals(jsonType)) {
                flat.put(obj);
            }

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

    private Object convertValue(Object raw, String type)
            throws NotesException {

        if(raw == null) {
            return JSONObject.NULL;
        }

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
                    return new JSONArray();
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
                    return ((DateTime) raw).toJavaDate().getTime() / 1000;
                } else if(raw instanceof String) {
                    try {
                        Instant instant = java.time.Instant.parse((String) raw);
                        return instant.getEpochSecond();
                    } catch(Exception e) {
                        SimpleDateFormat sdf = new SimpleDateFormat("dd.MM.yyyy HH:mm:ss");
                        java.util.Date date = null;
                        try {
                            date = sdf.parse((String) raw);
                        } catch (ParseException e1) {
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

    private int getLimit(Map<String, Object> config, Map<String, String> params) {

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

    private int parseInt(String val, int def) {
        try {
            return val != null ? Integer.parseInt(val) : def;
        } catch(Exception e) {
            return def;
        }
    }
}
