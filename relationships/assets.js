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
  var automate = [];        // Automate computers (name, last_user, type) used to guess who uses a device
  var automateByKey = {};   // alnum(device name) -> automate computer row, set by assignDevices(); the
                             // Managed Services column reads a device's Workstation/Server type from this.
  var linkMap = {};         // device id -> manual link made in Relationships
  var flash = '';
  var overrides = {};       // "contactId|columnKey" -> {value, set_by_name, set_at} (asset_overrides)
  var contactStatus = {};   // contactId -> {inactive, set_by_name, set_at} (asset_contact_status)
  var serviceCoverage = []; // [{slug,label,scope,qty,n,full}] -- this customer's real managed-service
                             // entitlements (ConnectWise Agreement) matched against Automate's device
                             // counts; computed by buildServiceCoverage().

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

  // ---- who uses each device: manual link > IT Glue contact > Automate last user (unique match only)
  function alnum(s) { return String(s || '').toLowerCase().replace(/[^a-z0-9]/g, ''); }
  function userKey(u) { return alnum(String(u || '').replace(/^.*[\\\/]/, '').replace(/@.*$/, '')); }
  function contactKeys(c) {
    var keys = [], parts = String(c.name || '').trim().split(/\s+/);
    if (parts.length > 1) keys.push(alnum(parts[0].charAt(0) + parts[parts.length - 1]));
    var em = alnum(String(c.email || '').split('@')[0]);
    if (em) keys.push(em);
    return keys;
  }
  function setLinks(list) {
    linkMap = {};
    (list || []).forEach(function (l) { linkMap[l.device_id] = l; });
  }
  function assignDevices() {
    var byContact = {}, contactById = {}, keyMap = {}, comps = {};
    data.contacts.forEach(function (c) {
      contactById[c.id] = c;
      contactKeys(c).forEach(function (k) {
        keyMap[k] = keyMap[k] || [];
        if (keyMap[k].indexOf(c) < 0) keyMap[k].push(c);
      });
    });
    automate.forEach(function (a) { var k = alnum(a.name); if (k) comps[k] = a; });
    automateByKey = comps; // expose for managedServicesCell()'s device-type lookups
    data.devices.forEach(function (d) {
      d._src = ''; d._contact = null; d._cands = []; d._lastUser = ''; d._link = null;
      var comp = comps[alnum(d.name)] || comps[alnum(String(d.hostname || '').split('.')[0])];
      if (comp) d._lastUser = comp.last_user || '';
      var l = linkMap[d.id], c = null;
      if (l && contactById[l.contact_id]) { c = contactById[l.contact_id]; d._src = 'manual'; d._link = l; }
      else if (d.contact_id && contactById[d.contact_id]) { c = contactById[d.contact_id]; d._src = 'itglue'; }
      else if (d._lastUser) {
        var m = keyMap[userKey(d._lastUser)] || [];
        if (m.length === 1) { c = m[0]; d._src = 'username'; }
        else if (m.length > 1) d._cands = m;
      }
      d._contact = c;
      if (c) (byContact[c.id] = byContact[c.id] || []).push(d);
    });
    return byContact;
  }
  function assignHtml(d, label) {
    var opts = '<option value="">' + esc(label) + '</option>' + data.contacts.slice().sort(function (a, b) { return a.name.localeCompare(b.name); }).map(function (c) {
      return '<option value="' + c.id + '">' + esc(c.name) + '</option>';
    }).join('');
    return '<div class="as-assign"><select data-assign="' + d.id + '" aria-label="Assign ' + esc(d.name) + ' to a person">' + opts + '</select>' +
      (d._link ? '<button type="button" class="as-link" data-act="unlink" data-dev="' + d.id + '">Remove manual link</button>' : '') + '</div>';
  }
  function sourceHtml(d) {
    if (d._src === 'manual') return '<span class="as-chip manual">Manual link' + (d._link && d._link.linked_by ? ' by ' + esc(d._link.linked_by) : '') + '</span>';
    if (d._src === 'username') return '<span class="as-chip auto">Matched by last user: ' + esc(d._lastUser) + '</span>';
    return '';
  }
  function postLink(action, payload) {
    fetch('api/itglue.php?action=' + action + '&' + query, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'fetch' },
      body: JSON.stringify(payload)
    }).then(function (r) { return r.json().catch(function () { return null; }); })
      .then(function (j) {
        if (j && j.ok) { flash = ''; setLinks(j.links); build(); }
        else flash = (j && j.error) || 'Could not save that change.';
        render();
      })
      .catch(function () { flash = 'Could not reach the server. The change was not saved.'; render(); });
  }

  // ---- Users & Assigned Services grid (added 2026-10-15, Managed Services column added
  // 2026-10-15 per Michael): a spreadsheet-style row-per-person view under the web, with
  // Computer / Microsoft License / Managed Services columns. Every cell writes through
  // api/itglue.php's override_set (or link_set for Computer, or contact_toggle_inactive for
  // Status), which ALSO logs a to-do under this customer -- IT Glue's API is read-only (see
  // itglue.php's header), so the to-do is what actually drives the follow-through in Microsoft
  // 365 / Automate / the firewall, not a live push back to IT Glue. Managed Services replaced
  // the Software column and the four manual columns (Firewall/VPN/DUO/Guardz) with real data --
  // see buildServiceCoverage() below.
  function overrideKey(contactId, columnKey) { return contactId + '|' + columnKey; }
  function setOverrides(list) {
    overrides = {};
    (list || []).forEach(function (o) { overrides[overrideKey(o.contact_id, o.column_key)] = o; });
  }
  function setContactStatus(list) {
    contactStatus = {};
    (list || []).forEach(function (s) { contactStatus[s.contact_id] = s; });
  }
  function isInactive(contactId) { return !!(contactStatus[contactId] && contactStatus[contactId].inactive); }
  function overrideValue(contactId, columnKey) {
    var o = overrides[overrideKey(contactId, columnKey)];
    return o ? o.value : '';
  }
  // Microsoft License options, enriched with IT Glue's synced seat counts (Active/Consumed/
  // Unused) so a rep has real context when picking a tier by hand. These counts are ORG-WIDE
  // totals -- IT Glue's "Licensed Users" tag (which would say WHICH person holds a seat) isn't
  // populated for any license, at any customer, as of 2026-10-15 (confirmed live against several
  // real orgs) -- so there is no per-person baseline to show here, only an informed manual pick.
  function licenseOptionsWithCounts() {
    return (data.licenses || []).map(function (l) {
      var big = l.active >= 1000; // treat as "effectively unlimited" (free/trial SKUs often report active:10000)
      return { name: l.name, label: l.name + ' \u2014 ' + l.consumed + ' used' + (big ? '' : ', ' + l.unused + ' free') };
    });
  }
  function postOverride(contactId, columnKey, columnLabel, value, previousValue) {
    fetch('api/itglue.php?action=override_set&' + query, {
      method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'fetch' },
      body: JSON.stringify({ contact_id: contactId, column_key: columnKey, column_label: columnLabel, value: value, previous_value: previousValue })
    }).then(function (r) { return r.json().catch(function () { return null; }); })
      .then(function (j) {
        if (j && j.ok) { flash = ''; setOverrides(j.overrides); } else flash = (j && j.error) || 'Could not save that change.';
        render();
      })
      .catch(function () { flash = 'Could not reach the server. The change was not saved.'; render(); });
  }
  function postInactiveToggle(contactId) {
    fetch('api/itglue.php?action=contact_toggle_inactive&' + query, {
      method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'fetch' },
      body: JSON.stringify({ contact_id: contactId })
    }).then(function (r) { return r.json().catch(function () { return null; }); })
      .then(function (j) {
        if (j && j.ok) { flash = ''; setContactStatus(j.contact_status); } else flash = (j && j.error) || 'Could not save that change.';
        render();
      })
      .catch(function () { flash = 'Could not reach the server. The change was not saved.'; render(); });
  }
  function computerCell(p) {
    var h = '<div class="as-cell">';
    h += p.devices.length ? p.devices.map(function (d) { return '<div>' + itemLink(d.url, d.name) + '</div>'; }).join('') : '<small class="as-hint">No PC assigned</small>';
    var unassigned = data._unassignedDevices || [];
    if (unassigned.length) {
      h += '<select data-assign-contact="' + p.c.id + '" aria-label="Assign a PC to ' + esc(p.c.name) + '"><option value="">+ Assign a PC…</option>' +
        unassigned.slice().sort(function (a, b) { return a.name.localeCompare(b.name); }).map(function (d) {
          return '<option value="' + d.id + '">' + esc(d.name) + '</option>';
        }).join('') + '</select>';
    }
    return h + '</div>';
  }
  function licenseCell(p) {
    var val = overrideValue(p.c.id, 'microsoft_license');
    var opts = licenseOptionsWithCounts();
    var optsHtml = ['<option value="">\u2014 not set \u2014</option>'].concat(opts.map(function (o) {
      return '<option value="' + esc(o.name) + '"' + (o.name === val ? ' selected' : '') + '>' + esc(o.label) + '</option>';
    }));
    if (val && !opts.some(function (o) { return o.name === val; })) {
      optsHtml.push('<option value="' + esc(val) + '" selected>' + esc(val) + '</option>');
    }
    var hint = opts.length
      ? 'Seat counts are IT Glue\u2019s synced totals, not tied to this person yet \u2014 pick the tier that applies.'
      : 'IT Glue has no Microsoft License records synced for this organization yet.';
    return '<div class="as-cell"><select data-override-contact="' + p.c.id + '" data-override-col="microsoft_license" data-override-label="Microsoft License" data-override-prev="' + esc(val) + '" aria-label="Microsoft License for ' + esc(p.c.name) + '">' +
      optsHtml.join('') + '</select><small class="as-hint">' + esc(hint) + '</small></div>';
  }
  // ---- Managed Services column (added 2026-10-15 per Michael): sourced from two real systems --
  // the customer's ConnectWise Agreement (data.customer_services: what was actually purchased,
  // with a quantity) and Automate RMM (the live device list, with a Workstation/Server type).
  // Neither system records which SPECIFIC device a purchased seat belongs to, so: when a
  // service's purchased quantity covers every device of the type it applies to, it's shown
  // automatically (a plain tag) on all of them; when it's only partial (e.g. EDR bought for 2 of
  // 9 workstations), there is no real signal for which 2 -- so it's a checkbox the rep sets by
  // hand per device, which writes through the same override + to-do pattern as every other cell.
  var SERVICE_FAMILIES = [
    { slug: 'maintenance', label: 'Managed Maintenance', match: /\b(workstation|server)\s+maintenance\b/i },
    { slug: 'patch', label: 'Patch Management', match: /patch management/i },
    { slug: 'agent', label: 'RMM Access Agent', match: /\baccess agent\b/i },
    { slug: 'edr', label: 'Managed Antivirus / EDR', match: /\bedr\b|anti-?virus/i },
    { slug: 'backup-device', label: 'Managed Backup', match: /backup for (workstations|servers)\b/i }
  ];
  function serviceScope(label) {
    if (/\bserver\b/i.test(label)) return 'server';
    if (/\b(desktop|workstation)s?\b/i.test(label)) return 'workstation';
    return 'all';
  }
  function automateTypeCounts() {
    var n = { server: 0, workstation: 0, all: 0 };
    automate.forEach(function (a) {
      n.all++;
      if (/server/i.test(a.type || '')) n.server++; else n.workstation++;
    });
    return n;
  }
  // Matches customer_services line items to SERVICE_FAMILIES and checks the purchased quantity
  // against Automate's real device counts. "full" means the quantity covers every device of that
  // scope (safe to auto-show on all of them); anything less is "partial" -- there's no signal for
  // which specific devices, so those become manual checkboxes instead (see managedServicesCell).
  function buildServiceCoverage() {
    var counts = automateTypeCounts();
    var totals = {}; // "slug|scope" -> qty
    (data.customer_services || []).forEach(function (row) {
      SERVICE_FAMILIES.forEach(function (f) {
        if (!f.match.test(row.label)) return;
        var scope = serviceScope(row.label);
        var key = f.slug + '|' + scope;
        totals[key] = (totals[key] || 0) + (Number(row.qty) || 0);
      });
    });
    serviceCoverage = Object.keys(totals).map(function (key) {
      var parts = key.split('|'), slug = parts[0], scope = parts[1];
      var fam = SERVICE_FAMILIES.filter(function (f) { return f.slug === slug; })[0];
      var n = scope === 'server' ? counts.server : scope === 'workstation' ? counts.workstation : counts.all;
      return { slug: slug, scope: scope, label: fam.label, qty: totals[key], n: n, full: n > 0 && totals[key] >= n };
    }).filter(function (c) { return c.n > 0; });
  }
  function automateFor(d) {
    return automateByKey[alnum(d.name)] || automateByKey[alnum(String(d.hostname || '').split('.')[0])] || null;
  }
  function managedServicesCell(p) {
    if (!p.devices.length) return '<div class="as-cell"><small class="as-hint">No PC assigned</small></div>';
    return '<div class="as-cell">' + p.devices.map(function (d) {
      var a = automateFor(d);
      if (!a) {
        return '<div class="as-svc-device">' + (p.devices.length > 1 ? '<small>' + esc(d.name) + '</small>' : '') + '<small class="as-hint">Not RMM-tracked yet \u2014 no device type to match services against.</small></div>';
      }
      var isServer = /server/i.test(a.type || '');
      var applicable = serviceCoverage.filter(function (c) { return c.scope === 'all' || (c.scope === 'server') === isServer; });
      var chips = applicable.filter(function (c) { return c.full; }).map(function (c) {
        return '<span class="as-chip good" title="' + esc(c.qty + ' of ' + c.n + ' covered by the agreement') + '">' + esc(c.label) + '</span>';
      }).join('');
      var manual = applicable.filter(function (c) { return !c.full; }).map(function (c) {
        var key = 'svc_' + c.slug + '_' + c.scope;
        var val = overrideValue(p.c.id, key);
        var on = val === 'Assigned';
        return '<label class="as-svc-toggle"><input type="checkbox" data-override-contact="' + p.c.id + '" data-override-col="' + esc(key) + '" data-override-label="' + esc(c.label) + '" data-override-prev="' + esc(val) + '"' + (on ? ' checked' : '') + '> ' + esc(c.label) + ' <small>(' + c.qty + ' of ' + c.n + ' on the agreement)</small></label>';
      }).join('');
      return '<div class="as-svc-device">' + (p.devices.length > 1 ? '<small>' + esc(d.name) + '</small>' : '') +
        (chips ? '<div class="as-svc-chips">' + chips + '</div>' : '') +
        (manual ? '<div class="as-svc-manual">' + manual + '</div>' : '') +
        (!chips && !manual ? '<small class="as-hint">No managed services matched on the agreement.</small>' : '') +
        '</div>';
    }).join('') + '</div>';
  }
  // Partial-coverage services, for the banner above the grid -- lets a rep see at a glance which
  // services aren't on everyone before scanning the whole table for checkboxes.
  function coverageBannerHtml() {
    var partial = serviceCoverage.filter(function (c) { return !c.full; });
    if (!partial.length) return '';
    return '<div class="as-note as-svc-banner"><b>Not fully covered by the agreement:</b> ' +
      partial.map(function (c) { return esc(c.label) + ' (' + c.qty + ' of ' + c.n + (c.scope !== 'all' ? ' ' + c.scope + 's' : ' devices') + ')'; }).join(', ') +
      ' \u2014 check the specific device(s) in the Managed Services column below.</div>';
  }
  function statusButtonHtml(p) {
    var inactive = isInactive(p.c.id);
    return '<span class="as-status-pill' + (inactive ? ' inactive' : '') + '">' + (inactive ? 'Inactive' : 'Active') + '</span> ' +
      '<button type="button" class="as-link" data-act="toggle-inactive" data-contact="' + p.c.id + '">' + (inactive ? 'Mark Active' : 'Mark Inactive') + '</button>';
  }
  function statusCell(p) { return '<div class="as-cell">' + statusButtonHtml(p) + '</div>'; }
  function matrixHtml(list) {
    var cols = [{ key: 'computer', label: 'Computer' }, { key: 'microsoft_license', label: 'Microsoft License' }, { key: 'managed_services', label: 'Managed Services' }];
    var head = '<th>Person</th>' + cols.map(function (c) {
      return '<th data-col="' + esc(c.key) + '">' + esc(c.label) + '</th>';
    }).join('') + '<th>Status</th>';
    var rows = list.map(function (p) {
      var cells = cols.map(function (c) {
        if (c.key === 'computer') return '<td>' + computerCell(p) + '</td>';
        if (c.key === 'microsoft_license') return '<td>' + licenseCell(p) + '</td>';
        if (c.key === 'managed_services') return '<td>' + managedServicesCell(p) + '</td>';
        return '<td></td>';
      }).join('');
      return '<tr class="' + (isInactive(p.c.id) ? 'as-row-inactive' : '') + '">' +
        '<td class="as-person-cell"><b>' + esc(p.c.name) + '</b>' + (p.c.title ? '<small>' + esc(p.c.title) + '</small>' : '') + '</td>' +
        cells + '<td>' + statusCell(p) + '</td></tr>';
    }).join('');
    return '<table class="as-matrix"><thead><tr>' + head + '</tr></thead><tbody>' + rows + '</tbody></table>';
  }

  // ---- join the lists into one record per person
  function build() {
    buildServiceCoverage();
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
    var devsByContact = assignDevices();

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
        nodes += '<g class="as-node' + (p.c.id === selectedId ? ' sel' : '') + (p.total === 0 ? ' empty' : '') + (isInactive(p.c.id) ? ' inactive' : '') + '" data-id="' + p.c.id + '" tabindex="0" role="button" aria-label="' + esc(p.c.name) + '">' +
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
      '<div>' + warrantyChip(d.warranty_expires) + chips + sourceHtml(d) + '</div>' + assignHtml(d, 'Reassign to…') + '</div>';
  }
  function itemLink(url, name) { return url ? '<a href="' + esc(url) + '" target="_blank" rel="noopener">' + esc(name) + '</a>' : esc(name); }
  function detailHtml() {
    var p = null;
    people.forEach(function (x) { if (x.c.id === selectedId) p = x; });
    if (!p) return overviewHtml();
    var h = '<div class="as-card"><div class="as-person"><div class="as-avatar">' + esc(initials(p.c.name)) + '</div><div><h2>' + esc(p.c.name) + '</h2>' +
      '<small>' + esc([p.c.title, p.c.type].filter(Boolean).join(' · ')) + '</small></div></div>' +
      '<div class="as-sub">' + statusButtonHtml(p) + (p.c.email ? ' &middot; ' + esc(p.c.email) : '') + '</div>';
    h += '<h3>Hardware (' + p.devices.length + ')</h3>';
    h += p.devices.length ? p.devices.map(function (d) { return deviceBlock(p, d); }).join('') : '<div class="as-empty">No devices assigned to this person.</div>';
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
    h += '<h3>Devices with no person (' + data._unassignedDevices.length + ')</h3>';
    h += data._unassignedDevices.length ? data._unassignedDevices.slice(0, 40).map(function (d) {
      var hint = d._cands.length ? 'Last user ' + esc(d._lastUser) + ' could be ' + d._cands.map(function (c) { return esc(c.name); }).join(' or ')
        : d._lastUser ? 'Last user ' + esc(d._lastUser) + ' (no matching contact)' : '';
      return '<div class="as-item"><b>' + itemLink(d.url, d.name) + '</b><small>' + esc([d.type, d.make, d.model].filter(Boolean).join(' · ')) + '</small>' +
        (hint ? '<small class="as-hint">' + hint + '</small>' : '') + assignHtml(d, 'Assign to…') + '</div>';
    }).join('') + (data._unassignedDevices.length > 40 ? '<div class="as-empty">+ ' + (data._unassignedDevices.length - 40) + ' more</div>' : '') : '<div class="as-empty">Every device has a person.</div>';
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
    if (flash) h += '<div class="as-flash" role="alert">' + esc(flash) + '</div>';
    h += '<div class="as-stats">' +
      '<div class="as-stat as-stat-click" data-act="scroll-col"><b>' + people.length + '</b><span>people</span></div>' +
      '<div class="as-stat as-stat-click" data-act="scroll-col"><b>' + assigned + '</b><span>with assets</span></div>' +
      '<div class="as-stat as-stat-click" data-act="scroll-col" data-col="computer"><b>' + d.devices.length + '</b><span>devices</span></div>' +
      '<div class="as-stat as-stat-click" data-act="scroll-col" data-col="managed_services"><b>' + serviceCoverage.length + '</b><span>managed services tracked</span></div>' +
      '<div class="as-stat as-stat-click" data-act="scroll-col" data-col="microsoft_license"><b>' + (d.licenses || []).length + '</b><span>license records</span></div></div>';
    h += '<div class="as-grid"><div class="as-card"><div class="as-tools">' +
      '<input type="search" id="as-search" placeholder="Find a person…" value="' + esc(search) + '" aria-label="Find a person">' +
      '<label><input type="checkbox" id="as-hide"' + (hideEmpty ? ' checked' : '') + '> Hide people with nothing assigned</label>' +
      '<span class="as-legend"><span><i class="i-dev"></i>Devices</span><span><i class="i-sw"></i>Software</span><span><i class="i-lic"></i>Licenses</span></span></div>' +
      (list.length ? graphSvg(list) : '<div class="as-empty">No one matches.</div>') +
      '<div class="as-note">Devices are placed by a manual link made here first, then the IT Glue contact, then Automate’s last logged-in user when it matches exactly one contact. Software appears for a person when it is tagged to one of their devices in IT Glue. Licenses appear when the person is listed under Licensed Users.</div></div>' +
      '<div id="as-detail">' + detailHtml() + '</div></div>' +
      '<div class="as-card as-matrix-card"><div class="as-matrix-head"><h2>Users &amp; Assigned Services</h2></div>' +
      coverageBannerHtml() +
      (list.length ? matrixHtml(list) : '<div class="as-empty">No one matches.</div>') +
      '<div class="as-note">Every cell here is editable. Changing a Microsoft License, assigning a PC, or checking a Managed Services box creates a To-Do under this customer recording what changed and who changed it — IT Glue itself is never written to, so that To-Do is the record that the real change (in Microsoft 365, Automate, the firewall, …) still needs to happen. A plain tag in Managed Services is covered for every device of that type by the agreement; a checkbox means the agreement only covers some of them, so pick the ones that actually have it.</div></div></div>';
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
    if (act === 'unlink') postLink('link_clear', { device_id: +btn.getAttribute('data-dev') });
    else if (act === 'clear') select(null);
    else if (act === 'print') window.print();
    else if (act === 'refresh') load(true);
    else if (act === 'toggle-inactive') postInactiveToggle(+btn.getAttribute('data-contact'));
    else if (act === 'scroll-col') {
      var matrixEl = root.querySelector('.as-matrix-card');
      if (matrixEl) matrixEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
      var colKey = btn.getAttribute('data-col');
      if (colKey) {
        var idx = -1;
        root.querySelectorAll('.as-matrix thead th').forEach(function (th, i) { if (th.getAttribute('data-col') === colKey) idx = i; });
        if (idx >= 0) {
          root.querySelectorAll('.as-matrix tbody tr, .as-matrix thead tr').forEach(function (tr) {
            var cell = tr.children[idx + 1]; // +1 for the leading Person column
            if (cell) { cell.classList.add('as-col-flash'); setTimeout(function () { cell.classList.remove('as-col-flash'); }, 1600); }
          });
        }
      }
    }
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
    if (e.target.id === 'as-hide') { hideEmpty = e.target.checked; render(); return; }
    var assignContact = e.target.getAttribute && e.target.getAttribute('data-assign-contact');
    if (assignContact && e.target.value) { postLink('link_set', { device_id: +e.target.value, contact_id: +assignContact }); return; }
    var ovContact = e.target.getAttribute && e.target.getAttribute('data-override-contact');
    if (ovContact) {
      var ovValue = e.target.type === 'checkbox' ? (e.target.checked ? 'Assigned' : '') : e.target.value;
      postOverride(+ovContact, e.target.getAttribute('data-override-col'), e.target.getAttribute('data-override-label'), ovValue, e.target.getAttribute('data-override-prev') || '');
      return;
    }
    var dev = e.target.getAttribute && e.target.getAttribute('data-assign');
    if (dev && e.target.value) postLink('link_set', { device_id: +dev, contact_id: +e.target.value });
  });

  // Automate's "last logged in user" per computer; optional, any failure just means no username matching
  function loadAutomate() {
    return fetch('api/automate.php?action=computers&' + query, { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (j) { automate = j && j.ok && j.computers ? j.computers : []; })
      .catch(function () { automate = []; });
  }

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
        data = d; setLinks(d.links);
        setOverrides(d.overrides); setContactStatus(d.contact_status);
        loadAutomate().then(function () { build(); render(); });
      })
      .catch(function () { msg('Could not reach the server. Check your connection and try again.'); });
  }
  load(false);
})();
