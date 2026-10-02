const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');

const { loadConfig } = require('../src/config');
const { openDb } = require('../src/db');
const { createApp } = require('../src/server');
const { createMailer } = require('../src/mailer');
const { parsePrice, slugify } = require('../src/util');

let server, base, tmp, repo, cookie;
const created = [];
const mails = [];
const waitFor = async (cond) => { for (let i = 0; i < 50 && !cond(); i++) await new Promise((r) => setTimeout(r, 10)); };

const fakeStripe = {
  checkout: {
    sessions: {
      async create(params) {
        const id = `cs_test_${created.length + 1}`;
        created.push({ id, params });
        return { id, url: `https://checkout.example/${id}` };
      },
      async retrieve(id) {
        const s = created.find((c) => c.id === id);
        if (!s) throw new Error('No such session');
        return {
          id, payment_status: 'paid', amount_total: s.params.line_items[0].price_data.unit_amount,
          currency: 'eur', metadata: s.params.metadata, customer_details: { email: 'leser@example.com' },
        };
      },
    },
  },
  webhooks: { constructEvent() { throw new Error('bad signature'); } },
};

before(async () => {
  tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'textshop-'));
  const config = loadConfig({
    DATA_DIR: tmp, ADMIN_PASSWORD: 'geheim', SESSION_SECRET: 'x'.repeat(32),
    BASE_URL: 'http://localhost', DOWNLOAD_LIMIT: '2', STRIPE_WEBHOOK_SECRET: 'whsec_test',
    MAIL_FROM: 'shop@example.com', OWNER_EMAIL: 'autorin@example.com',
  });
  repo = openDb(config);
  const mailer = createMailer(config, { sendMail: async (m) => { mails.push(m); } });
  const { app } = createApp({ config, repo, stripe: fakeStripe, mailer });
  server = app.listen(0);
  await new Promise((r) => server.once('listening', r));
  base = `http://127.0.0.1:${server.address().port}`;
});

after(() => {
  server.close();
  repo.db.close();
  fs.rmSync(tmp, { recursive: true, force: true });
});

const form = (data) => new URLSearchParams(data);

test('price and slug helpers', () => {
  assert.equal(parsePrice('4,99'), 499);
  assert.equal(parsePrice('1.234,50 €'), 123450);
  assert.equal(parsePrice('5'), 500);
  assert.equal(parsePrice('abc'), null);
  assert.equal(slugify('Über Nacht: Gedichte'), 'ueber-nacht-gedichte');
});

test('admin is protected and rejects a wrong password', async () => {
  const r = await fetch(`${base}/admin`, { redirect: 'manual' });
  assert.equal(r.status, 302);
  assert.equal(r.headers.get('location'), '/admin/login');
  const bad = await fetch(`${base}/admin/login`, { method: 'POST', body: form({ password: 'falsch' }) });
  assert.equal(bad.status, 401);
});

async function createText(fields, fileName, content) {
  const fd = new FormData();
  for (const [k, v] of Object.entries(fields)) fd.set(k, v);
  fd.set('active', '1');
  fd.set('file', new Blob([content]), fileName);
  const r = await fetch(`${base}/admin/products`, { method: 'POST', body: fd, headers: { cookie }, redirect: 'manual' });
  assert.equal(r.status, 302);
}

/** Minimal cookie jar for the buyer's cart cookie. */
function buyer() {
  let cart = '';
  return async (url, opts = {}) => {
    const r = await fetch(`${base}${url}`, { redirect: 'manual', ...opts, headers: { ...(opts.headers || {}), cookie: cart } });
    for (const c of r.headers.getSetCookie()) if (c.startsWith('cart=')) cart = c.split(';')[0];
    return r;
  };
}

test('full purchase flow with two texts in one order', async () => {
  const login = await fetch(`${base}/admin/login`, { method: 'POST', body: form({ password: 'geheim' }), redirect: 'manual' });
  assert.equal(login.status, 302);
  cookie = login.headers.get('set-cookie').split(';')[0];

  const rejected = new FormData();
  rejected.set('title', 'Böse'); rejected.set('price', '3');
  rejected.set('file', new Blob(['x']), 'virus.exe');
  const rej = await fetch(`${base}/admin/products`, { method: 'POST', body: rejected, headers: { cookie } });
  assert.equal(rej.status, 400);
  assert.match(await rej.text(), /\.exe ist hier nicht erlaubt/);

  await createText({ title: 'Der stille Hafen', subtitle: 'Erzählung', price: '4,99',
    excerpt: 'Es war einmal <script>alert(1)</script>' }, 'hafen.pdf', '%PDF-1.4 Inhalt');
  await createText({ title: 'Winterlicht', price: '7,50' }, 'winter.epub', 'EPUB-Inhalt');

  const home = await (await fetch(base)).text();
  assert.match(home, /Der stille Hafen/);
  assert.match(home, /4,99/);

  const page = await (await fetch(`${base}/t/der-stille-hafen`)).text();
  assert.match(page, /Leseprobe/);
  assert.match(page, /In den Warenkorb/);
  assert.doesNotMatch(page, /<script>alert/);

  const b = buyer();
  assert.equal((await b('/cart/add/der-stille-hafen', { method: 'POST' })).headers.get('location'), '/cart');
  await b('/cart/add/winterlicht', { method: 'POST' });
  await b('/cart/add/winterlicht', { method: 'POST' }); // adding twice keeps it once
  const cartPage = await (await b('/cart')).text();
  assert.match(cartPage, /Der stille Hafen/);
  assert.match(cartPage, /Winterlicht/);
  assert.match(cartPage, /12,49/);
  assert.match(cartPage, /class="badge">2</);

  assert.equal((await b('/checkout', { method: 'POST', body: form({}) })).status, 400, 'waiver is required');

  const co = await b('/checkout', { method: 'POST', body: form({ waiver: '1' }) });
  assert.equal(co.status, 303);
  assert.equal(co.headers.get('location'), 'https://checkout.example/cs_test_1');
  assert.deepEqual(created[0].params.line_items.map((l) => l.price_data.unit_amount), [499, 750]);

  const okRes = await b('/success?session_id=cs_test_1');
  const ok = await okRes.text();
  assert.match(ok, /Danke für deinen Kauf/);
  assert.match(ok, /Der stille Hafen/);
  assert.match(ok, /Winterlicht/);
  assert.match(ok, /per E-Mail an <strong>leser@example.com/);
  assert.doesNotMatch(await (await b('/cart')).text(), /Winterlicht/, 'cart is emptied after purchase');
  const token = ok.match(/\/download\/([\w-]+)\//)[1];

  await waitFor(() => mails.length >= 2);
  const buyerMail = mails.find((m) => m.to === 'leser@example.com');
  assert.ok(buyerMail, 'buyer gets an email');
  assert.match(buyerMail.subject, /2 Texte/);
  assert.match(buyerMail.text, /Der stille Hafen[\s\S]*Winterlicht/);
  assert.ok(buyerMail.text.includes(`http://localhost/download/${token}`));
  assert.ok(mails.some((m) => m.to === 'autorin@example.com' && /Neuer Verkauf/.test(m.subject)));

  // Reloading the success page must not create a second order or email.
  await fetch(`${base}/success?session_id=cs_test_1`);
  assert.equal(repo.listOrders().length, 1);
  await new Promise((r) => setTimeout(r, 50));
  assert.equal(mails.filter((m) => m.to === 'leser@example.com').length, 1, 'email is sent only once');

  const list = await (await fetch(`${base}/download/${token}`)).text();
  assert.match(list, /Deine Downloads/);
  const [hafen, winter] = repo.getOrderItems(repo.listOrders()[0].id);

  const dl = await fetch(`${base}/download/${token}/${hafen.product_id}`);
  assert.equal(dl.status, 200);
  assert.equal(await dl.text(), '%PDF-1.4 Inhalt');
  assert.match(dl.headers.get('content-disposition'), /der-stille-hafen\.pdf/);
  assert.equal((await fetch(`${base}/download/${token}/${hafen.product_id}`)).status, 200);
  assert.equal((await fetch(`${base}/download/${token}/${hafen.product_id}`)).status, 410, 'limit of 2 downloads per text');
  const w = await fetch(`${base}/download/${token}/${winter.product_id}`);
  assert.equal(w.status, 200, 'other text has its own limit');
  assert.equal(await w.text(), 'EPUB-Inhalt');
  assert.equal((await fetch(`${base}/download/${token}/999`)).status, 404);
  assert.equal((await fetch(`${base}/download/nope`)).status, 404);

  const orders = await (await fetch(`${base}/admin/orders`, { headers: { cookie } })).text();
  assert.match(orders, /leser@example.com/);
  assert.match(orders, /Der stille Hafen, Winterlicht/);
  assert.match(orders, /gesendet/);

  mails.length = 0;
  const lost = await (await fetch(`${base}/links`, { method: 'POST', body: form({ email: 'LESER@example.com' }) })).text();
  assert.match(lost, /E-Mail ist unterwegs/);
  assert.equal(mails.length, 1);
  assert.ok(mails[0].text.includes(token));

  const unknown = await (await fetch(`${base}/links`, { method: 'POST', body: form({ email: 'niemand@example.com' }) })).text();
  assert.match(unknown, /E-Mail ist unterwegs/, 'same answer for unknown addresses');
  assert.equal(mails.length, 1);

  const order = repo.listOrders()[0];
  const resend = await fetch(`${base}/admin/orders/${order.id}/email`, { method: 'POST', headers: { cookie }, redirect: 'manual' });
  assert.equal(resend.headers.get('location'), '/admin/orders?msg=mailed');
  assert.equal(mails.length, 2);
});

test('orders from the single-text version still work', async () => {
  const p = repo.getProductBySlug('winterlicht');
  created.push({ id: 'cs_old', params: { line_items: [{ price_data: { unit_amount: 750 } }],
    metadata: { product_id: String(p.id), waiver_consent_at: 'x' } } });
  const ok = await (await fetch(`${base}/success?session_id=cs_old`)).text();
  assert.match(ok, /Winterlicht/);
  const token = ok.match(/\/download\/([\w-]+)\//)[1];
  assert.equal((await fetch(`${base}/download/${token}/${p.id}`)).status, 200);
});

test('webhook rejects invalid signatures', async () => {
  const r = await fetch(`${base}/webhook`, { method: 'POST', body: '{}', headers: { 'content-type': 'application/json' } });
  assert.equal(r.status, 400);
});
