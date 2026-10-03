#!/usr/bin/env python3
"""
End-to-End-Test für das DRK-CMS.
Startet den PHP-Entwicklungsserver mit einer frischen SQLite-Datenbank
und klickt sich durch Einrichtung, Website und alle Verwaltungsmodule.

Aufruf:  python3 tests/smoke_test.py
"""
import http.cookiejar
import os
import re
import shutil
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PORT = 8765
BASE = f"http://127.0.0.1:{PORT}/"


class Client:
    def __init__(self):
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))
        self.csrf = None

    def req(self, path, data=None, expect=200):
        body = urllib.parse.urlencode(data, doseq=True).encode() if data is not None else None
        try:
            res = self.opener.open(BASE + path, body)
            status, html = res.status, res.read().decode()
        except urllib.error.HTTPError as e:
            status, html = e.code, e.read().decode()
        m = re.search(r'name="_csrf" value="([0-9a-f]+)"', html)
        if m:
            self.csrf = m.group(1)
        assert status == expect, f"{path}: Status {status}, erwartet {expect}\n{html[:500]}"
        for bad in ("Fatal error", "Warning:", "Notice:", "Deprecated:", "Uncaught"):
            assert bad not in html, f"{path}: PHP-Fehler gefunden\n{html[:2000]}"
        return html

    def post(self, path, data, expect=200):
        return self.req(path, dict(data, _csrf=self.csrf), expect)


def main():
    tmp = tempfile.mkdtemp()
    work = os.path.join(tmp, "cms")
    shutil.copytree(ROOT, work, ignore=shutil.ignore_patterns("cms.sqlite*", "config.php"))
    os.makedirs(os.path.join(work, "uploads"), exist_ok=True)
    env = dict(os.environ)
    server = subprocess.Popen(["php", "-S", f"127.0.0.1:{PORT}", "-t", work],
                              stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, env=env)
    time.sleep(1)
    ok = 0
    try:
        c = Client()

        # Ersteinrichtung
        html = c.req("admin.php")
        assert "DRK-CMS einrichten" in html
        c.post("admin.php", {"verein": "DRK-Ortsverein Teststadt", "name": "Test Admin", "email": "a@b.de",
                             "benutzername": "admin", "passwort": "geheim12345", "passwort2": "geheim12345"})
        html = c.req("admin.php")
        assert "Hallo Test Admin" in html; ok += 1

        # Öffentliche Seiten
        for slug in ["", "?seite=aktuelles", "?seite=ueber-uns", "?seite=bereitschaft", "?seite=blutspende", "?seite=mitmachen", "?seite=kontakt", "?seite=impressum"]:
            html = c.req("index.php" + slug)
            assert "DRK-Ortsverein Teststadt" in html
        c.req("index.php?seite=gibtsnicht", expect=404); ok += 1

        # Alle Module aufrufen
        for m in ["dashboard", "seiten", "news", "medien", "mitglieder", "unterstuetzer", "blutspende", "rezepte", "profil", "benutzer", "einstellungen", "protokoll"]:
            c.req(f"admin.php?m={m}")
        ok += 1

        # Seite anlegen + Baustein
        c.req("admin.php?m=seiten&a=neu")
        c.post("admin.php?m=seiten&a=speichern", {"titel": "Seniorenarbeit", "layout": "standard", "farbe": "rot",
                                                   "sortierung": "15", "veroeffentlicht": "1", "im_menue": "1"})
        html = c.req("admin.php?m=seiten")
        pid = re.search(r'a=bearbeiten&amp;id=(\d+)">\s*<strong>Seniorenarbeit', html).group(1)
        c.req(f"admin.php?m=seiten&a=bearbeiten&id={pid}")
        html = c.post(f"admin.php?m=seiten&a=block_neu&id={pid}", {"typ": "text"})
        bid = re.search(r'm=seiten&amp;a=block&amp;id=(\d+)', html).group(1)
        c.post(f"admin.php?m=seiten&a=block&id={bid}", {"d[titel]": "Kaffeenachmittag", "d[inhalt]": "Jeden **Mittwoch** <script>alert(1)</script>\n\n- Kaffee\n- Kuchen"})
        html = c.req("index.php?seite=seniorenarbeit")
        assert "Kaffeenachmittag" in html and "<strong>Mittwoch</strong>" in html and "<li>Kuchen</li>" in html
        assert "<script>alert(1)</script>" not in html, "XSS nicht maskiert!"
        c.req(f"admin.php?m=seiten&a=bearbeiten&id={pid}")
        c.post(f"admin.php?m=seiten&a=block_kopieren&id={bid}", {})
        c.post(f"admin.php?m=seiten&a=block_verschieben&id={bid}", {"richtung": "runter"}); ok += 1

        # Alle Bausteintypen anlegen und rendern
        for typ in ["bild_text", "bild", "zwei_spalten", "kacheln", "unterseiten", "hinweis", "button", "akkordeon", "kontakt", "zahlen",
                    "blutspendetermine", "unterstuetzer", "karte", "news"]:
            c.req(f"admin.php?m=seiten&a=bearbeiten&id={pid}")
            html = c.post(f"admin.php?m=seiten&a=block_neu&id={pid}", {"typ": typ})
            b = re.search(r'm=seiten&amp;a=block&amp;id=(\d+)', html).group(1)
            c.post(f"admin.php?m=seiten&a=block&id={b}", {"d[titel]": f"Test {typ}", "d[eintraege]": "A | B | blutspende\nC | D | https://drk.de",
                                                         "d[link]": "javascript:alert(1)", "d[text]": "Klick"})
        html = c.req("index.php?seite=seniorenarbeit")
        assert "javascript:" not in html, "unsicherer Link durchgelassen"
        assert "<details>" in html and 'class="tiles"' in html; ok += 1

        # Mitglieder
        c.req("admin.php?m=mitglieder&a=neu")
        c.post("admin.php?m=mitglieder&a=speichern", {"vorname": "Anna", "nachname": "Muster", "status": "aktiv", "geburtsdatum": "1990-05-01",
                                                       "mobil": "0170 123", "qualifikationen[]": ["Sanitätshelfer/in", "Hygienebelehrung (§ 43 IfSG)"],
                                                       "bereiche[]": ["Blutspende"]})
        c.req("admin.php?m=mitglieder&a=neu")
        c.post("admin.php?m=mitglieder&a=speichern", {"vorname": "=Bernd", "nachname": "Beispiel", "status": "aktiv"})
        html = c.req("admin.php?m=mitglieder&quali=Sanit%C3%A4tshelfer%2Fin")
        assert "Muster" in html and "Beispiel" not in html
        csv = c.req("admin.php?m=mitglieder&a=export")
        assert "Anna" in csv and "'=Bernd" in csv, "CSV-Export/Formelschutz"
        mid = re.search(r'a=bearbeiten&amp;id=(\d+)">\s*<strong>Muster', c.req("admin.php?m=mitglieder")).group(1); ok += 1

        # Unterstützer
        c.req("admin.php?m=unterstuetzer&a=neu")
        c.post("admin.php?m=unterstuetzer&a=speichern", {"name": "Bäckerei Krume", "typ": "Firma", "art": "Sachspende", "betrag": "250,50",
                                                          "oeffentlich": "1", "webseite": "https://example.org"})
        html = c.req("admin.php?m=unterstuetzer")
        assert "250,5" in html
        assert "Bäckerei Krume" in c.req("index.php"); ok += 1

        # Rezept mit Zutaten
        c.req("admin.php?m=rezepte&a=neu")
        c.post("admin.php?m=rezepte&a=speichern", {"name": "Kartoffelsuppe", "portionen": "10", "kategorie": "Warmes Gericht",
                                                    "z[menge][]": ["1,5", "500", "1", ""], "z[einheit][]": ["kg", "g", "Stück", ""],
                                                    "z[name][]": ["Kartoffeln", "Butter", "Zwiebel", ""],
                                                    "z[abteilung][]": ["Obst & Gemüse", "Kühlregal", "Obst & Gemüse", ""]})
        html = c.req("admin.php?m=rezepte")
        rid = re.search(r'a=bearbeiten&amp;id=(\d+)">\s*<strong>Kartoffelsuppe', html).group(1)
        html = c.req(f"admin.php?m=rezepte&a=bearbeiten&id={rid}")
        assert 'value="1,5"' in html; ok += 1

        # Blutspendetermin mit Menü, Einkaufsliste, Personal
        c.req("admin.php?m=blutspende&a=neu")
        html = c.post("admin.php?m=blutspende&a=speichern", {"datum": "2099-03-15", "beginn": "15:30", "ende": "19:30", "ort": "Gemeindehaus",
                                                       "erwartete_spender": "80", "oeffentlich": "1", "standard_schichten": "1"})
        assert "Gemeindehaus" in html and "tab=personal" in html, "nach dem Anlegen soll der neue Termin geöffnet werden"
        tid = re.search(r'a=termin&amp;id=(\d+)', html).group(1)
        c.req(f"admin.php?m=blutspende&a=termin&id={tid}&tab=menue")
        c.post(f"admin.php?m=blutspende&a=menue_add&id={tid}", {"rezept_id": rid, "portionen": "40"})
        nudel = re.search(r'<option value="(\d+)">Nudelsalat', c.req(f"admin.php?m=blutspende&a=termin&id={tid}&tab=menue")).group(1)
        c.post(f"admin.php?m=blutspende&a=menue_add&id={tid}", {"rezept_id": nudel, "portionen": "40"})
        html = c.req(f"admin.php?m=blutspende&a=termin&id={tid}&tab=einkauf")
        assert "6 kg</strong> Kartoffeln" in html, "Einkaufsliste: 1,5 kg x 4 = 6 kg erwartet"
        assert "2 kg</strong> Butter" in html
        assert "4 Stück</strong> Zwiebel" in html
        assert "2 kg</strong> Nudeln" in html, "Nudelsalat: 1 kg für 20 Port. -> 40 Port. = 2 kg"
        c.req(f"admin.php?m=blutspende&a=einkauf_druck&id={tid}")
        html = c.req(f"admin.php?m=blutspende&a=termin&id={tid}&tab=personal")
        assert "beginn<" not in html and "ende–" not in html, "Platzhalter in Standard-Schichten nicht ersetzt"
        assert "Aufbau <small class=\"muted\">13:30–15:30" in html
        sid = re.search(r'name="sid" value="(\d+)"', html).group(1)
        c.post(f"admin.php?m=blutspende&a=einteilen&id={tid}", {"sid": sid, "mitglied_id": mid, "status": "zugesagt"})
        html = c.req(f"admin.php?m=blutspende&a=termin&id={tid}&tab=personal")
        assert "Anna Muster" in html
        html = c.req(f"admin.php?m=blutspende&a=dienstplan_druck&id={tid}")
        assert "Anna Muster" in html
        assert "Gemeindehaus" in c.req("index.php?seite=blutspende"); ok += 1

        # Aktuelles / News
        html = c.req("index.php")
        assert "Neue Sanitätsrucksäcke" in html and "Alle Meldungen" in html, "News-Kacheln auf der Startseite"
        assert 'href="index.php?seite=blutspende"' in html, "Seitenkürzel in Textlinks auflösen"
        html = c.req("index.php?seite=aktuelles")
        assert "Blutspender/innen gesucht" in html and "Wichtig</span>" in html
        assert "Jugendrotkreuz-Gruppe" in html, "Bindestriche in der Kurzfassung erhalten"
        html = c.req("index.php?news=neue-sanitaetsrucksaecke-fuer-die-bereitschaft")
        assert "<h2>Was ist neu?</h2>" in html and "Alle Meldungen" in html
        c.req("admin.php?m=news&a=neu")
        c.post("admin.php?m=news&a=speichern", {"titel": "Zukunftsmeldung", "datum": "2099-01-01", "inhalt": "geplant", "veroeffentlicht": "1"})
        c.req("admin.php?m=news&a=neu")
        c.post("admin.php?m=news&a=speichern", {"titel": "Entwurf <b>X</b>", "datum": "2020-01-01", "inhalt": "geheim"})
        for i in range(12):
            c.req("admin.php?m=news&a=neu")
            c.post("admin.php?m=news&a=speichern", {"titel": f"Meldung {i}", "datum": "2021-01-01", "inhalt": "Text", "veroeffentlicht": "1",
                                                     "kategorie": "Blutspende"})
        html = c.req("admin.php?m=news")
        assert "geplant</span>" in html and "Entwurf</span>" in html
        html = c.req("index.php?seite=aktuelles")
        assert "Zukunftsmeldung" not in html and "Entwurf" not in html, "geplante/Entwürfe nicht öffentlich"
        assert 'class="pager"' in html and "seite=aktuelles&amp;p=2" in html
        assert "Meldung" in c.req("index.php?seite=aktuelles&p=2")
        x = Client()
        x.req("index.php?news=zukunftsmeldung", expect=404)
        x.req("index.php?news=entwurf-b-x-b", expect=404)
        assert "Vorschau" in c.req("index.php?news=zukunftsmeldung"), "Redaktion darf Vorschau sehen"
        feed = x.req("feed.php")
        assert "<rss" in feed and "Blutspender/innen gesucht" in feed and "Zukunftsmeldung" not in feed; ok += 1

        # Benutzer für Helferin anlegen, als Helferin anmelden und selbst eintragen
        c.req(f"admin.php?m=benutzer&a=neu&mitglied={mid}")
        c.post("admin.php?m=benutzer&a=speichern", {"benutzername": "anna", "name": "Anna Muster", "passwort": "helferin12345",
                                                     "rollen[]": ["helfer"], "mitglied_id": mid, "aktiv": "1"})
        c.post("admin.php?m=logout", {})
        h = Client()
        h.req("admin.php?m=login")
        h.post("admin.php?m=login", {"benutzername": "anna", "passwort": "helferin12345"})
        html = h.req("admin.php?m=profil")
        assert "Ich helfe mit" in html
        s2 = re.findall(r'm=profil&amp;a=eintragen".*?name="sid" value="(\d+)"', html, re.S)[0]
        h.post("admin.php?m=profil&a=eintragen", {"sid": s2})
        assert "Du bist dabei" in h.req("admin.php?m=profil")
        h.req("admin.php?m=mitglieder", expect=403)
        h.req("admin.php?m=einstellungen", expect=403); ok += 1

        # CSRF-Schutz
        h.req("admin.php?m=profil&a=konto", {"name": "X", "_csrf": "falsch"}, expect=400); ok += 1

        # Falsches Passwort
        x = Client()
        x.req("admin.php?m=login")
        assert "falsch" in x.post("admin.php?m=login", {"benutzername": "admin", "passwort": "nein"}); ok += 1

        print(f"OK – {ok} Testgruppen erfolgreich")
    finally:
        server.terminate()
        shutil.rmtree(tmp, ignore_errors=True)


if __name__ == "__main__":
    try:
        main()
    except AssertionError:
        import traceback
        traceback.print_exc()
        print("FEHLER")
        sys.exit(1)
