/* collections/app.js — Collections sub-app (added 2026-10-07).
   Open (unpaid) ConnectWise invoices by rep / territory, with a pop-over collections report
   that can be emailed. Data comes from commissions/api/ar.php, which enforces who sees what
   (Courtney, Trey, Michael and Kasie see everything; Moe and Chester only their own territories). */
(function () {
  'use strict';

  var API = '../commissions/api/ar.php';
  var root = document.getElementById('app-root');
  var CW = (function () { try { var v = new URLSearchParams(window.location.search).get('cw_company') || ''; return /^\d+$/.test(v) ? v : ''; } catch (e) { return ''; } })();
  var state = { user: null, denied: false, error: null, ar: null, arPop: null, refreshing: false };

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function money(n) {
    n = Number(n || 0);
    var s = '$' + Math.abs(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    return n < 0 ? '-' + s : s;
  }
  function fmtDate(d) {
    var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(d || '');
    return m ? m[2] + '/' + m[3] + '/' + m[1] : (d || '');
  }
  function fmtStamp(iso) {
    if (!iso) return 'never';
    var d = new Date(iso);
    return isNaN(d.getTime()) ? iso : d.toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
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

  // ---- boot: sign-in check, show what we have, then refresh from ConnectWise if stale ----
  function boot() {
    api('../auth/me.php').then(function (r) {
      var u = r.data && r.data.user;
      if (!u) { window.location.href = 'login.html'; return; }
      state.user = u;
      if (CW) { render(); loadCustomer(true); return; }   // client-level report: the API decides who may see this customer
      if (!u.can_view_collections) { state.denied = true; render(); return; }
      render();
      loadAr(true);
    }).catch(function () { state.error = 'Could not reach the server.'; render(); });
  }

  function loadAr(maybeRefresh) {
    api(API + '?action=summary').then(function (r) {
      if (r.status === 401) { window.location.href = 'login.html'; return; }
      if (r.status === 403) { state.denied = true; render(); return; }
      if (r.data && r.data.ok) {
        state.ar = r.data; state.error = null; render();
        var age = r.data.refreshed_at ? Date.now() - new Date(r.data.refreshed_at).getTime() : Infinity;
        if (maybeRefresh && (age > 30 * 60 * 1000 || r.data.detail_pending)) refreshAr();
      } else {
        state.error = (r.data && r.data.error) || 'Could not load collections.'; render();
      }
    }).catch(function () { state.error = 'Could not load collections.'; render(); });
  }

  // ---- one customer's open balance (linked from the Relationships customer page) ----
  function loadCustomer(maybeRefresh) {
    var p = state.arPop;
    if (!p) { p = state.arPop = { loading: true, data: null, error: null, cwId: CW, customerMode: true, emailOpen: false, to: '', msg: null, msgOk: false, sending: false }; render(); }
    api(API + '?action=customer&cw_id=' + encodeURIComponent(CW)).then(function (r) {
      if (r.status === 401) { window.location.href = 'login.html'; return; }
      p.loading = false;
      if (r.data && r.data.ok) {
        p.data = r.data; p.error = null;
        var age = r.data.refreshed_at ? Date.now() - new Date(r.data.refreshed_at).getTime() : Infinity;
        if (maybeRefresh && (age > 30 * 60 * 1000 || r.data.detail_pending)) refreshCustomer();
      } else p.error = (r.data && r.data.error) || 'Could not load the open balance report.';
      render();
    }).catch(function () { p.loading = false; p.error = 'Could not load the open balance report.'; render(); });
  }
  function refreshCustomer() {
    state.refreshing = true; render();
    var done = function () { state.refreshing = false; loadCustomer(false); };
    api(API + '?action=refresh', {}).then(function (r) {
      if (!r.data || !r.data.ok || !(r.data.pending > 0)) return done();
      var step = function () {
        api(API + '?action=detail_step', {}).then(function (s2) {
          if (s2.data && s2.data.ok && s2.data.pending > 0) step(); else done();
        }).catch(done);
      };
      step();
    }).catch(done);
  }

  // Re-read unpaid invoices from ConnectWise, then fill in agreement/ticket detail a few at a time.
  function refreshAr() {
    if (state.refreshing) return;
    state.refreshing = true; render();
    var finish = function (msg) {
      state.refreshing = false;
      if (msg) state.error = msg;
      loadAr(false);
    };
    api(API + '?action=refresh', {}).then(function (r) {
      if (!r.data || !r.data.ok) return finish('Could not refresh from ConnectWise: ' + ((r.data && r.data.error) || 'unknown error'));
      var step = function () {
        api(API + '?action=detail_step', {}).then(function (s) {
          if (!s.data || !s.data.ok) return finish(null);
          if (s.data.pending > 0) { loadArQuiet(); step(); } else finish(null);
        }).catch(function () { finish(null); });
      };
      if (r.data.pending > 0) step(); else finish(null);
    }).catch(function () { finish('Could not reach the server to refresh.'); });
  }
  function loadArQuiet() {
    api(API + '?action=summary').then(function (r) { if (r.data && r.data.ok) { state.ar = r.data; render(); } });
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
    var h = '<div class="card ar-card no-print"><h3>' + (a.viewer && a.viewer.scope === 'rep' ? esc(a.viewer.rep_name) + '’s open invoices' : 'Open invoices to collect') + ' <small>as of ' + esc(a.as_of_label) + '</small></h3>' +
      '<div class="view-sub" style="margin-bottom:10px">Closed ConnectWise invoices not yet paid and no more than ' + a.bad_debt_days + ' days old (older is bad debt), with days counted from the invoice date. Click an amount to see the invoices and send a collections report.' +
      (a.detail_pending ? ' <i>(' + a.detail_pending + ' invoice' + (a.detail_pending === 1 ? '' : 's') + ' still loading agreement/ticket detail…)</i>' : '') + '</div>' +
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
    var url = API + '?action=detail&rep_id=' + encodeURIComponent(rep) + (territory ? '&territory=' + encodeURIComponent(territory) : '');
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
    if (p.customerMode) {
      return '<div class="modal cust-report" style="max-width:1100px;margin:0 auto">' +
        '<div class="modal-head no-print"><h3>' + (p.data ? 'Open balance — ' + esc(p.data.title) : 'Open balance report') + '</h3><div class="actions">' +
        (p.data && p.data.customers.length ? '<button class="btn" type="button" data-action="ar-email-toggle">Email this report…</button>' : '') +
        '<button class="btn secondary" type="button" data-action="print">Print / Save PDF</button></div></div>' + email + msg + body + '</div>';
    }
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
    api(API + '?action=email', p.customerMode ? { cw_id: p.cwId, to: p.to } : { rep_id: p.rep, territory: p.territory, to: p.to }).then(function (r) {
      p.sending = false;
      if (r.data && r.data.ok) { p.msg = 'Collections report sent to ' + r.data.sent_to + ' (' + r.data.invoices + ' invoice' + (r.data.invoices === 1 ? '' : 's') + ', ' + money(r.data.balance) + ').'; p.msgOk = true; p.emailOpen = false; }
      else { p.msg = (r.data && r.data.error) || 'Could not send the email.'; p.msgOk = false; }
      render();
    }).catch(function () { p.sending = false; p.msg = 'Could not reach the server.'; p.msgOk = false; render(); });
  }


  // ---- page ----
  function topbarHtml() {
    return '<div class="topbar no-print"><div class="topbar-left"><div><div class="brand">Collections</div><div class="brand-sub">CodeBlue Technology</div></div></div>' +
      '<div class="topbar-nav"></div>' +
      '<div class="topbar-right"><button class="btn small secondary" type="button" data-action="refresh"' + (state.refreshing ? ' disabled' : '') + '>' + (state.refreshing ? 'Refreshing…' : 'Refresh from ConnectWise') + '</button> ' +
      '<a class="back-to-hub" href="' + (CW ? '../relationships/index.html' : '../finance/') + '">' + (CW ? '← Relationships' : '← Finance') + '</a></div></div>';
  }

  function render() {
    if (state.denied) {
      root.innerHTML = '<div class="empty"><h2>No access</h2>Collections is limited to a few people. If you need access, ask Michael Bergamo.<br><br><a class="back-to-hub" href="../finance/">← Finance</a></div>';
      return;
    }
    if (!state.user) {
      root.innerHTML = state.error ? '<div class="empty">' + esc(state.error) + '</div>' : '<div class="loading">Loading…</div>';
      return;
    }
    var body = CW ? '' : (state.ar ? arCardHtml() : '<div class="loading">Loading…</div>');
    document.body.classList.toggle('has-report', !!state.arPop && !CW);
    root.innerHTML = topbarHtml() + '<main>' +
      (state.error ? '<div class="banner error no-print">' + esc(state.error) + ' <a href="#" data-action="dismiss">dismiss</a></div>' : '') +
      (CW && state.arPop ? '<div class="cust-slot">' + arModalHtml() + '</div>' : '') + (state.refreshing ? '<div class="banner info no-print"><b>Refreshing from ConnectWise…</b> The numbers below update as it finishes.</div>' : '') +
      (CW ? '' : '<h2 class="view-title">Collections</h2>') + '<div class="view-body">' + body + '</div><div class="report-slot">' + (CW ? '' : arModalHtml()) + '</div></main>';
  }

  var savedTitle = document.title;
  function printWithTitle() {
    if (state.arPop && state.arPop.data) document.title = 'Collections Report - ' + state.arPop.data.title + ' - ' + state.arPop.data.as_of_label;
    window.print();
    setTimeout(function () { document.title = savedTitle; }, 500);
  }

  document.addEventListener('click', function (e) {
    var el = e.target.closest('[data-action]');
    if (!el) return;
    var a = el.getAttribute('data-action');
    if (a === 'close-ar-bg') { if (e.target === el) { state.arPop = null; render(); } return; }
    if (el.tagName === 'A') e.preventDefault();
    if (a === 'dismiss') { state.error = null; render(); }
    else if (a === 'refresh') { if (CW) refreshCustomer(); else refreshAr(); }
    else if (a === 'open-ar') openAr(el.getAttribute('data-rep'), el.getAttribute('data-territory'));
    else if (a === 'close-ar') { state.arPop = null; render(); }
    else if (a === 'ar-email-toggle') { state.arPop.emailOpen = !state.arPop.emailOpen; state.arPop.msg = null; render(); }
    else if (a === 'ar-email-send') sendArEmail();
    else if (a === 'print') printWithTitle();
  });
  document.addEventListener('change', function (e) {
    var t = e.target;
    if (t.getAttribute && t.getAttribute('data-change') === 'ar-to') state.arPop.to = t.value.trim();
  });
  window.addEventListener('afterprint', function () { document.title = savedTitle; });

  boot();
})();
