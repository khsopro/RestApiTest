const crypto = require('node:crypto');
const { safeEqual } = require('./util');

const COOKIE = 'shop_admin';
const SESSION_HOURS = 12;

function sign(value, secret) {
  return crypto.createHmac('sha256', secret).update(value).digest('base64url');
}

function readCookie(req, name) {
  const header = req.headers.cookie || '';
  for (const part of header.split(';')) {
    const [k, ...v] = part.trim().split('=');
    if (k === name) return decodeURIComponent(v.join('='));
  }
  return null;
}

function createAuth(config) {
  const secret = config.sessionSecret || crypto.randomBytes(32).toString('hex');
  const secure = config.baseUrl.startsWith('https://');

  function isAdmin(req) {
    const raw = readCookie(req, COOKIE);
    if (!raw) return false;
    const [expires, sig] = raw.split('.');
    if (!expires || !sig || !safeEqual(sig, sign(expires, secret))) return false;
    return Number(expires) > Date.now();
  }

  function login(res) {
    const expires = String(Date.now() + SESSION_HOURS * 3600 * 1000);
    const value = `${expires}.${sign(expires, secret)}`;
    res.setHeader('Set-Cookie',
      `${COOKIE}=${value}; Path=/; HttpOnly; SameSite=Strict; Max-Age=${SESSION_HOURS * 3600}${secure ? '; Secure' : ''}`);
  }

  function logout(res) {
    res.setHeader('Set-Cookie', `${COOKIE}=; Path=/; HttpOnly; SameSite=Strict; Max-Age=0`);
  }

  function checkPassword(password) {
    return !!config.adminPassword && safeEqual(password, config.adminPassword);
  }

  function requireAdmin(req, res, next) {
    if (isAdmin(req)) return next();
    res.redirect('/admin/login');
  }

  return { isAdmin, login, logout, checkPassword, requireAdmin };
}

module.exports = { createAuth };
