/* Browser tests use an HTTP fixture and synthetic registrations; never contact K8. */
const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const plugin = path.resolve(__dirname, '..');
const event = {
  id: 1, name: 'Retiro Carnaval 2028', description: '<p>Um tempo para descansar, fortalecer a fé e viver momentos especiais em comunidade.</p>',
  event_date: '2028-02-29', registration_start: '2026-10-17', registration_end: '2027-01-30', payment_deadline: '2028-02-29',
  location: 'Local do encontro', logo_url: '', hero_url: '', primary_color: '#215c4a', accent_color: '#d2a45c', background_color: '#f6f5f0',
  payment_message: 'O saldo devedor deve ser quitado até o dia do evento: {data}.', minimum_entry: 100, allow_pix: true, allow_card: true,
  terms: '<p>Condições de teste do evento.</p>', privacy_url: '', active_lot: 1, open: true, notice: '',
  lots: [
    { id: 1, name: 'Lote 1', starts_on: '2026-10-17', ends_on: '2026-11-15', price: 49000, max_installments: 10, note: '' },
    { id: 2, name: 'Lote 2', starts_on: '2026-11-16', ends_on: '2026-12-31', price: 52000, max_installments: 8, note: 'Valor a confirmar' },
    { id: 3, name: 'Lote 3', starts_on: '2027-01-01', ends_on: '2027-01-30', price: 55000, max_installments: 6, note: '' }
  ]
};
let account = null, failPayment = false, calls = [], quote = event;
function makeAccount(method = 'Pix', paid = 10000) {
  return { id: 1, event_id: 1, event_name: event.name, name: 'João', lot: 'Lote 1', method, max_installments: 10,
    payment_methods: method === 'Pix' ? ['Pix', 'Cartao'] : ['Cartao'], card_installments: { base: 10, max: paid ? 7 : 10, months: paid ? 3 : 0, next_reduction: '2027-02-17' },
    total: 49000, paid, balance: 49000 - paid, overpaid: 0, status: paid === 49000 ? 'paid' : 'partial',
    deadline: event.payment_deadline, message: event.payment_message.replace('{data}', '29/02/2028'), can_pay: paid < 49000,
    payments: paid ? [{ id: 1, amount: paid, credit: paid, method, state: 'paid', created_at: '2026-10-20 12:00:00', url: '' }] : [] };
}
(async () => {
  const server = http.createServer(async (req, res) => {
    const url = new URL(req.url, 'http://localhost');
    if (url.pathname.startsWith('/api/')) {
      let body = ''; for await (const chunk of req) body += chunk;
      const input = body ? JSON.parse(body) : {};
      calls.push({ route: url.pathname, input, csrf: req.headers['x-ie-csrf'] });
      let data, status = 200;
      switch (url.pathname) {
        case '/api/bootstrap': data = { csrf: 'browser-test-csrf' }; break;
        case '/api/events': data = [quote]; break;
        case '/api/account': if (account) data = account; else { status = 400; data = { message: 'Consulte seu CPF.' }; } break;
        case '/api/lookup': account = makeAccount(); data = account; break;
        case '/api/register': account = makeAccount(input.method, 0); data = account; break;
        case '/api/payment':
          if (failPayment) { account.payments = [{ id: 4, amount: 10000, credit: 0, method: 'Pix', state: 'review', created_at: '2026-10-20 12:00:00', url: '' }]; status = 400; data = { message: 'Pagamento em conferência.' }; }
          else data = { payment_id: 3, url: 'https://sandbox.meuk8.com.br/PagamentosUI/checkout?id=test-only' };
          break;
        case '/api/refresh': data = account; break;
        case '/api/logout': account = null; data = { ok: true }; break;
        default: status = 404; data = { message: 'Unknown test endpoint' };
      }
      res.writeHead(status, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' }); res.end(JSON.stringify(data)); return;
    }
    if (['/public.css', '/public.js'].includes(url.pathname)) {
      res.setHeader('Content-Type', url.pathname.endsWith('.css') ? 'text/css' : 'application/javascript'); res.end(fs.readFileSync(path.join(plugin, 'assets', url.pathname.slice(1)))); return;
    }
    res.setHeader('Content-Type', 'text/html; charset=utf-8'); res.end('<!doctype html><html lang="pt-BR"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/public.css"><style>body{margin:0;padding:16px;background:#ecefe8}</style><div class="ie-app" data-event="1"></div><script>window.IE_CONFIG={api:"/api/",nonce:""}</script><script src="/public.js"></script></html>');
  });
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  const base = 'http://127.0.0.1:' + server.address().port;
  const browser = await chromium.launch({ headless: true, ...(process.env.IE_TEST_CHROMIUM ? { executablePath: process.env.IE_TEST_CHROMIUM, args: process.env.IE_TEST_CHROMIUM_ARGS_MODULE ? require(process.env.IE_TEST_CHROMIUM_ARGS_MODULE).default.args : ['--no-sandbox', '--disable-dev-shm-usage'] } : {}) });
  const page = await browser.newPage({ viewport: { width: 1366, height: 1000 } });
  const errors = []; page.on('pageerror', e => errors.push(e.message));
  await page.route('https://sandbox.meuk8.com.br/**', route => route.fulfill({ contentType: 'text/html', body: '<h1>Checkout K8 simulado</h1>' }));
  await page.goto(base); await page.locator('[data-form="register"]').waitFor();
  assert.equal(await page.locator('.ie-lot').count(), 3); assert.equal(await page.locator('.ie-lot-active').count(), 1);
  assert.match(await page.locator('.ie-lot-active').textContent(), /490/);
  const artifacts = process.env.IE_TEST_ARTIFACTS;
  if (artifacts) { fs.mkdirSync(artifacts, { recursive: true }); await page.screenshot({ path: path.join(artifacts, 'desktop.png'), fullPage: true }); }
  await page.setViewportSize({ width: 390, height: 844 });
  assert(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth));
  assert(await page.locator('.ie-lot').evaluateAll(cards => cards.every(card => card.querySelector('strong').getBoundingClientRect().right <= card.getBoundingClientRect().right)));
  if (artifacts) await page.screenshot({ path: path.join(artifacts, 'mobile.png'), fullPage: true });
  await page.locator('.ie-tabs [data-tab="lookup"]').click(); await page.locator('input[name="cpf"]').fill('52998224725');
  assert.equal(await page.locator('input[name="cpf"]').inputValue(), '529.982.247-25');
  await page.locator('button[type="submit"]').click(); await page.locator('.ie-account').waitFor();
  assert.match(await page.locator('.ie-balance-main').textContent(), /390/); assert.match(await page.locator('.ie-account-top').textContent(), /João/);
  await page.locator('[data-form="pay"] input[name="amount"]').fill('50.00'); await page.locator('[data-form="pay"] button').click();
  await page.waitForURL('https://sandbox.meuk8.com.br/**');
  assert.equal(calls.findLast(c => c.route === '/api/payment').input.amount, '50.00');
  assert.equal(calls.findLast(c => c.route === '/api/payment').input.method, 'Pix');
  assert.equal(calls.findLast(c => c.route === '/api/payment').csrf, 'browser-test-csrf');
  // A PIX deposit can finish on card at today's reduced limit, paying only the outstanding amount.
  account = makeAccount(); await page.goto(base); await page.locator('[data-form="pay"]').waitFor();
  await page.locator('[data-form="pay"] input[name="amount"]').fill('9999');
  await page.locator('[data-form="pay"] input[value="Cartao"]').check();
  assert(await page.locator('.ie-entry').isHidden()); assert.equal(await page.locator('[name="amount"]').getAttribute('required'), null);
  assert(await page.locator('[name="amount"]').isDisabled(), 'An invalid PIX amount cannot block card settlement');
  assert.match(await page.locator('.ie-card-terms').textContent(), /até 7x/); assert.match(await page.locator('.ie-card-terms').textContent(), /Limite original do lote: 10x/); assert.match(await page.locator('.ie-card-terms').textContent(), /17\/02\/2027/);
  assert(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth));
  if (artifacts) await page.screenshot({ path: path.join(artifacts, 'balance-card-mobile.png'), fullPage: true });
  await page.locator('[data-form="pay"] button').click(); await page.waitForURL('https://sandbox.meuk8.com.br/**');
  assert.deepEqual(calls.findLast(c => c.route === '/api/payment').input, { amount: '390.00', method: 'Cartao', expected_installments: 7 });
  await page.goto(base + '?ie_return=1&ie_event=1'); await page.locator('.ie-account').waitFor(); assert.equal(await page.locator('.ie-success').count(), 0);
  // A month change in the API updates the visible limit; 1x has no zero installment option.
  account.card_installments = { base: 10, max: 1, months: 12, next_reduction: '' };
  await page.locator('[data-action="refresh"]').click(); await page.locator('[data-form="pay"]').waitFor(); await page.locator('[data-form="pay"] input[value="Cartao"]').check();
  assert.match(await page.locator('.ie-card-terms').textContent(), /à vista \(1x\)/);
  // Card is absent from a PIX-only event's allowed methods.
  account.payment_methods = ['Pix']; await page.goto(base); await page.locator('[data-form="pay"]').waitFor(); assert.equal(await page.locator('[data-form="pay"] input[value="Cartao"]').count(), 0);
  // Card selection sends the entire frozen amount, and the entry input stops being required.
  account = null; await page.goto(base); await page.locator('[data-form="register"]').waitFor();
  await page.locator('input[name="name"]').fill('João Exemplo'); await page.locator('input[name="email"]').fill('joao@example.test');
  await page.locator('input[name="phone"]').fill('41999999999'); await page.locator('input[name="cpf"]').fill('52998224725');
  await page.locator('input[value="Cartao"]').check(); assert(await page.locator('.ie-entry').isHidden());
  await page.locator('input[name="accepted"]').check(); await page.locator('button[type="submit"]').click(); await page.waitForURL('https://sandbox.meuk8.com.br/**');
  assert.equal(calls.findLast(c => c.route === '/api/register').input.expected_price, 49000);
  assert.equal(calls.findLast(c => c.route === '/api/payment').input.amount, '490.00');
  // A return URL alone cannot cause the frontend to display settlement.
  account = makeAccount('Cartao', 0); await page.goto(base + '?ie_return=1&ie_event=1'); await page.locator('.ie-account').waitFor();
  assert.equal(await page.locator('.ie-success').count(), 0); assert.match(await page.locator('.ie-balance-main').textContent(), /490/);
  account = makeAccount('Cartao', 49000); await page.locator('[data-action="refresh"]').click(); await page.locator('.ie-success').waitFor();
  assert.match(await page.locator('.ie-success').textContent(), /quitada/);
  // Pending checkouts are resumed and prevent an additional amount form.
  account = makeAccount(); account.payments.unshift({ id: 4, amount: 5000, method: 'Pix', state: 'pending', created_at: '2026-10-20 12:00:00', url: 'https://sandbox.meuk8.com.br/PagamentosUI/checkout?id=test-only' });
  await page.goto(base); await page.locator('.ie-account').waitFor(); assert.equal(await page.locator('[data-form="pay"]').count(), 0);
  assert.match(await page.locator('.ie-payment-box').textContent(), /Continuar pagamento/);
  // An ambiguous create error leaves a review record, with no additional pay button.
  account = makeAccount(); failPayment = true; await page.goto(base); await page.locator('[data-form="pay"]').waitFor();
  await page.locator('[data-form="pay"] input[name="amount"]').fill('100'); await page.locator('[data-form="pay"] button').click();
  await page.waitForFunction(() => document.querySelector('.ie-alert')?.textContent.includes('conferência'));
  assert.equal(await page.locator('[data-form="pay"]').count(), 0); assert(await page.locator('[data-action="refresh"]').isEnabled());
  // Cancellation waits for K8 and hides payment actions even if the existing credit covers the total.
  account = makeAccount('Pix', 49000); account.payments[0].state = 'canceling'; await page.goto(base); await page.locator('.ie-account').waitFor();
  assert.equal(await page.locator('.ie-success').count(), 0); assert.equal(await page.locator('[data-form="pay"]').count(), 0); assert.match(await page.locator('.ie-account').textContent(), /Cancelamento \/ estorno/);
  for (const registration_status of ['cancelled', 'deleted']) {
    account = makeAccount(); account.registration_status = registration_status; account.payments.unshift({ id: 9, amount: 5000, state: 'pending', method: 'Pix', created_at: '2026-10-20 12:00:00', url: 'https://sandbox.meuk8.com.br/PagamentosUI/checkout?id=test-only' });
    await page.goto(base); await page.locator('.ie-account').waitFor(); assert.equal(await page.locator('[data-form="pay"]').count(), 0); assert.equal(await page.locator('.ie-account a[href*="meuk8"]').count(), 0); assert.match(await page.locator('.ie-account').textContent(), registration_status === 'deleted' ? /Inscrição excluída/ : /Inscrição cancelada/);
  }
  // Unknown dates keep registrations closed while the CPF consultation remains available.
  account = null; quote = { ...event, active_lot: null, open: false, event_date: '', notice: 'Prévia: defina a data do evento.' };
  await page.goto(base); await page.locator('.ie-notice').waitFor(); assert.equal(await page.locator('[data-form="register"]').count(), 0);
  await page.locator('.ie-tabs [data-tab="lookup"]').click(); assert.equal(await page.locator('[data-form="lookup"]').count(), 1);
  assert.deepEqual(errors, []);
  await browser.close(); server.close();
  console.log('OK: desktop/mobile, CPF lookup, PIX deposit + reduced card balance, minimum 1x, method restrictions, return without approval, settlement, pending reuse and no JS errors.');
})().catch(error => { console.error(error); process.exit(1); });
