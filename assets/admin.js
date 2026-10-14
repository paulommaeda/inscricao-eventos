(() => {
  'use strict';
  document.addEventListener('click', event => {
    const button = event.target.closest('button'); if (!button) return;
    if (button.matches('#ie-add-lot')) {
      const list = document.getElementById('ie-lots');
      const template = document.getElementById('ie-lot-template');
      const ids = [...list.querySelectorAll('input[name]')].map(el => Number(el.name.match(/lots\[(\d+)\]/)?.[1] ?? -1));
      const next = Math.max(-1, ...ids) + 1;
      list.insertAdjacentHTML('beforeend', template.innerHTML.replaceAll('__INDEX__', String(next)));
    }
    if (button.matches('.ie-remove-lot')) button.closest('.ie-lot-row').remove();
    if (button.matches('.ie-confirm') && !window.confirm(button.dataset.confirm)) event.preventDefault();
    if (button.matches('.ie-media') && window.wp?.media) {
      const modal = wp.media({ title: 'Selecionar imagem do evento', button: { text: 'Usar imagem' }, multiple: false, library: { type: 'image' } });
      modal.on('select', () => { const selected = modal.state().get('selection').first().toJSON(); document.querySelector(`[name="${button.dataset.field}"]`).value = selected.url; });
      modal.open();
    }
  });
})();
