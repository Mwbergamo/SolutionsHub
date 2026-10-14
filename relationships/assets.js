/* Asset Assignments page: a graph of a customer's IT Glue contacts; click a person to see the hardware,
   software and licenses assigned to them. Data comes from api/itglue.php?action=assignments.
   Joins done here:
     hardware  = configurations whose contact is this person
     software  = "Software" records tagged to that person's devices (IT Glue tags software to devices, not people)
     licenses  = "Microsoft Licenses" records that tag this person under Licensed Users
     protection= Antivirus / Backup records that tag the device under Protected Devices */
(function () {
  'use strict';
  var root = document.getElementById('as-root');
  var params = new URLSearchParams(location.search);
  var query = params.get('customer') ? 'customer_id=' + encodeURIComponent(params.get('customer'))
    : params.get('cw_company') ? 'cw_company=' + encodeURIComponent(params.get('cw_company')) : '';
  var data = null;
  var people = [];          // contacts with their joined assets
  var selectedId = null;
  var hideEmpty = false;
  var search = '';

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function msg(html) { root.innerHTML = '<div class="as-msg">' + html + '</div>'; }
  function initials(name) {
    var p = String(name || '?').replace(/[^A-Za-z0-9 ]+/g, ' ').trim().split(/\s+/);
    return ((p[0] || '?').charAt(0) + (p.length > 1 ? p[p.length - 1].charAt(0) : '')).toUpperCase();
  }
  function short(s, n) { s = String(s || ''); return s.length > n ? s.slice(0, n - 1) + '…' : s; }
  function dateNice(iso) {
    var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso || '');
    if (!m) return '';
    return ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'][+m[2] - 1] + ' ' + (+m[3]) + ', ' + m[1];
  }
  function warrantyChip(iso) {
    var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso || '');
    if (!m) return '';
    var days = Math.round((new Date(+m[1], +m[2] - 1, +m[3]) - new Date()) / 86400000);
    var cls = days < 0 ? 'bad' : days < 90 ? 'warn' : 'good';
    var label = days < 0 ? 'Warranty expired ' : 'Warranty to ';
    return '<span class="as-chip ' + cls + '">' + label + esc(dateNice(iso)) + '</span>';
  }

  // ---- join the lists into one record per person
  function build() {
    var devById = {}, protByDev = {}, swByDev = {};
    data.devices.forEach(function (d) { devById[d.id] = d; protByDev[d.id] = []; swByDev[d.id] = []; });
    (data.protection || []).forEach(function (p) {
      (p.device_ids || []).forEach(function (id) { if (protByDev[id]) protByDev[id].push(p); });
    });
    (data.software || []).forEach(function (s) {
      (s.device_ids || []).forEach(function (id) { if (swByDev[id]) swByDev[id].push(s); });
    });
    var licByContact = {};
    (data.licenses || []).forEach(function (l) {
      (l.contact_ids || []).forEach(function (id) { (licByContact[id] = licByContact[id] || []).push(l); });
    });
    var devsByContact = {};
    data.devices.forEach(function (d) { if (d.contact_id) (devsByContact[d.contact_id] = devsByContact[d.contact_id] || []).push(d); });

    people = data.contacts.map(function (c) {
      var devs = devsByContact[c.id] || [];
      var seen = {}, sw = [];
      devs.forEach(function (d) {
        swByDev[d.id].forEach(function (s) {
          if (seen[s.id]) { seen[s.id].devices.push(d.name); return; }
          seen[s.id] = { id: s.id, name: s.name, vendor: s.vendor, url: s.url, devices: [d.name] };
          sw.push(seen[s.id]);
        });
      });
      var lic = licByContact[c.id] || [];
      return {
        c: c, devices: devs, software: sw, licenses: lic,
        protection: function (d) { return protByDev[d.id] || []; },
        total: devs.length + sw.length + lic.length
      };
    });
    people.sort(function (a, b) { return a.c.name.localeCompare(b.c.name); });

    // things nobody is assigned to
    var assignedDev = {};
    people.forEach(function (p) { p.devices.forEach(function (d) { assignedDev[d.id] = 1; }); });
    data._unassignedDevices = data.devices.filter(function (d) { return !assignedDev[d.id]; });
    data._sharedLicenses = (data.licenses || []).filter(function (l) { return !(l.contact_ids || []).length; });
    var onPerson = {};
    people.forEach(function (p) { p.software.forEach(function (s) { onPerson[s.id] = 1; }); });
    data._unplacedSoftware = (data.software || []).filter(function (s) { return !onPerson[s.id]; });
    data._swByDev = swByDev; data._protByDev = protByDev;
  }

  // ---- graph: company in the middle, people on rings around it
  function visiblePeople() {
    var q = search.trim().toLowerCase();
    return people.filter(function (p) {
      if (hideEmpty && p.total === 0) return false;
      if (q && (p.c.name + ' ' + p.c.title + ' ' + p.c.email).toLowerCase().indexOf(q) < 0) return false;
      return true;
    });
  }
  function pill(x, y, cls, n) {
    return '<g><rect class="' + cls + '" x="' + (x - 9) + '" y="' + (y - 7) + '" width="18" height="14" rx="4"/>' +
      '<text class="bn" x="' + x + '" y="' + y + '">' + n + '</text></g>';
  }
  function graphSvg(list) {
    var rings = [], placed = 0, k = 0, W;
    while (placed < list.length) {
      var radius = 170 + k * 120;
      var cap = Math.max(6, Math.floor((2 * Math.PI * radius) / 96));
      var n = Math.min(cap, list.length - placed);
      rings.push({ r: radius, n: n, start: placed });
      placed += n; k++;
    }
    var outer = rings.length ? rings[rings.length - 1].r : 170;
    W = (outer + 90) * 2;
    var cx = W / 2, cy = W / 2, edges = '', nodes = '';
    rings.forEach(function (ring, ri) {
      for (var i = 0; i < ring.n; i++) {
        var p = list[ring.start + i];
        var ang = (2 * Math.PI * i) / ring.n - Math.PI / 2 + (ri % 2 ? Math.PI / ring.n : 0);
        var x = cx + ring.r * Math.cos(ang), y = cy + ring.r * Math.sin(ang);
        edges += '<line class="as-edge" x1="' + cx + '" y1="' + cy + '" x2="' + x.toFixed(1) + '" y2="' + y.toFixed(1) + '"/>';
        var badges = '', bx = [];
        if (p.devices.length) bx.push(['b-dev', p.devices.length]);
        if (p.software.length) bx.push(['b-sw', p.software.length]);
        if (p.licenses.length) bx.push(['b-lic', p.licenses.length]);
        bx.forEach(function (b, bi) { badges += pill(x - (bx.length - 1) * 11 + bi * 22, y + 50, b[0], b[1]); });
        nodes += '<g class="as-node' + (p.c.id === selectedId ? ' sel' : '') + (p.total === 0 ? ' empty' : '') + '" data-id="' + p.c.id + '" tabindex="0" role="button" aria-label="' + esc(p.c.name) + '">' +
          '<title>' + esc(p.c.name + (p.c.title ? ' - ' + p.c.title : '')) + '</title>' +
          '<circle class="p" cx="' + x.toFixed(1) + '" cy="' + y.toFixed(1) + '" r="27"/>' +
          '<text class="ini" x="' + x.toFixed(1) + '" y="' + y.toFixed(1) + '">' + esc(initials(p.c.name)) + '</text>' +
          '<text class="nm" x="' + x.toFixed(1) + '" y="' + (y + 41).toFixed(1) + '">' + esc(short(p.c.name, 20)) + '</text>' +
          badges + '</g>';
      }
    });
    var hub = '<g class="as-hub"><circle cx="' + cx + '" cy="' + cy + '" r="62"/>' +
      '<text x="' + cx + '" y="' + (cy - 2) + '">' + esc(short(data.matched_org.name, 16)) + '</text>' +
      '<text x="' + cx + '" y="' + (cy + 16) + '" style="font-size:11px;font-weight:500">' + people.length + ' people</text></g>';
    return '<svg class="as-graph" viewBox="0 0 ' + W + ' ' + W + '" role="group" aria-label="Contacts of ' + esc(data.matched_org.name) + '">' + edges + hub + nodes + '</svg>';
  }

  // ---- detail panel
  function deviceBlock(p, d) {
    var chips = '';
    p.protection(d).forEach(function (pr) {
      chips += '<span class="as-chip good">' + (pr.kind === 'backup' ? 'Backup: ' : 'Antivirus: ') + esc(pr.platform || 'yes') + '</span>';
    });
    var line = [d.type, [d.make, d.model].filter(Boolean).join(' '), d.os].filter(Boolean).join(' · ');
    return '<div class="as-item"><b>' + (d.url ? '<a href="' + esc(d.url) + '" target="_blank" rel="noopener">' + esc(d.name) + '</a>' : esc(d.name)) + '</b>' +
      (line ? '<small>' + esc(line) + '</small>' : '') +
      (d.serial ? '<small>Serial ' + esc(d.serial) + '</small>' : '') +
      '<div>' + warrantyChip(d.warranty_expires) + chips + '</div></div>';
  }
  function itemLink(url, name) { return url ? '<a href="' + esc(url) + '" target="_blank" rel="noopener">' + esc(name) + '</a>' : esc(name); }
  function detailHtml() {
    var p = null;
    people.forEach(function (x) { if (x.c.id === selectedId) p = x; });
    if (!p) return overviewHtml();
    var h = '<div class="as-card"><div class="as-person"><div class="as-avatar">' + esc(initials(p.c.name)) + '</div><div><h2>' + esc(p.c.name) + '</h2>' +
      '<small>' + esc([p.c.title, p.c.type].filter(Boolean).join(' · ')) + '</small></div></div>' +
      (p.c.email ? '<div class="as-sub">' + esc(p.c.email) + '</div>' : '');
    h += '<h3>Hardware (' + p.devices.length + ')</h3>';
    h += p.devices.length ? p.devices.map(function (d) { return deviceBlock(p, d); }).join('') : '<div class="as-empty">No devices assigned to this person in IT Glue.</div>';
    h += '<h3>Software (' + p.software.length + ')</h3>';
    h += p.software.length ? p.software.map(function (s) {
      return '<div class="as-item"><b>' + itemLink(s.url, s.name) + '</b><small>' + esc([s.vendor, 'on ' + s.devices.join(', ')].filter(Boolean).join(' · ')) + '</small></div>';
    }).join('') : '<div class="as-empty">No software is tagged to this person’s devices.</div>';
    h += '<h3>Licenses (' + p.licenses.length + ')</h3>';
    h += p.licenses.length ? p.licenses.map(function (l) {
      return '<div class="as-item"><b>' + itemLink(l.url, l.name) + '</b><small>' + l.consumed + ' of ' + l.active + ' used · ' + l.unused + ' unused</small></div>';
    }).join('') : '<div class="as-empty">No license lists this person under Licensed Users.</div>';
    h += '<button type="button" class="as-btn" data-act="clear" style="margin-top:10px">Back to overview</button></div>';
    return h;
  }
  function overviewHtml() {
    var h = '<div class="as-card"><h2>Not tied to one person</h2>';
    h += '<h3>Devices with no contact (' + data._unassignedDevices.length + ')</h3>';
    h += data._unassignedDevices.length ? data._unassignedDevices.slice(0, 40).map(function (d) {
      return '<div class="as-item"><b>' + itemLink(d.url, d.name) + '</b><small>' + esc([d.type, d.make, d.model].filter(Boolean).join(' · ')) + '</small></div>';
    }).join('') + (data._unassignedDevices.length > 40 ? '<div class="as-empty">+ ' + (data._unassignedDevices.length - 40) + ' more</div>' : '') : '<div class="as-empty">Every device has a contact.</div>';
    h += '<h3>Licenses with no named users (' + data._sharedLicenses.length + ')</h3>';
    h += data._sharedLicenses.length ? data._sharedLicenses.map(function (l) {
      return '<div class="as-item"><b>' + itemLink(l.url, l.name) + '</b><small>' + l.consumed + ' of ' + l.active + ' used</small></div>';
    }).join('') : '<div class="as-empty">None.</div>';
    h += '<h3>Software not on any person’s device (' + data._unplacedSoftware.length + ')</h3>';
    h += data._unplacedSoftware.length ? data._unplacedSoftware.slice(0, 40).map(function (s) {
      return '<div class="as-item"><b>' + itemLink(s.url, s.name) + '</b><small>' + esc(s.vendor || '') + '</small></div>';
    }).join('') : '<div class="as-empty">None.</div>';
    h += '<div class="as-note">Click a person in the graph to see their hardware, software and licenses.</div></div>';
    return h;
  }

  function render() {
    var list = visiblePeople();
    var assigned = people.filter(function (p) { return p.total > 0; }).length;
    var d = data;
    var h = '<div class="as-page"><div class="as-head"><div><h1>Asset Assignments</h1>' +
      '<div class="as-sub">' + esc(d.customer.name) + ' · IT Glue: ' + esc(d.matched_org.name) + ' · refreshed ' + esc(dateNice(d.generated_at)) + '</div></div>' +
      '<div class="as-actions"><button class="as-btn" type="button" data-act="refresh">Refresh from IT Glue</button>' +
      '<button class="as-btn" type="button" data-act="print">Print</button></div></div>';
    h += '<div class="as-stats">' +
      '<div class="as-stat"><b>' + people.length + '</b><span>people</span></div>' +
      '<div class="as-stat"><b>' + assigned + '</b><span>with assets</span></div>' +
      '<div class="as-stat"><b>' + d.devices.length + '</b><span>devices</span></div>' +
      '<div class="as-stat"><b>' + (d.software || []).length + '</b><span>software records</span></div>' +
      '<div class="as-stat"><b>' + (d.licenses || []).length + '</b><span>license records</span></div></div>';
    h += '<div class="as-grid"><div class="as-card"><div class="as-tools">' +
      '<input type="search" id="as-search" placeholder="Find a person…" value="' + esc(search) + '" aria-label="Find a person">' +
      '<label><input type="checkbox" id="as-hide"' + (hideEmpty ? ' checked' : '') + '> Hide people with nothing assigned</label>' +
      '<span class="as-legend"><span><i class="i-dev"></i>Devices</span><span><i class="i-sw"></i>Software</span><span><i class="i-lic"></i>Licenses</span></span></div>' +
      (list.length ? graphSvg(list) : '<div class="as-empty">No one matches.</div>') +
      '<div class="as-note">Software appears for a person when it is tagged to one of their devices in IT Glue. Licenses appear when the person is listed under Licensed Users.</div></div>' +
      '<div id="as-detail">' + detailHtml() + '</div></div></div>';
    var keep = document.activeElement && document.activeElement.id === 'as-search';
    root.innerHTML = h;
    if (keep) { var el = document.getElementById('as-search'); el.focus(); el.setSelectionRange(el.value.length, el.value.length); }
  }

  function select(id) { selectedId = id; render(); }

  root.addEventListener('click', function (e) {
    var node = e.target.closest ? e.target.closest('.as-node') : null;
    if (node) { select(+node.getAttribute('data-id')); return; }
    var btn = e.target.closest ? e.target.closest('[data-act]') : null;
    if (!btn) return;
    var act = btn.getAttribute('data-act');
    if (act === 'clear') select(null);
    else if (act === 'print') window.print();
    else if (act === 'refresh') load(true);
  });
  root.addEventListener('keydown', function (e) {
    if ((e.key === 'Enter' || e.key === ' ') && e.target.classList && e.target.classList.contains('as-node')) {
      e.preventDefault(); select(+e.target.getAttribute('data-id'));
    }
  });
  root.addEventListener('input', function (e) {
    if (e.target.id === 'as-search') { search = e.target.value; render(); }
  });
  root.addEventListener('change', function (e) {
    if (e.target.id === 'as-hide') { hideEmpty = e.target.checked; render(); }
  });

  function load(refresh) {
    if (!query) { msg('Open this page from a customer in Relationships (Computers &rarr; Asset Assignments).'); return; }
    msg('Loading asset assignments…');
    fetch('api/itglue.php?action=assignments&' + query + (refresh ? '&refresh=1' : ''), { credentials: 'same-origin' })
      .then(function (r) {
        if (r.status === 401) { msg('Please <a href="index.html">sign in to Relationships</a>, then open this page again.'); return null; }
        return r.json().catch(function () { return null; });
      })
      .then(function (d) {
        if (d === null) return;
        if (!d || !d.ok) { msg(esc((d && d.error) || 'Could not load asset assignments.')); return; }
        if (d.configured === false) { msg('IT Glue is not connected on this server yet.'); return; }
        if (!d.matched_org) {
          var close = (d.closest || []).map(function (c) { return esc(c.name); }).join(', ');
          msg('<b>' + esc(d.customer.name) + '</b> was not found in IT Glue by name.' + (close ? '<br>Closest names: ' + close + '.' : ''));
          return;
        }
        data = d; build(); render();
      })
      .catch(function () { msg('Could not reach the server. Check your connection and try again.'); });
  }
  load(false);
})();
