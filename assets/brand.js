(() => {
  'use strict';
  const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const money = value => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value / 100);
  window.IEBrand = {
    cover(event, lot) {
      if (event?.brand_preset !== 'origens' || !event.cover_url) return '';
      const cap = lot?.max_installments ?? lot?.installments;
      const method = event.allow_card ? `Cartão online em até ${cap}x` : event.allow_card_physical ? 'Cartão físico na secretaria' : 'Pagamento por PIX';
      return `<div class="ie-brand-cover"><img src="${esc(event.cover_url)}" alt="Origens — O fogo permanece" fetchpriority="high"><aside class="ie-live-lot" aria-label="Lote vigente"><small>${lot ? 'LOTE VIGENTE' : 'INSCRIÇÕES'}</small><b>${esc(lot?.name || 'Novas inscrições indisponíveis')}</b>${lot ? `<strong>${esc(money(lot.price))}</strong><span>${esc(method)}</span><small>Até ${esc(lot.ends_on.split('-').reverse().join('/'))}</small>` : '<span>Consulte as condições do evento.</span>'}</aside></div>`;
    }
  };
})();
