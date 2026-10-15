#!/usr/bin/env python3
path = "assets.js"
with open(path, "r", encoding="utf-8") as f:
    src = f.read()
orig = src

def do(old, new, label):
    global src
    n = src.count(old)
    assert n == 1, "%s: count=%d" % (label, n)
    src = src.replace(old, new, 1)

# 1) header comment above assignDevices()'s grid section -- update to describe Managed Services.
do(
"""  // ---- Users & Assigned Services grid (added 2026-10-15 per Michael): a spreadsheet-style
  // row-per-person view under the web, with Computer / Microsoft License / Software plus any
  // configurable manual columns (Firewall Assignment, VPN Assignment, DUO, Guardz, ...). Every
  // cell writes through api/itglue.php's override_set (or link_set for Computer, or
  // contact_toggle_inactive for Status), which ALSO logs a to-do under this customer -- IT Glue's
  // API is read-only (see itglue.php's header), so the to-do is what actually drives the
  // follow-through in Microsoft 365 / Automate / the firewall, not a live push back to IT Glue.""",
"""  // ---- Users & Assigned Services grid (added 2026-10-15, Managed Services column added
  // 2026-10-15 per Michael): a spreadsheet-style row-per-person view under the web, with
  // Computer / Microsoft License / Managed Services columns. Every cell writes through
  // api/itglue.php's override_set (or link_set for Computer, or contact_toggle_inactive for
  // Status), which ALSO logs a to-do under this customer -- IT Glue's API is read-only (see
  // itglue.php's header), so the to-do is what actually drives the follow-through in Microsoft
  // 365 / Automate / the firewall, not a live push back to IT Glue. Managed Services replaced
  // the Software column and the four manual columns (Firewall/VPN/DUO/Guardz) with real data --
  // see buildServiceCoverage() below.""",
    "header comment")

# 2) state vars: drop manualColumns, add automateByKey + serviceCoverage.
do(
"""  var automate = [];        // Automate computers (name, last_user) used to guess who uses a device
  var linkMap = {};         // device id -> manual link made in Relationships
  var flash = '';
  var overrides = {};       // "contactId|columnKey" -> {value, set_by_name, set_at} (asset_overrides)
  var contactStatus = {};   // contactId -> {inactive, set_by_name, set_at} (asset_contact_status)
  var manualColumns = [];   // [{column_key,label,options}] -- the configurable grid columns beyond Computer/Microsoft License/Software""",
"""  var automate = [];        // Automate computers (name, last_user, type) used to guess who uses a device
  var automateByKey = {};   // alnum(device name) -> automate computer row, set by assignDevices(); the
                             // Managed Services column reads a device's Workstation/Server type from this.
  var linkMap = {};         // device id -> manual link made in Relationships
  var flash = '';
  var overrides = {};       // "contactId|columnKey" -> {value, set_by_name, set_at} (asset_overrides)
  var contactStatus = {};   // contactId -> {inactive, set_by_name, set_at} (asset_contact_status)
  var serviceCoverage = []; // [{slug,label,scope,qty,n,full}] -- this customer's real managed-service
                             // entitlements (ConnectWise Agreement) matched against Automate's device
                             // counts; computed by buildServiceCoverage().""",
    "state vars")

# 3) expose the automate-by-key map built inside assignDevices() for reuse by the Managed Services column.
do(
"""    automate.forEach(function (a) { var k = alnum(a.name); if (k) comps[k] = a; });
    data.devices.forEach(function (d) {""",
"""    automate.forEach(function (a) { var k = alnum(a.name); if (k) comps[k] = a; });
    automateByKey = comps; // expose for managedServicesCell()'s device-type lookups
    data.devices.forEach(function (d) {""",
    "assignDevices exposes comps")

# 4) drop softwareOptionNames() (only used by the removed softwareCell).
do(
"""  function softwareOptionNames() { return (data.software || []).map(function (s) { return s.name; }); }

  function postOverride(contactId, columnKey, columnLabel, value, previousValue) {""",
"""  function postOverride(contactId, columnKey, columnLabel, value, previousValue) {""",
    "drop softwareOptionNames")

# 5) drop postColumnAdd/postColumnRemove (the generic "+Add column" feature Michael is replacing
#    with real data for this grid).
do(
"""  function postColumnAdd(label, options) {
    fetch('api/itglue.php?action=column_add&' + query, {
      method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'fetch' },
      body: JSON.stringify({ label: label, options: options })
    }).then(function (r) { return r.json().catch(function () { return null; }); })
      .then(function (j) {
        if (j && j.ok) { flash = ''; manualColumns = j.columns; } else flash = (j && j.error) || 'Could not add that column.';
        render();
      })
      .catch(function () { flash = 'Could not reach the server.'; render(); });
  }
  function postColumnRemove(key) {
    fetch('api/itglue.php?action=column_remove&' + query, {
      method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'fetch' },
      body: JSON.stringify({ column_key: key })
    }).then(function (r) { return r.json().catch(function () { return null; }); })
      .then(function (j) {
        if (j && j.ok) { flash = ''; manualColumns = j.columns; } else flash = (j && j.error) || 'Could not remove that column.';
        render();
      })
      .catch(function () { flash = 'Could not reach the server.'; render(); });
  }

  function selectCell(contactId, columnKey, columnLabel, options, currentValue, baselineHint) {
    var opts = ['<option value="">— none —</option>'].concat((options || []).map(function (o) {
      return '<option value="' + esc(o) + '"' + (o === currentValue ? ' selected' : '') + '>' + esc(o) + '</option>';
    }));
    if (currentValue && (options || []).indexOf(currentValue) < 0) {
      opts.push('<option value="' + esc(currentValue) + '" selected>' + esc(currentValue) + '</option>');
    }
    return '<div class="as-cell"><select data-override-contact="' + contactId + '" data-override-col="' + esc(columnKey) + '" data-override-label="' + esc(columnLabel) + '" data-override-prev="' + esc(currentValue) + '" aria-label="' + esc(columnLabel) + ' for this person">' +
      opts.join('') + '</select>' + (baselineHint ? '<small class="as-hint">' + esc(baselineHint) + '</small>' : '') + '</div>';
  }
  function textCell(contactId, columnKey, columnLabel, currentValue) {
    return '<div class="as-cell"><input type="text" value="' + esc(currentValue) + '" data-override-contact="' + contactId + '" data-override-col="' + esc(columnKey) + '" data-override-label="' + esc(columnLabel) + '" data-override-prev="' + esc(currentValue) + '" aria-label="' + esc(columnLabel) + ' for this person"></div>';
  }
  function computerCell(p) {""",
"""  function computerCell(p) {""",
    "drop postColumnAdd/Remove + selectCell/textCell")

# 6) replace softwareCell + manualCell with the SERVICE_FAMILIES catalog, coverage computation,
#    and the Managed Services cell renderer.
do(
"""  function softwareCell(p) {
    var baseline = p.software.map(function (s) { return s.name; }).join(', ');
    var val = overrideValue(p.c.id, 'software') || baseline;
    return selectCell(p.c.id, 'software', 'Software', softwareOptionNames(), val, (baseline && baseline !== val) ? 'IT Glue shows: ' + baseline : '');
  }
  function manualCell(p, col) {
    var val = overrideValue(p.c.id, col.column_key);
    return (col.options && col.options.length) ? selectCell(p.c.id, col.column_key, col.label, col.options, val, '') : textCell(p.c.id, col.column_key, col.label, val);
  }
  function statusButtonHtml(p) {""",
"""  // ---- Managed Services column (added 2026-10-15 per Michael): sourced from two real systems --
  // the customer's ConnectWise Agreement (data.customer_services: what was actually purchased,
  // with a quantity) and Automate RMM (the live device list, with a Workstation/Server type).
  // Neither system records which SPECIFIC device a purchased seat belongs to, so: when a
  // service's purchased quantity covers every device of the type it applies to, it's shown
  // automatically (a plain tag) on all of them; when it's only partial (e.g. EDR bought for 2 of
  // 9 workstations), there is no real signal for which 2 -- so it's a checkbox the rep sets by
  // hand per device, which writes through the same override + to-do pattern as every other cell.
  var SERVICE_FAMILIES = [
    { slug: 'maintenance', label: 'Managed Maintenance', match: /\\b(workstation|server)\\s+maintenance\\b/i },
    { slug: 'patch', label: 'Patch Management', match: /patch management/i },
    { slug: 'agent', label: 'RMM Access Agent', match: /\\baccess agent\\b/i },
    { slug: 'edr', label: 'Managed Antivirus / EDR', match: /\\bedr\\b|anti-?virus/i },
    { slug: 'backup-device', label: 'Managed Backup', match: /backup for (workstations|servers)\\b/i }
  ];
  function serviceScope(label) {
    if (/\\bserver\\b/i.test(label)) return 'server';
    if (/\\b(desktop|workstation)s?\\b/i.test(label)) return 'workstation';
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
        return '<div class="as-svc-device">' + (p.devices.length > 1 ? '<small>' + esc(d.name) + '</small>' : '') + '<small class="as-hint">Not RMM-tracked yet \\u2014 no device type to match services against.</small></div>';
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
      ' \\u2014 check the specific device(s) in the Managed Services column below.</div>';
  }
  function statusButtonHtml(p) {""",
    "softwareCell/manualCell -> SERVICE_FAMILIES + managedServicesCell")

# 7) addColumnHtml + matrixHtml -> drop addColumnHtml, simplify cols to Computer/License/Managed Services.
do(
"""  function statusCell(p) { return '<div class="as-cell">' + statusButtonHtml(p) + '</div>'; }
  function addColumnHtml() {
    return '<button type="button" class="as-btn" data-act="add-column">+ Add column</button>';
  }
  function matrixHtml(list) {
    var cols = [{ key: 'computer', label: 'Computer' }, { key: 'microsoft_license', label: 'Microsoft License' }, { key: 'software', label: 'Software' }]
      .concat(manualColumns.map(function (c) { return { key: c.column_key, label: c.label }; }));
    var head = '<th>Person</th>' + cols.map(function (c) {
      var removable = manualColumns.some(function (m) { return m.column_key === c.key; });
      return '<th data-col="' + esc(c.key) + '">' + esc(c.label) + (removable ? ' <button type="button" class="as-col-remove" data-act="remove-column" data-col="' + esc(c.key) + '" title="Remove this column">&times;</button>' : '') + '</th>';
    }).join('') + '<th>Status</th>';
    var rows = list.map(function (p) {
      var cells = cols.map(function (c) {
        if (c.key === 'computer') return '<td>' + computerCell(p) + '</td>';
        if (c.key === 'microsoft_license') return '<td>' + licenseCell(p) + '</td>';
        if (c.key === 'software') return '<td>' + softwareCell(p) + '</td>';
        var col = null;
        manualColumns.forEach(function (m) { if (m.column_key === c.key) col = m; });
        return '<td>' + (col ? manualCell(p, col) : '') + '</td>';
      }).join('');
      return '<tr class="' + (isInactive(p.c.id) ? 'as-row-inactive' : '') + '">' +
        '<td class="as-person-cell"><b>' + esc(p.c.name) + '</b>' + (p.c.title ? '<small>' + esc(p.c.title) + '</small>' : '') + '</td>' +
        cells + '<td>' + statusCell(p) + '</td></tr>';
    }).join('');
    return '<table class="as-matrix"><thead><tr>' + head + '</tr></thead><tbody>' + rows + '</tbody></table>';
  }""",
"""  function statusCell(p) { return '<div class="as-cell">' + statusButtonHtml(p) + '</div>'; }
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
  }""",
    "addColumnHtml/matrixHtml simplify")

# 8) build() -- compute serviceCoverage whenever data+automate are (re)loaded.
do(
"""  function build() {
    var devById = {}, protByDev = {}, swByDev = {};""",
"""  function build() {
    buildServiceCoverage();
    var devById = {}, protByDev = {}, swByDev = {};""",
    "build() calls buildServiceCoverage")

# 9) render(): stat row (swap "software records" for "managed services tracked") + the matrix
#    card header/note (drop addColumnHtml(), add the coverage banner, update the editing note).
do(
"""      '<div class="as-stat as-stat-click" data-act="scroll-col" data-col="computer"><b>' + d.devices.length + '</b><span>devices</span></div>' +
      '<div class="as-stat as-stat-click" data-act="scroll-col" data-col="software"><b>' + (d.software || []).length + '</b><span>software records</span></div>' +
      '<div class="as-stat as-stat-click" data-act="scroll-col" data-col="microsoft_license"><b>' + (d.licenses || []).length + '</b><span>license records</span></div></div>';""",
"""      '<div class="as-stat as-stat-click" data-act="scroll-col" data-col="computer"><b>' + d.devices.length + '</b><span>devices</span></div>' +
      '<div class="as-stat as-stat-click" data-act="scroll-col" data-col="managed_services"><b>' + serviceCoverage.length + '</b><span>managed services tracked</span></div>' +
      '<div class="as-stat as-stat-click" data-act="scroll-col" data-col="microsoft_license"><b>' + (d.licenses || []).length + '</b><span>license records</span></div></div>';""",
    "stat row")

do(
"""      '<div class="as-card as-matrix-card"><div class="as-matrix-head"><h2>Users &amp; Assigned Services</h2>' + addColumnHtml() + '</div>' +
      (list.length ? matrixHtml(list) : '<div class="as-empty">No one matches.</div>') +
      '<div class="as-note">Every cell here is editable. Changing a Microsoft License, assigning a PC, or changing any other column creates a To-Do under this customer recording what changed and who changed it — IT Glue itself is never written to, so that To-Do is the record that the real change (in Microsoft 365, Automate, the firewall, …) still needs to happen.</div></div></div>';""",
"""      '<div class="as-card as-matrix-card"><div class="as-matrix-head"><h2>Users &amp; Assigned Services</h2></div>' +
      coverageBannerHtml() +
      (list.length ? matrixHtml(list) : '<div class="as-empty">No one matches.</div>') +
      '<div class="as-note">Every cell here is editable. Changing a Microsoft License, assigning a PC, or checking a Managed Services box creates a To-Do under this customer recording what changed and who changed it — IT Glue itself is never written to, so that To-Do is the record that the real change (in Microsoft 365, Automate, the firewall, …) still needs to happen. A plain tag in Managed Services is covered for every device of that type by the agreement; a checkbox means the agreement only covers some of them, so pick the ones that actually have it.</div></div></div>';""",
    "matrix card header/note")

# 10) click handler: drop add-column / remove-column.
do(
"""    else if (act === 'toggle-inactive') postInactiveToggle(+btn.getAttribute('data-contact'));
    else if (act === 'add-column') {
      var label = window.prompt('New column name:');
      if (!label || !label.trim()) return;
      var opts = window.prompt('Preset choices for this column, comma-separated (leave blank for free text):', '');
      postColumnAdd(label.trim(), (opts || '').trim());
    }
    else if (act === 'remove-column') {
      if (window.confirm('Remove this column for everyone? Any values already entered in it will be lost.')) postColumnRemove(btn.getAttribute('data-col'));
    }
    else if (act === 'scroll-col') {""",
"""    else if (act === 'toggle-inactive') postInactiveToggle(+btn.getAttribute('data-contact'));
    else if (act === 'scroll-col') {""",
    "click handler drop add/remove column")

# 11) change handler: checkboxes (Managed Services toggles) need their own value, not e.target.value.
do(
"""    var ovContact = e.target.getAttribute && e.target.getAttribute('data-override-contact');
    if (ovContact) {
      postOverride(+ovContact, e.target.getAttribute('data-override-col'), e.target.getAttribute('data-override-label'), e.target.value, e.target.getAttribute('data-override-prev') || '');
      return;
    }""",
"""    var ovContact = e.target.getAttribute && e.target.getAttribute('data-override-contact');
    if (ovContact) {
      var ovValue = e.target.type === 'checkbox' ? (e.target.checked ? 'Assigned' : '') : e.target.value;
      postOverride(+ovContact, e.target.getAttribute('data-override-col'), e.target.getAttribute('data-override-label'), ovValue, e.target.getAttribute('data-override-prev') || '');
      return;
    }""",
    "change handler checkbox support")

# 12) load(): drop manualColumns assignment (d.columns is still returned by the API but no longer rendered).
do(
"""        data = d; setLinks(d.links);
        setOverrides(d.overrides); setContactStatus(d.contact_status); manualColumns = d.columns || [];
        loadAutomate().then(function () { build(); render(); });""",
"""        data = d; setLinks(d.links);
        setOverrides(d.overrides); setContactStatus(d.contact_status);
        loadAutomate().then(function () { build(); render(); });""",
    "load() drop manualColumns")

assert src != orig
with open(path, "w", encoding="utf-8") as f:
    f.write(src)
print("assets.js updated: Managed Services column (real agreement + RMM data) replaces Software + the 4 manual columns")
