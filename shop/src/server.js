const fs = require('node:fs');
const path = require('node:path');
const express = require('express');
const multer = require('multer');

const { loadConfig } = require('./config');
const { openDb } = require('./db');
const { createAuth } = require('./auth');
const { createMailer } = require('./mailer');
const { parsePrice, slugify, randomToken } = require('./util');
const views = require('./views');

const FILE_EXTS = ['.pdf', '.epub', '.mobi', '.azw3', '.docx', '.odt', '.txt', '.rtf', '.zip'];
const COVER_EXTS = ['.jpg', '.jpeg', '.png', '.webp'];
const FLASH = {
  saved: 'Gespeichert.',
  created: 'Text angelegt.',
  deleted: 'Text gelöscht.',
  hidden: 'Der Text hat Bestellungen und wurde deshalb nur versteckt.',
  mailed: 'E-Mail wurde verschickt.',
  mailfail: 'E-Mail konnte nicht verschickt werden – prüfe die SMTP-Einstellungen und das Server-Log.',
};
const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

function createApp({ config, repo, stripe, mailer = createMailer(config) }) {
  const app = express();
  const auth = createAuth(config);
  const paymentsEnabled = !!stripe;

  app.disable('x-powered-by');
  app.set('trust proxy', 1);
  app.use((req, res, next) => {
    res.setHeader('X-Content-Type-Options', 'nosniff');
    res.setHeader('X-Frame-Options', 'DENY');
    res.setHeader('Referrer-Policy', 'same-origin');
    res.locals.ctx = { config, repo, paymentsEnabled, mailEnabled: mailer.enabled, flash: FLASH[req.query.msg] || '' };
    next();
  });

  // Stripe needs the raw body to verify the signature, so this comes before the body parsers.
  app.post('/webhook', express.raw({ type: 'application/json' }), async (req, res) => {
    if (!stripe || !config.stripeWebhookSecret) return res.status(400).send('Webhook nicht konfiguriert');
    let event;
    try {
      event = stripe.webhooks.constructEvent(req.body, req.headers['stripe-signature'], config.stripeWebhookSecret);
    } catch (err) {
      return res.status(400).send(`Ungültige Signatur: ${err.message}`);
    }
    try {
      if (event.type === 'checkout.session.completed' || event.type === 'checkout.session.async_payment_succeeded') {
        fulfill(event.data.object);
      }
      res.json({ received: true });
    } catch (err) {
      console.error('Webhook-Fehler', err);
      res.status(500).send('Fehler');
    }
  });

  app.use(express.urlencoded({ extended: false, limit: '2mb' }));
  app.use('/static', express.static(path.join(__dirname, '..', 'public'), { maxAge: '1h' }));
  app.use('/covers', express.static(config.coversDir, { maxAge: '1d' }));

  /** Records a paid Checkout Session as an order (idempotent). */
  function fulfill(session) {
    if (session.payment_status !== 'paid') return null;
    const productId = Number(session.metadata?.product_id);
    if (!repo.getProduct(productId)) throw new Error(`Unbekanntes Produkt ${session.metadata?.product_id}`);
    const expires = new Date(Date.now() + config.downloadDays * 24 * 3600 * 1000);
    const order = repo.createOrderOnce({
      stripe_session_id: session.id,
      product_id: productId,
      email: session.customer_details?.email || session.customer_email || '',
      amount_cents: session.amount_total ?? 0,
      currency: session.currency || config.currency,
      download_token: randomToken(),
      waiver_consent_at: session.metadata?.waiver_consent_at || null,
      expires_at: expires.toISOString(),
    });
    // Runs in the background so the buyer is not kept waiting; the claim makes sure it goes out once.
    deliverOrderEmail(order);
    return order;
  }

  /** Sends the download link to the buyer (once per order) and notifies the shop owner. */
  async function deliverOrderEmail(order) {
    if (!mailer.enabled || !order.email || !repo.claimOrderEmail(order.id)) return;
    const p = repo.getProduct(order.product_id);
    try {
      await mailer.purchase(order, p);
      repo.markOrderEmail(order.id, true);
    } catch (err) {
      repo.markOrderEmail(order.id, false);
      console.error(`E-Mail zu Bestellung ${order.id} fehlgeschlagen`, err);
      return;
    }
    mailer.ownerNotice(order, p).catch((err) => console.error('Verkaufs-Benachrichtigung fehlgeschlagen', err));
  }

  // Simple in-memory limit for the "lost link" form: 5 requests per IP and hour.
  const linkRequests = new Map();
  function allowLinkRequest(ip) {
    const now = Date.now();
    const recent = (linkRequests.get(ip) || []).filter((t) => now - t < 3600 * 1000);
    if (recent.length >= 5) return false;
    recent.push(now);
    linkRequests.set(ip, recent);
    return true;
  }

  /* ---------- Shop ---------- */

  app.get('/', (req, res) => {
    res.send(views.home(res.locals.ctx, repo.listProducts()));
  });

  app.get('/t/:slug', (req, res, next) => {
    const p = repo.getProductBySlug(req.params.slug);
    if (!p || (!p.active && !auth.isAdmin(req))) return next();
    res.send(views.product(res.locals.ctx, p));
  });

  app.post('/buy/:slug', async (req, res, next) => {
    const p = repo.getProductBySlug(req.params.slug);
    if (!p || !p.active) return next();
    const ctx = res.locals.ctx;
    if (!stripe || !p.file_name) {
      return res.status(503).send(views.product(ctx, p, { error: 'Kauf ist gerade nicht möglich.' }));
    }
    if (req.body.waiver !== '1') {
      return res.status(400).send(views.product(ctx, p, { error: 'Bitte bestätige den Hinweis zum Widerrufsrecht.' }));
    }
    try {
      const session = await stripe.checkout.sessions.create({
        mode: 'payment',
        line_items: [{
          quantity: 1,
          price_data: {
            currency: config.currency,
            unit_amount: p.price_cents,
            product_data: { name: p.title, ...(p.subtitle ? { description: p.subtitle } : {}) },
          },
        }],
        metadata: { product_id: String(p.id), waiver_consent_at: new Date().toISOString() },
        success_url: `${config.baseUrl}/success?session_id={CHECKOUT_SESSION_ID}`,
        cancel_url: `${config.baseUrl}/t/${encodeURIComponent(p.slug)}`,
      });
      res.redirect(303, session.url);
    } catch (err) {
      console.error('Stripe-Fehler', err);
      res.status(502).send(views.product(ctx, p, { error: 'Die Zahlung konnte nicht gestartet werden. Bitte versuch es später noch einmal.' }));
    }
  });

  app.get('/success', async (req, res) => {
    const ctx = res.locals.ctx;
    const sessionId = String(req.query.session_id || '');
    if (!sessionId || !stripe) return res.status(400).send(views.message(ctx, 'Ungültiger Link', 'Diese Bestellung wurde nicht gefunden.'));
    let order = repo.getOrderBySession(sessionId);
    if (!order) {
      try {
        order = fulfill(await stripe.checkout.sessions.retrieve(sessionId));
      } catch (err) {
        console.error('Fehler beim Abschließen der Bestellung', err);
        return res.status(400).send(views.message(ctx, 'Bestellung nicht gefunden',
          'Wir konnten diese Bestellung nicht finden. Falls du bezahlt hast, melde dich bitte über die Kontaktdaten im Impressum.'));
      }
    }
    if (!order) {
      return res.send(views.message(ctx, 'Zahlung wird verarbeitet',
        'Deine Zahlung ist noch nicht bestätigt. Lade diese Seite in ein paar Minuten neu.'));
    }
    res.send(views.success(ctx, order, repo.getProduct(order.product_id)));
  });

  app.get('/download/:token', (req, res, next) => {
    const ctx = res.locals.ctx;
    const order = repo.getOrderByToken(req.params.token);
    if (!order) return next();
    const p = repo.getProduct(order.product_id);
    if (!p || !p.file_name) {
      return res.status(410).send(views.message(ctx, 'Nicht verfügbar', 'Diese Datei ist nicht mehr verfügbar. Bitte melde dich bei uns.'));
    }
    const admin = auth.isAdmin(req);
    if (!admin && new Date(order.expires_at) < new Date()) {
      return res.status(410).send(views.message(ctx, 'Link abgelaufen', 'Dieser Download-Link ist abgelaufen. Bitte melde dich bei uns, wir helfen gern.'));
    }
    if (!admin && !repo.registerDownload(order.id, config.downloadLimit)) {
      return res.status(410).send(views.message(ctx, 'Download-Limit erreicht', 'Dieser Link wurde bereits zu oft verwendet. Bitte melde dich bei uns, wir helfen gern.'));
    }
    const filePath = path.join(config.filesDir, p.file_name);
    res.download(filePath, `${p.slug}${path.extname(p.file_name)}`);
  });

  app.get('/links', (req, res) => {
    res.send(views.lostLinks(res.locals.ctx));
  });

  app.post('/links', async (req, res) => {
    const ctx = res.locals.ctx;
    const email = String(req.body.email || '').trim();
    if (!EMAIL_RE.test(email)) return res.status(400).send(views.lostLinks(ctx, { error: 'Bitte gib eine gültige E-Mail-Adresse ein.', email }));
    if (!allowLinkRequest(req.ip)) return res.status(429).send(views.lostLinks(ctx, { error: 'Zu viele Anfragen. Bitte versuch es später noch einmal.', email }));
    const orders = repo.activeOrdersForEmail(email);
    if (orders.length && mailer.enabled) {
      try {
        await mailer.resend(orders[0].email, orders);
      } catch (err) {
        console.error('Link-Versand fehlgeschlagen', err);
      }
    }
    // Same answer whether or not orders exist, so nobody can probe which addresses bought something.
    res.send(views.message(ctx, 'E-Mail ist unterwegs',
      `Falls es zu ${email} gültige Käufe gibt, haben wir dir die Download-Links geschickt. Schau auch im Spam-Ordner nach.`));
  });

  const LEGAL_TITLES = { impressum: 'Impressum', datenschutz: 'Datenschutzerklärung', agb: 'AGB', widerruf: 'Widerrufsbelehrung' };
  for (const [key, title] of Object.entries(LEGAL_TITLES)) {
    app.get(`/${key}`, (req, res) => res.send(views.legalPage(res.locals.ctx, title, repo.getSetting(key))));
  }

  /* ---------- Admin ---------- */

  const upload = multer({
    storage: multer.diskStorage({
      destination: (req, file, cb) => cb(null, file.fieldname === 'cover' ? config.coversDir : config.filesDir),
      filename: (req, file, cb) => cb(null, randomToken(12) + path.extname(file.originalname).toLowerCase()),
    }),
    limits: { fileSize: 100 * 1024 * 1024, files: 2 },
    fileFilter: (req, file, cb) => {
      const ext = path.extname(file.originalname).toLowerCase();
      const allowed = file.fieldname === 'cover' ? COVER_EXTS : file.fieldname === 'file' ? FILE_EXTS : [];
      if (allowed.includes(ext)) return cb(null, true);
      cb(Object.assign(new Error(`Dateityp ${ext || '(ohne Endung)'} ist hier nicht erlaubt.`), { userFacing: true }));
    },
  }).fields([{ name: 'file', maxCount: 1 }, { name: 'cover', maxCount: 1 }]);

  function removeStored(dir, name) {
    if (name) fs.rm(path.join(dir, name), { force: true }, () => {});
  }

  function removeUploads(req) {
    for (const f of Object.values(req.files || {}).flat()) fs.rm(f.path, { force: true }, () => {});
  }

  app.get('/admin/login', (req, res) => {
    if (auth.isAdmin(req)) return res.redirect('/admin');
    res.send(views.adminLogin(res.locals.ctx, { configured: !!config.adminPassword }));
  });

  app.post('/admin/login', (req, res) => {
    if (!auth.checkPassword(req.body.password || '')) {
      return res.status(401).send(views.adminLogin(res.locals.ctx, { error: 'Falsches Passwort.', configured: !!config.adminPassword }));
    }
    auth.login(res);
    res.redirect('/admin');
  });

  app.post('/admin/logout', (req, res) => {
    auth.logout(res);
    res.redirect('/');
  });

  app.use('/admin', auth.requireAdmin);

  app.get('/admin', (req, res) => {
    res.send(views.adminDashboard(res.locals.ctx, repo.listProducts({ includeInactive: true }), repo.revenue()));
  });

  app.get('/admin/products/new', (req, res) => {
    res.send(views.adminProductForm(res.locals.ctx, { active: 1 }));
  });

  function handleProductSave(existing) {
    return (req, res) => {
      upload(req, res, (err) => {
        const ctx = res.locals.ctx;
        const b = req.body || {};
        const draft = {
          ...(existing || {}),
          title: String(b.title || '').trim(),
          subtitle: String(b.subtitle || '').trim(),
          description: String(b.description || ''),
          excerpt: String(b.excerpt || ''),
          slug: slugify(b.slug || b.title),
          price_cents: parsePrice(b.price),
          active: b.active === '1',
        };
        const fail = (message) => {
          removeUploads(req);
          res.status(400).send(views.adminProductForm(ctx, { ...draft, price_cents: draft.price_cents ?? existing?.price_cents }, { error: message }));
        };
        if (err) return fail(err.userFacing || err.code === 'LIMIT_FILE_SIZE' ? err.message : 'Upload fehlgeschlagen.');
        if (!draft.title) return fail('Bitte gib einen Titel ein.');
        if (draft.price_cents == null || draft.price_cents < 50) return fail('Bitte gib einen gültigen Preis ein (mindestens 0,50).');
        if (repo.slugExists(draft.slug, existing?.id || 0)) return fail(`Die Adresse „${draft.slug}“ ist schon vergeben.`);

        const file = req.files?.file?.[0];
        const coverFile = req.files?.cover?.[0];
        if (file) {
          draft.file_name = file.filename;
          draft.file_label = `${path.extname(file.originalname).slice(1).toUpperCase()}, ${(file.size / 1024 / 1024).toFixed(1).replace('.', ',')} MB`;
        }
        if (coverFile) draft.cover_name = coverFile.filename;

        if (existing) {
          repo.updateProduct(existing.id, draft);
          if (file) removeStored(config.filesDir, existing.file_name);
          if (coverFile) removeStored(config.coversDir, existing.cover_name);
          return res.redirect(`/admin/products/${existing.id}?msg=saved`);
        }
        const id = repo.createProduct(draft);
        res.redirect(`/admin/products/${id}?msg=created`);
      });
    };
  }

  app.post('/admin/products', handleProductSave(null));

  app.get('/admin/products/:id', (req, res, next) => {
    const p = repo.getProduct(Number(req.params.id));
    if (!p) return next();
    res.send(views.adminProductForm(res.locals.ctx, p));
  });

  app.post('/admin/products/:id', (req, res, next) => {
    const p = repo.getProduct(Number(req.params.id));
    if (!p) return next();
    handleProductSave(p)(req, res);
  });

  app.post('/admin/products/:id/delete', (req, res, next) => {
    const p = repo.getProduct(Number(req.params.id));
    if (!p) return next();
    if (repo.countOrdersForProduct(p.id) > 0) {
      repo.updateProduct(p.id, { ...p, active: false });
      return res.redirect('/admin?msg=hidden');
    }
    repo.deleteProduct(p.id);
    removeStored(config.filesDir, p.file_name);
    removeStored(config.coversDir, p.cover_name);
    res.redirect('/admin?msg=deleted');
  });

  app.get('/admin/orders', (req, res) => {
    res.send(views.adminOrders(res.locals.ctx, repo.listOrders()));
  });

  app.post('/admin/orders/:id/email', async (req, res, next) => {
    const order = repo.getOrder(Number(req.params.id));
    if (!order) return next();
    try {
      if (!order.email) throw new Error('Bestellung hat keine E-Mail-Adresse');
      await mailer.purchase(order, repo.getProduct(order.product_id));
      repo.markOrderEmail(order.id, true);
      res.redirect('/admin/orders?msg=mailed');
    } catch (err) {
      console.error(`E-Mail zu Bestellung ${order.id} fehlgeschlagen`, err);
      res.redirect('/admin/orders?msg=mailfail');
    }
  });

  app.get('/admin/legal', (req, res) => {
    res.send(views.adminLegal(res.locals.ctx));
  });

  app.post('/admin/legal', (req, res) => {
    for (const [key] of views.LEGAL_KEYS) {
      if (typeof req.body[key] === 'string') repo.setSetting(key, req.body[key]);
    }
    res.redirect('/admin/legal?msg=saved');
  });

  app.use((req, res) => {
    res.status(404).send(views.message(res.locals.ctx, 'Nicht gefunden', 'Diese Seite gibt es nicht.'));
  });

  app.use((err, req, res, next) => {
    console.error(err);
    res.status(500).send(views.message(res.locals.ctx || { config, repo }, 'Fehler', 'Da ist etwas schiefgelaufen.'));
  });

  return { app, fulfill };
}

if (require.main === module) {
  const config = loadConfig();
  const repo = openDb(config);
  const stripe = config.stripeSecretKey ? require('stripe')(config.stripeSecretKey) : null;
  if (!config.adminPassword) console.warn('Warnung: ADMIN_PASSWORD ist nicht gesetzt – /admin ist gesperrt.');
  if (!config.sessionSecret) console.warn('Warnung: SESSION_SECRET ist nicht gesetzt – Admin-Anmeldungen gelten nur bis zum Neustart.');
  if (!config.smtp || !config.mailFrom) console.warn('Warnung: SMTP_HOST/MAIL_FROM nicht gesetzt – Käufer bekommen keine E-Mail.');
  if (!stripe) console.warn('Warnung: STRIPE_SECRET_KEY ist nicht gesetzt – Käufe sind deaktiviert.');
  const { app } = createApp({ config, repo, stripe });
  app.listen(config.port, () => console.log(`${config.shopName} läuft auf ${config.baseUrl}`));
}

module.exports = { createApp };
