package apiRest;

import java.security.MessageDigest;
import java.util.Date;
import java.util.HashMap;
import java.util.Map;
import java.util.Vector;

import lotus.domino.*;

import com.ibm.commons.util.io.json.*;

/**
 * Scheduled Agent: baut fuer jeden in vwVariableAll definierten Endpoint das
 * JSON einmalig vor (mit DataService.getData(), derselben Logik wie der
 * Live-Endpoint) und legt es zusammen mit dem passenden ETag in einem
 * Cache-Dokument ab. ApiServiceBean bleibt davon unberuehrt und bedient
 * weiterhin live aus der View - dieser Agent ist reine Zusatz-Infrastruktur
 * fuer eine spaetere Umstellung auf "aus Cache-Dokument lesen".
 */
public class ApiCacheAgent extends AgentBase {

    private static final String CACHE_FORM = "ApiCache";
    private static final String CONFIG_VIEW = "vwVariableAll";

    public void NotesMain() {

        try {
            Session session = getSession();
            AgentContext agentContext = session.getAgentContext();
            Database db = agentContext.getCurrentDatabase();

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
                            buildAndStore(session, db, endpoint);
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

    private void buildAndStore(Session session, Database db, String endpoint) throws Exception {

        View configView = db.getView(CONFIG_VIEW);
        Document configDoc = configView.getDocumentByKey(endpoint, true);

        if(configDoc == null) {
            configView.recycle();
            return;
        }

        String configJson = configDoc.getItemValueString("ConfigJson");

        @SuppressWarnings("unchecked")
        Map<String, Object> config =
            (Map<String, Object>) JsonParser.fromJson(
                JsonJavaFactory.instanceEx, configJson
            );

        configDoc.recycle();
        configView.recycle();

        Object data = DataService.getData(session, config, new HashMap<String, String>());
        String json = data.toString();
        String etag = hashETag(json);

        Document cacheDoc = findCacheDocument(db, endpoint);

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

    private Document findCacheDocument(Database db, String endpoint) throws Exception {

        String safeEndpoint = endpoint.replace("\"", "\\\"");
        String formula = "Form=\"" + CACHE_FORM + "\" & Endpoint=\"" + safeEndpoint + "\"";

        DocumentCollection dc = db.search(formula, null, 0);
        Document doc = (dc.getCount() > 0) ? dc.getFirstDocument() : null;
        dc.recycle();

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
}
