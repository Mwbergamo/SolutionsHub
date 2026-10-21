/* client-finance/app.js — Client Finance sub-app (added 2026-10-16).
   Vanilla JS, renders straight into #app-root (same approach as
   relationships/app.js and commissions/app.js). Access is restricted to
   the same four people as Commissions -- enforced by every
   client-finance/api/*.php endpoint, not just by hiding the card.

   Customer search + dashboard shell (2026-10-16), then the real sync
   engine (sync-core.php/sync.php, 2026-10-16), then the five section
   views below (2026-10-16) reading from api/sections.php once a
   customer's data is synced -- see client-finance/api/probe.php and
   client-finance/api/client-finance-probe-findings.md (project docs) for
   the ConnectWise research behind every field used here. */
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
  var SECTION_SYNC_KEY = {
    standard: 'standard_synced_at', product_sales: 'product_sales_synced_at',
    agreements: 'agreements_synced_at', credits: 'credits_synced_at', opportunities: 'opportunities_synced_at'
  };

  var state = {
    user: null,
    denied: false,
    error: null,
    view: 'search',           // search | dashboard | section
    query: '',
    results: null,            // null = not searched yet
    searching: false,
    searchSeq: 0,
    customer: null,           // { cw_company_id, name, is_peoplefirst, is_prospect_only, is_residential }
    dashLoading: false,
    sync: null,                // per-section sync state from api/dashboard.php + api/sync.php
    syncing: false,
    syncSeq: 0,
    sectionView: null,         // one of SECTIONS[].key while view === 'section'
    sectionData: null,
    sectionLoading: false,
    sectionSeq: 0,
    expandedLineId: null,      // invoice id whose product-line detail is expanded (product_sales/credits)
    pushingOppId: null,
    // Portfolio-wide gauges, front page only (added 2026-10-21) -- see
    // api/gauges.php's header for the sync-model reversal behind these
    // and claude/client-finance-gauges.md for the full decision trail.
    gaugeMrr: null,
    gaugeMrrLoading: false,
    gaugeMrrError: null
  };

  // ---- helpers --------------------------------------------------------

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function fmtCurrency(n) {
    return '$' + Number(n || 0).toLocaleString(undefined, { maximumFractionDigits: 0 });
  }

  function fmtDate(raw) {
    if (!raw) return '';
    var d = new Date(raw);
    if (isNaN(d.getTime())) return String(raw).slice(0, 10);
    return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
  }

  function fmtMonthLabel(m) {
    if (!m) return '';
    var d = new Date(m + '-02');
    if (isNaN(d.getTime())) return m;
    return d.toLocaleDateString(undefined, { month: 'short', year: 'numeric' });
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
      // Portfolio gauges load once per app boot, independent of which
      // view is showing -- nightly-cadence data doesn't need refetching
      // every time someone returns to the search view.
      loadGaugeMrr();
      // Deep link from a Relationships to-do ("linking back to the detail") --
      // ?cw_company_id=N jumps straight to that customer's dashboard,
      // skipping search. dashboard.php?action=summary resolves the real
      // name/badges once loadDashboard() runs.
      var params = new URLSearchParams(window.location.search);
      var linkedId = parseInt(params.get('cw_company_id'), 10);
      if (linkedId > 0) {
        state.customer = { cw_company_id: linkedId, name: '' };
        state.view = 'dashboard';
        render();
        loadDashboard();
        return;
      }
      render();
    }).catch(function () { state.error = 'Could not reach the server.'; render(); });
  }

  // ---- portfolio gauges (front page, under customer search) --------------
  // Added 2026-10-21. One function per gauge, same shape, so the next
  // gauge (Michael's explicit "one at a time" build process) is a copy
  // of this block pointed at a new api/gauges.php action, not a rewrite.

  function loadGaugeMrr() {
    state.gaugeMrrLoading = true;
    render();
    api('api/gauges.php?action=mrr').then(function (r) {
      state.gaugeMrrLoading = false;
      if (r.data && r.data.ok) {
        state.gaugeMrr = r.data.mrr;
        state.gaugeMrrError = null;
      } else {
        state.gaugeMrrError = (r.data && r.data.error) || 'Could not load this gauge.';
      }
      render();
    }).catch(function () {
      state.gaugeMrrLoading = false;
      state.gaugeMrrError = 'Could not reach the server.';
      render();
    });
  }

  function gaugeCoverageText(g) {
    if (!g) return '';
    var cov = g.coverage ? (g.coverage.synced + ' of ' + g.coverage.eligible + ' customers synced') : '';
    var bulk = g.bulk_sync || {};
    var asOf;
    if (bulk.finished_at) {
      asOf = 'as of ' + fmtDate(bulk.finished_at);
    } else if (bulk.started_at) {
      asOf = 'first sync in progress';
    } else {
      asOf = 'nightly sync not yet run';
    }
    return cov ? (cov + ' \u00b7 ' + asOf) : asOf;
  }

  function gaugeMrrCardHtml() {
    var g = state.gaugeMrr;
    var body;
    if (state.gaugeMrrLoading && !g) {
      body = '<div class="hint">Loading\u2026</div>';
    } else if (state.gaugeMrrError) {
      body = '<div class="hint">' + esc(state.gaugeMrrError) + '</div>';
    } else if (!g || !g.month) {
      body = '<div class="hint">No synced agreement revenue yet.</div>';
    } else {
      body = '<div class="val">' + fmtCurrency(g.total) + '</div>' +
        '<div class="cf-gauge-sub">' + esc(fmtMonthLabel(g.month)) + ' \u00b7 ' + g.customers_with_revenue + ' customers billed</div>';
    }
    return '<div class="cf-gauge-card">' +
      '<div class="lbl">Recurring Revenue (MRR)</div>' +
      body +
      '<div class="cf-gauge-foot">' + esc(gaugeCoverageText(g)) + '</div>' +
    '</div>';
  }

  function gaugesHtml() {
    return '<div class="cf-gauges-wrap"><div class="cf-gauges-row">' + gaugeMrrCardHtml() + '</div></div>';
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
        var neverSynced = !r.data.sync.standard_synced_at && !r.data.sync.product_sales_synced_at &&
          !r.data.sync.agreements_synced_at && !r.data.sync.credits_synced_at && !r.data.sync.opportunities_synced_at;
        if (neverSynced) startSync();
      } else {
        state.error = (r.data && r.data.error) || 'Could not load this customer.';
      }
      render();
    }).catch(function () { state.dashLoading = false; state.error = 'Could not reach the server.'; render(); });
  }

  // ---- sync -------------------------------------------------------------

  function startSync() {
    var seq = ++state.syncSeq;
    state.syncing = true;
    state.error = null;
    render();
    api('api/sync.php?action=start', { cw_company_id: state.customer.cw_company_id, company_name: state.customer.name }).then(function (r) {
      if (seq !== state.syncSeq) return;
      if (!r.data || !r.data.ok) {
        state.syncing = false;
        state.error = (r.data && r.data.error) || 'Could not start sync.';
        render();
        return;
      }
      state.sync = r.data.sync;
      render();
      stepSync(seq);
    }).catch(function () {
      if (seq !== state.syncSeq) return;
      state.syncing = false;
      state.error = 'Could not reach the server.';
      render();
    });
  }

  function stepSync(seq) {
    api('api/sync.php?action=step', {}).then(function (r) {
      if (seq !== state.syncSeq) return;
      if (!r.data || !r.data.ok) {
        state.syncing = false;
        state.error = (r.data && r.data.error) || 'Sync failed.';
        render();
        return;
      }
      if (r.data.done) {
        state.syncing = false;
        loadDashboardStatusOnly();
        // If the section view that's open just finished syncing, pull its
        // real data in automatically rather than leaving the rep looking
        // at a stale "Not yet synced" detail view.
        if (state.view === 'section' && state.sectionView) loadSectionData(state.sectionView);
        return;
      }
      loadDashboardStatusOnly();
      stepSync(seq);
    }).catch(function () {
      if (seq !== state.syncSeq) return;
      state.syncing = false;
      state.error = 'Could not reach the server.';
      render();
    });
  }

  // Refreshes just the per-section sync state (no full page reload) --
  // called after every step so the dashboard cards update live as each
  // section finishes, without re-running startSync().
  function loadDashboardStatusOnly() {
    if (!state.customer) return;
    api('api/sync.php?action=status&cw_company_id=' + state.customer.cw_company_id).then(function (r) {
      if (r.data && r.data.ok) state.sync = r.data.sync;
      render();
    }).catch(function () { /* next step's render will retry anyway */ });
  }

  // ---- section detail -----------------------------------------------------

  function openSection(key) {
    state.view = 'section';
    state.sectionView = key;
    state.sectionData = null;
    state.sectionError = null;
    state.expandedLineId = null;
    render();
    loadSectionData(key);
  }

  function backToDashboard() {
    state.view = 'dashboard';
    state.sectionView = null;
    render();
  }

  function loadSectionData(key) {
    var seq = ++state.sectionSeq;
    state.sectionLoading = true;
    render();
    api('api/sections.php?action=' + key + '&cw_company_id=' + state.customer.cw_company_id).then(function (r) {
      if (seq !== state.sectionSeq) return;
      state.sectionLoading = false;
      if (r.data && r.data.ok) {
        state.sectionData = r.data;
        state.error = null;
      } else {
        state.error = (r.data && r.data.error) || 'Could not load this section.';
      }
      render();
    }).catch(function () {
      if (seq !== state.sectionSeq) return;
      state.sectionLoading = false;
      state.error = 'Could not reach the server.';
      render();
    });
  }

  function toggleLineDetail(invoiceId) {
    state.expandedLineId = state.expandedLineId === invoiceId ? null : invoiceId;
    render();
  }

  function pushOpportunity(oppId) {
    if (state.pushingOppId) return;
    state.pushingOppId = oppId;
    state.error = null;
    render();
    api('api/opportunity-todo.php?action=create', { opportunity_id: oppId }).then(function (r) {
      state.pushingOppId = null;
      if (r.data && r.data.ok) {
        // Re-pull this section's data so the row flips to "actioned" with
        // the real relationships_todo_id rather than hand-patching state.
        loadSectionData('opportunities');
      } else {
        state.error = (r.data && r.data.error) || 'Could not push this to Relationships.';
        render();
      }
    }).catch(function () {
      state.pushingOppId = null;
      state.error = 'Could not reach the server.';
      render();
    });
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
    html += gaugesHtml();
    return html;
  }

  function sectionCardHtml(section) {
    var syncedAt = state.sync ? state.sync[SECTION_SYNC_KEY[section.key]] : null;
    var statusText;
    if (syncedAt) {
      statusText = 'Synced ' + fmtDate(syncedAt) + ' — click to view';
    } else if (state.syncing) {
      statusText = 'Syncing…';
    } else if (state.sync && state.sync.last_error) {
      statusText = 'Sync error — see banner above';
    } else {
      statusText = 'Not yet synced';
    }
    var clickable = !!syncedAt;
    var tag = clickable ? 'button' : 'div';
    var attrs = clickable ? ' type="button" data-action="open-section" data-key="' + section.key + '"' : '';
    return '<' + tag + ' class="cf-section-card' + (section.cls ? ' ' + section.cls : '') + (clickable ? ' clickable' : '') + '"' + attrs + '>' +
      '<div class="cf-section-title"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + section.icon + '</svg>' + esc(section.title) + '</div>' +
      '<div class="cf-section-desc">' + esc(section.desc) + '</div>' +
      '<div class="cf-section-status">' + statusText + '</div>' +
    '</' + tag + '>';
  }

  function dashboardViewHtml() {
    var c = state.customer;
    var html = '<div>';
    html += '<a href="#" class="cf-back" data-action="back-to-search">&larr; Back to search</a>';
    html += '<div class="cf-cust-head"><div><h2 class="view-title">' + esc(c.name) + '</h2>';
    if (c.cw_company_id) html += '<div class="badges">' + badgesHtml(c) + '</div>';
    html += '</div>';
    if (!state.dashLoading) {
      html += '<button type="button" class="btn secondary small" data-action="sync-now"' + (state.syncing ? ' disabled' : '') + '>' +
        (state.syncing ? 'Syncing…' : 'Sync now') + '</button>';
    }
    html += '</div>';

    if (state.dashLoading && !state.sync) {
      html += '<div class="loading">Loading…</div>';
    } else {
      html += '<div class="cf-sections">' + SECTIONS.map(sectionCardHtml).join('') + '</div>';
    }
    html += '</div>';
    return html;
  }

  // Reused bar chart -- same markup/classes as relationships/app.js's
  // Monthly Billing panel, just driven by whatever series+keys a section
  // passes in instead of being hardwired to month/total.
  function barChartHtml(series, labelKey, valueKey) {
    if (!series || series.length === 0) return '<div class="hint">No data in this window yet.</div>';
    var maxTotal = Math.max.apply(null, series.map(function (m) { return Number(m[valueKey]) || 0; }).concat([1]));
    return '<div class="billing-chart">' +
      series.map(function (m) {
        var val = Number(m[valueKey]) || 0;
        var pct = maxTotal > 0 ? Math.max(4, Math.round((val / maxTotal) * 100)) : 4;
        return '<div class="billing-bar-col" title="' + esc(m[labelKey]) + ': ' + fmtCurrency(val) + '">' +
          '<div class="billing-bar-value">' + fmtCurrency(val) + '</div>' +
          '<div class="billing-bar-track"><div class="billing-bar-fill" style="height:' + pct + '%"></div></div>' +
          '<div class="billing-bar-label">' + esc(m[labelKey]) + '</div>' +
        '</div>';
      }).join('') +
    '</div>';
  }

  function lineDetailRowsHtml(lines) {
    if (!lines || lines.length === 0) {
      return '<tr><td colspan="6" class="hint">No catalog line items on this invoice.</td></tr>';
    }
    return lines.map(function (l) {
      var margin = Number(l.price || 0) - Number(l.cost || 0);
      var tie = l.sales_order_identifier ? ('SO ' + esc(l.sales_order_identifier)) : '';
      if (l.opportunity_name) tie += (tie ? ' · ' : '') + esc(l.opportunity_name);
      return '<tr>' +
        '<td>' + esc(l.product_identifier) + '<div class="hint">' + esc(l.description) + '</div></td>' +
        '<td class="r">' + Number(l.qty || 0) + '</td>' +
        '<td class="r">' + fmtCurrency(l.price) + '</td>' +
        '<td class="r">' + fmtCurrency(l.cost) + '</td>' +
        '<td class="r ' + (margin < 0 ? 'neg' : 'pos') + '">' + fmtCurrency(margin) + '</td>' +
        '<td>' + (tie || '<span class="hint">—</span>') + '</td>' +
      '</tr>';
    }).join('');
  }

  // Shared table for Product Sales + Credits -- both are a list of
  // invoices, each expandable to its own product-line detail.
  function invoiceDrilldownTableHtml(invoices) {
    if (!invoices || invoices.length === 0) {
      return '<div class="hint">No invoices in this window yet.</div>';
    }
    var rows = invoices.map(function (inv) {
      var expanded = state.expandedLineId === inv.id;
      var out = '<tr class="cf-invoice-row" data-action="toggle-line-detail" data-id="' + inv.id + '">' +
        '<td>' + esc(inv.invoice_number) + '</td>' +
        '<td>' + fmtDate(inv.invoice_date) + '</td>' +
        '<td class="r">' + fmtCurrency(inv.total) + '</td>' +
        '<td class="r hint">' + (inv.lines && inv.lines.length ? (expanded ? '▲ hide lines' : '▼ ' + inv.lines.length + ' line(s)') : '—') + '</td>' +
      '</tr>';
      if (expanded) {
        out += '<tr><td colspan="4" style="padding:0">' +
          '<table class="data" style="margin:4px 0 10px">' +
            '<thead><tr><th>Product</th><th class="r">Qty</th><th class="r">Price</th><th class="r">Cost</th><th class="r">Margin</th><th>Sold via</th></tr></thead>' +
            '<tbody>' + lineDetailRowsHtml(inv.lines) + '</tbody>' +
          '</table>' +
        '</td></tr>';
      }
      return out;
    }).join('');
    return '<table class="data"><thead><tr><th>Invoice #</th><th>Date</th><th class="r">Total</th><th class="r">Lines</th></tr></thead><tbody>' + rows + '</tbody></table>';
  }

  function standardSectionHtml(d) {
    var months = d.months.map(function (m) { return { label: fmtMonthLabel(m.month), total: m.total }; });
    var html = '<div class="card"><h3>6-Month Trend</h3>' + barChartHtml(months, 'label', 'total') + '</div>';
    html += '<div class="card"><h3>Invoices</h3>';
    if (d.invoices.length === 0) {
      html += '<div class="hint">No Standard/T&amp;M invoices in this window yet.</div>';
    } else {
      html += '<table class="data"><thead><tr><th>Invoice #</th><th>Date</th><th class="r">Total</th></tr></thead><tbody>' +
        d.invoices.map(function (inv) {
          return '<tr><td>' + esc(inv.invoice_number) + '</td><td>' + fmtDate(inv.invoice_date) + '</td><td class="r">' + fmtCurrency(inv.total) + '</td></tr>';
        }).join('') + '</tbody></table>';
    }
    html += '</div>';
    return html;
  }

  function productSalesSectionHtml(d) {
    var years = d.years.map(function (y) { return { label: y.year, total: y.total }; });
    var html = '<div class="card"><h3>Year-over-Year</h3>' + barChartHtml(years, 'label', 'total') + '</div>';
    html += '<div class="card"><h3>Sales Orders — click a row for line detail</h3>' + invoiceDrilldownTableHtml(d.invoices) + '</div>';
    return html;
  }

  function agreementCardHtml(ag) {
    var months = ag.months.map(function (m) { return { label: fmtMonthLabel(m.month), total: m.total }; });
    var statusChipCls = ag.status === 'Cancelled' ? 'neg' : 'pos';
    var additions = ag.additions || [];
    var html = '<div class="card">';
    html += '<h3>' + esc(ag.name) + ' <span class="chip ' + statusChipCls + '" style="background:transparent;border:1px solid currentColor">' + esc(ag.status || '') + '</span></h3>';
    html += '<div class="hint">' + esc(ag.type_name || '') + ' · Started ' + (ag.start_date ? fmtDate(ag.start_date) : '—') +
      (ag.cancelled_date ? (' · Cancelled ' + fmtDate(ag.cancelled_date)) : '') + '</div>';
    html += barChartHtml(months, 'label', 'total');
    if (additions.length === 0) {
      html += '<div class="hint" style="margin-top:10px">No addition-level detail synced for this agreement.</div>';
    } else {
      html += '<table class="data" style="margin-top:10px"><thead><tr><th>Product</th><th class="r">Qty</th><th class="r">Unit Price</th><th class="r">Unit Cost</th><th>Start</th><th>Cancelled</th></tr></thead><tbody>' +
        additions.map(function (a) {
          return '<tr' + (a.cancelled_date ? ' style="opacity:.55"' : '') + '>' +
            '<td>' + esc(a.product_identifier) + '<div class="hint">' + esc(a.description) + '</div></td>' +
            '<td class="r">' + Number(a.qty || 0) + '</td>' +
            '<td class="r">' + fmtCurrency(a.unit_price) + '</td>' +
            '<td class="r">' + fmtCurrency(a.unit_cost) + '</td>' +
            '<td>' + (a.start_date ? fmtDate(a.start_date) : '—') + '</td>' +
            '<td>' + (a.cancelled_date ? fmtDate(a.cancelled_date) : '—') + '</td>' +
          '</tr>';
        }).join('') + '</tbody></table>';
    }
    html += '</div>';
    return html;
  }

  function agreementsSectionHtml(d) {
    if (!d.agreements || d.agreements.length === 0) {
      return '<div class="card"><div class="hint">No agreements found for this customer.</div></div>';
    }
    return d.agreements.map(agreementCardHtml).join('');
  }

  function creditsSectionHtml(d) {
    var months = d.months.map(function (m) { return { label: fmtMonthLabel(m.month), total: m.total }; });
    var html = '<div class="card"><h3>6-Month Trend</h3>' + barChartHtml(months, 'label', 'total') + '</div>';
    html += '<div class="card"><h3>Credit Memos — click a row for line detail</h3>' + invoiceDrilldownTableHtml(d.invoices) + '</div>';
    return html;
  }

  function opportunityRowHtml(o) {
    var confBadge = o.match_confidence === 'keyword'
      ? '<span class="cf-badge" title="Flagged by description keyword, not the clean catalog subcategory -- worth a quick sanity check.">keyword match</span>'
      : '';
    var actionCell;
    if (o.status === 'actioned') {
      actionCell = '<span class="hint">Sent to Relationships' + (o.relationships_todo_id ? (' (#' + o.relationships_todo_id + ')') : '') + '</span>';
    } else if (o.status === 'dismissed') {
      actionCell = '<span class="hint">Dismissed</span>';
    } else {
      var busy = state.pushingOppId === o.id;
      actionCell = '<button type="button" class="btn small" data-action="push-opportunity" data-id="' + o.id + '"' + (busy ? ' disabled' : '') + '>' +
        (busy ? 'Sending…' : 'Push to Relationships') + '</button>';
    }
    return '<tr>' +
      '<td>' + esc(o.category === 'computer' ? 'Computer' : 'Networking') + '<div class="hint">' + esc(o.subcategory) + '</div></td>' +
      '<td>' + esc(o.product_identifier) + '<div class="hint">' + esc(o.description) + '</div></td>' +
      '<td>' + esc(o.invoice_number) + '</td>' +
      '<td>' + fmtDate(o.sold_date) + '</td>' +
      '<td class="r">' + Number(o.years_ago).toFixed(1) + ' yrs</td>' +
      '<td>' + confBadge + '</td>' +
      '<td>' + actionCell + '</td>' +
    '</tr>';
  }

  function opportunitiesSectionHtml(d) {
    var html = '<div class="card">';
    html += '<h3>Potential Opportunities: ' + d.open_count + '</h3>';
    html += '<div class="hint">Computer or networking hardware sold 3+ years ago -- a natural opening to discuss an upgrade.</div>';
    if (!d.opportunities || d.opportunities.length === 0) {
      html += '<div class="hint" style="margin-top:10px">No qualifying hardware sales found.</div>';
    } else {
      html += '<table class="data" style="margin-top:10px"><thead><tr><th>Type</th><th>Item</th><th>Invoice #</th><th>Sold</th><th class="r">Age</th><th></th><th></th></tr></thead><tbody>' +
        d.opportunities.map(opportunityRowHtml).join('') + '</tbody></table>';
    }
    html += '</div>';
    return html;
  }

  var SECTION_RENDERERS = {
    standard: standardSectionHtml,
    product_sales: productSalesSectionHtml,
    agreements: agreementsSectionHtml,
    credits: creditsSectionHtml,
    opportunities: opportunitiesSectionHtml
  };

  function sectionViewHtml() {
    var meta = SECTIONS.filter(function (s) { return s.key === state.sectionView; })[0];
    var html = '<a href="#" class="cf-back" data-action="back-to-dashboard">&larr; Back to ' + esc(state.customer.name) + '</a>';
    html += '<h2 class="view-title">' + esc(meta ? meta.title : '') + '</h2>';
    if (state.sectionLoading && !state.sectionData) {
      html += '<div class="loading">Loading…</div>';
    } else if (state.sectionData) {
      var renderer = SECTION_RENDERERS[state.sectionView];
      html += renderer ? renderer(state.sectionData) : '<div class="hint">Nothing to show.</div>';
    }
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
    if (state.view === 'section' && state.customer) {
      html += sectionViewHtml();
    } else if (state.view === 'dashboard' && state.customer) {
      html += dashboardViewHtml();
    } else {
      html += searchViewHtml();
    }
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
    } else if (action === 'sync-now') {
      e.preventDefault();
      if (!state.syncing) startSync();
    } else if (action === 'open-section') {
      e.preventDefault();
      openSection(el.getAttribute('data-key'));
    } else if (action === 'back-to-dashboard') {
      e.preventDefault();
      backToDashboard();
    } else if (action === 'toggle-line-detail') {
      toggleLineDetail(Number(el.getAttribute('data-id')));
    } else if (action === 'push-opportunity') {
      e.preventDefault();
      pushOpportunity(Number(el.getAttribute('data-id')));
    }
  });

  boot();
})();
