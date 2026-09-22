/**
 * ratesheet/receipt.js
 *
 * The printable "accepted terms" record (added 2026-09-17, follow-up),
 * per Michael: opens in its own tab when a rep clicks a row in the
 * dashboard, shows the legal text + checkbox + signature + timestamp +
 * IP address the customer submitted, and prints cleanly to one 8.5x11"
 * sheet (see receipt.html's @media print rules).
 *
 * Authenticated (signed-in rep, same shared Microsoft 365 SSO as the
 * rest of this app) -- unlike signup.html, this is NOT reachable by a
 * bare link/token, since it's an internal audit record, not something to
 * hand a customer. api/requests.php's ?action=detail enforces the same
 * admin/own-rows visibility rule as the dashboard list.
 */

(function () {
  'use strict';

  var root = document.getElementById('app-root');
  var params = new URLSearchParams(window.location.search);
  var id = params.get('id') || '';

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

  function fmtDateTime(iso) {
    if (!iso) return '—';
    var d = new Date(iso.indexOf('Z') === -1 && iso.indexOf('+') === -1 ? iso + 'Z' : iso);
    if (isNaN(d.getTime())) return iso;
    return d.toLocaleDateString(undefined, { month: 'long', day: 'numeric', year: 'numeric' }) +
      ' at ' + d.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit', second: '2-digit' }) +
      ' ' + Intl.DateTimeFormat().resolvedOptions().timeZone;
  }

  function renderError(message) {
    root.innerHTML = '<div class="error-banner">' + e(message) + '</div>';
  }

  function render(r) {
    var fullName = (r.first_name || '') + ' ' + (r.last_name || '');
    var fullAddress = [r.address_line1, r.address_line2].filter(Boolean).join(', ') +
      (r.city ? ', ' + r.city : '') + (r.state ? ', ' + r.state : '') + (r.zip ? ' ' + r.zip : '');
    var locationLabel = r.location === 'Richmond' ? 'Richmond' : 'Northern Neck (Warsaw)';
    var paymentLabel = r.payment_method === 'ach' ? 'ACH (Bank Transfer)' : r.payment_method === 'card' ? 'Credit Card' : '—';
    // payment_status is computed server-side from a live ConnectWise
    // lookup (api/requests.php's ratesheet_payment_status()) -- see
    // Michael's 2026-09-18 redesign in public.php's header. 'sent' never
    // reaches this page (a still-pending row 409s before render() runs).
    // Explicit branch per known state, added 2026-09-22 -- previously
    // this was a two-way ternary that silently fell through to "Account
    // Creation Failed" for anything that wasn't 'payment_added' or
    // 'signed', which would have mislabeled the new 'hold_not_set' state.
    // See ratesheet_payment_status()'s docblock in api/requests.php for
    // why 'hold_not_set' is its own state, never folded into
    // 'payment_added': a live status off Credit Hold only means Invoicing
    // released it on purpose if this app actually got it onto Credit Hold
    // at signup in the first place.
    var statusBadge = r.payment_status === 'payment_added'
      ? '<span class="status-badge status-payment-added">Submitted &amp; Payment Added</span>'
      : r.payment_status === 'signed'
      ? '<span class="status-badge status-signed">Submitted — Awaiting Payment</span>'
      : r.payment_status === 'hold_not_set'
      ? '<span class="status-badge status-hold-not-set">Submitted — Credit Hold Not Set</span>'
      : '<span class="status-badge status-failed">Submitted — Account Creation Failed</span>';

    root.innerHTML = '' +
      '<div class="sheet">' +
      '  <div class="letterhead">' +
      '    <div class="name">CodeBlue Technology</div>' +
      '    <div class="doc-title">Accepted Rate Sheet Terms<br>' + statusBadge + '</div>' +
      '  </div>' +

      '  <div class="section-title">Customer</div>' +
      '  <div class="info-grid">' +
      '    <div><span class="k">Name:</span> <span class="v">' + e(fullName.trim()) + '</span></div>' +
      '    <div><span class="k">Email:</span> <span class="v">' + e(r.customer_email) + '</span></div>' +
      '    <div><span class="k">Phone:</span> <span class="v">' + e(r.phone || '—') + '</span></div>' +
      (r.business_name ? '    <div><span class="k">Business:</span> <span class="v">' + e(r.business_name) + '</span></div>' : '') +
      '    <div><span class="k">Account Type:</span> <span class="v">' + e(r.account_kind) + '</span></div>' +
      '    <div><span class="k">Address:</span> <span class="v">' + e(fullAddress) + '</span></div>' +
      '    <div><span class="k">Rate:</span> <span class="v">$' + r.hourly_rate.toFixed(2) + '/hr — ' + e(locationLabel) + '</span></div>' +
      '    <div><span class="k">Payment Method (Selected):</span> <span class="v">' + e(paymentLabel) + '</span></div>' +
      '    <div><span class="k">ConnectWise Billing Status:</span> <span class="v">' + (r.live_billing_status ? e(r.live_billing_status) : (r.status === 'submitted' ? '<span style="color:#8A93A3;">unavailable right now</span>' : '—')) + '</span></div>' +
      '    <div><span class="k">Emailed Invoices:</span> <span class="v">' + (r.invoices_emailed ? 'Yes' : 'No') + '</span></div>' +
      '    <div><span class="k">Sent By:</span> <span class="v">' + e(r.rep_name) + '</span></div>' +
      (r.cw_company_id ? '    <div><span class="k">ConnectWise:</span> <span class="v">Company #' + r.cw_company_id + ' / Contact #' + r.cw_contact_id + '</span></div>' : '') +
      (r.credit_hold_status === 'lookup_failed' ? '    <div><span class="k">Credit Hold:</span> <span class="v" style="color:#A6362B;">Could not be set automatically at signup — verify manually in ConnectWise</span></div>' : '') +
      '  </div>' +

      (r.status === 'failed' && r.fail_reason ? '  <div class="section-title">Note</div><div style="font-size:11.5px;color:#A6362B;">Account creation in ConnectWise failed at submission time — a CodeBlue Technology team member needs to finish this manually. (' + e(r.fail_reason) + ')</div>' : '') +

      '  <div class="section-title">Terms &amp; Conditions Presented At Signing</div>' +
      '  <div class="legal-block">' + e(r.legal_text) + '</div>' +

      '  <div class="checkbox-block">' +
      '    <span class="box">' + (r.agreed_to_terms ? '✓' : '') + '</span>' +
      '    <span>' + e(r.checkbox_text) + '</span>' +
      '  </div>' +

      '  <div class="sig-block">' +
      '    <div class="sig-image">' + (r.signature_data ? '<img src="' + e(r.signature_data) + '" alt="Customer signature" />' : '<div style="color:#8A93A3;font-size:11px;">No signature on file</div>') + '</div>' +
      '    <div class="sig-meta">' +
      '      Signed: ' + fmtDateTime(r.signed_at) + '<br>' +
      '      IP Address: ' + e(r.ip_address || 'not recorded') +
      '    </div>' +
      '  </div>' +
      '</div>';
  }

  if (!id) {
    renderError('No rate sheet specified.');
    return;
  }

  apiGet('api/requests.php?action=detail&id=' + encodeURIComponent(id)).then(function (res) {
    if (!res.data || !res.data.ok) {
      if (res.status === 401) {
        window.location.href = 'login.html?next=' + encodeURIComponent('receipt.html?id=' + id);
        return;
      }
      renderError((res.data && res.data.error) || 'Could not load this record.');
      return;
    }
    render(res.data.request);
  }).catch(function () {
    renderError('Could not reach the server — check your connection and try again.');
  });
})();
