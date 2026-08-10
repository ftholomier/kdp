/* ============================================================================
   Tirage — KDP Studio · application (vanilla JS)
   Parcours en 7 étapes : Niche → Concept → Sommaire → Couverture →
   Rédaction → Chapitres → Mise en page
   ========================================================================== */
(function () {
  'use strict';

  // Numéro de build — affiché dans ⚡ Connecteurs pour vérifier que la bonne
  // version est bien chargée (utile en cas de cache navigateur récalcitrant).
  const BUILD = '2026-08-10 · c8';

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
      S.cover = null;
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
    if (S.step === 4) injectCoverPreviews();
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
          <div style="font-size:11.5px; color:var(--faint); margin-top:6px;">${canopy.used} utilisée${canopy.used > 1 ? 's' : ''}${canopy.exhausted ? ' · quota atteint' : ''} · ${canopy.real ? '<span style="color:var(--green);">en direct depuis Canopy</span>' : '<span>estimation locale · <span style="color:var(--accent); cursor:pointer;" onclick="App.resetCanopyUsage()">réinitialiser</span></span>'}</div>
          <div style="font-size:10.5px; color:var(--fainter); margin-top:4px;">${canopy.real ? 'Synchronisé avec votre compte canopyapi.co' : 'Réf. exacte : votre tableau de bord canopyapi.co'}</div>`
          : `<div style="font-size:13px; color:var(--muted); margin-top:8px; line-height:1.5;">Connecteur non configuré.<br><span style="color:var(--accent); cursor:pointer;" onclick="App.openConnectors()">Coller ma clé Canopy ›</span></div>`}
        </div>
      </div>

      <div style="display:flex; gap:10px; margin:30px 0 22px; max-width:560px;">
        <input type="text" id="watch-term" placeholder="Niche à suivre — ex. : carnet de gratitude, batch cooking…"
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

  function watchCardView(w, canopy) {
    const s = w.snapshot;
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
          <div class="name">${esc(w.term)}</div>
          <div class="cat">${s ? 'Relevé du ' + esc((w.updated_at || '').slice(0, 16).replace('T', ' ')) : 'Jamais relevé'}</div>
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
        <div class="metric-label" style="margin-bottom:7px;">Top réel (page 1)</div>
        ${(s.top || []).slice(0, 5).map((p, i) => `
        <div style="display:flex; gap:8px; padding:4px 0; font-size:12.5px; line-height:1.35;">
          <span class="mono" style="color:var(--fainter); flex:none; font-size:11px; padding-top:1px;">${i + 1}</span>
          <span style="flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="${esc(p.title)}">${esc(p.title)}</span>
          <span class="mono" style="flex:none; color:var(--muted); font-size:11.5px;">${p.price !== null ? String(p.price.toFixed(2)).replace('.', ',') + ' €' : '—'}</span>
        </div>`).join('')}
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
    const isDescribe = p.mode !== 'trends';
    const themes = currentThemes();
    const showThemes = themes.length > 0;

    return `
    <div class="page">
      <div class="page-head" style="max-width:640px;">
        <div class="kicker">Étape 01 — Niche</div>
        <h1>Trouvez une thématique<br>qui se vend déjà.</h1>
        <p class="lead">Décrivez votre idée pour la confronter au marché, ou partez des catégories les plus consultées sur Amazon ces 30 derniers jours.</p>
      </div>

      <div class="mode-toggle">
        <div class="${isDescribe ? 'on' : ''}" onclick="App.setMode('describe')">J'ai une idée</div>
        <div class="${!isDescribe ? 'on' : ''}" onclick="App.setMode('trends')">Explorer les tendances</div>
      </div>

      ${isDescribe ? `
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
      </div>` : `
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

          <div style="font-size:14px; font-weight:600; margin:20px 0 10px;">Palette</div>
          <div class="palette-row">
            ${['c1', 'c2', 'c3', 'c4'].map(k => `<input type="color" value="${esc(cover.palette[k] || '#1B2A4A')}" onchange="App.setCoverColor('${k}', this.value)" title="${k.toUpperCase()}">`).join('')}
            <span class="faint" style="font-size:11.5px;">C1 fond · C2 accent · C3 clair · C4 encre</span>
          </div>

          <div style="display:grid; gap:12px; margin-top:20px;">
            <label>Titre<input type="text" value="${esc(cover.texts.title)}" oninput="App.setCoverText('title', this.value)"></label>
            <label>Sous-titre<input type="text" value="${esc(cover.texts.subtitle)}" oninput="App.setCoverText('subtitle', this.value)"></label>
            <label>Accroche (1ère de couv)<input type="text" value="${esc(cover.texts.tagline)}" oninput="App.setCoverText('tagline', this.value)"></label>
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
          <div class="cover-preview-zone">
            <div>
              <div class="cover-face" style="width:min(340px, 80vw);">
                <img id="cover-front-img" src="api.php?r=coverstudio/front&id=${S.project.id}&t=${S.coverStamp || 0}" alt="1ère de couverture" style="width:100%; display:block; border-radius:2px;">
              </div>
              <div class="cover-face-label">1ère de couverture · rendu haute résolution</div>
            </div>
            <div>
              <div class="cover-face" id="cover-back" style="width:min(340px, 80vw);"></div>
              <div class="cover-face-label">4ème de couverture · zone code-barres réservée</div>
            </div>
          </div>
          <div class="preview-caption">La couverture complète (4ème + dos + 1ère, fond perdu compris) s'exporte à l'étape 07.</div>
        </div>
      </div>`}
    </div>`;
  }

  async function loadCover() {
    try {
      const data = await Api.get('covers/get', { id: S.project.id });
      S.cover = data.cover;
      S.coverTemplates = data.templates;
      S.coverHasIllustration = data.has_illustration;
      S.coverHasRef = data.has_reference;
      S.coverDefaultPrompt = data.default_prompt;
      S.coverStamp = Date.now();
      render();
      if (!S.coverVariants) loadCoverVariants();
    } catch (e) { toast(e.message, true); }
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
    const img = document.getElementById('cover-front-img');
    if (img) img.src = 'api.php?r=coverstudio/front&id=' + S.project.id + '&t=' + S.coverStamp;
  }

  async function injectCoverPreviews() {
    if (S.step !== 4 || !S.cover) return;
    // 4ème de couverture : gabarit SVG rendu avec la palette courante
    const host = document.getElementById('cover-back');
    if (!host) return;
    try {
      const response = await fetch('api.php?r=covers/render&id=' + S.project.id + '&face=back&t=' + Date.now(), { credentials: 'same-origin' });
      const svg = await response.text();
      host.innerHTML = svg;
      const el = host.querySelector('svg');
      if (el) { el.removeAttribute('width'); el.removeAttribute('height'); el.style.width = '100%'; }
    } catch (_) { /* aperçu indisponible */ }
  }

  function saveCover() {
    debounce('cover', async () => {
      try {
        await Api.post('covers/save', {
          id: S.project.id, template: S.cover.template,
          palette: S.cover.palette, texts: S.cover.texts
        });
        refreshFrontPreview();
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
      : running ? `Rédaction en cours · chapitre ${st.chapter_current} sur ${st.chapter_total}`
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
              <div><div class="k">Mots écrits</div><div class="v">${nf(st.words_done)}</div></div>
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
                  <div class="head"><span class="ch">CH ${pad2(c.num)}</span>${badge}</div>
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
          <div class="meta">${chapters.length} chapitres · ${nf(totalWords)} mots · ${p.pages} p.</div>
        </div>
        ${chapters.map(c => `
        <div class="reader-nav-item ${S.reader.num === c.num ? 'on' : ''}" onclick="App.openChapter(${c.num})">
          <span class="n">${pad2(c.num)}</span><span class="t">${esc(c.title)}</span>
        </div>`).join('')}
      </div>

      <div class="reader-body">
        <div class="reader-inner">
          ${!data ? loadingCard('Chargement du chapitre…') : `
          <div class="chapter-kicker">Chapitre ${pad2(data.chapter.num)}</div>
          <h1>${esc(data.chapter.title)}</h1>
          ${data.sections.map((sec, i) => `
            ${i > 0 ? `<h3 class="sec serif">${esc(sec.title)}</h3>` : ''}
            <div class="reader-prose">
              ${(sec.content ? sec.content.split(/\n\s*\n/) : []).map(par => `<p>${esc(par)}</p>`).join('')
                || '<p class="muted" style="font-family:var(--sans); font-size:14px;">Section pas encore rédigée.</p>'}
            </div>
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
    ${S.modal || ''}`;
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
      </figcaption>
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
    return `
    <div class="layout-grid">
      <div class="layout-preview">
        <div class="head">
          <div>
            <div class="kicker">Étape 07 — Mise en page</div>
            <div class="t">Épreuve intérieure — ${L ? esc(String(L.geometry.w_mm).replace('.', ',') + ' × ' + String(L.geometry.h_mm).replace('.', ',') + ' mm') : ''}</div>
          </div>
          <button class="btn btn-ghost" onclick="window.open('print.php?id=${S.project.id}', '_blank')">Ouvrir l'épreuve complète ↗</button>
        </div>
        <div class="preview-frame-wrap">
          <iframe class="preview-frame" src="print.php?id=${S.project.id}&mode=preview"></iframe>
        </div>
        ${L ? `<div class="preview-caption">Marges ${String(L.geometry.margin_top_mm).replace('.', ',')} mm · gouttière ${String(L.geometry.margin_inner_mm).replace('.', ',')} mm · dos ${esc(L.spine_label)} · ${L.geometry.pages} pages estimées</div>` : ''}
      </div>

      <div class="layout-side">
        <div class="title">Réglages d'impression</div>
        <div class="sub">Conformes aux gabarits KDP broché.</div>
        ${!L ? loadingCard('Calculs en cours…') : `
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
            <button class="btn btn-light" onclick="window.open('print.php?id=${S.project.id}&auto=1', '_blank')">Imprimer l'intérieur (PDF qualité studio)</button>
            <button class="btn btn-outline-light" onclick="window.open('api.php?r=export/pdf&id=${S.project.id}', '_blank')">PDF automatique (serveur)</button>
            <button class="btn btn-outline-light" onclick="window.open('cover.php?id=${S.project.id}', '_blank')">Couverture complète · dos ${esc(L.spine_label)}</button>
            <button class="btn btn-outline-light" onclick="window.open('api.php?r=export/docx&id=${S.project.id}', '_blank')">Manuscrit .docx (Kindle eBook)</button>
            <button class="btn btn-light" style="background:var(--accent); color:#FFF6EA;" onclick="App.openPublishModal()">🚀 Publier sur Amazon KDP</button>
          </div>
        </div>`}
      </div>
    </div>
    ${S.modal || ''}`;
  }

  async function loadLayout() {
    try {
      const data = await Api.get('layout/summary', { id: S.project.id });
      S.layout = data;
      render();
    } catch (e) { toast(e.message, true); }
  }

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

  function publishModalView(meta, tokens) {
    const kw = Array.from({ length: 7 }, (_, i) => (meta.keywords || [])[i] || '');
    return `
    <div class="modal-backdrop" onclick="if(event.target===this)App.closeModal()">
      <div class="modal">
        <h2>Publier sur Amazon KDP</h2>
        <div class="sub">Métadonnées du formulaire KDP + remplissage automatique par userscript. Vous gardez le contrôle : le script remplit les champs dans votre navigateur connecté à KDP, le clic final « Publier » reste le vôtre.</div>

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
          <label>ISBN (vide = attribué par KDP)<input type="text" id="kdp-isbn" value="${esc(meta.isbn)}"></label>
        </div>
        <div style="display:flex; gap:10px; flex-wrap:wrap;">
          <button class="btn btn-soft" onclick="App.generateKdpMeta()" ${S.busy.kdpgen ? 'disabled' : ''}>${S.busy.kdpgen ? 'Génération…' : '✦ Générer avec l’IA'}</button>
          <button class="btn btn-primary" onclick="App.saveKdpMeta()" ${S.busy.kdpsave ? 'disabled' : ''}>${S.busy.kdpsave ? 'Enregistrement…' : 'Enregistrer les métadonnées'}</button>
        </div>

        <hr style="border:none; border-top:1px solid var(--line-soft); margin:22px 0;">
        <div class="title" style="font-weight:600; margin-bottom:6px;">Remplissage automatique du formulaire KDP</div>
        <div class="sub" style="margin-bottom:12px;">
          1. Installez l'extension Tampermonkey puis <a href="assets/kdp-autofill.user.js" target="_blank">ouvrez le userscript</a> pour l'installer.<br>
          2. Créez un jeton ci-dessous et collez-le (avec l'URL de ce site) dans le panneau « ${esc(S.app.name)} » qui apparaît sur kdp.amazon.com.<br>
          3. Sur chaque page du formulaire KDP, cliquez « Remplir cette page » : titre, sous-titre, auteur, description, mots-clés et prix sont saisis automatiquement.
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
        S.coverVariants.forEach((x, i) => { x.selected = i === index; });
        render();
        refreshFrontPreview();
        injectCoverPreviews();
      } catch (e) { toast(e.message, true); }
    },

    async generateIllustration() {
      const prompt = (document.getElementById('cover-illus-prompt') || {}).value || '';
      setBusy('illus', true);
      try {
        const data = await Api.post('coverstudio/illustration', { id: S.project.id, prompt });
        S.coverHasIllustration = true;
        if (data.cover) S.cover = data.cover; // bascule auto en mise en page « affiche »
        if (S.coverVariants) S.coverVariants.forEach(v => { v.selected = false; });
        toast('Illustration générée — affichée en pleine page sur la couverture.');
      } catch (e) { toast(e.message, true); }
      setBusy('illus', false);
      render();
      refreshFrontPreview();
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

    // Étape 7
    async openPublishModal() {
      try {
        const [metaData, tokenData] = await Promise.all([
          Api.get('kdpmeta/get', { id: S.project.id }),
          Api.get('tokens/list')
        ]);
        S.kdpMeta = metaData.meta;
        S.tokens = tokenData.tokens;
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
        isbn: document.getElementById('kdp-isbn').value
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
      } catch (e) { toast(e.message, true); }
      S.busy['watch' + watchId] = false;
      render();
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
