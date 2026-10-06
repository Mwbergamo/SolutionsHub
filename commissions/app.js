/* commissions/app.js — Commissions sub-app (added 2026-10-05).
   Vanilla JS, renders straight into #app-root (same approach as relationships/app.js).
   Only Kasie Van Fossen, Michael Bergamo, Trey Hayden and Jaclyn Kelley can use it --
   enforced by every commissions/api/*.php endpoint, not just by hiding the card. */
(function () {
  'use strict';

  var root = document.getElementById('app-root');

  var state = {
    user: null,
    denied: false,
    view: 'dashboard',          // dashboard | history | trends | settings
    error: null,
    dash: null, dashLoading: false,
    sync: { running: false, text: '', done: 0, total: 0 },
    report: null,               // { loading, data, error, lossOnly }
    months: null, monthsLoading: false,
    histYear: null, histMonth: null,
    trendsRep: null, trends: null, trendsLoading: false,
    settings: null, settingsLoading: false, settingsMsg: null,
    terr: null, terrPeriod: 'all', terrLoading: false,
    ar: null, arPop: null,      // open invoices to collect (summary + pop-over)
    mgr: null                   // Michael's private sales-manager commission (only fetched for him)
  };

  // ---- helpers ------------------------------------------------------------

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function money(n, opts) {
    n = Number(n || 0);
    var neg = n < 0;
    var s = '$' + Math.abs(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    if (opts && opts.whole) s = '$' + Math.abs(Math.round(n)).toLocaleString('en-US');
    return neg ? '-' + s : s;
  }
  function moneyCell(n) { return '<span class="' + (n < 0 ? 'neg' : '') + '">' + money(n) + '</span>'; }
  function pctText(p) { return (Math.round(Number(p || 0) * 100) / 100) + '%'; }
  function fmtDate(d) {
    if (!d) return '';
    var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(d);
    return m ? m[2] + '/' + m[3] + '/' + m[1] : d;
  }
  function fmtStamp(iso) {
    if (!iso) return 'never';
    var d = new Date(iso);
    return isNaN(d.getTime()) ? iso : d.toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
  }
  function monthLabel(ym) {
    var p = String(ym).split('-');
    var d = new Date(Number(p[0]), Number(p[1]) - 1, 1);
    return d.toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
  }
  function shortMonth(ym) {
    var p = String(ym).split('-');
    return new Date(Number(p[0]), Number(p[1]) - 1, 1).toLocaleDateString(undefined, { month: 'short' }) + " '" + String(p[0]).slice(2);
  }
  function deltaHtml(n, isMoney) {
    if (n == null) return '<span class="hint">—</span>';
    var cls = n > 0 ? 'pos' : (n < 0 ? 'neg' : '');
    var arrow = n > 0 ? '▲' : (n < 0 ? '▼' : '•');
    return '<span class="' + cls + '">' + arrow + ' ' + (isMoney === false ? Math.abs(n) : money(Math.abs(n))) + '</span>';
  }
  function pctChange(now, prior) {
    if (!prior) return now ? 'new' : '—';
    var p = (now - prior) / Math.abs(prior) * 100;
    return (p > 0 ? '+' : '') + p.toFixed(0) + '%';
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

  // ---- boot ---------------------------------------------------------------

  function boot() {
    api('api/auth.php?action=me').then(function (r) {
      if (r.status === 401) { window.location.href = 'login.html'; return; }
      if (r.status === 403) { state.denied = true; render(); return; }
      if (!r.data || !r.data.ok) { state.error = (r.data && r.data.error) || 'Could not load.'; render(); return; }
      state.user = r.data.user;
      render();
      loadDashboard(true);
    }).catch(function () { state.error = 'Could not reach the server.'; render(); });
  }

  function loadDashboard(autoSync) {
    state.dashLoading = true;
    render();
    api('api/dashboard.php').then(function (r) {
      state.dashLoading = false;
      if (r.data && r.data.ok) {
        state.dash = r.data;
        state.error = null;
        loadManager();
        loadAr();
        if (autoSync && !state.sync.running && r.data.total_invoices > 0) {
          var fin = r.data.sync && r.data.sync.finished_at ? new Date(r.data.sync.finished_at).getTime() : 0;
          if (Date.now() - fin > 30 * 60 * 1000) runSync(1, false, true);
        }
      } else {
        state.error = (r.data && r.data.error) || 'Could not load the dashboard.';
      }
      render();
    }).catch(function () { state.dashLoading = false; state.error = 'Could not load the dashboard.'; render(); });
  }

  function loadManager() {
    if (!state.user || !state.user.is_manager) return;
    api('api/manager.php?action=summary').then(function (r) {
      if (r.data && r.data.ok) { state.mgr = r.data; render(); }
    }).catch(function () {});
  }

  // ---- sync (start, then step until the queue is empty) -------------------

  function runSync(monthsBack, force, quiet) {
    if (state.sync.running) return;
    state.sync = { running: true, text: 'Listing ConnectWise invoices…', done: 0, total: 0 };
    render();
    api('api/sync.php?action=start', { months_back: monthsBack, force: !!force }).then(function (r) {
      if (!r.data || !r.data.ok) { return syncFailed(r.data && r.data.error); }
      state.sync.total = r.data.queued;
      state.sync.text = r.data.queued + ' invoice' + (r.data.queued === 1 ? '' : 's') + ' to process…';
      render();
      stepLoop();
    }).catch(function () { syncFailed('Could not reach the server.'); });
  }
  function stepLoop() {
    api('api/sync.php?action=step', { batch_size: 10 }).then(function (r) {
      if (!r.data || !r.data.ok) { return syncFailed(r.data && r.data.error); }
      var st = r.data.status || {};
      state.sync.done = (st.done || 0) + (st.error || 0);
      state.sync.total = st.total || state.sync.total;
      state.sync.text = (st.pending === 0 && state.sync.total) ? 'Looking up agreement and ticket details for open invoices…' : 'Processed ' + state.sync.done + ' of ' + state.sync.total + ' invoices…';
      if (r.data.done) {
        state.sync = { running: false, text: '', done: 0, total: 0 };
        loadDashboard(false);
        if (state.view === 'history') loadMonths();
        if (state.view === 'trends') loadTrends();
        if (state.view === 'settings') loadSettings();
        return;
      }
      render();
      stepLoop();
    }).catch(function () { syncFailed('Lost the connection during the sync — click Sync again to resume.'); });
  }
  function syncFailed(msg) {
    state.sync = { running: false, text: '', done: 0, total: 0 };
    state.error = 'Sync stopped: ' + (msg || 'unknown error');
    render();
  }

  // ---- views --------------------------------------------------------------

  function topbarHtml() {
    var tab = function (v, label) {
      return '<button class="nav-btn' + (state.view === v ? ' active' : '') + '" type="button" data-action="view" data-view="' + v + '">' + label + '</button>';
    };
    return '<div class="topbar no-print"><div class="topbar-left"><div><div class="brand">Commissions</div><div class="brand-sub">CodeBlue Technology</div></div></div>' +
      '<div class="topbar-nav">' + tab('dashboard', 'Dashboard') + tab('history', 'History') + tab('trends', 'Rep Trends') + tab('territories', 'By Territory') + tab('settings', 'Settings') + '</div>' +
      '<div class="topbar-right"><a class="back-to-hub" href="../index.html">← SolutionsHub</a></div></div>';
  }

  function syncBarHtml() {
    var s = state.sync;
    if (!s.running) return '';
    var pct = s.total ? Math.min(100, Math.round(s.done / s.total * 100)) : 5;
    return '<div class="banner info no-print"><b>Syncing with ConnectWise.</b> ' + esc(s.text) + '<div class="progress"><div style="width:' + pct + '%"></div></div></div>';
  }

  function dashboardHtml() {
    var d = state.dash;
    if (!d) return state.dashLoading ? '<div class="loading">Loading…</div>' : '';
    var h = '<h2 class="view-title">Commissions</h2>' +
      '<div class="view-sub">Commission dollars by sales rep, from ConnectWise invoices (the invoiced company’s ConnectWise territory decides who is paid; house territories pay nothing). Click any number for the invoice-level report behind it.</div>';

    if (d.total_invoices === 0) {
      h += '<div class="banner info no-print"><b>No invoices synced yet.</b> Run the first sync to pull invoices from ConnectWise. For the history and trend reports, backfill 12–24 months once: ' +
        '<button class="btn small" type="button" data-action="sync" data-months="1">Sync now</button> ' +
        '<button class="btn small secondary" type="button" data-action="sync" data-months="12">Backfill 12 months</button> ' +
        '<button class="btn small secondary" type="button" data-action="sync" data-months="24">Backfill 24 months</button></div>';
    }
    var missing = d.reps.filter(function (r) { return r.pct_missing; }).map(function (r) { return r.name; });
    if (missing.length) {
      h += '<div class="banner warn no-print"><b>No commission % set for ' + esc(missing.join(', ')) + '.</b> Their numbers show $0 until you enter a rate in <a href="#" data-action="view" data-view="settings">Settings</a>.</div>';
    }
    if (d.needs_review_invoices > 0) {
      h += '<div class="banner warn no-print"><b>' + d.needs_review_invoices + ' invoice' + (d.needs_review_invoices === 1 ? '' : 's') + ' need review</b> — ConnectWise didn’t return line detail that adds up to the invoice (shown with ⚠ in the reports). Nothing is paid on missing detail.</div>';
    }
    if (d.house_invoices > 0) {
      h += '<div class="hint no-print" style="margin:0 0 10px">' + d.house_invoices + ' open/recent invoice' + (d.house_invoices === 1 ? ' is' : 's are') + ' on house accounts (no commission): ' +
        '<a href="#" data-action="open-report" data-rep="house" data-bucket="pending">Pending</a> · ' +
        '<a href="#" data-action="open-report" data-rep="house" data-bucket="current">Current</a> · ' +
        '<a href="#" data-action="open-report" data-rep="house" data-bucket="last">Last</a></div>';
    }

    var cats = [
      { key: 'pending', label: 'Pending commissions', sub: 'Created in ConnectWise, not yet Closed' },
      { key: 'current', label: 'Current month', sub: d.periods.current.label + ' — Closed' },
      { key: 'last', label: 'Last month', sub: d.periods.last.label + ' — Closed' }
    ];
    h += '<table class="comm-grid"><thead><tr><th></th>';
    d.reps.forEach(function (r) {
      var rate = r.pct_missing ? '<small class="missing">rate not set</small>' :
        '<small>' + pctText(r.base_pct) + (r.agreement_after_year_pct != null ? ' · ' + pctText(r.agreement_after_year_pct) + ' on agreements 365+ days' : '') + '</small>';
      h += '<th class="rep-head">' + esc(r.name) + rate +
        '<a href="#" class="full-link no-print" data-action="open-report" data-rep="' + r.id + '" data-bucket="all">One report ›</a></th>';
    });
    h += '<th class="rep-head">Total</th></tr></thead><tbody>';
    cats.forEach(function (c) {
      h += '<tr><td class="cat-label">' + c.label + '<small>' + esc(c.sub) + '</small></td>';
      var total = 0, tInv = 0, tLoss = 0;
      d.reps.forEach(function (r) {
        var cell = r[c.key];
        total += cell.commission; tInv += cell.invoices; tLoss += cell.loss_lines;
        h += '<td>' + cellHtml(cell, c.key, 'data-action="open-report" data-rep="' + r.id + '" data-bucket="' + c.key + '"') + '</td>';
      });
      h += '<td>' + cellHtml({ commission: total, invoices: tInv, loss_lines: tLoss, needs_review: 0 }, 'total', 'data-action="open-report" data-rep="all" data-bucket="' + c.key + '"') + '</td></tr>';
    });
    h += '</tbody></table>';
    h += '<div class="synced-note no-print">Last synced ' + esc(fmtStamp(d.sync && d.sync.finished_at)) +
      ' &nbsp; <button class="btn small secondary" type="button" data-action="sync" data-months="1"' + (state.sync.running ? ' disabled' : '') + '>Sync now</button>' +
      ' &nbsp; Labor assumed at ' + money(d.labor_cost_per_hour) + '/hr of actual time · a losing line is subtracted from the rep’s commission.</div>';
    h += arCardHtml();
    h += managerCardHtml();
    return h;
  }

  // Private to Michael: 1.25% of gross profit on every invoice in the period.
  function managerCardHtml() {
    var m = state.mgr;
    if (!m || !state.user || !state.user.is_manager) return '';
    var cats = [
      { key: 'pending', label: 'Pending', sub: 'Not yet Closed' },
      { key: 'current', label: 'Current month', sub: m.periods.current },
      { key: 'last', label: 'Last month', sub: m.periods.last }
    ];
    var h = '<div class="card no-print manager-card"><h3>Sales manager commission — private to you</h3>' +
      '<div class="view-sub" style="margin-bottom:10px">' + pctText(m.rate) + ' of gross profit on every invoice in the period (all territories, house accounts included; losing lines reduce it).</div><div class="manager-row">';
    cats.forEach(function (c) {
      var v = m[c.key];
      h += '<button class="comm-cell' + (c.key === 'pending' ? ' pending' : '') + '" type="button" data-action="open-report" data-rep="manager" data-bucket="' + c.key + '">' +
        '<span class="cat">' + c.label + ' <small>' + esc(c.sub) + '</small></span>' +
        '<span class="num">' + money(v.commission, { whole: false }) + '</span>' +
        '<span class="sub">GP ' + money(v.gp) + ' · ' + v.invoices + ' invoice' + (v.invoices === 1 ? '' : 's') +
        (v.loss_lines ? ' · <span class="loss">' + v.loss_lines + ' loss' + (v.loss_lines === 1 ? '' : 'es') + '</span>' : '') +
        (v.needs_review ? ' · <span class="review">⚠ ' + v.needs_review + '</span>' : '') + '</span></button>';
    });
    h += '</div><div style="margin-top:10px"><button class="btn small secondary" type="button" data-action="open-report" data-rep="manager" data-bucket="all">One report — all three</button></div></div>';
    return h;
  }

  function cellHtml(cell, key, attrs) {
    var cls = key === 'pending' ? ' pending' : (key === 'total' ? ' total' : '');
    var sub = cell.invoices + ' invoice' + (cell.invoices === 1 ? '' : 's');
    if (cell.loss_lines) sub += ' · <span class="loss">' + cell.loss_lines + ' loss' + (cell.loss_lines === 1 ? '' : 'es') + '</span>';
    if (cell.needs_review) sub += ' · <span class="review">⚠ ' + cell.needs_review + '</span>';
    return '<button class="comm-cell' + cls + '" type="button" ' + attrs + '><span class="num">' + money(cell.commission, { whole: false }) + '</span><span class="sub">' + sub + '</span></button>';
  }

  // ---- open invoices to collect -------------------------------------------

  function loadAr() {
    api('api/ar.php?action=summary').then(function (r) {
      if (r.data && r.data.ok) { state.ar = r.data; render(); }
    }).catch(function () {});
  }

  function agingCells(t) {
    return '<td class="r">' + (t.b0 ? money(t.b0) : '<span class="hint">—</span>') + '</td>' +
      '<td class="r">' + (t.b30 ? money(t.b30) : '<span class="hint">—</span>') + '</td>' +
      '<td class="r' + (t.b60 ? ' neg' : '') + '">' + (t.b60 ? money(t.b60) : '<span class="hint">—</span>') + '</td>';
  }
  function arAmountBtn(t, rep, territory) {
    if (!t.count) return '<span class="hint">$0.00</span>';
    return '<button class="ar-amt" type="button" data-action="open-ar" data-rep="' + esc(rep) + '" data-territory="' + esc(territory || '') + '" title="Show the open invoices">' + money(t.balance) + '</button>';
  }

  function arCardHtml() {
    var a = state.ar;
    if (!a) return '';
    var head = '<tr><th>__W__</th><th class="r">Open invoices</th><th class="r">Outstanding</th><th class="r">0–30 days</th><th class="r">31–60 days</th><th class="r">Over 60 days</th></tr>';
    var h = '<div class="card ar-card no-print"><h3>Open invoices to collect <small>as of ' + esc(a.as_of_label) + '</small></h3>' +
      '<div class="view-sub" style="margin-bottom:10px">Closed ConnectWise invoices not yet paid and no more than ' + a.bad_debt_days + ' days old (older is bad debt), with days counted from the invoice date. Click an amount to see the invoices and send a collections report.' +
      (a.detail_pending ? ' <i>(' + a.detail_pending + ' invoice' + (a.detail_pending === 1 ? '' : 's') + ' still waiting for agreement/ticket detail — run Sync now.)</i>' : '') + '</div>' +
      '<table class="data ar-table"><thead>' + head.replace('__W__', 'Rep') + '</thead><tbody>';
    a.reps.forEach(function (r) {
      h += '<tr><td><b>' + esc(r.name) + '</b></td><td class="r">' + r.count + '</td><td class="r">' + arAmountBtn(r, r.id) + '</td>' + agingCells(r) + '</tr>';
    });
    if (a.house.count) {
      h += '<tr><td><b>House accounts</b> <span class="hint">(no rep)</span></td><td class="r">' + a.house.count + '</td><td class="r">' + arAmountBtn(a.house, 'house') + '</td>' + agingCells(a.house) + '</tr>';
    }
    h += '<tr class="total"><td><b>All open invoices</b></td><td class="r">' + a.total.count + '</td><td class="r">' + arAmountBtn(a.total, 'all') + '</td>' + agingCells(a.total) + '</tr></tbody></table>';
    h += '<div class="hint" style="margin:6px 0 14px">A shared territory (Arcus + Chester) shows its invoices under both reps; the All row counts each invoice once.</div>';
    h += '<table class="data ar-table"><thead>' + head.replace('__W__', 'Territory') + '</thead><tbody>';
    if (!a.territories.length) h += '<tr><td colspan="6" class="hint">Nothing unpaid. (Refreshes on every sync.)</td></tr>';
    a.territories.forEach(function (t) {
      h += '<tr><td>' + esc(t.label) + ' <span class="hint">' + (t.house ? 'house account' : '→ ' + esc(t.payees.join(' + '))) + '</span></td><td class="r">' + t.count + '</td><td class="r">' +
        arAmountBtn(t, 'all', t.territory || '(none)') + '</td>' + agingCells(t) + '</tr>';
    });
    h += '</tbody></table><div class="hint" style="margin-top:6px">Only invoices 0–' + a.bad_debt_days + ' days old are tracked' + (a.bad_debt && a.bad_debt.count ? '; ' + a.bad_debt.count + ' older invoice' + (a.bad_debt.count === 1 ? '' : 's') + ' (' + money(a.bad_debt.balance) + ') are treated as bad debt and not shown' : '') + '. Open invoices refreshed ' + esc(fmtStamp(a.refreshed_at)) + '.</div></div>';
    return h;
  }

  function openAr(rep, territory) {
    var p = state.arPop = { loading: true, data: null, error: null, rep: rep, territory: territory || '', emailOpen: false, to: '', msg: null, msgOk: false, sending: false };
    render();
    var url = 'api/ar.php?action=detail&rep_id=' + encodeURIComponent(rep) + (territory ? '&territory=' + encodeURIComponent(territory) : '');
    api(url).then(function (r) {
      if (r.data && r.data.ok) { p.data = r.data; p.to = (r.data.rep && r.data.rep.email) || ''; } else p.error = (r.data && r.data.error) || 'Could not load the open invoices.';
      p.loading = false; render();
    }).catch(function () { p.loading = false; p.error = 'Could not load the open invoices.'; render(); });
  }

  function arDocHtml(d) {
    var t = d.totals;
    var h = '<div class="report-doc"><div class="print-only report-title">Collections report — ' + esc(d.title) + '</div>' +
      '<div class="report-meta">As of ' + esc(d.as_of_label) + ' · days counted from the invoice date · customers listed oldest balance first, invoices oldest to newest.</div>' +
      '<div class="report-summary"><div><span>Total outstanding</span><b>' + money(t.balance) + '</b></div><div><span>Open invoices</span><b>' + t.count + '</b></div>' +
      '<div><span>Over ' + d.hold_days + ' days</span><b>' + money(t.b60) + '</b></div><div><span>Oldest</span><b>' + t.oldest_days + ' days</b></div></div>';
    if (!d.customers.length) return h + '<div class="hint">No open invoices. 🎉</div></div>';
    d.customers.forEach(function (c) {
      var name = c.company_id > 0
        ? '<a class="cust-link" href="../relationships/index.html?cw_company=' + c.company_id + '" target="_blank" rel="noopener" title="Open in Relationships">' + esc(c.customer) + ' ↗</a>'
        : esc(c.customer);
      h += '<div class="report-section"><h4>' + name + ' <span class="hint">— ' + money(c.subtotal) + ' outstanding' + (c.territory ? ' · ' + esc(c.territory) : '') + '</span></h4>' +
        '<table class="report"><thead><tr><th>Invoice #</th><th>Customer</th><th>Agreement</th><th>Ticket</th><th>Invoice date</th><th class="r">Days</th><th class="r">Open balance</th></tr></thead><tbody>';
      c.invoices.forEach(function (i) {
        var over = i.days > d.hold_days;
        h += '<tr' + (over ? ' class="loss"' : '') + '><td>' + esc(i.invoice_number) + '</td><td>' + esc(i.customer) + '</td><td>' + (i.agreement ? esc(i.agreement) : '<span class="hint">—</span>') + '</td>' +
          '<td>' + (i.tickets ? esc(i.tickets) : '<span class="hint">—</span>') + '</td><td>' + esc(fmtDate(i.invoice_date)) + '</td><td class="r">' + i.days + '</td><td class="r">' + money(i.balance) + '</td></tr>';
        if (over) h += '<tr class="note"><td colspan="7">Invoice ' + esc(i.invoice_number) + ' — ' + esc(d.hold_note) + '</td></tr>';
      });
      h += '<tr class="total"><td colspan="6">Total — ' + esc(c.customer) + '</td><td class="r">' + money(c.subtotal) + '</td></tr></tbody></table></div>';
    });
    h += '<div class="report-section"><h4>Grand total outstanding: ' + money(t.balance) + '</h4></div></div>';
    return h;
  }

  function arModalHtml() {
    var p = state.arPop;
    if (!p) return '';
    var body;
    if (p.loading) body = '<div class="loading">Loading open invoices…</div>';
    else if (p.error) body = '<div class="banner error">' + esc(p.error) + '</div>';
    else body = arDocHtml(p.data);
    var email = '';
    if (p.emailOpen && p.data) {
      email = '<div class="ar-email no-print"><label class="field">Send collections report to<input type="email" data-change="ar-to" id="ar-to" placeholder="name@company.com" value="' + esc(p.to) + '"></label>' +
        '<button class="btn" type="button" data-action="ar-email-send"' + (p.sending ? ' disabled' : '') + '>' + (p.sending ? 'Sending…' : 'Send') + '</button>' +
        '<span class="hint">' + (p.data.rep && !p.data.rep.email ? 'No email saved for this rep — add one in Settings to prefill it. ' : '') + 'It is sent from the CodeBlue mailbox and replies come to you.</span></div>';
    }
    var msg = p.msg ? '<div class="banner ' + (p.msgOk ? 'info' : 'error') + ' no-print">' + esc(p.msg) + '</div>' : '';
    return '<div class="modal-backdrop" data-action="close-ar-bg"><div class="modal" role="dialog">' +
      '<div class="modal-head no-print"><h3>' + (p.data ? 'Open invoices — ' + esc(p.data.title) : 'Open invoices') + '</h3><div class="actions">' +
      (p.data && p.data.customers.length ? '<button class="btn" type="button" data-action="ar-email-toggle">Collections report…</button>' : '') +
      '<button class="btn secondary" type="button" data-action="print">Print / Save PDF</button>' +
      '<button class="btn secondary" type="button" data-action="close-ar">Close</button></div></div>' + email + msg + body + '</div></div>';
  }

  function sendArEmail() {
    var p = state.arPop;
    var inp = document.getElementById('ar-to');
    if (inp) p.to = inp.value.trim();
    if (!p.to) { p.msg = 'Enter an email address first.'; p.msgOk = false; render(); return; }
    p.sending = true; p.msg = null; render();
    api('api/ar.php?action=email', { rep_id: p.rep, territory: p.territory, to: p.to }).then(function (r) {
      p.sending = false;
      if (r.data && r.data.ok) { p.msg = 'Collections report sent to ' + r.data.sent_to + ' (' + r.data.invoices + ' invoice' + (r.data.invoices === 1 ? '' : 's') + ', ' + money(r.data.balance) + ').'; p.msgOk = true; p.emailOpen = false; }
      else { p.msg = (r.data && r.data.error) || 'Could not send the email.'; p.msgOk = false; }
      render();
    }).catch(function () { p.sending = false; p.msg = 'Could not reach the server.'; p.msgOk = false; render(); });
  }

  // ---- report (popover + printable) ---------------------------------------

  function openReport(repId, bucket, month) {
    state.report = { loading: true, data: null, error: null, lossOnly: false, cat: 'all' };
    render();
    var url = repId === 'manager'
      ? 'api/manager.php?action=lines&bucket=' + encodeURIComponent(bucket)
      : 'api/report.php?action=lines&rep_id=' + encodeURIComponent(repId) + (month ? '&month=' + month : '&bucket=' + bucket);
    api(url).then(function (r) {
      if (r.data && r.data.ok) state.report.data = r.data; else state.report.error = (r.data && r.data.error) || 'Could not load the report.';
      state.report.loading = false;
      render();
    }).catch(function () { state.report.loading = false; state.report.error = 'Could not load the report.'; render(); });
  }

  var CATS = [['hardware', 'Hardware / Software'], ['agreement', 'Agreements'], ['service', 'Service (time)'], ['other', 'Other / unresolved']];
  function catLabel(k) { for (var i = 0; i < CATS.length; i++) if (CATS[i][0] === k) return CATS[i][1]; return k; }
  function catOptions(rows, cur) {
    var present = {};
    rows.forEach(function (r) { present[r.category] = true; });
    return '<option value="all"' + (cur === 'all' ? ' selected' : '') + '>All three together</option>' +
      CATS.filter(function (c) { return present[c[0]] || c[0] === cur; }).map(function (c) { return '<option value="' + c[0] + '"' + (cur === c[0] ? ' selected' : '') + '>' + c[1] + ' only</option>'; }).join('');
  }
  function computeTotals(rows) {
    var t = { commission: 0, revenue: 0, cost: 0, gp: 0, loss_lines: 0, loss_amount: 0, invoices: 0, needs_review: 0 }, lines = {}, inv = {};
    rows.forEach(function (r) {
      t.commission += r.commission;
      if (!lines[r.line_id]) {
        lines[r.line_id] = 1; t.revenue += r.price; t.cost += r.cost; t.gp += r.gp;
        if (r.is_loss) { t.loss_lines++; t.loss_amount += r.gp; }
      }
      if (!inv[r.invoice_id]) { inv[r.invoice_id] = 1; t.invoices++; if (r.detail_state && r.detail_state !== 'ok') t.needs_review++; }
    });
    return t;
  }
  // Territory x category summary (margin and commission) for the rows in a report.
  function categorySummaryHtml(rows) {
    var terr = {}, order = [], present = {};
    rows.forEach(function (r) {
      var k = r.territory || '(no territory)';
      if (!terr[k]) { terr[k] = {}; order.push(k); }
      var c = terr[k][r.category] = terr[k][r.category] || { gp: 0, com: 0, seen: {} };
      if (!c.seen[r.line_id]) { c.seen[r.line_id] = 1; c.gp += r.gp; }
      c.com += r.commission;
      present[r.category] = true;
    });
    var cats = CATS.filter(function (c) { return present[c[0]]; });
    if (!order.length) return '';
    var h = '<div class="report-section"><h4>Summary by territory</h4><table class="report"><thead><tr><th>Territory</th>' +
      cats.map(function (c) { return '<th class="r">' + c[1] + '<br>margin / commission</th>'; }).join('') + '<th class="r">Total<br>margin / commission</th></tr></thead><tbody>';
    var tot = {};
    order.sort().forEach(function (k) {
      var gp = 0, com = 0;
      h += '<tr><td>' + esc(k) + '</td>';
      cats.forEach(function (c) {
        var v = terr[k][c[0]];
        h += '<td class="r">' + (v ? money(v.gp) + ' / <b>' + money(v.com) + '</b>' : '—') + '</td>';
        if (v) { gp += v.gp; com += v.com; tot[c[0]] = tot[c[0]] || { gp: 0, com: 0 }; tot[c[0]].gp += v.gp; tot[c[0]].com += v.com; }
      });
      h += '<td class="r">' + money(gp) + ' / <b>' + money(com) + '</b></td></tr>';
    });
    var tg = 0, tc = 0;
    h += '<tr class="total"><td>All territories</td>';
    cats.forEach(function (c) { var v = tot[c[0]] || { gp: 0, com: 0 }; tg += v.gp; tc += v.com; h += '<td class="r">' + money(v.gp) + ' / ' + money(v.com) + '</td>'; });
    return h + '<td class="r">' + money(tg) + ' / ' + money(tc) + '</td></tr></tbody></table></div>';
  }

  function reportDocHtml(data, lossOnly, cat) {
    cat = cat || 'all';
    var allRows = data.rows;
    var rows = cat === 'all' ? allRows : allRows.filter(function (r) { return r.category === cat; });
    var rep = data.rep || {};
    var rateLine = '';
    if (rep.kind === 'rep') {
      rateLine = 'Commission rate: ' + pctText(rep.base_pct) + (rep.agreement_after_year_pct != null ? ' (' + pctText(rep.agreement_after_year_pct) + ' on Agreement invoices once the agreement is 365 or more days old)' : '') + ' of gross profit.' + (rep.name === 'Arcus' ? ' On a shared Arcus + Chester Sienko territory, Arcus is paid his shared-territory % (set in Settings) of the gross profit remaining after Chester’s commission.' : '');
    } else if (rep.kind === 'all') {
      rateLine = 'Each rep is paid their own rate on each line (a split territory pays more than one rep).';
    } else if (rep.kind === 'manager') {
      rateLine = 'Sales manager commission: ' + pctText(rep.base_pct) + ' of gross profit on every invoice in the period, all territories (house accounts included).';
    } else if (rep.kind === 'house') {
      rateLine = 'House accounts do not pay commission; shown for reference.';
    }
    var t = cat === 'all' ? data.totals : computeTotals(rows);
    var h = '<div class="report-doc"><h1 class="report-title">CodeBlue Technology — Commission Report</h1>' +
      '<div class="report-meta"><b>' + esc(rep.name || '') + '</b> · ' + esc(data.title) + (cat !== 'all' ? ' · <b>' + catLabel(cat) + ' only</b>' : '') + '<br>' + esc(rateLine) +
      ' Labor cost assumed at ' + money(data.labor_cost_per_hour) + ' per hour worked (actual hours). Generated ' + esc(new Date(data.generated_at).toLocaleString()) + '.</div>' +
      '<div class="report-summary">' +
        '<div><span>Commission</span><b>' + money(t.commission) + '</b></div>' +
        '<div><span>Revenue (price)</span><b>' + money(t.revenue) + '</b></div>' +
        '<div><span>Assumed cost</span><b>' + money(t.cost) + '</b></div>' +
        '<div><span>Gross profit</span><b>' + money(t.gp) + '</b></div>' +
        '<div><span>Invoices</span><b>' + t.invoices + '</b></div>' +
        '<div><span>Lost money</span><b' + (t.loss_lines ? ' class="neg"' : '') + '>' + t.loss_lines + ' · ' + money(t.loss_amount) + '</b></div>' +
      '</div>';
    if (data.late_after_lock) {
      h += '<div class="report-meta"><b>Note:</b> ' + data.late_after_lock + ' invoice(s) in this month were closed after the month was locked and are included.</div>';
    }
    if (cat === 'all') h += categorySummaryHtml(allRows);
    var losses = rows.filter(function (r) { return r.is_loss; });
    if (losses.length) {
      h += '<div class="report-section"><h4>Transactions that lost money (cost higher than price)</h4>' + tableHtml(losses, false) + '</div>';
    }
    var shown = lossOnly ? losses : rows;
    if (rep.kind === 'all') {
      var groups = [], idx = {};
      shown.forEach(function (r) {
        if (idx[r.rep_name] == null) { idx[r.rep_name] = groups.length; groups.push({ name: r.rep_name, rows: [] }); }
        groups[idx[r.rep_name]].rows.push(r);
      });
      groups.forEach(function (g, gi) {
        var sum = g.rows.reduce(function (a, r) { return a + r.commission; }, 0);
        h += '<div class="report-section' + (gi > 0 ? ' rep-break' : '') + '"><h4>' + esc(g.name) + ' — commission ' + money(sum) + '</h4>' + tableHtml(g.rows, true) + '</div>';
      });
    } else {
      h += '<div class="report-section"><h4>' + (lossOnly ? 'Losing lines only' : (cat === 'all' ? 'All lines' : catLabel(cat))) + '</h4>' + tableHtml(shown, true) + '</div>';
    }
    return h + '</div>';
  }

  // Laid out like the existing ConnectWise "Service Commission" report: grouped
  // under each company territory, one row per line with the technician, ticket,
  // billable vs actual hours, billable amount, cost, margin and commission.
  function tableHtml(rows, withTotal) {
    if (!rows.length) return '<div class="hint">Nothing to show.</div>';
    var groups = [], idx = {};
    rows.forEach(function (r) {
      var k = r.territory || '(no territory)';
      if (idx[k] == null) { idx[k] = groups.length; groups.push({ name: k, rows: [] }); }
      groups[idx[k]].rows.push(r);
    });
    var h = '<table class="report"><colgroup><col style="width:9%"><col style="width:15%"><col style="width:24%"><col style="width:10%"><col style="width:9%"><col style="width:9%"><col style="width:9%"><col style="width:6%"><col style="width:9%"></colgroup>' +
      '<thead><tr><th>Invoice</th><th>Customer / Tech</th><th>Ticket / Item</th><th class="r">Hours<br>bill / act</th><th class="r">Billable amt</th><th class="r">Cost</th><th class="r">Margin</th><th class="r">Rate</th><th class="r">Commission</th></tr></thead>';
    var grand = { price: 0, cost: 0, gp: 0, com: 0 };
    groups.forEach(function (g) {
      var t = { price: 0, cost: 0, gp: 0, com: 0 }, lastInv = null;
      h += '<tbody><tr class="terr"><td colspan="9">' + esc(g.name) + ' <span class="hint">(' + g.rows.length + ' line' + (g.rows.length === 1 ? '' : 's') + ')</span></td></tr>';
      g.rows.forEach(function (r) {
        t.price += r.price; t.cost += r.cost; t.gp += r.gp; t.com += r.commission;
        var flag = r.detail_state && r.detail_state !== 'ok';
        var pending = r.is_closed === 0 || r.is_closed === '0';
        var tick = r.ticket_id ? '#' + r.ticket_id + (r.ticket_summary ? ' ' + esc(r.ticket_summary) : '') : (r.ticket_summary ? esc(r.ticket_summary) : '');
        var hrs = r.hours ? (r.hours + (r.actual_hours != null && r.actual_hours !== r.hours ? ' / ' + r.actual_hours : ' / ' + r.hours)) : '';
        h += '<tr class="' + (r.is_loss ? 'loss' : '') + '"><td>' + esc(r.invoice_number) + (flag ? ' ⚠' : '') + (pending ? ' <span class="hint">(pending)</span>' : '') + '<br><span class="hint" style="white-space:nowrap">' + esc(fmtDate(r.invoice_date)) + '</span></td>' +
          '<td>' + esc(r.company_name) + (r.member ? '<br><span class="hint">' + esc(r.member) + '</span>' : '') + '</td>' +
          '<td>' + (r.kind === 'time' ? '' : '<b>' + esc(r.item || '') + '</b>' + (r.qty ? ' <span class="hint">× ' + r.qty + '</span>' : '') + '<br>') + tick + (r.is_loss ? '<span class="loss-tag">LOSS</span>' : '') + '</td>' +
          '<td class="r">' + hrs + '</td>' +
          '<td class="r">' + money(r.price) + '</td><td class="r">' + money(r.cost) + '</td><td class="r">' + money(r.gp) + '</td>' +
          '<td class="r">' + pctText(r.pct) + (r.over_year ? '*' : '') + '</td>' +
          '<td class="r"><b>' + money(r.commission) + '</b></td></tr>';
        if (flag && lastInv !== r.invoice_id && r.detail_note) {
          h += '<tr class="note"><td colspan="9">⚠ Invoice ' + esc(r.invoice_number) + ': ' + esc(r.detail_note) + '</td></tr>';
        }
        lastInv = r.invoice_id;
      });
      h += '<tr class="subtotal"><td colspan="4" class="r">' + esc(g.name) + ' total</td><td class="r">' + money(t.price) + '</td><td class="r">' + money(t.cost) + '</td><td class="r">' + money(t.gp) + '</td><td></td><td class="r"><b>' + money(t.com) + '</b></td></tr></tbody>';
      grand.price += t.price; grand.cost += t.cost; grand.gp += t.gp; grand.com += t.com;
    });
    if (withTotal) h += '<tbody><tr class="total"><td colspan="4" class="r">Total</td><td class="r">' + money(grand.price) + '</td><td class="r">' + money(grand.cost) + '</td><td class="r">' + money(grand.gp) + '</td><td></td><td class="r">' + money(grand.com) + '</td></tr></tbody>';
    h += '</table>';
    if (rows.some(function (r) { return r.over_year; })) h += '<div class="hint">* Agreement is 365 or more days old at the invoice date — reduced rate applies.</div>';
    return h;
  }

  function reportModalHtml() {
    var r = state.report;
    if (!r) return '';
    var body;
    if (r.loading) body = '<div class="loading">Loading report…</div>';
    else if (r.error) body = '<div class="banner error">' + esc(r.error) + '</div>';
    else body = reportDocHtml(r.data, r.lossOnly, r.cat);
    return '<div class="modal-backdrop" data-action="close-report-bg"><div class="modal" role="dialog">' +
      '<div class="modal-head no-print"><h3>' + (r.data ? esc(r.data.rep.name + ' — ' + r.data.title) : 'Report') + '</h3><div class="actions">' +
      (r.data ? '<select data-change="report-cat" aria-label="Show">' + catOptions(r.data.rows, r.cat) + '</select>' : '') +
      '<label class="hint"><input type="checkbox" data-action="toggle-loss"' + (r.lossOnly ? ' checked' : '') + '> Losing lines only</label>' +
      '<button class="btn" type="button" data-action="print">Print / Save PDF</button>' +
      '<button class="btn secondary" type="button" data-action="close-report">Close</button></div></div>' + body + '</div></div>';
  }

  // ---- by territory: Hardware/Software vs Agreements vs Service -----------

  function loadMonthsQuiet() {
    api('api/report.php?action=months').then(function (r) { if (r.data && r.data.ok) { state.months = r.data; render(); } });
  }
  function loadTerritories() {
    state.terrLoading = true; render();
    var p = state.terrPeriod;
    var url = 'api/report.php?action=territories&' + (/^\d{4}-\d{2}$/.test(p) ? 'month=' + p : 'bucket=' + p);
    api(url).then(function (r) {
      state.terrLoading = false;
      if (r.data && r.data.ok) state.terr = r.data; else state.error = (r.data && r.data.error) || 'Could not load.';
      render();
    }).catch(function () { state.terrLoading = false; state.error = 'Could not load.'; render(); });
  }
  function territoriesHtml() {
    var h = '<h2 class="view-title">Commissions by Territory</h2><div class="view-sub">Hardware / Software, Agreements and Service (time) reported separately for each company territory — margin and what the payees earn on it. Includes house territories (no commission).</div>';
    var opts = [['all', 'Pending + Current + Last month'], ['pending', 'Pending (not yet Closed)'], ['current', 'Current month'], ['last', 'Last month']];
    ((state.months && state.months.months) || []).forEach(function (m) { opts.push([m.month, m.label + ' (closed)']); });
    h += '<div class="controls no-print"><label class="field">Period<select data-change="terr-period">' + opts.map(function (o) { return '<option value="' + o[0] + '"' + (state.terrPeriod === o[0] ? ' selected' : '') + '>' + esc(o[1]) + '</option>'; }).join('') + '</select></label>' +
      '<button class="btn" type="button" data-action="print">Print / Save PDF</button></div>';
    var t = state.terr;
    if (!t) return h + '<div class="loading">Loading…</div>';
    h += '<div class="print-only"><h1 class="report-title">CodeBlue Technology — Commissions by Territory</h1></div><div class="report-meta"><b>' + esc(t.label) + '</b> · generated ' + esc(new Date(t.generated_at).toLocaleString()) + '</div>';
    if (!t.territories.length) return h + '<div class="empty">No invoices in this period.</div>';
    var cats = CATS.filter(function (c) { return t.territories.some(function (x) { return x.cats[c[0]]; }); });
    h += '<table class="report terr-table"><thead><tr><th>Territory</th><th>Pays</th>' +
      cats.map(function (c) { return '<th class="r">' + c[1] + '<br>revenue · margin · <b>commission</b></th>'; }).join('') + '<th class="r">Total<br>margin · <b>commission</b></th></tr></thead><tbody>';
    var tot = {}, gT = 0, cT = 0;
    t.territories.forEach(function (x) {
      var g = 0, c = 0;
      h += '<tr><td><b>' + esc(x.territory) + '</b></td><td>' + (x.payees.length ? esc(x.payees.join(' + ')) : '<span class="hint">House</span>') + '</td>';
      cats.forEach(function (k) {
        var v = x.cats[k[0]];
        if (v) {
          h += '<td class="r">' + money(v.revenue) + ' · <span class="' + (v.gp < 0 ? 'neg' : '') + '">' + money(v.gp) + '</span> · <b>' + money(v.commission) + '</b><br><span class="hint">' + v.invoices + ' inv' + (v.loss_lines ? ' · <span class="loss">' + v.loss_lines + ' loss</span>' : '') + '</span></td>';
          g += v.gp; c += v.commission; tot[k[0]] = tot[k[0]] || { rev: 0, gp: 0, com: 0 }; tot[k[0]].rev += v.revenue; tot[k[0]].gp += v.gp; tot[k[0]].com += v.commission;
        } else h += '<td class="r hint">—</td>';
      });
      h += '<td class="r">' + money(g) + ' · <b>' + money(c) + '</b></td></tr>';
      gT += g; cT += c;
    });
    h += '<tr class="total"><td colspan="2" class="r">All territories</td>' + cats.map(function (k) { var v = tot[k[0]] || { rev: 0, gp: 0, com: 0 }; return '<td class="r">' + money(v.rev) + ' · ' + money(v.gp) + ' · ' + money(v.com) + '</td>'; }).join('') + '<td class="r">' + money(gT) + ' · ' + money(cT) + '</td></tr></tbody></table>';
    h += '<div class="hint" style="margin-top:8px">For the invoice-level lines, open any rep’s report and pick Hardware / Software, Agreements or Service from the “Show” menu.</div>';
    return h;
  }

  // ---- history ------------------------------------------------------------

  function loadMonths() {
    state.monthsLoading = true; render();
    api('api/report.php?action=months').then(function (r) {
      state.monthsLoading = false;
      if (r.data && r.data.ok) {
        state.months = r.data;
        if (!state.histMonth && r.data.months.length) {
          // default to the newest month that is "Last Month" or older
          var cutoff = state.dash ? state.dash.periods.last.month : '';
          var pick = r.data.months.filter(function (m) { return !cutoff || m.month <= cutoff; })[0] || r.data.months[0];
          state.histYear = pick.month.slice(0, 4); state.histMonth = pick.month.slice(5, 7);
        }
      } else state.error = (r.data && r.data.error) || 'Could not load history.';
      render();
    }).catch(function () { state.monthsLoading = false; state.error = 'Could not load history.'; render(); });
  }

  function historyHtml() {
    var m = state.months;
    var h = '<h2 class="view-title">History</h2><div class="view-sub">Saved monthly commission reports. A month is locked (frozen) once it is older than Last Month, so later rate changes never alter it. Pick a year and month to review or print it.</div>';
    if (!m) return h + '<div class="loading">Loading…</div>';
    if (!m.months.length) return h + '<div class="banner info">No closed invoices saved yet. Run a sync (Settings → Backfill) to build history.</div>';
    var years = {};
    m.months.forEach(function (x) { years[x.month.slice(0, 4)] = true; });
    var yearList = Object.keys(years).sort().reverse();
    var monthsOfYear = m.months.filter(function (x) { return x.month.slice(0, 4) === state.histYear; }).map(function (x) { return x.month.slice(5, 7); }).sort();
    if (monthsOfYear.indexOf(state.histMonth) < 0) state.histMonth = monthsOfYear[monthsOfYear.length - 1];
    var key = state.histYear + '-' + state.histMonth;
    var row = m.months.filter(function (x) { return x.month === key; })[0];

    h += '<div class="controls no-print"><label class="field">Year<select data-change="hist-year">' +
      yearList.map(function (y) { return '<option' + (y === state.histYear ? ' selected' : '') + '>' + y + '</option>'; }).join('') + '</select></label>' +
      '<label class="field">Month<select data-change="hist-month">' +
      monthsOfYear.map(function (mm) { return '<option value="' + mm + '"' + (mm === state.histMonth ? ' selected' : '') + '>' + monthLabel(state.histYear + '-' + mm).split(' ')[0] + '</option>'; }).join('') + '</select></label>' +
      '<button class="btn" type="button" data-action="open-report" data-rep="all" data-month="' + key + '">Print report — all reps</button></div>';

    if (row) {
      h += '<div class="card"><h3>' + esc(monthLabel(key)) + ' ' + (row.locked ? '<span class="chip lock">Locked ' + esc(fmtStamp(row.locked_at)) + '</span>' : '<span class="chip open">Not locked yet</span>') + '</h3>' +
        '<table class="data"><thead><tr><th>Rep</th><th class="r">Invoices</th><th class="r">Revenue</th><th class="r">Lost on losing lines</th><th class="r">Commission</th><th></th></tr></thead><tbody>';
      var tot = 0;
      m.reps.forEach(function (r) {
        var v = row.reps['r' + r.id] || { invoices: 0, revenue: 0, commission: 0, loss_amount: 0 };
        tot += v.commission;
        h += '<tr><td><b>' + esc(r.name) + '</b></td><td class="r">' + v.invoices + '</td><td class="r">' + money(v.revenue) + '</td><td class="r">' + (v.loss_amount ? '<span class="neg">' + money(v.loss_amount) + '</span>' : '—') + '</td><td class="r"><b>' + money(v.commission) + '</b></td>' +
          '<td class="r no-print"><button class="btn small secondary" type="button" data-action="open-report" data-rep="' + r.id + '" data-month="' + key + '">View / Print</button></td></tr>';
      });
      var un = row.reps.r0;
      if (un) h += '<tr><td><i>House (no commission)</i></td><td class="r">' + un.invoices + '</td><td class="r">' + money(un.revenue) + '</td><td class="r">—</td><td class="r">' + money(un.commission) + '</td><td class="r no-print"><button class="btn small secondary" type="button" data-action="open-report" data-rep="house" data-month="' + key + '">View</button></td></tr>';
      h += '<tr><td colspan="4" class="r"><b>Total</b></td><td class="r"><b>' + money(tot) + '</b></td><td></td></tr></tbody></table></div>';
    }
    return h;
  }

  // ---- trends -------------------------------------------------------------

  function loadTrends() {
    if (!state.trendsRep) return;
    state.trendsLoading = true; render();
    api('api/report.php?action=trends&rep_id=' + state.trendsRep).then(function (r) {
      state.trendsLoading = false;
      if (r.data && r.data.ok) state.trends = r.data; else state.error = (r.data && r.data.error) || 'Could not load trends.';
      render();
    }).catch(function () { state.trendsLoading = false; state.error = 'Could not load trends.'; render(); });
  }

  function svgBars(vals, labels, color, fmt) {
    var w = 760, hgt = 170, padL = 6, padB = 26, n = vals.length;
    var max = Math.max.apply(null, vals.concat([1]));
    var min = Math.min.apply(null, vals.concat([0]));
    var span = max - min || 1;
    var bw = (w - padL) / n;
    var zeroY = hgt - padB - ((0 - min) / span) * (hgt - padB - 14);
    var s = '<svg viewBox="0 0 ' + w + ' ' + hgt + '" width="100%" role="img" style="max-width:' + w + 'px">';
    vals.forEach(function (v, i) {
      var y = hgt - padB - ((v - min) / span) * (hgt - padB - 14);
      var top = Math.min(y, zeroY), bh = Math.max(1, Math.abs(zeroY - y));
      var x = padL + i * bw + bw * 0.15;
      s += '<rect x="' + x.toFixed(1) + '" y="' + top.toFixed(1) + '" width="' + (bw * 0.7).toFixed(1) + '" height="' + bh.toFixed(1) + '" rx="3" fill="' + (v < 0 ? '#e5484d' : color) + '"></rect>';
      s += '<text x="' + (x + bw * 0.35).toFixed(1) + '" y="' + (top - 3).toFixed(1) + '" font-size="9" text-anchor="middle" fill="#9aa4b5">' + esc(fmt(v)) + '</text>';
      s += '<text x="' + (x + bw * 0.35).toFixed(1) + '" y="' + (hgt - 9) + '" font-size="9.5" text-anchor="middle" fill="#9aa4b5">' + esc(labels[i]) + '</text>';
    });
    return s + '</svg>';
  }
  function spark(vals) {
    var w = 90, h = 22, max = Math.max.apply(null, vals.concat([1]));
    var pts = vals.map(function (v, i) { return (i * (w / (vals.length - 1))).toFixed(1) + ',' + (h - 2 - (v / max) * (h - 4)).toFixed(1); }).join(' ');
    return '<svg width="' + w + '" height="' + h + '" viewBox="0 0 ' + w + ' ' + h + '"><polyline fill="none" stroke="#2f8fef" stroke-width="1.6" points="' + pts + '"></polyline></svg>';
  }

  function trendsHtml() {
    var h = '<h2 class="view-title">Rep Trends</h2><div class="view-sub">Revenue won alongside commission earned — per month, month over month, year to date and per customer — ready to print or save as PDF for a review with the rep. Counts closed invoices only.</div>';
    var reps = (state.dash && state.dash.reps) || [];
    h += '<div class="rep-tabs no-print">' + reps.map(function (r) {
      return '<button type="button" class="' + (String(state.trendsRep) === String(r.id) ? 'active' : '') + '" data-action="trends-rep" data-rep="' + r.id + '">' + esc(r.name) + '</button>';
    }).join('') + '</div>';
    if (!state.trendsRep) return h + '<div class="empty">Choose a rep.</div>';
    if (state.trendsLoading || !state.trends) return h + '<div class="loading">Loading…</div>';
    var t = state.trends;
    var ytdC = t.ytd.commission, pC = t.prior_ytd.commission, ytdR = t.ytd.revenue, pR = t.prior_ytd.revenue;
    h += '<div class="no-print" style="margin-bottom:12px"><button class="btn" type="button" data-action="print">Print / Save PDF</button>' +
      (t.earliest_month_with_data && t.earliest_month_with_data > (t.year - 1) + '-01' ? ' <span class="hint">History only goes back to ' + esc(monthLabel(t.earliest_month_with_data)) + ' — backfill more in Settings for full year-over-year.</span>' : '') + '</div>';
    h += '<div class="trend-print-area"><div class="print-only"><h1 class="report-title">' + esc(t.rep.name) + ' — Commission Trend Review</h1><div class="report-meta">Generated ' + esc(new Date(t.generated_at).toLocaleString()) + ' · closed invoices only</div></div>';
    h += '<div class="cards-row">' +
      '<div class="stat"><div class="lbl">Commission YTD</div><div class="val">' + money(ytdC) + '</div><div class="delta">' + deltaHtml(ytdC - pC) + ' vs same period last year (' + money(pC) + ', ' + pctChange(ytdC, pC) + ')</div></div>' +
      '<div class="stat"><div class="lbl">Revenue won YTD</div><div class="val">' + money(ytdR) + '</div><div class="delta">' + deltaHtml(ytdR - pR) + ' vs last year (' + money(pR) + ', ' + pctChange(ytdR, pR) + ')</div></div>' +
      '<div class="stat"><div class="lbl">Gross profit YTD</div><div class="val">' + money(t.ytd.gp) + '</div><div class="delta hint">last year same period ' + money(t.prior_ytd.gp) + '</div></div>' +
      '<div class="stat"><div class="lbl">Last year total</div><div class="val">' + money(t.prior_year.commission) + '</div><div class="delta hint">commission on ' + money(t.prior_year.revenue) + ' revenue</div></div></div>';

    var labels = t.series.map(function (s) { return shortMonth(s.month) + (s.partial ? '*' : ''); });
    h += '<div class="card"><h3>Commission earned — last 13 months</h3><div class="chart-wrap">' + svgBars(t.series.map(function (s) { return s.commission; }), labels, '#2f8fef', function (v) { return Math.round(v).toLocaleString(); }) + '</div></div>';
    h += '<div class="card"><h3>Revenue won — last 13 months</h3><div class="chart-wrap">' + svgBars(t.series.map(function (s) { return s.revenue; }), labels, '#3ab57a', function (v) { return v >= 1000 ? (v / 1000).toFixed(1) + 'k' : String(Math.round(v)); }) + '</div><div class="hint">* current month so far</div></div>';

    h += '<div class="card"><h3>Month by month and month over month</h3><table class="data"><thead><tr><th>Month</th><th class="r">Invoices</th><th class="r">Revenue won</th><th class="r">Δ revenue</th><th class="r">Gross profit</th><th class="r">Commission</th><th class="r">Δ commission</th><th class="r">Lost on losing lines</th></tr></thead><tbody>';
    t.series.slice().reverse().forEach(function (s) {
      h += '<tr><td>' + esc(monthLabel(s.month)) + (s.partial ? ' <span class="hint">(so far)</span>' : '') + '</td><td class="r">' + s.invoices + '</td><td class="r">' + money(s.revenue) + '</td><td class="r">' + deltaHtml(s.revenue_change) + '</td><td class="r">' + money(s.gp) + '</td><td class="r"><b>' + money(s.commission) + '</b></td><td class="r">' + deltaHtml(s.commission_change) + '</td><td class="r">' + (s.loss_amount ? '<span class="neg">' + money(s.loss_amount) + '</span>' : '—') + '</td></tr>';
    });
    h += '</tbody></table></div>';

    h += '<div class="card"><h3>Per-customer trend — year to date vs same period last year</h3><table class="data"><thead><tr><th>Customer</th><th class="r">Revenue YTD</th><th class="r">Prior YTD</th><th class="r">Change</th><th class="r">Commission YTD</th><th class="r">Prior YTD</th><th class="r">Δ commission</th><th>12-mo revenue</th></tr></thead><tbody>';
    if (!t.customers.length) h += '<tr><td colspan="8" class="hint">No closed invoices in this period.</td></tr>';
    t.customers.forEach(function (c) {
      h += '<tr><td>' + esc(c.customer) + '</td><td class="r">' + money(c.ytd_revenue) + '</td><td class="r">' + money(c.prior_ytd_revenue) + '</td><td class="r">' + deltaHtml(c.revenue_change) + '</td><td class="r"><b>' + money(c.ytd_commission) + '</b></td><td class="r">' + money(c.prior_ytd_commission) + '</td><td class="r">' + deltaHtml(c.commission_change) + '</td><td>' + spark(c.last12_revenue) + '</td></tr>';
    });
    h += '</tbody></table></div>';

    h += '<div class="card"><h3>Transactions that lost money (last 12 months)</h3>';
    if (!t.loss_lines.length) h += '<div class="hint">None — no line was sold below cost.</div>';
    else {
      h += '<table class="data"><thead><tr><th>Invoice</th><th>Customer</th><th>Item</th><th>Ticket</th><th class="r">Cost</th><th class="r">Price</th><th class="r">Loss</th></tr></thead><tbody>';
      t.loss_lines.forEach(function (l) {
        h += '<tr><td>' + esc(l.invoice_number) + '<br><span class="hint">' + esc(fmtDate(l.invoice_date)) + '</span></td><td>' + esc(l.company_name) + '</td><td>' + esc(l.item) + '</td><td>' + esc(l.ticket_summary || '') + '</td><td class="r">' + money(l.cost) + '</td><td class="r">' + money(l.price) + '</td><td class="r neg">' + money(l.gp) + '</td></tr>';
      });
      h += '</tbody></table>';
    }
    return h + '</div></div>';
  }

  // ---- settings -----------------------------------------------------------

  function loadSettings() {
    state.settingsLoading = true; render();
    api('api/settings.php').then(function (r) {
      state.settingsLoading = false;
      if (r.data && r.data.ok) state.settings = r.data; else state.error = (r.data && r.data.error) || 'Could not load settings.';
      render();
    }).catch(function () { state.settingsLoading = false; state.error = 'Could not load settings.'; render(); });
  }

  function settingsHtml() {
    var s = state.settings;
    var h = '<h2 class="view-title">Settings</h2><div class="view-sub">Rates, the labor cost per hour, and which ConnectWise territory belongs to which rep. Changes are logged and re-applied to every month that isn’t locked yet.</div>';
    if (state.settingsMsg) h += '<div class="banner info">' + esc(state.settingsMsg) + '</div>';
    if (!s) return h + '<div class="loading">Loading…</div>';
    h += '<div class="card"><h3>Labor cost</h3><div class="controls">' +
      '<label class="field">Labor cost per hour ($)<input type="number" step="0.01" min="0" id="set-labor" value="' + esc(s.settings.labor_cost_per_hour) + '"></label></div>' +
      '<div class="hint">Time is costed at the labor rate above times the hours actually worked (as in the ConnectWise Service Commission report), regardless of what the customer was billed. A line that loses money is subtracted from the rep’s commission and always appears in the drill-downs and printed reports.</div></div>';

    h += '<div class="card"><h3>Who gets paid</h3><div class="hint" style="margin-bottom:8px">A ConnectWise territory pays every rep below whose words appear in it (whole words, any order). “Arcus + Chester Sienko” matches both Arcus and Chester, so it splits: Chester is paid his rate first, then Arcus is paid ' + esc(s.settings.shared_arcus_pct) + '% of the gross profit that remains after Chester’s commission (the % shown on those lines is Arcus’s share of the full gross profit; change it below the table); “Moe” matches “Moe Okeilli (new accounts)” and “Trey + Moe Okeilli”. A territory that matches nobody is a house account and pays nothing.</div><table class="data"><thead><tr><th>Rep</th><th>Territory words (comma separated)</th><th>Email<br><span class="hint" style="text-transform:none">(collections reports)</span></th><th>Commission % of gross profit</th><th>% on Agreement invoices after 1 year<br><span class="hint" style="text-transform:none">(leave empty for no change)</span></th></tr></thead><tbody>';
    s.reps.forEach(function (r) {
      h += '<tr data-rep-row="' + r.id + '"><td><b>' + esc(r.name) + '</b></td><td><input type="text" style="width:100%" data-f="territory" value="' + esc(r.territory_match) + '"></td>' +
        '<td><input type="email" style="width:100%" data-f="email" value="' + esc(r.email || '') + '"></td>' +
        '<td><input type="number" step="0.01" min="0" max="100" class="pct" data-f="base" value="' + esc(r.base_pct) + '"> %</td>' +
        '<td><input type="number" step="0.01" min="0" max="100" class="pct" data-f="after" value="' + (r.agreement_after_year_pct == null ? '' : esc(r.agreement_after_year_pct)) + '"> %</td></tr>';
    });
    h += '</tbody></table>' +
      '<div class="controls" style="margin-top:12px"><label class="field">Arcus on a shared Arcus + Chester territory — % of the gross profit remaining after Chester’s commission' +
      '<input type="number" step="0.01" min="0" max="100" class="pct" id="set-shared-arcus" value="' + esc(s.settings.shared_arcus_pct) + '"> %</label></div>' +
      '<div class="hint">Chester is paid first (his % above, or his after-one-year % on older agreements); Arcus is then paid this % of what is left. Arcus alone in a territory still uses his own % in the table.</div>' +
      '<div style="margin-top:12px"><button class="btn" type="button" data-action="save-settings">Save and recalculate</button></div></div>';

    h += '<div class="card"><h3>Territories seen on synced invoices</h3><table class="data"><thead><tr><th>ConnectWise territory</th><th class="r">Invoices</th><th>Pays</th></tr></thead><tbody>';
    if (!s.territories.length) h += '<tr><td colspan="3" class="hint">Nothing synced yet.</td></tr>';
    s.territories.forEach(function (t) {
      h += '<tr><td>' + esc(t.territory) + '</td><td class="r">' + t.invoices + '</td><td>' + (t.payees && t.payees.length ? esc(t.payees.join(' + ')) : '<span class="hint">House account — no commission</span>') + '</td></tr>';
    });
    h += '</tbody></table></div>';

    h += '<div class="card"><h3>ConnectWise sync</h3><div class="view-sub" style="margin-bottom:10px">The dashboard syncs itself when opened (at most every 30 minutes). Use a backfill once to load history for the History and Trends reports; months older than Last Month lock automatically.</div><div class="controls">' +
      '<button class="btn secondary" type="button" data-action="sync" data-months="1">Sync now</button>' +
      '<button class="btn secondary" type="button" data-action="sync" data-months="12">Backfill 12 months</button>' +
      '<button class="btn secondary" type="button" data-action="sync" data-months="24">Backfill 24 months</button>' +
      '<button class="btn secondary" type="button" data-action="sync-force" data-months="3">Re-sync last 3 months incl. locked</button></div>' +
      '<div class="controls"><label class="field">Diagnose one invoice (ConnectWise invoice id)<input type="number" id="probe-id" style="width:160px"></label><button class="btn small secondary" type="button" data-action="probe">Open raw ConnectWise data</button></div>' +
      '<div class="hint">Locked months: ' + (s.locked_months.length ? esc(s.locked_months.join(', ')) : 'none yet') + '</div></div>';
    return h;
  }

  function saveSettings() {
    var reps = [];
    document.querySelectorAll('[data-rep-row]').forEach(function (tr) {
      reps.push({
        id: Number(tr.getAttribute('data-rep-row')),
        territory_match: tr.querySelector('[data-f=territory]').value,
        email: tr.querySelector('[data-f=email]').value,
        base_pct: tr.querySelector('[data-f=base]').value,
        agreement_after_year_pct: tr.querySelector('[data-f=after]').value
      });
    });
    api('api/settings.php', {
      labor_cost_per_hour: document.getElementById('set-labor').value,
      shared_arcus_pct: document.getElementById('set-shared-arcus').value,
      reps: reps
    }).then(function (r) {
      if (r.data && r.data.ok) {
        state.settings = r.data;
        state.settingsMsg = 'Saved. ' + r.data.lines_recomputed + ' lines recalculated (locked months untouched).';
        loadDashboard(false);
      } else state.settingsMsg = 'Not saved: ' + ((r.data && r.data.error) || 'unknown error');
      render();
    }).catch(function () { state.settingsMsg = 'Not saved: could not reach the server.'; render(); });
  }

  // ---- render + events ----------------------------------------------------

  function render() {
    if (state.denied) {
      root.innerHTML = '<div class="empty"><h2>No access</h2>Commissions is limited to a few people. If you need access, ask Michael Bergamo.<br><br><a class="back-to-hub" href="../index.html">← SolutionsHub</a></div>';
      return;
    }
    if (!state.user) {
      root.innerHTML = state.error ? '<div class="empty">' + esc(state.error) + '</div>' : '<div class="loading">Loading…</div>';
      return;
    }
    var body;
    if (state.view === 'history') body = historyHtml();
    else if (state.view === 'trends') body = trendsHtml();
    else if (state.view === 'territories') body = territoriesHtml();
    else if (state.view === 'settings') body = settingsHtml();
    else body = dashboardHtml();
    document.body.classList.toggle('has-report', !!(state.report || state.arPop));
    root.innerHTML = topbarHtml() + '<main>' + (state.error ? '<div class="banner error no-print">' + esc(state.error) + ' <a href="#" data-action="dismiss">dismiss</a></div>' : '') +
      syncBarHtml() + '<div class="view-body">' + body + '</div><div class="report-slot">' + reportModalHtml() + arModalHtml() + '</div></main>';
  }

  function setView(v) {
    state.view = v; state.error = null; state.settingsMsg = null;
    if (v === 'history') loadMonths();
    else if (v === 'trends') {
      if (!state.trendsRep && state.dash && state.dash.reps.length) state.trendsRep = state.dash.reps[0].id;
      loadTrends();
    } else if (v === 'territories') { if (!state.months) loadMonthsQuiet(); loadTerritories(); }
    else if (v === 'settings') loadSettings();
    else render();
  }

  var savedTitle = document.title;
  function printWithTitle() {
    var title = savedTitle;
    if (state.arPop && state.arPop.data) title = 'Collections Report - ' + state.arPop.data.title + ' - ' + state.arPop.data.as_of_label;
    else if (state.report && state.report.data) title = 'Commission Report - ' + state.report.data.rep.name + ' - ' + state.report.data.period_label + (state.report.cat && state.report.cat !== 'all' ? ' - ' + catLabel(state.report.cat) : '');
    else if (state.view === 'territories' && state.terr) title = 'Commissions by Territory - ' + state.terr.label;
    else if (state.view === 'trends' && state.trends) title = 'Commission Trend Review - ' + state.trends.rep.name;
    document.title = title;
    window.print();
    setTimeout(function () { document.title = savedTitle; }, 500);
  }

  document.addEventListener('click', function (e) {
    var el = e.target.closest('[data-action]');
    if (!el) return;
    var a = el.getAttribute('data-action');
    if (a === 'close-ar-bg') { if (e.target === el) { state.arPop = null; render(); } return; }
    if (a === 'close-report-bg') { if (e.target === el) { state.report = null; render(); } return; }
    if (el.tagName === 'A') e.preventDefault();
    if (a === 'view') setView(el.getAttribute('data-view'));
    else if (a === 'dismiss') { state.error = null; render(); }
    else if (a === 'sync') runSync(Number(el.getAttribute('data-months')) || 1, false);
    else if (a === 'sync-force') runSync(Number(el.getAttribute('data-months')) || 3, true);
    else if (a === 'open-report') openReport(el.getAttribute('data-rep'), el.getAttribute('data-bucket'), el.getAttribute('data-month'));
    else if (a === 'open-ar') openAr(el.getAttribute('data-rep'), el.getAttribute('data-territory'));
    else if (a === 'close-ar') { state.arPop = null; render(); }
    else if (a === 'ar-email-toggle') { state.arPop.emailOpen = !state.arPop.emailOpen; state.arPop.msg = null; render(); }
    else if (a === 'ar-email-send') sendArEmail();
    else if (a === 'close-report') { state.report = null; render(); }
    else if (a === 'print') printWithTitle();
    else if (a === 'trends-rep') { state.trendsRep = el.getAttribute('data-rep'); state.trends = null; loadTrends(); }
    else if (a === 'save-settings') saveSettings();
    else if (a === 'probe') {
      var id = document.getElementById('probe-id').value;
      if (id) window.open('api/sync.php?action=probe&invoice_id=' + encodeURIComponent(id), '_blank');
    }
  });
  document.addEventListener('change', function (e) {
    var t = e.target;
    if (t.getAttribute && t.getAttribute('data-action') === 'toggle-loss') { state.report.lossOnly = t.checked; render(); return; }
    var c = t.getAttribute && t.getAttribute('data-change');
    if (c === 'ar-to') { state.arPop.to = t.value.trim(); return; }
    if (c === 'hist-year') { state.histYear = t.value; render(); }
    else if (c === 'hist-month') { state.histMonth = t.value; render(); }
    else if (c === 'report-cat') { state.report.cat = t.value; render(); }
    else if (c === 'terr-period') { state.terrPeriod = t.value; loadTerritories(); }
  });
  window.addEventListener('afterprint', function () { document.title = savedTitle; });

  boot();
})();
