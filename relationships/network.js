/* Customer Network page: top-down diagram (network devices -> servers -> computers), a key table, and the
   "replacement opportunity" flags. Data comes from api/automate.php?action=network. */
(function () {
  'use strict';
  var root = document.getElementById('net-root');
  var params = new URLSearchParams(location.search);
  var query = params.get('customer') ? 'customer_id=' + encodeURIComponent(params.get('customer'))
    : params.get('cw_company') ? 'cw_company=' + encodeURIComponent(params.get('cw_company')) : '';
  var activeFlag = '';
  var data = null;

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function msg(html) { root.innerHTML = '<div class="net-msg">' + html + '</div>'; }

  function gb(bytes) { return bytes > 0 ? Math.round(bytes / 1e9) + ' GB' : '-'; }
  function disk(mb) {
    if (!(mb > 0)) return '-';
    var g = mb / 1024;
    return g >= 1000 ? (g / 1024).toFixed(1).replace(/\.0$/, '') + ' TB' : Math.round(g) + ' GB';
  }
  function dateNice(iso) {
    var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso || '');
    if (!m) return '';
    return ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'][+m[2] - 1] + ' ' + (+m[3]) + ', ' + m[1];
  }
  function bar(pct, warnAt, badAt) {
    if (pct == null) return '';
    var cls = pct >= badAt ? 'bad' : pct >= warnAt ? 'warn' : '';
    return '<div class="bar"><i class="' + cls + '" style="width:' + Math.min(100, Math.max(2, pct)) + '%"></i></div>';
  }

  // ---- icons (simple line drawings; colours come from CSS)
  var ICONS = {
    laptop: '<svg viewBox="0 0 52 40"><rect class="fill stroke" x="9" y="5" width="34" height="23" rx="2" stroke-width="2"/><path class="stroke" d="M3 33h46l-4-5H7z" fill="none" stroke-width="2" stroke-linejoin="round"/></svg>',
    desktop: '<svg viewBox="0 0 52 40"><rect class="fill stroke" x="8" y="3" width="36" height="25" rx="2" stroke-width="2"/><path class="stroke" d="M26 28v6M17 36h18" stroke-width="2" fill="none" stroke-linecap="round"/></svg>',
    server: '<svg viewBox="0 0 52 40"><rect class="fill stroke" x="12" y="2" width="28" height="10" rx="2" stroke-width="2"/><rect class="fill stroke" x="12" y="14" width="28" height="10" rx="2" stroke-width="2"/><rect class="fill stroke" x="12" y="26" width="28" height="10" rx="2" stroke-width="2"/><circle class="stroke" cx="18" cy="7" r="1.5"/><circle class="stroke" cx="18" cy="19" r="1.5"/><circle class="stroke" cx="18" cy="31" r="1.5"/></svg>',
    firewall: '<svg viewBox="0 0 52 40"><path class="fill stroke" d="M26 3l15 5v11c0 9-6 15-15 18C17 34 11 28 11 19V8z" stroke-width="2" stroke-linejoin="round"/><path class="stroke" d="M18 20h16M18 14h16M22 26h8" stroke-width="1.6" fill="none"/></svg>',
    switch: '<svg viewBox="0 0 52 40"><rect class="fill stroke" x="3" y="12" width="46" height="16" rx="2" stroke-width="2"/><path class="stroke" d="M10 18h4v6h-4zM17 18h4v6h-4zM24 18h4v6h-4zM31 18h4v6h-4zM38 18h4v6h-4z" fill="none" stroke-width="1.5"/></svg>',
    wifi: '<svg viewBox="0 0 52 40"><path class="stroke" d="M8 17a26 26 0 0 1 36 0M14 23a17 17 0 0 1 24 0M20 29a8 8 0 0 1 12 0" fill="none" stroke-width="2.2" stroke-linecap="round"/><circle class="stroke" cx="26" cy="34" r="2.4"/></svg>',
    router: '<svg viewBox="0 0 52 40"><rect class="fill stroke" x="5" y="18" width="42" height="14" rx="3" stroke-width="2"/><path class="stroke" d="M14 18L10 6M38 18l4-12M26 18V5" stroke-width="2" fill="none" stroke-linecap="round"/><circle class="stroke" cx="14" cy="25" r="1.6"/><circle class="stroke" cx="21" cy="25" r="1.6"/></svg>',
    printer: '<svg viewBox="0 0 52 40"><rect class="fill stroke" x="8" y="14" width="36" height="16" rx="2" stroke-width="2"/><path class="stroke" d="M15 14V5h22v9M15 24h22v10H15z" fill="none" stroke-width="2" stroke-linejoin="round"/></svg>',
    generic: '<svg viewBox="0 0 52 40"><rect class="fill stroke" x="6" y="9" width="40" height="22" rx="3" stroke-width="2"/><circle class="stroke" cx="14" cy="20" r="2"/></svg>'
  };
  function iconFor(d) {
    if (d.kind === 'server') return 'server';
    if (d.kind === 'net') {
      var t = (d.type + ' ' + d.template).toLowerCase();
      if (/firewall/.test(t)) return 'firewall';
      if (/switch|bridge/.test(t)) return 'switch';
      if (/wireless|access point|wifi|wi-fi|\bap\b/.test(t)) return 'wifi';
      if (/router|gateway/.test(t)) return 'router';
      if (/printer/.test(t)) return 'printer';
      return 'generic';
    }
    var s = (d.name + ' ' + d.model).toLowerCase();
    if (/-lt|lap|book|thinkpad|latitude|elitebook|probook|surface|\bnb\b/.test(s)) return 'laptop';
    return 'desktop';
  }
  function modelLine(d) {
    if (d.kind === 'net') return d.template || d.type;
    var m = [d.make, d.model].filter(Boolean).join(' ');
    return m || d.os;
  }

  // ---- numbering: network, servers, computers (same order in the diagram and the tables)
  function numbered() {
    var n = 0;
    ['network', 'servers', 'computers'].forEach(function (k) { data[k].forEach(function (d) { d.no = ++n; }); });
  }

  function tile(d) {
    var flags = d.flags || [];
    var who = d.kind === 'net' ? d.ip : (d.friendly || d.last_user);
    var tip = flags.map(function (f) { return data.flag_labels[f]; }).join(', ');
    return '<div class="net-tile ' + (d.online ? 'on' : 'off') + (flags.length ? ' flag' : '') + '" data-flags="' + esc(flags.join(' ')) + '" title="' + esc(d.name + (tip ? ' - ' + tip : '')) + '">' +
      '<span class="no">' + d.no + '</span><span class="st ' + (d.online ? 'on' : 'off') + '"></span>' +
      '<div class="ico">' + ICONS[iconFor(d)] + '</div>' +
      '<div class="nm">' + esc(d.name) + '</div>' +
      '<div class="md">' + esc(modelLine(d)) + '</div>' +
      (who ? '<div class="who">' + esc(who) + '</div>' : '') +
      (d.online ? '' : '<div class="offtag">OFFLINE' + (d.offline_days >= 1 ? ' · ' + d.offline_days + 'd' : '') + '</div>') +
      (flags.length ? '<span class="bang">!</span>' : '') + '</div>';
  }

  function pyramid() {
    var bands = [];
    if (data.network.length) bands.push('<div class="net-band net"><span class="net-band-label">Network devices</span>' + data.network.map(tile).join('') + '</div>');
    if (data.servers.length) bands.push('<div class="net-band srv"><span class="net-band-label">Servers</span>' + data.servers.map(tile).join('') + '</div>');
    if (data.computers.length) bands.push('<div class="net-band pcs"><span class="net-band-label">Computers</span>' + data.computers.map(tile).join('') + '</div>');
    return '<div class="net-pyramid">' + bands.join('<div class="net-stem"></div>') + '</div>';
  }

  function flagPills(d) {
    return (d.flags || []).map(function (f) {
      return '<span class="flagpill' + (f === 'offline30' || f === 'warranty' ? ' bad' : '') + '">' + esc(data.flag_labels[f]) + '</span>';
    }).join('');
  }

  function warrantyCell(d) {
    if (!d.warranty_end) return '<span class="sub">Not recorded</span>';
    var exp = d.warranty_end < new Date().toISOString().slice(0, 10);
    return '<span class="' + (exp ? 'bad' : '') + '">' + (exp ? 'Expired ' : '') + esc(dateNice(d.warranty_end)) + '</span>';
  }

  function pcRow(d) {
    var storeWarn = d.kind === 'server' ? 80 : 90;
    return '<tr data-flags="' + esc((d.flags || []).join(' ')) + '">' +
      '<td class="n">' + d.no + '</td>' +
      '<td><div class="nm">' + esc(d.name) + '</div><div class="sub">' + (d.online ? '<span class="status-on">Online</span>' : '<span class="status-off">Offline' + (d.offline_days >= 1 ? ' ' + d.offline_days + ' days' : '') + '</span>') + (d.kind === 'server' ? ' · Server' : '') + '</div></td>' +
      '<td>' + (d.friendly ? esc(d.friendly) : '<span class="sub">-</span>') + '</td>' +
      '<td>' + esc(d.last_user || '-') + '</td>' +
      '<td><span class="' + ((d.flags || []).indexOf('os_old') >= 0 ? 'warn' : '') + '">' + esc(d.os || '-') + '</span></td>' +
      '<td>' + esc(d.av || '-') + '</td>' +
      '<td>' + warrantyCell(d) + '</td>' +
      '<td>' + (d.age_years == null ? '-' : '<span class="' + (d.age_years > 3 ? 'warn' : '') + '">' + d.age_years.toFixed(1) + ' yrs</span>') + '</td>' +
      '<td>' + disk(d.storage_mb) + (d.storage_pct != null ? '<div class="sub">' + Math.round(d.storage_pct) + '% used</div>' + bar(d.storage_pct, storeWarn, 90) : '') + '</td>' +
      '<td>' + gb(d.ram_bytes) + (d.ram_pct != null ? '<div class="sub">' + Math.round(d.ram_pct) + '% in use</div>' + bar(d.ram_pct, 80, 90) : '') + '</td>' +
      '<td>' + esc(d.cpu_model || '-') + '</td>' +
      '<td>' + flagPills(d) + '</td></tr>';
  }

  function netRow(d) {
    return '<tr data-flags="' + esc((d.flags || []).join(' ')) + '"><td class="n">' + d.no + '</td>' +
      '<td><div class="nm">' + esc(d.name) + '</div>' + (d.raw_name && d.raw_name !== d.name ? '<div class="sub">' + esc(d.raw_name) + '</div>' : '') + '</td>' +
      '<td>' + esc(d.type || '-') + '</td><td>' + esc(d.template || '-') + '</td><td>' + esc(d.ip || '-') + '</td><td>' + esc(d.location || '') + '</td>' +
      '<td>' + (d.online ? '<span class="status-on">Online</span>' : '<span class="status-off">Offline' + (d.offline_days >= 1 ? ' ' + d.offline_days + ' days' : '') + '</span>') + '</td>' +
      '<td>' + flagPills(d) + '</td></tr>';
  }

  function chips() {
    var order = ['offline30', 'os_old', 'age3', 'warranty', 'ram_hi', 'cpu_hi', 'disk_hi', 'server_disk'];
    return '<div class="net-chips">' + order.map(function (f) {
      var n = data.flag_counts[f] || 0;
      return '<button type="button" class="net-chip' + (n ? ' has' : '') + (f === 'offline30' ? ' bad' : '') + (activeFlag === f ? ' on' : '') + '" data-flag="' + f + '">' + esc(data.flag_labels[f]) + ' <b>' + n + '</b></button>';
    }).join('') + '</div>';
  }

  function historyNote() {
    var h = data.history || {};
    if (!h.max_days) return 'Capacity trends start building from today.';
    if (h.max_days < 7) return 'Capacity history started ' + dateNice(h.first_day) + ' (' + h.max_days + ' day' + (h.max_days === 1 ? '' : 's') + ' so far). RAM and CPU patterns appear after a week of readings and cover up to 90 days.';
    return 'Capacity history since ' + dateNice(h.first_day) + ' (' + h.max_days + ' days). RAM and CPU are flagged when a device is over 90% on at least half of the days measured, within the last 90 days.';
  }

  function render() {
    numbered();
    var cust = data.customer.name;
    document.title = 'Network - ' + cust;
    var all = data.network.length + data.servers.length + data.computers.length;
    var off = [].concat(data.network, data.servers, data.computers).filter(function (d) { return !d.online; }).length;
    var rows = data.servers.concat(data.computers);
    root.innerHTML = '<div class="net-page">' +
      '<div class="net-head"><div><h1>' + esc(cust) + ' — Network</h1>' +
        '<div class="net-sub">' + data.network.length + ' network device' + (data.network.length === 1 ? '' : 's') + ' · ' + data.servers.length + ' server' + (data.servers.length === 1 ? '' : 's') + ' · ' + data.computers.length + ' computer' + (data.computers.length === 1 ? '' : 's') + ' · ' + off + ' offline · as of ' + esc(dateNice(data.generated_at)) + '</div>' +
        '<div class="net-legend"><span><i class="net-dot on"></i>Online</span><span><i class="net-dot off"></i>Offline</span><span>Numbers match the lists below</span></div></div>' +
        '<div class="net-actions"><button class="net-btn primary" data-act="print">Print</button><button class="net-btn" data-act="refresh">Refresh</button></div></div>' +
      '<div class="net-card"><h2>Replacement opportunities</h2>' + chips() +
        '<div class="net-note net-chips-hint">Click a card to highlight just those devices (the diagram and lists both filter).</div>' +
        '<div class="net-note">' + esc(historyNote()) + ' Age is measured from when the device was added to monitoring, which usually tracks the purchase date.</div></div>' +
      '<div class="net-card">' + (all ? pyramid() : '<div class="net-msg">Automate has no devices on file for this customer.</div>') + '</div>' +
      '<div class="net-card tables pb"><h2>Computers &amp; servers — who uses what</h2><div class="net-table-wrap"><table class="net-table"><thead><tr>' +
        '<th>#</th><th>Computer name</th><th>Friendly name</th><th>Last logged-in user</th><th>Operating system</th><th>Anti-virus</th><th>Warranty</th><th>Age</th><th>Storage</th><th>RAM</th><th>Processor</th><th>Attention</th></tr></thead><tbody>' +
        rows.map(pcRow).join('') + '</tbody></table></div></div>' +
      (data.network.length ? '<div class="net-card tables"><h2>Network devices</h2><div class="net-table-wrap"><table class="net-table"><thead><tr><th>#</th><th>Name</th><th>Type</th><th>Make / model</th><th>IP address</th><th>Location</th><th>Status</th><th>Attention</th></tr></thead><tbody>' +
        data.network.map(netRow).join('') + '</tbody></table></div></div>' : '') +
      '</div>';
    applyFilter();
  }

  function applyFilter() {
    var tiles = root.querySelectorAll('.net-tile'), trs = root.querySelectorAll('.net-table tbody tr');
    function match(el) { return !activeFlag || (' ' + el.getAttribute('data-flags') + ' ').indexOf(' ' + activeFlag + ' ') >= 0; }
    Array.prototype.forEach.call(tiles, function (t) { t.classList.toggle('dim', !match(t)); });
    Array.prototype.forEach.call(trs, function (t) { t.classList.toggle('hide', !match(t)); });
    Array.prototype.forEach.call(root.querySelectorAll('.net-chip'), function (c) { c.classList.toggle('on', c.getAttribute('data-flag') === activeFlag); });
  }

  root.addEventListener('click', function (e) {
    var chip = e.target.closest('.net-chip');
    if (chip) { activeFlag = activeFlag === chip.getAttribute('data-flag') ? '' : chip.getAttribute('data-flag'); applyFilter(); return; }
    var act = e.target.closest('[data-act]');
    if (!act) return;
    if (act.getAttribute('data-act') === 'print') window.print();
    if (act.getAttribute('data-act') === 'refresh') load();
  });

  function load() {
    if (!query) { msg('Open this page from a customer in Relationships (Computers &rarr; Show Customer Network).'); return; }
    msg('Loading the network…');
    fetch('api/automate.php?action=network&' + query, { credentials: 'same-origin' })
      .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { return { status: r.status, data: j }; }); })
      .then(function (r) {
        if (r.status === 401) { msg('Please <a href="index.html">sign in to Relationships</a>, then open this page again.'); return; }
        var d = r.data;
        if (!d || !d.ok) { msg(esc((d && d.error) || 'Could not load the network.')); return; }
        if (d.configured === false) { msg('Automate is not connected on this server yet.'); return; }
        if (!d.matched_client) { msg('This customer was not found in Automate.'); return; }
        data = d;
        render();
      })
      .catch(function () { msg('Could not reach the server. Check your connection and try again.'); });
  }
  load();
})();
