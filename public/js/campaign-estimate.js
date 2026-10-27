(() => {
  const root = document.getElementById('campaign-estimate');
  if (!root) return;
  const form = document.querySelector('form.campaign-editor');
  if (!form) return;
  const fields = ['source_group_id', 'audio_id', 'type', 'ivr_flow_id', 'contact_ids'];
  let sequence = 0;
  let controller = null;
  let timer = null;

  function query() {
    const params = new URLSearchParams();
    fields.forEach((name) => {
      const field = form.elements.namedItem(name);
      if (field && 'value' in field) params.set(name, field.value);
    });
    return params.toString();
  }

  async function refresh() {
    const current = ++sequence;
    if (controller) controller.abort();
    controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 6000);
    try {
      const response = await fetch(root.dataset.url + '?' + query(), {
        headers: { Accept: 'application/json' },
        cache: 'no-store',
        signal: controller.signal
      });
      if (!response.ok || current !== sequence) return;
      const data = await response.json();
      if (current === sequence && typeof data.html === 'string') root.innerHTML = data.html;
    } catch (_) {
      // Keep the last rendered forecast; the next change will retry.
    } finally {
      clearTimeout(timeout);
    }
  }

  function schedule(delay) {
    clearTimeout(timer);
    timer = setTimeout(refresh, delay);
  }

  form.addEventListener('change', (event) => {
    if (fields.includes(event.target.name)) schedule(0);
  });
  form.addEventListener('input', (event) => {
    if (event.target.name === 'contact_ids') schedule(400);
  });
})();
