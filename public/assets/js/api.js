/* Tirage — client API JSON (fetch natif, CSRF, erreurs normalisées) */
(function () {
  'use strict';

  const state = { csrf: null };

  async function call(route, options = {}) {
    const { method = 'GET', body = null, query = {} } = options;
    const params = new URLSearchParams({ r: route, ...query });
    const headers = { 'Accept': 'application/json' };
    const init = { method, headers, credentials: 'same-origin' };

    if (method === 'POST') {
      if (body instanceof FormData) {
        init.body = body;
      } else {
        headers['Content-Type'] = 'application/json';
        init.body = JSON.stringify(body || {});
      }
      if (state.csrf) headers['X-CSRF-Token'] = state.csrf;
    }

    let response;
    try {
      response = await fetch('api.php?' + params.toString(), init);
    } catch (networkError) {
      const error = new Error('Connexion interrompue — vérifiez votre réseau.');
      error.network = true;
      throw error;
    }

    let data = null;
    try { data = await response.json(); } catch (_) { /* réponse non JSON */ }

    if (!response.ok || !data || data.ok === false) {
      const message = (data && data.error) ? data.error : ('Erreur serveur (' + response.status + ')');
      const error = new Error(message);
      error.status = response.status;
      error.retryable = response.status === 502 || response.status >= 500;
      throw error;
    }
    return data;
  }

  window.Api = {
    setCsrf(token) { state.csrf = token; },
    get: (route, query) => call(route, { query }),
    post: (route, body, query) => call(route, { method: 'POST', body, query }),
    upload: (route, formData, query) => call(route, { method: 'POST', body: formData, query })
  };
})();
