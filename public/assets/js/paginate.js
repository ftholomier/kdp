/* ============================================================================
   Tirage — pagination de l'épreuve intérieure (print.php)
   Découpe le manuscrit en pages aux dimensions exactes du format KDP :
   marges en miroir (gouttière), chapitres sur belle page avec lettrine,
   titres courants, folios, sommaire paginé. Fonctionne à l'écran, en aperçu
   double page (iframe) et à l'impression (PDF via le navigateur).
   ========================================================================== */
(function () {
  'use strict';

  const BOOK = window.BOOK;
  const MODE = window.MODE || 'full';
  const flow = document.getElementById('flow');

  const CHAPTER_WORDS = ['Un', 'Deux', 'Trois', 'Quatre', 'Cinq', 'Six', 'Sept', 'Huit', 'Neuf', 'Dix',
    'Onze', 'Douze', 'Treize', 'Quatorze', 'Quinze', 'Seize', 'Dix-sept', 'Dix-huit', 'Dix-neuf', 'Vingt'];

  const sheets = [];        // { el, content, num, kind, chapterTitle }
  const chapterStartPages = {};
  let pageNum = 0;
  let currentChapterTitle = '';

  function esc(s) {
    return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  function newSheet(kind) {
    pageNum++;
    const el = document.createElement('div');
    const isRecto = pageNum % 2 === 1;
    el.className = 'sheet ' + (isRecto ? 'recto' : 'verso');
    el.dataset.page = pageNum;
    const content = document.createElement('div');
    content.className = 'content';
    el.appendChild(content);
    flow.appendChild(el);
    sheets.push({ el, content, num: pageNum, kind: kind || 'normal', chapterTitle: currentChapterTitle });
    return content;
  }

  function fits(content) {
    return content.scrollHeight <= content.clientHeight + 1;
  }

  // ── Pages liminaires (1 à 8) ─────────────────────────────────────────────

  function buildFrontMatter() {
    // p.1 — faux-titre
    let c = newSheet('front');
    c.insertAdjacentHTML('beforeend', `<div class="front-center"><div class="half-title">${esc(BOOK.title)}</div></div>`);
    // p.2 — blanche
    newSheet('blank');
    // p.3 — page de titre
    c = newSheet('front');
    c.insertAdjacentHTML('beforeend', `<div class="front-center">
      <div class="book-title">${esc(BOOK.title)}</div>
      ${BOOK.subtitle ? `<div class="book-subtitle">${esc(BOOK.subtitle)}</div>` : ''}
      <div class="book-author">${esc(BOOK.author)}</div>
    </div>`);
    // p.4 — copyright
    c = newSheet('front');
    c.insertAdjacentHTML('beforeend', `<div class="copyright-block">
      © ${esc(BOOK.year)} ${esc(BOOK.author)}. Tous droits réservés.<br>
      Aucune partie de ce livre ne peut être reproduite sans autorisation écrite de l'auteur.<br>
      Publié en autoédition via Amazon Kindle Direct Publishing.
    </div>`);
    // p.5 — sommaire (rempli après pagination du corps)
    c = newSheet('front');
    c.id = 'toc-page';
    // p.6, 7, 8 — blanches (le corps démarre p.9, belle page)
    newSheet('blank');
    newSheet('blank');
    newSheet('blank');
  }

  // ── Corps ────────────────────────────────────────────────────────────────

  function buildBody() {
    BOOK.chapters.forEach(chapter => {
      currentChapterTitle = chapter.title;

      // Belle page : ouverture sur page impaire (recto)
      if (pageNum % 2 === 1) newSheet('blank');
      let content = newSheet('opener');
      chapterStartPages[chapter.num] = pageNum;

      const kicker = chapter.role === 'chapter'
        ? (CHAPTER_WORDS[(chapter.display_num || chapter.num) - 1] || chapter.label || 'Chapitre ' + chapter.num)
        : (chapter.label || chapter.title);
      content.insertAdjacentHTML('beforeend', `<div class="chapter-opening">
        <div class="chapter-word">${esc(kicker)}</div>
        <h1>${esc(chapter.title)}</h1>
      </div>`);

      let firstParagraphOfChapter = true;
      let figuresPlaced = false;

      chapter.sections.forEach((section, sectionIndex) => {
        if (sectionIndex > 0) {
          content = appendBlock(content, `<h2 class="section-title">${esc(section.title)}</h2>`, true);
        }
        const blocks = section.blocks && section.blocks.length
          ? section.blocks
          : (section.paragraphs || []).map(p => ({ t: 'p', text: p }));
        blocks.forEach((block, blockIndex) => {
          if (block.t === 'call') {
            content = appendBlock(content, calloutHtml(block), true);
            return;
          }
          if (block.t === 'list') {
            content = appendBlock(content,
              `<div class="prose"><ul class="book-list">${block.items.map(i => `<li>${esc(i)}</li>`).join('')}</ul></div>`, true);
            return;
          }
          const cls = firstParagraphOfChapter ? 'dropcap' : (blockIndex === 0 && sectionIndex > 0 ? '' : 'indent');
          content = appendParagraph(content, block.text, cls);
          firstParagraphOfChapter = false;
        });
        if (!figuresPlaced && chapter.images && chapter.images.length) {
          figuresPlaced = true;
          chapter.images.forEach(image => {
            content = appendBlock(content, figureHtml(chapter, image), true);
          });
        }
      });
    });
    // Pagination totale paire
    if (pageNum % 2 === 1) newSheet('blank');
  }

  function calloutHtml(block) {
    const labels = BOOK.callout_labels || {};
    const label = labels[block.kind] || block.kind;
    const paragraphs = block.text.split(/\n\s*\n/).map(p => `<p>${esc(p.trim())}</p>`).join('');
    return `<div class="callout k-${esc(block.kind)}">
      <div class="co-label">${esc(label)}</div>${paragraphs}
    </div>`;
  }

  function figureHtml(chapter, image) {
    const inner = image.url
      ? `<img src="${esc(image.url)}" alt="">`
      : `<div class="ph">Emplacement visuel ${chapter.num}.${image.slot}<br>${esc(image.spec || '300 dpi')}</div>`;
    return `<figure class="book-figure">
      <div class="frame">${inner}</div>
      <figcaption>Fig. ${chapter.num}.${image.slot} — ${esc(image.caption || 'Visuel')}</figcaption>
    </figure>`;
  }

  /** Bloc insécable : passe à la page suivante s'il ne tient pas. */
  function appendBlock(content, html, keepTogether) {
    content.insertAdjacentHTML('beforeend', html);
    if (!fits(content)) {
      content.lastElementChild.remove();
      content = newSheet('normal');
      content.insertAdjacentHTML('beforeend', html);
    }
    return content;
  }

  /** Paragraphe sécable : coupé au mot près si nécessaire (min. 2 lignes de part et d'autre). */
  function appendParagraph(content, text, cls) {
    const html = `<div class="prose"><p class="${cls}">${esc(text)}</p></div>`;
    content.insertAdjacentHTML('beforeend', html);
    if (fits(content)) return content;

    const block = content.lastElementChild;
    const words = text.split(/\s+/);

    // Trop peu de mots pour couper proprement : page suivante
    if (words.length < 18 || cls === 'dropcap') {
      block.remove();
      content = newSheet('normal');
      content.insertAdjacentHTML('beforeend', html);
      return content;
    }

    // Recherche dichotomique du plus grand préfixe qui tient
    const p = block.querySelector('p');
    let low = 8, high = words.length - 8, best = 0;
    while (low <= high) {
      const mid = (low + high) >> 1;
      p.textContent = words.slice(0, mid).join(' ');
      if (fits(content)) { best = mid; low = mid + 1; } else { high = mid - 1; }
    }

    if (best < 8) {
      block.remove();
      content = newSheet('normal');
      content.insertAdjacentHTML('beforeend', html);
      return content;
    }

    p.textContent = words.slice(0, best).join(' ');
    content = newSheet('normal');
    content.insertAdjacentHTML('beforeend',
      `<div class="prose"><p>${esc(words.slice(best).join(' '))}</p></div>`);
    return content;
  }

  // ── Habillage : sommaire, titres courants, folios ────────────────────────

  function fillToc() {
    const tocPage = document.getElementById('toc-page');
    if (!tocPage) return;
    tocPage.insertAdjacentHTML('beforeend', `<div class="toc-title">Sommaire</div>` +
      BOOK.chapters.map(chapter => `<div class="toc-line">
        <span>${chapter.role === 'chapter' ? (chapter.display_num || chapter.num) + '.&nbsp;&nbsp;' : ''}${esc(chapter.title)}</span>
        <span class="pg">${chapterStartPages[chapter.num] || ''}</span>
      </div>`).join(''));
  }

  function decorate() {
    sheets.forEach(sheet => {
      if (sheet.num < 9 || sheet.kind === 'blank') return;
      const isRecto = sheet.num % 2 === 1;
      if (sheet.kind !== 'opener') {
        const headText = isRecto ? sheet.chapterTitle : BOOK.title;
        sheet.el.insertAdjacentHTML('beforeend', `<div class="running-head">${esc(headText)}</div>`);
      }
      sheet.el.insertAdjacentHTML('beforeend', `<div class="folio">${sheet.num}</div>`);
    });
    const count = document.getElementById('page-count');
    if (count) count.textContent = String(pageNum);
  }

  // ── Mode aperçu : double page navigable ──────────────────────────────────

  function buildPreview() {
    const spreads = [];
    // Doubles pages : [verso pair, recto impair] — la p.1 s'affiche seule à droite
    for (let left = 0; left <= pageNum; left += 2) {
      const pair = [];
      if (left >= 2) pair.push(left);
      if (left + 1 <= pageNum) pair.push(left + 1);
      if (pair.length) spreads.push(pair);
    }

    const byNum = {};
    sheets.forEach(sheet => { byNum[sheet.num] = sheet.el; });

    const viewport = document.createElement('div');
    viewport.className = 'spread-viewport';
    const nav = document.createElement('div');
    nav.className = 'spread-nav';
    const holder = document.createElement('div');
    viewport.appendChild(holder);
    viewport.appendChild(nav);

    // Ouvre sur la première double page du chapitre 1
    const firstBody = chapterStartPages[1] || 9;
    let index = spreads.findIndex(pair => pair.includes(firstBody) || pair.includes(firstBody - 1));
    if (index < 0) index = 0;

    function show() {
      holder.innerHTML = '';
      const spread = document.createElement('div');
      spread.className = 'spread';
      spreads[index].forEach(n => spread.appendChild(byNum[n]));
      holder.appendChild(spread);

      requestAnimationFrame(() => {
        const w = spread.getBoundingClientRect().width;
        const available = window.innerWidth - 24;
        const scale = Math.min(1, available / w);
        spread.style.transform = `scale(${scale})`;
        spread.style.marginBottom = scale < 1 ? `-${(1 - scale) * spread.getBoundingClientRect().height / scale}px` : '0';
      });

      nav.innerHTML = '';
      const prev = document.createElement('button');
      prev.textContent = '‹ Précédente';
      prev.disabled = index === 0;
      prev.onclick = () => { index--; show(); };
      const label = document.createElement('span');
      label.textContent = 'Pages ' + spreads[index].join('–') + ' / ' + pageNum;
      const next = document.createElement('button');
      next.textContent = 'Suivante ›';
      next.disabled = index === spreads.length - 1;
      next.onclick = () => { index++; show(); };
      nav.append(prev, label, next);
    }

    flow.replaceWith(viewport);
    show();
    window.addEventListener('resize', show);
  }

  // ── Lancement (après chargement des polices pour des mesures exactes) ────

  function run() {
    buildFrontMatter();
    buildBody();
    fillToc();
    decorate();
    if (MODE === 'preview') {
      buildPreview();
    } else if (window.AUTO_PRINT) {
      setTimeout(() => window.print(), 400);
    }
  }

  if (document.fonts && document.fonts.ready) {
    document.fonts.ready.then(() => setTimeout(run, 50));
  } else {
    window.addEventListener('load', () => setTimeout(run, 200));
  }
})();
