// ==UserScript==
// @name         Tirage — Remplissage auto du formulaire Amazon KDP
// @namespace    tirage-kdp-studio
// @version      2.0.0
// @description  Remplit tout seul le formulaire de publication Amazon KDP (titre, sous-titre, auteur, description, mots-clés, ISBN gratuit, format, fond perdu, papier, finition, prix) depuis vos projets Tirage. Vous choisissez le projet une fois, le reste se remplit page après page. Le clic final « Publier » reste manuel.
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
 *   4. Choisissez votre projet : c'est le seul geste. URL, jeton et projet
 *      sont mémorisés, et chaque page du formulaire se remplit ensuite
 *      automatiquement dès que ses champs apparaissent.
 *
 * Amazon fait évoluer son formulaire en permanence : chaque champ est donc
 * cherché par sélecteur ID *puis* par libellé visible (FR + EN), et le
 * remplissage est rejoué tant que la page bouge (MutationObserver + relances).
 */
(function () {
  'use strict';

  // ── Champs texte : liste de sélecteurs essayés dans l'ordre (broché + eBook)
  const FIELD_SELECTORS = {
    title:       ['#data-print-book-title', '#data-ebook-title', 'input[name="data[title]"]', 'input[id*="book-title"]'],
    subtitle:    ['#data-print-book-subtitle', '#data-ebook-subtitle', 'input[id*="subtitle"]'],
    author_first:['#data-print-book-primary-author-first-name', '#data-ebook-primary-author-first-name', 'input[id*="primary-author-first"]'],
    author_last: ['#data-print-book-primary-author-last-name', '#data-ebook-primary-author-last-name', 'input[id*="primary-author-last"]'],
    description: ['#data-print-book-description textarea', '#data-ebook-description textarea', '#cke_data-print-book-description iframe',
                  'div[id*="description"] div[contenteditable="true"]', 'textarea[id*="description"]'],
    isbn:        ['#data-print-book-isbn', 'input[id*="isbn"]:not([id*="free"])']
  };

  // ── Choix fermés (radios / boutons) : sélecteurs + libellés FR & EN ────────
  // `labels` sert de filet quand Amazon change ses identifiants : on cherche le
  // libellé visible le plus court qui contient l'une de ces expressions.
  const CHOICES = {
    isbn_free: {
      selectors: ['#free-isbn-radio', 'input[type="radio"][id*="free-isbn"]', 'input[type="radio"][id*="free_isbn"]',
                  'input[type="radio"][value="FREE_ISBN"]', '#assign-isbn-button', 'button[id*="free-isbn"]'],
      labels: ['isbn gratuit', 'obtenir un isbn gratuit', 'attribuer un isbn kdp gratuit',
               'free kdp isbn', 'assign me a free kdp isbn', 'get a free kdp isbn'],
      label: 'ISBN gratuit KDP'
    },
    rights_own: {
      selectors: ['#non-public-domain', 'input[type="radio"][id*="non-public-domain"]', 'input[type="radio"][value="NON_PUBLIC_DOMAIN"]'],
      labels: ['je suis titulaire des droits', 'titulaire des droits d’auteur', 'titulaire des droits d\'auteur',
               'i own the copyright', 'this is not a public domain work'],
      label: 'Droits : œuvre originale'
    },
    adult_no: {
      selectors: ['#data-print-book-adult-content-false', '#data-ebook-adult-content-false',
                  'input[type="radio"][id*="adult-content-false"]', 'input[type="radio"][id*="adult"][value="false"]'],
      labels: ['non, ce livre ne contient', 'no, this book does not contain'],
      label: 'Contenu adulte : non'
    },
    bleed_with: {
      selectors: ['#data-print-book-bleed-with-bleed', 'input[type="radio"][id*="with-bleed"]',
                  'input[type="radio"][value="WITH_BLEED"]'],
      labels: ['fond perdu (pdf uniquement)', 'avec fond perdu', 'bleed (pdf only)', 'with bleed'],
      label: 'Fond perdu : avec'
    },
    bleed_without: {
      selectors: ['#data-print-book-bleed-no-bleed', 'input[type="radio"][id*="no-bleed"]',
                  'input[type="radio"][value="NO_BLEED"]'],
      labels: ['sans fond perdu', 'no bleed'],
      label: 'Fond perdu : sans'
    },
    finish_matte: {
      selectors: ['#data-print-book-cover-finish-matte', 'input[type="radio"][id*="cover-finish-matte"]',
                  'input[type="radio"][value="MATTE"]'],
      labels: ['mat', 'matte'],
      label: 'Finition : mate'
    },
    finish_glossy: {
      selectors: ['#data-print-book-cover-finish-glossy', 'input[type="radio"][id*="cover-finish-glossy"]',
                  'input[type="radio"][value="GLOSSY"]'],
      labels: ['brillant', 'glossy'],
      label: 'Finition : brillante'
    },
    ink_bw_white: {
      selectors: ['#data-print-book-color-and-paper-black-and-white-interior-with-white-paper',
                  'input[type="radio"][id*="black-and-white"][id*="white-paper"]',
                  'input[type="radio"][value="BLACK_AND_WHITE_INTERIOR_WITH_WHITE_PAPER"]'],
      labels: ['noir et blanc avec papier blanc', 'black & white interior with white paper',
               'black and white interior with white paper'],
      label: 'Impression : N&B, papier blanc'
    },
    ink_bw_cream: {
      selectors: ['#data-print-book-color-and-paper-black-and-white-interior-with-cream-paper',
                  'input[type="radio"][id*="black-and-white"][id*="cream-paper"]',
                  'input[type="radio"][value="BLACK_AND_WHITE_INTERIOR_WITH_CREAM_PAPER"]'],
      labels: ['noir et blanc avec papier crème', 'black & white interior with cream paper',
               'black and white interior with cream paper'],
      label: 'Impression : N&B, papier crème'
    },
    ink_color_white: {
      selectors: ['#data-print-book-color-and-paper-standard-color-interior-with-white-paper',
                  'input[type="radio"][id*="standard-color"][id*="white-paper"]',
                  'input[type="radio"][value="STANDARD_COLOR_INTERIOR_WITH_WHITE_PAPER"]'],
      labels: ['couleur standard avec papier blanc', 'standard color interior with white paper'],
      label: 'Impression : couleur, papier blanc'
    }
  };

  // Noms lisibles des champs, pour le bilan affiché dans le panneau.
  const FIELD_LABELS = {
    title: 'Titre', subtitle: 'Sous-titre', author_first: 'Prénom auteur',
    author_last: 'Nom auteur', description: 'Description', isbn: 'ISBN'
  };

  // Libellés des formats d'impression, pour cocher le bon gabarit de page.
  const TRIM_LABELS = {
    '6x9':  ['6 x 9', '6x9', '15,24 x 22,86', '15.24 x 22.86'],
    '5x8':  ['5 x 8', '5x8', '12,7 x 20,32', '12.7 x 20.32'],
    '7x10': ['7 x 10', '7x10', '17,78 x 25,4', '17.78 x 25.4']
  };

  const state = {
    baseUrl: GM_getValue('tirage_base', ''),
    token: GM_getValue('tirage_token', ''),
    projectId: GM_getValue('tirage_project', ''),
    auto: GM_getValue('tirage_auto', true) !== false,
    projects: [],
    payload: null,
    report: [],         // dernier bilan affiché
    filledKeys: {},     // clés déjà remplies pour l'URL courante
    counts: {},         // nombre de champs QUE NOUS avons remplis, par clé
    url: location.href,
    timer: null,
    deadline: 0
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

  function isVisible(el) {
    if (!el) return false;
    if (el.disabled) return false;
    const rect = el.getBoundingClientRect ? el.getBoundingClientRect() : null;
    if (rect && rect.width === 0 && rect.height === 0) {
      // Les radios KDP sont parfois masqués derrière un habillage : on les
      // accepte quand même s'ils sont dans le flux du document.
      return el.type === 'radio' || el.type === 'checkbox';
    }
    return true;
  }

  function findField(selectors) {
    for (const selector of selectors) {
      for (const el of document.querySelectorAll(selector)) {
        if (isVisible(el) && !inPanel(el)) return el;
      }
    }
    return null;
  }

  /** Ne jamais remplir nos propres champs (URL du studio, jeton…). */
  function inPanel(el) {
    const panel = document.getElementById('tirage-panel');
    return !!(panel && panel.contains(el));
  }

  /** Le champ est-il déjà rempli (par KDP, par vous, ou par nous) ? */
  function hasValue(el) {
    if (!el) return false;
    if (el.tagName === 'IFRAME') {
      try { return (el.contentDocument.body.textContent || '').trim().length > 3; } catch (e) { return false; }
    }
    if (el.isContentEditable) return (el.textContent || '').trim().length > 3;
    return String(el.value || '').trim() !== '';
  }

  function name(key) { return FIELD_LABELS[key] || key; }

  function fillField(key, value) {
    if (value == null || value === '' || state.filledKeys[key]) return;
    const el = findField(FIELD_SELECTORS[key]);
    if (!el) return;                          // champ absent : il est sur une autre page
    if (hasValue(el)) { note(key, '=', name(key) + ' — déjà renseigné, laissé tel quel'); return; }

    if (el.tagName === 'IFRAME') {
      try {
        const body = el.contentDocument.body;
        body.innerHTML = value;
        body.dispatchEvent(new Event('input', { bubbles: true }));
        note(key, '✓', name(key) + ' — rempli (éditeur riche)');
      } catch (e) { note(key, '✗', name(key) + ' — éditeur riche inaccessible'); }
      return;
    }
    if (el.isContentEditable) {
      el.innerHTML = value;
      el.dispatchEvent(new Event('input', { bubbles: true }));
      note(key, '✓', name(key) + ' — rempli (zone éditable)');
      return;
    }
    setNativeValue(el, key === 'description' ? stripHtml(value) : value);
    note(key, '✓', name(key) + ' — rempli');
  }

  /** Les 7 champs de mots-clés, repérés par ID puis à défaut en ordre DOM. */
  function fillKeywords(keywords) {
    const list = (keywords || []).filter(k => k && String(k).trim() !== '');
    if (!list.length || state.filledKeys.keywords) return;

    let inputs = [];
    for (let i = 0; i < 7; i++) {
      const el = findField([
        '#data-print-book-keywords-' + i, '#data-ebook-keywords-' + i, 'input[id*="keywords-' + i + '"]'
      ]);
      if (el) inputs.push(el);
    }
    if (!inputs.length) {
      // Repli : tout champ texte dont l'id/le nom/le libellé évoque un mot-clé.
      inputs = Array.from(document.querySelectorAll('input[type="text"], input:not([type])'))
        .filter(el => isVisible(el) && !inPanel(el) && /keyword|mot.?cl/i.test(
          (el.id || '') + ' ' + (el.name || '') + ' ' + (el.getAttribute('aria-label') || '') + ' ' + (el.placeholder || '')));
    }
    if (!inputs.length) return;

    const wanted = Math.min(list.length, 7);
    let filled = 0, kept = 0;
    inputs.slice(0, 7).forEach((el, i) => {
      if (!list[i]) return;
      if (hasValue(el)) { kept++; return; }
      setNativeValue(el, list[i]);
      filled++;
    });
    if (!filled && !kept) return;
    state.counts.keywords = (state.counts.keywords || 0) + filled;
    // Verrouillé seulement quand les 7 champs ont été traités : KDP les
    // affiche parfois progressivement.
    note('keywords', state.counts.keywords ? '✓' : '=',
      state.counts.keywords + '/' + wanted + ' mots-clés remplis'
      + (filled + kept > state.counts.keywords ? ' · ' + (filled + kept - state.counts.keywords) + ' déjà saisi(s)' : ''),
      filled + kept >= wanted);
  }

  /**
   * Coche une option fermée : d'abord par sélecteur, sinon en repérant son
   * libellé visible. Amazon fait évoluer le formulaire, cette double approche
   * encaisse les changements d'identifiants.
   */
  function pickChoice(key) {
    const choice = CHOICES[key];
    if (!choice || state.filledKeys[key]) return false;

    const direct = findField(choice.selectors);
    if (direct) {
      if (direct.type === 'radio' || direct.type === 'checkbox') {
        if (direct.checked) { note(key, '=', choice.label + ' — déjà coché'); return true; }
        direct.click();
      } else {
        direct.click();
      }
      note(key, '✓', choice.label);
      return true;
    }
    const hit = findByLabel(choice.labels);
    if (!hit) return false;
    clickOption(hit);
    note(key, '✓', choice.label + ' (via le libellé)');
    return true;
  }

  /**
   * Élément dont le texte visible contient l'un des libellés donnés, en
   * MOTS ENTIERS : « Mat » ne doit pas se déclencher sur « matinées » ni sur
   * « maintenant ». Notre propre panneau est évidemment exclu de la recherche.
   */
  function findByLabel(labels, maxLength) {
    const limit = maxLength || 120;
    const panel = document.getElementById('tirage-panel');
    const candidates = document.querySelectorAll('label, button, span, div[role="radio"], div[role="button"], li');
    for (const el of candidates) {
      if (panel && panel.contains(el)) continue;
      const text = (el.textContent || '').trim().toLowerCase().replace(/\s+/g, ' ');
      if (!text || text.length > limit) continue;
      if (!labels.some(l => wordMatch(text, l))) continue;
      if (!isVisible(el)) continue;
      return el;
    }
    return null;
  }

  /** Le libellé apparaît-il en mots entiers dans ce texte ? */
  function wordMatch(text, label) {
    const escaped = String(label).toLowerCase().replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    try {
      return new RegExp('(^|[^\\p{L}\\p{N}])' + escaped + '($|[^\\p{L}\\p{N}])', 'u').test(text);
    } catch (e) {
      // Navigateur sans classes Unicode dans les regex : repli ASCII.
      return new RegExp('(^|[^a-z0-9àâäéèêëïîôöùûüç])' + escaped + '($|[^a-z0-9àâäéèêëïîôöùûüç])').test(text);
    }
  }

  function clickOption(el) {
    const input = el.querySelector && el.querySelector('input[type="radio"], input[type="checkbox"]');
    const linked = el.htmlFor ? document.getElementById(el.htmlFor) : null;
    const target = input || linked || el;
    if ((target.type === 'radio' || target.type === 'checkbox') && target.checked) return;
    target.click();
  }

  /** Format d'impression (6×9 par défaut) : bouton/radio portant la mesure. */
  function pickTrim(trim) {
    if (state.filledKeys.trim) return;
    const labels = TRIM_LABELS[trim] || TRIM_LABELS['6x9'];
    const direct = findField([
      'input[type="radio"][id*="trim-size"][id*="' + trim.replace('x', '-') + '"]',
      'input[type="radio"][value*="' + trim.toUpperCase() + '"]'
    ]);
    if (direct) {
      if (!direct.checked) direct.click();
      note('trim', '✓', 'Format ' + trim.replace('x', ' × ') + ' po');
      return;
    }
    const hit = findByLabel(labels.map(l => l.toLowerCase()), 60);
    if (!hit) return;
    clickOption(hit);
    note('trim', '✓', 'Format ' + trim.replace('x', ' × ') + ' po (via le libellé)');
  }

  /** Prix : un champ par boutique Amazon, repéré par son code marché. */
  function fillPrices(prices) {
    if (!prices || state.filledKeys.prices) return;
    let filled = 0, kept = 0, found = 0;
    Object.keys(prices).forEach(code => {
      const holder = document.querySelector('#data-pricing-print-' + code + '-price-input')
        || document.querySelector('#data-pricing-ebook-' + code + '-price-input')
        || document.querySelector('[id*="pricing"][id*="-' + code + '-"][id*="price"]');
      if (!holder) return;
      const input = holder.tagName === 'INPUT' ? holder : holder.querySelector('input');
      if (!input || !isVisible(input)) return;
      found++;
      if (hasValue(input)) { kept++; return; }
      setNativeValue(input, String(prices[code].value).replace('.', ','));
      filled++;
    });
    if (!found) return;
    state.counts.prices = (state.counts.prices || 0) + filled;
    // Jamais verrouillé : KDP affiche les boutiques au fil du calcul des
    // redevances, la passe suivante remplira celles qui viennent d'arriver.
    note('prices', state.counts.prices ? '✓' : '=',
      state.counts.prices + ' prix rempli(s) sur ' + found + ' boutique(s) affichée(s)'
      + (kept > state.counts.prices ? ' · ' + (kept - state.counts.prices) + ' déjà saisi(s)' : ''), false);
  }

  function stripHtml(html) {
    const div = document.createElement('div');
    div.innerHTML = html.replace(/<\/(p|li|ul|br)>/gi, '\n').replace(/<li>/gi, '• ');
    return div.textContent.replace(/\n{3,}/g, '\n\n').trim();
  }

  /**
   * Journalise un champ traité. `lock` à false laisse la passe suivante
   * retenter : indispensable pour les listes (mots-clés, prix) dont KDP
   * n'affiche parfois qu'une partie des champs au premier passage.
   */
  function note(key, mark, message, lock) {
    if (lock !== false) state.filledKeys[key] = true;
    state.report = state.report.filter(r => r.key !== key);
    state.report.push({ key, mark, message });
    renderStatus();
  }

  // ── Moteur de remplissage ───────────────────────────────────────────────
  /**
   * Passe unique : remplit tout ce qui est présent à cet instant. Les champs
   * absents sont ignorés silencieusement (ils sont sur une autre page du
   * formulaire, où la passe suivante les trouvera).
   */
  function runPass() {
    const p = state.payload;
    if (!p) return;

    fillField('title', p.title);
    fillField('subtitle', p.subtitle);
    fillField('author_first', p.author && p.author.first_name);
    fillField('author_last', p.author && p.author.last_name);
    fillField('description', p.description_html);
    fillKeywords(p.keywords);

    pickChoice('rights_own');
    pickChoice('adult_no');

    if (p.isbn_mode === 'own' && p.isbn) fillField('isbn', p.isbn);
    else pickChoice('isbn_free');

    pickTrim(p.trim || '6x9');
    pickChoice(p.bleed ? 'bleed_with' : 'bleed_without');
    pickChoice(p.cover_finish === 'glossy' ? 'finish_glossy' : 'finish_matte');
    if (p.ink === 'color') pickChoice('ink_color_white');
    else pickChoice(p.paper === 'cream' ? 'ink_bw_cream' : 'ink_bw_white');

    fillPrices(p.prices);
    renderStatus();
  }

  /**
   * Surveille la page : KDP construit son formulaire après coup et navigue
   * sans rechargement. On rejoue donc le remplissage à chaque mutation, et
   * on repart de zéro dès que l'URL change.
   */
  function watch() {
    const observer = new MutationObserver(() => schedule(4000));
    observer.observe(document.documentElement, { childList: true, subtree: true });

    // Navigation SPA : pushState / replaceState / retour arrière.
    ['pushState', 'replaceState'].forEach(method => {
      const original = history[method];
      history[method] = function () {
        const result = original.apply(this, arguments);
        window.dispatchEvent(new Event('tirage:navigation'));
        return result;
      };
    });
    window.addEventListener('popstate', () => window.dispatchEvent(new Event('tirage:navigation')));
    window.addEventListener('tirage:navigation', onNavigation);
    setInterval(() => { if (location.href !== state.url) onNavigation(); }, 700);

    schedule(12000);
  }

  function onNavigation() {
    state.url = location.href;
    state.filledKeys = {};
    state.report = [];
    state.counts = {};
    schedule(12000);
  }

  /** Relance le remplissage pendant `ms` millisecondes (champs asynchrones). */
  function schedule(ms) {
    if (!state.auto || !state.payload) return;
    state.deadline = Math.max(state.deadline, Date.now() + ms);
    if (state.timer) return;
    state.timer = setInterval(() => {
      runPass();
      if (Date.now() > state.deadline) { clearInterval(state.timer); state.timer = null; }
    }, 600);
    runPass();
  }

  // ── Panneau flottant ────────────────────────────────────────────────────
  const css = `
    #tirage-panel { position: fixed; bottom: 18px; right: 18px; z-index: 999999; width: 330px;
      background: #FFFDF8; border: 1px solid #E2D9C7; border-radius: 14px;
      box-shadow: 0 14px 40px rgba(26,26,23,.25); font-family: system-ui, sans-serif; font-size: 13px; color: #1A1A17; }
    #tirage-panel * { box-sizing: border-box; }
    #tirage-panel .hd { display: flex; align-items: center; gap: 9px; padding: 12px 14px; border-bottom: 1px solid #EFE7D7; cursor: pointer; }
    #tirage-panel .hd .mark { width: 22px; height: 22px; border-radius: 6px; background: #1B2A4A; color: #F4EFE4;
      display: grid; place-items: center; font-family: Georgia, serif; font-size: 14px; }
    #tirage-panel .hd .nm { font-weight: 600; flex: 1; }
    #tirage-panel .hd .dot { width: 8px; height: 8px; border-radius: 50%; background: #C9C2B4; }
    #tirage-panel .hd .dot.on { background: #2E7D5B; }
    #tirage-panel .bd { padding: 12px 14px; display: none; }
    #tirage-panel.open .bd { display: block; }
    #tirage-panel label { display: block; font-size: 11.5px; color: #6E685C; margin: 8px 0 3px; }
    #tirage-panel input, #tirage-panel select { width: 100%; padding: 7px 9px; border: 1px solid #E2D9C7;
      border-radius: 7px; background: #FAF6EC; font-size: 12.5px; color: #1A1A17; }
    #tirage-panel .row { display: flex; align-items: center; gap: 8px; margin-top: 10px; font-size: 12px; color: #4A463D; }
    #tirage-panel .row input { width: auto; }
    #tirage-panel button { width: 100%; margin-top: 10px; padding: 9px; border: none; border-radius: 8px;
      background: #1B2A4A; color: #F7F2E7; font-size: 13px; cursor: pointer; }
    #tirage-panel button.alt { background: #EAE2D1; color: #1A1A17; }
    #tirage-panel button:hover { opacity: .92; }
    #tirage-panel .setup { display: none; }
    #tirage-panel.setup-open .setup { display: block; }
    #tirage-panel .link { margin-top: 10px; font-size: 11.5px; color: #1B2A4A; cursor: pointer; text-decoration: underline; }
    #tirage-panel .st { margin-top: 10px; padding: 9px; border-radius: 8px; background: #F4EFE4;
      font-size: 11.5px; line-height: 1.55; white-space: pre-wrap; max-height: 220px; overflow: auto; }
    #tirage-panel .st.err { background: #FDF3E4; border: 1px solid #E9C9A0; }
    #tirage-panel .cats { margin-top: 8px; font-size: 11.5px; color: #4A463D; }
    #tirage-panel .cats b { display: block; color: #6E685C; font-weight: 500; margin-bottom: 3px; }
  `;

  function buildPanel() {
    const style = document.createElement('style');
    style.textContent = css;
    document.head.appendChild(style);

    const panel = document.createElement('div');
    panel.id = 'tirage-panel';
    panel.innerHTML = `
      <div class="hd"><div class="mark">T</div><div class="nm">Tirage — remplissage KDP</div><div class="dot" id="tirage-dot"></div></div>
      <div class="bd">
        <label>Projet à publier</label>
        <select id="tirage-project"><option value="">— chargement… —</option></select>
        <div class="row"><input type="checkbox" id="tirage-auto"><span>Remplir automatiquement chaque page</span></div>
        <button id="tirage-fill">Remplir cette page maintenant</button>
        <div class="link" id="tirage-setup-toggle">Réglages de connexion</div>
        <div class="setup">
          <label>URL du studio (ex. https://studio.mondomaine.fr)</label>
          <input id="tirage-url" type="text" placeholder="https://…">
          <label>Jeton d'accès (étape 07 → Publier)</label>
          <input id="tirage-token" type="password" placeholder="jeton hexadécimal">
          <button class="alt" id="tirage-connect">Reconnecter</button>
        </div>
        <div class="st" id="tirage-status">Connexion au studio…</div>
        <div class="cats" id="tirage-cats"></div>
      </div>`;
    document.body.appendChild(panel);

    panel.querySelector('.hd').addEventListener('click', () => panel.classList.toggle('open'));
    panel.querySelector('#tirage-url').value = state.baseUrl;
    panel.querySelector('#tirage-token').value = state.token;
    panel.querySelector('#tirage-auto').checked = state.auto;

    panel.querySelector('#tirage-setup-toggle').addEventListener('click', () => panel.classList.toggle('setup-open'));

    panel.querySelector('#tirage-auto').addEventListener('change', event => {
      state.auto = event.target.checked;
      GM_setValue('tirage_auto', state.auto);
      if (state.auto) schedule(8000);
    });

    panel.querySelector('#tirage-connect').addEventListener('click', () => {
      state.baseUrl = panel.querySelector('#tirage-url').value.trim();
      state.token = panel.querySelector('#tirage-token').value.trim();
      GM_setValue('tirage_base', state.baseUrl);
      GM_setValue('tirage_token', state.token);
      connect();
    });

    panel.querySelector('#tirage-project').addEventListener('change', event => {
      selectProject(event.target.value);
    });

    panel.querySelector('#tirage-fill').addEventListener('click', () => {
      state.filledKeys = {};
      state.report = [];
      state.counts = {};
      if (!state.payload) { setStatus('Choisissez d’abord un projet.', true); return; }
      runPass();
    });
  }

  /** Charge la liste des projets puis, si un projet est mémorisé, son payload. */
  async function connect() {
    const panel = document.getElementById('tirage-panel');
    if (!state.baseUrl || !state.token) {
      panel.classList.add('open', 'setup-open');
      setStatus('Renseignez l’URL de votre studio et votre jeton d’accès : ils ne vous seront plus demandés ensuite.', true);
      return;
    }
    setStatus('Connexion au studio…');
    try {
      const data = await apiGet('kdp/projects');
      state.projects = data.projects || [];
      const select = document.getElementById('tirage-project');
      select.innerHTML = '<option value="">— choisir un projet —</option>' + state.projects.map(p =>
        `<option value="${p.id}">#${p.id} · ${String(p.title).replace(/</g, '&lt;')}</option>`).join('');
      if (state.projectId && state.projects.some(p => String(p.id) === String(state.projectId))) {
        select.value = String(state.projectId);
        await selectProject(state.projectId, true);
      } else {
        panel.classList.add('open');
        setStatus(state.projects.length + ' projet(s) disponible(s) — choisissez celui à publier, tout le reste se remplira tout seul.');
      }
    } catch (e) {
      panel.classList.add('open', 'setup-open');
      setStatus(e.message, true);
    }
  }

  async function selectProject(id, silent) {
    if (!id) { state.payload = null; return; }
    state.projectId = id;
    GM_setValue('tirage_project', String(id));
    if (!silent) setStatus('Chargement du projet #' + id + '…');
    try {
      const data = await apiGet('kdp/payload', { project: id });
      state.payload = data.payload;
      state.filledKeys = {};
      state.report = [];
      state.counts = {};
      renderCategories();
      document.getElementById('tirage-dot').classList.add('on');
      schedule(12000);
      renderStatus();
    } catch (e) { setStatus(e.message, true); }
  }

  /** Bilan vivant : ce qui a été rempli + ce qui reste à faire à la main. */
  function renderStatus() {
    const p = state.payload;
    if (!p) return;
    const lines = state.report.map(r => r.mark + ' ' + r.message);
    const done = state.report.filter(r => r.mark === '✓').length;
    const head = 'Projet « ' + p.title +' » · ' + (state.auto ? 'remplissage automatique actif' : 'remplissage manuel');
    const seen = key => state.report.some(r => r.key === key);
    const rest = [];
    if (!seen('prices')) rest.push('• Prix : rempli dès que vous arriverez sur « Tarification »');
    if (!seen('isbn_free') && !seen('isbn')) rest.push('• ISBN gratuit : coché dès que vous arriverez sur « Contenu »');
    if (p.categories && p.categories.length) rest.push('• Catégories : à choisir dans la fenêtre KDP (rappel ci-dessous)');
    const tail = '\nRappel impression : ' + (p.trim || '6x9').replace('x', ' × ') + ' po · ' + (p.pages || '?') + ' pages · '
      + (p.bleed ? 'AVEC fond perdu' : 'sans fond perdu') + ' · '
      + (p.ink === 'color' ? 'couleur' : 'noir & blanc') + ' papier ' + (p.paper === 'cream' ? 'crème' : 'blanc') + ' · finition '
      + (p.cover_finish === 'glossy' ? 'brillante' : 'mate');
    setStatus(head + '\n' + (lines.length ? lines.join('\n') : 'En attente des champs de cette page…')
      + (rest.length ? '\nSur les autres pages :\n' + rest.join('\n') : '')
      + tail + '\n— ' + done + ' champ(s) rempli(s) ici. Le clic « Publier » reste le vôtre.');
  }

  function renderCategories() {
    const el = document.getElementById('tirage-cats');
    const cats = (state.payload && state.payload.categories) || [];
    el.innerHTML = cats.length
      ? '<b>Catégories à saisir dans KDP :</b>' + cats.map(c => '• ' + String(c).replace(/</g, '&lt;')).join('<br>')
      : '';
  }

  function setStatus(message, isError) {
    const el = document.getElementById('tirage-status');
    if (el) { el.textContent = message; el.classList.toggle('err', !!isError); }
  }

  function boot() {
    buildPanel();
    watch();
    connect();
  }

  if (document.body) boot();
  else window.addEventListener('DOMContentLoaded', boot);
})();
