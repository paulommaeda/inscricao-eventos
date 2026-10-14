/* Actual shortcode, report SQL and authenticated endpoint; synthetic records only. */
const { chromium } = require('playwright');
const { spawn } = require('node:child_process');
const fs = require('node:fs'); const path = require('node:path'); const net = require('node:net');
const assert = require('node:assert/strict');
(async () => {
  const probe = net.createServer(); await new Promise(resolve => probe.listen(0, '127.0.0.1', resolve)); const port = probe.address().port; await new Promise(resolve => probe.close(resolve));
  const base = `http://127.0.0.1:${port}`;
  const server = spawn(process.env.IE_TEST_PHP || 'php', ['-d', 'error_reporting=E_ALL', '-d', 'display_errors=0', '-d', 'opcache.jit=0', '-d', 'opcache.jit_buffer_size=0', '-S', `127.0.0.1:${port}`, path.join(__dirname, 'dashboard-fixture.php')], { stdio: ['ignore', 'ignore', 'pipe'] });
  let logs = '', browser; server.stderr.on('data', value => { logs += value; });
  try {
    for (let i = 0; i < 50; i++) { try { await fetch(base); break; } catch { await new Promise(resolve => setTimeout(resolve, 100)); } }
    browser = await chromium.launch({ headless: true, ...(process.env.IE_TEST_CHROMIUM ? { executablePath: process.env.IE_TEST_CHROMIUM, args: ['--no-sandbox', '--disable-dev-shm-usage'] } : {}) });
    const visitor = await browser.newPage(); await visitor.goto(base);
    assert.match(await visitor.textContent('body'), /restrito a administradores/); assert.equal(await visitor.locator('.ied-metric').count(), 0);
    assert.equal((await visitor.request.get(base + '/api/dashboard?event=1')).status(), 403); await visitor.close();
    const context = await browser.newContext({ viewport: { width: 1440, height: 1080 }, acceptDownloads: true });
    await context.addCookies([{ name: 'ie_test_admin', value: '1', url: base }]);
    const page = await context.newPage(); const errors = []; page.on('pageerror', error => errors.push(error.message));
    const ready = async () => { await page.locator('.ie-dashboard[aria-busy="false"] .ied-metric').first().waitFor(); };
    const apply = async () => { await page.getByRole('button', { name: 'Aplicar filtros', exact: true }).click(); await ready(); };
    const count = async () => Number(await page.locator('.ied-metric').first().locator('strong').textContent());
    await page.goto(base); await ready(); assert.equal(await count(), 30); assert.match(await page.locator('.ied-header').textContent(), /120 dias/);
    assert.match(await page.locator('.ied-metric-received strong').textContent(), /8\.150,00/); assert.match(await page.locator('.ied-metric-due strong').textContent(), /6\.550,00/);
    assert.equal(await page.locator('.ied-registration-panel tbody tr').count(), 25);
    assert.equal(await page.locator('.ied-filters [name="event"]').isDisabled(), true);
    assert.equal((await page.request.get(base + '/api/dashboard?event=1')).status(), 403, 'Cookie without wp_rest nonce is denied');
    const detailsUrl = await page.locator('.ied-registration-panel tbody a').first().getAttribute('href'); assert.match(detailsUrl, /ie-dashboard&view=30/);
    if (process.env.IE_TEST_ARTIFACTS) { fs.mkdirSync(process.env.IE_TEST_ARTIFACTS, { recursive: true }); await page.screenshot({ path: path.join(process.env.IE_TEST_ARTIFACTS, 'dashboard-desktop.png'), fullPage: true }); }
    await page.getByRole('button', { name: 'Próxima', exact: true }).click(); await ready(); assert.equal(await page.locator('.ied-registration-panel tbody tr').count(), 5);
    await page.locator('.ied-filters [name="method"]').selectOption('Pix'); await apply(); assert.equal(await count(), 20);
    await page.locator('.ied-filters [name="lot"]').selectOption('Lote 1'); await apply(); assert.equal(await count(), 10);
    await page.getByRole('button', { name: 'Limpar', exact: true }).click(); await ready();
    await page.locator('.ied-filters [name="q"]').fill('529.982.247-25'); await apply(); assert.equal(await count(), 1); assert.match(await page.locator('.ied-registration-panel tbody').textContent(), /João da Silva/);
    const downloadWait = page.waitForEvent('download'); await page.getByRole('button', { name: 'Exportar CSV', exact: true }).click(); const download = await downloadWait;
    const csv = fs.readFileSync(await download.path(), 'utf8'); assert.match(csv, /João da Silva/); assert.equal(csv.trim().split('\n').length, 2); assert(!csv.includes('Participante 02'));
    await page.getByRole('button', { name: 'Limpar', exact: true }).click(); await ready();
    await page.locator('.ied-filters [name="registration_status"]').selectOption('all'); await apply(); assert.equal(await count(), 32);
    await page.locator('.ied-filters [name="all_scopes"]').check(); await apply(); assert.equal(await count(), 33);
    await page.getByRole('button', { name: 'Limpar', exact: true }).click(); await ready();
    await page.setViewportSize({ width: 390, height: 844 });
    assert(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), 'No horizontal page overflow on mobile');
    if (process.env.IE_TEST_ARTIFACTS) await page.screenshot({ path: path.join(process.env.IE_TEST_ARTIFACTS, 'dashboard-mobile.png'), fullPage: true });
    await page.locator('.ied-filters [name="status"]').selectOption('pending'); await apply(); assert.equal(await count(), 7);
    await page.route('**/api/dashboard?**', route => route.fulfill({ status: 500, contentType: 'application/json', body: '{"message":"Falha de teste no relatório"}' }));
    await page.getByRole('button', { name: 'Atualizar', exact: true }).click(); await page.getByText('Falha de teste no relatório', { exact: true }).waitFor();
    assert.equal(await count(), 7, 'Failure does not fabricate zero totals'); assert.equal(await page.getByRole('button', { name: 'Exportar CSV', exact: true }).isDisabled(), true);
    await page.unroute('**/api/dashboard?**'); await page.getByRole('button', { name: 'Atualizar', exact: true }).click(); await ready();
    await page.goto(base + '/all'); await ready(); assert.equal(await count(), 31);
    await page.locator('.ied-filters [name="event"]').selectOption('2'); await ready(); assert.equal(await count(), 1); assert.match(await page.locator('.ied-header').textContent(), /Segundo encontro/);
    await page.locator('.ied-filters [name="q"]').fill('inexistente'); await apply(); assert.equal(await count(), 0); assert.match(await page.locator('tbody').textContent(), /Nenhuma inscrição/);
    assert.deepEqual(errors, []); assert(!/Warning:|Fatal error:|Deprecated:/.test(logs), logs);
    console.log('OK: authenticated event dashboard, actual SQL totals, filters, pagination, CPF search, matching CSV, empty/error states and mobile layout.');
  } finally { if (browser) await browser.close(); server.kill(); }
})().catch(error => { console.error(error); process.exit(1); });
