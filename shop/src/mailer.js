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

  function linkBlock(order, titles) {
    const url = `${config.baseUrl}/download/${order.download_token}`;
    const until = new Date(order.expires_at).toLocaleDateString('de-DE');
    return `${titles.map((t) => `– „${t}“`).join('\n')}\n${url}\n(gültig bis ${until}, je Text ${config.downloadLimit} Downloads)`;
  }

  const subjectFor = (items) => (items.length === 1 ? items[0].title : `${items.length} Texte`);

  return {
    enabled,

    purchase(order, items) {
      const text = linkBlock(order, items.map((i) => i.title));
      return send({
        to: order.email,
        subject: `Dein Download: ${subjectFor(items)}`,
        text: [
          'Hallo,',
          '',
          `vielen Dank für deinen Kauf (${formatMoney(order.amount_cents, order.currency)})!`,
          items.length === 1 ? 'Hier ist dein persönlicher Download-Link:' : 'Unter diesem persönlichen Link findest du alle gekauften Texte:',
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
          ...orders.map((o) => `${linkBlock(o, (o.titles || 'Text').split(', '))}\n`),
          'Viel Freude beim Lesen!',
          config.shopName,
        ].join('\n'),
      });
    },

    ownerNotice(order, items) {
      if (!config.ownerEmail) return Promise.resolve();
      return send({
        to: config.ownerEmail,
        subject: `Neuer Verkauf: ${subjectFor(items)} (${formatMoney(order.amount_cents, order.currency)})`,
        text: `${order.email || 'Unbekannt'} hat gekauft:\n${items.map((i) => `– „${i.title}“`).join('\n')}\n\n${config.baseUrl}/admin/orders`,
      });
    },
  };
}

module.exports = { createMailer };
