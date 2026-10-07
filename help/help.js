/*
 * SolutionsHub Help Center -- page logic. No dependencies.
 * Content: help-content*.js (articles), help-changelog.js (What's New). The Services section is built here from the
 * Hub's own catalog (PILLARS in ../app.js), so it never goes stale.
 */
(function () {
  'use strict';
  var HUB = '../index.html';
  var sections = (window.HELP_SECTIONS || []).slice();
  var changelog = window.HELP_CHANGELOG || [];

  // ---------- Services section generated from the catalog ----------
  function buildServices() {
    var P = window.PILLARS;
    if (!P || !P.length) return null;
    var groups = [];
    P.forEach(function (p) {
      var arts = [];
      arts.push({
        id: 'pillar-' + p.id, title: p.name + ' (service pillar)', def: p.tagline || '',
        where: 'Solutions Creator > ' + p.name, link: { href: HUB + '?pillar=' + encodeURIComponent(p.id), label: 'Open ' + p.name },
        why: 'A service pillar groups related services. Pick it in Solutions Creator to see every service under it.',
        steps: [], notes: [], children: (p.services || []).map(function (s) {
          return { title: s.name, blurb: s.blurb || '', href: HUB + '?pillar=' + encodeURIComponent(p.id) + '&service=' + encodeURIComponent(s.id), articleId: 'svc-' + p.id + '-' + s.id };
        }), childrenTitle: 'Services in this pillar', generated: true, kw: [p.id]
      });
      (p.services || []).forEach(function (s) {
        var base = HUB + '?pillar=' + encodeURIComponent(p.id) + '&service=' + encodeURIComponent(s.id);
        arts.push({
          id: 'svc-' + p.id + '-' + s.id, title: s.name, def: s.blurb || '',
          where: 'Solutions Creator > ' + p.name + ' > ' + s.name, link: { href: base, label: 'Open ' + s.name },
          why: s.methodology || '', whyTitle: 'How it works',
          steps: [], notes: [],
          children: (s.categories || []).map(function (c) {
            return { title: c.name, blurb: c.blurb || '', href: base + '&category=' + encodeURIComponent(c.id) };
          }), childrenTitle: 'Categories', generated: true, kw: [p.name], parentPillar: p.id
        });
      });
      groups.push({ title: p.name, articles: arts });
    });
    return {
      id: 'services', title: 'Services (Catalog)', generated: true,
      intro: 'Every service CodeBlue sells in the Hub, grouped by pillar. This section is built automatically from the Hub\'s catalog, so new services and categories appear here on their own.',
      groups: groups, articles: []
    };
  }

  // ---------- helpers ----------
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function el(id) { return document.getElementById(id); }
  function fmtDate(d) {
    if (!d) return '';
    var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(d); if (!m) return d;
    return ['Jan.', 'Feb.', 'Mar.', 'Apr.', 'May', 'Jun.', 'Jul.', 'Aug.', 'Sep.', 'Oct.', 'Nov.', 'Dec.'][+m[2] - 1] + ' ' + (+m[3]) + ', ' + m[1];
  }
  function hl(text, tokens) {
    var out = esc(text);
    tokens.forEach(function (t) {
      if (!t) return;
      var re = new RegExp('(' + t.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'ig');
      out = out.replace(/(<[^>]+>)|([^<]+)/g, function (m, tag, txt) { return tag ? tag : txt.replace(re, '<mark>$1</mark>'); });
    });
    return out;
  }

  // ---------- model ----------
  var all = [];          // flat list of every article with its section
  var byId = {};
  var sectionById = {};

  function register() {
    var svc = buildServices();
    var secs = sections.slice();
    if (svc) secs.push(svc);
    secs.forEach(function (sec) {
      sectionById[sec.id] = sec;
      var list = (sec.groups ? sec.groups.reduce(function (a, g) { return a.concat(g.articles); }, []) : sec.articles);
      list.forEach(function (a) { a.section = sec; byId[a.id] = a; all.push(a); });
    });
    sections = secs;
  }

  var newest = '';
  function computeNewest() {
    all.forEach(function (a) { if (a.updated && a.updated > newest) newest = a.updated; });
    changelog.forEach(function (c) { if (c.date > newest) newest = c.date; });
  }

  // ---------- search ----------
  function haystack(a) {
    return {
      title: (a.title || '').toLowerCase(), kw: ((a.kw || []).join(' ') + ' ' + (a.section ? a.section.title : '')).toLowerCase(),
      def: (a.def || '').toLowerCase(), where: (a.where || '').toLowerCase(),
      body: [a.why || ''].concat(a.steps || [], a.notes || [], (a.children || []).map(function (c) { return c.title + ' ' + c.blurb; })).join(' ').toLowerCase()
    };
  }
  function search(q) {
    var tokens = q.toLowerCase().split(/\s+/).filter(Boolean);
    if (!tokens.length) return [];
    var res = [];
    all.forEach(function (a) {
      var h = a._h || (a._h = haystack(a)); var score = 0, ok = true;
      tokens.forEach(function (t) {
        var s = 0;
        if (h.title.indexOf(t) >= 0) s += 10;
        if (h.kw.indexOf(t) >= 0) s += 6;
        if (h.def.indexOf(t) >= 0) s += 4;
        if (h.where.indexOf(t) >= 0) s += 3;
        if (h.body.indexOf(t) >= 0) s += 1;
        if (!s) ok = false; score += s;
      });
      if (ok) res.push({ a: a, score: score });
    });
    // category matches inside service articles: surface the category name
    res.sort(function (x, y) { return y.score - x.score || x.a.title.localeCompare(y.a.title); });
    return res;
  }

  // ---------- views ----------
  var main, side;

  function link(href, label, cls) { return '<a class="' + (cls || '') + '" href="' + esc(href) + '">' + esc(label) + '</a>'; }
  function articleHref(id) { return '#/a/' + encodeURIComponent(id); }

  function crumb(a) {
    var parts = ['<a href="#/">Help Center</a>', '<a href="#/s/' + esc(a.section.id) + '">' + esc(a.section.title) + '</a>'];
    if (a.parentPillar && byId['pillar-' + a.parentPillar]) parts.push('<a href="' + articleHref('pillar-' + a.parentPillar) + '">' + esc(byId['pillar-' + a.parentPillar].title.replace(' (service pillar)', '')) + '</a>');
    return '<nav class="crumb">' + parts.join('<span>/</span>') + '</nav>';
  }

  function renderArticle(a) {
    var h = crumb(a) + '<article class="article"><h1>' + esc(a.title) + '</h1>';
    h += '<div class="meta">' + esc(a.section.title) + (a.updated ? ' &middot; Updated ' + esc(fmtDate(a.updated)) : '') + (a.added && a.added !== a.updated ? ' &middot; Added ' + esc(fmtDate(a.added)) : '') + (a.generated ? ' &middot; Generated from the Hub catalog' : '') + '</div>';
    if (a.def) h += '<p class="def">' + esc(a.def) + '</p>';
    if (a.link) h += '<p><a class="btn" href="' + esc(a.link.href) + '">' + esc(a.link.label) + ' <span aria-hidden="true">&rarr;</span></a></p>';
    if (a.where) h += '<h2>Where to find it</h2><p>' + esc(a.where) + '</p>';
    if (a.why) h += '<h2>' + esc(a.whyTitle || 'What it is used for') + '</h2><p>' + esc(a.why) + '</p>';
    if (a.steps && a.steps.length) h += '<h2>How to use it</h2><ol>' + a.steps.map(function (s) { return '<li>' + esc(s) + '</li>'; }).join('') + '</ol>';
    if (a.notes && a.notes.length) h += '<h2>Good to know</h2><ul>' + a.notes.map(function (s) { return '<li>' + esc(s) + '</li>'; }).join('') + '</ul>';
    if (a.children && a.children.length) {
      h += '<h2>' + esc(a.childrenTitle || 'Related') + '</h2><dl class="defs">' + a.children.map(function (c) {
        var t = c.articleId && byId[c.articleId] ? '<a href="' + articleHref(c.articleId) + '">' + esc(c.title) + '</a>' : esc(c.title);
        return '<dt>' + t + (c.href ? ' <a class="open" href="' + esc(c.href) + '" title="Open in the Hub">Open &rarr;</a>' : '') + '</dt><dd>' + esc(c.blurb) + '</dd>';
      }).join('') + '</dl>';
    }
    var rel = changelog.filter(function (c) { return (c.articles || []).indexOf(a.id) >= 0; });
    if (rel.length) h += '<h2>Recent changes</h2><ul class="log-mini">' + rel.slice(0, 5).map(function (c) { return '<li><span>' + esc(fmtDate(c.date)) + '</span> ' + esc(c.title) + '</li>'; }).join('') + '</ul>';
    h += '<p class="foot">Something missing or out of date? Use <strong>Clyde Support</strong> in the Hub header.</p></article>';
    return h;
  }

  function cardList(arts) {
    return '<ul class="cards">' + arts.map(function (a) {
      return '<li><a href="' + articleHref(a.id) + '"><strong>' + esc(a.title) + '</strong><span>' + esc(a.def || '') + '</span></a></li>';
    }).join('') + '</ul>';
  }

  function renderSection(sec) {
    var h = '<nav class="crumb"><a href="#/">Help Center</a><span>/</span></nav><h1>' + esc(sec.title) + '</h1><p class="def">' + esc(sec.intro || '') + '</p>';
    if (sec.groups) sec.groups.forEach(function (g) { h += '<h2>' + esc(g.title) + '</h2>' + cardList(g.articles); });
    else h += cardList(sec.articles);
    return h;
  }

  function renderHome() {
    var h = '<h1>Help Center</h1><p class="def">What every CodeBlue tool can do, where to find each feature, and what it is for. Search above, browse the contents on the left, or pick a tool below.</p>';
    h += '<ul class="tiles">' + sections.map(function (s) {
      var n = s.groups ? s.groups.reduce(function (t, g) { return t + g.articles.length; }, 0) : s.articles.length;
      return '<li><a href="#/s/' + esc(s.id) + '"><strong>' + esc(s.title) + '</strong><span>' + esc(s.intro || '') + '</span><em>' + n + ' topics</em></a></li>';
    }).join('') + '</ul>';
    h += '<h2>Recently updated</h2><ul class="log-mini">' + changelog.slice(0, 6).map(function (c) {
      var first = (c.articles || [])[0];
      return '<li><span>' + esc(fmtDate(c.date)) + '</span> ' + (first && byId[first] ? '<a href="' + articleHref(first) + '">' + esc(c.title) + '</a>' : esc(c.title)) + '</li>';
    }).join('') + '</ul><p><a href="#/new">See everything that is new &rarr;</a> &middot; <a href="#/index">A&ndash;Z index</a></p>';
    return h;
  }

  function renderNew() {
    var h = '<nav class="crumb"><a href="#/">Help Center</a><span>/</span></nav><h1>What\'s New</h1><p class="def">A dated list of features added or changed, newest first.</p>';
    var lastMonth = '';
    changelog.forEach(function (c) {
      var m = c.date.slice(0, 7);
      if (m !== lastMonth) { lastMonth = m; h += '<h2>' + esc(fmtDate(c.date).replace(/ \d+,/, '')) + '</h2>'; }
      h += '<div class="entry"><div class="d">' + esc(fmtDate(c.date)) + '</div><div><strong>' + esc(c.title) + '</strong><p>' + esc(c.text) + '</p>' +
        ((c.articles || []).length ? '<p class="rel">' + c.articles.filter(function (id) { return byId[id]; }).map(function (id) { return '<a href="' + articleHref(id) + '">' + esc(byId[id].title) + '</a>'; }).join(' &middot; ') + '</p>' : '') + '</div></div>';
    });
    return h;
  }

  function renderIndex() {
    var sorted = all.slice().sort(function (a, b) { return a.title.toLowerCase().localeCompare(b.title.toLowerCase()); });
    var h = '<nav class="crumb"><a href="#/">Help Center</a><span>/</span></nav><h1>A&ndash;Z Index</h1><p class="def">Every topic, alphabetically.</p>';
    var letter = '';
    sorted.forEach(function (a) {
      var l = a.title.charAt(0).toUpperCase();
      if (l !== letter) { letter = l; h += '<h2>' + esc(l) + '</h2>'; }
      h += '<p class="idx"><a href="' + articleHref(a.id) + '">' + esc(a.title) + '</a> <span>' + esc(a.section.title) + '</span></p>';
    });
    return h;
  }

  function renderSearch(q) {
    var tokens = q.toLowerCase().split(/\s+/).filter(Boolean);
    var res = search(q);
    var h = '<h1>Search results</h1><p class="def">' + res.length + (res.length === 1 ? ' topic' : ' topics') + ' for <strong>' + esc(q) + '</strong></p>';
    if (!res.length) return h + '<p>Nothing found. Try a shorter or different word, such as a feature or app name.</p>';
    h += '<ul class="results">' + res.slice(0, 60).map(function (r) {
      var a = r.a, snip = a.def || a.why || '';
      return '<li><a href="' + articleHref(a.id) + '"><strong>' + hl(a.title, tokens) + '</strong></a><div class="path">' + esc(a.section.title) + '</div><p>' + hl(snip.length > 220 ? snip.slice(0, 217) + '...' : snip, tokens) + '</p></li>';
    }).join('') + '</ul>';
    return h;
  }

  // ---------- sidebar ----------
  var openSecs = {};
  function renderSide(currentSecId, currentArtId) {
    var h = '<a class="side-top' + (!currentSecId && !currentArtId ? ' on' : '') + '" href="#/">Home</a><a class="side-top" href="#/new">What\'s New</a><a class="side-top" href="#/index">A&ndash;Z Index</a><div class="rule"></div>';
    sections.forEach(function (s) {
      var open = openSecs[s.id] || s.id === currentSecId;
      h += '<div class="sec' + (open ? ' open' : '') + '"><button type="button" data-sec="' + esc(s.id) + '"><span class="chev">&#9656;</span>' + esc(s.title) + '</button><div class="items">';
      if (s.groups) {
        s.groups.forEach(function (g) {
          var gOpen = (currentArtId && g.articles.some(function (a) { return a.id === currentArtId; }));
          h += '<div class="grp' + (gOpen ? ' open' : '') + '"><button type="button" class="gbtn" data-grp="1"><span class="chev">&#9656;</span>' + esc(g.title) + '</button><div class="gitems">' + g.articles.map(function (a) { return '<a class="' + (a.id === currentArtId ? 'on' : '') + '" href="' + articleHref(a.id) + '">' + esc(a.title.replace(' (service pillar)', ' (overview)')) + '</a>'; }).join('') + '</div></div>';
        });
      } else {
        h += s.articles.map(function (a) { return '<a class="' + (a.id === currentArtId ? 'on' : '') + '" href="' + articleHref(a.id) + '">' + esc(a.title) + '</a>'; }).join('');
      }
      h += '</div></div>';
    });
    side.innerHTML = h;
  }

  // ---------- routing ----------
  function route() {
    var hash = decodeURIComponent((location.hash || '#/').slice(1));
    var parts = hash.split('/').filter(function (x, i) { return i > 0 || x; });
    var html = '', secId = null, artId = null;
    if (parts[0] === 'a' && byId[parts.slice(1).join('/')]) { var a = byId[parts.slice(1).join('/')]; artId = a.id; secId = a.section.id; html = renderArticle(a); document.title = a.title + ' - SolutionsHub Help'; }
    else if (parts[0] === 's' && sectionById[parts[1]]) { secId = parts[1]; html = renderSection(sectionById[secId]); document.title = sectionById[secId].title + ' - SolutionsHub Help'; }
    else if (parts[0] === 'new') { html = renderNew(); document.title = 'What\'s New - SolutionsHub Help'; }
    else if (parts[0] === 'index') { html = renderIndex(); document.title = 'A-Z Index - SolutionsHub Help'; }
    else if (parts[0] === 'q') { var q = parts.slice(1).join('/'); html = renderSearch(q); el('q').value = q; document.title = 'Search - SolutionsHub Help'; }
    else { html = renderHome(); document.title = 'SolutionsHub Help Center'; }
    if (parts[0] !== 'q' && document.activeElement !== el('q')) el('q').value = '';
    main.innerHTML = html;
    renderSide(secId, artId);
    window.scrollTo(0, 0);
    document.body.classList.remove('side-open');
  }

  function init() {
    main = el('main'); side = el('side');
    register(); computeNewest();
    el('updated').textContent = newest ? 'Updated ' + fmtDate(newest) : '';
    var t;
    el('q').addEventListener('input', function (e) {
      clearTimeout(t); var v = e.target.value.trim();
      t = setTimeout(function () { if (v) location.hash = '#/q/' + encodeURIComponent(v); else if (/^#\/q\//.test(location.hash)) location.hash = '#/'; }, 180);
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === '/' && !/INPUT|TEXTAREA/.test((document.activeElement || {}).tagName || '')) { e.preventDefault(); el('q').focus(); }
      if (e.key === 'Escape' && document.activeElement === el('q')) { el('q').blur(); }
    });
    side.addEventListener('click', function (e) {
      var b = e.target.closest('button'); if (!b) return;
      var box = b.parentNode;
      if (b.getAttribute('data-sec')) { openSecs[b.getAttribute('data-sec')] = !box.classList.contains('open'); }
      box.classList.toggle('open');
    });
    el('toc').addEventListener('click', function () { document.body.classList.toggle('side-open'); });
    window.addEventListener('hashchange', route);
    route();
  }

  // sign-in gate, same as the other CodeBlue tools
  fetch('/auth/me.php', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (r) {
    if (!r.ok || !r.user) { location.replace('/login.php?return_to=' + encodeURIComponent(location.pathname + location.search + location.hash)); return; }
    init();
  }).catch(function () { location.replace('/login.php?return_to=' + encodeURIComponent(location.pathname + location.search + location.hash)); });
})();
