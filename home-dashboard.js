/* home-dashboard.js -- the personalized "My Day" dashboard, merged onto
   the main SolutionsHub home screen (2026-11-12, per Michael: "I want
   the information in My Day to be on the main portal screen"). Mounts
   into #home-dashboard-root, which lives inside the Overview section of
   index.html's app-template.

   That section is controlled by the custom template runtime's
   <sc-if value="{{isOverview}}">, which REMOVES and re-inserts its DOM
   subtree every time a rep navigates away and back to Overview (see
   runtime.js's doc comment on sc-if) -- so #home-dashboard-root is a
   brand-new element each time, not just hidden/shown. A MutationObserver
   watches for it appearing and (re)mounts into it, rather than booting
   once on page load, so the dashboard loads fresh data every time a rep
   lands back on the home screen, same as navigating to any other screen
   would.

   This used to be its own standalone app at myday/ (see
   claude/relationships-myday-dashboard.md) -- myday/index.html now just
   redirects here. The backend (myday/api/digest.php) is unchanged and
   stays where it is; it already uses the same shared Microsoft 365
   session the root page itself already required before this script ever
   runs (see index.html's own sign-in gate), so there's no separate
   sign-in check here the way the old standalone myday/app.js needed
   one -- a 401 here just means the shared session itself expired. */
(function () {
  'use strict';

  var DIGEST_URL = 'myday/api/digest.php?action=digest';
  var REP_TODOS_URL = 'relationships/index.html?view=rep-todos';
  var PROJECTS_URL = 'relationships/index.html?view=projects&section=projects';
  var RELATIONSHIPS_URL = 'relationships/index.html';
  var CLIENT_FINANCE_URL = 'client-finance/index.html';

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

  function mountDashboard(root) {
    var state = { loading: true, error: null, digest: null };

    function render() {
      root.innerHTML = mainHtml();
    }

    function mainHtml() {
      if (state.loading && !state.digest) return '<div class="home-dash-loading">Loading your day…</div>';
      if (state.error && !state.digest) return '<div class="home-dash-error">' + esc(state.error) + '</div>';
      var d = state.digest;
      if (!d) return '';
      return (
        greetingHtml(d) +
        mustDoHtml(d.todos) +
        meetingsHtml(d.meetings) +
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
        '<div class="home-dash-greeting">' +
          '<h1 class="home-dash-greeting-h">Good ' + part + ', ' + esc(firstName) + '.</h1>' +
          '<div class="home-dash-greeting-sub">' + esc(today) + (d.restricted ? '' : ' · Unrestricted view — showing the whole book, not a single territory.') + '</div>' +
        '</div>'
      );
    }

    function mustDoHtml(todos) {
      var total = (todos.overdue || 0) + (todos.due_today || 0) + (todos.future || 0);
      function tile(key, label, count) {
        var zero = count === 0 ? ' home-dash-stat-zero' : '';
        return '<a class="home-dash-stat home-dash-stat-' + key + zero + '" href="' + REP_TODOS_URL + '" title="Open your to-do list and calendar">' +
          '<div class="home-dash-stat-value">' + count + '</div>' +
          '<div class="home-dash-stat-label">' + label + '</div>' +
        '</a>';
      }
      return (
        '<div class="home-dash-card">' +
          '<div class="home-dash-card-head">' +
            '<h2 class="home-dash-card-title">Today’s Must-Dos</h2>' +
            '<span class="home-dash-card-hint">' + total + ' open to-do' + (total === 1 ? '' : 's') + ' — click any to open your list</span>' +
          '</div>' +
          '<div class="home-dash-grid-3">' +
            tile('overdue', 'Overdue', todos.overdue || 0) +
            tile('due-today', 'Due Today', todos.due_today || 0) +
            tile('future', 'Upcoming', todos.future || 0) +
          '</div>' +
        '</div>'
      );
    }

    // Scheduled Meetings -- added 2026-11-12, per Michael: "a gauge... for
    // any and all meetings for that rep... similar to Today's Must-Dos,
    // this would be one of scheduled Meetings." Mirrors mustDoHtml's
    // 3-tile layout, but bucketed Today / This Week / Later instead of
    // Overdue / Due Today / Upcoming -- a meeting doesn't go "overdue" the
    // way a to-do does, and only forward-looking (meeting_date >= today)
    // rows are counted at all (confirmed via AskUserQuestion; see
    // myday/api/digest.php's doc comment for the full reasoning and the
    // logged_by_name credit rule). Tiles link to the rep's own rep-todos
    // screen, same as mustDoHtml's tiles -- per Michael (2026-11-13), the
    // number should go straight to "the person's calendar" rather than
    // the Relationships root.
    function meetingsHtml(meetings) {
      var total = (meetings.today || 0) + (meetings.this_week || 0) + (meetings.later || 0);
      function tile(key, label, count) {
        var zero = count === 0 ? ' home-dash-stat-zero' : '';
        return '<a class="home-dash-stat home-dash-stat-' + key + zero + '" href="' + REP_TODOS_URL + '" title="Open your calendar">' +
          '<div class="home-dash-stat-value">' + count + '</div>' +
          '<div class="home-dash-stat-label">' + label + '</div>' +
        '</a>';
      }
      return (
        '<div class="home-dash-card">' +
          '<div class="home-dash-card-head">' +
            '<h2 class="home-dash-card-title">Scheduled Meetings</h2>' +
            '<span class="home-dash-card-hint">' + total + ' upcoming meeting' + (total === 1 ? '' : 's') + ' — click any to open your calendar</span>' +
          '</div>' +
          '<div class="home-dash-grid-3">' +
            tile('due-today', 'Today', meetings.today || 0) +
            tile('this-week', 'This Week', meetings.this_week || 0) +
            tile('later', 'Later', meetings.later || 0) +
          '</div>' +
        '</div>'
      );
    }

    function projectsCardHtml(p) {
      var body;
      if (!p.total) {
        body = '<div class="home-dash-locked">No projects assigned to you right now.</div>';
      } else {
        body = bucketBarHtml([
          { key: 'red', label: 'Just started', count: p.red },
          { key: 'amber', label: 'In progress', count: p.yellow },
          { key: 'green', label: 'Nearly done', count: p.green }
        ], p.total);
      }
      return (
        '<a class="home-dash-card clickable" href="' + PROJECTS_URL + '">' +
          '<div class="home-dash-card-head">' +
            '<h2 class="home-dash-card-title">My Projects</h2>' +
            '<span class="home-dash-card-link">' + p.total + ' assigned →</span>' +
          '</div>' +
          body +
        '</a>'
      );
    }

    function territoryHealthCardHtml(risk, restricted) {
      var total = risk.total || 0;
      var dangerPct = total > 0 ? Math.round((risk.danger / total) * 100) : 0;
      var gaugeColor = dangerPct > 0 ? 'var(--cbt-brand-red)' : 'var(--cbt-brand-green)';
      var body;
      if (!total) {
        body = '<div class="home-dash-locked">No customers in your territory yet.</div>';
      } else {
        body =
          '<div class="home-dash-gauge-wrap">' +
            '<div class="home-dash-gauge" style="--pct:' + dangerPct + ';--gauge-color:' + gaugeColor + '">' +
              '<div class="home-dash-gauge-label">' + dangerPct + '%</div>' +
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
            ? '<div class="home-dash-chips">' + risk.territories.map(function (t) { return '<span class="home-dash-chip">' + esc(t) + '</span>'; }).join('') + '</div>'
            : '');
      }
      return (
        '<a class="home-dash-card clickable" href="' + RELATIONSHIPS_URL + '">' +
          '<div class="home-dash-card-head">' +
            '<h2 class="home-dash-card-title">My Territory Health</h2>' +
            '<span class="home-dash-card-link">' + total + ' customer' + (total === 1 ? '' : 's') + (restricted ? '' : ' — company-wide') + ' →</span>' +
          '</div>' +
          body +
        '</a>'
      );
    }

    function bucketBarHtml(buckets, total) {
      var segs = buckets.map(function (b) {
        var pct = total > 0 ? (b.count / total) * 100 : 0;
        return pct > 0 ? '<div class="home-dash-bar-seg ' + b.key + '" style="width:' + pct + '%" title="' + esc(b.label) + ': ' + b.count + '"></div>' : '';
      }).join('');
      var legend = buckets.map(function (b) {
        return '<span class="home-dash-bar-legend-item"><span class="home-dash-bar-dot ' + b.key + '"></span>' + esc(b.label) + ' <span class="home-dash-bar-count">' + b.count + '</span></span>';
      }).join('');
      return '<div class="home-dash-bar-track">' + segs + '</div><div class="home-dash-bar-legend">' + legend + '</div>';
    }

    function financeCardHtml(d) {
      if (!d.finance_access) return '';
      var f = d.finance || {};
      var rec = f.recurring_revenue || {};
      var ann = f.annual_revenue || {};
      var so = f.sales_order_revenue || {};
      return (
        '<div class="home-dash-card">' +
          '<div class="home-dash-card-head">' +
            '<h2 class="home-dash-card-title">My Territory Finance</h2>' +
            '<a class="home-dash-card-link" href="' + CLIENT_FINANCE_URL + '">Open Client Finance →</a>' +
          '</div>' +
          '<div class="home-dash-grid-finance">' +
            '<a class="home-dash-finance-tile" href="' + CLIENT_FINANCE_URL + '">' +
              '<div class="home-dash-finance-value">' + fmtCurrency(rec.total) + '</div>' +
              '<div class="home-dash-finance-label">Recurring Revenue</div>' +
              '<div class="home-dash-finance-sub">' + (rec.month ? fmtMonth(rec.month) : 'Latest closed month') + '</div>' +
            '</a>' +
            '<a class="home-dash-finance-tile" href="' + CLIENT_FINANCE_URL + '">' +
              '<div class="home-dash-finance-value">' + fmtCurrency(ann.total) + '</div>' +
              '<div class="home-dash-finance-label">Annual Revenue</div>' +
              '<div class="home-dash-finance-sub">' + (ann.year || '') + ' year-to-date</div>' +
            '</a>' +
            '<a class="home-dash-finance-tile" href="' + CLIENT_FINANCE_URL + '">' +
              '<div class="home-dash-finance-value">' + fmtCurrency(so.total) + '</div>' +
              '<div class="home-dash-finance-label">Sales Order Revenue</div>' +
              '<div class="home-dash-finance-sub">' + (so.year || '') + ' year-to-date</div>' +
            '</a>' +
          '</div>' +
        '</div>'
      );
    }

    render();
    api(DIGEST_URL).then(function (r) {
      state.loading = false;
      if (r.status === 401) {
        window.location.href = '/login.php?return_to=' + encodeURIComponent(window.location.pathname + window.location.search);
        return;
      }
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

  function tryMount() {
    var el = document.getElementById('home-dashboard-root');
    if (el && !el.dataset.mounted) {
      el.dataset.mounted = '1';
      mountDashboard(el);
    }
  }

  tryMount();
  var observer = new MutationObserver(tryMount);
  observer.observe(document.body, { childList: true, subtree: true });
})();
