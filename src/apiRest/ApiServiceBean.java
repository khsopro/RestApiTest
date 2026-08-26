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

        	response.setStatus(HttpServletResponse.SC_OK);
        	writer = response.getWriter();

        	HttpServletRequest req = (HttpServletRequest) fc.getExternalContext().getRequest();
        	String uri = req.getRequestURI();
        	String endpoint = extractEndpoint(uri);

            Map<String, String> params = fc.getExternalContext().getRequestParameterMap();

            Map<String, Object> config = getConfig(endpoint);

            if(config == null) {
                writeError(writer, "Unknown endpoint");
                return;
            }

            String cacheKey = DataService.getCacheKey(config, params);
            String etag = generateETag(cacheKey);

            if(etag != null) {
                response.setHeader("ETag", etag);
            }

            String ifNoneMatch = req.getHeader("If-None-Match");

            if(etag != null && matchesETag(ifNoneMatch, etag)) {
                response.setStatus(HttpServletResponse.SC_NOT_MODIFIED);
                return;
            }

            // Erst ab hier wird tatsaechlich der teure JSON-Aufbau angestossen
            Object data = DataService.getData(config, params);
            writer.write(data.toString());

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

    private Map<String, Object> getConfig(String key) throws Exception {

    	Session session = (Session) ExtLibUtil.resolveVariable("session");
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
