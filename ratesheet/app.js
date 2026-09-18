/**
 * ratesheet/app.js
 *
 * Customer Rate Sheet Sign Up — rep-facing app (added 2026-09-17, per
 * Michael). Same self-contained vanilla-JS pattern as register/app.js and
 * relationships/app.js: a state object + string-template rendering, no
 * framework, sign-in gate via the shared Microsoft 365 SSO.
 *
 * Two views:
 *   'send'      — the send-a-rate-sheet form (prospect email, Sending
 *                 Representative, Location, Kind of Account, Send).
 *   'dashboard' — list of rate sheets this rep can see (role-based, see
 *                 api/requests.php's ?action=list), with a status dot
 *                 driven by the server's `payment_status` field -- per
 *                 Michael's 2026-09-18 redesign: RED "Sent" (link sent,
 *                 not yet submitted) -> YELLOW "Signed" (customer
 *                 submitted, ConnectWise Company on Credit Hold) -> GREEN
 *                 "Payment Added" (Invoicing has since changed the
 *                 Company's Billing Status in ConnectWise -- detected
 *                 live, not tracked in this app's own database, see
 *                 api/requests.php's ratesheet_payment_status()). A
 *                 distinct red "Failed" covers a ConnectWise create error.
 */

(function () {
  'use strict';

  var HUB_URL = '../index.html';

  // Keep in sync with api/_util.php's ratesheet_sender_roster() -- names
  // only here (the dropdown doesn't need the email, the server resolves
  // it), same order Michael gave.
  var SENDER_ROSTER = [
    'Chester Sienko', 'Moe Okeilli', 'Walter Drew', 'Claire Hayden',
    'Casey Mayes', 'Michael Bergamo', 'Courtney Cruz', 'Kasie Van Fossen', 'Trey Hayden',
    'Daemian Caron', 'Kevin Headley'
  ];

  var root = document.getElementById('app-root');

  var state = {
    user: null,
    isAdmin: false,
    error: null,
    view: 'send',

    sendForm: { prospect_email: '', rep_name: '', location: '', account_kind: '' },
    sending: false,
    sendError: null,
    sendSuccess: null,

    requests: null,
    requestsLoading: false,
    requestsError: null,

    clearingTestData: false,
    clearTestDataError: null
  };

  function e(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

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

  function fmtDateTime(iso) {
    if (!iso) return '—';
    var d = new Date(iso.indexOf('Z') === -1 && iso.indexOf('+') === -1 ? iso + 'Z' : iso);
    if (isNaN(d.getTime())) return iso;
    return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }) +
      ' ' + d.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
  }

  // ---- Boot ----------------------------------------------------------

  function boot() {
    apiGet('api/auth.php?action=me').then(function (r) {
      if (!r.data || !r.data.ok || !r.data.user) {
        window.location.href = 'login.html?next=' + encodeURIComponent('index.html');
        return;
      }
      state.user = r.data.user;
      state.isAdmin = !!r.data.is_admin;
      render();
      loadRequests();
    }).catch(function () {
      window.location.href = 'login.html?next=' + encodeURIComponent('index.html');
    });
  }

  function loadRequests() {
    state.requestsLoading = true;
    state.requestsError = null;
    render();
    apiGet('api/requests.php?action=list').then(function (r) {
      state.requestsLoading = false;
      if (r.data && r.data.ok) {
        state.requests = r.data.requests;
      } else {
        state.requestsError = (r.data && r.data.error) || 'Could not load rate sheets.';
      }
      render();
    }).catch(function () {
      state.requestsLoading = false;
      state.requestsError = 'Could not load rate sheets — check your connection and try again.';
      render();
    });
  }

  function clearTestData() {
    if (state.clearingTestData) return;
    if (!window.confirm('This permanently deletes every rate sheet in the dashboard (sent, signed, everything) — there is no undo. Continue?')) {
      return;
    }
    state.clearingTestData = true;
    state.clearTestDataError = null;
    render();
    apiPost('api/requests.php?action=clear-test-data', { confirm: true }).then(function (r) {
      state.clearingTestData = false;
      if (r.data && r.data.ok) {
        loadRequests();
      } else {
        state.clearTestDataError = (r.data && r.data.error) || 'Could not clear rate sheets.';
        render();
      }
    }).catch(function () {
      state.clearingTestData = false;
      state.clearTestDataError = 'Could not reach the server — check your connection and try again.';
      render();
    });
  }

  function submitSendForm() {
    var f = state.sendForm;
    if (!f.prospect_email.trim() || !f.rep_name || !f.location || !f.account_kind) {
      state.sendError = 'Please fill in every field before sending.';
      render();
      return;
    }
    state.sending = true;
    state.sendError = null;
    state.sendSuccess = null;
    render();
    apiPost('api/requests.php?action=send', f).then(function (r) {
      state.sending = false;
      if (r.data && r.data.ok) {
        state.sendSuccess = 'Rate sheet sent to ' + f.prospect_email + '.';
        state.sendForm = { prospect_email: '', rep_name: '', location: '', account_kind: '' };
        loadRequests();
      } else {
        state.sendError = (r.data && r.data.error) || 'Could not send the rate sheet.';
      }
      render();
    }).catch(function () {
      state.sending = false;
      state.sendError = 'Could not send the rate sheet — check your connection and try again.';
      render();
    });
  }

  // ---- Render ----------------------------------------------------------

  // payment_status is computed server-side (api/requests.php's
  // ratesheet_payment_status()) from a live ConnectWise lookup -- see this
  // file's header. 'sent' (RED), 'signed' (YELLOW), 'payment_added'
  // (GREEN), 'failed' (RED, distinct label).
  function statusDot(paymentStatus) {
    var colors = { sent: '#e5534b', signed: '#e8c547', payment_added: '#2ecc71', failed: '#e5534b' };
    var labels = { sent: 'Sent', signed: 'Signed', payment_added: 'Payment Added', failed: 'Failed' };
    var color = colors[paymentStatus] || '#8A93A3';
    var label = labels[paymentStatus] || 'Unknown';
    return '<span style="display:inline-flex;align-items:center;gap:6px;font-size:12.5px;font-weight:700;color:' + color + ';">' +
      '<span style="width:9px;height:9px;border-radius:999px;background:' + color + ';display:inline-block;"></span>' + label + '</span>';
  }

  // No payment numbers are ever collected by this app (Invoicing adds the
  // real payment method directly in Alternative Payments, see
  // api/public.php's header) -- this column just shows the customer's
  // stated Card/ACH preference from the signup form.
  function paymentMethodCell(r) {
    if (r.status === 'pending') return '—';
    if (r.payment_method === 'ach') return 'ACH';
    if (r.payment_method === 'card') return 'Card';
    return '—';
  }

  function topbarHtml() {
    return '' +
      '<div class="topbar">' +
      '  <div class="topbar-left">' +
      '    <div>' +
      '      <div class="brand">Customer Rate Sheet Sign Up</div>' +
      '      <div class="brand-sub">CodeBlue Technology</div>' +
      '    </div>' +
      '    <div class="topbar-nav">' +
      '      <button class="nav-btn ' + (state.view === 'send' ? 'active' : '') + '" data-action="nav-send">Send Rate Sheet</button>' +
      '      <button class="nav-btn ' + (state.view === 'dashboard' ? 'active' : '') + '" data-action="nav-dashboard">' + (state.isAdmin ? 'All Rate Sheets' : 'My Rate Sheets') + '</button>' +
      '    </div>' +
      '  </div>' +
      '  <div class="topbar-right">' +
      '    <a class="back-to-hub" href="' + HUB_URL + '">← Portal</a>' +
      '    <span>' + e(state.user.name) + '</span>' +
      '    <button class="signout-btn" data-action="signout">Sign Out</button>' +
      '  </div>' +
      '</div>';
  }

  function sendFormHtml() {
    var f = state.sendForm;
    var repOptions = '<option value="">Select…</option>' + SENDER_ROSTER.map(function (n) {
      return '<option value="' + e(n) + '" ' + (f.rep_name === n ? 'selected' : '') + '>' + e(n) + '</option>';
    }).join('');

    return '' +
      '<div class="card form-card">' +
      '  <div class="card-title">Send a Rate Sheet</div>' +
      (state.sendError ? '<div class="error-banner">' + e(state.sendError) + '</div>' : '') +
      (state.sendSuccess ? '<div class="success-banner">' + e(state.sendSuccess) + '</div>' : '') +
      '  <label class="field-label">Prospect Email Address</label>' +
      '  <input class="text-input" type="email" placeholder="prospect@example.com" value="' + e(f.prospect_email) + '" data-field="prospect_email" data-action="field-input" />' +
      '' +
      '  <label class="field-label">Sending Representative</label>' +
      '  <select class="select-input" data-field="rep_name" data-action="field-input">' + repOptions + '</select>' +
      '' +
      '  <label class="field-label">Location</label>' +
      '  <select class="select-input" data-field="location" data-action="field-input">' +
      '    <option value="">Select…</option>' +
      '    <option value="Warsaw" ' + (f.location === 'Warsaw' ? 'selected' : '') + '>Warsaw</option>' +
      '    <option value="Richmond" ' + (f.location === 'Richmond' ? 'selected' : '') + '>Richmond</option>' +
      '  </select>' +
      '' +
      '  <label class="field-label">Kind of Account</label>' +
      '  <div class="radio-row">' +
      '    <label class="radio-option"><input type="radio" name="account_kind" value="Commercial" ' + (f.account_kind === 'Commercial' ? 'checked' : '') + ' data-field="account_kind" data-action="field-radio" /> Commercial</label>' +
      '    <label class="radio-option"><input type="radio" name="account_kind" value="Residential" ' + (f.account_kind === 'Residential' ? 'checked' : '') + ' data-field="account_kind" data-action="field-radio" /> Residential</label>' +
      '  </div>' +
      '' +
      '  <button class="primary-btn" data-action="send-submit" ' + (state.sending ? 'disabled' : '') + '>' + (state.sending ? 'Sending…' : 'Send') + '</button>' +
      '</div>';
  }

  // Admin-only, per Michael (2026-09-18): one-time cleanup of the
  // development/testing rate sheets sent while this app was being built.
  // See api/requests.php's ?action=clear-test-data docblock -- wipes
  // every row (there's no "test" flag to filter on), gated by both the
  // admin check server-side and a confirm() here so it can't fire by
  // accident. Shown above the dashboard table whenever it renders,
  // including the empty state, so an admin can confirm a clear worked.
  function clearTestDataButtonHtml() {
    if (!state.isAdmin) return '';
    return '<div class="clear-test-data-row">' +
      (state.clearingTestData ? '<span class="clear-test-data-status">Clearing…</span>' : '') +
      (state.clearTestDataError ? '<span class="clear-test-data-status" style="color:#e5534b;">' + e(state.clearTestDataError) + '</span>' : '') +
      '<button class="secondary-btn" data-action="clear-test-data" ' + (state.clearingTestData ? 'disabled' : '') + '>Clear Test Data</button>' +
      '</div>';
  }

  function dashboardHtml() {
    if (state.requestsLoading && state.requests === null) {
      return '<div class="loading">Loading rate sheets…</div>';
    }
    if (state.requestsError) {
      return '<div class="error-banner">' + e(state.requestsError) + '</div>';
    }
    var rows = state.requests || [];
    if (rows.length === 0) {
      return clearTestDataButtonHtml() + '<div class="empty-state">No rate sheets sent yet.</div>';
    }
    var body = rows.map(function (r) {
      // Only a submitted or failed row has anything to show on the
      // printable "accepted terms" record -- a still-pending row has no
      // signature/timestamp/IP yet, so it's not clickable.
      var clickable = r.status !== 'pending';
      return '<tr' + (clickable ? ' class="row-clickable" data-action="view-detail" data-id="' + r.id + '"' : '') + '>' +
        '<td>' + statusDot(r.payment_status) + '</td>' +
        '<td>' + e(r.prospect_email) + '</td>' +
        '<td>' + e(r.rep_name) + '</td>' +
        '<td>' + fmtDateTime(r.sent_at) + '</td>' +
        '<td>' + e(r.account_kind) + '</td>' +
        '<td>' + e(r.location) + '</td>' +
        '<td>' + paymentMethodCell(r) + '</td>' +
        '<td>' + (r.invoices_emailed === null ? '—' : (r.invoices_emailed ? 'Yes' : 'No')) + '</td>' +
        '</tr>';
    }).join('');

    return '' +
      clearTestDataButtonHtml() +
      '<div class="card">' +
      '  <table class="data-table">' +
      '    <thead><tr><th></th><th>Prospect Email</th><th>Sent By</th><th>Time Sent</th><th>Type</th><th>Location</th><th>Payment Method</th><th>Invoices Emailed</th></tr></thead>' +
      '    <tbody>' + body + '</tbody>' +
      '  </table>' +
      '  <div class="table-hint">Click a submitted or failed row to view the signed terms record.</div>' +
      '</div>';
  }

  function render() {
    if (!state.user) {
      root.innerHTML = '<div class="loading">Loading…</div>';
      return;
    }
    root.innerHTML = topbarHtml() +
      '<div class="main">' +
      (state.view === 'send' ? sendFormHtml() : dashboardHtml()) +
      '</div>';
  }

  // ---- Events ----------------------------------------------------------

  root.addEventListener('click', function (ev) {
    var el = ev.target.closest('[data-action]');
    if (!el) return;
    var action = el.getAttribute('data-action');
    if (action === 'nav-send') {
      state.view = 'send';
      render();
    } else if (action === 'nav-dashboard') {
      state.view = 'dashboard';
      if (state.requests === null) loadRequests();
      render();
    } else if (action === 'signout') {
      apiPost('api/auth.php?action=logout', {}).then(function () { window.location.href = 'login.html'; });
    } else if (action === 'send-submit') {
      submitSendForm();
    } else if (action === 'view-detail') {
      window.open('receipt.html?id=' + encodeURIComponent(el.getAttribute('data-id')), '_blank');
    } else if (action === 'clear-test-data') {
      clearTestData();
    }
  });

  root.addEventListener('input', function (ev) {
    var el = ev.target.closest('[data-action="field-input"]');
    if (!el) return;
    state.sendForm[el.getAttribute('data-field')] = el.value;
  });
  root.addEventListener('change', function (ev) {
    var el = ev.target.closest('[data-action="field-radio"]');
    if (!el) return;
    state.sendForm[el.getAttribute('data-field')] = el.value;
    render();
  });

  boot();
})();
