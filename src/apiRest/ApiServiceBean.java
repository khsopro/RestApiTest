package apiRest;

import java.io.PrintWriter;
import java.io.StringWriter;
import java.security.MessageDigest;
import java.util.Map;

import javax.faces.context.FacesContext;
import javax.servlet.http.*;

import lotus.domino.*;

import org.json.JSONArray;
import org.json.JSONObject;

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

            Document cacheDoc = getCacheDocument(session, endpoint);

            try {
                // Bevorzugt: ETag kommt direkt aus dem vom ApiCacheAgent
                // vorgebauten Cache-Dokument, kein View-Zugriff noetig.
                // Fallback (kein Cache-Dokument vorhanden, z.B. neuer
                // Endpoint oder Agent noch nicht gelaufen): wie bisher live
                // per Zweitdurchlauf ermitteln.
                String etag = (cacheDoc != null)
                    ? cacheDoc.getItemValueString("ETag")
                    : null;

                if(etag == null || etag.isEmpty()) {
                    etag = generateETag(DataService.getCacheKey(session, config, params));
                }

                if(etag != null) {
                    response.setHeader("ETag", etag);
                }

                String ifNoneMatch = req.getHeader("If-None-Match");

                if(etag != null && matchesETag(ifNoneMatch, etag)) {
                    response.setStatus(HttpServletResponse.SC_NOT_MODIFIED);
                    return;
                }

                String json = (cacheDoc != null) ? readCachedJson(cacheDoc) : null;

                if(json == null) {
                    // Kein (nutzbares) Cache-Dokument -> wie bisher live aufbauen
                    Object data = DataService.getData(session, config, params);
                    json = data.toString();
                }

                writer.write(json);

            } finally {
                if(cacheDoc != null) {
                    cacheDoc.recycle();
                }
            }

        } catch (Exception e) {
           StringWriter sw = new StringWriter();
           e.printStackTrace(new PrintWriter(sw));
           writeError(response, HttpServletResponse.SC_INTERNAL_SERVER_ERROR, sw.toString());
        }

        fc.responseComplete();
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

    /**
     * Sucht das vom ApiCacheAgent vorgebaute Cache-Dokument fuer den
     * Endpoint ueber die View vwApiCache (Selektion Form="ApiCache",
     * sortiert nach Endpoint). Existiert die View oder das Dokument
     * (noch) nicht, wird null zurueckgegeben und handleRequest() faellt
     * auf die alte Live-Berechnung zurueck - kein Fehler.
     */
    private Document getCacheDocument(Session session, String endpoint) {
        try {
            Database db = session.getCurrentDatabase();

            View cacheView = db.getView("vwApiCache");

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

            return body.getContentAsText();

        } catch(Exception e) {
            e.printStackTrace();
            return null;
        }
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
