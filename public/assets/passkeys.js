(() => {
  'use strict';

  const encode = (buffer) => {
    const bytes = new Uint8Array(buffer);
    let binary = '';
    bytes.forEach((byte) => { binary += String.fromCharCode(byte); });
    return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
  };

  const decode = (value) => {
    if (typeof value !== 'string' || !/^[A-Za-z0-9_-]+$/.test(value)) throw new Error('El servidor devolvió datos WebAuthn no válidos.');
    const padded = value.replace(/-/g, '+').replace(/_/g, '/') + '='.repeat((4 - value.length % 4) % 4);
    const binary = atob(padded);
    return Uint8Array.from(binary, (character) => character.charCodeAt(0)).buffer;
  };

  const endpoint = (element, action) => {
    const url = new URL(element.dataset.webauthnEndpoint, window.location.href);
    if (url.origin !== window.location.origin) throw new Error('El endpoint de Passkeys no pertenece a este sistema.');
    url.searchParams.set('webauthn', action);
    return url;
  };

  const request = async (element, action, payload = {}) => {
    const response = await fetch(endpoint(element, action), {
      method: 'POST',
      credentials: 'same-origin',
      headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
      body: JSON.stringify(payload),
    });
    let data;
    try { data = await response.json(); } catch { throw new Error('El servidor devolvió una respuesta inesperada.'); }
    if (!response.ok || !data.ok) throw new Error(data.message || 'No fue posible completar la operación.');
    return data;
  };

  const requestOptions = (options) => {
    const publicKey = {...options.publicKey, challenge: decode(options.publicKey.challenge)};
    if (Array.isArray(publicKey.allowCredentials)) {
      publicKey.allowCredentials = publicKey.allowCredentials.map((item) => ({...item, id: decode(item.id)}));
    }
    return publicKey;
  };

  const creationOptions = (options) => {
    const publicKey = {
      ...options.publicKey,
      challenge: decode(options.publicKey.challenge),
      user: {...options.publicKey.user, id: decode(options.publicKey.user.id)},
    };
    if (Array.isArray(publicKey.excludeCredentials)) {
      publicKey.excludeCredentials = publicKey.excludeCredentials.map((item) => ({...item, id: decode(item.id)}));
    }
    return publicKey;
  };

  const assertionPayload = (credential) => ({
    id: credential.id,
    rawId: encode(credential.rawId),
    type: credential.type,
    authenticatorAttachment: credential.authenticatorAttachment || null,
    response: {
      clientDataJSON: encode(credential.response.clientDataJSON),
      authenticatorData: encode(credential.response.authenticatorData),
      signature: encode(credential.response.signature),
      userHandle: credential.response.userHandle ? encode(credential.response.userHandle) : '',
    },
    clientExtensionResults: credential.getClientExtensionResults(),
  });

  const registrationPayload = (credential, name, csrfToken) => ({
    id: credential.id,
    rawId: encode(credential.rawId),
    type: credential.type,
    name,
    csrf_token: csrfToken,
    response: {
      clientDataJSON: encode(credential.response.clientDataJSON),
      attestationObject: encode(credential.response.attestationObject),
      transports: typeof credential.response.getTransports === 'function' ? credential.response.getTransports() : [],
    },
    clientExtensionResults: credential.getClientExtensionResults(),
  });

  const readableError = (error) => {
    if (error?.name === 'NotAllowedError') return 'La operación se canceló, venció o no se encontró una Passkey compatible.';
    if (error?.name === 'InvalidStateError') return 'Esta Passkey ya está registrada en la cuenta.';
    if (error?.name === 'SecurityError') return 'El dominio no coincide con la configuración segura de Passkeys.';
    if (error?.name === 'AbortError') return 'La operación fue cancelada.';
    return error?.message || 'No fue posible usar la Passkey.';
  };

  const setStatus = (element, message, error = false) => {
    if (!element) return;
    element.textContent = message;
    element.hidden = false;
    element.classList.toggle('is-error', error);
  };

  const safeRedirect = (value) => {
    const target = new URL(value, window.location.href);
    if (!['http:', 'https:'].includes(target.protocol) || target.origin !== window.location.origin) {
      throw new Error('El destino de acceso no pertenece a este sistema.');
    }
    return `${target.pathname}${target.search}${target.hash}`;
  };

  const supported = 'PublicKeyCredential' in window && !!navigator.credentials;
  const loginButton = document.querySelector('[data-passkey-login]');
  if (loginButton) {
    const status = document.querySelector('[data-passkey-status]');
    if (!supported) {
      loginButton.disabled = true;
      setStatus(status, 'Este navegador no admite Passkeys.', true);
    } else {
      loginButton.addEventListener('click', async () => {
        loginButton.disabled = true;
        loginButton.setAttribute('aria-busy', 'true');
        setStatus(status, 'Confirma tu identidad en el dispositivo…');
        try {
          const identity = (document.querySelector(`[name="${loginButton.dataset.identityField}"]`)?.value || '').trim();
          const options = await request(loginButton, 'login-options', {identity});
          const credential = await navigator.credentials.get({publicKey: requestOptions(options.options)});
          if (!credential) throw new Error('El dispositivo no devolvió una credencial.');
          const result = await request(loginButton, 'login-verify', assertionPayload(credential));
          setStatus(status, 'Identidad verificada. Abriendo el sistema…');
          window.location.assign(safeRedirect(result.redirect));
        } catch (error) {
          setStatus(status, readableError(error), true);
          loginButton.disabled = false;
          loginButton.removeAttribute('aria-busy');
        }
      });
    }
  }

  const manager = document.querySelector('[data-passkey-manager]');
  if (!manager) return;
  const csrfToken = manager.dataset.csrfToken;
  const status = manager.querySelector('[data-passkey-status]');
  const register = manager.querySelector('[data-passkey-register]');
  if (!supported) {
    if (register) register.disabled = true;
    setStatus(status, 'Este navegador no admite Passkeys.', true);
  }

  register?.addEventListener('click', async () => {
    const value = window.prompt('Nombre para identificar este dispositivo:', 'Mi dispositivo');
    if (value === null) return;
    const name = value.trim();
    if (!name) return setStatus(status, 'Escribe un nombre para la Passkey.', true);
    register.disabled = true;
    setStatus(status, 'Preparando el registro seguro…');
    try {
      const options = await request(manager, 'register-options', {csrf_token: csrfToken});
      const credential = await navigator.credentials.create({publicKey: creationOptions(options.options)});
      if (!credential) throw new Error('El dispositivo no devolvió una credencial.');
      const result = await request(manager, 'register-verify', registrationPayload(credential, name, csrfToken));
      setStatus(status, result.data?.message || 'Passkey registrada.');
      window.location.reload();
    } catch (error) {
      setStatus(status, readableError(error), true);
      register.disabled = false;
    }
  });

  manager.querySelectorAll('[data-passkey-rename]').forEach((button) => button.addEventListener('click', async () => {
    const value = window.prompt('Nuevo nombre para esta Passkey:', button.dataset.currentName || '');
    if (value === null || !value.trim()) return;
    button.disabled = true;
    try {
      const result = await request(manager, 'rename', {csrf_token: csrfToken, credential_id: button.dataset.passkeyRename, name: value.trim()});
      setStatus(status, result.message || 'Nombre actualizado.');
      window.location.reload();
    } catch (error) {
      setStatus(status, readableError(error), true);
      button.disabled = false;
    }
  }));

  manager.querySelectorAll('[data-passkey-delete]').forEach((button) => button.addEventListener('click', async () => {
    if (!window.confirm('¿Eliminar esta Passkey? El dispositivo dejará de poder iniciar sesión.')) return;
    button.disabled = true;
    try {
      const result = await request(manager, 'delete', {csrf_token: csrfToken, credential_id: button.dataset.passkeyDelete});
      setStatus(status, result.message || 'Passkey eliminada.');
      button.closest('[data-passkey-card]')?.remove();
    } catch (error) {
      setStatus(status, readableError(error), true);
      button.disabled = false;
    }
  }));
})();
