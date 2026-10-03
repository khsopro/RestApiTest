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

    def upload(self, path, fields, file_field, filename, content, mime, expect=200):
        """multipart/form-data senden (Datei-Upload)"""
        boundary = "----drkcmstest"
        body = b""
        for k, v in dict(fields, _csrf=self.csrf).items():
            body += f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode()
        body += (f'--{boundary}\r\nContent-Disposition: form-data; name="{file_field}"; filename="{filename}"\r\n'
                 f'Content-Type: {mime}\r\n\r\n').encode() + content + f"\r\n--{boundary}--\r\n".encode()
        req = urllib.request.Request(BASE + path, body, {"Content-Type": f"multipart/form-data; boundary={boundary}"})
        try:
            res = self.opener.open(req)
            status, html = res.status, res.read().decode()
        except urllib.error.HTTPError as e:
            status, html = e.code, e.read().decode()
        assert status == expect, f"{path}: Status {status}\n{html[:500]}"
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
        for m in ["dashboard", "seiten", "news", "medien", "mitglieder", "unterstuetzer", "blutspende", "rezepte", "helferprofile", "stellen", "profil", "benutzer", "einstellungen", "protokoll"]:
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
        assert re.search(r'Aufbau( <a class="job-link"[^>]*>ⓘ</a>)?\s*<small class="muted">13:30–15:30', html), "Aufbau 13:30–15:30"
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

        # Helfer-Profile (Blutspende)
        html = c.req("admin.php?m=helferprofile")
        assert "Anna Muster" in html and "Bernd" not in html, "Team: Bereich Blutspende oder eingeteilt"
        assert '<span class="tag">Sanitätshelfer/in</span>' in html
        html = c.req("admin.php?m=helferprofile&alle=1")
        assert "Anna Muster" in html and "Beispiel" in html
        html = c.req("admin.php?m=helferprofile&alle=1&quali=Sanit%C3%A4tshelfer%2Fin")
        assert "Anna Muster" in html and "Beispiel" not in html and 'class="tag match"' in html
        html = c.req(f"admin.php?m=helferprofile&a=profil&id={mid}")
        assert "Gemeindehaus" in html and "Häufigste Aufgaben" in html and "0170 123" in html
        assert "Muster, Anna" in c.req("admin.php?m=helferprofile&a=telefonliste")
        assert f"m=helferprofile&amp;a=profil&amp;id={mid}" in c.req(f"admin.php?m=blutspende&a=termin&id={tid}&tab=personal"); ok += 1

        # Stellenbeschreibungen (Blutspende)
        html = c.req("admin.php?m=stellen")
        assert html.count('class="profile-card job-card"') == 7, "7 Vorlagen bei der Einrichtung"
        kid = re.search(r'a=ansehen&amp;id=(\d+)">Küche', html).group(1)
        html = c.req(f"admin.php?m=stellen&a=ansehen&id={kid}")
        assert "Hygienebelehrung (§ 43 IfSG)" in html and 'class="job-checklist"' in html and "Gemeindehaus" in html
        html = c.req(f"admin.php?m=blutspende&a=termin&id={tid}&tab=personal")
        assert f"m=stellen&amp;a=ansehen&amp;id={kid}" in html, "Schicht verlinkt Stellenbeschreibung"
        assert 'list="stellen"' in html and '<option value="Küche">' in html
        c.req("admin.php?m=stellen&a=neu")
        html = c.post("admin.php?m=stellen&a=speichern", {"titel": "Parkplatzeinweisung", "kurz": "Autos einweisen",
                                                           "ablauf": "Warnweste anziehen\nPylonen aufstellen", "sortierung": "80"})
        assert "Parkplatzeinweisung" in html and "Pylonen aufstellen" in html
        c.req("admin.php?m=stellen&a=neu")
        assert "gibt es bereits" in c.post("admin.php?m=stellen&a=speichern", {"titel": "KÜCHE", "sortierung": "1"})
        html = c.req("admin.php?m=stellen&a=druck")
        assert "Einweisungsmappe" in html and "Parkplatzeinweisung" in html and "Ruheraum/Betreuung" in html
        c.req(f"admin.php?m=stellen&a=ansehen&id={kid}")
        c.post(f"admin.php?m=stellen&a=loeschen&id={kid}", {})
        assert "1 Vorlagen angelegt" in c.post("admin.php?m=stellen&a=vorlagen", {}); ok += 1

        # Aufgaben verwalten: archivieren, wiederherstellen, kopieren, Standard-Schicht
        html = c.req("admin.php?m=stellen")
        assert "Archiv (0)" in html and 'class="card-actions"' in html
        kid = re.search(r'a=ansehen&amp;id=(\d+)">Küche', html).group(1)
        html = c.post(f"admin.php?m=stellen&a=archivieren&id={kid}", {})
        assert "archiviert" in html and ">Küche</a>" not in html and "Archiv (1)" in html
        assert ">Küche</a>" in c.req("admin.php?m=stellen&archiv=1")
        html = c.req(f"admin.php?m=blutspende&a=termin&id={tid}&tab=personal")
        assert f"a=ansehen&amp;id={kid}" not in html and '<option value="Küche">' not in html, "archivierte Aufgabe nicht mehr verlinkt"
        c.req("admin.php?m=stellen&a=neu")
        c.post("admin.php?m=stellen&a=speichern", {"titel": "Getränkestand", "standard": "1", "std_von": "beginn", "std_bis": "18:00", "std_anzahl": "2", "sortierung": "55"})
        c.req("admin.php?m=blutspende&a=neu")
        html = c.post("admin.php?m=blutspende&a=speichern", {"datum": "2099-06-01", "beginn": "15:00", "ende": "19:00", "ort": "Turnhalle", "standard_schichten": "1"})
        t2 = re.search(r'a=termin&amp;id=(\d+)', html).group(1)
        html = c.req(f"admin.php?m=blutspende&a=termin&id={t2}&tab=personal")
        assert "Getränkestand" in html and "15:00–18:00" in html, "neue Aufgabe als Standard-Schicht"
        assert "<h3>Küche" not in html, "archivierte Aufgabe nicht automatisch eingeplant"
        html = c.post(f"admin.php?m=stellen&a=wiederherstellen&id={kid}", {})
        assert "wiederhergestellt" in html
        html = c.post(f"admin.php?m=stellen&a=kopieren&id={kid}", {})
        assert 'value="Küche (Kopie)"' in html, "Kopie öffnet sich zum Bearbeiten"; ok += 1

        # Helfer-Profile verwalten: aufnehmen, neu, bearbeiten, archivieren, löschen
        html = c.req("admin.php?m=helferprofile&a=neu")
        assert "Schon Mitglied im Verein?" in html and "Beispiel, =Bernd" in html
        bid = re.search(r'<option value="(\d+)">Beispiel, =Bernd', html).group(1)
        assert "=Bernd Beispiel" in c.post("admin.php?m=helferprofile&a=aufnehmen", {"mitglied_id": bid})
        assert "=Bernd Beispiel" in c.req("admin.php?m=helferprofile")
        c.req("admin.php?m=helferprofile&a=neu")
        html = c.post("admin.php?m=helferprofile&a=speichern", {"vorname": "Lena", "nachname": "Neu", "mobil": "0151 111",
                                                                 "qualifikationen[]": ["Erste-Hilfe-Kurs"], "verfuegbarkeit": "abends"})
        lid = re.search(r'm=helferprofile&amp;a=bearbeiten&amp;id=(\d+)', html).group(1)
        assert "Lena Neu" in html and "abends" in html
        c.req(f"admin.php?m=helferprofile&a=bearbeiten&id={lid}")
        assert "nur sonntags" in c.post(f"admin.php?m=helferprofile&a=speichern&id={lid}", {"vorname": "Lena", "nachname": "Neu", "verfuegbarkeit": "nur sonntags"})
        assert "Blutspende" in c.req(f"admin.php?m=mitglieder&a=bearbeiten&id={lid}"), "neues Profil = Mitglied mit Bereich Blutspende"
        html = c.post(f"admin.php?m=helferprofile&a=archivieren&id={mid}", {})
        assert "archiviert" in html and "Anna Muster" not in html.split('class="profile-grid"')[1]
        assert "Anna Muster" in c.req("admin.php?m=helferprofile&archiv=1")
        html = c.req(f"admin.php?m=blutspende&a=termin&id={t2}&tab=personal")
        assert f'<option value="{mid}">' not in html, "archivierte Helferin nicht mehr einteilbar"
        assert "ist wieder im Blutspende-Team" in c.post(f"admin.php?m=helferprofile&a=wiederherstellen&id={mid}", {})
        # Nur-Blutspende-Benutzer: archivieren ja, löschen nein
        c.req("admin.php?m=benutzer&a=neu")
        c.post("admin.php?m=benutzer&a=speichern", {"benutzername": "bsteam", "passwort": "blutspende123", "rollen[]": ["blutspende"], "aktiv": "1"})
        bs = Client()
        bs.req("admin.php?m=login")
        bs.post("admin.php?m=login", {"benutzername": "bsteam", "passwort": "blutspende123"})
        html = bs.req("admin.php?m=helferprofile")
        assert "Archivieren" in html and "Bearbeiten" in html and ">Löschen<" not in html
        bs.post(f"admin.php?m=helferprofile&a=loeschen&id={lid}", {}, expect=403)
        html = c.req("admin.php?m=helferprofile")
        assert ">Löschen<" in html
        c.post(f"admin.php?m=helferprofile&a=loeschen&id={lid}", {})
        assert "Lena Neu" not in c.req("admin.php?m=helferprofile&alle=1"); ok += 1

        # Flexibles Schichtsystem: feste + flexible Schichten, Lücken, Stunden
        def new_helper(vor, nach):
            c.req("admin.php?m=helferprofile&a=neu")
            h_ = c.post("admin.php?m=helferprofile&a=speichern", {"vorname": vor, "nachname": nach})
            return re.search(r'm=helferprofile&amp;a=bearbeiten&amp;id=(\d+)', h_).group(1)
        carl = new_helper("Carl", "Flex")
        dora = new_helper("Dora", "Spontan")
        c.req("admin.php?m=blutspende&a=neu")
        html = c.post("admin.php?m=blutspende&a=speichern", {"datum": "2099-08-01", "beginn": "15:00", "ende": "19:00", "ort": "Flexhalle"})
        ft = re.search(r'a=termin&amp;id=(\d+)', html).group(1)
        P = f"admin.php?m=blutspende&a=termin&id={ft}&tab=personal"
        c.req(P)
        c.post(f"admin.php?m=blutspende&a=schicht_add&id={ft}", {"aufgabe": "Anmeldung", "von": "15:00", "bis": "19:00", "benoetigt": "1", "flexibel": "1"})
        c.post(f"admin.php?m=blutspende&a=schicht_add&id={ft}", {"aufgabe": "Aufbau", "von": "14:00", "bis": "15:00", "benoetigt": "2"})
        c.post(f"admin.php?m=blutspende&a=schicht_add&id={ft}", {"aufgabe": "Imbiss-Ausgabe", "von": "18:10", "bis": "19:00", "benoetigt": "1", "flexibel": "1"})
        html = c.req(P)
        sid_of = lambda name, h_: re.search(r'id="s(\d+)">\s*<div class="shift-head">\s*<h3>' + re.escape(name), h_).group(1)
        anm, auf, imb = sid_of("Anmeldung", html), sid_of("Aufbau", html), sid_of("Imbiss-Ausgabe", html)
        assert "18:15–19:00" in html, "Schichtzeiten auf 15 Minuten gerundet"
        assert 'class="timeline"' in html and "feste Zeit" in html and "flexibel" in html
        c.post(f"admin.php?m=blutspende&a=einteilen&id={ft}", {"sid": anm, "mitglied_id": mid, "von": "15:00", "bis": "17:00", "status": "zugesagt"})
        html = c.req(P)
        assert "Noch offen: 17:00–19:00 (1 fehlt)" in html, "Lücke erkannt"
        c.post(f"admin.php?m=blutspende&a=einteilen&id={ft}", {"sid": anm, "mitglied_id": carl, "von": "17:00", "bis": "19:00", "status": "zugesagt"})
        html = c.req(P)
        assert "Durchgehend besetzt." in html, "Staffelübergabe 15–17 / 17–19 = durchgehend besetzt"
        assert "bereits eingeteilt" in c.post(f"admin.php?m=blutspende&a=einteilen&id={ft}", {"sid": anm, "mitglied_id": mid, "von": "16:00", "bis": "16:45"})
        c.post(f"admin.php?m=blutspende&a=einteilen&id={ft}", {"sid": auf, "mitglied_id": mid})
        c.post(f"admin.php?m=blutspende&a=einteilen&id={ft}", {"sid": auf, "mitglied_id": carl})
        assert "bereits eingeteilt" in c.post(f"admin.php?m=blutspende&a=einteilen&id={ft}", {"sid": auf, "mitglied_id": carl}), "feste Schicht: Person nur einmal"
        c.post(f"admin.php?m=blutspende&a=einteilen&id={ft}", {"sid": imb, "mitglied_id": carl, "von": "18:15", "bis": "19:00"})
        html = c.req(P)
        assert "Überschneidung mit: Anmeldung" in html, "Konflikt Carl Anmeldung/Imbiss"
        anna_e = re.search(r'name="eid" value="(\d+)">\s*<select name="von"[^>]*><option value="15:00"[^>]*>15:00</option>.*?value="17:00" selected', html, re.S).group(1)
        c.post(f"admin.php?m=blutspende&a=einteilung_update&id={ft}", {"eid": anna_e, "von": "13:00", "bis": "16:00", "status": "zugesagt"})
        html = c.req(P)
        assert '15:00–16:00 Anmeldung' in html and '14:00–15:00 Aufbau' in html, "Ablauf pro Person, Zeiten eingepasst"
        assert "16:00–17:00 (1 fehlt)" in html
        html = c.post(f"admin.php?m=blutspende&a=schicht_update&id={ft}", {"sid": anm, "aufgabe": "Anmeldung", "von": "15:30", "bis": "19:00", "benoetigt": "1", "flexibel": "1"})
        assert "an das neue Zeitfenster angepasst" in html and "15:30–16:00 Anmeldung" in html
        assert "Dienstplan Blutspende" in c.req(f"admin.php?m=blutspende&a=dienstplan_druck&id={ft}")
        # Stunden nach dem Dienst
        H = f"admin.php?m=blutspende&a=termin&id={ft}&tab=stunden"
        c.req(H)
        assert "wie geplant übernommen" in c.post(f"admin.php?m=blutspende&a=stunden_uebernehmen&id={ft}", {})
        html = c.req(H)
        e_anna = re.search(r'<td>Anmeldung</td>\s*<td class="nowrap muted">15:30–16:00</td>\s*<td><input type="time" step="900" name="ist_von\[(\d+)\]', html).group(1)
        e_imb = re.search(r'<td>Imbiss-Ausgabe</td>\s*<td class="nowrap muted">18:15–19:00</td>\s*<td><input type="time" step="900" name="ist_von\[(\d+)\]', html).group(1)
        html = c.post(f"admin.php?m=blutspende&a=stunden_speichern&id={ft}", {f"ist_von[{e_anna}]": "15:31", f"ist_bis[{e_anna}]": "16:29",
                                                                              f"ist_von[{e_imb}]": "", f"ist_bis[{e_imb}]": "", f"fehlt[{e_imb}]": "1"})
        assert "Stunden gespeichert (2 Änderungen)" in html
        assert "Summe Muster, Anna</td><td class=\"right nowrap\"><strong>2 Std.</strong>" in html, "Anna 14–15 + 15:30–16:30 = 2 Std."
        assert "Summe Flex, Carl</td><td class=\"right nowrap\"><strong>3 Std.</strong>" in html, "Carl 14–15 + 17–19, Imbiss nicht erschienen"
        html = c.post(f"admin.php?m=blutspende&a=stunden_nachtragen&id={ft}", {"mitglied_id": dora, "sid": auf, "von": "14:00", "bis": "15:30"})
        assert "Summe Spontan, Dora</td><td class=\"right nowrap\"><strong>1,5 Std.</strong>" in html
        html = c.req("admin.php?m=ehrenamt&jahr=2099")
        assert "Flex, Carl" in html and "3 Std." in html and "6,5 Std." in html, "Jahresauswertung (2 + 3 + 1,5)"
        csv = c.req("admin.php?m=ehrenamt&a=export&jahr=2099")
        assert '"Flex, Carl";01.08.2099;Flexhalle;' in csv and "Summe;3,00" in csv, csv[:400]
        html = c.req(f"admin.php?m=ehrenamt&a=nachweis&id={carl}&jahr=2099")
        assert "Bescheinigung über ehrenamtliche Tätigkeit" in html and "Insgesamt (1 Einsätze)" in html and "Aufbau, Anmeldung" in html
        html = c.req(f"admin.php?m=helferprofile&a=profil&id={carl}")
        assert "tatsächlich 17:00–19:00" in html and "nicht erschienen" in html, "Ist-Zeiten im Helfer-Profil"; ok += 1

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
        html = h.req("admin.php?m=profil")
        assert "Du: " in html and "m=stellen&amp;a=ansehen" in html, "Selbst-Eintragung verlinkt Stellenbeschreibungen"
        h.req("admin.php?m=profil")
        html = h.post("admin.php?m=profil&a=eintragen", {"sid": anm, "von": "16:00", "bis": "17:00"})
        assert "von 16:00 bis 17:00 Uhr eingetragen" in html, "Selbst-Eintragung in die Lücke"
        assert "schon eingeteilt" in h.post("admin.php?m=profil&a=eintragen", {"sid": anm, "von": "16:30", "bis": "18:00"})
        assert "schon voll besetzt" in h.post("admin.php?m=profil&a=eintragen", {"sid": anm, "von": "17:00", "bis": "18:00"})
        html = h.req("admin.php?m=profil")
        assert "Du: 16:00–17:00" in html and "Meine Ehrenamtsstunden" in html
        html = h.req("admin.php?m=stellen")
        assert "Küche" in html and "Neue Stellenbeschreibung" not in html, "Helfer/innen dürfen nur lesen"
        sid_k = re.search(r'a=ansehen&amp;id=(\d+)">Anmeldung', html).group(1)
        assert "Bearbeiten" not in h.req(f"admin.php?m=stellen&a=ansehen&id={sid_k}")
        h.post(f"admin.php?m=stellen&a=speichern&id={sid_k}", {"titel": "gehackt"}, expect=403)
        h.req("admin.php?m=mitglieder", expect=403)
        h.req("admin.php?m=einstellungen", expect=403)
        h.req("admin.php?m=helferprofile", expect=403)
        # eigenes Profilfoto hochladen (1x1-PNG) und kein PHP einschleusen
        png = bytes.fromhex("89504e470d0a1a0a0000000d4948445200000001000000010806000000"
                            "1f15c4890000000d49444154789c6360000002000154a24f5d0000000049454e44ae426082")
        h.req("admin.php?m=profil")
        h.upload("admin.php?m=profil&a=mitglied", {"mobil": "0170 123"}, "foto_datei", "ich.png", png, "image/png")
        assert '<img src="uploads/' in h.req("admin.php?m=profil"), "Profilfoto gespeichert"
        h.req("admin.php?m=profil")
        html = h.upload("admin.php?m=profil&a=mitglied", {"mobil": "0170 123"}, "foto_datei", "boese.php", b"<?php echo 1;", "image/png")
        assert "nicht erlaubt" in h.req("admin.php?m=profil") or "nicht erlaubt" in html
        a2 = Client()
        a2.req("admin.php?m=login")
        a2.post("admin.php?m=login", {"benutzername": "admin", "passwort": "geheim12345"})
        assert '<img src="uploads/' in a2.req("admin.php?m=helferprofile"), "Foto in der Profilübersicht"; ok += 1

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
