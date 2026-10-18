/* Actual PHP admin workflow with a failed K8 sync; all K8 traffic is mocked. */
const { chromium } = require('playwright');
const { spawn } = require('node:child_process');
const fs = require('node:fs'); const path = require('node:path'); const os = require('node:os'); const net = require('node:net');
const assert = require('node:assert/strict');
(async () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'ie-k8-manual-'));
  const probe = net.createServer(); await new Promise(resolve => probe.listen(0, '127.0.0.1', resolve)); const port = probe.address().port; await new Promise(resolve => probe.close(resolve));
  const base = `http://127.0.0.1:${port}`;
  const server = spawn(process.env.IE_TEST_PHP || 'php', ['-d', 'error_reporting=E_ALL', '-d', 'log_errors=1', '-d', 'display_errors=0', '-d', 'opcache.jit=0', '-d', 'opcache.jit_buffer_size=0', '-S', `127.0.0.1:${port}`, path.join(__dirname, 'admin-fixture.php')], { env: { ...process.env, IE_ADMIN_STATE: path.join(directory, 'state.json'), IE_ADMIN_K8_MANUAL: '1' }, stdio: ['ignore', 'ignore', 'pipe'] });
  let logs = ''; server.stderr.on('data', data => { logs += data; }); let browser;
  try {
    for (let i = 0; i < 50; i++) { try { await fetch(base); break; } catch { await new Promise(resolve => setTimeout(resolve, 100)); } }
    browser = await chromium.launch({ headless: true, ...(process.env.IE_TEST_CHROMIUM ? { executablePath: process.env.IE_TEST_CHROMIUM, args: process.env.IE_TEST_CHROMIUM_ARGS_MODULE ? require(process.env.IE_TEST_CHROMIUM_ARGS_MODULE).default.args : ['--no-sandbox', '--disable-dev-shm-usage'] } : {}) });
    const page = await browser.newPage({ viewport: { width: 1366, height: 1000 } }); const errors = []; page.on('pageerror', e => errors.push(e.message)); page.on('dialog', dialog => dialog.accept());
    const state = async () => (await (await fetch(base + '/_state')).json());
    const k8 = page.locator('#ie-k8-manual-1');
    await page.goto(base + '/admin.php?view=1');
    assert(await k8.evaluate(el => el.open)); assert(await k8.getByRole('button', { name: 'Confirmar baixa da transação K8' }).isVisible());
    assert.match(await k8.textContent(), /sem depender de uma consulta K8/);
    assert.equal(await k8.locator('[name="remote_id"]').inputValue(), 'ui-transaction');
    if (process.env.IE_TEST_ARTIFACTS) { fs.mkdirSync(process.env.IE_TEST_ARTIFACTS, { recursive: true }); await k8.screenshot({ path: path.join(process.env.IE_TEST_ARTIFACTS, 'k8-manual-form-desktop.png') }); }
    const receipt = k8.locator('form').filter({ has: page.locator('[name="action"][value="ie_manual_payment"]') });
    await receipt.locator('[name="note"]').fill('PIX recebido no K8; conferido comprovante'); await receipt.locator('[name="confirmed"]').check();
    const nonce = await receipt.locator('[name="_wpnonce"]').inputValue();
    const managerContext = await browser.newContext(); await managerContext.addCookies([{ name: 'ie_test_manager', value: '1', url: base }]); const manager = await managerContext.newPage();
    const forged = await manager.request.post(base + '/admin-post.php', { form: { action: 'ie_manual_payment', registration_id: '1', payment_id: '1', amount: '50', paid_on: '2026-10-15', remote_id: 'ui-transaction', note: 'Tentativa de gerente', confirmed: '1', request_token: 'a'.repeat(64), _wpnonce: nonce } });
    assert.equal(forged.status(), 403); await manager.goto(base + '/admin.php?view=1'); assert.equal(await manager.locator('.ie-k8-manual form').count(), 0); await managerContext.close();
    await receipt.getByRole('button', { name: 'Confirmar baixa da transação K8' }).click(); await page.waitForLoadState('networkidle');
    let data = await state(); assert.equal(data.calls.length, 0); assert.equal(data.rows.wp_ie_manuals[1].payment_id, 1); assert.equal(data.rows.wp_ie_manuals[1].remote_id, 'ui-transaction'); assert.equal(data.rows.wp_ie_payments[1].state, 'review'); assert.equal(Object.keys(data.rows.wp_ie_transactions || {}).length, 0);
    assert.match(await page.locator('.ie-k8-manual-status').textContent(), /Baixada manualmente/);
    await page.setViewportSize({ width: 390, height: 844 }); assert(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth));
    if (process.env.IE_TEST_ARTIFACTS) await page.locator('.ie-k8-manual-status').screenshot({ path: path.join(process.env.IE_TEST_ARTIFACTS, 'k8-manual-status-mobile.png') });
    const entry = page.locator('.ie-manual-entry').first(); await entry.locator('summary').click(); await entry.locator('[name="note"]').fill('Vincular a baixa já existente em outro lançamento'); await entry.locator('[name="confirmed"]').check(); await entry.getByRole('button', { name: 'Reverter baixa manual' }).click(); await page.waitForLoadState('networkidle');
    // Existing external PIX receipt can be attached to this K8 charge, with no second credit.
    const offline = page.locator('.ie-manual-panel').filter({ has: page.locator('[name="payment_id"][value="0"]') }); await offline.locator('summary').click();
    await offline.locator('[name="amount"]').fill('50'); await offline.locator('[name="method"]').selectOption('Pix'); await offline.locator('[name="note"]').fill('PIX K8 lançado antes de vincular'); await offline.locator('[name="confirmed"]').check(); await offline.getByRole('button', { name: 'Registrar baixa manual' }).click(); await page.waitForLoadState('networkidle');
    const link = k8.locator('form').filter({ has: page.locator('[name="action"][value="ie_link_manual_k8"]') }); assert(await link.isVisible());
    await link.locator('[name="note"]').fill('Mesmo pagamento K8 já lançado manualmente'); await link.locator('[name="confirmed"]').check();
    const invalid = await page.request.post(base + '/admin-post.php', { form: { action: 'ie_link_manual_k8', registration_id: '1', payment_id: '1', manual_id: '2', note: 'Mesmo pagamento K8', confirmed: '1', _wpnonce: 'invalid' } }); assert.equal(invalid.status(), 403);
    if (process.env.IE_TEST_ARTIFACTS) await k8.screenshot({ path: path.join(process.env.IE_TEST_ARTIFACTS, 'k8-link-existing-mobile.png') });
    await link.getByRole('button', { name: 'Vincular baixa existente ao K8' }).click(); await page.waitForLoadState('networkidle');
    assert.match(await page.locator('.notice').textContent(), /sem novo crédito/); data = await state(); assert.equal(data.rows.wp_ie_manuals[2].payment_id, 1); assert.equal(data.calls.length, 0); assert.equal(Object.keys(data.rows.wp_ie_manuals).length, 2);
    const sync = page.locator('form').filter({ has: page.locator('[name="action"][value="ie_sync_payment"]') }); await sync.getByRole('button', { name: 'Consultar e conciliar no K8' }).click(); await page.waitForLoadState('networkidle');
    data = await state(); assert.equal(data.rows.wp_ie_transactions[1].credit, 5000); assert.equal(data.rows.wp_ie_manuals[2].resolved_state, 'Aprovada'); assert.equal(data.rows.wp_ie_payments[1].state, 'paid'); assert.equal(await page.locator('.ie-k8-manual-status').count(), 0);
    assert.match(await page.locator('.ie-manual-unavailable').textContent(), /resultado final conferido/); assert.match(await page.locator('.ie-form-grid').first().textContent(), /R\$\s*440,00/);
    assert(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)); assert.deepEqual(errors, []); assert(!/Warning:|Fatal error:|Deprecated:/.test(logs), logs);
    console.log('OK: K8 manual fallback during outage, existing receipt link, late sync without double credit, admin permissions and mobile layout.');
  } finally { if (browser) await browser.close(); server.kill(); fs.rmSync(directory, { recursive: true, force: true }); }
})().catch(error => { console.error(error); process.exit(1); });
