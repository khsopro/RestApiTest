package apiRest;

import java.io.PrintWriter;
import java.io.StringWriter;
import java.security.MessageDigest;
import java.text.Collator;
import java.util.ArrayList;
import java.util.Collections;
import java.util.Comparator;
import java.util.HashSet;
import java.util.Iterator;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Locale;
import java.util.Map;
import java.util.Set;

import javax.faces.context.FacesContext;
import javax.servlet.http.*;

import lotus.domino.*;

import org.json.JSONArray;
import org.json.JSONObject;
import org.json.JSONTokener;

import com.ibm.commons.util.io.json.*;
import com.ibm.xsp.extlib.util.ExtLibUtil;

public class ApiServiceBean {

    public void handleRequest() {

        FacesContext fc = FacesContext.getCurrentInstance();
        PrintWriter writer = null;
        HttpServletResponse response = null;

        try {
        	response = (HttpServletResponse) fc.getExternalContext().getResponse();
        	response.setContentType("application/json; charset=UTF-8");

        	// Erzwingt Revalidierung bei jedem Aufruf, statt dass der Browser
        	// die Antwort ungeprueft aus dem Cache liefert oder sie mangels
        	// Cache-Control gar nicht erst speichert (sonst kommt nie ein
        	// If-None-Match zurueck).
        	response.setHeader("Cache-Control", "no-cache, private");

        	response.setStatus(HttpServletResponse.SC_OK);
        	writer = response.getWriter();

        	HttpServletRequest req = (HttpServletRequest) fc.getExternalContext().getRequest();
        	String uri = req.getRequestURI();
        	String endpoint = extractEndpoint(uri);

            Map<String, String> params = fc.getExternalContext().getRequestParameterMap();

            Session session = (Session) ExtLibUtil.resolveVariable("session");

            Map<String, Object> config = getConfig(session, endpoint);

            if(config == null) {
                writeError(writer, "Unknown endpoint");
                return;
            }

            // Paging/Sortierung/Filter nur, wenn einer der Parameter gesetzt
            // ist. Ohne Parameter wird das JSON unveraendert wie bisher
            // geliefert. Mit Parametern: bei "arr" ohne "bereich" weiter als
            // Objekt der Bereiche, gefiltert, sortiert und geblaettert wird
            // je Bereich; mit "bereich" und bei "obj" als Array.
            // "auswahl=feld" liefert stattdessen die vorkommenden Werte des
            // Feldes, "feld=x&wert=y" nur die Zeilen mit diesem Wert.
            String bereich = trimToNull(params.get("bereich"));
            String sort = trimToNull(params.get("sort"));
            String auswahl = trimToNull(params.get("auswahl"));
            String feld = trimToNull(params.get("feld"));
            String wert = trimToNull(params.get("wert"));

            // Filter nur mit bekanntem Feld und Wert, sonst ignorieren (wie
            // unbekannte Sortierfelder)
            if(feld == null || wert == null || !getFieldNames(config).contains(feld)) {
                feld = null;
                wert = null;
            }

            boolean paged = bereich != null || sort != null || auswahl != null || feld != null
                || params.containsKey("start") || params.containsKey("limit");

            int start = Math.max(0, parseInt(params.get("start"), 0));
            int limit = Math.max(0, getLimit(config, params));

            // MIME-Items roh lesen: mit ConvertMime=true (Standard) wandelt
            // Domino das Json-Item beim Oeffnen des Dokuments in Rich Text
            // und beim Lesen wieder zurueck - dabei entstehen Zeilenumbrueche
            // und verlorene Zeichen im JSON.
            boolean convertMime = session.isConvertMime();
            session.setConvertMime(false);

            Document cacheDoc = getCacheDocument(session, endpoint, config);

            try {
                // Bevorzugt: ETag kommt direkt aus dem vom ApiCacheAgent
                // vorgebauten Cache-Dokument, kein View-Zugriff noetig.
                // Fallback (kein Cache-Dokument vorhanden, z.B. neuer
                // Endpoint oder Agent noch nicht gelaufen): wie bisher live
                // per Zweitdurchlauf ermitteln.
                String baseETag = (cacheDoc != null)
                    ? cacheDoc.getItemValueString("ETag")
                    : null;

                if(baseETag == null || baseETag.isEmpty()) {
                    baseETag = generateETag(DataService.getCacheKey(session, config));
                }

                // Jede Seite/Sortierung bekommt einen eigenen ETag, abgeleitet
                // vom ETag des Gesamt-JSON - ohne die Daten anzufassen, damit
                // der 304 weiterhin vor jedem Parsen greift.
                String etag = (paged && baseETag != null)
                    ? generateETag(baseETag + "|" + bereich + "|" + start + "|" + limit + "|" + sort
                        + "|" + auswahl + "|" + feld + "|" + wert)
                    : baseETag;

                if(etag != null) {
                    response.setHeader("ETag", etag);
                }

                String ifNoneMatch = req.getHeader("If-None-Match");

                if(etag != null && matchesETag(ifNoneMatch, etag)) {
                    response.setStatus(HttpServletResponse.SC_NOT_MODIFIED);
                    return;
                }

                if(!paged) {
                    writer.write(loadJson(session, config, cacheDoc));
                    return;
                }

                ParsedData data = getParsedData(session, endpoint, baseETag, config, cacheDoc);

                if(auswahl != null) {
                    // Werte fuer eine Auswahlliste: ueber alle Zeilen bzw.
                    // nur den angegebenen Bereich, ein gesetzter Filter wirkt
                    // mit (fuer abhaengige Auswahllisten)
                    writer.write(distinctValues(filterRows(data.select(bereich), feld, wert), auswahl, config).toString());
                    return;
                }

                if(bereich == null && "arr".equals(config.get("art"))) {
                    // X-Total-Count = alle (gefilterten) Zeilen ueber alle Bereiche
                    response.setHeader("X-Total-Count", String.valueOf(filterRows(data.all, feld, wert).length()));
                    writer.write(pageGroups(data.groups, feld, wert, sort, config, start, limit));
                    return;
                }

                List<Object> sorted = sortRows(filterRows(data.select(bereich), feld, wert), sort, config);

                response.setHeader("X-Total-Count", String.valueOf(sorted.size()));
                writer.write(pageOf(sorted, start, limit).toString());

            } finally {
                if(cacheDoc != null) {
                    cacheDoc.recycle();
                }
                session.setConvertMime(convertMime);
            }

        } catch (Exception e) {
           StringWriter sw = new StringWriter();
           e.printStackTrace(new PrintWriter(sw));
           writeError(response, HttpServletResponse.SC_INTERNAL_SERVER_ERROR, sw.toString());
        } finally {
            // Auf jedem Weg, auch nach den return-Anweisungen oben (304,
            // unbekannter Endpoint, Ausgabe ohne Parameter): sonst rendert
            // JSF danach die XPage selbst und haengt ihr HTML an das JSON an.
            fc.responseComplete();
        }
    }

    private String extractEndpoint(String uri) {

        int idx = uri.indexOf(".xsp/");
        if(idx == -1) {
            return "default";
        }

        String part = uri.substring(idx + 5);

        if(part.contains("/")) {
            part = part.substring(0, part.indexOf("/"));
        }

        return part.toLowerCase();
    }

    // -------- CACHE DOCUMENT (ApiCacheAgent) --------

    private static final String DEFAULT_CACHE_VIEW = "vwApiCache";

    /**
     * Sucht das vom ApiCacheAgent vorgebaute Cache-Dokument fuer den
     * Endpoint ueber die im Config-JSON hinterlegte View (Schluessel
     * "cacheView", faellt auf DEFAULT_CACHE_VIEW zurueck, falls der
     * Schluessel in einer Config fehlt). Selektion dort Form="ApiCache",
     * sortiert nach Endpoint. Existiert die View oder das Dokument (noch)
     * nicht, wird null zurueckgegeben und handleRequest() faellt auf die
     * alte Live-Berechnung zurueck - kein Fehler.
     */
    private Document getCacheDocument(Session session, String endpoint, Map<String, Object> config) {
        try {
            Database db = session.getCurrentDatabase();

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

        } catch(Exception e) {
            return null;
        }
    }

    private String readCachedJson(Document cacheDoc) {
        try {
            MIMEEntity body = cacheDoc.getMIMEEntity("Json");

            if(body == null) {
                return null;
            }

            // Der Agent legt das JSON Base64-kodiert ab (siehe
            // ApiCacheAgent.writeJson). decodeContent() aendert nur die Kopie
            // im Speicher, das Dokument wird hier nicht gespeichert. Aeltere,
            // unkodierte Cache-Dokumente werden unveraendert gelesen.
            if(body.getEncoding() != MIMEEntity.ENC_NONE) {
                body.decodeContent();
            }

            return body.getContentAsText();

        } catch(Exception e) {
            e.printStackTrace();
            return null;
        }
    }

    /**
     * Gesamt-JSON des Endpoints: aus dem Cache-Dokument, sonst live gebaut.
     */
    private String loadJson(Session session, Map<String, Object> config, Document cacheDoc) throws Exception {
        String json = (cacheDoc != null) ? readCachedJson(cacheDoc) : null;

        if(json == null) {
            // Kein (nutzbares) Cache-Dokument -> wie bisher live aufbauen
            json = DataService.getData(session, config);
        }

        return json;
    }

    // -------- PAGING / SORTIERUNG --------

    private static final String PARSED_SCOPE_PREFIX = "apiRest.parsed.";

    /**
     * Geparstes Gesamt-JSON eines Endpoints. Wird nach dem Aufbau nicht mehr
     * veraendert und kann deshalb von parallelen Requests gelesen werden.
     */
    private static final class ParsedData {
        final String etag;
        final JSONArray all;
        final Map<String, JSONArray> groups;

        ParsedData(String etag, JSONArray all, Map<String, JSONArray> groups) {
            this.etag = etag;
            this.all = all;
            this.groups = groups;
        }

        /**
         * Ohne Bereich alle Zeilen (bei "both" die "obj"-Liste), mit Bereich
         * nur dessen Zeilen. Bei "obj" gibt es keine Bereiche, der Parameter
         * wird dort ignoriert.
         */
        JSONArray select(String bereich) {
            if(bereich == null || groups == null) {
                return all;
            }
            JSONArray rows = groups.get(bereich);
            return (rows != null) ? rows : new JSONArray();
        }
    }

    /**
     * Haelt das geparste JSON pro Endpoint im applicationScope, damit nicht
     * jeder Seitenaufruf die komplette Datenmenge neu parst. Aendert sich der
     * ETag (Agent hat neu gebaut), wird der alte Eintrag ersetzt.
     */
    private ParsedData getParsedData(Session session, String endpoint, String etag,
            Map<String, Object> config, Document cacheDoc) throws Exception {

        Map<String, Object> appScope = ExtLibUtil.getApplicationScope();
        String key = PARSED_SCOPE_PREFIX + endpoint;

        Object memo = appScope.get(key);
        if(etag != null && memo instanceof ParsedData && etag.equals(((ParsedData) memo).etag)) {
            return (ParsedData) memo;
        }

        String json = loadJson(session, config, cacheDoc);
        String jsonType = (String) config.get("art");

        ParsedData data;

        if("arr".equals(jsonType)) {
            Map<String, JSONArray> groups = parseGroups(json);
            JSONArray all = new JSONArray();
            for(JSONArray rows : groups.values()) {
                for(int i = 0; i < rows.length(); i++) {
                    all.put(rows.get(i));
                }
            }
            data = new ParsedData(etag, all, groups);

        } else if("both".equals(jsonType)) {
            JSONObject both = new JSONObject(json);
            JSONObject grouped = both.getJSONObject("arr");
            Map<String, JSONArray> groups = new LinkedHashMap<String, JSONArray>();
            Iterator<?> keys = grouped.keys();
            while(keys.hasNext()) {
                String k = (String) keys.next();
                groups.put(k, grouped.getJSONArray(k));
            }
            data = new ParsedData(etag, both.getJSONArray("obj"), groups);

        } else {
            data = new ParsedData(etag, new JSONArray(json), null);
        }

        if(etag != null) {
            appScope.put(key, data);
        }

        return data;
    }

    /**
     * Liest das gruppierte "arr"-Objekt so ein, dass die Reihenfolge der
     * Bereiche erhalten bleibt (new JSONObject(json) wuerde sie verlieren).
     */
    private Map<String, JSONArray> parseGroups(String json) throws Exception {
        JSONTokener x = new JSONTokener(json);
        Map<String, JSONArray> groups = new LinkedHashMap<String, JSONArray>();

        if(x.nextClean() != '{') {
            throw x.syntaxError("A JSONObject text must begin with '{'");
        }

        while(true) {
            char c = x.nextClean();
            if(c == '}') {
                return groups;
            }
            if(c == 0) {
                throw x.syntaxError("A JSONObject text must end with '}'");
            }
            x.back();

            String key = x.nextValue().toString();

            if(x.nextClean() != ':') {
                throw x.syntaxError("Expected a ':' after a key");
            }

            Object value = x.nextValue();
            groups.put(key, (value instanceof JSONArray) ? (JSONArray) value : new JSONArray());

            c = x.nextClean();
            if(c == '}') {
                return groups;
            }
            if(c != ',') {
                throw x.syntaxError("Expected a ',' or '}'");
            }
        }
    }

    /**
     * Filtert, sortiert und blaettert jeden Bereich fuer sich und schreibt sie als
     * Objekt {"Kap1":[...],"Kap2":[...]} in View-Reihenfolge (ein
     * JSONObject wuerde die Reihenfolge verlieren). Bereiche, deren Seite
     * leer ist, bleiben mit [] enthalten.
     */
    private String pageGroups(Map<String, JSONArray> groups, String feld, String wert, String sort,
            Map<String, Object> config, int start, int limit) throws Exception {

        StringBuilder out = new StringBuilder("{");

        for(Map.Entry<String, JSONArray> e : groups.entrySet()) {
            if(out.length() > 1) {
                out.append(',');
            }
            List<Object> sorted = sortRows(filterRows(e.getValue(), feld, wert), sort, config);
            out.append(JSONObject.quote(e.getKey())).append(':')
               .append(pageOf(sorted, start, limit).toString());
        }

        return out.append('}').toString();
    }

    private JSONArray pageOf(List<Object> rows, int start, int limit) {
        int total = rows.size();
        int from = Math.min(start, total);
        int to = (int) Math.min((long) from + limit, total);

        JSONArray page = new JSONArray();
        for(int i = from; i < to; i++) {
            page.put(rows.get(i));
        }
        return page;
    }

    /**
     * Sortiert nach "sort=feld,-feld2" (Minus = absteigend). Erlaubt sind die
     * json-Namen aus "fields", eingeschraenkt durch die optionale Liste
     * "sortable" in der Config; unbekannte Felder werden ignoriert. Zahlen
     * und Booleans werden als solche verglichen, alles andere als Text
     * (deutsch, ohne Gross-/Kleinschreibung). Leere Werte stehen immer am
     * Ende, bei Gleichstand bleibt die View-Reihenfolge.
     */
    private List<Object> sortRows(JSONArray rows, String sort, Map<String, Object> config) throws Exception {

        List<Object> list = new ArrayList<Object>(rows.length());
        for(int i = 0; i < rows.length(); i++) {
            list.add(rows.get(i));
        }

        if(sort == null) {
            return list;
        }

        Set<String> allowed = getSortableFields(config);
        final List<String> keys = new ArrayList<String>();
        final List<Boolean> descending = new ArrayList<Boolean>();

        for(String part : sort.split(",")) {
            String p = part.trim();
            boolean desc = p.startsWith("-");
            if(desc || p.startsWith("+")) {
                p = p.substring(1).trim();
            }
            if(allowed.contains(p)) {
                keys.add(p);
                descending.add(desc);
            }
        }

        if(keys.isEmpty()) {
            return list;
        }

        final Collator collator = Collator.getInstance(Locale.GERMAN);
        collator.setStrength(Collator.SECONDARY);

        Collections.sort(list, new Comparator<Object>() {
            public int compare(Object a, Object b) {
                for(int i = 0; i < keys.size(); i++) {
                    Object va = (a instanceof JSONObject) ? ((JSONObject) a).opt(keys.get(i)) : null;
                    Object vb = (b instanceof JSONObject) ? ((JSONObject) b).opt(keys.get(i)) : null;

                    boolean na = isEmpty(va);
                    boolean nb = isEmpty(vb);
                    if(na || nb) {
                        if(na && nb) {
                            continue;
                        }
                        return na ? 1 : -1;
                    }

                    int r = compareValues(va, vb, collator);
                    if(r != 0) {
                        return descending.get(i) ? -r : r;
                    }
                }
                return 0;
            }
        });

        return list;
    }

    /**
     * Nur Zeilen, deren Feld "feld" den Wert "wert" hat (ohne Gross-/
     * Kleinschreibung). Bei mehrwertigen Feldern reicht ein passender Wert.
     * Ohne Filter wird rows unveraendert zurueckgegeben.
     */
    private JSONArray filterRows(JSONArray rows, String feld, String wert) {
        if(feld == null) {
            return rows;
        }

        JSONArray out = new JSONArray();
        for(int i = 0; i < rows.length(); i++) {
            Object row = rows.opt(i);
            if(row instanceof JSONObject && matches(((JSONObject) row).opt(feld), wert)) {
                out.put(row);
            }
        }
        return out;
    }

    private static boolean matches(Object value, String wert) {
        if(value instanceof JSONArray) {
            JSONArray values = (JSONArray) value;
            for(int i = 0; i < values.length(); i++) {
                if(matches(values.opt(i), wert)) {
                    return true;
                }
            }
            return false;
        }

        String text = valueText(value);
        return text != null && text.equalsIgnoreCase(wert);
    }

    /**
     * Alle vorkommenden Werte des Feldes fuer eine Auswahlliste: ohne leere
     * Werte, ohne Doppelte (ohne Gross-/Kleinschreibung), sortiert wie bei
     * "sort". Mehrwertige Felder liefern jeden Einzelwert. Unbekanntes Feld
     * ergibt [].
     */
    private JSONArray distinctValues(JSONArray rows, String feld, Map<String, Object> config) {
        JSONArray out = new JSONArray();

        if(!getFieldNames(config).contains(feld)) {
            return out;
        }

        Map<String, Object> seen = new LinkedHashMap<String, Object>();
        for(int i = 0; i < rows.length(); i++) {
            Object row = rows.opt(i);
            if(row instanceof JSONObject) {
                collectValues(((JSONObject) row).opt(feld), seen);
            }
        }

        List<Object> values = new ArrayList<Object>(seen.values());

        final Collator collator = Collator.getInstance(Locale.GERMAN);
        collator.setStrength(Collator.SECONDARY);

        Collections.sort(values, new Comparator<Object>() {
            public int compare(Object a, Object b) {
                return compareValues(a, b, collator);
            }
        });

        for(Object v : values) {
            out.put(v);
        }
        return out;
    }

    private static void collectValues(Object value, Map<String, Object> seen) {
        if(value instanceof JSONArray) {
            JSONArray values = (JSONArray) value;
            for(int i = 0; i < values.length(); i++) {
                collectValues(values.opt(i), seen);
            }
            return;
        }

        String text = valueText(value);
        if(text != null && !seen.containsKey(text.toLowerCase())) {
            seen.put(text.toLowerCase(), value);
        }
    }

    /**
     * Vergleichbarer Text eines einfachen Wertes, null fuer leere Werte und
     * Objekte/Arrays. Zahlen wie im JSON (2.0 -> "2"), damit ein Wert aus
     * der Auswahlliste unveraendert als "wert" zurueckgegeben werden kann.
     */
    private static String valueText(Object value) {
        if(isEmpty(value) || value instanceof JSONObject || value instanceof JSONArray) {
            return null;
        }
        if(value instanceof Number) {
            try {
                return JSONObject.numberToString((Number) value);
            } catch(Exception e) {
                return value.toString();
            }
        }
        String text = value.toString().trim();
        return text.isEmpty() ? null : text;
    }

    /**
     * Die json-Namen aus "fields" der Config.
     */
    private Set<String> getFieldNames(Map<String, Object> config) {
        Set<String> fieldNames = new HashSet<String>();

        @SuppressWarnings("unchecked")
        List<Map<String, Object>> fields = (List<Map<String, Object>>) config.get("fields");
        if(fields != null) {
            for(Map<String, Object> f : fields) {
                Object json = f.get("json");
                if(json != null) {
                    fieldNames.add(json.toString());
                }
            }
        }

        return fieldNames;
    }

    private Set<String> getSortableFields(Map<String, Object> config) {
        Set<String> fieldNames = getFieldNames(config);

        Object sortable = config.get("sortable");
        if(sortable instanceof List) {
            Set<String> allowed = new HashSet<String>();
            for(Object s : (List<?>) sortable) {
                if(s != null && fieldNames.contains(s.toString())) {
                    allowed.add(s.toString());
                }
            }
            return allowed;
        }

        return fieldNames;
    }

    private static boolean isEmpty(Object value) {
        return value == null || value == JSONObject.NULL || "".equals(value);
    }

    private static int compareValues(Object a, Object b, Collator collator) {
        if(a instanceof Number && b instanceof Number) {
            return Double.compare(((Number) a).doubleValue(), ((Number) b).doubleValue());
        }
        if(a instanceof Boolean && b instanceof Boolean) {
            return ((Boolean) a).compareTo((Boolean) b);
        }
        return collator.compare(a.toString(), b.toString());
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
            return val != null ? Integer.parseInt(val.trim()) : def;
        } catch(Exception e) {
            return def;
        }
    }

    private String trimToNull(String val) {
        if(val == null || val.trim().isEmpty()) {
            return null;
        }
        return val.trim();
    }

    // -------- ETAG HANDLING --------

    /**
     * Berechnet einen starken ETag als MD5-Hash des JSON-Inhalts.
     * Aendert sich der Inhalt nicht, bleibt der ETag identisch.
     */
    private String generateETag(String content) {
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

    /**
     * Prueft den If-None-Match Header gegen den aktuellen ETag.
     * Unterstuetzt "*", einzelne sowie kommaseparierte Listen von ETags
     * und optionale schwache (W/) Praefixe.
     */
    private boolean matchesETag(String ifNoneMatch, String currentETag) {

        if(ifNoneMatch == null || ifNoneMatch.trim().isEmpty()) {
            return false;
        }

        if("*".equals(ifNoneMatch.trim())) {
            return true;
        }

        for(String candidate : ifNoneMatch.split(",")) {
            String c = candidate.trim();

            if(c.startsWith("W/") || c.startsWith("w/")) {
                c = c.substring(2);
            }

            if(c.equals(currentETag)) {
                return true;
            }
        }

        return false;
    }

    private void writeError(PrintWriter writer, String msg) {
        try {
            JSONObject err = new JSONObject();
            err.put("error", msg);
            writer.write(err.toString());
        } catch(Exception ignore) {}
    }

    private void writeError(HttpServletResponse response, int status, String message) {

        try {
            if(response == null) return;

            response.setStatus(status);
            response.setContentType("application/json; charset=UTF-8");

            JSONObject err = new JSONObject();

            // 👉 HIER passiert die Magie
            JSONArray arr = new JSONArray();

            if(message != null) {
                String[] lines = message.split("\\r?\\n");

                for(String line : lines) {
                    arr.put(line);
                }
            }

            err.put("error", arr);

            PrintWriter writer = response.getWriter();
            writer.write(err.toString());

        } catch (Exception e) {
            e.printStackTrace();
        }
    }

    // -------- CONFIG LOADING --------

    private Map<String, Object> getConfig(Session session, String key) throws Exception {

        Database db = session.getCurrentDatabase();

        View view = db.getView("vwVariableAll");
        Document doc = view.getDocumentByKey(key, true);

        if(doc == null) {
            return null;
        }

        String json = doc.getItemValueString("ConfigJson");

        @SuppressWarnings("unchecked")
        Map<String, Object> config =
            (Map<String, Object>) JsonParser.fromJson(
                JsonJavaFactory.instanceEx, json
            );

        doc.recycle();
        view.recycle();
        db.recycle();

        return config;
    }
}
