const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');

const { loadConfig } = require('../src/config');
const { openDb } = require('../src/db');
const { createApp } = require('../src/server');
const { parsePrice, slugify } = require('../src/util');

let server, base, tmp, repo, cookie;
const created = [];

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
  });
  repo = openDb(config);
  const { app } = createApp({ config, repo, stripe: fakeStripe });
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

test('full purchase flow', async () => {
  const login = await fetch(`${base}/admin/login`, { method: 'POST', body: form({ password: 'geheim' }), redirect: 'manual' });
  assert.equal(login.status, 302);
  cookie = login.headers.get('set-cookie').split(';')[0];

  const rejected = new FormData();
  rejected.set('title', 'Böse'); rejected.set('price', '3');
  rejected.set('file', new Blob(['x']), 'virus.exe');
  const rej = await fetch(`${base}/admin/products`, { method: 'POST', body: rejected, headers: { cookie } });
  assert.equal(rej.status, 400);
  assert.match(await rej.text(), /\.exe ist hier nicht erlaubt/);

  const fd = new FormData();
  fd.set('title', 'Der stille Hafen');
  fd.set('subtitle', 'Erzählung');
  fd.set('price', '4,99');
  fd.set('excerpt', 'Es war einmal <script>alert(1)</script>');
  fd.set('active', '1');
  fd.set('file', new Blob(['%PDF-1.4 Inhalt']), 'hafen.pdf');
  const save = await fetch(`${base}/admin/products`, { method: 'POST', body: fd, headers: { cookie }, redirect: 'manual' });
  assert.equal(save.status, 302);

  const home = await (await fetch(base)).text();
  assert.match(home, /Der stille Hafen/);
  assert.match(home, /4,99/);

  const page = await (await fetch(`${base}/t/der-stille-hafen`)).text();
  assert.match(page, /Leseprobe/);
  assert.doesNotMatch(page, /<script>alert/);

  const noConsent = await fetch(`${base}/buy/der-stille-hafen`, { method: 'POST', body: form({}) });
  assert.equal(noConsent.status, 400);

  const buy = await fetch(`${base}/buy/der-stille-hafen`, { method: 'POST', body: form({ waiver: '1' }), redirect: 'manual' });
  assert.equal(buy.status, 303);
  assert.equal(buy.headers.get('location'), 'https://checkout.example/cs_test_1');
  assert.equal(created[0].params.line_items[0].price_data.unit_amount, 499);

  const ok = await (await fetch(`${base}/success?session_id=cs_test_1`)).text();
  assert.match(ok, /Danke für deinen Kauf/);
  const token = ok.match(/\/download\/([\w-]+)/)[1];

  // Reloading the success page must not create a second order.
  await fetch(`${base}/success?session_id=cs_test_1`);
  assert.equal(repo.listOrders().length, 1);

  const dl = await fetch(`${base}/download/${token}`);
  assert.equal(dl.status, 200);
  assert.equal(await dl.text(), '%PDF-1.4 Inhalt');
  assert.match(dl.headers.get('content-disposition'), /der-stille-hafen\.pdf/);
  assert.equal((await fetch(`${base}/download/${token}`)).status, 200);
  assert.equal((await fetch(`${base}/download/${token}`)).status, 410, 'limit of 2 downloads');
  assert.equal((await fetch(`${base}/download/nope`)).status, 404);

  const orders = await (await fetch(`${base}/admin/orders`, { headers: { cookie } })).text();
  assert.match(orders, /leser@example.com/);
});

test('webhook rejects invalid signatures', async () => {
  const r = await fetch(`${base}/webhook`, { method: 'POST', body: '{}', headers: { 'content-type': 'application/json' } });
  assert.equal(r.status, 400);
});
