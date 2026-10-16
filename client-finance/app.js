/* client-finance/app.js — Client Finance sub-app (added 2026-10-16).
   Vanilla JS, renders straight into #app-root (same approach as
   relationships/app.js and commissions/app.js). Access is restricted to
   the same four people as Commissions -- enforced by every
   client-finance/api/*.php endpoint, not just by hiding the card.

   This first pass only wires up sign-in, the customer search (typing a
   name, just like Relationships' picker), and a dashboard shell showing
   all five planned sections as "coming soon" placeholders. The real
   section data (Standard Invoices, Product Sales, Agreement Invoicing,
   Credits, Opportunities) lands once client-finance/api/sync-core.php is
   written against confirmed ConnectWise field names -- see
   client-finance/api/probe.php and client-finance/api/db.php's headers. */
(function () {
  'use strict';

  var root = document.getElementById('app-root');

  var SECTIONS = [
    {
      key: 'standard',
      title: 'Standard Invoices',
      desc: 'Time & materials service billing, invoiced dollars over the last 6 months with trend.',
      icon: '<rect x="3" y="4" width="18" height="16" rx="2"></rect><path d="M7 9h10M7 13h10M7 17h6"></path>'
    },
    {
      key: 'product_sales',
      title: 'Product Sales',
      desc: 'Sales order dollars over the last year, year-over-year, with line-level drill-down into what was sold.',
      icon: '<path d="M21 8l-9-5-9 5 9 5 9-5z"></path><path d="M3 8v8l9 5 9-5V8"></path><path d="M12 13v8"></path>'
    },
    {
      key: 'agreements',
      title: 'Agreement Invoicing',
      desc: 'Every agreement, its invoices and trend, with addition-level detail (price, cost, start/cancelled dates).',
      icon: '<path d="M8 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"></path><path d="M14 3v6h6"></path><path d="M8 13h8M8 17h5"></path>'
    },
    {
      key: 'credits',
      title: 'Credits',
      desc: 'Credit memo invoices, 6 months of trend, with invoice-level detail of what was credited and when.',
      icon: '<circle cx="12" cy="12" r="9"></circle><path d="M8 12h8"></path>'
    },
    {
      key: 'opportunities',
      title: 'Opportunities',
      desc: 'Computer or networking hardware sold 3+ years ago -- a prompt to discuss upgrade and replacement.',
      icon: '<path d="M12 2v6M12 2l3 3M12 2L9 5"></path><circle cx="12" cy="14" r="8"></circle><path d="M12 10v4l3 2"></path>',
      cls: 'cf-opportunity'
    }
  ];

  var state = {
    user: null,
    denied: false,
    error: null,
    view: 'search',           // search | dashboard
    query: '',
    results: null,            // null = not searched yet
    searching: false,
    searchSeq: 0,
    customer: null,           // { cw_company_id, name, is_peoplefirst, is_prospect_only, is_residential }
    dashLoading: false,
    sync: null                // per-section sync state from api/dashboard.php
  };

  // ---- helpers --------------------------------------------------------

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function api(url, body) {
    var opts = { credentials: 'same-origin' };
    if (body !== undefined) {
      opts.method = 'POST';
      opts.headers = { 'Content-Type': 'application/json' };
      opts.body = JSON.stringify(body);
    }
    return fetch(url, opts).then(function (r) {
      return r.json().catch(function () { return { ok: false, error: 'Unexpected response (HTTP ' + r.status + ').' }; })
        .then(function (data) { return { status: r.status, data: data }; });
    });
  }

  function debounce(fn, ms) {
    var t = null;
    return function () {
      var args = arguments;
      var ctx = this;
      clearTimeout(t);
      t = setTimeout(function () { fn.apply(ctx, args); }, ms);
    };
  }

  // ---- boot -------------------------------------------------------------

  function boot() {
    api('api/auth.php?action=me').then(function (r) {
      if (r.status === 401) { window.location.href = 'login.html'; return; }
      if (r.status === 403) { state.denied = true; render(); return; }
      if (!r.data || !r.data.ok) { state.error = (r.data && r.data.error) || 'Could not load.'; render(); return; }
      state.user = r.data.user;
      render();
    }).catch(function () { state.error = 'Could not reach the server.'; render(); });
  }

  // ---- search -------------------------------------------------------------

  var runSearch = debounce(function () {
    var seq = ++state.searchSeq;
    state.searching = true;
    render();
    api('api/customers.php?action=search&q=' + encodeURIComponent(state.query)).then(function (r) {
      if (seq !== state.searchSeq) return; // stale response, a newer keystroke already fired
      state.searching = false;
      if (r.data && r.data.ok) {
        state.results = r.data.customers;
      } else {
        state.results = [];
        state.error = (r.data && r.data.error) || 'Could not search customers.';
      }
      render();
    }).catch(function () {
      if (seq !== state.searchSeq) return;
      state.searching = false;
      state.results = [];
      state.error = 'Could not reach the server.';
      render();
    });
  }, 250);

  function onSearchInput(val) {
    state.query = val;
    state.error = null;
    if (val.trim() === '' && state.results === null) { render(); return; }
    runSearch();
  }

  function selectCustomer(c) {
    state.customer = c;
    state.view = 'dashboard';
    state.sync = null;
    state.error = null;
    render();
    loadDashboard();
  }

  function backToSearch() {
    state.view = 'search';
    state.customer = null;
    render();
  }

  function loadDashboard() {
    state.dashLoading = true;
    render();
    api('api/dashboard.php?action=summary&cw_company_id=' + state.customer.cw_company_id).then(function (r) {
      state.dashLoading = false;
      if (r.data && r.data.ok) {
        state.customer = r.data.company;
        state.sync = r.data.sync;
        state.error = null;
      } else {
        state.error = (r.data && r.data.error) || 'Could not load this customer.';
      }
      render();
    }).catch(function () { state.dashLoading = false; state.error = 'Could not reach the server.'; render(); });
  }

  // ---- render -------------------------------------------------------------

  function badgesHtml(c) {
    var out = '';
    if (c.is_peoplefirst) out += '<span class="cf-badge peoplefirst">PeopleFirst</span>';
    if (c.is_prospect_only) out += '<span class="cf-badge prospect">Prospect</span>';
    if (c.is_residential) out += '<span class="cf-badge">Residential</span>';
    return out;
  }

  function searchViewHtml() {
    var html = '<div class="cf-search-wrap">';
    html += '<h2>Who are we meeting with?</h2>';
    html += '<div class="view-sub">Type a customer or contact name to pull up their financial dashboard.</div>';
    html += '<div class="cf-search-box">';
    html += '<input type="text" class="cf-search-input" id="cf-search-input" placeholder="Search customers…" value="' + esc(state.query) + '" autocomplete="off">';
    html += '</div>';

    if (state.searching) {
      html += '<div class="cf-search-results"><div class="cf-search-empty">Searching…</div></div>';
    } else if (state.results !== null) {
      if (state.results.length === 0) {
        html += '<div class="cf-search-results"><div class="cf-search-empty">No matching customers.</div></div>';
      } else {
        html += '<div class="cf-search-results">';
        html += state.results.map(function (c, i) {
          var via = c.matched_contact_name ? '<div class="via">via ' + esc(c.matched_contact_name) + '</div>' : '';
          return '<button class="cf-search-row" type="button" data-action="select-customer" data-idx="' + i + '">' +
            '<span><span class="name">' + esc(c.name) + '</span>' + via + '</span>' +
            '<span class="badges">' + badgesHtml(c) + '</span>' +
          '</button>';
        }).join('');
        html += '</div>';
      }
    }
    html += '</div>';
    return html;
  }

  function sectionCardHtml(section) {
    var statusText = 'Coming soon';
    if (state.sync) {
      var key = section.key === 'standard' ? 'standard_synced_at'
        : section.key === 'product_sales' ? 'product_sales_synced_at'
        : section.key === 'agreements' ? 'agreements_synced_at'
        : section.key === 'credits' ? 'credits_synced_at'
        : 'opportunities_synced_at';
      if (!state.sync[key]) statusText = 'Coming soon — not built yet';
    }
    return '<div class="cf-section-card' + (section.cls ? ' ' + section.cls : '') + '">' +
      '<div class="cf-section-title"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + section.icon + '</svg>' + esc(section.title) + '</div>' +
      '<div class="cf-section-desc">' + esc(section.desc) + '</div>' +
      '<div class="cf-section-status">' + statusText + '</div>' +
    '</div>';
  }

  function dashboardViewHtml() {
    var c = state.customer;
    var html = '<div>';
    html += '<a href="#" class="cf-back" data-action="back-to-search">&larr; Back to search</a>';
    html += '<div class="cf-cust-head"><div><h2 class="view-title">' + esc(c.name) + '</h2>';
    if (c.cw_company_id) html += '<div class="badges">' + badgesHtml(c) + '</div>';
    html += '</div></div>';

    if (state.dashLoading && !state.sync) {
      html += '<div class="loading">Loading…</div>';
    } else {
      html += '<div class="cf-sections">' + SECTIONS.map(sectionCardHtml).join('') + '</div>';
    }
    html += '</div>';
    return html;
  }

  function render() {
    var html = '';
    if (state.denied) {
      html = '<div class="topbar"><div class="topbar-left"><div><div class="brand">Client Finance</div><div class="brand-sub">CodeBlue Technology</div></div></div></div>' +
        '<main><div class="banner error">You do not have access to Client Finance.</div></main>';
      root.innerHTML = html;
      return;
    }
    if (!state.user) {
      root.innerHTML = '<div class="loading">Loading…</div>';
      return;
    }

    html += '<div class="topbar">';
    html += '<div class="topbar-left"><div><div class="brand">Client Finance</div><div class="brand-sub">CodeBlue Technology</div></div></div>';
    html += '<div class="topbar-right"><a class="back-to-hub" href="../finance/">&larr; Finance</a></div>';
    html += '</div>';

    html += '<main>';
    if (state.error) html += '<div class="banner error">' + esc(state.error) + '</div>';
    html += state.view === 'dashboard' && state.customer ? dashboardViewHtml() : searchViewHtml();
    html += '</main>';

    root.innerHTML = html;

    var input = document.getElementById('cf-search-input');
    if (input) {
      input.focus();
      input.setSelectionRange(input.value.length, input.value.length);
    }
  }

  // ---- events -------------------------------------------------------------

  root.addEventListener('input', function (e) {
    if (e.target && e.target.id === 'cf-search-input') {
      onSearchInput(e.target.value);
    }
  });

  root.addEventListener('click', function (e) {
    var el = e.target.closest('[data-action]');
    if (!el) return;
    var action = el.getAttribute('data-action');
    if (action === 'select-customer') {
      e.preventDefault();
      var idx = Number(el.getAttribute('data-idx'));
      if (state.results && state.results[idx]) selectCustomer(state.results[idx]);
    } else if (action === 'back-to-search') {
      e.preventDefault();
      backToSearch();
    }
  });

  boot();
})();
