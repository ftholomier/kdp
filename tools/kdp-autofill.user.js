// ==UserScript==
// @name         Tirage — Remplissage auto du formulaire Amazon KDP
// @namespace    tirage-kdp-studio
// @version      1.0.0
// @description  Remplit automatiquement le formulaire de publication Amazon KDP (titre, sous-titre, auteur, description, mots-clés, prix) depuis vos projets Tirage. Vous gardez le clic final « Publier ».
// @match        https://kdp.amazon.com/*
// @grant        GM_xmlhttpRequest
// @grant        GM_setValue
// @grant        GM_getValue
// @connect      *
// @run-at       document-idle
// ==/UserScript==

/*
 * Installation :
 *   1. Installez l'extension Tampermonkey (Chrome/Edge/Firefox).
 *   2. Ouvrez ce fichier : Tampermonkey propose l'installation.
 *   3. Sur kdp.amazon.com, un panneau « Tirage » apparaît en bas à droite :
 *      renseignez l'URL de votre studio + le jeton créé dans l'application
 *      (étape 07 → Publier sur Amazon KDP → Créer un jeton d'accès).
 *
 * Amazon fait évoluer son formulaire : les sélecteurs des champs sont
 * regroupés dans FIELD_SELECTORS ci-dessous pour être ajustés facilement.
 */
(function () {
  'use strict';

  // Chaque champ = liste de sélecteurs CSS essayés dans l'ordre (paperback + eBook).
  const FIELD_SELECTORS = {
    title:       ['#data-print-book-title', '#data-ebook-title', 'input[name="data[title]"]', 'input[id*="book-title"]'],
    subtitle:    ['#data-print-book-subtitle', '#data-ebook-subtitle', 'input[id*="subtitle"]'],
    author_first:['#data-print-book-primary-author-first-name', '#data-ebook-primary-author-first-name', 'input[id*="primary-author-first"]'],
    author_last: ['#data-print-book-primary-author-last-name', '#data-ebook-primary-author-last-name', 'input[id*="primary-author-last"]'],
    description: ['#data-print-book-description textarea', '#data-ebook-description textarea', '#cke_data-print-book-description iframe', 'div[id*="description"] div[contenteditable="true"]', 'textarea[id*="description"]'],
    keywords:    ['#data-print-book-keywords-{i}', '#data-ebook-keywords-{i}', 'input[id*="keywords-{i}"]'],
    price:       ['#data-pricing-print-fr-price-input input', '#data-pricing-print-fr-price-input', 'input[id*="price-input"][id*="fr"]', 'input[name*="fr"][name*="price"]'],
    isbn:        ['#data-print-book-isbn', 'input[id*="isbn"]:not([id*="free"])']
  };

  const state = {
    baseUrl: GM_getValue('tirage_base', ''),
    token: GM_getValue('tirage_token', ''),
    projects: [],
    payload: null
  };

  // ── Réseau (GM_xmlhttpRequest contourne le CORS si besoin) ──────────────
  function apiGet(route, params) {
    const query = new URLSearchParams(Object.assign({ r: route, token: state.token }, params || {}));
    const url = state.baseUrl.replace(/\/+$/, '') + '/api.php?' + query.toString();
    return new Promise((resolve, reject) => {
      const handle = text => {
        try {
          const data = JSON.parse(text);
          if (data.ok === false) reject(new Error(data.error || 'Erreur API'));
          else resolve(data);
        } catch (e) { reject(new Error('Réponse illisible du studio.')); }
      };
      if (typeof GM_xmlhttpRequest === 'function') {
        GM_xmlhttpRequest({
          method: 'GET', url,
          onload: r => handle(r.responseText),
          onerror: () => reject(new Error('Studio injoignable — vérifiez l’URL.'))
        });
      } else {
        fetch(url).then(r => r.text()).then(handle).catch(() => reject(new Error('Studio injoignable.')));
      }
    });
  }

  // ── Remplissage des champs (compatible React : setter natif + événements) ─
  function setNativeValue(element, value) {
    const proto = element instanceof HTMLTextAreaElement
      ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype;
    const setter = Object.getOwnPropertyDescriptor(proto, 'value');
    if (setter && setter.set) setter.set.call(element, value);
    else element.value = value;
    ['input', 'change', 'blur'].forEach(type =>
      element.dispatchEvent(new Event(type, { bubbles: true })));
  }

  function findField(selectors) {
    for (const selector of selectors) {
      const el = document.querySelector(selector);
      if (el) return el;
    }
    return null;
  }

  function fillField(key, value, report) {
    if (value == null || value === '') { return; }
    const selectors = FIELD_SELECTORS[key];
    const el = findField(selectors);
    if (!el) { report.push(['✗', key, 'champ introuvable sur cette page']); return; }

    if (el.tagName === 'IFRAME') {
      try {
        const body = el.contentDocument.body;
        body.innerHTML = value;
        body.dispatchEvent(new Event('input', { bubbles: true }));
        report.push(['✓', key, 'rempli (éditeur riche)']);
      } catch (e) { report.push(['✗', key, 'éditeur riche inaccessible']); }
      return;
    }
    if (el.isContentEditable) {
      el.innerHTML = value;
      el.dispatchEvent(new Event('input', { bubbles: true }));
      report.push(['✓', key, 'rempli (zone éditable)']);
      return;
    }
    setNativeValue(el, key === 'description' ? stripHtml(value) : value);
    report.push(['✓', key, 'rempli']);
  }

  function fillKeywords(keywords, report) {
    let filled = 0;
    (keywords || []).forEach((keyword, i) => {
      if (!keyword) return;
      const selectors = FIELD_SELECTORS.keywords.map(s => s.replace('{i}', String(i)));
      const el = findField(selectors);
      if (el) { setNativeValue(el, keyword); filled++; }
    });
    report.push([filled ? '✓' : '✗', 'keywords', filled + '/7 mots-clés remplis']);
  }

  function stripHtml(html) {
    const div = document.createElement('div');
    div.innerHTML = html.replace(/<\/(p|li|ul|br)>/gi, '\n').replace(/<li>/gi, '• ');
    return div.textContent.replace(/\n{3,}/g, '\n\n').trim();
  }

  function fillPage() {
    const p = state.payload;
    if (!p) { setStatus('Choisissez d’abord un projet.', true); return; }
    const report = [];
    fillField('title', p.title, report);
    fillField('subtitle', p.subtitle, report);
    fillField('author_first', p.author && p.author.first_name, report);
    fillField('author_last', p.author && p.author.last_name, report);
    fillField('description', p.description_html, report);
    fillKeywords(p.keywords, report);
    fillField('price', p.price_eur != null ? String(p.price_eur) : '', report);
    fillField('isbn', p.isbn, report);

    const done = report.filter(r => r[0] === '✓').length;
    setStatus(report.map(r => r.join(' ')).join('\n') +
      '\n— ' + done + ' champ(s) rempli(s). Vérifiez chaque valeur, les catégories se choisissent dans l’interface KDP, puis validez vous-même.', done === 0);
  }

  // ── Panneau flottant ────────────────────────────────────────────────────
  const css = `
    #tirage-panel { position: fixed; bottom: 18px; right: 18px; z-index: 999999; width: 320px;
      background: #FFFDF8; border: 1px solid #E2D9C7; border-radius: 14px;
      box-shadow: 0 14px 40px rgba(26,26,23,.25); font-family: system-ui, sans-serif; font-size: 13px; color: #1A1A17; }
    #tirage-panel * { box-sizing: border-box; }
    #tirage-panel .hd { display: flex; align-items: center; gap: 9px; padding: 12px 14px; border-bottom: 1px solid #EFE7D7; cursor: pointer; }
    #tirage-panel .hd .mark { width: 22px; height: 22px; border-radius: 6px; background: #1B2A4A; color: #F4EFE4;
      display: grid; place-items: center; font-family: Georgia, serif; font-size: 14px; }
    #tirage-panel .hd .nm { font-weight: 600; flex: 1; }
    #tirage-panel .bd { padding: 12px 14px; display: none; }
    #tirage-panel.open .bd { display: block; }
    #tirage-panel label { display: block; font-size: 11.5px; color: #6E685C; margin: 8px 0 3px; }
    #tirage-panel input, #tirage-panel select { width: 100%; padding: 7px 9px; border: 1px solid #E2D9C7;
      border-radius: 7px; background: #FAF6EC; font-size: 12.5px; color: #1A1A17; }
    #tirage-panel button { width: 100%; margin-top: 10px; padding: 9px; border: none; border-radius: 8px;
      background: #1B2A4A; color: #F7F2E7; font-size: 13px; cursor: pointer; }
    #tirage-panel button.alt { background: #EAE2D1; color: #1A1A17; }
    #tirage-panel button:hover { opacity: .92; }
    #tirage-panel .st { margin-top: 10px; padding: 9px; border-radius: 8px; background: #F4EFE4;
      font-size: 11.5px; line-height: 1.5; white-space: pre-wrap; max-height: 180px; overflow: auto; }
    #tirage-panel .st.err { background: #FDF3E4; border: 1px solid #E9C9A0; }
  `;

  function buildPanel() {
    const style = document.createElement('style');
    style.textContent = css;
    document.head.appendChild(style);

    const panel = document.createElement('div');
    panel.id = 'tirage-panel';
    panel.innerHTML = `
      <div class="hd"><div class="mark">T</div><div class="nm">Tirage — remplissage KDP</div><div class="tg">▾</div></div>
      <div class="bd">
        <label>URL du studio (ex. https://studio.mondomaine.fr)</label>
        <input id="tirage-url" type="text" placeholder="https://…">
        <label>Jeton d'accès (étape 07 → Publier)</label>
        <input id="tirage-token" type="password" placeholder="jeton hexadécimal">
        <button class="alt" id="tirage-connect">Charger mes projets</button>
        <label>Projet</label>
        <select id="tirage-project"><option value="">—</option></select>
        <button id="tirage-fill">Remplir cette page</button>
        <div class="st" id="tirage-status">Connectez votre studio, choisissez un projet, puis remplissez chaque page du formulaire KDP. Le clic final « Publier » reste manuel, volontairement.</div>
      </div>`;
    document.body.appendChild(panel);

    panel.querySelector('.hd').addEventListener('click', () => panel.classList.toggle('open'));
    panel.querySelector('#tirage-url').value = state.baseUrl;
    panel.querySelector('#tirage-token').value = state.token;

    panel.querySelector('#tirage-connect').addEventListener('click', async () => {
      state.baseUrl = panel.querySelector('#tirage-url').value.trim();
      state.token = panel.querySelector('#tirage-token').value.trim();
      GM_setValue('tirage_base', state.baseUrl);
      GM_setValue('tirage_token', state.token);
      setStatus('Connexion au studio…');
      try {
        const data = await apiGet('kdp/projects');
        state.projects = data.projects;
        const select = panel.querySelector('#tirage-project');
        select.innerHTML = '<option value="">— choisir —</option>' + data.projects.map(p =>
          `<option value="${p.id}">#${p.id} · ${p.title.replace(/</g, '&lt;')}</option>`).join('');
        setStatus(data.projects.length + ' projet(s) chargé(s).');
      } catch (e) { setStatus(e.message, true); }
    });

    panel.querySelector('#tirage-project').addEventListener('change', async event => {
      const id = event.target.value;
      if (!id) return;
      setStatus('Chargement du projet #' + id + '…');
      try {
        const data = await apiGet('kdp/payload', { project: id });
        state.payload = data.payload;
        setStatus('Projet « ' + state.payload.title + ' » prêt. Ouvrez une page du formulaire KDP puis cliquez « Remplir cette page ».');
      } catch (e) { setStatus(e.message, true); }
    });

    panel.querySelector('#tirage-fill').addEventListener('click', fillPage);
  }

  function setStatus(message, isError) {
    const el = document.getElementById('tirage-status');
    if (el) { el.textContent = message; el.classList.toggle('err', !!isError); }
  }

  if (document.body) buildPanel();
  else window.addEventListener('DOMContentLoaded', buildPanel);
})();
