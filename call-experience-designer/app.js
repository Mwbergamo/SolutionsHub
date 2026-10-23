/**
 * call-experience-designer/app.js
 *
 * Call In Experience Designer — rep-facing app (added 2026-10-23, per
 * Michael). Same self-contained vanilla-JS pattern as ratesheet/app.js,
 * register/app.js, relationships/app.js: a state object + string-
 * template rendering, no framework, sign-in gate via the shared
 * Microsoft 365 SSO.
 *
 * Two views:
 *   'send'      — the send-a-link form (just the customer's email --
 *                 there's no Sending Representative picker here, unlike
 *                 ratesheet; the signed-in rep IS the sender, see
 *                 api/requests.php's ?action=send).
 *   'dashboard' — list of every Call In Experience request ever sent,
 *                 to EVERY signed-in rep (no admin/roster split, per
 *                 Michael, 2026-10-23) with a status dot and a "x/4"
 *                 recorded-tracks pill. Clicking any row opens
 *                 receipt.html in a new tab to review/play/download
 *                 whatever has been recorded or uploaded so far, whether
 *                 the customer has submitted yet or not.
 */

(function () {
  'use strict';

  var HUB_URL = '../index.html';

  var root = document.getElementById('app-root');

  var state = {
    user: null,
    error: null,
    view: 'send',

    sendForm: { customer_email: '' },
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
        state.requestsError = (r.data && r.data.error) || 'Could not load requests.';
      }
      render();
    }).catch(function () {
      state.requestsLoading = false;
      state.requestsError = 'Could not load requests — check your connection and try again.';
      render();
    });
  }

  function submitSendForm() {
    var f = state.sendForm;
    if (!f.customer_email.trim()) {
      state.sendError = 'Please enter the customer’s email address.';
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
        state.sendSuccess = 'Recording link sent to ' + f.customer_email + '.';
        state.sendForm = { customer_email: '' };
        loadRequests();
      } else {
        state.sendError = (r.data && r.data.error) || 'Could not send the recording link.';
      }
      render();
    }).catch(function () {
      state.sending = false;
      state.sendError = 'Could not send the recording link — check your connection and try again.';
      render();
    });
  }

  // ---- Render ----------------------------------------------------------

  function statusDot(status) {
    var colors = { pending: '#e5534b', submitted: '#2ecc71' };
    var labels = { pending: 'Awaiting Customer', submitted: 'Submitted' };
    var color = colors[status] || '#8A93A3';
    var label = labels[status] || 'Unknown';
    return '<span style="display:inline-flex;align-items:center;gap:6px;font-size:12.5px;font-weight:700;color:' + color + ';">' +
      '<span style="width:9px;height:9px;border-radius:999px;background:' + color + ';display:inline-block;"></span>' + label + '</span>';
  }

  function topbarHtml() {
    return '' +
      '<div class="topbar">' +
      '  <div class="topbar-left">' +
      '    <div>' +
      '      <div class="brand">Call In Experience Designer</div>' +
      '      <div class="brand-sub">CodeBlue Technology</div>' +
      '    </div>' +
      '    <div class="topbar-nav">' +
      '      <button class="nav-btn ' + (state.view === 'send' ? 'active' : '') + '" data-action="nav-send">Send Link</button>' +
      '      <button class="nav-btn ' + (state.view === 'dashboard' ? 'active' : '') + '" data-action="nav-dashboard">All Requests</button>' +
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
    return '' +
      '<div class="card form-card">' +
      '  <div class="card-title">Send a Call In Experience Link</div>' +
      (state.sendError ? '<div class="error-banner">' + e(state.sendError) + '</div>' : '') +
      (state.sendSuccess ? '<div class="success-banner">' + e(state.sendSuccess) + '</div>' : '') +
      '  <label class="field-label">Customer Email Address</label>' +
      '  <input class="text-input" type="email" placeholder="customer@example.com" value="' + e(f.customer_email) + '" data-field="customer_email" data-action="field-input" />' +
      '  <button class="primary-btn" data-action="send-submit" ' + (state.sending ? 'disabled' : '') + '>' + (state.sending ? 'Sending…' : 'Send') + '</button>' +
      '</div>';
  }

  function dashboardHtml() {
    if (state.requestsLoading && state.requests === null) {
      return '<div class="loading">Loading requests…</div>';
    }
    if (state.requestsError) {
      return '<div class="error-banner">' + e(state.requestsError) + '</div>';
    }
    var rows = state.requests || [];
    if (rows.length === 0) {
      return '<div class="empty-state">No Call In Experience links sent yet.</div>';
    }
    var body = rows.map(function (r) {
      return '<tr class="row-clickable" data-action="view-detail" data-id="' + r.id + '">' +
        '<td>' + statusDot(r.status) + '</td>' +
        '<td>' + e(r.customer_email) + '</td>' +
        '<td>' + e(r.company_name || '—') + '</td>' +
        '<td>' + e(r.rep_name) + '</td>' +
        '<td>' + fmtDateTime(r.sent_at) + '</td>' +
        '<td>' + fmtDateTime(r.submitted_at) + '</td>' +
        '<td><span class="track-progress-pill">' + r.tracks_recorded + '/' + r.tracks_total + '</span></td>' +
        '</tr>';
    }).join('');

    return '' +
      '<div class="card">' +
      '  <table class="data-table">' +
      '    <thead><tr><th></th><th>Customer Email</th><th>Company</th><th>Sent By</th><th>Time Sent</th><th>Submitted</th><th>Tracks</th></tr></thead>' +
      '    <tbody>' + body + '</tbody>' +
      '  </table>' +
      '  <div class="table-hint">Click any row to review, play, and download what’s been recorded or uploaded so far.</div>' +
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

  boot();
})();
