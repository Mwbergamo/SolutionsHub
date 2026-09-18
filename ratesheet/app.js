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
 *                 (green=submitted, yellow=pending, red=failed).
 */

(function () {
  'use strict';

  var HUB_URL = '../index.html';

  // Keep in sync with api/_util.php's ratesheet_sender_roster() -- names
  // only here (the dropdown doesn't need the email, the server resolves
  // it), same order Michael gave.
  var SENDER_ROSTER = [
    'Chester Sienko', 'Moe Okeilli', 'Walter Drew', 'Claire Hayden',
    'Casey Mayes', 'Michael Bergamo', 'Courtney Cruz', 'Kasie Van Fossen', 'Trey Hayden'
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
    requestsError: null
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

  // 'awaiting_payment' added 2026-09-17 (follow-up #4, two-step signup):
  // Step 1 done (ConnectWise Company/Contact created, Credit Hold ON),
  // customer just hasn't finished Step 2 (payment) yet -- distinct from
  // 'pending' (link sent, nothing done) so staff can tell them apart.
  function statusDot(status) {
    var color = status === 'submitted' ? '#2ecc71' : status === 'failed' ? '#e5534b' : status === 'awaiting_payment' ? '#e08a2e' : '#e8c547';
    var label = status === 'submitted' ? 'Submitted' : status === 'failed' ? 'Failed' : status === 'awaiting_payment' ? 'Awaiting Payment' : 'Pending';
    return '<span style="display:inline-flex;align-items:center;gap:6px;font-size:12.5px;font-weight:700;color:' + color + ';">' +
      '<span style="width:9px;height:9px;border-radius:999px;background:' + color + ';display:inline-block;"></span>' + label + '</span>';
  }

  // altpay_status is only meaningful once a customer has actually
  // completed Step 2 (pending/awaiting_payment rows have no payment
  // method attempt yet). Red "Needs follow-up" is reserved for a REAL
  // recorded failure (altpay_fail_reason) -- a fresh awaiting_payment row
  // is normal, in-progress, not a problem.
  function paymentOnFileCell(r) {
    if (r.status === 'pending') return '—';
    if (r.altpay_status === 'vaulted' && r.altpay_payment_method_summary) {
      return '<span style="color:#1E8A4C;">' + e(r.altpay_payment_method_summary) + '</span>';
    }
    if (r.status === 'awaiting_payment') return '<span style="color:#8A93A3;">Awaiting payment</span>';
    return '<span style="color:#e5534b;">Needs follow-up</span>';
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

  function dashboardHtml() {
    if (state.requestsLoading && state.requests === null) {
      return '<div class="loading">Loading rate sheets…</div>';
    }
    if (state.requestsError) {
      return '<div class="error-banner">' + e(state.requestsError) + '</div>';
    }
    var rows = state.requests || [];
    if (rows.length === 0) {
      return '<div class="empty-state">No rate sheets sent yet.</div>';
    }
    var body = rows.map(function (r) {
      // Only a submitted or failed row has anything to show on the
      // printable "accepted terms" record -- a still-pending row has no
      // signature/timestamp/IP yet, so it's not clickable.
      var clickable = r.status !== 'pending';
      return '<tr' + (clickable ? ' class="row-clickable" data-action="view-detail" data-id="' + r.id + '"' : '') + '>' +
        '<td>' + statusDot(r.status) + '</td>' +
        '<td>' + e(r.prospect_email) + '</td>' +
        '<td>' + e(r.rep_name) + '</td>' +
        '<td>' + fmtDateTime(r.sent_at) + '</td>' +
        '<td>' + e(r.account_kind) + '</td>' +
        '<td>' + e(r.location) + '</td>' +
        '<td>' + paymentOnFileCell(r) + '</td>' +
        '<td>' + (r.invoices_emailed === null ? '—' : (r.invoices_emailed ? 'Yes' : 'No')) + '</td>' +
        '</tr>';
    }).join('');

    return '' +
      '<div class="card">' +
      '  <table class="data-table">' +
      '    <thead><tr><th></th><th>Prospect Email</th><th>Sent By</th><th>Time Sent</th><th>Type</th><th>Location</th><th>Payment On File</th><th>Invoices Emailed</th></tr></thead>' +
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
