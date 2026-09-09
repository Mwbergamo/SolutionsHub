/**
 * relationships/app.js
 *
 * CRC "Relationships" dashboard — a small, separate vanilla-JS app (no
 * runtime.js template engine; this is a fraction of SolutionsHub's size and
 * plain string-rendering is simpler than pulling that engine in). Talks to
 * relationships/api/auth.php and relationships/api/customers.php.
 *
 * Phase 1 scope: sign-in gate, customer search, pillar summary (bright =
 * has at least one active service in that pillar, dark = none), pillar
 * drill-down showing active products + missing services, a missing-services
 * roster, deep-links back into the main Solutions Hub for a missing
 * service, and a link out to the (placeholder, pending real per-service
 * folders) SharePoint marketing library. The 7-step cross-sell checklist
 * and the step-queue reporting view are later phases — not built yet.
 */

(function () {
  'use strict';

  // SolutionsHub site root is one level up from /relationships/.
  var HUB_URL = '../index.html';

  // Placeholder until CodeBlue supplies the real per-pillar/per-service
  // SharePoint folder links — for now every "View Marketing" action opens
  // the shared library root.
  var MARKETING_LIBRARY_URL = 'https://codebluetechnology.sharepoint.com/sites/Training/Customer%20Marketing/Forms/AllItems.aspx';

  var root = document.getElementById('app-root');

  var state = {
    user: null,
    query: '',
    searching: false,
    results: [],
    resultsOpen: false,
    selectedCustomer: null, // { customer: {id,name}, pillars: [...] }
    loadingDetail: false,
    activePillarId: null,
    error: null
  };

  function escapeHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function fmtQty(p) {
    var qty = p.qty;
    var unit = p.unit ? ' ' + escapeHtml(p.unit) : '';
    return escapeHtml(qty) + unit;
  }

  // ---- API helpers ----------------------------------------------------

  function apiGet(url) {
    return fetch(url, { credentials: 'same-origin' }).then(function (r) {
      return r.json().then(function (data) { return { status: r.status, data: data }; });
    });
  }

  function apiPost(url, body) {
    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body || {})
    }).then(function (r) {
      return r.json().then(function (data) { return { status: r.status, data: data }; });
    });
  }

  // ---- Data loading -----------------------------------------------------

  function boot() {
    apiGet('api/auth.php?action=me').then(function (r) {
      if (!r.data || !r.data.ok || !r.data.user) {
        window.location.href = 'login.html?next=' + encodeURIComponent('index.html');
        return;
      }
      state.user = r.data.user;
      render();
    }).catch(function () {
      window.location.href = 'login.html?next=' + encodeURIComponent('index.html');
    });
  }

  var searchDebounce = null;
  function runSearch(q) {
    clearTimeout(searchDebounce);
    if (!q.trim()) {
      state.results = [];
      state.searching = false;
      render();
      return;
    }
    searchDebounce = setTimeout(function () {
      state.searching = true;
      render();
      apiGet('api/customers.php?action=list&q=' + encodeURIComponent(q)).then(function (r) {
        state.searching = false;
        if (r.data && r.data.ok) {
          state.results = r.data.customers;
        }
        render();
      }).catch(function () {
        state.searching = false;
        render();
      });
    }, 220);
  }

  function selectCustomer(id) {
    state.loadingDetail = true;
    state.resultsOpen = false;
    state.activePillarId = null;
    state.error = null;
    render();
    apiGet('api/customers.php?action=detail&id=' + encodeURIComponent(id)).then(function (r) {
      state.loadingDetail = false;
      if (r.data && r.data.ok) {
        state.selectedCustomer = r.data;
      } else {
        state.error = (r.data && r.data.error) || 'Could not load that customer.';
      }
      render();
    }).catch(function () {
      state.loadingDetail = false;
      state.error = 'Could not load that customer — check your connection and try again.';
      render();
    });
  }

  function signOut() {
    apiPost('api/auth.php?action=logout', {}).finally(function () {
      window.location.href = 'login.html';
    });
  }

  // ---- Derived data -----------------------------------------------------

  function missingRoster(detail) {
    var roster = [];
    detail.pillars.forEach(function (pillar) {
      pillar.services.forEach(function (svc) {
        if (!svc.active) {
          roster.push({ pillarId: pillar.id, pillarName: pillar.name, serviceId: svc.id, serviceName: svc.name });
        }
      });
    });
    return roster;
  }

  // ---- Rendering ----------------------------------------------------

  function render() {
    root.innerHTML = topbarHtml() + '<div class="main">' + mainHtml() + '</div>';
    bindEvents();
  }

  function topbarHtml() {
    if (!state.user) return '';
    return (
      '<div class="topbar">' +
        '<div class="topbar-left">' +
          '<div>' +
            '<div class="brand">Relationships</div>' +
            '<div class="brand-sub">CodeBlue Technology — Client Relationship Dashboard</div>' +
          '</div>' +
          '<a class="back-to-hub" href="' + HUB_URL + '">← Solutions Hub</a>' +
        '</div>' +
        '<div class="topbar-right">' +
          '<span>' + escapeHtml(state.user.name) + '</span>' +
          '<button class="signout-btn" data-action="signout" type="button">Sign Out</button>' +
        '</div>' +
      '</div>'
    );
  }

  function mainHtml() {
    if (!state.user) return '<div class="loading">Loading…</div>';

    var html = '<div class="search-wrap">' + searchBoxHtml() + '</div>';

    if (state.error) {
      html += '<div class="error-banner">' + escapeHtml(state.error) + '</div>';
    }

    if (state.loadingDetail) {
      html += '<div class="loading">Loading customer…</div>';
    } else if (state.selectedCustomer) {
      html += customerDashboardHtml(state.selectedCustomer);
    } else {
      html += '<div class="empty-state">Search for a customer above to see their active CodeBlue services and what they’re missing.</div>';
    }

    return html;
  }

  function searchBoxHtml() {
    var box =
      '<div class="search-box">' +
        '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="oklch(0.5 0.02 255)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex:0 0 auto;"><circle cx="11" cy="11" r="7"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>' +
        '<input id="customerSearchInput" type="text" value="' + escapeHtml(state.query) + '" placeholder="Search customers by name…" autocomplete="off">' +
      '</div>';

    if (state.resultsOpen && state.query.trim()) {
      box += '<div class="search-results">';
      if (state.searching) {
        box += '<div class="search-empty">Searching…</div>';
      } else if (state.results.length) {
        state.results.forEach(function (c) {
          box += '<div class="search-result-row" data-action="select-customer" data-id="' + c.id + '">' + escapeHtml(c.name) + '</div>';
        });
      } else {
        box += '<div class="search-empty">No customers match “' + escapeHtml(state.query) + '”.</div>';
      }
      box += '</div>';
    }

    return box;
  }

  function customerDashboardHtml(detail) {
    var roster = missingRoster(detail);
    var html = '';

    html += '<div class="customer-header">' +
      '<div class="customer-name">' + escapeHtml(detail.customer.name) + '</div>' +
      '<button class="change-customer-btn" type="button" data-action="change-customer">Search a different customer</button>' +
    '</div>';

    html += '<div class="dashboard-grid">';

    html += '<div class="pillar-grid">';
    detail.pillars.forEach(function (pillar) {
      var activeCount = pillar.services.filter(function (s) { return s.active; }).length;
      var totalCount = pillar.services.length;
      html += '<div class="pillar-tile ' + (pillar.active ? 'active' : 'inactive') + '" data-action="open-pillar" data-pillar="' + pillar.id + '">' +
        '<div>' +
          '<div class="pillar-tile-name">' + escapeHtml(pillar.name) + '</div>' +
          '<div class="pillar-tile-count">' + activeCount + ' of ' + totalCount + ' service areas in use</div>' +
        '</div>' +
        '<div class="pillar-tile-status">' + (pillar.active ? 'ACTIVE →' : 'NOT IN USE →') + '</div>' +
      '</div>';
    });
    html += '</div>';

    html += '<div class="roster-panel">' +
      '<div class="roster-title">Cross-Sell Opportunities</div>' +
      '<div class="roster-sub">Services this customer isn’t using yet. Click one to open its pillar.</div>' +
      '<div class="roster-list">';
    if (roster.length === 0) {
      html += '<div class="roster-empty">This customer is using every CodeBlue service area — nothing to cross-sell right now.</div>';
    } else {
      roster.forEach(function (item) {
        html += '<div class="roster-item" data-action="open-pillar" data-pillar="' + item.pillarId + '">' +
          '<div class="roster-item-pillar">' + escapeHtml(item.pillarName) + '</div>' +
          '<div class="roster-item-name">' + escapeHtml(item.serviceName) + '</div>' +
        '</div>';
      });
    }
    html += '</div></div>';

    html += '</div>'; // .dashboard-grid

    if (state.activePillarId) {
      var pillar = detail.pillars.filter(function (p) { return p.id === state.activePillarId; })[0];
      if (pillar) {
        html += drilldownHtml(pillar);
      }
    }

    return html;
  }

  function drilldownHtml(pillar) {
    var html = '<div class="drilldown">' +
      '<div class="drilldown-header">' +
        '<button class="drilldown-back" type="button" data-action="close-drilldown" aria-label="Close">' +
          '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"></polyline></svg>' +
        '</button>' +
        '<div class="drilldown-title">' + escapeHtml(pillar.name) + '</div>' +
      '</div>';

    pillar.services.forEach(function (svc) {
      if (svc.active) {
        html += '<div class="service-block active">' +
          '<div class="service-block-head">' +
            '<div class="service-name">' + escapeHtml(svc.name) + '</div>' +
            '<div class="service-badge active">ACTIVE</div>' +
          '</div>' +
          '<div class="product-list">' +
            svc.products.map(function (p) {
              return '<div class="product-row"><span>' + escapeHtml(p.label) + '</span><span class="product-qty">' + fmtQty(p) + '</span></div>';
            }).join('') +
          '</div>' +
        '</div>';
      } else {
        var hubUrl = HUB_URL + '?pillar=' + encodeURIComponent(pillar.id) + '&service=' + encodeURIComponent(svc.id);
        html += '<div class="service-block inactive">' +
          '<div class="service-block-head">' +
            '<div class="service-name">' + escapeHtml(svc.name) + '</div>' +
            '<div class="service-badge inactive">NOT IN USE</div>' +
          '</div>' +
          '<div class="service-actions">' +
            '<a class="svc-action-btn primary" href="' + hubUrl + '" target="_blank" rel="noopener">Open in Solutions Hub →</a>' +
            '<a class="svc-action-btn secondary" href="' + MARKETING_LIBRARY_URL + '" target="_blank" rel="noopener">View Marketing ↗</a>' +
          '</div>' +
        '</div>';
      }
    });

    html += '</div>';
    return html;
  }

  // ---- Event binding ----------------------------------------------------

  // Tracks whether the search input was focused going into the last
  // render(), purely so a re-render (which replaces the DOM node — the old
  // one loses focus for free) can silently refocus the new one. Neither
  // listener below calls render() itself: doing that from a focus handler
  // while bindEvents() re-focuses on every render is a recursive loop
  // (each render -> focus() -> 'focus' handler -> render() -> ...) that
  // blows the call stack — caught in testing, kept as a comment as a
  // trap for the next person who "simplifies" this.
  var searchInputHadFocus = false;

  function bindEvents() {
    var searchInput = document.getElementById('customerSearchInput');
    if (searchInput) {
      searchInput.addEventListener('input', function (e) {
        state.query = e.target.value;
        state.resultsOpen = true;
        runSearch(state.query);
      });
      searchInput.addEventListener('focus', function () { searchInputHadFocus = true; });
      searchInput.addEventListener('blur', function () { searchInputHadFocus = false; });

      // Preserve focus/caret across re-renders triggered while typing.
      if (searchInputHadFocus) {
        searchInput.focus();
        searchInput.setSelectionRange(searchInput.value.length, searchInput.value.length);
      }
    }
  }

  function onRootClick(e) {
    var el = e.target.closest('[data-action]');
    if (!el) return;
    var action = el.getAttribute('data-action');

    if (action === 'select-customer') {
      selectCustomer(el.getAttribute('data-id'));
    } else if (action === 'change-customer') {
      state.selectedCustomer = null;
      state.activePillarId = null;
      state.query = '';
      state.results = [];
      state.resultsOpen = false;
      render();
    } else if (action === 'open-pillar') {
      state.activePillarId = el.getAttribute('data-pillar');
      render();
      var dd = document.querySelector('.drilldown');
      if (dd) dd.scrollIntoView({ behavior: 'smooth', block: 'start' });
    } else if (action === 'close-drilldown') {
      state.activePillarId = null;
      render();
    } else if (action === 'signout') {
      signOut();
    }
  }

  // Close the search dropdown on an outside click, without a rebind loop.
  function onDocumentClick(e) {
    if (!state.resultsOpen) return;
    if (e.target.closest('.search-wrap')) return;
    state.resultsOpen = false;
    render();
  }

  // Bound once — root's contents are replaced on every render(), so these
  // rely on event delegation rather than being rebound each time.
  root.addEventListener('click', onRootClick);
  document.addEventListener('click', onDocumentClick);

  boot();
})();
