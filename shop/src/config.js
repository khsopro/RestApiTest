const path = require('node:path');

function int(value, fallback) {
  const n = Number.parseInt(value, 10);
  return Number.isFinite(n) && n > 0 ? n : fallback;
}

function loadConfig(env = process.env) {
  const dataDir = path.resolve(env.DATA_DIR || './data');
  return {
    shopName: env.SHOP_NAME || 'Meine Texte',
    baseUrl: (env.BASE_URL || `http://localhost:${env.PORT || 3000}`).replace(/\/+$/, ''),
    port: int(env.PORT, 3000),
    adminPassword: env.ADMIN_PASSWORD || '',
    sessionSecret: env.SESSION_SECRET || '',
    stripeSecretKey: env.STRIPE_SECRET_KEY || '',
    stripeWebhookSecret: env.STRIPE_WEBHOOK_SECRET || '',
    currency: (env.CURRENCY || 'eur').toLowerCase(),
    downloadLimit: int(env.DOWNLOAD_LIMIT, 5),
    downloadDays: int(env.DOWNLOAD_DAYS, 30),
    smtp: env.SMTP_HOST ? {
      host: env.SMTP_HOST,
      port: int(env.SMTP_PORT, 587),
      secure: env.SMTP_SECURE ? env.SMTP_SECURE === 'true' : int(env.SMTP_PORT, 587) === 465,
      auth: env.SMTP_USER ? { user: env.SMTP_USER, pass: env.SMTP_PASS || '' } : undefined,
    } : null,
    mailFrom: env.MAIL_FROM || env.SMTP_USER || '',
    ownerEmail: env.OWNER_EMAIL || '',
    dataDir,
    filesDir: path.join(dataDir, 'files'),
    coversDir: path.join(dataDir, 'covers'),
    dbFile: path.join(dataDir, 'shop.db'),
  };
}

module.exports = { loadConfig };
