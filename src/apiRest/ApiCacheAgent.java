package apiRest;

import java.security.MessageDigest;
import java.text.ParseException;
import java.time.Instant;
import java.util.ArrayList;
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
 * Scheduled Agent: baut das JSON der in vwVariableAll definierten Endpoints
 * vor (getData() unten, gleiche Logik wie DataService.getData() unter
 * Code/Java) und legt es zusammen mit dem passenden ETag in einem
 * Cache-Dokument ab.
 *
 * Laeuft nur auf dem Server aus Profildokument "ApiCacheConfig", Feld
 * "AgentServer". Pro Zeitplan-Lauf werden zuerst die offenen
 * Request-Dokumente (Form "ApiCacheRequest", View vwApiCacheRequests)
 * nacheinander abgearbeitet. Kam seit "UpdateInterval" (Feld in
 * "ApiCacheConfig", Millisekunden, Standard 30 Minuten) kein Request,
 * werden alle Endpoints neu
 * gebaut, hoechstens in diesem Abstand. Die Zeitstempel dafuer
 * liegen im Profildokument "ApiCacheState".
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
    private static final String AGENT_CONFIG_PROFILE = "ApiCacheConfig";
    private static final String AGENT_STATE_PROFILE = "ApiCacheState";
    private static final String QUEUE_VIEW = "vwApiCacheRequests";
    private static final long DEFAULT_UPDATE_INTERVAL_MILLIS = 30L * 60L * 1000L;

    /**
     * Liest das Feld "UpdateInterval" (Millisekunden) aus dem Profildokument
     * "ApiCacheConfig" - als Zahl- oder Textfeld. Fehlt es oder ist es
     * ungueltig (nicht positiv), gilt DEFAULT_UPDATE_INTERVAL_MILLIS.
     */
    private long getUpdateIntervalMillis(Database db) {
        long millis = 0;

        try {
            Document profileDoc = db.getProfileDocument(AGENT_CONFIG_PROFILE, "");

            if(profileDoc != null) {
                millis = (long) profileDoc.getItemValueDouble("UpdateInterval");

                if(millis <= 0) {
                    String text = profileDoc.getItemValueString("UpdateInterval");
                    if(text != null && !text.trim().isEmpty()) {
                        millis = Long.parseLong(text.trim());
                    }
                }

                profileDoc.recycle();
            }
        } catch(Exception e) {
            millis = 0;
        }

        if(millis <= 0) {
            System.out.println("ApiCacheAgent: Kein gueltiges 'UpdateInterval' im Profildokument '"
                + AGENT_CONFIG_PROFILE + "', verwende " + DEFAULT_UPDATE_INTERVAL_MILLIS + " ms.");
            millis = DEFAULT_UPDATE_INTERVAL_MILLIS;
        }

        return millis;
    }

    /**
     * Liest das Feld "AgentServer" aus dem Profildokument "ApiCacheConfig".
     * Leerer Rueckgabewert = nicht konfiguriert (Agent laeuft dann nicht).
     */
    private String getConfiguredAgentServer(Database db) {
        try {
            Document profileDoc = db.getProfileDocument(AGENT_CONFIG_PROFILE, "");

            if(profileDoc == null) {
                return "";
            }

            String server = profileDoc.getItemValueString("AgentServer");
            profileDoc.recycle();

            return (server != null) ? server.trim() : "";

        } catch(Exception e) {
            return "";
        }
    }

    public void NotesMain() {

        try {
            Session session = getSession();
            AgentContext agentContext = session.getAgentContext();
            Database db = agentContext.getCurrentDatabase();

            // Nur auf dem im Profildokument konfigurierten Server arbeiten.
            // Fehlt die Konfiguration (Profildokument nicht vorhanden oder
            // Feld leer), wird gar nicht gelaufen.
            String configuredServer = getConfiguredAgentServer(db);

            if(configuredServer.isEmpty()) {
                System.out.println("ApiCacheAgent: Kein Server im Profildokument '" + AGENT_CONFIG_PROFILE
                    + "' (Feld 'AgentServer') konfiguriert oder Profildokument nicht gefunden - abgebrochen.");
                return;
            }

            String currentServer = db.getServer();

            if(!configuredServer.equalsIgnoreCase(currentServer)) {
                System.out.println("ApiCacheAgent: Konfigurierter Server '" + configuredServer
                    + "' stimmt nicht mit aktuellem Server '" + currentServer + "' ueberein - abgebrochen.");
                return;
            }

            // Gezielter Aufruf per agent.RunOnServer(configDoc.NoteID): die
            // NoteID kommt als ParameterDocID an, nicht ueber
            // getDocumentContext() (das liefert bei Run/RunOnServer immer
            // null).
            Agent currentAgent = agentContext.getCurrentAgent();
            String paramDocId = (currentAgent != null) ? currentAgent.getParameterDocID() : null;

            Document contextDoc = (paramDocId != null && !paramDocId.isEmpty())
                ? db.getDocumentByID(paramDocId)
                : null;

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

            // Zeitplan-Lauf: zuerst die Request-Queue abarbeiten. Nur wenn
            // seit "UpdateInterval" (Millisekunden) kein Request mehr kam, alle
            // Endpoints neu bauen - und auch dann hoechstens in diesem
            // Abstand.
            Document state = db.getProfileDocument(AGENT_STATE_PROFILE, "");
            long now = System.currentTimeMillis();

            if(processQueue(session, db)) {
                state.replaceItemValue("LastRequestMillis", Double.valueOf(now));
                state.save(true, false);
                state.recycle();
                return;
            }

            long interval = getUpdateIntervalMillis(db);
            long lastRequest = (long) state.getItemValueDouble("LastRequestMillis");
            long lastFullRun = (long) state.getItemValueDouble("LastFullRunMillis");

            if(now - lastRequest < interval || now - lastFullRun < interval) {
                state.recycle();
                return;
            }

            runFullScan(session, db);

            state.replaceItemValue("LastFullRunMillis", Double.valueOf(now));
            state.save(true, false);
            state.recycle();

        } catch (Exception e) {
            e.printStackTrace();
        }
    }

    /**
     * Arbeitet alle offenen Requests (View QUEUE_VIEW, aelteste zuerst)
     * nacheinander ab. Mehrere Requests fuer dasselbe Config-Dokument werden
     * nur einmal gebaut. Jeder Request wird danach auf "done" bzw. "error"
     * gesetzt und anonymisiert ($UpdatedBy entfernt) liegen gelassen.
     *
     * @return true, wenn mindestens ein Request verarbeitet wurde
     */
    private boolean processQueue(Session session, Database db) throws Exception {

        View queueView = db.getView(QUEUE_VIEW);

        if(queueView == null) {
            System.out.println("ApiCacheAgent: View '" + QUEUE_VIEW + "' nicht gefunden, Queue uebersprungen.");
            return false;
        }

        // Erst alle UNIDs einsammeln, dann verarbeiten: ein Request mit
        // geaendertem Status faellt aus der View, das wuerde eine laufende
        // Iteration ueber die View durcheinanderbringen.
        queueView.setAutoUpdate(false);
        List<String> requestUnids = new ArrayList<String>();

        Document request = queueView.getFirstDocument();
        while(request != null) {
            requestUnids.add(request.getUniversalID());
            Document next = queueView.getNextDocument(request);
            request.recycle();
            request = next;
        }
        queueView.recycle();

        if(requestUnids.isEmpty()) {
            return false;
        }

        // ConfigUNID -> Fehlertext ("" = erfolgreich gebaut)
        Map<String, String> results = new HashMap<String, String>();

        for(String requestUnid : requestUnids) {

            Document req = db.getDocumentByUNID(requestUnid);
            String configUnid = req.getItemValueString("ConfigUNID").trim();

            if(!results.containsKey(configUnid)) {
                results.put(configUnid, buildFromConfigUnid(session, db, configUnid));
            }

            String error = results.get(configUnid);

            req.replaceItemValue("Status", error.isEmpty() ? "done" : "error");
            if(!error.isEmpty()) {
                req.replaceItemValue("ErrorText", error);
            }
            req.replaceItemValue("ProcessedAt", session.createDateTime(new Date()));
            req.removeItem("$UpdatedBy");
            req.save(true, false);
            req.recycle();
        }

        System.out.println("ApiCacheAgent: " + requestUnids.size() + " Request(s) verarbeitet, "
            + results.size() + " Endpoint(s) gebaut.");

        return true;
    }

    /**
     * Baut den Endpoint des Config-Dokuments mit der gegebenen UNID.
     *
     * @return "" bei Erfolg, sonst eine Fehlerbeschreibung
     */
    private String buildFromConfigUnid(Session session, Database db, String configUnid) {
        try {
            if(configUnid.isEmpty()) {
                return "Request ohne ConfigUNID";
            }

            Document configDoc = db.getDocumentByUNID(configUnid);
            String endpoint = configDoc.getItemValueString("Titel").trim().toLowerCase();

            if(endpoint.isEmpty()) {
                configDoc.recycle();
                return "Config-Dokument " + configUnid + " ohne 'Titel'";
            }

            buildAndStore(session, db, endpoint, configDoc);
            return "";

        } catch(Exception e) {
            System.out.println("ApiCacheAgent: Fehler beim Bauen fuer ConfigUNID '" + configUnid + "': " + e);
            e.printStackTrace();
            return e.toString();
        }
    }

    /**
     * Baut alle Endpoints aus CONFIG_VIEW neu.
     */
    private void runFullScan(Session session, Database db) throws Exception {

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
                        // Dokument direkt vom Entry holen statt ueber einen
                        // zweiten db.getView(CONFIG_VIEW)-Handle: Domino gibt
                        // bei gleichem Viewnamen oft denselben gecachten
                        // Handle zurueck, ein Recycle davon wuerde die View
                        // invalidieren, ueber die hier noch iteriert wird.
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

        System.out.println("ApiCacheAgent: Kompletter Lauf abgeschlossen.");
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
