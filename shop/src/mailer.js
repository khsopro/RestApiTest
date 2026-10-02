const { formatMoney } = require('./util');

/**
 * Sends shop emails over SMTP. `transport` can be injected (tests); otherwise it is built
 * from config.smtp, and without SMTP settings the mailer is disabled.
 */
function createMailer(config, transport = null) {
  if (!transport && config.smtp) {
    transport = require('nodemailer').createTransport(config.smtp);
  }
  const enabled = !!transport && !!config.mailFrom;
  const from = `"${config.shopName.replace(/"/g, '')}" <${config.mailFrom}>`;

  async function send(message) {
    if (!enabled) throw new Error('E-Mail-Versand ist nicht konfiguriert');
    await transport.sendMail({ from, ...message });
  }

  function linkBlock(order, title) {
    const url = `${config.baseUrl}/download/${order.download_token}`;
    const until = new Date(order.expires_at).toLocaleDateString('de-DE');
    return { url, text: `„${title}“\n${url}\n(gültig bis ${until}, ${config.downloadLimit} Downloads)` };
  }

  return {
    enabled,

    purchase(order, product) {
      const { text } = linkBlock(order, product.title);
      return send({
        to: order.email,
        subject: `Dein Download: ${product.title}`,
        text: [
          'Hallo,',
          '',
          `vielen Dank für deinen Kauf (${formatMoney(order.amount_cents, order.currency)})!`,
          'Hier ist dein persönlicher Download-Link:',
          '',
          text,
          '',
          'Bitte gib den Link nicht weiter. Falls etwas nicht klappt, antworte einfach auf diese E-Mail.',
          '',
          'Viel Freude beim Lesen!',
          config.shopName,
          '',
          `Hinweis: Du hast beim Kauf zugestimmt, dass der Download sofort bereitgestellt wird, und damit dein Widerrufsrecht verloren. ${config.baseUrl}/widerruf`,
        ].join('\n'),
      });
    },

    resend(email, orders) {
      return send({
        to: email,
        subject: `Deine Download-Links bei ${config.shopName}`,
        text: [
          'Hallo,',
          '',
          'hier sind deine aktuell gültigen Download-Links:',
          '',
          ...orders.map((o) => `${linkBlock(o, o.product_title).text}\n`),
          'Viel Freude beim Lesen!',
          config.shopName,
        ].join('\n'),
      });
    },

    ownerNotice(order, product) {
      if (!config.ownerEmail) return Promise.resolve();
      return send({
        to: config.ownerEmail,
        subject: `Neuer Verkauf: ${product.title} (${formatMoney(order.amount_cents, order.currency)})`,
        text: `${order.email || 'Unbekannt'} hat „${product.title}“ gekauft.\n\n${config.baseUrl}/admin/orders`,
      });
    },
  };
}

module.exports = { createMailer };
