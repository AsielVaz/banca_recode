document.querySelectorAll('[data-confirm]').forEach((form) => {
  form.addEventListener('submit', (event) => {
    if (!window.confirm(form.dataset.confirm || '¿Continuar?')) event.preventDefault();
  });
});

document.querySelectorAll('[data-lock-submit]').forEach((form) => {
  form.addEventListener('submit', () => {
    const button = form.querySelector('button[type="submit"], button:not([type])');
    if (button) {
      button.disabled = true;
      button.textContent = 'Procesando…';
    }
  });
});

const contactPicker = document.querySelector('#contact-picker');
if (contactPicker) {
  contactPicker.addEventListener('change', () => {
    const option = contactPicker.selectedOptions[0];
    if (!option || !option.value) return;
    const form = contactPicker.closest('form');
    form.elements.name.value = option.dataset.name || '';
    form.elements.account.value = option.dataset.account || '';
    form.elements.email.value = option.dataset.email || '';
    form.elements.phone.value = option.dataset.phone || '';
    form.elements.concept.value = option.dataset.concept || '';
    form.elements.account.dispatchEvent(new Event('input'));
  });
}

const accountInput = document.querySelector('input[name="account"]');
if (accountInput) {
  const bankSelect = accountInput.form.elements.bank;
  const bankStatus = accountInput.form.querySelector('[data-bank-status]');
  let bankRequest;
  const selectBank = (code) => {
    const option = [...bankSelect.options].find((item) => item.value.padStart(3, '0') === code);
    if (!option) return false;
    bankSelect.value = option.value;
    return true;
  };
  accountInput.addEventListener('input', async () => {
    bankRequest?.abort();
    accountInput.value = accountInput.value.replace(/\D/g, '').slice(0, 18);
    bankSelect.value = '';
    bankStatus.textContent = '';
    if (accountInput.value.length === 18) {
      const prefix = accountInput.value.slice(0, 3);
      if (selectBank(prefix)) bankStatus.textContent = 'Banco identificado por la CLABE.';
      else bankStatus.textContent = 'El banco de la CLABE no está en el catálogo.';
      return;
    }
    if (accountInput.value.length !== 16) {
      if (accountInput.value.length > 0) bankStatus.textContent = 'Ingresa 16 dígitos de tarjeta o 18 de CLABE.';
      return;
    }

    bankStatus.textContent = 'Consultando banco en Sistema 14…';
    bankRequest = new AbortController();
    const payload = new FormData();
    payload.append('_csrf', accountInput.form.elements._csrf.value);
    payload.append('account', accountInput.value);
    try {
      const response = await fetch(accountInput.form.dataset.bankResolveUrl, {
        method: 'POST',
        body: payload,
        signal: bankRequest.signal,
        headers: { Accept: 'application/json' },
      });
      const result = await response.json();
      if (!response.ok || !result.ok) throw new Error(result.message || 'Banco no reconocido.');
      if (!selectBank(result.bank.code)) throw new Error('El banco identificado no está disponible.');
      bankStatus.textContent = `${result.bank.name} · identificado automáticamente`;
    } catch (error) {
      if (error.name === 'AbortError') return;
      bankStatus.textContent = error.message || 'No se pudo consultar el banco.';
    }
  });
}

document.querySelector('#print-page')?.addEventListener('click', () => window.print());

const sidebar = document.querySelector('#sidebar');
const menuToggle = document.querySelector('.menu-toggle');
const menuClose = document.querySelector('.menu-close');

function setMenu(open) {
  if (!sidebar || !menuToggle) return;
  sidebar.classList.toggle('open', open);
  document.body.classList.toggle('menu-open', open);
  menuToggle.setAttribute('aria-expanded', String(open));
  menuToggle.setAttribute('aria-label', open ? 'Cerrar navegación' : 'Abrir navegación');
  if (open) menuClose?.focus();
  else menuToggle.focus();
}

menuToggle?.addEventListener('click', () => setMenu(!sidebar?.classList.contains('open')));
menuClose?.addEventListener('click', () => setMenu(false));
document.querySelector('[data-menu-close]')?.addEventListener('click', () => setMenu(false));
document.addEventListener('keydown', (event) => {
  if (event.key === 'Escape' && sidebar?.classList.contains('open')) setMenu(false);
});

const batchForm = document.querySelector('[data-batch-form]');
if (batchForm) {
  const fileInput = batchForm.querySelector('input[type="file"]');
  const fileLabel = batchForm.querySelector('[data-file-label]');
  const status = batchForm.querySelector('[data-batch-status]');
  const preview = batchForm.querySelector('[data-batch-preview]');
  const rowsBody = batchForm.querySelector('[data-batch-rows]');
  const totp = batchForm.querySelector('[data-batch-totp]');
  const submit = batchForm.querySelector('[data-batch-submit]');
  let previewRequest;

  const money = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' });
  const setStatus = (message, state = '') => {
    status.textContent = message;
    status.dataset.state = state;
  };
  const cell = (value, className = '') => {
    const element = document.createElement('td');
    element.textContent = String(value ?? '');
    if (className) element.className = className;
    return element;
  };
  const resetAuthorization = () => {
    totp.disabled = true;
    totp.value = '';
    submit.disabled = true;
  };
  const renderRows = (rows) => {
    rowsBody.replaceChildren();
    rows.forEach((item) => {
      const row = document.createElement('tr');
      if (!item.valid) row.classList.add('invalid-row');
      row.append(
        cell(item.row),
        cell(item.name),
        cell(item.account, 'account-mask'),
      );
      const bank = cell(item.bank || 'No identificado');
      if (item.bank_source) bank.title = `Origen: ${item.bank_source}`;
      row.append(bank, cell(item.phone), cell(money.format(Number(item.amount) || 0), 'number'));
      const validation = document.createElement('td');
      const badge = document.createElement('span');
      badge.className = `badge ${item.valid ? 'in' : 'out'}`;
      badge.textContent = item.valid ? 'Correcto' : item.error;
      validation.append(badge);
      row.append(validation);
      rowsBody.append(row);
    });
  };

  fileInput.addEventListener('change', async () => {
    previewRequest?.abort();
    preview.hidden = true;
    resetAuthorization();
    const file = fileInput.files?.[0];
    if (!file) {
      fileLabel.textContent = 'Archivo XLS original · máximo 50 retiros';
      setStatus('Selecciona el archivo para cargar su información.');
      return;
    }
    fileLabel.textContent = file.name;
    setStatus('Leyendo la plantilla y determinando bancos…', 'loading');
    previewRequest = new AbortController();
    const payload = new FormData(batchForm);
    payload.delete('totp');
    try {
      const response = await fetch(batchForm.dataset.previewUrl, {
        method: 'POST',
        body: payload,
        signal: previewRequest.signal,
        headers: { Accept: 'application/json' },
      });
      const result = await response.json();
      if (!response.ok || !result.ok) throw new Error(result.message || 'No se pudo leer la plantilla.');

      renderRows(result.rows);
      batchForm.querySelector('[data-summary-balance]').textContent = money.format(result.summary.balance);
      batchForm.querySelector('[data-summary-after]').textContent = money.format(result.summary.after);
      batchForm.querySelector('[data-summary-total]').textContent = money.format(result.summary.total);
      batchForm.querySelector('[data-summary-count]').textContent = String(result.summary.count);
      preview.hidden = false;
      if (result.valid) {
        setStatus('Plantilla validada. Revisa la información y autoriza el envío.', 'success');
        totp.disabled = false;
        submit.disabled = false;
        totp.focus();
      } else {
        setStatus('La plantilla contiene errores. Corrige las filas marcadas antes de continuar.', 'error');
      }
    } catch (error) {
      if (error.name === 'AbortError') return;
      setStatus(error.message || 'No se pudo procesar la plantilla.', 'error');
    }
  });
}
