(() => {
  'use strict';
  const money = value => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value / 100);
  const date = value => value ? value.split('-').reverse().join('/') : 'a definir';
  const esc = value => String(value ?? '').replace(/[&<>"']/g, x => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[x]);
  const cfg = window.IE_CONFIG || {};

  document.querySelectorAll('.ie-app').forEach(root => {
    const state = { events: [], event: null, csrf: '', account: null, tab: 'register', error: '', busy: false, polls: 0 };
    let timer;
    async function api(path, body) {
      const headers = { 'Content-Type': 'application/json' };
      if (cfg.nonce) headers['X-WP-Nonce'] = cfg.nonce;
      if (state.csrf) headers['X-IE-CSRF'] = state.csrf;
      const result = await fetch(cfg.api + path, { method: body === undefined ? 'GET' : 'POST', credentials: 'same-origin', cache: 'no-store', headers, ...(body === undefined ? {} : { body: JSON.stringify(body) }) });
      let data;
      try { data = await result.json(); } catch (_) { throw new Error('O servidor não respondeu como esperado. Recarregue a página.'); }
      if (!result.ok) throw new Error(data.message || 'Não foi possível concluir. Tente novamente.');
      return data;
    }
    function alert(message) {
      state.error = message;
      const el = root.querySelector('.ie-alert');
      if (el) { el.textContent = message; el.hidden = !message; if (message) el.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); }
    }
    function busy(value) {
      state.busy = value; root.setAttribute('aria-busy', String(value));
      root.querySelectorAll('button[type="submit"],button[data-action="refresh"]').forEach(b => { b.disabled = value; });
      const loader = root.querySelector('.ie-progress'); if (loader) loader.hidden = !value;
    }
    function deadline(e) { return esc((e.payment_message || 'O saldo devedor deve ser quitado até o dia do evento: {data}.').replaceAll('{data}', date(e.payment_deadline))); }
    function selectedLot() { return state.event.lots.find(l => l.id === state.event.active_lot); }

    function registerForm(e) {
      const lot = selectedLot();
      if (!lot) return `<div class="ie-empty"><h3>Um encontro para viver juntos</h3><p>${esc(e.notice)}</p><button type="button" class="ie-btn ie-secondary" data-tab="lookup">Consultar minha inscrição</button></div>`;
      return `<form data-form="register" class="ie-form"><div class="ie-form-heading"><span class="ie-eyebrow">${esc(lot.name)} · inscrições abertas</span><h2>Garanta sua participação</h2><p>Preencha seus dados e escolha como pagar.</p></div>
        <div class="ie-fields"><label>Nome completo<input name="name" autocomplete="name" maxlength="200" minlength="3" required></label><label>E-mail<input type="email" name="email" autocomplete="email" maxlength="254" required></label><label>Telefone com DDD<input name="phone" type="tel" inputmode="tel" autocomplete="tel" maxlength="20" placeholder="(41) 99999-9999" required></label><label>CPF<input name="cpf" inputmode="numeric" autocomplete="off" maxlength="14" placeholder="000.000.000-00" data-cpf required></label></div>
        <fieldset class="ie-methods"><legend>Como deseja pagar?</legend>${e.allow_card ? `<label class="ie-method"><input type="radio" name="method" value="Cartao" ${!e.allow_pix ? 'checked' : ''}><span><b>Cartão de crédito</b><small>Até ${lot.max_installments}x no checkout K8</small></span></label>` : ''}${e.allow_pix ? `<label class="ie-method"><input type="radio" name="method" value="Pix" checked><span><b>Entrada + PIX mensais</b><small>Você escolhe o valor de cada pagamento</small></span></label>` : ''}</fieldset>
        <div class="ie-entry" ${!e.allow_pix ? 'hidden' : ''}><label>Quanto deseja pagar de entrada? (R$)<input name="amount" type="number" inputmode="decimal" min="${(e.minimum_entry / 100).toFixed(2)}" max="${(lot.price / 100).toFixed(2)}" step="0.01" placeholder="Ex.: 100,00" ${e.allow_pix ? 'required' : ''}></label><small>Entrada mínima: ${money(e.minimum_entry)}. Você também pode pagar o valor completo por PIX.</small></div>
        <div class="ie-deadline">${deadline(e)}</div><label class="ie-accept"><input type="checkbox" name="accepted" required><span>Li e concordo com as condições da inscrição e com o prazo de quitação.</span></label>
        ${e.terms ? `<details class="ie-terms"><summary>Ler condições de inscrição</summary><div>${e.terms}</div></details>` : ''}${e.privacy_url ? `<a class="ie-privacy" href="${esc(e.privacy_url)}" target="_blank" rel="noopener">Política de privacidade</a>` : ''}
        <div class="ie-order"><span>Valor da inscrição · ${esc(lot.name)}</span><strong>${money(lot.price)}</strong></div><button type="submit" class="ie-btn">Continuar para pagamento <span aria-hidden="true">→</span></button></form>`;
    }
    function lookupForm() {
      return `<form data-form="lookup" class="ie-form ie-lookup"><span class="ie-eyebrow">Minha inscrição</span><h2>Continue de onde parou</h2><p>Informe seu CPF para consultar o saldo e fazer um novo pagamento.</p><label>CPF<input name="cpf" inputmode="numeric" autocomplete="off" maxlength="14" placeholder="000.000.000-00" data-cpf required></label><button type="submit" class="ie-btn">Consultar inscrição <span aria-hidden="true">→</span></button></form>`;
    }
    function accountView(a) {
      const outstanding = a.payments.find(p => ['pending', 'review', 'creating'].includes(p.state));
      const labels = { pending: 'Aguardando confirmação', creating: 'Preparando pagamento', review: 'Em conferência', paid: 'Confirmado', closed: 'Encerrado' };
      let pay = '';
      if (a.balance === 0) pay = '<div class="ie-success"><b>Inscrição quitada!</b><p>Seus pagamentos foram confirmados. Esperamos você no evento.</p></div>';
      else if (outstanding) pay = outstanding.url ? `<div class="ie-payment-box"><h3>Há um pagamento em andamento</h3><p>Conclua o pagamento de ${money(outstanding.amount)} ou atualize após pagar.</p><a class="ie-btn" href="${esc(outstanding.url)}">Continuar pagamento no K8 →</a></div>` : '<div class="ie-deadline"><b>Pagamento em conferência</b><p>Procure a organização antes de gerar outra tentativa.</p></div>';
      else if (a.can_pay) pay = `<form data-form="pay" class="ie-payment-box"><h3>${a.method === 'Pix' ? 'Quanto deseja pagar agora?' : 'Concluir pagamento no cartão'}</h3>${a.method === 'Pix' ? `<p>Escolha qualquer valor até o saldo devedor.</p><label>Valor do PIX (R$)<input name="amount" type="number" inputmode="decimal" min="0.01" max="${(a.balance / 100).toFixed(2)}" step="0.01" required placeholder="Ex.: 100,00"></label>` : `<p>Saldo total de ${money(a.balance)}, em até ${a.max_installments}x no K8.</p><input type="hidden" name="amount" value="${(a.balance / 100).toFixed(2)}">`}<button type="submit" class="ie-btn">Pagar agora →</button></form>`;
      else pay = '<div class="ie-deadline"><b>Novos pagamentos indisponíveis</b><p>Procure a organização para orientar a quitação.</p></div>';
      return `<div class="ie-account"><div class="ie-account-top"><div><span class="ie-eyebrow">${esc(a.lot)} · Inscrição #${a.id}</span><h2>Olá, ${esc(a.name)}.</h2></div><button type="button" class="ie-text-btn" data-action="logout">Sair</button></div><div class="ie-balances"><div><span>Total da inscrição</span><b>${money(a.total)}</b></div><div><span>Já confirmado</span><b>${money(a.paid)}</b></div><div class="ie-balance-main"><span>Saldo a pagar</span><b>${money(a.balance)}</b></div></div><div class="ie-deadline">${esc(a.message)}</div>${a.overpaid ? `<div class="ie-deadline">Há ${money(a.overpaid)} de pagamento excedente. Entre em contato com a organização.</div>` : ''}${pay}<div class="ie-history-heading"><h3>Seus pagamentos</h3><button type="button" class="ie-text-btn" data-action="refresh">↻ Atualizar saldo</button></div><p class="ie-hint">O saldo é atualizado após a confirmação pelo K8. O retorno ao site pode ocorrer antes dessa confirmação.</p><div class="ie-history">${a.payments.length ? a.payments.map(p => `<article><div><b>${p.method === 'Pix' ? 'PIX' : 'Cartão'} · ${money(p.amount)}</b><small>${date(p.created_at.split(' ')[0])}</small></div><span class="ie-status ie-status-${esc(p.state)}">${esc(labels[p.state] || p.state)}</span></article>`).join('') : '<p>Aguardando seu primeiro pagamento.</p>'}</div></div>`;
    }

    function render() {
      const e = state.event;
      if (!e) { root.innerHTML = '<div class="ie-empty"><h2>Evento em preparação</h2><p>A organização está finalizando as informações de inscrição.</p></div>'; return; }
      root.style.setProperty('--ie-primary', e.primary_color); root.style.setProperty('--ie-accent', e.accent_color); root.style.setProperty('--ie-bg', e.background_color);
      root.innerHTML = `<div class="ie-shell"><header class="ie-hero ${e.hero_url ? 'ie-has-cover' : ''}">${e.hero_url ? `<img class="ie-cover" src="${esc(e.hero_url)}" alt="">` : ''}<div class="ie-hero-content">${e.logo_url ? `<img class="ie-logo" src="${esc(e.logo_url)}" alt="Logo do evento">` : '<span class="ie-eyebrow">Um encontro especial</span>'}<h1>${esc(e.name)}</h1><div class="ie-description">${e.description}</div><div class="ie-event-meta">${e.event_date ? `<span>◷ ${date(e.event_date)}</span>` : ''}${e.location ? `<span>⌖ ${esc(e.location)}</span>` : ''}</div></div></header>
        ${state.events.length > 1 && !Number(root.dataset.event) ? `<label class="ie-event-picker">Escolha seu evento<select data-events>${state.events.map(item => `<option value="${item.id}" ${item.id === e.id ? 'selected' : ''}>${esc(item.name)}</option>`).join('')}</select></label>` : ''}
        <div class="ie-body"><div class="ie-lots">${e.lots.map(l => `<article class="ie-lot ${l.id === e.active_lot ? 'ie-lot-active' : ''}"><div><b>${esc(l.name)}</b>${l.id === e.active_lot ? '<span class="ie-lot-tag">Atual</span>' : ''}</div><strong>${money(l.price)}</strong><small>${date(l.starts_on)} a ${date(l.ends_on)}</small><small>${e.allow_card ? `Cartão até ${l.max_installments}x` : 'Pagamento por PIX'}</small>${l.note ? `<small class="ie-lot-note">${esc(l.note)}</small>` : ''}</article>`).join('')}</div>
        ${e.notice ? `<div class="ie-notice">${esc(e.notice)}</div>` : ''}
        <div class="ie-content"><div class="ie-alert" role="alert" ${!state.error ? 'hidden' : ''}>${esc(state.error)}</div><div class="ie-progress" role="status" hidden>Processando… aguarde.</div>${state.account ? accountView(state.account) : `<nav class="ie-tabs" aria-label="Inscrições"><button type="button" data-tab="register" aria-pressed="${state.tab === 'register'}" class="${state.tab === 'register' ? 'active' : ''}">Fazer inscrição</button><button type="button" data-tab="lookup" aria-pressed="${state.tab === 'lookup'}" class="${state.tab === 'lookup' ? 'active' : ''}">Minha inscrição</button></nav>${state.tab === 'lookup' ? lookupForm() : registerForm(e)}`}</div><footer class="ie-footer">Inscrições de ${date(e.registration_start)} a ${date(e.registration_end)}<br>${deadline(e)}</footer></div></div>`;
      schedulePoll();
    }
    function schedulePoll() {
      clearTimeout(timer);
      if (!state.account || !state.account.payments.some(p => p.state === 'pending') || state.polls >= 20) return;
      timer = setTimeout(async () => {
        if (document.hidden || state.busy) { schedulePoll(); return; }
        state.polls++;
        try { state.account = await api('refresh', {}); render(); } catch (_) { schedulePoll(); }
      }, 30000);
    }
    root.addEventListener('input', event => {
      const field = event.target;
      if (field.matches('[data-cpf]')) { const n = field.value.replace(/\D/g, '').slice(0, 11); field.value = n.replace(/^(\d{3})(\d)/, '$1.$2').replace(/^(\d{3})\.(\d{3})(\d)/, '$1.$2.$3').replace(/(\d{3})\.(\d{3})\.(\d{3})(\d)/, '$1.$2.$3-$4'); }
    });
    root.addEventListener('change', event => {
      if (event.target.name === 'method') {
        const entry = root.querySelector('.ie-entry'); const input = entry?.querySelector('input');
        if (entry) entry.hidden = event.target.value !== 'Pix'; if (input) input.required = event.target.value === 'Pix';
      }
      if (event.target.matches('[data-events]')) { state.event = state.events.find(e => e.id === Number(event.target.value)); state.account = null; state.error = ''; render(); }
    });
    root.addEventListener('click', async event => {
      const button = event.target.closest('[data-tab],[data-action]'); if (!button || state.busy) return;
      if (button.dataset.tab) { state.tab = button.dataset.tab; state.error = ''; render(); return; }
      alert(''); busy(true);
      try {
        if (button.dataset.action === 'logout') { await api('logout', {}); state.account = null; state.tab = 'lookup'; }
        if (button.dataset.action === 'refresh') state.account = await api('refresh', {});
        render();
      } catch (e) { alert(e.message); } finally { busy(false); }
    });
    root.addEventListener('submit', async event => {
      const form = event.target.closest('[data-form]'); if (!form) return; event.preventDefault();
      if (state.busy || !form.reportValidity()) return;
      const values = new FormData(form); const kind = form.dataset.form; let amount = values.get('amount');
      alert(''); busy(true);
      try {
        if (kind === 'lookup') { state.account = await api('lookup', { event_id: state.event.id, cpf: values.get('cpf') }); render(); return; }
        if (kind === 'register') {
          const lot = selectedLot(); const method = values.get('method');
          state.account = await api('register', { event_id: state.event.id, lot_id: lot.id, expected_price: lot.price, name: values.get('name'), email: values.get('email'), phone: values.get('phone'), cpf: values.get('cpf'), method, accepted_terms: values.get('accepted') === 'on' });
          if (method === 'Cartao') amount = (state.account.balance / 100).toFixed(2);
        }
        const payment = await api('payment', { amount });
        window.location.assign(payment.url);
      } catch (e) {
        if (state.account) { try { state.account = await api('account', {}); } catch (_) {} render(); }
        alert(e.message);
      } finally { busy(false); }
    });
    (async () => {
      try {
        state.csrf = (await api('bootstrap')).csrf;
        const id = Number(root.dataset.event) || 0;
        state.events = await api('events' + (id ? '?event=' + id : ''));
        const returning = new URLSearchParams(window.location.search); const returnEvent = Number(returning.get('ie_event'));
        state.event = state.events.find(e => e.id === returnEvent) || state.events[0] || null;
        try {
          const account = await api('account', {});
          if (account.event_id === state.event?.id) state.account = returning.has('ie_return') ? await api('refresh', {}) : account;
        } catch (_) { /* No active CPF session yet. */ }
        render();
      } catch (e) { root.innerHTML = `<div class="ie-empty" role="alert"><h2>Não foi possível carregar</h2><p>${esc(e.message)}</p><button class="ie-btn" type="button" onclick="window.location.reload()">Tentar novamente</button></div>`; }
    })();
  });
})();
