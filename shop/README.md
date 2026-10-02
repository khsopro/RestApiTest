# Text-Shop

Ein kleiner, eigener Online-Shop (ähnlich Shopify, aber schlank) zum Verkauf deiner Texte als
digitale Downloads – Kurzgeschichten, Gedichte, E-Books als PDF, EPUB usw.

**Funktionen**

- Schaufenster mit allen Texten, Detailseite mit Beschreibung, Titelbild und **öffentlicher Leseprobe**
- **Warenkorb**: mehrere Texte in einer Bestellung, eine Zahlung, ein Download-Link für alles
- Bezahlung über **Stripe Checkout** (Karte, PayPal, Klarna, SEPA … – je nach Stripe-Einstellungen)
- Nach dem Kauf ein persönlicher **Download-Link** (standardmäßig 30 Tage und 5 Downloads je Text) – auf der Danke-Seite **und per E-Mail**
- „Download-Link verloren?“: Käufer können sich ihre gültigen Links erneut zuschicken lassen
- Optional eine E-Mail an dich bei jedem Verkauf
- Pflicht-Häkchen zum Verzicht auf das Widerrufsrecht bei digitalen Inhalten (§ 356 Abs. 5 BGB), wird mit der Bestellung gespeichert
- **Verwaltung** unter `/admin`: Texte anlegen/bearbeiten, Dateien und Titelbilder hochladen, Bestellungen und Umsatz ansehen,
  Impressum/Datenschutz/AGB/Widerruf und Begrüßungstext pflegen
- Gekaufte Dateien sind nicht öffentlich erreichbar, nur über den Download-Link

Technik: Node.js ≥ 22.5, Express, eingebautes SQLite (`node:sqlite`), keine weitere Datenbank nötig.

## Schnellstart

```bash
cd shop
npm install
cp .env.example .env     # Werte eintragen (siehe unten)
npm start                # http://localhost:3000, Verwaltung unter /admin
```

## Stripe einrichten

1. Konto auf <https://stripe.com> anlegen. Zum Ausprobieren den **Testmodus** verwenden.
2. Unter *Entwickler → API-Schlüssel* den geheimen Schlüssel (`sk_test_…`) als `STRIPE_SECRET_KEY` eintragen.
3. Webhook anlegen (*Entwickler → Webhooks*): Endpunkt `https://deine-domain.de/webhook`,
   Ereignisse `checkout.session.completed` und `checkout.session.async_payment_succeeded`.
   Das Signatur-Secret (`whsec_…`) als `STRIPE_WEBHOOK_SECRET` eintragen.
   Lokal geht das mit der Stripe CLI: `stripe listen --forward-to localhost:3000/webhook`.
4. Testkauf mit Karte `4242 4242 4242 4242`, beliebiges Datum in der Zukunft, beliebige Prüfziffer.
5. Für echte Verkäufe auf Live-Schlüssel (`sk_live_…`) umstellen.

Die Bestellung wird sowohl über den Webhook als auch beim Zurückkehren auf die Danke-Seite erfasst,
doppelte Bestellungen entstehen dabei nicht.

## E-Mail einrichten

Die E-Mails werden über SMTP verschickt – das bietet praktisch jeder Mail-Anbieter. Für zuverlässige
Zustellung (nicht im Spam) eignet sich ein Versanddienst wie **Brevo** (kostenlos bis 300 Mails/Tag),
Postmark oder Mailjet; dort deine Absender-Domain bestätigen. Dann in `.env`:

```
SMTP_HOST=smtp-relay.brevo.com
SMTP_PORT=587
SMTP_USER=dein-login
SMTP_PASS=dein-smtp-schluessel
MAIL_FROM=shop@deine-domain.de
OWNER_EMAIL=du@deine-domain.de   # optional: Benachrichtigung bei jedem Verkauf
```

Jede Bestellung bekommt genau eine E-Mail. Ob sie rausging, siehst du unter *Bestellungen*; dort kannst du
sie auch erneut senden. Ohne SMTP-Einstellungen läuft der Shop weiter, der Link steht dann nur auf der
Danke-Seite.

## Online stellen

Der Shop braucht einen Server, auf dem Node.js läuft, und einen dauerhaften Speicherort für den Ordner
`DATA_DIR` (Datenbank + Dateien). Geeignet sind z.B. ein kleiner VPS (Hetzner, netcup), Render, Railway
oder Fly.io mit persistentem Volume. Wichtig:

- `BASE_URL` auf die echte `https://`-Adresse setzen
- `ADMIN_PASSWORD` und `SESSION_SECRET` (`openssl rand -hex 32`) setzen
- `DATA_DIR` regelmäßig sichern

## Rechtliches (Deutschland)

Bevor du verkaufst, solltest du in der Verwaltung unter *Rechtliches* Impressum, Datenschutzerklärung, AGB
und Widerrufsbelehrung hinterlegen (z.B. über einen Generator wie den der IT-Recht-Kanzlei oder von
e-recht24) und dich zur **Umsatzsteuer** informieren – bei Verkäufen an Privatpersonen in anderen EU-Ländern
gilt deren Steuersatz (OSS-Verfahren). Stripe Tax kann das automatisieren. Als Kleinunternehmer (§ 19 UStG)
den Hinweis „inkl. MwSt.“ in `src/views.js` anpassen.

## Tests

```bash
npm test
```

Die Tests spielen einen kompletten Kauf mit einem Stripe-Ersatz durch (Texte anlegen, Warenkorb, kaufen,
herunterladen, Download-Limit, E-Mail-Versand, Übernahme alter Bestellungen).

## Mögliche Erweiterungen

- Rabattcodes (Stripe Promotion Codes: `allow_promotion_codes: true` in `src/server.js`)
- Bundles (z.B. „alle Gedichte zusammen“ zum Sonderpreis)
- Wasserzeichen mit Käufer-E-Mail im PDF
