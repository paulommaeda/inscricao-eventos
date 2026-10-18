(() => {
  'use strict';
  const config = window.IE_DASHBOARD;
  if (!config) return;
  const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const number = new Intl.NumberFormat('pt-BR');
  const money = value => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(value || 0) / 100);
  const day = value => value ? value.split('-').reverse().join('/') : 'A definir';
  const labels = { Dinheiro: 'Dinheiro', Transferencia: 'Transferência', Outro: 'Outro', pending: 'Sem pagamento', partial: 'Parcial', paid: 'Quitada', active: 'Ativa', cancelled: 'Cancelada', deleted: 'Lixeira', Pix: 'PIX', Cartao: 'Cartão', CartaoPOS: 'Cartão físico (maquininha)' };
  const options = (items, selected) => items.map(([v, text]) => `<option value="${esc(v)}" ${String(v) === String(selected) ? 'selected' : ''}>${esc(text)}</option>`).join('');
  const countdown = days => days === null ? 'A definir' : days < 0 ? `Há ${Math.abs(days)} dias` : days === 0 ? 'Hoje' : `${days} dias`;
  const metric = (label, value, note, cls = '') => `<article class="ied-metric ${cls}"><span>${esc(label)}</span><strong>${esc(value)}</strong><small>${esc(note)}</small></article>`;
  const percent = (part, total) => total ? Math.min(100, Math.max(0, 100 * part / total)) : 0;
  const progress = (value, label) => `<div class="ied-progress" role="progressbar" aria-label="${esc(label)}" aria-valuenow="${value.toFixed(1)}" aria-valuemin="0" aria-valuemax="100"><span style="width:${value}%"></span></div>`;
  const filterField = (label, html, cls = '') => `<label class="ied-field ${cls}"><span>${esc(label)}</span>${html}</label>`;

  document.querySelectorAll('.ie-dashboard').forEach(root => {
    const fixedEvent = Number(root.dataset.event || 0);
    let controller, applied = { event: fixedEvent, page: 1 }, data;

    function render(result) {
      data = result;
      const f = result.filters, m = result.metrics, e = result.event, c = result.calendar;
      const primary = /^#[a-f\d]{6}$/i.test(e?.primary_color || '') ? e.primary_color : '#215c4a';
      root.style.setProperty('--ied-brand', primary); root.classList.toggle('ie-origens', e?.brand_preset === 'origens');
      const title = e?.name || 'Todos os eventos';
      const stamp = new Intl.DateTimeFormat('pt-BR', { hour: '2-digit', minute: '2-digit', second: '2-digit' }).format(new Date(result.updated_at));
      const eventItems = [[0, 'Todos os eventos'], ...result.events.map(item => [item.id, item.name])];
      const lotItems = [['', 'Todos os lotes'], ...result.lot_options.map(name => [name, name])];
      const warnings = [
        [m.review_attempts, 'Tentativas em conferência', 'Confira a cobrança nos detalhes da inscrição.'],
        [m.cancel_attempts, 'Cancelamentos / estornos em andamento', 'O saldo aguarda o estado final confirmado pelo K8.'],
        [m.error_attempts, 'Tentativas com erro registrado', 'Abra o diagnóstico da inscrição para conferir.'],
        [m.overpaid_count, 'Inscrições com pagamento excedente', `Excedente total: ${money(m.overpaid)}.`]
      ].filter(([count]) => count > 0);
      if (c?.deadline_days !== null && c?.deadline_days < 0 && m.balance > 0) warnings.unshift([m.pending + m.partial, 'Inscrições com saldo após o prazo', `Prazo de quitação: ${day(c.deadline)}.`]);
      const statuses = [[m.paid_count, 'Quitadas', 'paid'], [m.partial, 'Parciais', 'partial'], [m.pending, 'Sem pagamento', 'pending']];
      const paidAngle = percent(m.paid_count, m.count) * 3.6;
      const partialAngle = paidAngle + percent(m.partial, m.count) * 3.6;
      const maxDay = Math.max(1, ...result.timeline.map(item => item.count));
      const hiddenExport = Object.entries(f).map(([key, value]) => `<input type="hidden" name="${esc(key)}" value="${esc(value)}">`).join('');
      root.innerHTML = `
        ${e?.brand_preset === 'origens' ? window.IEBrand.cover(e, c?.active_lot) : ''}
        <header class="ied-header">
          <div><span class="ied-eyebrow">PAINEL DA ORGANIZAÇÃO</span><h2>${esc(title)}</h2>
            <p>${e ? `${esc(day(e.event_date))}${e.location ? ` <span aria-hidden="true">·</span> ${esc(e.location)}` : ''}` : 'Inscrições e financeiro em um só lugar'}</p>
            <div class="ied-header-tags"><span>${esc(result.scope_notice)}</span>${e ? `<span>${esc({ draft: 'Rascunho', published: 'Publicado', archived: 'Arquivado' }[e.status] || e.status)}</span>` : ''}</div>
          </div>
          <div class="ied-countdown"><small>${c?.days < 0 ? 'Evento realizado' : 'Até o evento'}</small><strong>${esc(c ? countdown(c.days) : 'Selecione um evento')}</strong><span>${c ? `Quitação: ${esc(day(c.deadline))}` : 'Veja datas e prazos por evento'}</span></div>
        </header>
        <div class="ied-toolbar"><span class="ied-stamp">Atualizado às ${esc(stamp)}</span><div><button type="button" class="ied-button" data-refresh>Atualizar</button><form class="ied-export" method="post" action="${esc(config.exportUrl)}"><input type="hidden" name="action" value="ie_export"><input type="hidden" name="_wpnonce" value="${esc(config.exportNonce)}">${hiddenExport}<button class="ied-button ied-button-primary" type="submit">Exportar CSV</button></form></div></div>
        <div class="ied-message" role="status" aria-live="polite"></div>
        <details class="ied-filter-panel" open><summary>Filtrar inscrições</summary>
          <form class="ied-filters">
            ${filterField('Evento', `<select name="event" ${fixedEvent ? 'disabled' : ''}>${options(eventItems, f.event)}</select>`)}
            ${filterField('Lote', `<select name="lot">${options(lotItems, f.lot)}</select>`)}
            ${filterField('Pagamento', `<select name="status">${options([['', 'Todos os saldos'], ['pending', 'Sem pagamento'], ['partial', 'Pagamento parcial'], ['paid', 'Quitadas'], ['overpaid', 'Pagamento excedente']], f.status)}</select>`)}
            ${filterField('Forma do cadastro', `<select name="method">${options([['', 'Todas as formas'], ['Pix', 'PIX'], ['Cartao', 'Cartão online'], ['CartaoPOS', 'Cartão físico']], f.method)}</select>`)}
            ${filterField('Situação da inscrição', `<select name="registration_status">${options([['active', 'Ativas'], ['cancelled', 'Canceladas'], ['deleted', 'Lixeira'], ['all', 'Todas']], f.registration_status)}</select>`)}
            ${filterField('Nome, e-mail ou CPF completo', `<input type="search" name="q" maxlength="200" value="${esc(f.q)}" placeholder="Buscar participante">`, 'ied-search')}
            ${filterField('Cadastro de', `<input type="date" name="start" value="${esc(f.start)}">`)}
            ${filterField('Cadastro até', `<input type="date" name="end" value="${esc(f.end)}">`)}
            <div class="ied-filter-footer"><label class="ied-checkbox"><input type="checkbox" name="all_scopes" value="1" ${f.all_scopes ? 'checked' : ''}> Incluir outras contas e ambientes K8</label><div><button type="button" class="ied-button" data-clear>Limpar</button><button class="ied-button ied-button-primary" type="submit">Aplicar filtros</button></div></div>
          </form><p class="ied-hint">Período de cadastro no fuso ${esc(result.date_timezone)}. A forma do cadastro filtra a modalidade da inscrição; recebimentos são separados pelo meio efetivamente pago.</p>
        </details>
        <div class="ied-metrics">
          ${metric('Inscrições', number.format(m.count), `${number.format(m.paid_count)} quitadas · ${number.format(m.partial)} parciais`)}
          ${metric('Valor das inscrições', money(m.total), 'Preço contratado em cada lote')}
          ${metric('Recebido / registrado', money(m.paid), `${money(m.gateway_paid)} K8 · ${money(m.manual_paid)} manual`, 'ied-metric-received')}
          ${metric('A receber', money(m.balance), `${number.format(m.pending + m.partial)} inscrições com saldo`, 'ied-metric-due')}
        </div>
        <p class="ied-hint ied-finance-note">Todos os indicadores seguem os filtros acima e incluem baixas manuais ativas identificadas pela organização. Cartão aprovado é contado pelo valor integral, mesmo que o banco ainda liquide as parcelas. Excedentes não quitam outras inscrições.</p>
        <div class="ied-overview">
          <article class="ied-panel ied-collection"><div class="ied-section-title"><h3>Quitação do valor contratado</h3><strong>${number.format(m.progress)}%</strong></div>${progress(m.progress, 'Percentual quitado do valor contratado')}<div class="ied-key"><span><i class="ied-dot ied-dot-paid"></i>${money(m.covered)} quitados</span><span><i class="ied-dot ied-dot-pending"></i>${money(m.balance)} a receber</span></div>
            <div class="ied-operational"><div><small>Inscrições</small><b>${c ? c.open ? 'Abertas' : 'Fechadas' : 'Por evento'}</b></div><div><small>Lote vigente</small><b>${esc(c?.active_lot?.name || 'Nenhum')}</b></div><div><small>Prazo de quitação</small><b>${esc(c ? countdown(c.deadline_days) : 'Por evento')}</b></div></div>
            ${c?.active_lot ? `<p class="ied-hint">${esc(money(c.active_lot.price))} · ${e?.allow_card ? `cartão online até ${c.active_lot.installments}x` : e?.allow_card_physical ? 'cartão físico na secretaria' : 'PIX'} · lote até ${esc(day(c.active_lot.ends_on))}</p>` : ''}
          </article>
          <article class="ied-panel"><h3>Situação dos pagamentos</h3><div class="ied-status-chart"><div class="ied-donut" style="--ied-paid-angle:${paidAngle}deg;--ied-partial-angle:${partialAngle}deg" aria-hidden="true"><span>${number.format(m.count)}<small>inscrições</small></span></div><ul>${statuses.map(([count, label, key]) => `<li><i class="ied-dot ied-dot-${key}"></i><span>${label}</span><b>${number.format(count)}</b></li>`).join('')}</ul></div></article>
        </div>
        ${warnings.length || result.cron_error ? `<aside class="ied-alerts" aria-label="Pendências"><h3>Atenção da organização</h3>${warnings.map(([count, label, note]) => `<div><b>${number.format(count)} · ${esc(label)}</b><span>${esc(note)}</span></div>`).join('')}${result.cron_error ? `<div><b>Conciliação automática</b><span>${esc(result.cron_error)}</span></div>` : ''}</aside>` : ''}
        <div class="ied-breakdowns">
          <article class="ied-panel"><h3>Financeiro por lote</h3><div class="ied-lot-list">${result.lots.length ? result.lots.map(lot => `<div><div class="ied-section-title"><b>${esc(lot.label)}${!e ? `<small>${esc(lot.event_name)}</small>` : ''}</b><span>${number.format(lot.count)} inscrições</span></div>${progress(percent(lot.covered, lot.total), `Quitação ${lot.label}`)}<div class="ied-lot-values"><span>Recebido <b>${esc(money(lot.paid))}</b></span><span>A receber <b>${esc(money(lot.balance))}</b></span></div></div>`).join('') : '<p class="ied-empty">Nenhum lote nos filtros selecionados.</p>'}</div></article>
          <article class="ied-panel"><h3>Recebimentos por meio</h3>${result.receipts.length ? result.receipts.map(method => `<div class="ied-method"><div class="ied-section-title"><b>${esc(labels[method.label] || method.label)}</b><span>${number.format(method.count)} inscrições com recebimento</span></div><strong>${esc(money(method.paid))}<small>${esc(money(method.gateway_paid))} K8 · ${esc(money(method.manual_paid))} manual</small></strong></div>`).join('') : '<p class="ied-empty">Nenhum recebimento confirmado nestes filtros.</p>'}</article>
          <article class="ied-panel"><h3>Evolução das inscrições</h3><p class="ied-hint">Últimos 30 dias com cadastros · UTC</p>${result.timeline.length ? `<div class="ied-timeline" role="img" aria-label="${esc(result.timeline.map(item => `${day(item.label)}: ${item.count} inscrições`).join('; '))}">${result.timeline.map(item => `<span title="${esc(day(item.label))}: ${item.count} inscrições"><i style="height:${percent(item.count, maxDay)}%"></i></span>`).join('')}</div><div class="ied-chart-dates"><span>${esc(day(result.timeline[0].label))}</span><span>${esc(day(result.timeline.at(-1).label))}</span></div>` : '<p class="ied-empty">Os cadastros aparecerão aqui.</p>'}</article>
        </div>
        <article class="ied-panel ied-registration-panel"><div class="ied-section-title"><h3>Inscrições</h3><span>${number.format(m.count)} resultado${m.count === 1 ? '' : 's'}</span></div>
          <div class="ied-table-wrap"><table><thead><tr><th>Participante</th><th>Lote / forma do cadastro</th><th>Valor</th><th>Recebido</th><th>A receber</th><th>Situação</th><th>Cadastro</th><th>Ações</th></tr></thead><tbody>
            ${result.registrations.length ? result.registrations.map(r => {
              const created = new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short', timeStyle: 'short', timeZone: result.date_timezone }).format(new Date(r.created_at.replace(' ', 'T') + 'Z'));
              return `<tr><td><b>${esc(r.name)}</b><small>#${r.id} · ${esc(r.email)}</small><small>${esc(r.phone)}</small>${!e ? `<small>${esc(r.event_name)}</small>` : ''}</td><td>${esc(r.lot_name)}<small>${esc(labels[r.method] || r.method)}</small></td><td>${esc(money(r.total))}</td><td class="ied-money-paid">${esc(money(r.paid))}${r.overpaid ? `<small>Excedente ${esc(money(r.overpaid))}</small>` : ''}</td><td>${esc(money(r.balance))}</td><td><span class="ied-badge ied-badge-${esc(r.status)}">${esc(r.overpaid ? 'Excedente' : labels[r.status] || r.status)}</span><small>${esc(labels[r.registration_status] || r.registration_status)}${r.alerts ? ' · Conferir pendência' : ''}</small></td><td>${esc(created)}</td><td><button type="button" class="ied-button" data-registration="${r.id}">${result.can_manage ? 'Detalhes / editar' : 'Consultar detalhes'}</button></td></tr>`;
            }).join('') : '<tr><td colspan="8" class="ied-empty">Nenhuma inscrição para estes filtros.</td></tr>'}
          </tbody></table></div>
          <div class="ied-pagination"><button type="button" class="ied-button" data-page="${result.pagination.page - 1}" ${result.pagination.page === 1 ? 'disabled' : ''}>Anterior</button><span>Página ${result.pagination.page} de ${result.pagination.pages}</span><button type="button" class="ied-button" data-page="${result.pagination.page + 1}" ${result.pagination.page >= result.pagination.pages ? 'disabled' : ''}>Próxima</button></div>
        </article><footer class="ied-footer">${result.can_manage ? 'Perfil administrador' : 'Gerente de Eventos · somente consulta'}. <a href="${esc(config.logoutUrl)}">Sair</a></footer>`;
    }
    function formFilters() {
      const values = Object.fromEntries(new FormData(root.querySelector('.ied-filters')));
      values.event = fixedEvent || values.event || 0; values.all_scopes = values.all_scopes || 0;
      return { ...values, page: 1 };
    }
    async function load(filters) {
      controller?.abort(); controller = new AbortController(); const active = controller;
      root.setAttribute('aria-busy', 'true');
      root.querySelector('.ied-message')?.replaceChildren(document.createTextNode('Atualizando…'));
      root.querySelectorAll('button').forEach(button => { button.disabled = true; });
      try {
        const url = new URL(config.api, window.location.href);
        Object.entries(filters).forEach(([key, value]) => url.searchParams.set(key, String(value)));
        const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store', headers: { 'X-WP-Nonce': config.nonce, Accept: 'application/json' }, signal: active.signal });
        const result = await response.json();
        if (!response.ok) throw new Error(response.status === 401 || response.status === 403 ? 'Sua sessão expirou ou não possui acesso. Entre como administrador ou gerente de eventos e recarregue a página.' : result.message || 'Não foi possível carregar o dashboard.');
        applied = { ...result.filters, page: result.pagination.page }; render(result);
        root.querySelector('.ied-message').textContent = 'Dashboard atualizado.';
      } catch (error) {
        if (error.name === 'AbortError') return;
        let message = root.querySelector('.ied-message');
        if (!message) { root.innerHTML = '<div class="ied-message" role="alert"></div><button type="button" class="ied-button" data-refresh>Tentar novamente</button>'; message = root.querySelector('.ied-message'); }
        message.textContent = error.message; message.classList.add('ied-message-error');
        if (/sessão expirou|não possui acesso/.test(error.message)) { const login = document.createElement('a'); login.href = config.loginUrl; login.textContent = ' Entrar no dashboard'; message.append(login); }
        root.querySelectorAll('button').forEach(button => { button.disabled = false; });
        const exporter = root.querySelector('.ied-export button'); if (exporter) exporter.disabled = true;
        root.querySelectorAll('[data-page]').forEach(button => { button.disabled = !data || Number(button.dataset.page) < 1 || Number(button.dataset.page) > data.pagination.pages; });
      } finally { if (controller === active) root.setAttribute('aria-busy', 'false'); }
    }
    root.addEventListener('submit', event => {
      if (event.target.matches('.ied-filters')) { event.preventDefault(); load(formFilters()); }
    });
    root.addEventListener('change', event => {
      if (event.target.name === 'event') { root.querySelector('[name="lot"]').value = ''; load(formFilters()); }
    });
    root.addEventListener('click', event => {
      const button = event.target.closest('button'); if (!button) return;
      if (button.hasAttribute('data-registration')) window.IERegistrationPanel.open({ id: Number(button.dataset.registration), event: data.filters.event, all_scopes: data.filters.all_scopes, config, onSaved: () => load(applied) });
      if (button.hasAttribute('data-refresh')) load(applied);
      if (button.hasAttribute('data-page')) load({ ...applied, page: Number(button.dataset.page) });
      if (button.hasAttribute('data-clear')) load({ event: fixedEvent || data.filters.event, page: 1 });
    });
    load(applied);
  });
})();
