(function () {
  const form = document.querySelector('.request-form');
  if (!form) return;

  const endpoint = form.dataset.endpoint || 'https://bot.mir-auto-china.ru/api/contact.php';
  const button = form.querySelector('button');
  if (!button) return;
  button.type = 'submit';
  ['name', 'contact', 'model', 'budget'].forEach((name) => {
    const input = form.querySelector(`[name="${name}"]`);
    if (input) input.required = true;
  });

  let status = form.querySelector('.form-status');
  if (!status) {
    status = document.createElement('small');
    status.className = 'form-status';
    status.hidden = true;
    status.setAttribute('role', 'status');
    status.setAttribute('aria-live', 'polite');
    form.append(status);
  }

  let honeypot = form.querySelector('[name="website"]');
  if (!honeypot) {
    honeypot = document.createElement('input');
    honeypot.name = 'website';
    honeypot.tabIndex = -1;
    honeypot.autocomplete = 'off';
    honeypot.setAttribute('aria-hidden', 'true');
    honeypot.style.cssText = 'position:absolute;left:-10000px;width:1px;height:1px;opacity:0';
    form.append(honeypot);
  }
  let lastSubmitAt = 0;
  let inFlight = false;
  const buttonLabel = button.textContent;

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (inFlight || Date.now() - lastSubmitAt < 4000 || !form.reportValidity()) return;
    inFlight = true;
    const controller = new AbortController();
    // Allow time for the server's bounded Telegram IPv4 retry on slow mobile links.
    const timeout = setTimeout(() => controller.abort(), 30000);

    const data = Object.fromEntries(new FormData(form).entries());
    lastSubmitAt = Date.now();
    button.disabled = true;
    button.textContent = 'Отправляем…';
    form.setAttribute('aria-busy', 'true');
    if (status) {
      status.hidden = false;
      status.textContent = 'Отправляем заявку…';
      status.dataset.state = 'pending';
    }

    try {
      const response = await fetch(endpoint, {
        method: 'POST',
        // text/plain is CORS-safelisted; the PHP endpoint still decodes the JSON body.
        // This avoids an OPTIONS preflight on mobile browsers and restrictive networks.
        headers: { 'Content-Type': 'text/plain;charset=UTF-8', Accept: 'application/json' },
        body: JSON.stringify(data),
        signal: controller.signal,
      });
      const result = await response.json().catch(() => null);
      if (!response.ok || !result || result.ok !== true) throw new Error('delivery_failed');
      form.reset();
      if (status) {
        status.textContent = 'Заявка успешно отправлена. Представитель свяжется с вами в ближайшее время.';
        status.dataset.state = 'success';
      }
    } catch (error) {
      if (status) {
        status.textContent = 'Не удалось отправить заявку. Позвоните представителю или попробуйте ещё раз.';
        status.dataset.state = 'error';
      }
    } finally {
      clearTimeout(timeout);
      inFlight = false;
      button.disabled = false;
      button.textContent = buttonLabel;
      form.removeAttribute('aria-busy');
      if (honeypot) honeypot.value = '';
    }
  });
})();
