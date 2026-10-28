(() => {
  const root = document.getElementById('campaign-estimate');
  const form = document.querySelector('form.campaign-editor');
  if (!root || !form) return;
  const fields = ['source_group_id', 'audio_id', 'type', 'ivr_flow_id', 'contact_ids'];
  let sequence = 0;
  let controller;
  let timer;

  function status(message, retry = false) {
    const estimate = root.querySelector('.campaign-estimate');
    if (estimate) estimate.hidden = true;
    let notice = root.querySelector('[data-pricing-status]');
    if (!notice) {
      notice = document.createElement('p');
      notice.dataset.pricingStatus = '';
      notice.setAttribute('role', 'status');
      root.appendChild(notice);
    }
    notice.textContent = message;
    if (retry) {
      const button = document.createElement('button');
      button.type = 'button';
      button.textContent = 'Retry pricing';
      button.addEventListener('click', () => schedule(0));
      notice.appendChild(button);
    }
  }

  async function refresh(current) {
    const params = new URLSearchParams();
    fields.forEach((name) => {
      const field = form.elements.namedItem(name);
      if (field && 'value' in field) params.set(name, field.value);
    });
    controller = new AbortController();
    const active = controller;
    const timeout = setTimeout(() => active.abort(), 10000);
    try {
      const response = await fetch(root.dataset.url + '?' + params.toString(), {
        headers: { Accept: 'application/json' }, cache: 'no-store', signal: active.signal
      });
      if (!response.ok) throw new Error('Pricing unavailable');
      const data = await response.json();
      if (current === sequence && typeof data.html === 'string') root.innerHTML = data.html;
    } catch (_) {
      if (current === sequence) status('Pricing could not be updated. ', true);
    } finally {
      clearTimeout(timeout);
      if (current === sequence) root.setAttribute('aria-busy', 'false');
    }
  }

  function schedule(delay) {
    clearTimeout(timer);
    if (controller) controller.abort();
    const current = ++sequence;
    root.setAttribute('aria-busy', 'true');
    status('Updating campaign pricing…');
    timer = setTimeout(() => refresh(current), delay);
  }
  form.addEventListener('change', (event) => {
    if (fields.includes(event.target.name)) schedule(0);
  });
  form.addEventListener('input', (event) => {
    if (event.target.name === 'contact_ids') schedule(400);
  });
})();
