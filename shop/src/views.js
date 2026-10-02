const { esc, paragraphs, formatMoney } = require('./util');

function layout(ctx, { title, body, admin = false }) {
  const nav = admin
    ? `<a href="/admin">Texte</a><a href="/admin/orders">Bestellungen</a><a href="/admin/legal">Rechtliches</a>
       <form method="post" action="/admin/logout" class="inline"><button class="link">Abmelden</button></form>`
    : `<a href="/">Alle Texte</a>`;
  return `<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>${esc(title ? `${title} · ${ctx.config.shopName}` : ctx.config.shopName)}</title>
<link rel="stylesheet" href="/static/style.css">
</head>
<body>
<header class="site-header">
  <div class="wrap">
    <a class="brand" href="/">${esc(ctx.config.shopName)}</a>
    <nav>${nav}</nav>
  </div>
</header>
<main class="wrap">
${ctx.flash ? `<div class="flash">${esc(ctx.flash)}</div>` : ''}
${body}
</main>
<footer class="site-footer">
  <div class="wrap">
    <a href="/impressum">Impressum</a>
    <a href="/datenschutz">Datenschutz</a>
    <a href="/agb">AGB</a>
    <a href="/widerruf">Widerruf</a>
    ${ctx.mailEnabled ? '<a href="/links">Download-Link verloren?</a>' : ''}
  </div>
</footer>
</body>
</html>`;
}

function cover(product, cls = 'cover') {
  if (product.cover_name) {
    return `<img class="${cls}" src="/covers/${esc(product.cover_name)}" alt="">`;
  }
  return `<div class="${cls} cover-placeholder"><span>${esc(product.title)}</span></div>`;
}

function home(ctx, products) {
  const intro = ctx.repo.getSetting('intro', '');
  const cards = products.map((p) => `
    <a class="card" href="/t/${esc(p.slug)}">
      ${cover(p)}
      <div class="card-body">
        <h2>${esc(p.title)}</h2>
        ${p.subtitle ? `<p class="muted">${esc(p.subtitle)}</p>` : ''}
        <p class="price">${formatMoney(p.price_cents, ctx.config.currency)}</p>
      </div>
    </a>`).join('');
  return layout(ctx, {
    body: `
    ${intro ? `<section class="intro">${paragraphs(intro)}</section>` : ''}
    ${products.length
      ? `<section class="grid">${cards}</section>`
      : '<p class="empty">Hier erscheinen bald die ersten Texte.</p>'}`,
  });
}

function product(ctx, p, { error } = {}) {
  const canBuy = !!p.file_name && ctx.paymentsEnabled;
  return layout(ctx, {
    title: p.title,
    body: `
    <article class="product">
      <div class="product-cover">${cover(p, 'cover large')}</div>
      <div class="product-info">
        <h1>${esc(p.title)}</h1>
        ${p.subtitle ? `<p class="subtitle">${esc(p.subtitle)}</p>` : ''}
        <p class="price big">${formatMoney(p.price_cents, ctx.config.currency)}</p>
        <p class="muted small">inkl. MwSt. · Digitaler Download${p.file_label ? ` (${esc(p.file_label)})` : ''}</p>
        ${error ? `<div class="error">${esc(error)}</div>` : ''}
        ${canBuy ? `
        <form method="post" action="/buy/${esc(p.slug)}" class="buy">
          <label class="consent">
            <input type="checkbox" name="waiver" value="1" required>
            <span>Ich stimme ausdrücklich zu, dass mit der Bereitstellung des Downloads vor Ablauf der
            Widerrufsfrist begonnen wird. Mir ist bekannt, dass ich dadurch mein
            <a href="/widerruf" target="_blank">Widerrufsrecht</a> verliere.</span>
          </label>
          <button class="btn">Jetzt kaufen</button>
        </form>`
        : '<p class="muted">Dieser Text ist gerade nicht erhältlich.</p>'}
        <div class="description">${paragraphs(p.description)}</div>
      </div>
    </article>
    ${p.excerpt ? `
    <section class="excerpt">
      <h2>Leseprobe</h2>
      <div class="excerpt-text">${paragraphs(p.excerpt)}</div>
    </section>` : ''}`,
  });
}

function success(ctx, order, p) {
  const url = `${ctx.config.baseUrl}/download/${order.download_token}`;
  return layout(ctx, {
    title: 'Danke für deinen Kauf',
    body: `
    <section class="panel center">
      <h1>Danke für deinen Kauf!</h1>
      <p>„${esc(p ? p.title : 'Dein Text')}“ steht jetzt für dich bereit.</p>
      <p><a class="btn" href="/download/${esc(order.download_token)}">Jetzt herunterladen</a></p>
      <p class="muted small">Speichere dir diesen Link – er funktioniert bis zum
        ${esc(new Date(order.expires_at).toLocaleDateString('de-DE'))}
        und für insgesamt ${ctx.config.downloadLimit} Downloads:</p>
      <p><input class="copy" readonly value="${esc(url)}" onclick="this.select()"></p>
      ${order.email ? `<p class="muted small">${ctx.mailEnabled
        ? `Wir haben dir den Link außerdem per E-Mail an <strong>${esc(order.email)}</strong> geschickt.`
        : `Die Zahlungsbestätigung geht an ${esc(order.email)}.`}</p>` : ''}
    </section>`,
  });
}

function message(ctx, title, text, status = '') {
  return layout(ctx, {
    title,
    body: `<section class="panel center ${status}"><h1>${esc(title)}</h1>${paragraphs(text)}
      <p><a href="/">Zurück zum Shop</a></p></section>`,
  });
}

function lostLinks(ctx, { error, email = '' } = {}) {
  return layout(ctx, {
    title: 'Download-Link verloren?',
    body: `
    <section class="panel narrow">
      <h1>Download-Link verloren?</h1>
      <p class="muted">Gib die E-Mail-Adresse ein, die du beim Kauf verwendet hast. Wir schicken dir alle noch gültigen Links.</p>
      ${error ? `<div class="error">${esc(error)}</div>` : ''}
      <form method="post" action="/links" class="stack form">
        <label>E-Mail <input type="email" name="email" value="${esc(email)}" required autofocus></label>
        <button class="btn">Links zuschicken</button>
      </form>
    </section>`,
  });
}

function legalPage(ctx, title, text) {
  return layout(ctx, {
    title,
    body: `<article class="legal"><h1>${esc(title)}</h1>${text ? paragraphs(text) : '<p class="muted">Noch nicht hinterlegt.</p>'}</article>`,
  });
}

/* ---------- Admin ---------- */

function adminLogin(ctx, { error, configured }) {
  return layout(ctx, {
    title: 'Anmelden',
    body: `
    <section class="panel narrow">
      <h1>Verwaltung</h1>
      ${configured ? '' : '<div class="error">ADMIN_PASSWORD ist nicht gesetzt – Anmeldung ist deaktiviert.</div>'}
      ${error ? `<div class="error">${esc(error)}</div>` : ''}
      <form method="post" action="/admin/login" class="stack">
        <label>Passwort <input type="password" name="password" autofocus required></label>
        <button class="btn">Anmelden</button>
      </form>
    </section>`,
  });
}

function adminDashboard(ctx, products, revenue) {
  const totals = revenue.length
    ? revenue.map((r) => `${r.n} Verkäufe · ${formatMoney(r.total, r.currency)}`).join(' / ')
    : 'Noch keine Verkäufe';
  const warn = (ctx.paymentsEnabled ? ''
    : '<div class="error">STRIPE_SECRET_KEY ist nicht gesetzt – Kunden können noch nicht kaufen.</div>')
    + (ctx.mailEnabled ? ''
      : '<div class="error">E-Mail-Versand ist nicht eingerichtet (SMTP_HOST, MAIL_FROM) – Käufer bekommen ihren Link nur auf der Danke-Seite.</div>');
  const rows = products.map((p) => `
    <tr>
      <td><a href="/admin/products/${p.id}">${esc(p.title)}</a>${p.subtitle ? `<div class="muted small">${esc(p.subtitle)}</div>` : ''}</td>
      <td>${formatMoney(p.price_cents, ctx.config.currency)}</td>
      <td>${p.file_name ? esc(p.file_label || 'Datei') : '<span class="bad">keine Datei</span>'}</td>
      <td>${p.active ? '<span class="good">sichtbar</span>' : '<span class="muted">versteckt</span>'}</td>
      <td><a href="/t/${esc(p.slug)}" target="_blank">ansehen</a></td>
    </tr>`).join('');
  return layout(ctx, {
    admin: true,
    title: 'Verwaltung',
    body: `
    ${warn}
    <div class="toolbar">
      <h1>Deine Texte</h1>
      <a class="btn" href="/admin/products/new">+ Neuer Text</a>
    </div>
    <p class="muted">${totals}</p>
    ${products.length ? `
    <table>
      <thead><tr><th>Titel</th><th>Preis</th><th>Datei</th><th>Status</th><th></th></tr></thead>
      <tbody>${rows}</tbody>
    </table>` : '<p class="empty">Noch keine Texte. Leg deinen ersten an!</p>'}`,
  });
}

function adminProductForm(ctx, p, { error } = {}) {
  const isNew = !p.id;
  const price = p.price_cents != null ? (p.price_cents / 100).toFixed(2).replace('.', ',') : '';
  return layout(ctx, {
    admin: true,
    title: isNew ? 'Neuer Text' : p.title,
    body: `
    <div class="toolbar"><h1>${isNew ? 'Neuer Text' : 'Text bearbeiten'}</h1></div>
    ${error ? `<div class="error">${esc(error)}</div>` : ''}
    <form method="post" enctype="multipart/form-data"
          action="${isNew ? '/admin/products' : `/admin/products/${p.id}`}" class="stack form">
      <label>Titel* <input name="title" value="${esc(p.title)}" required maxlength="200"></label>
      <label>Untertitel / Gattung <input name="subtitle" value="${esc(p.subtitle)}" maxlength="200"
             placeholder="z.B. Kurzgeschichte, 24 Seiten"></label>
      <label>Adresse (URL-Teil) <input name="slug" value="${esc(p.slug)}" maxlength="80"
             placeholder="wird automatisch aus dem Titel erzeugt"></label>
      <label>Preis in ${esc(ctx.config.currency.toUpperCase())}* <input name="price" value="${esc(price)}" required
             inputmode="decimal" placeholder="4,99"></label>
      <label>Beschreibung <textarea name="description" rows="6">${esc(p.description)}</textarea></label>
      <label>Leseprobe (öffentlich sichtbar) <textarea name="excerpt" rows="10">${esc(p.excerpt)}</textarea></label>
      <label>Datei für Käufer (PDF, EPUB, MOBI, DOCX, TXT, ZIP)
        ${p.file_name ? `<span class="muted small">Aktuell: ${esc(p.file_label)} – nur auswählen, um sie zu ersetzen</span>` : ''}
        <input type="file" name="file" accept=".pdf,.epub,.mobi,.azw3,.docx,.odt,.txt,.rtf,.zip"></label>
      <label>Titelbild (JPG, PNG, WEBP)
        ${p.cover_name ? `<img class="thumb" src="/covers/${esc(p.cover_name)}" alt="">` : ''}
        <input type="file" name="cover" accept=".jpg,.jpeg,.png,.webp"></label>
      <label class="check"><input type="checkbox" name="active" value="1" ${p.active ? 'checked' : ''}> Im Shop anzeigen</label>
      <div class="row">
        <button class="btn">Speichern</button>
        <a href="/admin">Abbrechen</a>
      </div>
    </form>
    ${isNew ? '' : `
    <form method="post" action="/admin/products/${p.id}/delete" class="danger-zone"
          onsubmit="return confirm('Diesen Text wirklich löschen?')">
      <button class="btn danger">Text löschen</button>
      <span class="muted small">Texte mit Bestellungen können nur versteckt werden.</span>
    </form>`}`,
  });
}

function adminOrders(ctx, orders) {
  const rows = orders.map((o) => `
    <tr>
      <td>${esc(new Date(o.created_at + 'Z').toLocaleString('de-DE'))}</td>
      <td>${esc(o.product_title || `#${o.product_id}`)}</td>
      <td>${esc(o.email)}</td>
      <td>${formatMoney(o.amount_cents, o.currency)}</td>
      <td>${o.downloads} / ${ctx.config.downloadLimit}</td>
      <td>${o.email_sent_at && o.email_sent_at !== 'pending' ? '<span class="good">gesendet</span>'
        : o.email_sent_at === 'pending' ? '<span class="muted">wird gesendet</span>' : '<span class="bad">nicht gesendet</span>'}
        ${ctx.mailEnabled && o.email ? `<form method="post" action="/admin/orders/${o.id}/email" class="inline">
          <button class="link accent">erneut senden</button></form>` : ''}</td>
      <td><a href="/download/${esc(o.download_token)}" title="Download-Link für Kunden-Support">Link</a></td>
    </tr>`).join('');
  return layout(ctx, {
    admin: true,
    title: 'Bestellungen',
    body: `
    <div class="toolbar"><h1>Bestellungen</h1></div>
    ${orders.length ? `
    <table>
      <thead><tr><th>Datum</th><th>Text</th><th>E-Mail</th><th>Betrag</th><th>Downloads</th><th>E-Mail-Versand</th><th></th></tr></thead>
      <tbody>${rows}</tbody>
    </table>` : '<p class="empty">Noch keine Bestellungen.</p>'}`,
  });
}

const LEGAL_KEYS = [
  ['intro', 'Begrüßungstext auf der Startseite'],
  ['impressum', 'Impressum'],
  ['datenschutz', 'Datenschutzerklärung'],
  ['agb', 'AGB'],
  ['widerruf', 'Widerrufsbelehrung'],
];

function adminLegal(ctx) {
  const fields = LEGAL_KEYS.map(([key, label]) => `
    <label>${esc(label)} <textarea name="${key}" rows="${key === 'intro' ? 4 : 10}">${esc(ctx.repo.getSetting(key))}</textarea></label>`).join('');
  return layout(ctx, {
    admin: true,
    title: 'Rechtliches',
    body: `
    <div class="toolbar"><h1>Texte &amp; Rechtliches</h1></div>
    <p class="muted">Für einen Shop in Deutschland brauchst du mindestens Impressum, Datenschutzerklärung,
      AGB und Widerrufsbelehrung. Lass sie im Zweifel rechtlich prüfen.</p>
    <form method="post" action="/admin/legal" class="stack form">
      ${fields}
      <div class="row"><button class="btn">Speichern</button></div>
    </form>`,
  });
}

module.exports = {
  home, product, success, message, lostLinks, legalPage,
  adminLogin, adminDashboard, adminProductForm, adminOrders, adminLegal, LEGAL_KEYS,
};
