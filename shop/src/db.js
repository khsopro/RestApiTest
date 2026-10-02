const fs = require('node:fs');
const { DatabaseSync } = require('node:sqlite');

const SCHEMA = `
CREATE TABLE IF NOT EXISTS products (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  slug        TEXT NOT NULL UNIQUE,
  title       TEXT NOT NULL,
  subtitle    TEXT NOT NULL DEFAULT '',
  description TEXT NOT NULL DEFAULT '',
  excerpt     TEXT NOT NULL DEFAULT '',
  price_cents INTEGER NOT NULL,
  file_name   TEXT,
  file_label  TEXT,
  cover_name  TEXT,
  active      INTEGER NOT NULL DEFAULT 1,
  created_at  TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE TABLE IF NOT EXISTS orders (
  id                INTEGER PRIMARY KEY AUTOINCREMENT,
  stripe_session_id TEXT NOT NULL UNIQUE,
  product_id        INTEGER NOT NULL REFERENCES products(id),
  email             TEXT NOT NULL DEFAULT '',
  amount_cents      INTEGER NOT NULL,
  currency          TEXT NOT NULL,
  download_token    TEXT NOT NULL UNIQUE,
  downloads         INTEGER NOT NULL DEFAULT 0,
  waiver_consent_at TEXT,
  expires_at        TEXT NOT NULL,
  created_at        TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE TABLE IF NOT EXISTS settings (
  key   TEXT PRIMARY KEY,
  value TEXT NOT NULL
);
`;

function openDb(config) {
  for (const dir of [config.dataDir, config.filesDir, config.coversDir]) {
    fs.mkdirSync(dir, { recursive: true });
  }
  const db = new DatabaseSync(config.dbFile);
  db.exec('PRAGMA journal_mode = WAL; PRAGMA foreign_keys = ON;');
  db.exec(SCHEMA);
  return createRepo(db);
}

function createRepo(db) {
  const q = (sql) => db.prepare(sql);
  return {
    db,

    listProducts({ includeInactive = false } = {}) {
      return includeInactive
        ? q('SELECT * FROM products ORDER BY created_at DESC, id DESC').all()
        : q('SELECT * FROM products WHERE active = 1 ORDER BY created_at DESC, id DESC').all();
    },
    getProduct(id) {
      return q('SELECT * FROM products WHERE id = ?').get(id);
    },
    getProductBySlug(slug) {
      return q('SELECT * FROM products WHERE slug = ?').get(slug);
    },
    slugExists(slug, exceptId = 0) {
      return !!q('SELECT 1 FROM products WHERE slug = ? AND id != ?').get(slug, exceptId);
    },
    createProduct(p) {
      const r = q(`INSERT INTO products (slug, title, subtitle, description, excerpt, price_cents, file_name, file_label, cover_name, active)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`)
        .run(p.slug, p.title, p.subtitle, p.description, p.excerpt, p.price_cents,
          p.file_name ?? null, p.file_label ?? null, p.cover_name ?? null, p.active ? 1 : 0);
      return Number(r.lastInsertRowid);
    },
    updateProduct(id, p) {
      q(`UPDATE products SET slug = ?, title = ?, subtitle = ?, description = ?, excerpt = ?, price_cents = ?,
           file_name = ?, file_label = ?, cover_name = ?, active = ? WHERE id = ?`)
        .run(p.slug, p.title, p.subtitle, p.description, p.excerpt, p.price_cents,
          p.file_name ?? null, p.file_label ?? null, p.cover_name ?? null, p.active ? 1 : 0, id);
    },
    deleteProduct(id) {
      q('DELETE FROM products WHERE id = ?').run(id);
    },
    countOrdersForProduct(id) {
      return q('SELECT COUNT(*) AS n FROM orders WHERE product_id = ?').get(id).n;
    },

    getOrderBySession(sessionId) {
      return q('SELECT * FROM orders WHERE stripe_session_id = ?').get(sessionId);
    },
    getOrderByToken(token) {
      return q('SELECT * FROM orders WHERE download_token = ?').get(token);
    },
    /** Inserts the order unless one already exists for this Stripe session; returns the stored order. */
    createOrderOnce(o) {
      q(`INSERT INTO orders (stripe_session_id, product_id, email, amount_cents, currency, download_token, waiver_consent_at, expires_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON CONFLICT(stripe_session_id) DO NOTHING`)
        .run(o.stripe_session_id, o.product_id, o.email, o.amount_cents, o.currency,
          o.download_token, o.waiver_consent_at ?? null, o.expires_at);
      return this.getOrderBySession(o.stripe_session_id);
    },
    /** Atomically counts a download; returns false when the limit is already reached. */
    registerDownload(orderId, limit) {
      const r = q('UPDATE orders SET downloads = downloads + 1 WHERE id = ? AND downloads < ?').run(orderId, limit);
      return r.changes === 1;
    },
    listOrders(limit = 200) {
      return q(`SELECT o.*, p.title AS product_title FROM orders o
                LEFT JOIN products p ON p.id = o.product_id
                ORDER BY o.created_at DESC, o.id DESC LIMIT ?`).all(limit);
    },
    revenue() {
      return q('SELECT currency, COUNT(*) AS n, SUM(amount_cents) AS total FROM orders GROUP BY currency').all();
    },

    getSetting(key, fallback = '') {
      const row = q('SELECT value FROM settings WHERE key = ?').get(key);
      return row ? row.value : fallback;
    },
    setSetting(key, value) {
      q('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value').run(key, value);
    },
  };
}

module.exports = { openDb };
