/* ============================================================================
   Tirage — KDP Studio · application (vanilla JS)
   Parcours en 7 étapes : Niche → Concept → Sommaire → Couverture →
   Rédaction → Chapitres → Mise en page
   ========================================================================== */
(function () {
  'use strict';

  // Numéro de build — affiché dans ⚡ Connecteurs pour vérifier que la bonne
  // version est bien chargée (utile en cas de cache navigateur récalcitrant).
  const BUILD = '2026-08-16 · c25';

  const STEPS = ['Niche', 'Concept', 'Sommaire', 'Couverture', 'Rédaction', 'Chapitres', 'Mise en page'];
  const TONES = ['Pratique et direct', 'Chaleureux', 'Analytique', 'Narratif'];
  const PHOTO_STYLES = [
    { key: 'nb', label: 'Noir & blanc' },
    { key: 'couleur', label: 'Couleur' },
    { key: 'schemas', label: 'Schémas seuls' }
  ];
  const IDEA_CHIPS = ['télétravail', 'parents pressés', 'sans se lever à 5 h', 'charge mentale', '21 jours'];
  const SIGNALS = [
    'Rang des ventes (BSR) des 100 premiers titres de la catégorie',
    'Volume de recherche des mots-clés et suggestions Amazon',
    'Nombre de nouveautés publiées sur 90 jours',
    'Avis 1–3 étoiles : ce que les lecteurs reprochent aux livres existants'
  ];

  const S = {
    app: { name: 'Tirage' },
    user: null,
    view: 'boot',              // boot | login | dashboard | wizard
    projects: [],
    project: null,
    bundle: { themes: { analysis: [], trends: [] }, concepts: [], toc: [], trims: {} },
    step: 1,
    busy: {},                  // indicateurs de chargement par clé
    cover: null,
    coverTemplates: [],
    writer: { status: null, journal: [], lastId: 0, looping: false, incident: null },
    reader: { num: 1, data: null },
    layout: null,
    modal: null
  };

  const root = document.getElementById('app');
  const esc = s => String(s == null ? '' : s)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  const nf = n => Number(n || 0).toLocaleString('fr-FR');
  const pad2 = n => String(n).padStart(2, '0');
  const debounces = {};
  function debounce(key, fn, ms) {
    clearTimeout(debounces[key]);
    debounces[key] = setTimeout(fn, ms || 600);
  }

  // Encadrés éditoriaux : mêmes types que côté serveur (Util::CALLOUTS)
  const CALLOUT_LABELS = {
    retenir: 'À retenir', chiffre: 'Chiffre clé', conseil: 'Conseil',
    exemple: 'Exemple', faq: 'Question fréquente', attention: 'Attention'
  };

  /** Parse un contenu de section en blocs {t:'p'|'list'|'call', …} (miroir PHP Util::blocks). */
  function parseBlocks(text) {
    const lines = String(text || '').replace(/\r\n/g, '\n').trim().split('\n');
    const blocks = [];
    let buffer = [];
    let callout = null;
    const flush = () => {
      const chunk = buffer.join('\n').trim();
      buffer = [];
      if (!chunk) return;
      chunk.split(/\n\s*\n/).forEach(paragraph => {
        const rows = paragraph.split('\n').map(r => r.trim()).filter(Boolean);
        const listRows = rows.filter(r => /^[–\-•]\s+/.test(r));
        if (rows.length > 1 && listRows.length >= Math.max(1, Math.floor(rows.length * 0.6))) {
          blocks.push({ t: 'list', items: rows.map(r => r.replace(/^[–\-•]\s+/, '')).filter(Boolean) });
        } else {
          blocks.push({ t: 'p', text: paragraph.replace(/\n/g, ' ') });
        }
      });
    };
    for (const line of lines) {
      const trimmed = line.trim();
      if (!callout && /^#{2,4}\s+/.test(trimmed)) {
        flush();
        blocks.push({ t: 'h', text: trimmed.replace(/^#{2,4}\s+/, '').replace(/\s*#+\s*$/, '').trim() });
        continue;
      }
      const open = trimmed.match(/^:::\s*([a-zé]+)\s*$/);
      if (!callout && open && (CALLOUT_LABELS[open[1]] || open[1] === 'tableau')) {
        flush();
        callout = { kind: open[1], lines: [] };
        continue;
      }
      if (callout && /^:::\s*$/.test(trimmed)) {
        const content = callout.lines.join('\n').trim();
        if (callout.kind === 'tableau') {
          const rows = content.split('\n').map(r => r.trim()).filter(Boolean)
            .map(r => r.split('|').map(c => c.trim())).filter(cells => cells.length >= 2);
          if (rows.length >= 2) blocks.push({ t: 'table', head: rows.shift(), rows });
          else if (content) blocks.push({ t: 'p', text: content.replace(/\n/g, ' ') });
          callout = null;
          continue;
        }
        if (content) blocks.push({ t: 'call', kind: callout.kind, text: content });
        callout = null;
        continue;
      }
      if (callout) callout.lines.push(line);
      else buffer.push(line);
    }
    if (callout) {
      const content = callout.lines.join('\n').trim();
      if (content) blocks.push({ t: 'call', kind: callout.kind, text: content });
    }
    flush();
    return blocks;
  }

  function blocksHtml(content) {
    return parseBlocks(content).map(block => {
      if (block.t === 'call') {
        return `<div class="reader-callout k-${esc(block.kind)}">
          <div class="rc-label">${esc(CALLOUT_LABELS[block.kind] || block.kind)}</div>
          ${block.text.split(/\n\s*\n/).map(p => `<p>${esc(p.trim())}</p>`).join('')}
        </div>`;
      }
      if (block.t === 'list') {
        return `<ul class="reader-list">${block.items.map(i => `<li>${esc(i)}</li>`).join('')}</ul>`;
      }
      if (block.t === 'h') {
        return `<h4 class="reader-subhead">${esc(block.text)}</h4>`;
      }
      if (block.t === 'table') {
        return `<table class="reader-table"><thead><tr>${block.head.map(c => `<th>${esc(c)}</th>`).join('')}</tr></thead><tbody>${block.rows.map(r => `<tr>${r.map(c => `<td>${esc(c)}</td>`).join('')}</tr>`).join('')}</tbody></table>`;
      }
      return `<p>${esc(block.text)}</p>`;
    }).join('');
  }

  let toastTimer = null;
  function toast(message, isError) {
    document.querySelectorAll('.toast').forEach(t => t.remove());
    const el = document.createElement('div');
    el.className = 'toast' + (isError ? ' error' : '');
    el.textContent = message;
    document.body.appendChild(el);
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => el.remove(), isError ? 6000 : 3200);
  }

  function setBusy(key, value) { S.busy[key] = value; render(); }

  // ── Démarrage ────────────────────────────────────────────────────────────

  async function boot() {
    try {
      const me = await Api.get('auth/me');
      S.app = me.app || S.app;
      if (me.user) {
        S.user = me.user;
        Api.setCsrf(me.csrf);
        await loadProjects();
        S.view = 'dashboard';
      } else {
        S.view = 'login';
      }
    } catch (e) {
      S.view = 'login';
      S.bootError = e.message;
    }
    render();
  }

  async function loadProjects() {
    const data = await Api.get('projects/list');
    S.projects = data.projects;
  }

  async function openProject(id, step) {
    setBusy('open', true);
    try {
      const data = await Api.get('projects/get', { id });
      S.project = data.project;
      S.bundle = { themes: data.themes, concepts: data.concepts, toc: data.toc, trims: data.trims };
      S.step = Math.min(step || Number(data.project.step) || 1, 7);
      S.view = 'wizard';
      S.writer = { status: null, journal: [], lastId: 0, looping: false, incident: null };
      S.reader = { num: 1, data: null };
      S.layout = null;
      // Tout l'état lié au livre PRÉCÉDENT est vidé : couverture, propositions
      // et graine de variantes, thèmes/composeur de mise en page, métadonnées
      // KDP, rangs de mots-clés, éditions en cours. Sans cela, un livre
      // affichait les propositions et réglages d'un autre.
      S.cover = null;
      S.coverVariants = null;
      S.coverSeed = 1;
      S.coverFace = 'front';
      S.coverElsFront = null;
      S.coverElsBack = null;
      S.coverLibrary = null;
      S.coverCustom = null;
      S.importAnalysis = null;
      S.importMode = 'identique';
      S.importOwned = false;
      S.editorEls = null;
      S.interiorThemes = null;
      S.layoutOptions = null;
      S.layoutColors = null;
      S.coverPaletteRef = null;
      S.layoutStamp = 0;
      S.kdpMeta = null;
      S.keywordRanks = null;
      S.sectionEdit = null;
      S.sectionRetouch = null;
      S.imageGen = null;
      S.modal = null;
      enterStep();
    } catch (e) {
      toast(e.message, true);
    }
    setBusy('open', false);
  }

  async function refreshProject() {
    const data = await Api.get('projects/get', { id: S.project.id });
    S.project = data.project;
    S.bundle = { themes: data.themes, concepts: data.concepts, toc: data.toc, trims: data.trims };
  }

  function goStep(n) {
    if (!S.project) return;
    if (n > Number(S.project.step)) return;
    S.step = n;
    enterStep();
    render();
    window.scrollTo(0, 0);
  }

  function enterStep() {
    if (S.step === 4) loadCover();
    if (S.step === 5) enterWriting();
    if (S.step === 6) loadChapter(S.reader.num || 1);
    if (S.step === 7) loadLayout();
  }

  // ── Rendu principal ──────────────────────────────────────────────────────

  function render() {
    if (S.view === 'boot') return;
    if (S.view === 'login') { root.innerHTML = loginView(); return; }
    if (S.view === 'dashboard') { root.innerHTML = dashboardView(); return; }
    if (S.view === 'watch') { root.innerHTML = watchView(); return; }
    root.innerHTML = wizardView();
    afterRender();
  }

  function afterRender() {
    if (S.step === 4 && S.editorEls) Editor.init();
    const log = document.getElementById('console-log');
    if (log) log.scrollTop = log.scrollHeight;
  }

  // ── Vue : connexion ──────────────────────────────────────────────────────

  function loginView() {
    return `
    <div class="auth-wrap">
      <div class="auth-card">
        <div class="auth-brand"><div class="logo-mark">T</div><div class="logo-name">${esc(S.app.name)}</div></div>
        <h1 class="serif" style="font-size:30px; margin:18px 0 6px;">Connexion</h1>
        <p class="muted" style="margin:0;">Votre studio d'écriture pour Amazon KDP.</p>
        ${S.bootError ? `<div class="note note-warn">! ${esc(S.bootError)}</div>` : ''}
        <form class="auth-form" onsubmit="App.login(event)">
          <label>Adresse e-mail<input type="email" id="login-email" required autocomplete="username"></label>
          <label>Mot de passe<input type="password" id="login-pass" required autocomplete="current-password"></label>
          <button class="btn btn-primary" type="submit" ${S.busy.login ? 'disabled' : ''}>
            ${S.busy.login ? '<span class="spinner"></span>' : ''} Se connecter
          </button>
        </form>
      </div>
    </div>`;
  }

  // ── Vue : projets ────────────────────────────────────────────────────────

  function dashboardView() {
    const cards = S.projects.map(p => `
      <div class="card project-card" onclick="App.open(${p.id})">
        <div style="display:flex; justify-content:space-between; gap:10px; align-items:flex-start;">
          <div class="title">${esc(p.title)}</div>
          <span class="tag">${p.writing_status === 'done' ? 'écrit' : 'brouillon'}</span>
        </div>
        <div class="meta">
          <span>Étape ${pad2(p.step)} / 07 — ${esc(STEPS[p.step - 1] || '')}</span>
          <span class="mono">${esc((p.updated_at || '').slice(0, 10))}</span>
        </div>
        <div class="card-actions" onclick="event.stopPropagation()">
          <span onclick="App.duplicateProject(${p.id})" title="Nouveau livre avec les mêmes réglages (format, thème, recette de mise en page, palette)">⧉ Dupliquer</span>
          <span onclick="window.open('api.php?r=projects/export&id=${p.id}', '_blank')" title="Sauvegarde complète du projet (JSON, images incluses)">⬇ Sauvegarder</span>
          ${p.writing_status === 'done' && !p.translate_from ? `<span onclick="App.translateProject(${p.id})" title="Créer la version étrangère : structure et couverture traduites, chaque section traduite à l'étape 05">🌍 Traduire</span>` : ''}
        </div>
      </div>`).join('');

    return `
    ${topbarView(false)}
    <div class="page">
      <div class="page-head" style="max-width:640px;">
        <div class="kicker">Vos projets</div>
        <h1>Quel livre écrivons-nous<br>aujourd'hui ?</h1>
        <p class="lead">Chaque projet suit les 7 étapes du studio, de la niche rentable au fichier prêt pour Amazon KDP.</p>
      </div>
      <div class="projects-grid">
        <div class="project-new" onclick="App.createProject()">${S.busy.create ? 'Création…' : '+ Nouveau livre'}</div>
        ${cards}
      </div>
      <div style="margin-top:18px; font-size:12px; color:var(--faint);">
        <span style="cursor:pointer; color:var(--accent);" onclick="App.importProject()">⬆ Restaurer un projet depuis une sauvegarde (.json)</span>
      </div>
    </div>
    ${S.modal || ''}`;
  }

  // ── Vue : veille marché (tableau de bord Canopy, relevés au clic) ────────

  function watchView() {
    const canopy = S.watchCanopy || (S.app.canopy || { enabled: false });
    const remaining = canopy.enabled ? Math.max(0, canopy.budget - canopy.used) : 0;
    const watches = S.watches || [];

    return `
    ${topbarView(false)}
    <div class="page">
      <div style="display:flex; align-items:flex-end; justify-content:space-between; gap:24px; flex-wrap:wrap;">
        <div class="page-head" style="max-width:640px;">
          <div class="kicker">Veille marché</div>
          <h1>Vos niches Amazon,<br>relevé par relevé.</h1>
          <p class="lead">Chaque « Actualiser » interroge la vraie recherche Amazon via Canopy et <strong style="font-weight:500; color:var(--ink);">consomme 1 crédit</strong> — rien ne se rafraîchit tout seul. Les relevés restent consultables gratuitement, avec l'évolution entre deux relevés.</p>
        </div>
        <div class="card card-pad" style="min-width:220px;">
          <div style="font-size:11px; letter-spacing:.1em; text-transform:uppercase; color:var(--fainter);">Crédits Canopy · ${esc((canopy.domain || 'FR'))}</div>
          ${canopy.enabled ? `
          <div class="mono" style="font-size:26px; margin-top:6px;">${remaining}<span style="font-size:14px; color:var(--faint);"> / ${canopy.budget}</span></div>
          <div class="demand-track" style="margin-top:8px;"><div class="demand-fill" style="width:${Math.min(100, Math.round(canopy.used / canopy.budget * 100))}%; background:${canopy.exhausted ? 'var(--accent)' : 'var(--navy)'};"></div></div>
          <div style="font-size:11.5px; color:var(--faint); margin-top:6px;">${canopy.used} utilisée${canopy.used > 1 ? 's' : ''}${canopy.exhausted ? ' · quota atteint' : ''} · ${canopy.real ? '<span style="color:var(--green);">en direct depuis Canopy</span>' : '<span>estimation locale</span>'}</div>
          ${canopy.real ? '<div style="font-size:10.5px; color:var(--fainter); margin-top:4px;">Synchronisé avec votre compte canopyapi.co</div>' : `
          <div style="font-size:10.5px; color:var(--fainter); margin-top:4px;">
            <span style="color:var(--accent); cursor:pointer;" onclick="App.calibrateCanopy()">⚙ Caler sur mon vrai compteur canopyapi.co</span>
            · <span style="cursor:pointer;" onclick="App.resetCanopyUsage()">remettre à zéro</span>
          </div>`}`
          : `<div style="font-size:13px; color:var(--muted); margin-top:8px; line-height:1.5;">Connecteur non configuré.<br><span style="color:var(--accent); cursor:pointer;" onclick="App.openConnectors()">Coller ma clé Canopy ›</span></div>`}
        </div>
      </div>

      <div style="display:flex; gap:10px; margin:30px 0 22px; max-width:560px;">
        <input type="text" id="watch-term" placeholder="Niche à suivre — ou collez l’ASIN/le lien Amazon de votre livre publié"
               onkeydown="if(event.key==='Enter')App.addWatch()">
        <button class="btn btn-primary" style="flex:none;" onclick="App.addWatch()" ${S.busy.watchAdd ? 'disabled' : ''}>+ Suivre</button>
      </div>

      ${watches.length === 0 ? `<div class="card card-pad" style="text-align:center; padding:44px; color:var(--faint);">
        Ajoutez vos premières niches à suivre. L'ajout est gratuit — seul le relevé (↻) consomme un crédit.
      </div>` : ''}

      <div class="themes-grid">
        ${watches.map(w => watchCardView(w, canopy)).join('')}
      </div>
    </div>
    ${S.modal || ''}`;
  }

  // Lien produit Amazon : URL Canopy si fournie, sinon construite via l'ASIN
  function amazonUrl(p, canopy) {
    if (p.url) return p.url;
    const tld = ({ FR: 'fr', COM: 'com', CO_UK: 'co.uk', UK: 'co.uk', DE: 'de', ES: 'es', IT: 'it', CA: 'ca' })[(canopy && canopy.domain) || 'FR'] || 'fr';
    return p.asin ? `https://www.amazon.${tld}/dp/${p.asin}` : `https://www.amazon.${tld}/`;
  }

  function watchCardView(w, canopy) {
    const s = w.snapshot;
    const isAsin = /^B0[A-Z0-9]{8}$/.test(w.term);
    const dPrice = w.delta && w.delta.price !== null ? w.delta.price : null;
    const dReviews = w.delta && w.delta.reviews !== null ? w.delta.reviews : null;
    const deltaBadge = (value, unit, invert) => {
      if (value === null || value === 0) return '';
      const up = value > 0;
      const color = invert ? (up ? 'var(--green)' : 'var(--accent)') : (up ? 'var(--green)' : 'var(--accent)');
      return `<span style="color:${color}; font-size:11px;">${up ? '▲' : '▼'} ${up ? '+' : ''}${value}${unit}</span>`;
    };
    return `
    <div class="theme-card" style="cursor:default;">
      <div class="top">
        <div>
          <div class="name">${isAsin && s && s.top && s.top[0] ? esc(s.top[0].title) : esc(w.term)}</div>
          <div class="cat">${isAsin ? '📕 Suivi produit · <a href="https://www.amazon.' + (({FR:'fr',COM:'com',DE:'de',ES:'es',IT:'it'})[(canopy && canopy.domain) || 'FR'] || 'fr') + '/dp/' + esc(w.term) + '" target="_blank" rel="noopener" style="color:var(--accent);">' + esc(w.term) + '</a> · ' : ''}${s ? 'Relevé du ' + esc((w.updated_at || '').slice(0, 16).replace('T', ' ')) : 'Jamais relevé'}</div>
        </div>
        <span style="color:var(--fainter); cursor:pointer; font-size:15px; padding:2px 6px;" title="Ne plus suivre" onclick="App.removeWatch(${w.id})">✕</span>
      </div>
      ${s ? `
      <div class="metrics">
        <div><div class="metric-label">Prix médian</div><div class="metric-value">${s.median_price !== null ? String(s.median_price.toFixed(2)).replace('.', ',') + ' €' : 'n/c'} ${deltaBadge(dPrice, ' €')}</div></div>
        <div><div class="metric-label">Note moy.</div><div class="metric-value">${s.avg_rating !== null ? s.avg_rating + '/5' : 'n/c'}</div></div>
        <div><div class="metric-label">Avis cumulés</div><div class="metric-value">${nf(s.total_reviews)} ${deltaBadge(dReviews, ' %')}</div></div>
      </div>
      <div style="margin-top:14px; padding-top:12px; border-top:1px solid var(--line-soft);">
        <div class="metric-label" style="margin-bottom:7px;">Top réel (page 1) — ${(s.top || []).length} titres, cliquez pour ouvrir sur Amazon</div>
        ${(s.top || []).map((p, i) => `
        <a class="watch-item" href="${esc(amazonUrl(p, canopy))}" target="_blank" rel="noopener">
          <span class="mono num">${i + 1}</span>
          <span class="ttl">${esc(p.title)}</span>
          <span class="mono prix">${p.price !== null ? String(p.price.toFixed(2)).replace('.', ',') + ' €' : '—'}${p.rating ? '<br><span class="note">' + p.rating + '★ · ' + nf(p.ratings_total || 0) + '</span>' : ''}</span>
        </a>`).join('')}
      </div>` : `
      <p class="why" style="color:var(--faint);">Cliquez « Relever » pour charger le top réel Amazon de cette niche (1 crédit).</p>`}
      <div style="display:flex; gap:8px; margin-top:16px;">
        <button class="btn btn-soft" style="padding:9px 14px; font-size:12.5px;" onclick="App.refreshWatch(${w.id})"
          ${!canopy.enabled || canopy.exhausted || S.busy['watch' + w.id] ? 'disabled' : ''}>
          ${S.busy['watch' + w.id] ? '<span class="spinner"></span> Relevé…' : '↻ ' + (s ? 'Actualiser' : 'Relever') + ' · 1 crédit'}
        </button>
        <button class="btn btn-ghost" style="padding:9px 14px; font-size:12.5px;" onclick="App.watchToBook(${w.id})">✎ Créer un livre</button>
      </div>
    </div>`;
  }

  // ── Vue : assistant 7 étapes ─────────────────────────────────────────────

  function topbarView(withProject) {
    const p = S.project;
    return `
    <div class="topbar">
      <div class="topbar-left">
        <div class="logo-mark" style="cursor:pointer;" onclick="App.home()">T</div>
        <div class="logo-name" style="cursor:pointer;" onclick="App.home()">${esc(S.app.name)}</div>
        ${withProject && p ? `
          <div class="topbar-sep"></div>
          <div class="topbar-project">
            <span class="name">${esc(p.title)}</span>
            <span class="tag">${p.writing_status === 'done' ? 'écrit' : 'brouillon'}</span>
          </div>` : ''}
      </div>
      <div class="topbar-right">
        ${withProject ? `<div class="step-indicator">Étape ${pad2(S.step)} / 7</div>` : ''}
        <div class="connector-btn ${S.view === 'watch' ? 'on' : ''}" onclick="App.openWatch()" title="Veille marché Amazon — relevés Canopy à la demande">◉ Veille marché</div>
        <div class="connector-btn" onclick="App.openConnectors()" title="Connecteurs — clés API Gemini, Canopy…">⚡ Connecteurs</div>
        <div class="user-pill" onclick="App.logout()" title="Se déconnecter">
          <div class="avatar">${esc(S.user ? S.user.initials : '')}</div>
          <span>${esc(S.user ? S.user.display_name : '')}</span>
        </div>
      </div>
    </div>`;
  }

  function stepsBarView() {
    const reached = Number(S.project.step);
    return `<div class="steps-bar">${STEPS.map((label, i) => {
      const n = i + 1;
      return `<div class="step-tab ${n === S.step ? 'active' : n <= reached ? 'done' : 'locked'}" onclick="App.goStep(${n})">
        <span class="num">${pad2(n)}</span><span class="lbl">${label}</span><span class="dot"></span>
      </div>`;
    }).join('')}</div>`;
  }

  function wizardView() {
    let body = '';
    switch (S.step) {
      case 1: body = step1View(); break;
      case 2: body = step2View(); break;
      case 3: body = step3View(); break;
      case 4: body = step4View(); break;
      case 5: body = step5View(); break;
      case 6: body = step6View(); break;
      case 7: body = step7View(); break;
    }
    return topbarView(true) + stepsBarView() + body + footerView() + (S.modal ? S.modal : '');
  }

  // ── Étape 1 : niche ──────────────────────────────────────────────────────

  function currentThemes() {
    return S.project.mode === 'trends' ? S.bundle.themes.trends : S.bundle.themes.analysis;
  }

  function step1View() {
    const p = S.project;
    const mode = p.mode === 'trends' ? 'trends' : (p.mode === 'import' ? 'import' : 'describe');
    const isDescribe = mode === 'describe';
    const imp = S.importAnalysis;
    const themes = currentThemes();
    const showThemes = themes.length > 0;

    return `
    <div class="page">
      <div class="page-head" style="max-width:640px;">
        <div class="kicker">Étape 01 — Niche</div>
        <h1>Trouvez une thématique<br>qui se vend déjà.</h1>
        <p class="lead">Décrivez votre idée pour la confronter au marché, partez des catégories les plus consultées sur Amazon, ou repartez d'un livre que vous avez déjà écrit.</p>
      </div>

      <div class="mode-toggle">
        <div class="${mode === 'describe' ? 'on' : ''}" onclick="App.setMode('describe')">J'ai une idée</div>
        <div class="${mode === 'trends' ? 'on' : ''}" onclick="App.setMode('trends')">Explorer les tendances</div>
        <div class="${mode === 'import' ? 'on' : ''}" onclick="App.setMode('import')">📄 Importer un livre</div>
      </div>

      ${mode === 'import' ? `
      <div class="idea-grid">
        <div class="card card-pad">
          <div style="font-size:14px; font-weight:600; margin-bottom:6px;">Repartir d'un livre existant (PDF)</div>
          <div class="faint" style="font-size:12.5px; line-height:1.55; margin-bottom:14px;">
            Le texte est extrait et structuré en chapitres. Aucun envoi à l'IA à cette étape : l'analyse est entièrement locale à votre serveur.
          </div>
          <button class="btn btn-soft" style="width:100%;" onclick="App.pickImportPdf()" ${S.busy.importan ? 'disabled' : ''}>
            ${S.busy.importan ? '<span class="spinner"></span> Analyse du PDF…' : (imp ? '↻ Choisir un autre PDF' : '📄 Choisir mon fichier PDF')}
          </button>

          ${imp ? `
          <div class="import-summary">
            <div class="row"><span>Pages</span><span class="mono">${imp.pages}</span></div>
            <div class="row"><span>Chapitres détectés</span><span class="mono">${imp.chapters.length}</span></div>
            <div class="row"><span>Mots</span><span class="mono">${nf(imp.words_total)}</span></div>
          </div>
          <label style="margin-top:14px; display:block;">Titre du livre
            <input type="text" id="import-title" value="${esc(imp.title_guess || '')}" placeholder="Titre repris sur la couverture">
          </label>

          <div style="font-size:13.5px; font-weight:600; margin:18px 0 8px;">Que voulez-vous en faire ?</div>
          <div class="import-modes">
            <label class="import-mode ${(S.importMode || 'identique') === 'identique' ? 'on' : ''}">
              <input type="radio" name="impmode" ${(S.importMode || 'identique') === 'identique' ? 'checked' : ''} onchange="App.setImportMode('identique')">
              <span><strong>Le reprendre à l'identique</strong><br>
              <span class="faint">Contenu conservé mot pour mot. Vous filez directement à la couverture et à la mise en page — aucun crédit IA.</span></span>
            </label>
            <label class="import-mode ${S.importMode === 'inspire' ? 'on' : ''}">
              <input type="radio" name="impmode" ${S.importMode === 'inspire' ? 'checked' : ''} onchange="App.setImportMode('inspire')">
              <span><strong>S'en inspirer pour un nouveau livre</strong><br>
              <span class="faint">Seul le plan sert de point de départ : l'IA rédige un contenu neuf, qui vous appartient.</span></span>
            </label>
          </div>

          ${(S.importMode || 'identique') === 'identique' ? `
          <label class="import-owned">
            <input type="checkbox" id="import-owned" ${S.importOwned ? 'checked' : ''} onchange="App.setImportOwned(this.checked)">
            <span>Je confirme être l'auteur de ce livre ou en détenir les droits.</span>
          </label>` : ''}

          <button class="btn btn-primary" style="width:100%; margin-top:14px;" onclick="App.applyImport()"
                  ${S.busy.importap || ((S.importMode || 'identique') === 'identique' && !S.importOwned) ? 'disabled' : ''}>
            ${S.busy.importap ? '<span class="spinner"></span> Import en cours…'
              : ((S.importMode || 'identique') === 'identique' ? 'Importer et passer à la couverture →' : 'Importer le plan et continuer →')}
          </button>` : ''}
        </div>

        <div class="signals">
          <div class="head">${imp ? 'Structure détectée' : 'Comment ça marche'}</div>
          ${imp ? imp.chapters.map((c, i) => `
          <div class="row"><span class="n">${pad2(i + 1)}</span><span>${esc(c.title)} <span style="opacity:.6;">· ${nf(c.words)} mots</span></span></div>`).join('')
            : ['Choisissez le PDF de votre livre', 'Le texte est extrait et découpé en chapitres', 'Vous choisissez : à l\'identique ou source d\'inspiration', 'Direction la couverture et la mise en page']
              .map((t, i) => `<div class="row"><span class="n">${pad2(i + 1)}</span><span>${t}</span></div>`).join('')}
        </div>
      </div>` : ''}

      ${mode === 'describe' ? `
      <div class="idea-grid">
        <div class="card card-pad">
          <label>Votre idée, en quelques lignes
            <textarea id="idea-text" oninput="App.ideaChanged()" placeholder="Ex. : un guide pratique pour reprendre le contrôle de ses matinées quand on travaille de chez soi…">${esc(p.idea || '')}</textarea>
          </label>
          <div style="display:flex; flex-wrap:wrap; gap:8px; margin-top:14px;">
            ${IDEA_CHIPS.map(c => `<div class="chip-dashed" onclick="App.addChip('${esc(c)}')">+ ${esc(c)}</div>`).join('')}
          </div>
          <div class="idea-footer">
            <div class="src">${S.app.canopy && S.app.canopy.enabled
              ? 'Analyse Gemini + <strong style="color:var(--green); font-weight:500;">données réelles Amazon</strong> (Canopy)'
              : 'Analyse Gemini + Google Search · marché Amazon.fr'}</div>
            <button class="btn btn-primary" onclick="App.analyze()" ${S.busy.analyze ? 'disabled' : ''}>
              ${S.busy.analyze ? '<span class="spinner"></span> Analyse en cours…' : (S.bundle.themes.analysis.length ? '↻ Relancer l’analyse' : 'Analyser le marché')}
            </button>
          </div>
        </div>
        <div class="signals">
          <div class="head">Ce que l'analyse regarde</div>
          ${SIGNALS.map((s, i) => `<div class="row"><span class="n">${pad2(i + 1)}</span><span>${s}</span></div>`).join('')}
          ${S.app.canopy && S.app.canopy.enabled ? `
          <div style="margin-top:14px; padding-top:12px; border-top:1px solid rgba(237,229,214,.14); font-size:12px; opacity:.75; display:flex; justify-content:space-between; gap:10px;">
            <span>Canopy API · vraies données Amazon.${esc((S.app.canopy.domain || 'FR').toLowerCase())}</span>
            <span class="mono">${S.app.canopy.used}/${S.app.canopy.budget}${S.app.canopy.exhausted ? ' · épuisé' : ''}</span>
          </div>` : ''}
        </div>
      </div>` : mode === 'import' ? '' : `
      ${!showThemes && !S.busy.trends ? `<div class="card card-pad" style="text-align:center; padding:40px;">
        <p class="muted" style="margin:0 0 16px;">Chargez le classement des catégories les plus consultées sur Amazon.fr.</p>
        <button class="btn btn-primary" onclick="App.loadTrends()">Charger les tendances</button>
      </div>` : ''}
      ${S.busy.trends ? loadingCard('Interrogation des tendances Amazon…') : ''}`}

      ${showThemes ? `
      <div style="margin-top:34px;">
        <div class="section-head">
          <h2>${isDescribe ? '6 thématiques pour votre idée' : 'Les catégories les plus consultées'}</h2>
          <span class="sub">${isDescribe && S.groundedAnalysis ? '<strong style="color:var(--green); font-weight:500;">✓ Ancré sur les résultats réels Amazon</strong> · ' : ''}Trié par potentiel · généré à l'instant ${!isDescribe ? `· <span style="color:var(--accent); cursor:pointer;" onclick="App.loadTrends()">↻ actualiser</span>` : ''}</span>
        </div>
        <div class="themes-grid">
          ${themes.map((t, i) => `
          <div class="theme-card ${Number(S.project.theme_id) === Number(t.id) ? 'on' : ''}" onclick="App.selectTheme(${t.id})">
            <div class="top">
              <div>
                <div class="name">${esc(t.name)}</div>
                <div class="cat">${esc(t.category)}</div>
              </div>
              <div class="score ${i < 2 ? 'hot' : ''}">${esc(t.score)}</div>
            </div>
            <p class="why">${esc(t.why)}</p>
            <div class="metrics">
              <div>
                <div class="metric-label">Demande</div>
                <div class="demand-track"><div class="demand-fill" style="width:${Number(t.demand)}%"></div></div>
              </div>
              <div><div class="metric-label">Concurrence</div><div class="metric-value">${esc(t.competition)}</div></div>
              <div><div class="metric-label">Prix médian</div><div class="metric-value">${esc(t.price_median)}</div></div>
            </div>
          </div>`).join('')}
        </div>
      </div>` : ''}
      ${S.busy.analyze && isDescribe ? loadingCard('Gemini analyse les best-sellers de votre niche…') : ''}
    </div>`;
  }

  function loadingCard(label) {
    return `<div class="card card-pad" style="margin-top:24px; display:flex; align-items:center; gap:14px;">
      <span class="spinner" style="display:inline-block; width:16px; height:16px; border:2px solid var(--navy); border-top-color:transparent; border-radius:99px; animation:spin .8s linear infinite;"></span>
      <span class="muted">${esc(label)}</span>
    </div>`;
  }

  // ── Étape 2 : concepts ───────────────────────────────────────────────────

  function step2View() {
    const theme = currentThemes().find(t => Number(t.id) === Number(S.project.theme_id));
    const books = S.bundle.concepts;
    return `
    <div class="page">
      <div style="display:flex; align-items:flex-end; justify-content:space-between; gap:24px; flex-wrap:wrap;">
        <div class="page-head" style="max-width:620px;">
          <div class="kicker">Étape 02 — Concept</div>
          <h1>8 livres à écrire dans cette niche.</h1>
          <p class="lead">Chaque concept comble un angle mort repéré dans les 100 meilleures ventes de <strong style="font-weight:500; color:var(--ink);">${esc(theme ? theme.name : 'votre thème')}</strong>.</p>
          ${S.groundedConcepts ? `<p style="font-size:13px; color:var(--green); margin:10px 0 0;">✓ Angles morts détectés sur le top réel Amazon (Canopy API)</p>` : ''}
        </div>
        <div style="display:flex; gap:10px;">
          <button class="btn btn-ghost" onclick="App.goStep(1)">Changer de thème</button>
          <button class="btn btn-ghost" onclick="App.generateConcepts()" ${S.busy.concepts ? 'disabled' : ''}>
            ${S.busy.concepts ? '<span class="spinner"></span> Génération…' : '↻ Régénérer 8 idées'}
          </button>
        </div>
      </div>

      ${books.length === 0 && !S.busy.concepts ? `<div class="card card-pad" style="margin-top:30px; text-align:center; padding:40px;">
        <p class="muted" style="margin:0 0 16px;">Générez 8 concepts de livres calés sur les meilleures ventes de cette thématique.</p>
        <button class="btn btn-primary" onclick="App.generateConcepts()">Générer les 8 concepts</button>
      </div>` : ''}
      ${S.busy.concepts ? loadingCard('Gemini construit 8 concepts différenciants…') : ''}

      <div class="books-grid">
        ${books.map((b, i) => {
          const good = b.competition === 'Faible' || b.competition === 'Très faible';
          return `
          <div class="book-card ${Number(S.project.concept_id) === Number(b.id) ? 'on' : ''}" onclick="App.selectConcept(${b.id})">
            <div class="inner">
              <div class="mini-cover v${i % 3}">
                <div class="t">${esc(b.short_title || b.title)}</div>
                <div class="rule"></div>
                <div class="brand">${esc(S.app.name)}</div>
              </div>
              <div style="flex:1; min-width:0;">
                <div style="display:flex; align-items:center; gap:8px; margin-bottom:7px;">
                  <span class="num">${pad2(i + 1)}</span>
                  <span class="badge ${good ? 'badge-green' : 'badge-neutral'}">${esc(b.badge)}</span>
                </div>
                <div class="title">${esc(b.title)}</div>
                <div class="hook">${esc(b.hook)}</div>
                <p class="desc">${esc(b.description)}</p>
                <div class="stats">
                  <span>Concurrence <strong>${esc(b.competition)}</strong></span>
                  <span>Prix conseillé <strong>${esc(b.price)}</strong></span>
                  <span>${Number(b.pages_est)} p.</span>
                </div>
              </div>
            </div>
          </div>`;
        }).join('')}
      </div>
    </div>`;
  }

  // ── Étape 3 : sommaire ───────────────────────────────────────────────────

  function step3View() {
    const p = S.project;
    const concept = S.bundle.concepts.find(c => Number(c.id) === Number(p.concept_id));
    const words = Math.round(p.pages * 285 / 100) * 100;
    const chapterCount = S.bundle.toc.length || Math.max(6, Math.min(14, Math.round(p.pages / 20)));
    const photosOn = Number(p.photos) === 1;

    return `
    <div class="page">
      <div class="kicker" style="margin-bottom:14px;">Étape 03 — Structure</div>
      <h1 class="serif" style="font-size:44px; line-height:1.05; letter-spacing:-.02em; margin:0 0 30px; max-width:700px;">${esc(concept ? concept.title : p.title)}</h1>

      <div class="toc-grid">
        <div class="card card-pad params-card">
          <div class="title" style="font-size:14px; font-weight:600; margin-bottom:18px;">Paramètres du livre</div>

          <div style="margin-bottom:22px;">
            <div class="param-row-head"><span>Nombre de pages</span><span class="mono">${p.pages}</span></div>
            <input type="range" min="60" max="400" step="10" value="${p.pages}" oninput="App.setPages(this.value)">
            <div class="range-scale"><span>60</span><span>≈ ${nf(words)} mots · ${chapterCount} chapitres</span><span>400</span></div>
          </div>

          <div class="photos-box">
            <div class="head">
              <div>
                <div style="font-size:13.5px; font-weight:500;">Photos & illustrations</div>
                <div class="sub">Emplacements réservés dans la maquette</div>
              </div>
              <div class="switch ${photosOn ? 'on' : ''}" onclick="App.togglePhotos()"><div class="knob"></div></div>
            </div>
            ${photosOn ? `
            <div class="photos-detail">
              <div class="row"><span>Visuels par chapitre</span><span class="mono">${p.photos_per}</span></div>
              <input type="range" min="1" max="6" value="${p.photos_per}" oninput="App.setPhotosPer(this.value)">
              <div class="chip-row" style="margin-top:12px;">
                ${PHOTO_STYLES.map(s => `<div class="chip ${p.photo_style === s.key ? 'on' : ''}" onclick="App.setPhotoStyle('${s.key}')">${s.label}</div>`).join('')}
              </div>
              <div class="photos-hint">Noir & blanc recommandé : impression KDP à 0,013 €/page contre 0,065 € en couleur.</div>
            </div>` : ''}
          </div>

          <div style="display:grid; gap:14px;">
            <div>
              <div class="param-label">Ton d'écriture</div>
              <div class="chip-row">
                ${TONES.map(t => `<div class="chip ${p.tone === t ? 'on' : ''}" onclick="App.setTone('${esc(t)}')">${t}</div>`).join('')}
              </div>
            </div>
            <div>
              <div class="param-label">Format d'impression</div>
              <select onchange="App.setTrim(this.value)">
                ${Object.entries(S.bundle.trims).map(([key, t]) =>
                  `<option value="${key}" ${p.trim_format === key ? 'selected' : ''}>${esc(t.label)}</option>`).join('')}
              </select>
            </div>
          </div>

          <button class="btn btn-soft" style="width:100%; margin-top:20px;" onclick="App.generateToc()" ${S.busy.toc ? 'disabled' : ''}>
            ${S.busy.toc ? '<span class="spinner"></span> Génération…' : (S.bundle.toc.length ? '↻ Regénérer le sommaire' : 'Générer le sommaire')}
          </button>
        </div>

        <div class="card toc-card">
          <div class="toc-head">
            <div>
              <div class="t">Sommaire proposé</div>
              <div class="sub">${chapterCount} chapitres · flèches pour réordonner · cliquez un titre pour l'éditer</div>
            </div>
            <div class="target">${nf(words)} mots visés</div>
          </div>
          ${S.bundle.toc.length === 0 ? `<div class="toc-empty">${S.busy.toc ? 'Gemini structure votre livre…' : 'Réglez les paramètres puis générez le sommaire.'}</div>` : ''}
          ${S.bundle.toc.map((c, i) => `
          <div class="toc-row">
            <div class="num">${pad2(i + 1)}</div>
            <div style="flex:1; min-width:0;">
              <div class="title serif" contenteditable="true" spellcheck="false"
                   onblur="App.editTocTitle(${i}, this.textContent)"
                   onkeydown="if(event.key==='Enter'){event.preventDefault();this.blur();}">${esc(c.title)}</div>
              <div class="toc-parts">${(c.parts || []).map(part => `<div class="toc-part">${esc(part)}</div>`).join('')}</div>
            </div>
            <div class="toc-side">
              <div>${Math.max(6, Math.round(p.pages / chapterCount))} p.</div>
              ${photosOn ? `<div class="visuals">${p.photos_per} visuel(s)</div>` : ''}
            </div>
            <div class="toc-move">
              <span onclick="App.moveToc(${i}, -1)" title="Monter">▲</span>
              <span onclick="App.moveToc(${i}, 1)" title="Descendre">▼</span>
            </div>
          </div>`).join('')}

          <div class="toc-request">
            <div class="t">Un chapitre en tête ? Demandez-le.</div>
            <div class="row">
              <input type="text" id="toc-request" placeholder="Ex. : ajoute un chapitre sur la création de 50 recettes originales"
                     onkeydown="if(event.key==='Enter'){App.addChapter();}">
              <button class="btn btn-soft" onclick="App.addChapter()" ${S.busy.addchapter ? 'disabled' : ''}>
                ${S.busy.addchapter ? '<span class="spinner"></span> Ajout…' : '+ Ajouter'}
              </button>
            </div>
            <div class="hint">Fonctionne aussi après rédaction : le chapitre est inséré avant la conclusion et s'écrit à l'étape 05.</div>
          </div>
        </div>
      </div>
    </div>`;
  }

  // ── Étape 4 : couverture ─────────────────────────────────────────────────

  function step4View() {
    const cover = S.cover;
    return `
    <div class="page">
      <div class="page-head" style="max-width:680px;">
        <div class="kicker">Étape 04 — Couverture</div>
        <h1>Votre couverture, l'arme<br>de vente n° 1 sur Amazon.</h1>
        <p class="lead">Parcourez des versions flat design générées pour votre livre — comme un générateur de logo — puis affinez : illustration IA, palette, textes. Tout est rendu en haute résolution côté serveur.</p>
      </div>

      ${!cover ? loadingCard('Chargement de la couverture…') : `
      <div class="cover-grid" style="margin-top:30px;">
        <div class="card card-pad params-card">
          <div style="display:flex; justify-content:space-between; align-items:baseline; margin-bottom:12px;">
            <div style="font-size:14px; font-weight:600;">Versions proposées</div>
            <span style="font-size:12px; color:var(--accent); cursor:pointer;" onclick="App.newCoverVariants()">${S.busy.variants ? 'Génération…' : '↻ 8 nouvelles versions'}</span>
          </div>
          ${!S.coverVariants ? loadingCard('Composition des versions…') : `
          <div class="cover-templates">
            ${S.coverVariants.map((v, i) => `
            <div class="cover-template ${v.selected ? 'on' : ''}" onclick="App.pickCoverVariant(${i})" title="${esc(v.layout)} · ${esc(v.palette.name)} · ${esc(v.motif)}">
              <img class="thumb" src="${v.thumb}" alt="">
              <div class="nm">${esc(v.palette.name)}</div>
            </div>`).join('')}
          </div>`}

          <div style="font-size:14px; font-weight:600; margin:20px 0 8px;">Ma couverture est déjà prête</div>
          <div class="faint" style="font-size:11.5px; line-height:1.5; margin-bottom:9px;">
            Envoyez votre fichier : il remplace la couverture composée dans les exports.
            PDF = broché complet (4ème + dos + 1ère), JPG/PNG = 1ère de couverture (eBook).
          </div>
          <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <button class="btn btn-ghost" style="padding:9px 12px; font-size:12.5px; flex:1;" onclick="App.uploadCustomCover()" ${S.busy.customcov ? 'disabled' : ''}>
              ${S.busy.customcov ? '<span class="spinner"></span> Envoi…' : '⬆ Envoyer ma couverture'}
            </button>
            ${(S.coverCustom && (S.coverCustom.wrap || S.coverCustom.front))
              ? `<button class="btn btn-ghost" style="padding:9px 12px; font-size:12.5px;" onclick="App.clearCustomCover()" title="Revenir à la couverture composée">✕</button>` : ''}
          </div>
          ${S.coverCustom && (S.coverCustom.wrap || S.coverCustom.front) ? `
          <div class="custom-cover-note">
            ✓ Vos fichiers sont utilisés dans les exports :
            ${S.coverCustom.wrap ? '<strong>PDF broché</strong>' : ''}${S.coverCustom.wrap && S.coverCustom.front ? ' · ' : ''}${S.coverCustom.front ? '<strong>JPG eBook</strong>' : ''}.
            L'éditeur ci-contre reste disponible pour l'autre face.
          </div>` : ''}

          <div style="font-size:14px; font-weight:600; margin:20px 0 8px;">Illustration flat design (IA)</div>
          <label style="font-weight:400;"><span class="faint" style="font-size:12px;">Décrivez l'image souhaitée — le style flat est imposé automatiquement</span>
            <textarea rows="3" id="cover-illus-prompt" placeholder="${esc(S.coverDefaultPrompt || '')}">${esc(cover.texts.illus_prompt || '')}</textarea>
          </label>
          <div style="display:flex; gap:8px; margin-top:10px; flex-wrap:wrap;">
            <button class="btn btn-primary" style="padding:9px 14px; font-size:12.5px; flex:1;" onclick="App.generateIllustration()" ${S.busy.illus ? 'disabled' : ''}>
              ${S.busy.illus ? '<span class="spinner"></span> Génération…' : '✦ Générer l’illustration'}
            </button>
            <button class="btn btn-ghost" style="padding:9px 12px; font-size:12.5px;" onclick="App.uploadCoverRef()" title="Image dont l'IA s'inspirera (style, sujet)">${S.coverHasRef ? '📎 Réf. ✓' : '📎 Image d’inspiration'}</button>
            ${S.coverHasIllustration ? `<button class="btn btn-ghost" style="padding:9px 12px; font-size:12.5px;" onclick="App.clearIllustration()" title="Revenir au motif géométrique">✕</button>` : ''}
          </div>
          <div class="faint" style="font-size:11px; margin-top:7px;">Sans illustration IA, un motif géométrique flat assorti est dessiné automatiquement.</div>
          ${(S.coverLibrary || []).length ? `
          <div class="illus-library">
            <div class="lbl">Vos créations (${S.coverLibrary.length}) — cliquez pour appliquer</div>
            <div class="strip">
              ${S.coverLibrary.map(it => `
              <div class="item ${it.active ? 'on' : ''}" title="${it.active ? 'Illustration active' : 'Appliquer cette création'}">
                <img src="api.php?r=coverstudio/illus-file&id=${S.project.id}&item=${esc(it.slug)}" alt="" onclick="App.selectIllustration('${esc(it.slug)}')">
                <span class="del" title="Supprimer de la bibliothèque" onclick="App.deleteIllustration('${esc(it.slug)}')">✕</span>
              </div>`).join('')}
            </div>
          </div>` : ''}

          <div style="font-size:14px; font-weight:600; margin:20px 0 10px;">Palette</div>
          <div class="palette-row">
            ${['c1', 'c2', 'c3', 'c4'].map(k => `<input type="color" value="${esc(cover.palette[k] || '#1B2A4A')}" onchange="App.setCoverColor('${k}', this.value)" title="${k.toUpperCase()}">`).join('')}
            <span class="faint" style="font-size:11.5px;">C1 fond · C2 accent · C3 clair · C4 encre</span>
          </div>

          <div style="display:grid; gap:12px; margin-top:20px;">
            <label>Titre<input type="text" data-covertext="title" value="${esc(cover.texts.title)}" oninput="App.setCoverText('title', this.value)"></label>
            <label>Sous-titre<input type="text" data-covertext="subtitle" value="${esc(cover.texts.subtitle)}" oninput="App.setCoverText('subtitle', this.value)"></label>
            <label>Accroche (1ère de couv)<input type="text" data-covertext="tagline" value="${esc(cover.texts.tagline)}" oninput="App.setCoverText('tagline', this.value)"></label>
            <label>Auteur<input type="text" value="${esc(cover.texts.author)}" oninput="App.setCoverText('author', this.value)"></label>
            <label>Texte de 4ème de couverture<textarea rows="6" oninput="App.setCoverText('back_text', this.value)">${esc(cover.texts.back_text)}</textarea></label>
            <label>Bio auteur<textarea rows="2" oninput="App.setCoverText('bio', this.value)">${esc(cover.texts.bio)}</textarea></label>
          </div>

          <button class="btn btn-soft" style="width:100%; margin-top:16px;" onclick="App.generateBackText()" ${S.busy.back ? 'disabled' : ''}>
            ${S.busy.back ? '<span class="spinner"></span> Rédaction…' : '✦ Générer les textes (accroche, 4ème, bio)'}
          </button>
          <button class="btn btn-ghost" style="width:100%; margin-top:8px;" onclick="window.open('api.php?r=coverstudio/front&id=${S.project.id}&download=1', '_blank')">
            Télécharger la 1ère de couv (JPG eBook 1600×2560)
          </button>
        </div>

        <div>
          <div class="card" style="padding:14px;">
            <div class="face-switch">
              <span class="${(S.coverFace || 'front') === 'front' ? 'on' : ''}" onclick="App.setCoverFace('front')">1ère de couverture</span>
              <span class="${S.coverFace === 'back' ? 'on' : ''}" onclick="App.setCoverFace('back')">4ème de couverture</span>
              <span class="hint">${S.coverFace === 'back' ? 'Glissez, redimensionnez, changez polices et couleurs — comme la 1ère.' : 'Cliquez « 4ème » pour la voir en grand et la retravailler.'}</span>
              ${S.coverFace === 'back' ? '<button class="btn btn-ghost" style="padding:5px 10px; font-size:11.5px; margin-left:auto;" onclick="App.resetFace()">↺ 4ème automatique</button>' : ''}
            </div>
            <div id="ed-toolbar" class="editor-toolbar"></div>
            <div class="editor-wrap"><div id="ed-stage"></div></div>
            <div class="editor-downloads">
              <button class="btn btn-ghost" style="padding:8px 12px; font-size:12px;" onclick="window.open('api.php?r=coverstudio/${S.coverFace === 'back' ? 'back' : 'front'}&id=${S.project.id}&t=' + Date.now(), '_blank')">Aperçu HD ${S.coverFace === 'back' ? '4ème' : '1ère'} ↗</button>
              <button class="btn btn-soft" style="padding:8px 12px; font-size:12px;" onclick="window.open('api.php?r=coverstudio/front&id=${S.project.id}&download=1', '_blank')">JPG eBook (1600×2560)</button>
              <button class="btn btn-primary" style="padding:8px 12px; font-size:12px;" onclick="window.open('api.php?r=export/cover-pdf&id=${S.project.id}', '_blank')">📕 PDF broché complet (KDP)</button>
            </div>
            <div class="faint" style="font-size:11px; margin-top:8px;">Le PDF broché contient 4ème + tranche + 1ère en une seule page 300 dpi, fond perdu et zone code-barres compris — téléversable tel quel sur KDP. La tranche est calculée d'après la pagination${S.coverGeometry ? ' : ' + S.coverGeometry.pages + ' pages → dos ' + String(S.coverGeometry.spine_mm).replace('.', ',') + ' mm' : ''}.</div>
            <div style="display:flex; align-items:center; gap:10px; margin-top:10px;">
              <label style="font-size:12px; font-weight:500; flex:none;">Pages définitives</label>
              <input type="number" min="24" max="828" style="width:110px; padding:7px 9px; font-size:12.5px;"
                     value="${S.project.final_pages || ''}" placeholder="auto${S.coverGeometry ? ' : ' + S.coverGeometry.pages : ''}"
                     onchange="App.setFinalPages(this.value)">
              <span class="faint" style="font-size:11px;">Reportez ici le nombre de pages affiché par le previewer KDP après téléversement de l'intérieur — la tranche sera exacte. Vide = estimation auto.</span>
            </div>
          </div>
          <div style="display:flex; gap:14px; margin-top:14px; align-items:flex-start;">
            <img id="cover-back-img" src="api.php?r=coverstudio/${(S.coverFace || 'front') === 'back' ? 'front' : 'back'}&id=${S.project.id}&t=${S.coverStamp || 0}"
                 alt="${S.coverFace === 'back' ? '1ère de couverture' : '4ème de couverture'}" title="Cliquez pour éditer cette face en grand"
                 style="width:130px; border-radius:3px; box-shadow:0 6px 18px rgba(48,40,26,.2); cursor:pointer;"
                 onclick="App.setCoverFace('${(S.coverFace || 'front') === 'back' ? 'front' : 'back'}')">
            <div class="faint" style="font-size:11.5px; line-height:1.5; padding-top:4px;">
              ${S.coverFace === 'back'
                ? 'Vous éditez la <strong>4ème de couverture</strong>. Aperçu de la 1ère ci-contre — cliquez dessus pour y revenir.'
                : 'Aperçu de la <strong>4ème de couverture</strong> (accroche, texte de vente, bio, zone code-barres). Cliquez dessus — ou sur l\'onglet — pour l\'éditer en grand.'}
            </div>
          </div>
        </div>
      </div>`}
    </div>`;
  }

  async function loadCover() {
    try {
      const data = await Api.get('covers/get', { id: S.project.id });
      S.cover = data.cover;
      S.coverFace = S.coverFace === 'back' ? 'back' : 'front';
      S.coverElsFront = data.els;
      S.coverElsBack = data.els_back;
      S.editorEls = S.coverFace === 'back' ? data.els_back : data.els;
      S.coverLibrary = data.library || [];
      S.coverCustom = data.custom || { wrap: false, front: false };
      S.coverFonts = data.fonts;
      S.coverMotifs = data.motifs;
      S.coverGeometry = data.geometry;
      S.coverHasIllustration = data.has_illustration;
      S.coverHasRef = data.has_reference;
      S.coverDefaultPrompt = data.default_prompt;
      S.coverStamp = Date.now();
      render();
      if (!S.coverVariants) loadCoverVariants();
    } catch (e) { toast(e.message, true); }
  }

  async function reloadInteriorThemes() {
    const themes = await Api.get('interior/themes', { id: S.project.id });
    S.interiorThemes = themes.themes;
    S.layoutOptions = themes.options || [];
    S.layoutColors = themes.colors || null;
    S.coverPaletteRef = themes.cover_palette || {};
  }

  async function loadCoverVariants() {
    setBusy('variants', true);
    try {
      const data = await Api.post('coverstudio/variants', { id: S.project.id, seed: S.coverSeed || 1 });
      S.coverVariants = data.variants;
    } catch (e) { toast(e.message, true); }
    setBusy('variants', false);
  }

  function refreshFrontPreview() {
    S.coverStamp = Date.now();
  }

  function refreshBackPreview() {
    S.coverStamp = Date.now();
    const img = document.getElementById('cover-back-img');
    if (img && img.src.includes('coverstudio/back')) {
      img.src = 'api.php?r=coverstudio/back&id=' + S.project.id + '&t=' + Date.now();
    }
  }

  function injectCoverPreviews() {
    if (S.step !== 4) return;
    const img = document.getElementById('cover-back-img');
    if (img) img.src = 'api.php?r=coverstudio/back&id=' + S.project.id + '&t=' + Date.now();
  }

  function saveCover() {
    debounce('cover', async () => {
      try {
        await Api.post('covers/save', {
          id: S.project.id, template: S.cover.template,
          palette: S.cover.palette, texts: S.cover.texts
        });
        Editor.syncTexts(S.cover.texts); // répercute titre/accroche dans l'éditeur
        injectCoverPreviews();
      } catch (e) { toast(e.message, true); }
    }, 700);
  }

  // ── Étape 5 : rédaction ──────────────────────────────────────────────────

  function step5View() {
    const p = S.project;
    const st = S.writer.status;
    const concept = S.bundle.concepts.find(c => Number(c.id) === Number(p.concept_id));
    const title = concept ? concept.title : p.title;
    if (!st) return `<div class="page">${loadingCard('Chargement de l’état de rédaction…')}</div>`;

    const running = st.writing_status === 'running';
    const done = st.writing_status === 'done';
    const statusLabel = done ? 'Rédaction terminée · manuscrit complet'
      : running ? `Rédaction en cours · ${st.current_label && st.current_label !== '—' ? st.current_label.toLowerCase() : 'chapitre ' + st.chapter_current} (${st.chapter_current}/${st.chapter_total})`
      : 'En pause · reprise possible à tout moment';

    return `
    <div class="page">
      <div class="writing-grid">
        <div>
          <div class="run-hero">
            <div class="top">
              <div>
                <div class="kicker mono" style="font-size:10.5px; letter-spacing:.16em;">Étape 05 — Rédaction</div>
                <div class="title serif">${esc(title)}</div>
                <div class="status">${statusLabel}</div>
              </div>
              <div style="text-align:right;">
                <div class="pct">${st.progress}%</div>
                <div class="pct-label">terminé</div>
              </div>
            </div>
            <div class="progress-track"><div class="progress-fill" style="width:${st.progress}%"></div></div>
            <div class="run-stats">
              <div>
                <div class="k">Mots écrits</div><div class="v">${nf(st.words_done)}</div>
                ${st.pages_est ? `<div style="font-size:11.5px; opacity:.65; margin-top:3px;">≈ ${nf(st.pages_est)} pages<span style="opacity:.7;"> (indicatif)</span></div>` : ''}
              </div>
              <div><div class="k">Chapitre</div><div class="v">${st.chapter_current} / ${st.chapter_total}</div></div>
              <div><div class="k">Temps restant</div><div class="v">${done ? '—' : st.eta_min + ' min'}</div></div>
              <div><div class="k">Point de contrôle</div><div class="v">${esc(st.checkpoint)}</div></div>
            </div>
            <div class="run-actions">
              ${done
                ? `<button class="btn btn-light" onclick="App.goStep(6)">Lire les chapitres</button>`
                : running
                  ? `<button class="btn btn-light" onclick="App.pauseWriting()">Mettre en pause</button>`
                  : `<button class="btn btn-light" onclick="App.startWriting()">${st.progress > 0 ? 'Reprendre la rédaction' : 'Lancer la rédaction'}</button>`}
              <button class="btn btn-outline-light" onclick="App.showCronInfo()" title="Le livre s'écrit tout seul via la tâche cron de votre hébergeur, même PC éteint — e-mail à la fin.">⚙ Écriture autonome (cron)</button>
            </div>
          </div>

          ${S.writer.incident ? `
          <div class="incident">
            <div class="ic">!</div>
            <div style="flex:1;">
              <div class="t">Interruption détectée à ${esc(S.writer.incident.time)} — reprise automatique</div>
              <div class="d">${esc(S.writer.incident.message)} La rédaction redémarre depuis le point de contrôle <strong style="font-weight:500; color:var(--ink);">${esc(S.writer.incident.checkpoint)}</strong> — aucun paragraphe perdu, aucun doublon généré.</div>
            </div>
            <div class="close" onclick="App.dismissIncident()">Masquer</div>
          </div>` : ''}

          <div class="chapters-progress">
            <div class="section-head">
              <h2 style="font-size:23px;">Avancement par chapitre</h2>
              <span class="sub">Sauvegarde continue en base après chaque section</span>
            </div>
            <div class="grid">
              ${st.chapters.map(c => {
                const stateCls = c.status === 'done' ? 'done' : c.status === 'writing' ? 'run' : 'wait';
                const badge = c.status === 'done' ? '<span class="badge badge-green">Terminé</span>'
                  : c.status === 'writing' ? '<span class="badge badge-orange">En cours</span>'
                  : '<span class="badge badge-wait">En attente</span>';
                return `
                <div class="chapter-run-card ${stateCls}">
                  <div class="head"><span class="ch">${esc(c.short || 'CH ' + pad2(c.num))}</span>${badge}</div>
                  <div class="title">${esc(c.title)}</div>
                  <div class="track"><div class="fill" style="width:${c.pct}%"></div></div>
                  <div class="nums"><span>${nf(c.words_done)} / ${nf(c.words_target)} mots</span><span>${c.pct}%</span></div>
                </div>`;
              }).join('')}
            </div>
          </div>
        </div>

        <div class="console-panel">
          <div class="head">
            <div class="live-dot ${running ? '' : 'paused'}"></div>
            <div class="label">Journal en direct</div>
          </div>
          <div class="console-log" id="console-log">
            ${S.writer.journal.map(l => `<div class="row"><span class="t">${esc(l.t)}</span><span class="${esc(l.level)}">${esc(l.message)}</span></div>`).join('')}
          </div>
        </div>
      </div>
    </div>`;
  }

  async function enterWriting() {
    try {
      const data = await Api.get('write/status', { id: S.project.id, after: 0 });
      S.writer.status = data.status;
      S.writer.journal = data.journal;
      S.writer.lastId = data.journal.length ? data.journal[data.journal.length - 1].id : 0;
      render();
      if (data.status.writing_status === 'running' && !S.writer.looping) writeLoop();
    } catch (e) { toast(e.message, true); }
  }

  async function pullJournal() {
    try {
      const data = await Api.get('write/status', { id: S.project.id, after: S.writer.lastId });
      S.writer.status = data.status;
      if (data.journal.length) {
        S.writer.journal = S.writer.journal.concat(data.journal).slice(-200);
        S.writer.lastId = data.journal[data.journal.length - 1].id;
      }
    } catch (_) { /* silencieux */ }
  }

  async function writeLoop() {
    if (S.writer.looping) return;
    S.writer.looping = true;
    let backoff = 2000;

    while (S.writer.looping && S.step === 5) {
      try {
        const data = await Api.post('write/tick', { id: S.project.id });
        S.writer.status = data.status;
        S.writer.incident = null;
        backoff = 2000;
        await pullJournal();
        render();
        if (data.status.writing_status !== 'running') {
          S.writer.looping = false;
          if (data.status.writing_status === 'done') {
            await refreshProject();
            toast('Rédaction terminée — le manuscrit est complet.');
            render();
          }
          break;
        }
      } catch (e) {
        if (!S.writer.looping || S.step !== 5) break;
        const now = new Date();
        S.writer.incident = {
          time: now.toTimeString().slice(0, 5),
          message: e.network ? 'Coupure réseau pendant la génération.' : e.message,
          checkpoint: (S.writer.status && S.writer.status.checkpoint) || '—'
        };
        await pullJournal();
        render();
        if (!e.retryable && !e.network && e.status !== 401) {
          // erreur non récupérable (ex. clé API) : pause propre
          try { await Api.post('write/pause', { id: S.project.id }); } catch (_) {}
          S.writer.looping = false;
          await pullJournal();
          render();
          toast(e.message, true);
          break;
        }
        await new Promise(resolve => setTimeout(resolve, backoff));
        backoff = Math.min(30000, backoff * 2);
      }
    }
    S.writer.looping = false;
  }

  // ── Étape 6 : relecture ──────────────────────────────────────────────────

  function step6View() {
    const p = S.project;
    const st = S.writer.status;
    const data = S.reader.data;
    const concept = S.bundle.concepts.find(c => Number(c.id) === Number(p.concept_id));
    const chapters = (st && st.chapters) || [];
    const totalWords = chapters.reduce((a, c) => a + Number(c.words_done), 0);

    return `
    <div class="reader-grid">
      <div class="reader-nav">
        <div class="head">
          <div class="kicker mono" style="font-size:10.5px;">Étape 06 — Relecture</div>
          <div class="book-title">${esc(concept ? concept.title : p.title)}</div>
          <div class="meta">${chapters.length} chapitres · ${nf(totalWords)} mots${st && st.pages_est ? ' · ≈ ' + st.pages_est + ' p.' : ''}</div>
        </div>
        ${chapters.map(c => `
        <div class="reader-nav-item ${S.reader.num === c.num ? 'on' : ''}" onclick="App.openChapter(${c.num})">
          <span class="n">${esc(c.short || pad2(c.num))}</span><span class="t">${esc(c.title)}</span>
        </div>`).join('')}
      </div>

      <div class="reader-body">
        <div class="reader-inner">
          ${!data ? loadingCard('Chargement du chapitre…') : `
          <div class="chapter-kicker">${esc(data.chapter.label || ('Chapitre ' + pad2(data.chapter.num)))}</div>
          <h1>${esc(data.chapter.title)}</h1>
          ${data.sections.map((sec, i) => `
            ${i > 0 ? `<h3 class="sec serif">${esc(sec.title)}</h3>` : ''}
            ${S.sectionEdit && S.sectionEdit.id === sec.id ? `
            <div class="section-edit">
              <textarea id="sec-edit-${sec.id}" rows="14">${esc(S.sectionEdit.content)}</textarea>
              <div class="row">
                <button class="btn btn-primary" onclick="App.saveSectionEdit(${sec.id})" ${S.busy.secsave ? 'disabled' : ''}>${S.busy.secsave ? '<span class="spinner"></span> Enregistrement…' : 'Enregistrer'}</button>
                <button class="btn btn-ghost" onclick="App.closeSectionEdit()">Annuler</button>
                <span class="hint">Syntaxe des encadrés : <code>:::conseil</code> … <code>:::</code> · listes avec « – »</span>
              </div>
            </div>` : `
            <div class="reader-prose">
              ${sec.content ? blocksHtml(sec.content)
                : '<p class="muted" style="font-family:var(--sans); font-size:14px;">Section pas encore rédigée.</p>'}
            </div>
            ${sec.content ? `
            <div class="section-actions">
              <span onclick="App.openSectionEdit(${sec.id})">✎ Modifier le texte</span>
              <span onclick="App.openSectionRetouch(${sec.id})">↻ Retoucher par l'IA</span>
              <span class="faint">${nf(sec.words || 0)} mots</span>
            </div>` : ''}
            ${S.sectionRetouch && S.sectionRetouch.id === sec.id ? `
            <div class="imagegen-box">
              <textarea id="sec-retouch-${sec.id}" rows="2" placeholder="Votre consigne — ex. : raccourcis d'un tiers, ajoute un exemple chiffré, ton plus direct…">${esc(S.sectionRetouch.prompt || '')}</textarea>
              <div class="row">
                <button class="btn btn-soft" onclick="App.runSectionRetouch(${sec.id})" ${S.busy.secretouch ? 'disabled' : ''}>${S.busy.secretouch ? '<span class="spinner"></span> Réécriture…' : '↻ Retoucher cette section'}</button>
                <button class="btn btn-ghost" onclick="App.closeSectionRetouch()">Fermer</button>
                <span class="hint">Une section = un appel IA. Le reste du livre ne bouge pas.</span>
              </div>
            </div>` : ''}`}
            ${i === 0 ? figuresView(data) : ''}
          `).join('')}
          <div class="reader-pager">
            <span onclick="App.openChapter(${data.chapter.num - 1})" style="${data.chapter.num <= 1 ? 'visibility:hidden;' : ''}">← Chapitre précédent</span>
            <span onclick="${data.chapter.num >= chapters.length ? 'App.goStep(7)' : 'App.openChapter(' + (data.chapter.num + 1) + ')'}">${data.chapter.num >= chapters.length ? 'Mise en page →' : 'Chapitre suivant →'}</span>
          </div>`}
        </div>
      </div>

      <div class="reader-tools">
        <div class="title">Retravailler ce chapitre</div>
        <div class="tool-action" onclick="App.proofreadBook()" style="border-color:var(--accent);">
          <span>${S.busy.proofread ? 'Relecture en cours…' : '🪄 Relire tout le livre (orthographe)'}</span><span class="arrow">›</span>
        </div>
        ${[
          ['rewrite', 'Réécrire avec un autre ton'],
          ['extend', 'Allonger de 400 mots'],
          ['tighten', 'Resserrer / supprimer les redites'],
          ['exercise', 'Ajouter un exercice pratique'],
          ['factcheck', 'Vérifier les affirmations factuelles']
        ].map(([action, label]) => `
        <div class="tool-action" onclick="App.chapterAction('${action}')">
          <span>${S.busy.chapAction === action ? 'En cours…' : label}</span><span class="arrow">›</span>
        </div>`).join('')}

        <div class="quality-list">
          <div class="title">Contrôle qualité</div>
          ${data ? data.quality.map(q => `
          <div class="quality-row"><span class="k">${esc(q.k)}</span><span class="v ${q.warn ? 'warn' : ''}">${esc(q.v)}</span></div>`).join('') : ''}
        </div>
      </div>
    </div>
    `;
  }

  function figuresView(data) {
    if (!data.images || !data.images.length) return '';
    return data.images.map(img => `
    <figure class="reader-figure">
      <div class="slot">
        ${img.filename ? `<img src="media.php?img=${img.id}&t=${Date.now()}" alt="">` : `
        <div>
          <div class="cap">Emplacement visuel ${data.chapter.num}.${img.slot} — ${esc(img.caption || 'visuel à fournir')}</div>
          <div class="spec">${esc(img.spec)}</div>
        </div>`}
      </div>
      <figcaption>Fig. ${data.chapter.num}.${img.slot} — ${esc(img.caption || '')}
        <span class="upload-link" onclick="App.uploadImage(${img.id})">${img.filename ? 'Remplacer l’image' : 'Téléverser l’image'}</span>
        <span class="upload-link" onclick="App.openImageGen(${img.id})">✨ ${img.filename ? 'Regénérer par l’IA' : 'Générer par l’IA'}</span>
      </figcaption>
      ${S.imageGen && S.imageGen.id === img.id ? `
      <div class="imagegen-box">
        <textarea id="ig-prompt" rows="3" placeholder="Précisez le visuel souhaité (optionnel — l'IA connaît déjà la légende, le chapitre et le style ${esc(S.project.photo_style === 'couleur' ? 'couleur' : S.project.photo_style === 'schemas' ? 'schéma' : 'noir & blanc')})">${esc(S.imageGen.prompt || '')}</textarea>
        <div class="row">
          <button class="btn btn-soft" onclick="App.runImageGen()" ${S.busy.imagegen ? 'disabled' : ''}>
            ${S.busy.imagegen ? '<span class="spinner"></span> Génération…' : (img.filename ? '↻ Nouvelle version' : '✨ Générer le visuel')}
          </button>
          <button class="btn btn-ghost" onclick="App.closeImageGen()">Fermer</button>
          <span class="hint">Pas convaincu ? Précisez votre demande et regénérez, autant de fois que nécessaire.</span>
        </div>
      </div>` : ''}
    </figure>`).join('');
  }

  async function loadChapter(num) {
    S.reader.num = num;
    S.reader.data = null;
    if (!S.writer.status) {
      try {
        const statusData = await Api.get('write/status', { id: S.project.id, after: 0 });
        S.writer.status = statusData.status;
        S.writer.journal = statusData.journal;
      } catch (_) {}
    }
    render();
    try {
      const data = await Api.get('chapters/get', { id: S.project.id, num });
      S.reader.data = data;
      render();
    } catch (e) { toast(e.message, true); }
  }

  // ── Étape 7 : mise en page ───────────────────────────────────────────────

  function step7View() {
    const L = S.layout;
    const theme = S.project.interior_theme || 'editorial';
    const themeName = ((S.interiorThemes || []).find(t => t.slug === theme) || {}).name || '';
    const pdfUrl = `api.php?r=export/pdf&id=${S.project.id}&inline=1&theme=${encodeURIComponent(theme)}&v=${S.layoutStamp || 0}`;
    const colors = S.layoutColors || { accent: '#C4571F', ink: '#1A1A17', from_cover: true };
    const coverPal = S.coverPaletteRef || {};
    return `
    <div class="layout-grid">
      <div class="layout-preview">
        <div class="head">
          <div>
            <div class="kicker">Étape 07 — Mise en page</div>
            <div class="t">PDF réel — thème ${esc(themeName || theme)}${L ? esc(' · ' + String(L.geometry.w_mm).replace('.', ',') + ' × ' + String(L.geometry.h_mm).replace('.', ',') + ' mm') : ''}</div>
          </div>
          <button class="btn btn-ghost" onclick="window.open('${pdfUrl}', '_blank')">Ouvrir le PDF en grand ↗</button>
        </div>
        <div class="preview-frame-wrap">
          <iframe class="preview-frame" src="${pdfUrl}#page=9&toolbar=0&navpanes=0&view=FitH" title="Aperçu du PDF intérieur"></iframe>
        </div>
        ${L ? `<div class="preview-caption">Aperçu = le PDF final exact (polices incorporées) · marges ${String(L.geometry.margin_top_mm).replace('.', ',')} mm · gouttière ${String(L.geometry.margin_inner_mm).replace('.', ',')} mm · dos ${esc(L.spine_label)} · ${L.geometry.pages} pages estimées</div>` : ''}
      </div>

      <div class="layout-side">
        <div class="title">Mise en page intérieure</div>
        <div class="sub">Choisissez le style : l'aperçu ci-contre montre le PDF réel, recomposé à chaque changement. Les couleurs suivent votre couverture — ajustables ci-dessous.</div>
        <div class="cover-templates" style="margin-bottom:20px;">
          ${(S.interiorThemes || []).map(t => `
          <div class="cover-template ${t.selected ? 'on' : ''}" onclick="App.setInteriorTheme('${esc(t.slug)}')" title="${esc(t.desc)}">
            <img class="thumb" src="${t.thumb}" alt="${esc(t.name)}">
            <div class="nm">${esc(t.name)}</div>
          </div>`).join('')}
        </div>

        ${(S.layoutOptions || []).length ? `
        <div class="layout-composer">
          <div class="title" style="margin-bottom:4px;">🧩 Composer la mise en page</div>
          <div class="sub" style="margin-bottom:12px;">Cochez vos ingrédients — l'aperçu se recompose à chaque changement.${theme === 'premium' || theme === 'premium2' ? '' : ' <strong style="font-weight:600;">S\'applique aux thèmes Premium.</strong>'}</div>
          ${S.layoutOptions.map(o => `
          <label class="composer-row ${o.on ? 'on' : ''}" title="${esc(o.desc)}">
            <input type="checkbox" ${o.on ? 'checked' : ''} onchange="App.toggleLayoutOpt('${esc(o.key)}', this.checked)">
            <span class="k">${esc(o.name)}</span>
          </label>`).join('')}
        </div>` : ''}

        <div class="layout-composer" style="margin-bottom:22px;">
          <div class="title" style="margin-bottom:4px;">🎨 Couleurs du livre</div>
          <div class="sub" style="margin-bottom:12px;">
            ${colors.from_cover
              ? 'Reprises automatiquement de votre couverture — modifiez-les ici si vous voulez vous en écarter.'
              : 'Couleurs personnalisées pour l\'intérieur.'}
          </div>
          <div class="color-row">
            <label>Accent
              <input type="color" value="${esc(colors.accent)}" onchange="App.setInteriorColor('accent', this.value)"
                     title="Titres de chapitre, encadrés, chiffres clés, filets">
            </label>
            <label>Encre
              <input type="color" value="${esc(colors.ink)}" onchange="App.setInteriorColor('ink', this.value)"
                     title="Texte courant et titres">
            </label>
            ${!colors.from_cover ? `<button class="btn btn-ghost" style="padding:6px 11px; font-size:11.5px;" onclick="App.resetInteriorColors()">↺ Reprendre la couverture</button>` : ''}
          </div>
          ${Object.keys(coverPal).length ? `
          <div class="cover-swatches">
            <span class="lbl">Palette de la couverture — cliquez pour l'appliquer à l'accent :</span>
            ${['c1', 'c2', 'c3', 'c4'].filter(k => coverPal[k]).map(k => `
            <span class="sw ${colors.accent.toLowerCase() === String(coverPal[k]).toLowerCase() ? 'on' : ''}"
                  style="background:${esc(coverPal[k])};" title="${k.toUpperCase()} · ${esc(coverPal[k])}"
                  onclick="App.setInteriorColor('accent', '${esc(coverPal[k])}')"></span>`).join('')}
          </div>` : ''}
        </div>

        <div class="title">Réglages d'impression</div>
        <div class="sub">Conformes aux gabarits KDP broché.</div>
        ${!L ? loadingCard('Calculs en cours…') : `
        <div class="spec-row">
          <span class="k">Pages définitives <span class="faint" style="font-size:10.5px;">(previewer KDP)</span></span>
          <input type="number" min="24" max="828" style="width:92px; padding:6px 8px; font-size:12.5px; text-align:right;"
                 value="${S.project.final_pages || ''}" placeholder="auto : ${L.geometry.pages}"
                 onchange="App.setFinalPages(this.value)">
        </div>
        ${L.fields.map(f => `<div class="spec-row"><span class="k">${esc(f.k)}</span><span class="v">${esc(f.v)}</span></div>`).join('')}

        <div class="title" style="margin-top:26px; margin-bottom:12px;">Conformité KDP</div>
        ${L.checks.map(c => `
        <div class="check-row">
          <span class="ic ${c.state}">${c.state === 'ok' ? '✓' : '!'}</span>
          <div><div class="l">${esc(c.label)}</div><div class="n">${esc(c.note)}</div></div>
        </div>`).join('')}

        <div class="publish-card">
          <div class="t">Prêt à publier</div>
          <div class="d">${esc(L.pricing.label)}</div>
          <div class="btns">
            <button class="btn btn-light" onclick="window.open('api.php?r=export/pdf&id=${S.project.id}', '_blank')">📘 PDF intérieur (KDP) — polices incorporées</button>
            <button class="btn btn-light" onclick="window.open('api.php?r=export/cover-pdf&id=${S.project.id}', '_blank')">📕 PDF couverture broché (KDP) · dos ${esc(L.spine_label)}</button>
            <button class="btn btn-light" onclick="window.open('api.php?r=export/epub&id=${S.project.id}', '_blank')">📱 eBook Kindle (.epub) — photos & encadrés inclus</button>
            <button class="btn btn-outline-light" onclick="window.open('api.php?r=export/docx&id=${S.project.id}', '_blank')">Manuscrit .docx</button>
            <button class="btn btn-outline-light" onclick="window.open('print.php?id=${S.project.id}', '_blank')">Aperçu de l'épreuve navigateur ↗</button>
            <button class="btn btn-light" style="background:var(--accent); color:#FFF6EA;" onclick="App.openPublishModal()">🚀 Publier sur Amazon KDP</button>
          </div>
        </div>`}
      </div>
    </div>
    `;
  }

  async function loadLayout() {
    try {
      const data = await Api.get('layout/summary', { id: S.project.id });
      S.layout = data;
      render();
      if (!S.interiorThemes) {
        await reloadInteriorThemes();
        render();
      }
    } catch (e) { toast(e.message, true); }
  }

  // ── Éditeur visuel de couverture (éléments, alignements, polices) ───────

  const Editor = (() => {
    let stage = null, sel = null, guideV = null, guideH = null, saveTimer = null, keysBound = false;

    const fontCss = slug => ((S.coverFonts || []).find(f => f.slug === slug) || {}).css || 'serif';
    const fontHasItalic = slug => !!(((S.coverFonts || []).find(f => f.slug === slug) || {}).has_italic);
    const findEl = id => (S.editorEls || []).find(e => e.id === id);

    function init() {
      stage = document.getElementById('ed-stage');
      if (!stage || !S.editorEls) return;
      stage.innerHTML = '';
      guideV = null; guideH = null;
      const keepSel = sel && findEl(sel.id) ? sel.id : null;
      sel = null;
      S.editorEls.forEach(el => stage.appendChild(buildNode(el)));
      // Zone de sécurité KDP : gardez textes et éléments clés à l'intérieur
      stage.insertAdjacentHTML('beforeend',
        '<div style="position:absolute; inset:3.2% 4.5%; border:1px dashed rgba(255,45,138,.5); pointer-events:none; z-index:20;" title="Zone de sécurité — gardez les textes à l\'intérieur"></div>');
      stage.onpointerdown = e => { if (e.target === stage) select(null); };
      if (!keysBound) { document.addEventListener('keydown', onKeys); keysBound = true; }
      if (keepSel) select(findEl(keepSel));
      toolbar();
    }

    function buildNode(el) {
      let node;
      if (el.type === 'text') {
        node = document.createElement('div');
        const inner = document.createElement('div');
        inner.className = 'ed-text-inner';
        inner.textContent = el.text || '';
        node.appendChild(inner);
      } else if (el.type === 'motif') {
        node = document.createElement('img');
        node.src = 'api.php?r=coverstudio/motif&type=' + encodeURIComponent(el.motif || 'blob')
          + '&c1=' + encodeURIComponent(el.color || '#C4571F') + '&c2=' + encodeURIComponent(el.color2 || '#F4EFE4');
        node.style.objectFit = 'contain';
      } else if (el.type === 'image') {
        node = document.createElement('img');
        node.src = 'api.php?r=coverstudio/illus-file&id=' + S.project.id + '&t=' + (S.coverStamp || 0);
        node.style.objectFit = 'cover';
        if (el.round) node.style.borderRadius = '50%';
      } else {
        node = document.createElement('div');
      }
      node.classList.add('ed-el', 'draggable');
      node.dataset.id = el.id;
      applyStyle(node, el);
      node.onpointerdown = ev => startDrag(ev, el, node);
      if (el.type === 'text') node.ondblclick = () => startTextEdit(node, el);
      return node;
    }

    function applyStyle(node, el) {
      if (el.type === 'poly') {
        node.style.left = '0'; node.style.top = '0'; node.style.width = '100%'; node.style.height = '100%';
        node.style.background = el.color;
        node.style.clipPath = 'polygon(' + (el.points || []).map(p => p[0] + '% ' + p[1] + '%').join(',') + ')';
        return;
      }
      node.style.left = el.x + '%';
      node.style.top = el.y + '%';
      node.style.width = el.w + '%';
      if (el.type === 'text') {
        node.style.height = 'auto';
        node.style.fontFamily = fontCss(el.font);
        node.style.fontSize = (el.size * stage.clientWidth / 100) + 'px';
        node.style.fontWeight = el.weight >= 600 ? 700 : 400;
        node.style.fontStyle = el.italic ? 'italic' : 'normal';
        node.style.textAlign = el.align || 'left';
        node.style.color = el.color;
        node.style.lineHeight = String(el.lh || 1.18);
      } else {
        node.style.height = el.h + '%';
        if (el.type === 'rect') node.style.background = el.color;
        if (el.type === 'ellipse') { node.style.background = el.color; node.style.borderRadius = '50%'; }
        if (el.type === 'frame') {
          node.style.background = 'transparent';
          node.style.border = Math.max(2, el.thick * stage.clientWidth / 100) + 'px solid ' + el.color;
        }
      }
    }

    // ── Sélection, poignées, barre d'outils ──
    function select(el) {
      sel = el;
      stage.querySelectorAll('.ed-el').forEach(n => n.classList.toggle('selected', !!el && n.dataset.id === el.id));
      stage.querySelectorAll('.ed-handle').forEach(h => h.remove());
      if (el && el.type !== 'poly') addHandles(el);
      toolbar();
    }

    function addHandles(el) {
      const node = stage.querySelector('.ed-el[data-id="' + el.id + '"]');
      if (!node) return;
      const mk = (cls) => {
        const h = document.createElement('div');
        h.className = 'ed-handle ' + cls;
        h.onpointerdown = ev => startResize(ev, el, node, cls);
        stage.appendChild(h);
        placeHandle(h, node, cls);
        return h;
      };
      mk('e');
      if (el.type !== 'text') { mk('s'); mk('se'); }
    }

    function placeHandle(h, node, cls) {
      const x = node.offsetLeft, y = node.offsetTop, w = node.offsetWidth, hh = node.offsetHeight;
      if (cls === 'e') { h.style.left = (x + w - 5) + 'px'; h.style.top = (y + hh / 2 - 5) + 'px'; }
      if (cls === 's') { h.style.left = (x + w / 2 - 5) + 'px'; h.style.top = (y + hh - 5) + 'px'; }
      if (cls === 'se') { h.style.left = (x + w - 5) + 'px'; h.style.top = (y + hh - 5) + 'px'; }
    }

    function refreshHandles(el) {
      const node = stage.querySelector('.ed-el[data-id="' + el.id + '"]');
      stage.querySelectorAll('.ed-handle').forEach(h => {
        placeHandle(h, node, h.classList.contains('se') ? 'se' : h.classList.contains('s') ? 's' : 'e');
      });
    }

    // ── Glisser-déposer avec traits d'alignement magnétiques ──
    function startDrag(ev, el, node) {
      if (node.dataset.editing === '1') return;
      ev.preventDefault();
      select(el);
      if (el.type === 'poly') return; // formes de fond : couleur éditable, position fixe
      const sx = ev.clientX, sy = ev.clientY, ox = el.x, oy = el.y;
      node.classList.add('dragging');
      node.setPointerCapture(ev.pointerId);
      node.onpointermove = e => {
        let nx = ox + (e.clientX - sx) * 100 / stage.clientWidth;
        let ny = oy + (e.clientY - sy) * 100 / stage.clientHeight;
        const hPct = node.offsetHeight * 100 / stage.clientHeight;
        [nx, ny] = snap(el, nx, ny, el.w, hPct);
        el.x = Math.round(nx * 10) / 10;
        el.y = Math.round(ny * 10) / 10;
        applyStyle(node, el);
        refreshHandles(el);
      };
      node.onpointerup = e => {
        node.classList.remove('dragging');
        node.releasePointerCapture(e.pointerId);
        node.onpointermove = null; node.onpointerup = null;
        hideGuides();
        save();
      };
    }

    function snap(el, nx, ny, w, h) {
      const T = 1.1; // seuil magnétique en %
      const xs = [{ v: 50 - w / 2, g: 50 }, { v: 8, g: 8 }, { v: 92 - w, g: 92 }];
      const ys = [{ v: 50 - h / 2, g: 50 }];
      (S.editorEls || []).forEach(other => {
        if (other.id === el.id || other.type === 'poly') return;
        const oh = other.type === 'text'
          ? (stage.querySelector('.ed-el[data-id="' + other.id + '"]') || { offsetHeight: 0 }).offsetHeight * 100 / stage.clientHeight
          : other.h;
        [other.x, other.x + other.w / 2 - w / 2, other.x + other.w - w].forEach((v, i) =>
          xs.push({ v, g: i === 1 ? other.x + other.w / 2 : (i === 0 ? other.x : other.x + other.w) }));
        [other.y, other.y + oh / 2 - h / 2, other.y + oh - h].forEach((v, i) =>
          ys.push({ v, g: i === 1 ? other.y + oh / 2 : (i === 0 ? other.y : other.y + oh) }));
      });
      let gx = null, gy = null;
      for (const c of xs) if (Math.abs(nx - c.v) < T) { nx = c.v; gx = c.g; break; }
      for (const c of ys) if (Math.abs(ny - c.v) < T) { ny = c.v; gy = c.g; break; }
      showGuides(gx, gy);
      return [nx, ny];
    }

    function showGuides(gx, gy) {
      if (gx !== null) {
        if (!guideV) { guideV = document.createElement('div'); guideV.className = 'ed-guide v'; stage.appendChild(guideV); }
        guideV.style.left = gx + '%'; guideV.style.display = 'block';
      } else if (guideV) guideV.style.display = 'none';
      if (gy !== null) {
        if (!guideH) { guideH = document.createElement('div'); guideH.className = 'ed-guide h'; stage.appendChild(guideH); }
        guideH.style.top = gy + '%'; guideH.style.display = 'block';
      } else if (guideH) guideH.style.display = 'none';
    }

    function hideGuides() {
      if (guideV) guideV.style.display = 'none';
      if (guideH) guideH.style.display = 'none';
    }

    function startResize(ev, el, node, mode) {
      ev.preventDefault(); ev.stopPropagation();
      const sx = ev.clientX, sy = ev.clientY, ow = el.w, oh = el.h || 10;
      const h = ev.target;
      h.setPointerCapture(ev.pointerId);
      h.onpointermove = e => {
        if (mode === 'e' || mode === 'se') el.w = Math.max(4, Math.round((ow + (e.clientX - sx) * 100 / stage.clientWidth) * 10) / 10);
        if ((mode === 's' || mode === 'se') && el.type !== 'text') el.h = Math.max(1, Math.round((oh + (e.clientY - sy) * 100 / stage.clientHeight) * 10) / 10);
        applyStyle(node, el);
        refreshHandles(el);
      };
      h.onpointerup = e => {
        h.releasePointerCapture(e.pointerId);
        h.onpointermove = null; h.onpointerup = null;
        save();
      };
    }

    // ── Édition de texte en place ──
    function startTextEdit(node, el) {
      const inner = node.querySelector('.ed-text-inner');
      node.dataset.editing = '1';
      inner.contentEditable = 'true';
      inner.focus();
      document.getSelection().selectAllChildren(inner);
      inner.onblur = () => {
        inner.contentEditable = 'false';
        node.dataset.editing = '0';
        el.text = inner.textContent.trim();
        if (['title', 'tagline', 'subtitle'].includes(el.id) && S.cover) {
          S.cover.texts[el.id] = el.text; // miroir vers les champs du panneau
          const field = document.querySelector('[data-covertext="' + el.id + '"]');
          if (field) field.value = el.text;
        }
        save();
      };
    }

    function onKeys(e) {
      if (S.step !== 4 || !sel || !stage) return;
      if (document.activeElement && (document.activeElement.isContentEditable
          || ['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement.tagName))) return;
      const step = e.shiftKey ? 2 : 0.4;
      let used = true;
      if (e.key === 'ArrowLeft') sel.x -= step;
      else if (e.key === 'ArrowRight') sel.x += step;
      else if (e.key === 'ArrowUp') sel.y -= step;
      else if (e.key === 'ArrowDown') sel.y += step;
      else if (e.key === 'Delete' || e.key === 'Backspace') { removeSelected(); return; }
      else if (e.key === 'Escape') { select(null); return; }
      else used = false;
      if (used) {
        e.preventDefault();
        const node = stage.querySelector('.ed-el[data-id="' + sel.id + '"]');
        applyStyle(node, sel);
        refreshHandles(sel);
        save();
      }
    }

    function removeSelected() {
      if (!sel || sel.id === 'fond') { toast('Le fond ne peut pas être supprimé.', true); return; }
      S.editorEls = S.editorEls.filter(e => e.id !== sel.id);
      sel = null;
      init();
      save();
    }

    function reorder(delta) {
      if (!sel) return;
      const i = S.editorEls.findIndex(e => e.id === sel.id);
      const j = i + delta;
      if (i < 0 || j < 1 || j >= S.editorEls.length) return; // le fond reste dessous
      [S.editorEls[i], S.editorEls[j]] = [S.editorEls[j], S.editorEls[i]];
      const keep = sel;
      init();
      select(findEl(keep.id));
      save();
    }

    // ── Barre d'outils contextuelle ──
    function toolbar() {
      const bar = document.getElementById('ed-toolbar');
      if (!bar) return;
      bar.innerHTML = '';
      const add = html => { bar.insertAdjacentHTML('beforeend', html); return bar.lastElementChild; };

      const btnText = add('<div class="tb-btn" title="Ajouter un texte">＋ Texte</div>');
      btnText.onclick = () => {
        const el = { id: 'txt' + Date.now() % 100000, type: 'text', text: 'Votre texte', x: 10, y: 40, w: 80,
          font: 'instrument-serif', size: 3.5, weight: 400, italic: false, align: 'left',
          color: '#1A1A17', lh: 1.2, maxLines: 0 };
        S.editorEls.push(el);
        init();
        select(findEl(el.id));
        save();
      };

      if (!sel) {
        add('<span class="tb-hint">Cliquez un élément pour l\'éditer · glissez pour déplacer (traits d\'alignement) · double-clic = modifier le texte · flèches = déplacement fin</span>');
        return;
      }
      add('<div class="tb-sep"></div>');

      if (sel.type === 'text') {
        // Police avec aperçu
        const fontSel = add('<select title="Police"></select>');
        (S.coverFonts || []).forEach(f => {
          const o = document.createElement('option');
          o.value = f.slug;
          o.textContent = f.label;
          o.style.fontFamily = f.css;
          o.style.fontSize = '15px';
          if (f.slug === sel.font) o.selected = true;
          fontSel.appendChild(o);
        });
        const fontPrev = add('<span class="font-preview" style="font-family:' + fontCss(sel.font) + ';">AaBb</span>');
        fontSel.onchange = () => { sel.font = fontSel.value; fontPrev.style.fontFamily = fontCss(sel.font);
          if (!fontHasItalic(sel.font)) sel.italic = false; applySel(); toolbar(); };

        const size = add('<input type="number" min="1" max="15" step="0.2" value="' + sel.size + '" title="Taille (% de la largeur)">');
        size.onchange = () => { sel.size = Math.max(1, Math.min(15, parseFloat(size.value) || 3)); applySel(); };

        const b = add('<div class="tb-btn bold ' + (sel.weight >= 600 ? 'on' : '') + '" title="Gras">G</div>');
        b.onclick = () => { sel.weight = sel.weight >= 600 ? 400 : 700; b.classList.toggle('on'); applySel(); };
        const i = add('<div class="tb-btn italic ' + (sel.italic ? 'on' : '') + '" title="Italique"' + (fontHasItalic(sel.font) ? '' : ' style="opacity:.35;pointer-events:none;"') + '>I</div>');
        i.onclick = () => { sel.italic = !sel.italic; i.classList.toggle('on'); applySel(); };

        ['left', 'center', 'right'].forEach(a => {
          const btn = add('<div class="tb-btn ' + (sel.align === a ? 'on' : '') + '" title="Aligner">' + (a === 'left' ? '⇤' : a === 'center' ? '↔' : '⇥') + '</div>');
          btn.onclick = () => { sel.align = a; applySel(); toolbar(); };
        });

        const col = add('<input type="color" value="' + toHex6(sel.color) + '" title="Couleur du texte">');
        col.oninput = () => { sel.color = col.value; applySel(); };

      } else if (sel.type === 'motif') {
        const mSel = add('<select title="Motif"></select>');
        (S.coverMotifs || []).forEach(m => {
          const o = document.createElement('option');
          o.value = m; o.textContent = m;
          if (m === sel.motif) o.selected = true;
          mSel.appendChild(o);
        });
        mSel.onchange = () => { sel.motif = mSel.value; rebuildSel(); };
        const c1 = add('<input type="color" value="' + toHex6(sel.color) + '" title="Couleur 1">');
        c1.oninput = () => { sel.color = c1.value; rebuildSel(); };
        const c2 = add('<input type="color" value="' + toHex6(sel.color2 || '#F4EFE4') + '" title="Couleur 2">');
        c2.oninput = () => { sel.color2 = c2.value; rebuildSel(); };

      } else if (sel.type === 'image') {
        add('<span class="tb-hint">Illustration IA — glissez/redimensionnez librement</span>');
        const round = add('<div class="tb-btn ' + (sel.round ? 'on' : '') + '" title="Rogner en cercle">◯</div>');
        round.onclick = () => { sel.round = !sel.round; round.classList.toggle('on'); rebuildSel(); };

      } else {
        const col = add('<input type="color" value="' + toHex6(sel.color) + '" title="Couleur">');
        col.oninput = () => { sel.color = col.value; applySel(); };
      }

      add('<div class="tb-sep"></div>');
      const up = add('<div class="tb-btn" title="Passer au premier plan">⬆</div>');
      up.onclick = () => reorder(1);
      const down = add('<div class="tb-btn" title="Passer à l\'arrière-plan">⬇</div>');
      down.onclick = () => reorder(-1);
      if (sel.id !== 'fond') {
        const del = add('<div class="tb-btn" title="Supprimer" style="color:var(--accent);">🗑</div>');
        del.onclick = removeSelected;
      }
    }

    function applySel() {
      const node = stage.querySelector('.ed-el[data-id="' + sel.id + '"]');
      applyStyle(node, sel);
      refreshHandles(sel);
      save();
    }

    function rebuildSel() {
      const keep = sel;
      init();
      select(findEl(keep.id));
      save();
    }

    function toHex6(c) {
      c = String(c || '#000000');
      return /^#[0-9a-fA-F]{6}$/.test(c) ? c : '#1B2A4A';
    }

    function save() {
      clearTimeout(saveTimer);
      saveTimer = setTimeout(() => {
        Api.post('coverstudio/layout-save', { id: S.project.id, els: S.editorEls, face: S.coverFace || 'front' })
          .then(() => { if (S.coverFace === 'back') refreshBackPreview(); })
          .catch(e => toast(e.message, true));
      }, 600);
    }

    function syncTexts(texts) {
      (S.editorEls || []).forEach(el => {
        if (el.type === 'text' && ['title', 'tagline', 'subtitle'].includes(el.id) && texts[el.id] !== undefined) {
          el.text = texts[el.id];
          const node = stage && stage.querySelector('.ed-el[data-id="' + el.id + '"] .ed-text-inner');
          if (node) node.textContent = el.text;
        }
      });
    }

    return { init, syncTexts };
  })();

  // ── Barre de pied ────────────────────────────────────────────────────────

  function footerView() {
    if (!S.project || S.step >= 7) return '';
    const p = S.project;
    const theme = currentThemes().find(t => Number(t.id) === Number(p.theme_id));
    const concept = S.bundle.concepts.find(c => Number(c.id) === Number(p.concept_id));
    const words = Math.round(p.pages * 285 / 100) * 100;
    const st = S.writer.status;

    const map = {
      1: ['Thème retenu', theme ? theme.name : '—', 'Voir 8 idées de livres', !!theme, 'App.next1()'],
      2: ['Livre retenu', concept ? concept.title : '—', 'Générer le sommaire', !!concept, 'App.next2()'],
      3: ['Sommaire', S.bundle.toc.length ? `${S.bundle.toc.length} chapitres · ${nf(words)} mots` : '—', 'Valider et créer la couverture', S.bundle.toc.length > 0, 'App.next3()'],
      4: ['Couverture', S.cover ? (S.cover.texts.title || '—') : '—', 'Valider et lancer la rédaction', !!S.cover, 'App.next4()'],
      5: ['Rédaction', st ? `${st.progress} % · reprise automatique active` : '—', 'Lire les chapitres', !!(st && st.progress > 0), 'App.goStep(6)'],
      6: ['Manuscrit', st ? `Relecture ${S.reader.num} / ${st.chapter_total}` : '—', 'Passer à la mise en page', true, 'App.next6()']
    };
    const [key, value, nextLabel, enabled, handler] = map[S.step];
    const busyNext = S.busy.next;

    return `
    <div class="footer-bar">
      <div class="info"><span class="key">${key}</span><span class="val">${esc(value)}</span></div>
      <div class="actions">
        ${S.step > 1 ? `<button class="btn btn-ghost" onclick="App.goStep(${S.step - 1})">Retour</button>` : ''}
        <button class="btn btn-primary" onclick="${handler}" ${enabled && !busyNext ? '' : 'disabled'}>
          ${busyNext ? '<span class="spinner"></span> ' : ''}${nextLabel}
        </button>
      </div>
    </div>`;
  }

  // ── Modales ──────────────────────────────────────────────────────────────

  function closeModal() { S.modal = null; render(); }

  /**
   * Langue du livre : elle commande la langue des métadonnées Amazon (titre,
   * description, mots-clés, catégories) ET des libellés composés dans le livre
   * (sommaire, encadrés, pages de fin). Déduite automatiquement — traduction,
   * sinon détection sur le texte — et corrigeable ici.
   */
  function langRowView() {
    const lang = S.kdpLang;
    if (!lang) return '';
    const langs = S.kdpLangs || [];
    return `
      <div class="row lang-row">
        <label>Langue du livre
          <select id="kdp-lang" onchange="App.setBookLang(this.value)">
            ${langs.map(l => `<option value="${esc(l.code)}" ${l.code === lang.code ? 'selected' : ''}>${esc(l.name)}${l.native !== l.name ? ' · ' + esc(l.native) : ''}</option>`).join('')}
          </select>
        </label>
        <div class="faint" style="font-size:11.5px; margin-top:6px;">
          Métadonnées, mots-clés et catégories sont rédigés dans cette langue, sur ${esc(lang.marketplace)} —
          tout comme le sommaire, les encadrés et les pages de fin du livre.
        </div>
      </div>`;
  }

  function publishModalView(meta, tokens) {
    const kw = Array.from({ length: 7 }, (_, i) => (meta.keywords || [])[i] || '');
    return `
    <div class="modal-backdrop" onclick="if(event.target===this)App.closeModal()">
      <div class="modal">
        <h2>Publier sur Amazon KDP</h2>
        <div class="sub">Métadonnées du formulaire KDP + remplissage automatique par userscript. Vous gardez le contrôle : le script remplit les champs dans votre navigateur connecté à KDP, le clic final « Publier » reste le vôtre.</div>

        ${langRowView()}
        <div class="row"><label>Sous-titre<input type="text" id="kdp-subtitle" value="${esc(meta.subtitle)}"></label></div>
        <div class="grid2 row">
          <label>Prénom auteur<input type="text" id="kdp-first" value="${esc(meta.author_first)}"></label>
          <label>Nom auteur<input type="text" id="kdp-last" value="${esc(meta.author_last)}"></label>
        </div>
        <div class="row"><label>Description Amazon (HTML limité)<textarea id="kdp-desc" rows="6">${esc(meta.description_html)}</textarea></label></div>
        <div class="row">
          <label style="margin-bottom:7px;">7 mots-clés de recherche</label>
          <div class="kw-grid">${kw.map((k, i) => `<input type="text" id="kdp-kw${i}" value="${esc(k)}" placeholder="Mot-clé ${i + 1}">`).join('')}</div>
        </div>
        <div class="row"><label>Catégories (3 max, une par ligne)<textarea id="kdp-cats" rows="3">${esc((meta.categories || []).join('\n'))}</textarea></label></div>
        <div class="grid2 row">
          <label>Prix broché (€)<input type="number" step="0.01" id="kdp-price" value="${meta.price != null ? meta.price : ''}"></label>
          <label>ISBN <span class="faint" style="font-size:11px;">— laissez vide : ISBN gratuit KDP (par défaut)</span><input type="text" id="kdp-isbn" value="${esc(meta.isbn)}" placeholder="Gratuit attribué par Amazon"></label>
        </div>
        <div class="row"><label>ASIN une fois publié <span class="faint" style="font-size:11px;">(active le suivi de classement des mots-clés)</span><input type="text" id="kdp-asin" value="${esc(meta.asin || '')}" placeholder="B0XXXXXXXX"></label></div>
        <div style="display:flex; gap:10px; flex-wrap:wrap;">
          <button class="btn btn-soft" onclick="App.generateKdpMeta()" ${S.busy.kdpgen ? 'disabled' : ''}>${S.busy.kdpgen ? 'Génération…' : '✦ Générer avec l’IA'}</button>
          <button class="btn btn-primary" onclick="App.saveKdpMeta()" ${S.busy.kdpsave ? 'disabled' : ''}>${S.busy.kdpsave ? 'Enregistrement…' : 'Enregistrer les métadonnées'}</button>
        </div>

        <hr style="border:none; border-top:1px solid var(--line-soft); margin:22px 0;">
        <div class="title" style="font-weight:600; margin-bottom:6px;">Pack marketing</div>
        <div class="sub" style="margin-bottom:12px;">Contenu A+ (les modules visuels sous votre description Amazon) et mockup 3D, dans la charte de votre couverture.</div>
        <div style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:10px;">
          <button class="btn btn-soft" onclick="App.generateAplus()" ${S.busy.aplus ? 'disabled' : ''}>${S.busy.aplus ? '<span class="spinner"></span> Génération…' : '✦ Générer les textes A+'}</button>
          <button class="btn btn-ghost" onclick="window.open('api.php?r=kdpmeta/mockup&id=${S.project.id}', '_blank')">🧊 Mockup 3D (PNG)</button>
          <button class="btn btn-ghost" onclick="window.open('api.php?r=kdpmeta/aplus-image&id=${S.project.id}&type=banner', '_blank')">Bannière A+ 970×600</button>
          ${[1,2,3].map(i => `<button class="btn btn-ghost" onclick="window.open('api.php?r=kdpmeta/aplus-image&id=${S.project.id}&type=sq${i}', '_blank')">Pavé ${i} · 300×300</button>`).join('')}
        </div>
        ${meta.aplus ? `<div class="sub" style="background:#FBF8F0; border:1px solid var(--line); border-radius:10px; padding:12px 14px; margin-bottom:14px;">
          <strong>${esc(meta.aplus.headline || '')}</strong><br>${esc(meta.aplus.subheadline || '')}<br>
          ${(meta.aplus.benefits || []).map(b => `• <strong>${esc(b.title)}</strong> — ${esc(b.text)}`).join('<br>')}
          ${meta.aplus.about ? '<br><em>' + esc(meta.aplus.about) + '</em>' : ''}
        </div>` : ''}

        <div class="title" style="font-weight:600; margin-bottom:6px;">Classement de vos mots-clés</div>
        <div class="sub" style="margin-bottom:10px;">Position de votre ASIN dans la recherche Amazon pour chacun de vos 7 mots-clés (via vos crédits Canopy — résultats en cache 7 jours, « forcer » = 1 crédit par mot-clé).</div>
        <div style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:10px;">
          <button class="btn btn-soft" onclick="App.checkKeywordRanks(false)" ${S.busy.ranks ? 'disabled' : ''}>${S.busy.ranks ? '<span class="spinner"></span> Analyse…' : '🔎 Vérifier (cache)'}</button>
          <button class="btn btn-ghost" onclick="App.checkKeywordRanks(true)" ${S.busy.ranks ? 'disabled' : ''}>↻ Forcer un relevé frais</button>
        </div>
        ${(S.keywordRanks || []).length ? `<div style="margin-bottom:14px;">${S.keywordRanks.map(r => `
          <div class="token-row"><span>${esc(r.keyword)}</span>
          <span class="mono" style="color:${r.position ? (r.position <= 10 ? 'var(--green)' : 'var(--ink)') : 'var(--faint)'};">${r.position ? '#' + r.position : 'absent du top ' + r.scanned}</span></div>`).join('')}</div>` : ''}

        <hr style="border:none; border-top:1px solid var(--line-soft); margin:22px 0;">
        <div class="title" style="font-weight:600; margin-bottom:6px;">Remplissage automatique du formulaire KDP</div>
        <div class="sub" style="margin-bottom:12px;">
          1. Installez l'extension Tampermonkey puis <a href="assets/kdp-autofill.user.js" target="_blank">ouvrez le userscript</a> pour l'installer.<br>
          2. Créez un jeton ci-dessous et collez-le (avec l'URL de ce site) dans le panneau « ${esc(S.app.name)} » qui apparaît sur kdp.amazon.com.<br>
          3. Choisissez votre livre dans la liste : c'est votre seul geste. Chaque page du formulaire se remplit ensuite toute seule dès que ses champs apparaissent — titre, sous-titre, auteur, description, 7 mots-clés, droits, ISBN gratuit KDP, format, fond perdu, papier, finition et prix sur chaque boutique Amazon. Le clic « Publier » reste le vôtre.
        </div>
        ${tokens.map(t => `
        <div class="token-row">
          <span class="mono">${esc(t.token.slice(0, 10))}…${esc(t.token.slice(-6))} <span class="faint">· ${esc(t.label)}</span></span>
          <span style="display:flex; gap:10px;">
            <span style="color:var(--navy); cursor:pointer;" onclick="App.copyToken('${esc(t.token)}')">Copier</span>
            <span style="color:var(--accent); cursor:pointer;" onclick="App.revokeToken(${t.id})">Révoquer</span>
          </span>
        </div>`).join('')}
        <button class="btn btn-ghost" style="margin-top:12px;" onclick="App.createToken()">+ Créer un jeton d'accès</button>

        <div class="foot"><button class="btn btn-ghost" onclick="App.closeModal()">Fermer</button></div>
      </div>
    </div>`;
  }

  function notesModalView(title, notes) {
    return `
    <div class="modal-backdrop" onclick="if(event.target===this)App.closeModal()">
      <div class="modal">
        <h2>${esc(title)}</h2>
        <pre>${esc(notes)}</pre>
        <div class="foot"><button class="btn btn-primary" onclick="App.closeModal()">Fermer</button></div>
      </div>
    </div>`;
  }

  function connectorsModalView(connectors) {
    const gemini = connectors.gemini;
    const canopy = connectors.canopy;
    const sourceLabel = source => source === 'interface' ? 'clé saisie ici'
      : source === 'fichier' ? 'clé du fichier config.php' : 'non configurée';

    // Liste de modèles : ceux réellement disponibles pour la clé (API, cache 24 h),
    // complétés d'une liste standard et des valeurs actuelles.
    const FALLBACK_MODELS = ['gemini-2.5-flash', 'gemini-2.5-pro', 'gemini-2.5-flash-lite', 'gemini-2.0-flash'];
    const seen = new Set();
    const models = [];
    (S.geminiModels || []).forEach(m => { if (!seen.has(m.id)) { seen.add(m.id); models.push(m); } });
    FALLBACK_MODELS.concat([gemini.model_fast, gemini.model_pro]).filter(Boolean).forEach(id => {
      if (!seen.has(id)) { seen.add(id); models.push({ id, label: id }); }
    });
    const modelSelect = (domId, current) => `
      <select id="${domId}">
        ${models.map(m => `<option value="${esc(m.id)}" ${m.id === current ? 'selected' : ''}>${esc(m.label !== m.id ? m.label + ' — ' + m.id : m.id)}</option>`).join('')}
      </select>`;
    const testResult = key => {
      const r = (S.testResults || {})[key];
      if (!r) return '';
      return `<div class="note ${r.ok ? 'note-ok' : 'note-warn'}" style="margin:12px 0 0; white-space:pre-line; word-break:break-word;">${r.ok ? '✓' : '!'} ${esc(r.message)}</div>`;
    };
    return `
    <div class="modal-backdrop" onclick="if(event.target===this)App.closeModal()">
      <div class="modal">
        <h2>⚡ Connecteurs</h2>
        <div class="sub">Collez vos clés API ici : elles sont enregistrées en base et priment sur <span class="mono" style="font-size:12px;">config/config.php</span>. Laissez un champ clé vide pour ne pas la changer, tapez <span class="mono" style="font-size:12px;">-</span> pour l'effacer.</div>

        <div style="border:1px solid var(--line); border-radius:12px; padding:16px; margin-bottom:14px;">
          <div style="display:flex; justify-content:space-between; align-items:baseline; gap:10px;">
            <div style="font-weight:600;">Google Gemini <span class="faint" style="font-weight:400;">— IA (obligatoire)</span></div>
            <span class="badge ${gemini.source ? 'badge-green' : 'badge-orange'}">${sourceLabel(gemini.source)}</span>
          </div>
          <div class="row" style="margin-top:12px;">
            <label>Clé API ${gemini.masked ? `<span class="faint">(actuelle : ${esc(gemini.masked)})</span>` : ''}
              <input type="password" id="cn-gemini-key" placeholder="${gemini.masked ? 'inchangée' : 'Collez votre clé — aistudio.google.com/apikey'}" autocomplete="off">
            </label>
          </div>
          <div class="grid2 row">
            <label>Modèle rapide <span class="faint">(analyses, sommaire…)</span>${modelSelect('cn-model-fast', gemini.model_fast)}</label>
            <label>Modèle qualité <span class="faint">(rédaction)</span>${modelSelect('cn-model-pro', gemini.model_pro)}</label>
          </div>
          ${S.geminiModels && S.geminiModels.length ? `<div class="faint" style="font-size:11.5px; margin:-4px 0 10px;">${S.geminiModels.length} modèles détectés pour votre clé.</div>` : ''}
          <button class="btn btn-ghost" style="padding:8px 14px;" onclick="App.testGemini()" ${S.busy.testGemini ? 'disabled' : ''}>${S.busy.testGemini ? '<span class="spinner" style="border-color:var(--navy); border-top-color:transparent;"></span> Test en cours (jusqu’à 45 s)…' : 'Tester la connexion'}</button>
          ${testResult('gemini')}
        </div>

        <div style="border:1px solid var(--line); border-radius:12px; padding:16px;">
          <div style="display:flex; justify-content:space-between; align-items:baseline; gap:10px;">
            <div style="font-weight:600;">Canopy API <span class="faint" style="font-weight:400;">— vraies données Amazon (optionnel)</span></div>
            <span class="badge ${canopy.source ? 'badge-green' : 'badge-neutral'}">${sourceLabel(canopy.source)}</span>
          </div>
          <div class="sub" style="margin:8px 0 0;">Version gratuite sur <a href="https://www.canopyapi.co" target="_blank" rel="noopener">canopyapi.co</a> : l'analyse de niche et les concepts s'appuient alors sur les vrais résultats Amazon (titres, prix, notes, volume d'avis). Cache 7 jours pour économiser le quota${canopy.status && canopy.status.enabled ? ` · <strong>${canopy.status.used}/${canopy.status.budget}</strong> ${canopy.status.real ? '<span style="color:var(--green);">(en direct depuis Canopy)</span>' : '<span>(estimation locale · <span style="color:var(--accent); cursor:pointer;" onclick="App.resetCanopyUsage()">réinitialiser</span>)</span>'}` : ''}.</div>
          <div class="row" style="margin-top:12px;">
            <label>Clé API ${canopy.masked ? `<span class="faint">(actuelle : ${esc(canopy.masked)})</span>` : ''}
              <input type="password" id="cn-canopy-key" placeholder="${canopy.masked ? 'inchangée' : 'Collez votre clé Canopy'}" autocomplete="off">
            </label>
          </div>
          <div class="grid2 row">
            <label>Place de marché
              <select id="cn-canopy-domain">
                ${['FR', 'US', 'UK', 'DE', 'ES', 'IT', 'CA'].map(d => `<option value="${d}" ${canopy.domain === d ? 'selected' : ''}>Amazon.${d === 'UK' ? 'co.uk' : d === 'US' ? 'com' : d.toLowerCase()}</option>`).join('')}
              </select>
            </label>
            <div style="display:flex; align-items:flex-end;">
              <button class="btn btn-ghost" style="padding:8px 14px; width:100%;" onclick="App.testCanopy()" ${S.busy.testCanopy ? 'disabled' : ''}>${S.busy.testCanopy ? '<span class="spinner" style="border-color:var(--navy); border-top-color:transparent;"></span> Test…' : 'Tester la connexion · 1 crédit'}</button>
            </div>
          </div>
          ${testResult('canopy')}
        </div>

        <div class="foot" style="align-items:center;">
          <span class="faint" style="font-size:11px; margin-right:auto;">Version ${BUILD}</span>
          <button class="btn btn-ghost" onclick="App.closeModal()">Fermer</button>
          <button class="btn btn-primary" onclick="App.saveConnectors()" ${S.busy.connectors ? 'disabled' : ''}>${S.busy.connectors ? '<span class="spinner"></span> ' : ''}Enregistrer</button>
        </div>
      </div>
    </div>`;
  }

  function toneModalView() {
    return `
    <div class="modal-backdrop" onclick="if(event.target===this)App.closeModal()">
      <div class="modal" style="max-width:440px;">
        <h2>Réécrire ce chapitre</h2>
        <div class="sub">Choisissez le nouveau ton : le contenu et la structure sont conservés.</div>
        <div class="chip-row">
          ${TONES.map(t => `<div class="chip" onclick="App.rewriteWithTone('${esc(t)}')">${t}</div>`).join('')}
        </div>
        <div class="foot"><button class="btn btn-ghost" onclick="App.closeModal()">Annuler</button></div>
      </div>
    </div>`;
  }

  // ── Actions exposées ─────────────────────────────────────────────────────

  window.App = {

    async login(event) {
      event.preventDefault();
      // Lire les champs AVANT tout re-rendu (setBusy reconstruit le formulaire)
      const email = document.getElementById('login-email').value;
      const password = document.getElementById('login-pass').value;
      setBusy('login', true);
      try {
        const data = await Api.post('auth/login', { email, password });
        S.user = data.user;
        Api.setCsrf(data.csrf);
        const me = await Api.get('auth/me'); // statut des connecteurs (Canopy…) une fois connecté
        S.app = me.app || S.app;
        await loadProjects();
        S.view = 'dashboard';
        S.bootError = null;
      } catch (e) { toast(e.message, true); }
      setBusy('login', false);
    },

    async logout() {
      if (!confirm('Se déconnecter ?')) return;
      try { await Api.post('auth/logout', {}); } catch (_) {}
      window.location.reload();
    },

    async home() {
      S.writer.looping = false;
      await loadProjects();
      S.view = 'dashboard';
      S.project = null;
      render();
    },

    open(id) { openProject(id); },

    async createProject() {
      setBusy('create', true);
      try {
        const data = await Api.post('projects/create', {});
        await openProject(data.project.id, 1);
      } catch (e) { toast(e.message, true); }
      setBusy('create', false);
    },

    goStep, closeModal,

    // Étape 1
    async setMode(mode) {
      S.project.mode = mode;
      try { await Api.post('projects/update', { id: S.project.id, mode }); } catch (_) {}
      render();
      if (mode === 'trends' && S.bundle.themes.trends.length === 0) App.loadTrends();
    },

    ideaChanged() {
      const value = document.getElementById('idea-text').value;
      S.project.idea = value;
      debounce('idea', () => Api.post('projects/update', { id: S.project.id, idea: value }).catch(() => {}));
    },

    addChip(text) {
      const area = document.getElementById('idea-text');
      area.value = (area.value.trim() + ' ' + text + '.').trim();
      App.ideaChanged();
    },

    pickImportPdf() {
      const input = document.createElement('input');
      input.type = 'file';
      input.accept = 'application/pdf,.pdf';
      input.onchange = async () => {
        if (!input.files.length) return;
        const form = new FormData();
        form.append('id', S.project.id);
        form.append('file', input.files[0]);
        setBusy('importan', true);
        try {
          const data = await Api.upload('import/analyze', form);
          S.importAnalysis = data.analysis;
          S.importMode = S.importMode || 'identique';
          toast(data.analysis.chapters.length + ' chapitres détectés · ' + nf(data.analysis.words_total) + ' mots.');
        } catch (e) { toast(e.message, true); }
        setBusy('importan', false);
      };
      input.click();
    },

    setImportMode(mode) { S.importMode = mode; render(); },
    setImportOwned(v) { S.importOwned = !!v; render(); },

    async applyImport() {
      if (!S.importAnalysis) return;
      const mode = S.importMode || 'identique';
      const title = (document.getElementById('import-title') || {}).value || '';
      setBusy('importap', true);
      try {
        const data = await Api.post('import/apply', {
          id: S.project.id, mode, title, owned: S.importOwned ? 1 : 0
        });
        S.project = data.project;
        S.importAnalysis = null;
        await refreshProject();
        S.step = data.step;
        enterStep();
        toast(mode === 'identique'
          ? data.chapters + ' chapitres repris (' + nf(data.words) + ' mots) — à vous la couverture.'
          : 'Plan importé — définissez le sommaire puis lancez la rédaction.');
      } catch (e) { toast(e.message, true); }
      setBusy('importap', false);
      render();
      window.scrollTo(0, 0);
    },

    async analyze() {
      const idea = (document.getElementById('idea-text') || {}).value || S.project.idea || '';
      setBusy('analyze', true);
      try {
        const data = await Api.post('market/analyze', { id: S.project.id, idea });
        S.bundle.themes.analysis = data.themes;
        S.groundedAnalysis = !!data.grounded;
        S.project.mode = 'describe';
      } catch (e) { toast(e.message, true); }
      setBusy('analyze', false);
    },

    async loadTrends() {
      setBusy('trends', true);
      try {
        const data = await Api.post('market/trends', { id: S.project.id });
        S.bundle.themes.trends = data.themes;
      } catch (e) { toast(e.message, true); }
      setBusy('trends', false);
    },

    async selectTheme(themeId) {
      try {
        const data = await Api.post('themes/select', { id: S.project.id, theme_id: themeId });
        S.project = data.project;
        render();
      } catch (e) { toast(e.message, true); }
    },

    async next1() {
      setBusy('next', true);
      try {
        if (S.bundle.concepts.length === 0) {
          const data = await Api.post('concepts/generate', { id: S.project.id });
          S.bundle.concepts = data.concepts;
          S.groundedConcepts = !!data.grounded;
        }
        S.step = 2;
      } catch (e) { toast(e.message, true); }
      setBusy('next', false);
      window.scrollTo(0, 0);
    },

    // Étape 2
    async generateConcepts() {
      setBusy('concepts', true);
      try {
        const data = await Api.post('concepts/generate', { id: S.project.id });
        S.bundle.concepts = data.concepts;
        S.groundedConcepts = !!data.grounded;
        S.project.concept_id = null;
      } catch (e) { toast(e.message, true); }
      setBusy('concepts', false);
    },

    async selectConcept(conceptId) {
      try {
        const data = await Api.post('concepts/select', { id: S.project.id, concept_id: conceptId });
        S.project = data.project;
        render();
      } catch (e) { toast(e.message, true); }
    },

    async next2() {
      setBusy('next', true);
      try {
        if (S.bundle.toc.length === 0) {
          const data = await Api.post('toc/generate', { id: S.project.id });
          S.bundle.toc = data.toc;
          S.project = data.project;
        }
        S.step = 3;
      } catch (e) { toast(e.message, true); }
      setBusy('next', false);
      window.scrollTo(0, 0);
    },

    // Étape 3
    setPages(value) { S.project.pages = Number(value); pushParams(); render(); },
    togglePhotos() { S.project.photos = Number(S.project.photos) === 1 ? 0 : 1; pushParams(); render(); },
    setPhotosPer(value) { S.project.photos_per = Number(value); pushParams(); render(); },
    setPhotoStyle(key) { S.project.photo_style = key; pushParams(); render(); },
    setTone(tone) { S.project.tone = tone; pushParams(); render(); },
    setTrim(trim) { S.project.trim_format = trim; pushParams(); render(); },

    async generateToc() {
      setBusy('toc', true);
      try {
        const data = await Api.post('toc/generate', {
          id: S.project.id, pages: S.project.pages, photos: S.project.photos,
          photos_per: S.project.photos_per, photo_style: S.project.photo_style,
          tone: S.project.tone, trim_format: S.project.trim_format
        });
        S.bundle.toc = data.toc;
        S.project = data.project;
      } catch (e) { toast(e.message, true); }
      setBusy('toc', false);
    },

    async addChapter() {
      const input = document.getElementById('toc-request');
      const request = input ? input.value.trim() : '';
      if (request.length < 8) { toast('Décrivez le chapitre souhaité en quelques mots.', true); return; }
      setBusy('addchapter', true);
      try {
        const data = await Api.post('toc/add-chapter', { id: S.project.id, request });
        S.bundle.toc = data.toc;
        S.project = data.project;
        toast(data.live
          ? '« ' + data.title + ' » inséré dans le livre — rédigez-le à l\'étape 05.'
          : '« ' + data.title + ' » ajouté au sommaire.');
      } catch (e) { toast(e.message, true); }
      setBusy('addchapter', false);
    },

    editTocTitle(index, text) {
      const title = String(text || '').trim();
      if (!title || !S.bundle.toc[index]) return;
      S.bundle.toc[index].title = title;
      debounce('tocsave', () => Api.post('toc/save', { id: S.project.id, toc: S.bundle.toc }).catch(e => toast(e.message, true)));
    },

    moveToc(index, delta) {
      const target = index + delta;
      if (target < 0 || target >= S.bundle.toc.length) return;
      const [item] = S.bundle.toc.splice(index, 1);
      S.bundle.toc.splice(target, 0, item);
      render();
      debounce('tocsave', () => Api.post('toc/save', { id: S.project.id, toc: S.bundle.toc }).catch(e => toast(e.message, true)));
    },

    async next3() {
      setBusy('next', true);
      try {
        await Api.post('toc/validate', { id: S.project.id });
        await refreshProject();
        S.step = 4;
        enterStep();
      } catch (e) { toast(e.message, true); }
      setBusy('next', false);
      window.scrollTo(0, 0);
    },

    // Étape 4 : couverture (studio)
    setCoverTemplate(slug) { S.cover.template = slug; render(); saveCover(); },
    setCoverColor(key, value) { S.cover.palette[key] = value; saveCover(); },
    setCoverText(key, value) { S.cover.texts[key] = value; saveCover(); },

    setCoverFace(face) {
      const next = face === 'back' ? 'back' : 'front';
      if (next === (S.coverFace || 'front')) return;
      // La face quittée garde ses éléments en mémoire (déjà enregistrés côté serveur)
      if ((S.coverFace || 'front') === 'back') S.coverElsBack = S.editorEls;
      else S.coverElsFront = S.editorEls;
      S.coverFace = next;
      S.editorEls = next === 'back' ? S.coverElsBack : S.coverElsFront;
      S.coverStamp = Date.now();
      render();
    },

    async resetFace() {
      const face = S.coverFace || 'front';
      if (!confirm(face === 'back'
        ? 'Revenir à la 4ème de couverture composée automatiquement ? Vos retouches sur cette face seront perdues.'
        : 'Revenir à la 1ère de couverture de la version choisie ? Vos retouches seront perdues.')) return;
      try {
        const data = await Api.post('coverstudio/layout-reset', { id: S.project.id, face });
        S.editorEls = data.els;
        if (face === 'back') S.coverElsBack = data.els; else S.coverElsFront = data.els;
        S.coverStamp = Date.now();
        render();
        toast('Composition automatique restaurée.');
      } catch (e) { toast(e.message, true); }
    },

    uploadCustomCover() {
      const input = document.createElement('input');
      input.type = 'file';
      input.accept = 'application/pdf,image/jpeg,image/png,.pdf,.jpg,.jpeg,.png';
      input.onchange = async () => {
        if (!input.files.length) return;
        const form = new FormData();
        form.append('id', S.project.id);
        form.append('file', input.files[0]);
        setBusy('customcov', true);
        try {
          const data = await Api.upload('coverstudio/upload-custom', form);
          S.coverCustom = data.custom;
          S.coverStamp = Date.now();
          toast('Couverture importée — elle sera utilisée telle quelle dans les exports.');
        } catch (e) { toast(e.message, true); }
        setBusy('customcov', false);
        render();
      };
      input.click();
    },

    async clearCustomCover() {
      if (!confirm('Revenir à la couverture composée dans le studio ?')) return;
      try {
        const data = await Api.post('coverstudio/clear-custom', { id: S.project.id, kind: '' });
        S.coverCustom = data.custom;
        S.coverStamp = Date.now();
        render();
      } catch (e) { toast(e.message, true); }
    },

    async selectIllustration(slug) {
      try {
        const data = await Api.post('coverstudio/illus-select', { id: S.project.id, slug });
        S.coverLibrary = data.library;
        S.coverHasIllustration = true;
        S.coverStamp = Date.now();
        render();
        toast('Illustration appliquée à la couverture.');
      } catch (e) { toast(e.message, true); }
    },

    async deleteIllustration(slug) {
      if (!confirm('Supprimer cette création de la bibliothèque ?')) return;
      try {
        const data = await Api.post('coverstudio/illus-delete', { id: S.project.id, slug });
        S.coverLibrary = data.library;
        S.coverHasIllustration = data.library.length > 0;
        S.coverStamp = Date.now();
        render();
      } catch (e) { toast(e.message, true); }
    },

    async newCoverVariants() {
      S.coverSeed = (S.coverSeed || 1) + 1;
      await loadCoverVariants();
      render();
    },

    async pickCoverVariant(index) {
      const v = (S.coverVariants || [])[index];
      if (!v) return;
      try {
        const data = await Api.post('coverstudio/select', {
          id: S.project.id, layout: v.layout, motif: v.motif, palette: v.palette
        });
        S.cover = data.cover;
        S.editorEls = data.cover.els; // la version choisie devient éditable
        S.coverVariants.forEach((x, i) => { x.selected = i === index; });
        render();
        injectCoverPreviews();
      } catch (e) { toast(e.message, true); }
    },

    async generateIllustration() {
      const prompt = (document.getElementById('cover-illus-prompt') || {}).value || '';
      setBusy('illus', true);
      try {
        const data = await Api.post('coverstudio/illustration', { id: S.project.id, prompt });
        S.coverHasIllustration = true;
        S.coverStamp = Date.now();
        S.coverLibrary = data.library || S.coverLibrary || [];
        S.coverFace = 'front';   // la nouvelle illustration s'applique à la 1ère
        if (data.cover) { S.cover = data.cover; S.coverElsFront = data.cover.els; S.editorEls = data.cover.els; }
        if (S.coverVariants) S.coverVariants.forEach(v => { v.selected = false; });
        toast('Illustration générée et ajoutée à votre bibliothèque (' + (S.coverLibrary.length) + ') — vos créations précédentes sont conservées.');
      } catch (e) { toast(e.message, true); }
      setBusy('illus', false);
      render();
      injectCoverPreviews();
    },

    async clearIllustration() {
      try {
        await Api.post('coverstudio/clear-illustration', { id: S.project.id });
        S.coverHasIllustration = false;
        render();
        refreshFrontPreview();
      } catch (e) { toast(e.message, true); }
    },

    uploadCoverRef() {
      const input = document.createElement('input');
      input.type = 'file';
      input.accept = 'image/jpeg,image/png,image/webp';
      input.onchange = async () => {
        if (!input.files.length) return;
        const form = new FormData();
        form.append('id', S.project.id);
        form.append('file', input.files[0]);
        try {
          await Api.upload('coverstudio/upload-ref', form);
          S.coverHasRef = true;
          toast('Image d’inspiration enregistrée — elle guidera la prochaine génération.');
          render();
        } catch (e) { toast(e.message, true); }
      };
      input.click();
    },

    async generateBackText() {
      setBusy('back', true);
      try {
        const data = await Api.post('covers/generate-back', { id: S.project.id });
        S.cover.texts.tagline = data.generated.tagline || S.cover.texts.tagline;
        S.cover.texts.back_text = data.generated.back_text || S.cover.texts.back_text;
        S.cover.texts.bio = data.generated.bio || S.cover.texts.bio;
        await Api.post('covers/save', { id: S.project.id, template: S.cover.template, palette: S.cover.palette, texts: S.cover.texts });
      } catch (e) { toast(e.message, true); }
      setBusy('back', false);
      injectCoverPreviews();
    },

    async next4() {
      setBusy('next', true);
      try {
        await Api.post('covers/save', { id: S.project.id, template: S.cover.template, palette: S.cover.palette, texts: S.cover.texts });
        await Api.post('covers/validate', { id: S.project.id });
        await Api.post('write/start', { id: S.project.id });
        await refreshProject();
        S.step = 5;
        await enterWriting();
        if (!S.writer.looping) writeLoop();
      } catch (e) { toast(e.message, true); }
      setBusy('next', false);
      window.scrollTo(0, 0);
    },

    // Étape 5
    async startWriting() {
      try {
        await Api.post('write/start', { id: S.project.id });
        await enterWriting();
        if (!S.writer.looping) writeLoop();
      } catch (e) { toast(e.message, true); }
    },

    async pauseWriting() {
      S.writer.looping = false;
      try {
        const data = await Api.post('write/pause', { id: S.project.id });
        S.writer.status = data.status;
        await pullJournal();
        render();
      } catch (e) { toast(e.message, true); }
    },

    dismissIncident() { S.writer.incident = null; render(); },

    // Étape 6
    openChapter(num) {
      if (num < 1) return;
      loadChapter(num);
    },

    chapterAction(action) {
      if (S.busy.chapAction) return;
      if (action === 'rewrite') { S.modal = toneModalView(); render(); return; }
      runChapterAction(action, '');
    },

    rewriteWithTone(tone) {
      S.modal = null;
      runChapterAction('rewrite', tone);
    },

    openSectionEdit(sectionId) {
      const sec = ((S.reader.data || {}).sections || []).find(s => Number(s.id) === Number(sectionId));
      if (!sec) return;
      S.sectionRetouch = null;
      S.sectionEdit = { id: sectionId, content: sec.content || '' };
      render();
    },

    closeSectionEdit() { S.sectionEdit = null; render(); },

    async saveSectionEdit(sectionId) {
      const box = document.getElementById('sec-edit-' + sectionId);
      const content = box ? box.value : '';
      setBusy('secsave', true);
      try {
        await Api.post('sections/save', { id: S.project.id, section_id: sectionId, content });
        S.sectionEdit = null;
        toast('Section enregistrée.');
        await loadChapter(S.reader.num);
      } catch (e) { toast(e.message, true); }
      setBusy('secsave', false);
    },

    openSectionRetouch(sectionId) {
      S.sectionEdit = null;
      S.sectionRetouch = { id: sectionId, prompt: S.sectionRetouch && S.sectionRetouch.id === sectionId ? S.sectionRetouch.prompt : '' };
      render();
      const box = document.getElementById('sec-retouch-' + sectionId);
      if (box) box.focus();
    },

    closeSectionRetouch() { S.sectionRetouch = null; render(); },

    async runSectionRetouch(sectionId) {
      const box = document.getElementById('sec-retouch-' + sectionId);
      const instruction = box ? box.value.trim() : '';
      if (instruction.length < 4) { toast('Précisez votre consigne de retouche.', true); return; }
      if (S.sectionRetouch) S.sectionRetouch.prompt = instruction;
      setBusy('secretouch', true);
      try {
        await Api.post('sections/retouch', { id: S.project.id, section_id: sectionId, instruction });
        S.sectionRetouch = null;
        toast('Section retouchée — relisez le résultat.');
        await loadChapter(S.reader.num);
      } catch (e) { toast(e.message, true); }
      setBusy('secretouch', false);
    },

    openImageGen(imageId) {
      const img = ((S.reader.data || {}).images || []).find(i => Number(i.id) === Number(imageId)) || {};
      S.imageGen = { id: imageId, prompt: S.imageGen && S.imageGen.id === imageId ? S.imageGen.prompt : (img.caption || '') };
      render();
      const box = document.getElementById('ig-prompt');
      if (box) box.focus();
    },

    closeImageGen() { S.imageGen = null; render(); },

    async runImageGen() {
      if (!S.imageGen) return;
      const box = document.getElementById('ig-prompt');
      const prompt = box ? box.value.trim() : (S.imageGen.prompt || '');
      S.imageGen.prompt = prompt;      // conservé pour affiner à la prochaine passe
      setBusy('imagegen', true);
      try {
        await Api.post('images/generate', { id: S.project.id, image_id: S.imageGen.id, prompt });
        toast('Visuel généré — pas convaincu ? Précisez votre demande et regénérez.');
        await loadChapter(S.reader.num);
      } catch (e) { toast(e.message, true); }
      setBusy('imagegen', false);
    },

    uploadImage(imageId) {
      const input = document.createElement('input');
      input.type = 'file';
      input.accept = 'image/jpeg,image/png,image/webp';
      input.onchange = async () => {
        if (!input.files.length) return;
        const form = new FormData();
        form.append('id', S.project.id);
        form.append('image_id', imageId);
        form.append('file', input.files[0]);
        try {
          await Api.upload('images/upload', form);
          toast('Image enregistrée.');
          loadChapter(S.reader.num);
        } catch (e) { toast(e.message, true); }
      };
      input.click();
    },

    next6() { S.step = 7; enterStep(); render(); window.scrollTo(0, 0); },

    async duplicateProject(projectId) {
      try {
        const data = await Api.post('projects/duplicate', { id: projectId });
        S.projects = data.projects;
        render();
        toast('Projet dupliqué avec les mêmes réglages — décrivez la nouvelle idée à l\'étape 01.');
      } catch (e) { toast(e.message, true); }
    },

    importProject() {
      const input = document.createElement('input');
      input.type = 'file';
      input.accept = 'application/json,.json';
      input.onchange = async () => {
        if (!input.files.length) return;
        const form = new FormData();
        form.append('file', input.files[0]);
        try {
          const data = await Api.upload('projects/import', form);
          S.projects = data.projects;
          render();
          toast('Projet restauré : « ' + data.title + ' ».');
        } catch (e) { toast(e.message, true); }
      };
      input.click();
    },

    async setInteriorTheme(slug) {
      try {
        await Api.post('projects/update', { id: S.project.id, interior_theme: slug });
        S.project.interior_theme = slug;
        (S.interiorThemes || []).forEach(t => { t.selected = t.slug === slug; });
        render();
        const t = (S.interiorThemes || []).find(x => x.slug === slug);
        toast('Mise en page « ' + ((t && t.name) || slug) + ' » appliquée — l\'aperçu se recompose.');
      } catch (e) { toast(e.message, true); }
    },

    async setInteriorColor(key, value) {
      const colors = S.layoutColors || {};
      const payload = { accent: colors.accent, ink: colors.ink };
      payload[key] = value;
      try {
        await Api.post('projects/update', { id: S.project.id, interior_colors: payload });
        S.layoutColors = Object.assign({}, payload, { from_cover: false });
        S.layoutStamp = Date.now();      // l'aperçu PDF se recompose
        await reloadInteriorThemes();    // les vignettes suivent les couleurs
        render();
      } catch (e) { toast(e.message, true); }
    },

    async resetInteriorColors() {
      try {
        await Api.post('projects/update', { id: S.project.id, interior_colors: '' });
        S.layoutStamp = Date.now();
        await reloadInteriorThemes();
        render();
        toast('Couleurs de la couverture reprises.');
      } catch (e) { toast(e.message, true); }
    },

    async toggleLayoutOpt(key, checked) {
      const opts = S.layoutOptions || [];
      const opt = opts.find(o => o.key === key);
      if (!opt) return;
      // Garde-fou : au moins un gabarit de colonnes coché
      if (!checked && ['col1', 'col2', 'col3'].includes(key)
          && !opts.some(o => ['col1', 'col2', 'col3'].includes(o.key) && o.key !== key && o.on)) {
        toast('Gardez au moins un gabarit de colonnes (1, 2 ou 3 colonnes).', true);
        render();
        return;
      }
      opt.on = checked;
      const payload = {};
      opts.forEach(o => { payload[o.key] = !!o.on; });
      try {
        await Api.post('projects/update', { id: S.project.id, layout_options: payload });
        render(); // l'aperçu PDF se recompose avec les nouveaux ingrédients
      } catch (e) { opt.on = !checked; render(); toast(e.message, true); }
    },

    async setFinalPages(value) {
      try {
        await Api.post('projects/update', { id: S.project.id, final_pages: value });
        S.project.final_pages = (value === '' || parseInt(value, 10) <= 0) ? null : parseInt(value, 10);
        // Recharge la géométrie (tranche recalculée) selon l'écran courant
        if (S.step === 7) {
          S.layout = null;
          await loadLayout();
        } else if (S.step === 4) {
          const data = await Api.get('covers/get', { id: S.project.id });
          S.coverGeometry = data.geometry;
          render();
        }
        toast(S.project.final_pages
          ? 'Pagination forcée à ' + S.project.final_pages + ' pages — dos recalculé pour les exports.'
          : 'Retour à l\'estimation automatique de la pagination.');
      } catch (e) { toast(e.message, true); }
    },

    // Étape 7
    async openPublishModal() {
      try {
        const [metaData, tokenData] = await Promise.all([
          Api.get('kdpmeta/get', { id: S.project.id }),
          Api.get('tokens/list')
        ]);
        S.kdpMeta = metaData.meta;
        S.kdpLang = metaData.lang || null;      // langue effective du livre
        S.kdpLangs = metaData.langs || [];      // langues gérées par KDP
        S.tokens = tokenData.tokens;
        S.modal = publishModalView(S.kdpMeta, S.tokens);
        render();
      } catch (e) { toast(e.message, true); }
    },

    /** Correction manuelle de la langue du livre (fenêtre Publier). */
    async setBookLang(code) {
      try {
        const data = await Api.post('projects/lang', { id: S.project.id, lang: code });
        S.kdpLang = data.lang;
        toast('Langue du livre : ' + data.lang.fr + ' — métadonnées et libellés du livre suivront.');
        S.modal = publishModalView(S.kdpMeta, S.tokens);
        render();
      } catch (e) { toast(e.message, true); }
    },

    async generateKdpMeta() {
      setBusy('kdpgen', true);
      S.modal = publishModalView(S.kdpMeta, S.tokens); render();
      try {
        const data = await Api.post('kdpmeta/generate', { id: S.project.id });
        S.kdpMeta = data.meta;
        toast('Métadonnées générées.');
      } catch (e) { toast(e.message, true); }
      S.busy.kdpgen = false;
      S.modal = publishModalView(S.kdpMeta, S.tokens);
      render();
    },

    async saveKdpMeta() {
      // Lire les champs AVANT tout re-rendu (setBusy reconstruit la modale)
      const payload = {
        id: S.project.id,
        subtitle: document.getElementById('kdp-subtitle').value,
        author_first: document.getElementById('kdp-first').value,
        author_last: document.getElementById('kdp-last').value,
        description_html: document.getElementById('kdp-desc').value,
        keywords: Array.from({ length: 7 }, (_, i) => document.getElementById('kdp-kw' + i).value),
        categories: document.getElementById('kdp-cats').value.split('\n').map(s => s.trim()).filter(Boolean),
        price: document.getElementById('kdp-price').value,
        isbn: document.getElementById('kdp-isbn').value,
        asin: (document.getElementById('kdp-asin') || { value: '' }).value
      };
      setBusy('kdpsave', true);
      try {
        const data = await Api.post('kdpmeta/save', payload);
        S.kdpMeta = data.meta;
        toast('Métadonnées enregistrées.');
        loadLayout();
      } catch (e) { toast(e.message, true); }
      S.busy.kdpsave = false;
      S.modal = publishModalView(S.kdpMeta, S.tokens);
      render();
    },

    async generateAplus() {
      setBusy('aplus', true);
      try {
        const data = await Api.post('kdpmeta/aplus', { id: S.project.id });
        if (S.kdpMeta) S.kdpMeta.aplus = data.aplus;
        toast('Textes A+ générés — téléchargez la bannière et les pavés.');
      } catch (e) { toast(e.message, true); }
      S.busy.aplus = false;
      S.modal = publishModalView(S.kdpMeta, S.tokens);
      render();
    },

    async checkKeywordRanks(force) {
      setBusy('ranks', true);
      try {
        const data = await Api.post('kdpmeta/keyword-ranks', { id: S.project.id, force: force ? 1 : 0 });
        S.keywordRanks = data.ranks;
        if (S.app.canopy) S.app.canopy = data.canopy;
      } catch (e) { toast(e.message, true); }
      S.busy.ranks = false;
      S.modal = publishModalView(S.kdpMeta, S.tokens);
      render();
    },

    async translateProject(projectId) {
      const lang = prompt('Langue de traduction ? (en = anglais, de = allemand, es = espagnol, it = italien, pt = portugais, nl = néerlandais)', 'en');
      if (!lang) return;
      try {
        const data = await Api.post('projects/translate', { id: projectId, lang: lang.trim().toLowerCase() });
        S.projects = data.projects;
        render();
        toast('Projet de traduction créé — ouvrez-le : l\'étape 05 traduit section par section (cron compatible).');
      } catch (e) { toast(e.message, true); }
    },

    async proofreadBook() {
      if (S.busy.proofread) return;
      const chapters = (S.bundle.chapters || []).map(c => c.num);
      if (!chapters.length) { toast('Aucun chapitre à relire.', true); return; }
      if (!confirm('Relire tout le livre (orthographe, répétitions, transitions) ?\nUn appel IA par chapitre — le fond et la longueur ne changent pas.')) return;
      S.busy.proofread = true;
      let total = 0;
      try {
        for (let i = 0; i < chapters.length; i++) {
          toast('Relecture ' + (i + 1) + '/' + chapters.length + '…');
          const data = await Api.post('write/proofread', { id: S.project.id, chapter_num: chapters[i] });
          total += (data.result && data.result.corrected) || 0;
        }
        toast('Relecture terminée : ' + total + ' section(s) corrigée(s).');
        if (S.reader && S.reader.num) await loadChapter(S.reader.num);
      } catch (e) { toast(e.message + ' — relecture interrompue, relancez pour continuer.', true); }
      S.busy.proofread = false;
      render();
    },

    async showCronInfo() {
      try {
        const info = await Api.get('cron/info');
        S.modal = notesModalView('Écriture autonome (cron)',
          'Votre livre peut s\'écrire TOUT SEUL, ordinateur éteint — et vous recevez un e-mail à la fin.\n\n'
          + '1. Lancez (ou laissez) la rédaction en cours à l\'étape 05.\n'
          + '2. Dans le panneau de votre hébergeur (cPanel / o2switch / OVH…), créez une tâche cron toutes les 5 à 10 minutes vers cette URL :\n\n'
          + info.url + '\n\n'
          + 'Variante ligne de commande :\nphp /chemin/vers/public/cron.php ' + info.secret + '\n\n'
          + 'À chaque passage, le cron rédige jusqu\'à 5 sections des projets « en cours d\'écriture », avec les mêmes points de contrôle que le navigateur. Gardez cette URL secrète.');
        render();
      } catch (e) { toast(e.message, true); }
    },

    async createToken() {
      try {
        await Api.post('tokens/create', { label: 'Userscript KDP' });
        const tokenData = await Api.get('tokens/list');
        S.tokens = tokenData.tokens;
        S.modal = publishModalView(S.kdpMeta, S.tokens);
        render();
      } catch (e) { toast(e.message, true); }
    },

    copyToken(token) {
      navigator.clipboard.writeText(token).then(() => toast('Jeton copié dans le presse-papiers.'));
    },

    // Veille marché (relevés Canopy au clic)
    async openWatch() {
      S.writer.looping = false;
      S.view = 'watch';
      render();
      try {
        const data = await Api.get('watch/list');
        S.watches = data.watches;
        S.watchCanopy = data.canopy;
        render();
      } catch (e) { toast(e.message, true); }
    },

    async addWatch() {
      const input = document.getElementById('watch-term');
      const term = (input ? input.value : '').trim();
      if (!term) return;
      setBusy('watchAdd', true);
      try {
        const data = await Api.post('watch/add', { term });
        S.watches = data.watches;
        toast('« ' + term + ' » est suivie — cliquez « Relever » pour charger ses données (1 crédit).');
      } catch (e) { toast(e.message, true); }
      setBusy('watchAdd', false);
    },

    async removeWatch(watchId) {
      const w = (S.watches || []).find(x => x.id === watchId);
      if (!confirm('Ne plus suivre « ' + (w ? w.term : '') + ' » ? Ses relevés seront supprimés.')) return;
      try {
        const data = await Api.post('watch/remove', { watch_id: watchId });
        S.watches = data.watches;
        render();
      } catch (e) { toast(e.message, true); }
    },

    async refreshWatch(watchId) {
      const canopy = S.watchCanopy || S.app.canopy || {};
      const remaining = Math.max(0, (canopy.budget || 0) - (canopy.used || 0));
      const w = (S.watches || []).find(x => x.id === watchId);
      if (!confirm('Relever « ' + (w ? w.term : '') + ' » maintenant ?\nCela consommera 1 crédit Canopy (' + remaining + ' restant' + (remaining > 1 ? 's' : '') + ' ce mois-ci).')) return;
      S.busy['watch' + watchId] = true;
      render();
      try {
        const data = await Api.post('watch/refresh', { watch_id: watchId });
        S.watches = data.watches;
        S.watchCanopy = data.canopy;
        if (S.app.canopy) S.app.canopy = data.canopy;
        toast('Relevé enregistré.');
      } catch (e) {
        // Erreur passerelle (524/502…) : le serveur a pu terminer quand même —
        // on recharge la liste pour récupérer un relevé enregistré tardivement.
        toast(e.message + ' — vérification du relevé…', true);
        try {
          const data = await Api.get('watch/list');
          S.watches = data.watches;
          S.watchCanopy = data.canopy;
          const fresh = (data.watches || []).find(x => x.id === watchId);
          if (fresh && fresh.snapshot) toast('Bonne nouvelle : le relevé a bien été enregistré malgré l\'erreur.');
        } catch (_) {}
      }
      S.busy['watch' + watchId] = false;
      render();
    },

    async calibrateCanopy() {
      const canopy = S.watchCanopy || S.app.canopy || {};
      const value = prompt(
        'Calage du compteur : combien de requêtes votre tableau de bord canopyapi.co affiche-t-il comme UTILISÉES ce mois-ci ?',
        String(canopy.used || 0)
      );
      if (value === null) return;
      const used = parseInt(value, 10);
      if (isNaN(used) || used < 0) { toast('Saisissez un nombre entier (ex. : 4).', true); return; }
      try {
        const data = await Api.post('canopy/calibrate', { used });
        S.watchCanopy = data.canopy;
        if (S.app.canopy) S.app.canopy = data.canopy;
        render();
        toast('Compteur calé sur ' + used + ' — il suivra désormais depuis ce point.');
      } catch (e) { toast(e.message, true); }
    },

    async watchToBook(watchId) {
      try {
        const data = await Api.post('watch/book', { watch_id: watchId });
        toast('Projet créé depuis la niche suivie.');
        await openProject(data.project.id, 1);
      } catch (e) { toast(e.message, true); }
    },

    // Connecteurs (clés API)
    async openConnectors() {
      try {
        S.testResults = {};
        const data = await Api.get('connectors/get');
        S.connectors = data.connectors;
        S.modal = connectorsModalView(S.connectors);
        render();
        // Liste réelle des modèles disponibles pour la clé (asynchrone)
        Api.get('gemini/models').then(m => {
          S.geminiModels = m.models || [];
          if (S.modal && S.connectors) { S.modal = connectorsModalView(S.connectors); render(); }
        }).catch(() => {});
      } catch (e) { toast(e.message, true); }
    },

    async saveConnectors() {
      // Lire les champs AVANT tout re-rendu
      const payload = {
        gemini_api_key: document.getElementById('cn-gemini-key').value,
        model_fast: document.getElementById('cn-model-fast').value,
        model_pro: document.getElementById('cn-model-pro').value,
        canopy_api_key: document.getElementById('cn-canopy-key').value,
        canopy_domain: document.getElementById('cn-canopy-domain').value
      };
      setBusy('connectors', true);
      try {
        await Api.post('connectors/save', payload);
        const me = await Api.get('auth/me');
        S.app = me.app || S.app;
        const data = await Api.get('connectors/get');
        S.connectors = data.connectors;
        S.modal = connectorsModalView(S.connectors);
        toast('Connecteurs enregistrés.');
        // La clé a pu changer : on réactualise la liste des modèles disponibles
        Api.get('gemini/models').then(m => {
          S.geminiModels = m.models || [];
          if (S.modal && S.connectors) { S.modal = connectorsModalView(S.connectors); render(); }
        }).catch(() => {});
      } catch (e) { toast(e.message, true); }
      S.busy.connectors = false;
      render();
    },

    async testGemini() {
      S.testResults = S.testResults || {};
      delete S.testResults.gemini;
      setBusy('testGemini', true);
      S.modal = connectorsModalView(S.connectors); render();
      try {
        const data = await Api.post('gemini/test', {});
        S.testResults.gemini = { ok: true, message: 'Connexion opérationnelle — le modèle répond : « ' + data.reply + ' ».' };
      } catch (e) {
        S.testResults.gemini = { ok: false, message: e.message };
      }
      S.busy.testGemini = false;
      S.modal = connectorsModalView(S.connectors);
      render();
    },

    async resetCanopyUsage() {
      try {
        const data = await Api.post('canopy/reset-usage', {});
        if (S.app.canopy) S.app.canopy = data.canopy;
        if (S.watchCanopy) S.watchCanopy = data.canopy;
        if (S.connectors && S.connectors.canopy) S.connectors.canopy.status = data.canopy;
        toast('Compteur local remis à zéro.');
        if (S.view === 'watch') { App.openWatch(); }
        else if (S.modal) { S.modal = connectorsModalView(S.connectors); render(); }
      } catch (e) { toast(e.message, true); }
    },

    async testCanopy() {
      S.testResults = S.testResults || {};
      delete S.testResults.canopy;
      setBusy('testCanopy', true);
      S.modal = connectorsModalView(S.connectors); render();
      try {
        const data = await Api.post('canopy/test', {});
        const t = data.test || {};
        let message = t.message || 'Réponse reçue.';
        if (t.ok && t.sample && t.sample.length) {
          message += ' Ex. : ' + t.sample.map(p => p.title).join(' · ').slice(0, 120) + '…';
        }
        // En cas d'échec, on montre le détail brut pour diagnostic précis
        if (!t.ok) {
          if (t.http_code) message += ' [HTTP ' + t.http_code + ']';
          if (t.excerpt) message += '\nRéponse Canopy : ' + t.excerpt;
        }
        S.testResults.canopy = { ok: !!t.ok, message };
        if (t.usage && S.app.canopy) S.app.canopy = t.usage;
      } catch (e) {
        S.testResults.canopy = { ok: false, message: e.message };
      }
      S.busy.testCanopy = false;
      S.modal = connectorsModalView(S.connectors);
      render();
    },

    async revokeToken(tokenId) {
      if (!confirm('Révoquer ce jeton ? Le userscript ne fonctionnera plus avec.')) return;
      try {
        await Api.post('tokens/revoke', { token_id: tokenId });
        const tokenData = await Api.get('tokens/list');
        S.tokens = tokenData.tokens;
        S.modal = publishModalView(S.kdpMeta, S.tokens);
        render();
      } catch (e) { toast(e.message, true); }
    }
  };

  function pushParams() {
    debounce('params', () => Api.post('projects/update', {
      id: S.project.id, pages: S.project.pages, photos: S.project.photos,
      photos_per: S.project.photos_per, photo_style: S.project.photo_style,
      tone: S.project.tone, trim_format: S.project.trim_format
    }).catch(() => {}));
  }

  async function runChapterAction(action, param) {
    S.busy.chapAction = action;
    render();
    try {
      const data = await Api.post('chapters/action', { id: S.project.id, num: S.reader.num, action, param });
      if (action === 'factcheck') {
        S.modal = notesModalView('Vérification factuelle — chapitre ' + S.reader.num, data.notes);
      } else {
        S.reader.data = data;
        toast('Chapitre retravaillé.');
      }
    } catch (e) { toast(e.message, true); }
    S.busy.chapAction = null;
    render();
  }

  boot();
})();
