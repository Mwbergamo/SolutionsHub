/**
 * call-experience-designer/receipt.js
 *
 * The authenticated review page (added 2026-10-23, per Michael): opens
 * in its own tab when a rep clicks a row in the dashboard (app.js), or
 * from the link in the service@codebluetechnology.com submission-notice
 * email (see api/public.php's call_experience_send_submit_notification()
 * docblock for why that email links HERE rather than to a second public
 * token). Shows company name, sender, status, and all 4 tracks with an
 * inline player + download link and their date-start/date-stop window.
 *
 * Authenticated (signed-in rep, same shared Microsoft 365 SSO as the
 * rest of this app) -- unlike record.html, this is NOT reachable by a
 * bare link/token; api/requests.php's ?action=detail/?action=track_audio
 * both require a signed-in session.
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
      ' at ' + d.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
  }

  function fmtDateOnly(ymd) {
    if (!ymd) return '—';
    // ymd is a plain 'YYYY-MM-DD' from a native date input -- parse as
    // local, not UTC, so it never shifts a day depending on the rep's
    // own timezone.
    var parts = ymd.split('-');
    var d = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
    if (isNaN(d.getTime())) return ymd;
    return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
  }

  // Added 2026-10-27 per Michael: each boundary can now carry an
  // 'HH:MM' (24-hour, from <input type="time">) time alongside its
  // date -- combines both into one local-time Date (same local, not
  // UTC, parsing as fmtDateOnly above) and renders the time portion in
  // the rep's own locale, which for en-US shows hour:minute with AM/PM.
  // A date with no time shows the date alone.
  function fmtDateAndTime(ymd, hm) {
    if (!ymd) return null;
    var dParts = ymd.split('-');
    var hh = 0, mm = 0;
    if (hm) {
      var tParts = hm.split(':');
      hh = parseInt(tParts[0], 10) || 0;
      mm = parseInt(tParts[1], 10) || 0;
    }
    var d = new Date(parseInt(dParts[0], 10), parseInt(dParts[1], 10) - 1, parseInt(dParts[2], 10), hh, mm);
    if (isNaN(d.getTime())) return ymd;
    return hm
      ? d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }) +
        ' at ' + d.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' })
      : fmtDateOnly(ymd);
  }

  function formatBytes(n) {
    if (n == null) return '';
    if (n < 1024) return n + ' B';
    if (n < 1024 * 1024) return (n / 1024).toFixed(0) + ' KB';
    return (n / (1024 * 1024)).toFixed(1) + ' MB';
  }

  function renderError(message, showDashboardLink) {
    root.innerHTML = '<div class="error-banner">' + e(message) +
      (showDashboardLink ? ' <a href="index.html" style="color:#8a2a24;font-weight:700;text-decoration:underline;">Open the dashboard instead →</a>' : '') +
      '</div>';
  }

  function trackCardHtml(id, t) {
    var startLabel = fmtDateAndTime(t.date_start, t.time_start);
    var stopLabel = fmtDateAndTime(t.date_stop, t.time_stop);
    var dateRange = (startLabel || stopLabel)
      ? 'In use ' + (startLabel || 'any time') + ' → ' + (stopLabel || 'until further notice')
      : 'No date/time window set — always in use';

    var body;
    if (!t.has_file) {
      body = '<span class="no-file">Not recorded or uploaded yet.</span>';
    } else {
      var src = 'api/requests.php?action=track_audio&id=' + encodeURIComponent(id) + '&track=' + encodeURIComponent(t.track_key);
      var downloadSrc = src + '&download=1';
      var sourceLabel = t.source === 'recorded' ? 'Recorded live' : 'Uploaded' + (t.original_filename ? ': ' + e(t.original_filename) : '');
      var size = t.size_bytes ? ' (' + formatBytes(t.size_bytes) + ')' : '';
      body = '<audio controls preload="none" src="' + src + '"></audio>' +
        '<span style="font-size:12px;color:#5A6472;">' + sourceLabel + size + '</span>' +
        '<a class="download-link" href="' + downloadSrc + '">Download .wav</a>';
    }

    return '' +
      '<div class="track-card">' +
      '  <div class="track-name">' + e(t.label) + '</div>' +
      '  <div class="track-dates">' + e(dateRange) + '</div>' +
      '  <div class="track-body">' + body + '</div>' +
      '</div>';
  }

  function render(r) {
    var statusBadge = r.status === 'submitted'
      ? '<span class="status-badge status-submitted">Submitted</span>'
      : '<span class="status-badge status-pending">Awaiting Customer</span>';

    root.innerHTML = '' +
      '<div class="sheet">' +
      '  <div class="letterhead">' +
      '    <div class="name">CodeBlue Technology</div>' +
      '    <div class="doc-title">Call In Experience Submission<br>' + statusBadge + '</div>' +
      '  </div>' +

      '  <div class="section-title">Request</div>' +
      '  <div class="info-grid">' +
      '    <div><span class="k">Company:</span> <span class="v">' + e(r.company_name || '—') + '</span></div>' +
      '    <div><span class="k">Customer Email:</span> <span class="v">' + e(r.customer_email) + '</span></div>' +
      '    <div><span class="k">Sent By:</span> <span class="v">' + e(r.rep_name) + ' (' + e(r.rep_email) + ')</span></div>' +
      '    <div><span class="k">Link Sent:</span> <span class="v">' + fmtDateTime(r.sent_at) + '</span></div>' +
      '    <div><span class="k">Submitted:</span> <span class="v">' + fmtDateTime(r.submitted_at) + '</span></div>' +
      '  </div>' +

      '  <div class="section-title">Messages</div>' +
      r.tracks.map(function (t) { return trackCardHtml(r.id, t); }).join('') +
      '</div>';
  }

  if (!id) {
    // A missing ?id= here most often means a link scanner/security
    // gateway stripped the query string from the notification email
    // (this app's own templates always include it -- see
    // api/public.php's call_experience_send_submit_notification()) --
    // the dashboard link below is always a safe fallback.
    renderError('No request specified.', true);
    return;
  }

  apiGet('api/requests.php?action=detail&id=' + encodeURIComponent(id)).then(function (res) {
    if (!res.data || !res.data.ok) {
      if (res.status === 401) {
        window.location.href = 'login.html?next=' + encodeURIComponent('receipt.html?id=' + id);
        return;
      }
      renderError((res.data && res.data.error) || 'Could not load this request.');
      return;
    }
    var r = res.data.request;
    r.id = id;
    render(r);
  }).catch(function () {
    renderError('Could not reach the server — check your connection and try again.');
  });
})();
