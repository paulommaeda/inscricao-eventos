/* Tests actual PHP admin handlers with synthetic data and mocked K8. */
const { chromium } = require('playwright');
const { spawn } = require('node:child_process');
const fs = require('node:fs'); const path = require('node:path'); const os = require('node:os'); const net = require('node:net');
const assert = require('node:assert/strict');
(async () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'ie-admin-test-'));
  const probe = net.createServer(); await new Promise(resolve => probe.listen(0, '127.0.0.1', resolve)); const port = probe.address().port; await new Promise(resolve => probe.close(resolve));
  const base = `http://127.0.0.1:${port}`; const server = spawn(process.env.IE_TEST_PHP || 'php', ['-S', `127.0.0.1:${port}`, path.join(__dirname, 'admin-fixture.php')], { env: { ...process.env, IE_ADMIN_STATE: path.join(directory, 'state.json') }, stdio: ['ignore', 'ignore', 'pipe'] });
  let logs = ''; server.stderr.on('data', data => { logs += data; }); let browser;
  try {
    for (let i = 0; i < 50; i++) { try { await fetch(base); break; } catch { await new Promise(resolve => setTimeout(resolve, 100)); } }
    browser = await chromium.launch({ headless: true, ...(process.env.IE_TEST_CHROMIUM ? { executablePath: process.env.IE_TEST_CHROMIUM, args: process.env.IE_TEST_CHROMIUM_ARGS_MODULE ? require(process.env.IE_TEST_CHROMIUM_ARGS_MODULE).default.args : ['--no-sandbox', '--disable-dev-shm-usage'] } : {}) });
    const page = await browser.newPage({ viewport: { width: 1366, height: 1050 } }); const errors = []; page.on('pageerror', e => errors.push(e.message));
    page.on('dialog', dialog => dialog.accept());
    const state = async () => (await (await fetch(base + '/_state')).json());
    await page.goto(base + '/admin.php?view=1'); await page.getByRole('link', { name: 'Editar inscrição', exact: true }).click();
    await page.locator('[name="name"]').fill('João Atualizado'); await page.locator('[name="email"]').fill('JOAO.NOVO@EXAMPLE.TEST'); await page.locator('[name="phone"]').fill('41988887777'); await page.locator('[name="cpf"]').fill('11144477735');
    await page.getByRole('button', { name: 'Salvar inscrição' }).click(); await page.waitForURL('**view=1');
    assert.match(await page.locator('h2').first().textContent(), /João Atualizado/);
    let data = await state(); assert.equal(data.rows.wp_ie_registrations[1].email, 'joao.novo@example.test'); assert.equal(data.rows.wp_ie_registrations[1].total, 49000);
    await page.getByRole('button', { name: 'Excluir inscrição', exact: true }).click(); await page.getByRole('button', { name: 'Restaurar inscrição' }).waitFor();
    data = await state(); assert.equal(data.rows.wp_ie_registrations[1].status, 'deleted'); assert.equal(data.rows.wp_ie_transactions[1].credit, 5000);
    assert.equal(await page.getByRole('button', { name: 'Excluir definitivamente' }).count(), 0);
    await page.getByRole('button', { name: 'Restaurar inscrição' }).click(); await page.getByRole('button', { name: 'Excluir inscrição', exact: true }).waitFor();
    await page.locator('.ie-diagnostic summary').click(); assert.match(await page.locator('.ie-diagnostic').textContent(), /Confere/); assert(!await page.locator('.ie-diagnostic').textContent().then(s => s.includes('52998224725')));
    await page.setViewportSize({ width: 390, height: 844 }); await page.locator('.ie-cancel-panel summary').click();
    assert(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth));
    const form = page.locator('form').filter({ has: page.locator('input[name="action"][value="ie_cancel_payment"]') });
    await form.locator('[name="operation"]').selectOption('refund'); await form.locator('[name="reason"]').fill('Solicitado pelo participante');
    await form.getByRole('button', { name: 'Enviar solicitação ao K8' }).click(); await page.waitForLoadState('networkidle');
    assert.match(await page.locator('.notice').textContent(), /Confirme/); data = await state(); assert.equal(data.calls.length, 0);
    await page.locator('.ie-cancel-panel summary').click(); await form.locator('[name="operation"]').selectOption('refund'); await form.locator('[name="reason"]').fill('Solicitado pelo participante'); await form.locator('[name="confirmed"]').check();
    await form.getByRole('button', { name: 'Enviar solicitação ao K8' }).click(); await page.waitForLoadState('networkidle');
    assert.match(await page.locator('.notice').textContent(), /confirmado/); data = await state(); assert.equal(data.rows.wp_ie_transactions[1].credit, 0); assert.equal(data.rows.wp_ie_transactions[1].cancel_status, 'confirmed');
    assert.equal(data.calls.filter(x => x[0].endsWith('/api/EstornaTransacaoPIx')).length, 1);
    const badNonce = await page.request.post(base + '/admin-post.php', { form: { action: 'ie_cancel_payment', registration_id: '1', payment_id: '1', remote_id: 'ui-transaction', operation: 'refund', reason: 'Repeated', confirmed: '1', _wpnonce: 'invalid' } });
    assert.equal(badNonce.status(), 403); assert.equal((await state()).calls.length, data.calls.length);
    if (process.env.IE_TEST_ARTIFACTS) { fs.mkdirSync(process.env.IE_TEST_ARTIFACTS, { recursive: true }); await page.screenshot({ path: path.join(process.env.IE_TEST_ARTIFACTS, 'admin-mobile.png'), fullPage: true }); }
    assert.deepEqual(errors, []); assert(!/Warning:|Fatal error:|Deprecated:/.test(logs));
    console.log('OK: PHP admin edit, trash/restore, history, diagnosis, confirmation, PIX refund, nonce and mobile layout.');
  } finally { if (browser) await browser.close(); server.kill(); fs.rmSync(directory, { recursive: true, force: true }); }
})().catch(error => { console.error(error); process.exit(1); });
