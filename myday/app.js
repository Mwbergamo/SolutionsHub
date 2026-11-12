/* myday/app.js — "My Day" personal dashboard (added 2026-11-12).
   Vanilla JS, renders straight into #app-root -- same approach as
   relationships/app.js and client-finance/app.js. Read-only: every
   number here comes from myday/api/digest.php, which itself just calls
   existing Relationships/Client Finance functions (see that file's doc
   comment). There is nothing to edit here, so there's no save path --
   clicking a tile takes you to the real screen that owns that data. */
(function () {
  'use strict';

  var root = document.getElementById('app-root');
  var HUB_URL = '../index.html';
  var REP_TODOS_URL = '../relationships/index.html?view=rep-todos';
  var PROJECTS_URL = '../relationships/index.html?view=projects&section=projects';
  var RELATIONSHIPS_URL = '../relationships/index.html';
  var CLIENT_FINANCE_URL = '../client-finance/index.html';

  var state = {
    user: null,
    loading: true,
    error: null,
    digest: null
  };

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function fmtCurrency(n) {
    return '$' + Number(n || 0).toLocaleString(undefined, { maximumFractionDigits: 0 });
  }

  function fmtMonth(ym) {
    if (!ym) return '';
    var parts = String(ym).split('-');
    if (parts.length !== 2) return esc(ym);
    var d = new Date(Number(parts[0]), Number(parts[1]) - 1, 1);
    if (isNaN(d.getTime())) return esc(ym);
    return d.toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
  }

  function api(url) {
    return fetch(url, { credentials: 'same-origin' }).then(function (r) {
      return r.json().then(function (data) { return { status: r.status, data: data }; });
    });
  }

  function apiPost(url) {
    return fetch(url, { method: 'POST', credentials: 'same-origin' }).then(function (r) {
      return r.json().then(function (data) { return { status: r.status, data: data }; });
    });
  }

  // ---- Data loading -------------------------------------------------

  function signInRedirect() {
    window.location.href = '/login.php?return_to=' + encodeURIComponent(window.location.pathname + window.location.search);
  }

  function boot() {
    api('api/auth.php?action=me').then(function (r) {
      if (!r.data || !r.data.ok || !r.data.user) { signInRedirect(); return; }
      state.user = r.data.user;
      render();
      loadDigest();
    }).catch(function () { signInRedirect(); });
  }

  function loadDigest() {
    state.loading = true;
    state.error = null;
    render();
    api('api/digest.php?action=digest').then(function (r) {
      state.loading = false;
      if (r.data && r.data.ok) {
        state.digest = r.data;
      } else {
        state.error = (r.data && r.data.error) || 'Could not load your dashboard. Try refreshing.';
      }
      render();
    }).catch(function () {
      state.loading = false;
      state.error = 'Could not load your dashboard. Try refreshing.';
      render();
    });
  }

  function signOut() {
    apiPost('api/auth.php?action=logout').finally(function () {
      window.location.href = '/login.php';
    });
  }

  // ---- Render ---------------------------------------------------------

  function render() {
    root.innerHTML = topbarHtml() + '<main>' + mainHtml() + '</main>';
    bindEvents();
  }

  function topbarHtml() {
    if (!state.user) return '';
    return (
      '<div class="topbar">' +
        '<div class="topbar-left">' +
          '<div>' +
            '<div class="brand">My Day</div>' +
            '<div class="brand-sub">CodeBlue Technology — Your Daily Dashboard</div>' +
          '</div>' +
          '<a class="back-to-hub" href="' + HUB_URL + '">← Solutions Hub</a>' +
        '</div>' +
        '<div class="topbar-right">' +
          '<span>' + esc(state.user.name) + '</span>' +
          '<button class="signout-btn" data-action="signout" type="button">Sign Out</button>' +
        '</div>' +
      '</div>'
    );
  }

  function mainHtml() {
    if (state.loading && !state.digest) return '<div class="loading">Loading your day…</div>';
    if (state.error && !state.digest) {
      return '<div class="error-banner">' + esc(state.error) + '</div>';
    }
    var d = state.digest;
    if (!d) return '';
    return (
      greetingHtml(d) +
      mustDoHtml(d.todos) +
      projectsCardHtml(d.projects) +
      territoryHealthCardHtml(d.territory_risk, d.restricted) +
      financeCardHtml(d)
    );
  }

  function greetingHtml(d) {
    var hour = new Date().getHours();
    var part = hour < 12 ? 'morning' : (hour < 18 ? 'afternoon' : 'evening');
    var firstName = (d.user && d.user.name ? d.user.name : '').split(' ')[0] || 'there';
    var today = new Date().toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric' });
    return (
      '<div class="myday-greeting">' +
        '<h1 class="myday-greeting-h">Good ' + part + ', ' + esc(firstName) + '.</h1>' +
        '<div class="myday-greeting-sub">' + esc(today) + (d.restricted ? '' : ' · Unrestricted view — showing the whole book, not a single territory.') + '</div>' +
      '</div>'
    );
  }

  // ---- Today's Must-Dos -------------------------------------------------

  function mustDoHtml(todos) {
    var total = (todos.overdue || 0) + (todos.due_today || 0) + (todos.future || 0);
    function tile(key, label, count) {
      var zero = count === 0 ? ' myday-stat-zero' : '';
      return '<a class="myday-stat myday-stat-' + key + zero + '" href="' + REP_TODOS_URL + '" title="Open your to-do list and calendar">' +
        '<div class="myday-stat-value">' + count + '</div>' +
        '<div class="myday-stat-label">' + label + '</div>' +
      '</a>';
    }
    return (
      '<div class="myday-card">' +
        '<div class="myday-card-head">' +
          '<h2 class="myday-card-title">Today’s Must-Dos</h2>' +
          '<span class="myday-card-hint">' + total + ' open to-do' + (total === 1 ? '' : 's') + ' — click any to open your list</span>' +
        '</div>' +
        '<div class="myday-grid-3">' +
          tile('overdue', 'Overdue', todos.overdue || 0) +
          tile('due-today', 'Due Today', todos.due_today || 0) +
          tile('future', 'Upcoming', todos.future || 0) +
        '</div>' +
      '</div>'
    );
  }

  // ---- My Projects -------------------------------------------------------

  function projectsCardHtml(p) {
    var body;
    if (!p.total) {
      body = '<div class="myday-locked">No projects assigned to you right now.</div>';
    } else {
      body = bucketBarHtml([
        { key: 'red', label: 'Just started', count: p.red },
        { key: 'amber', label: 'In progress', count: p.yellow },
        { key: 'green', label: 'Nearly done', count: p.green }
      ], p.total);
    }
    return (
      '<a class="myday-card clickable" href="' + PROJECTS_URL + '">' +
        '<div class="myday-card-head">' +
          '<h2 class="myday-card-title">My Projects</h2>' +
          '<span class="myday-card-link">' + p.total + ' assigned →</span>' +
        '</div>' +
        body +
      '</a>'
    );
  }

  // ---- My Territory Health -----------------------------------------------

  function territoryHealthCardHtml(risk, restricted) {
    var total = risk.total || 0;
    var dangerPct = total > 0 ? Math.round((risk.danger / total) * 100) : 0;
    var gaugeColor = dangerPct > 0 ? 'oklch(0.6 0.18 27)' : 'oklch(0.6 0.15 155)';
    var body;
    if (!total) {
      body = '<div class="myday-locked">No customers in your territory yet.</div>';
    } else {
      body =
        '<div class="myday-gauge-wrap">' +
          '<div class="myday-gauge" style="--pct:' + dangerPct + ';--gauge-color:' + gaugeColor + '">' +
            '<div class="myday-gauge-label">' + dangerPct + '%</div>' +
          '</div>' +
          '<div style="flex:1;min-width:220px;">' +
            bucketBarHtml([
              { key: 'red', label: 'Account in danger', count: risk.danger },
              { key: 'green', label: 'Likely to need services', count: risk.opportunity },
              { key: 'gray', label: 'Stable', count: risk.stable }
            ], total) +
          '</div>' +
        '</div>' +
        (risk.territories && risk.territories.length
          ? '<div class="myday-chips">' + risk.territories.map(function (t) { return '<span class="myday-chip">' + esc(t) + '</span>'; }).join('') + '</div>'
          : '');
    }
    return (
      '<a class="myday-card clickable" href="' + RELATIONSHIPS_URL + '">' +
        '<div class="myday-card-head">' +
          '<h2 class="myday-card-title">My Territory Health</h2>' +
          '<span class="myday-card-link">' + total + ' customer' + (total === 1 ? '' : 's') + (restricted ? '' : ' — company-wide') + ' →</span>' +
        '</div>' +
        body +
      '</a>'
    );
  }

  // Shared stacked-bar + legend renderer for a list of
  // { key: 'red'|'amber'|'green'|'gray', label, count } buckets.
  function bucketBarHtml(buckets, total) {
    var segs = buckets.map(function (b) {
      var pct = total > 0 ? (b.count / total) * 100 : 0;
      return pct > 0 ? '<div class="myday-bar-seg ' + b.key + '" style="width:' + pct + '%" title="' + esc(b.label) + ': ' + b.count + '"></div>' : '';
    }).join('');
    var legend = buckets.map(function (b) {
      return '<span class="myday-bar-legend-item"><span class="myday-bar-dot ' + b.key + '"></span>' + esc(b.label) + ' <span class="myday-bar-count">' + b.count + '</span></span>';
    }).join('');
    return '<div class="myday-bar-track">' + segs + '</div><div class="myday-bar-legend">' + legend + '</div>';
  }

  // ---- My Territory Finance ----------------------------------------------

  function financeCardHtml(d) {
    if (!d.finance_access) {
      // Not on the Client Finance allow-list -- same people who get the
      // Finance hub tile itself (auth/client-finance-access.php). Leaving
      // this card off entirely (rather than showing it locked) matches
      // how the hub tile is simply absent for everyone else.
      return '';
    }
    var f = d.finance || {};
    var rec = f.recurring_revenue || {};
    var ann = f.annual_revenue || {};
    var so = f.sales_order_revenue || {};
    return (
      '<div class="myday-card">' +
        '<div class="myday-card-head">' +
          '<h2 class="myday-card-title">My Territory Finance</h2>' +
          '<a class="myday-card-link" href="' + CLIENT_FINANCE_URL + '">Open Client Finance →</a>' +
        '</div>' +
        '<div class="myday-grid-finance">' +
          '<a class="myday-finance-tile" href="' + CLIENT_FINANCE_URL + '">' +
            '<div class="myday-finance-value">' + fmtCurrency(rec.total) + '</div>' +
            '<div class="myday-finance-label">Recurring Revenue</div>' +
            '<div class="myday-finance-sub">' + (rec.month ? fmtMonth(rec.month) : 'Latest closed month') + '</div>' +
          '</a>' +
          '<a class="myday-finance-tile" href="' + CLIENT_FINANCE_URL + '">' +
            '<div class="myday-finance-value">' + fmtCurrency(ann.total) + '</div>' +
            '<div class="myday-finance-label">Annual Revenue</div>' +
            '<div class="myday-finance-sub">' + (ann.year || '') + ' year-to-date</div>' +
          '</a>' +
          '<a class="myday-finance-tile" href="' + CLIENT_FINANCE_URL + '">' +
            '<div class="myday-finance-value">' + fmtCurrency(so.total) + '</div>' +
            '<div class="myday-finance-label">Sales Order Revenue</div>' +
            '<div class="myday-finance-sub">' + (so.year || '') + ' year-to-date</div>' +
          '</a>' +
        '</div>' +
      '</div>'
    );
  }

  // ---- Events -------------------------------------------------------

  function bindEvents() {
    root.querySelectorAll('[data-action]').forEach(function (el) {
      el.addEventListener('click', function () {
        var action = el.getAttribute('data-action');
        if (action === 'signout') signOut();
      });
    });
  }

  boot();
})();
