/* Tests actual PHP admin handlers with synthetic data and mocked K8. */
const { chromium } = require('playwright');
const { spawn } = require('node:child_process');
const fs = require('node:fs'); const path = require('node:path'); const os = require('node:os'); const net = require('node:net');
const assert = require('node:assert/strict');
(async () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'ie-admin-test-'));
  const probe = net.createServer(); await new Promise(resolve => probe.listen(0, '127.0.0.1', resolve)); const port = probe.address().port; await new Promise(resolve => probe.close(resolve));
  const base = `http://127.0.0.1:${port}`; const server = spawn(process.env.IE_TEST_PHP || 'php', ['-d', 'error_reporting=E_ALL', '-d', 'display_errors=0', '-d', 'opcache.jit=0', '-d', 'opcache.jit_buffer_size=0', '-S', `127.0.0.1:${port}`, path.join(__dirname, 'admin-fixture.php')], { env: { ...process.env, IE_ADMIN_STATE: path.join(directory, 'state.json') }, stdio: ['ignore', 'ignore', 'pipe'] });
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
    assert.equal(await page.getByRole('button', { name: 'Excluir definitivamente', includeHidden: true }).count(), 1);
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
    // Managers can inspect records and export, including when they know an otherwise valid admin nonce.
    const managerContext = await browser.newContext({ viewport: { width: 390, height: 844 }, acceptDownloads: true });
    await managerContext.addCookies([{ name: 'ie_test_manager', value: '1', url: base }]);
    const manager = await managerContext.newPage(); await manager.goto(base + '/admin.php?view=1');
    assert.match(await manager.locator('.ie-readonly').textContent(), /somente consulta/);
    assert.equal(await manager.locator('form').count(), 0); assert.match(await manager.locator('h2').first().textContent(), /João Atualizado/);
    assert.equal((await manager.request.get(base + '/admin.php?edit_registration=1')).status(), 403);
    assert.equal((await manager.request.get(base + '/admin.php?page=ie-settings')).status(), 403);
    const manualForm = page.locator('.ie-manual-panel').filter({ has: page.locator('input[name="payment_id"][value="0"]') }).locator('form');
    const forged = await manager.request.post(base + '/admin-post.php', { form: { action: 'ie_manual_payment', registration_id: '1', payment_id: '0', amount: '50', method: 'Dinheiro', paid_on: '2026-10-14', note: 'Tentativa de gerente', confirmed: '1', request_token: 'a'.repeat(64), _wpnonce: await manualForm.locator('[name="_wpnonce"]').inputValue() } });
    assert.equal(forged.status(), 403); assert.equal(Object.keys((await state()).rows.wp_ie_manuals || {}).length, 0);
    await manager.goto(base + '/admin.php?page=ie-events'); assert.equal(await manager.getByRole('button', { name: /Salvar|Excluir|Duplicar/ }).count(), 0);
    await manager.goto(base + '/admin.php?view=0');
    const managerDownload = manager.waitForEvent('download'); await manager.getByRole('button', { name: 'Exportar inscrições filtradas (CSV)' }).click();
    assert.match(fs.readFileSync(await (await managerDownload).path(), 'utf8'), /Confirmado K8.*Baixa manual ativa/);
    if (process.env.IE_TEST_ARTIFACTS) { fs.mkdirSync(process.env.IE_TEST_ARTIFACTS, { recursive: true }); await manager.screenshot({ path: path.join(process.env.IE_TEST_ARTIFACTS, 'manager-mobile.png'), fullPage: true }); }
    await managerContext.close();
    // Real PHP receipt form and reversal; no K8 request or fake gateway status is produced.
    await page.locator('.ie-manual-panel').filter({ has: page.locator('input[name="payment_id"][value="0"]') }).locator('summary').click();
    await manualForm.locator('[name="amount"]').fill('50'); await manualForm.locator('[name="method"]').selectOption('Dinheiro'); await manualForm.locator('[name="note"]').fill('Recebimento em dinheiro conferido'); await manualForm.locator('[name="confirmed"]').check();
    await manualForm.getByRole('button', { name: 'Registrar baixa manual' }).click(); await page.waitForLoadState('networkidle');
    assert.match(await page.locator('.notice').textContent(), /Baixa manual #1/); data = await state(); assert.equal(data.rows.wp_ie_manuals[1].amount, 5000); assert.equal(data.rows.wp_ie_manuals[1].method, 'Dinheiro'); assert.equal(data.rows.wp_ie_transactions[1].credit, 0);
    if (process.env.IE_TEST_ARTIFACTS) await page.screenshot({ path: path.join(process.env.IE_TEST_ARTIFACTS, 'manual-mobile.png'), fullPage: true });
    await page.locator('.ie-manual-entry summary').click(); const reverseForm = page.locator('.ie-manual-entry form');
    await reverseForm.locator('[name="note"]').fill('Correção de lançamento'); await reverseForm.locator('[name="confirmed"]').check(); await reverseForm.getByRole('button', { name: 'Reverter baixa manual' }).click(); await page.waitForLoadState('networkidle');
    data = await state(); assert(data.rows.wp_ie_manuals[1].reversed_at); assert.equal(data.rows.wp_ie_transactions[1].credit, 0);
    if (process.env.IE_TEST_ARTIFACTS) { fs.mkdirSync(process.env.IE_TEST_ARTIFACTS, { recursive: true }); await page.screenshot({ path: path.join(process.env.IE_TEST_ARTIFACTS, 'admin-mobile.png'), fullPage: true }); }
    const callsBeforePurge = data.calls.length;
    await page.locator('.ie-danger summary').click();
    const purge = page.locator('form').filter({ has: page.locator('input[name="mode"][value="purge"]') });
    await purge.locator('[name="confirmation"]').fill('EXCLUIR 999'); await purge.getByRole('button', { name: 'Excluir definitivamente' }).click();
    assert.equal(await purge.locator('[name="confirmation"]').evaluate(el => el.validity.patternMismatch), true);
    assert((await state()).rows.wp_ie_registrations[1]);
    const wrong = await page.request.post(base + '/admin-post.php', { form: { action: 'ie_registration_action', mode: 'purge', registration_id: '1', confirmation: 'EXCLUIR 999', _wpnonce: await purge.locator('[name="_wpnonce"]').inputValue() } });
    assert.match(await wrong.text(), /Digite exatamente EXCLUIR 1/); assert((await state()).rows.wp_ie_registrations[1]);
    await purge.locator('[name="confirmation"]').fill('EXCLUIR 1'); await purge.getByRole('button', { name: 'Excluir definitivamente' }).click(); await page.waitForLoadState('networkidle');
    data = await state(); assert.equal(Object.keys(data.rows.wp_ie_registrations).length, 0); assert.equal(Object.keys(data.rows.wp_ie_payments).length, 0); assert.equal(Object.keys(data.rows.wp_ie_transactions).length, 0); assert.equal(Object.keys(data.rows.wp_ie_audit).length, 0); assert.equal(data.calls.length, callsBeforePurge);
    assert.equal(Object.keys(data.rows.wp_ie_manuals).length, 0);
    await page.goto(base + '/admin.php?page=ie-events&edit=1');
    const removeEvent = page.locator('form').filter({ has: page.locator('input[name="action"][value="ie_delete_event"]') });
    await removeEvent.locator('[name="confirmation"]').fill('EXCLUIR EVENTO 1'); await removeEvent.getByRole('button', { name: 'Excluir evento permanentemente' }).click(); await page.waitForLoadState('networkidle');
    data = await state(); assert.equal(Object.keys(data.rows.wp_ie_events).length, 0); assert.equal(Object.keys(data.rows.wp_ie_lots).length, 0); assert.equal(data.calls.length, callsBeforePurge);
    assert.deepEqual(errors, []); assert(!/Warning:|Fatal error:|Deprecated:/.test(logs), logs);
    console.log('OK: PHP admin edits, manual receipt/reversal, manager read-only view/export, forged writes denied, deletion, PIX refund, nonce and mobile layout.');
  } finally { if (browser) await browser.close(); server.kill(); fs.rmSync(directory, { recursive: true, force: true }); }
})().catch(error => { console.error(error); process.exit(1); });
