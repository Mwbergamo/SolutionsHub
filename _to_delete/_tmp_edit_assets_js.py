#!/usr/bin/env python3
# -*- coding: utf-8 -*-
path = "assets.js"
with open(path, "r", encoding="utf-8") as f:
    src = f.read()
orig = src

def do(old, new, n=1):
    global src
    c = src.count(old)
    assert c == n, "anchor count=%d (wanted %d): %r" % (c, n, old[:80])
    src = src.replace(old, new, n)

# ---------------------------------------------------------------------------
# A. New module state: overrides, per-contact inactive flags, manual columns.
# ---------------------------------------------------------------------------
do(
"""  var linkMap = {};         // device id -> manual link made in Relationships
  var flash = '';""",
"""  var linkMap = {};         // device id -> manual link made in Relationships
  var flash = '';
  var overrides = {};       // "contactId|columnKey" -> {value, set_by_name, set_at} (asset_overrides)
  var contactStatus = {};   // contactId -> {inactive, set_by_name, set_at} (asset_contact_status)
  var manualColumns = [];   // [{column_key,label,options}] -- the configurable grid columns beyond Computer/Microsoft License/Software"""
)

# ---------------------------------------------------------------------------
# B. New helpers: override/status bookkeeping + the grid's cell renderers.
#    Inserted right after postLink(), before the person-joining section.
# ---------------------------------------------------------------------------
do(
"""  // ---- join the lists into one record per person""",
"""  // ---- Users & Assigned Services grid (added 2026-10-15 per Michael): a spreadsheet-style
  // row-per-person view under the web, with Computer / Microsoft License / Software plus any
  // configurable manual columns (Firewall Assignment, VPN Assignment, DUO, Guardz, ...). Every
  // cell writes through api/itglue.php's override_set (or link_set for Computer, or
  // contact_toggle_inactive for Status), which ALSO logs a to-do under this customer -- IT Glue's
  // API is read-only (see itglue.php's header), so the to-do is what actually drives the
  // follow-through in Microsoft 365 / Automate / the firewall, not a live push back to IT Glue.
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
  function licenseOptionNames() { return (data.licenses || []).map(function (l) { return l.name; }); }
  function softwareOptionNames() { return (data.software || []).map(function (s) { return s.name; }); }

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
  function postColumnAdd(label, options) {
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
    var baseline = p.licenses.map(function (l) { return l.name; }).join(', ');
    var val = overrideValue(p.c.id, 'microsoft_license') || baseline;
    return selectCell(p.c.id, 'microsoft_license', 'Microsoft License', licenseOptionNames(), val, (baseline && baseline !== val) ? 'IT Glue shows: ' + baseline : '');
  }
  function softwareCell(p) {
    var baseline = p.software.map(function (s) { return s.name; }).join(', ');
    var val = overrideValue(p.c.id, 'software') || baseline;
    return selectCell(p.c.id, 'software', 'Software', softwareOptionNames(), val, (baseline && baseline !== val) ? 'IT Glue shows: ' + baseline : '');
  }
  function manualCell(p, col) {
    var val = overrideValue(p.c.id, col.column_key);
    return (col.options && col.options.length) ? selectCell(p.c.id, col.column_key, col.label, col.options, val, '') : textCell(p.c.id, col.column_key, col.label, val);
  }
  function statusButtonHtml(p) {
    var inactive = isInactive(p.c.id);
    return '<span class="as-status-pill' + (inactive ? ' inactive' : '') + '">' + (inactive ? 'Inactive' : 'Active') + '</span> ' +
      '<button type="button" class="as-link" data-act="toggle-inactive" data-contact="' + p.c.id + '">' + (inactive ? 'Mark Active' : 'Mark Inactive') + '</button>';
  }
  function statusCell(p) { return '<div class="as-cell">' + statusButtonHtml(p) + '</div>'; }
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
  }

  // ---- join the lists into one record per person"""
)

# ---------------------------------------------------------------------------
# C. load(): pick up overrides / contact_status / columns from the assignments payload.
# ---------------------------------------------------------------------------
do(
"""        data = d; setLinks(d.links);
        loadAutomate().then(function () { build(); render(); });""",
"""        data = d; setLinks(d.links);
        setOverrides(d.overrides); setContactStatus(d.contact_status); manualColumns = d.columns || [];
        loadAutomate().then(function () { build(); render(); });"""
)

# ---------------------------------------------------------------------------
# D. graphSvg: dim an inactive person's node, same idea as the existing "empty" dimming.
# ---------------------------------------------------------------------------
do(
"""        nodes += '<g class="as-node' + (p.c.id === selectedId ? ' sel' : '') + (p.total === 0 ? ' empty' : '') + '" data-id="' + p.c.id + '" tabindex="0" role="button" aria-label="' + esc(p.c.name) + '">' +""",
"""        nodes += '<g class="as-node' + (p.c.id === selectedId ? ' sel' : '') + (p.total === 0 ? ' empty' : '') + (isInactive(p.c.id) ? ' inactive' : '') + '" data-id="' + p.c.id + '" tabindex="0" role="button" aria-label="' + esc(p.c.name) + '">' +"""
)

# ---------------------------------------------------------------------------
# E. detailHtml: show the Status control (mirrors the Contacts page's Active/Inactive control)
#    next to the person's email, instead of email alone.
# ---------------------------------------------------------------------------
do(
"""      (p.c.email ? '<div class="as-sub">' + esc(p.c.email) + '</div>' : '');""",
"""      '<div class="as-sub">' + statusButtonHtml(p) + (p.c.email ? ' &middot; ' + esc(p.c.email) : '') + '</div>';"""
)

# ---------------------------------------------------------------------------
# F. Stat cards become clickable: scroll to (and flash) the matching grid column.
# ---------------------------------------------------------------------------
do(
"""    h += '<div class="as-stats">' +
      '<div class="as-stat"><b>' + people.length + '</b><span>people</span></div>' +
      '<div class="as-stat"><b>' + assigned + '</b><span>with assets</span></div>' +
      '<div class="as-stat"><b>' + d.devices.length + '</b><span>devices</span></div>' +
      '<div class="as-stat"><b>' + (d.software || []).length + '</b><span>software records</span></div>' +
      '<div class="as-stat"><b>' + (d.licenses || []).length + '</b><span>license records</span></div></div>';""",
"""    h += '<div class="as-stats">' +
      '<div class="as-stat as-stat-click" data-act="scroll-col"><b>' + people.length + '</b><span>people</span></div>' +
      '<div class="as-stat as-stat-click" data-act="scroll-col"><b>' + assigned + '</b><span>with assets</span></div>' +
      '<div class="as-stat as-stat-click" data-act="scroll-col" data-col="computer"><b>' + d.devices.length + '</b><span>devices</span></div>' +
      '<div class="as-stat as-stat-click" data-act="scroll-col" data-col="software"><b>' + (d.software || []).length + '</b><span>software records</span></div>' +
      '<div class="as-stat as-stat-click" data-act="scroll-col" data-col="microsoft_license"><b>' + (d.licenses || []).length + '</b><span>license records</span></div></div>';"""
)

# ---------------------------------------------------------------------------
# G. Append the Users & Assigned Services matrix as a new full-width card after the graph/detail
#    grid, inside .as-page.
# ---------------------------------------------------------------------------
do(
"""      '<div id="as-detail">' + detailHtml() + '</div></div></div>';
    var keep = document.activeElement && document.activeElement.id === 'as-search';""",
"""      '<div id="as-detail">' + detailHtml() + '</div></div>' +
      '<div class="as-card as-matrix-card"><div class="as-matrix-head"><h2>Users &amp; Assigned Services</h2>' + addColumnHtml() + '</div>' +
      (list.length ? matrixHtml(list) : '<div class="as-empty">No one matches.</div>') +
      '<div class="as-note">Every cell here is editable. Changing a Microsoft License, assigning a PC, or changing any other column creates a To-Do under this customer recording what changed and who changed it — IT Glue itself is never written to, so that To-Do is the record that the real change (in Microsoft 365, Automate, the firewall, …) still needs to happen.</div></div></div>';
    var keep = document.activeElement && document.activeElement.id === 'as-search';"""
)

# ---------------------------------------------------------------------------
# H. click handler: Status toggle, add/remove column, stat-card scroll+flash.
# ---------------------------------------------------------------------------
do(
"""    if (act === 'unlink') postLink('link_clear', { device_id: +btn.getAttribute('data-dev') });
    else if (act === 'clear') select(null);
    else if (act === 'print') window.print();
    else if (act === 'refresh') load(true);
  });""",
"""    if (act === 'unlink') postLink('link_clear', { device_id: +btn.getAttribute('data-dev') });
    else if (act === 'clear') select(null);
    else if (act === 'print') window.print();
    else if (act === 'refresh') load(true);
    else if (act === 'toggle-inactive') postInactiveToggle(+btn.getAttribute('data-contact'));
    else if (act === 'add-column') {
      var label = window.prompt('New column name:');
      if (!label || !label.trim()) return;
      var opts = window.prompt('Preset choices for this column, comma-separated (leave blank for free text):', '');
      postColumnAdd(label.trim(), (opts || '').trim());
    }
    else if (act === 'remove-column') {
      if (window.confirm('Remove this column for everyone? Any values already entered in it will be lost.')) postColumnRemove(btn.getAttribute('data-col'));
    }
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
  });"""
)

# ---------------------------------------------------------------------------
# I. change handler: PC assignment select, generic override select/input.
# ---------------------------------------------------------------------------
do(
"""  root.addEventListener('change', function (e) {
    if (e.target.id === 'as-hide') { hideEmpty = e.target.checked; render(); return; }
    var dev = e.target.getAttribute && e.target.getAttribute('data-assign');
    if (dev && e.target.value) postLink('link_set', { device_id: +dev, contact_id: +e.target.value });
  });""",
"""  root.addEventListener('change', function (e) {
    if (e.target.id === 'as-hide') { hideEmpty = e.target.checked; render(); return; }
    var assignContact = e.target.getAttribute && e.target.getAttribute('data-assign-contact');
    if (assignContact && e.target.value) { postLink('link_set', { device_id: +e.target.value, contact_id: +assignContact }); return; }
    var ovContact = e.target.getAttribute && e.target.getAttribute('data-override-contact');
    if (ovContact) {
      postOverride(+ovContact, e.target.getAttribute('data-override-col'), e.target.getAttribute('data-override-label'), e.target.value, e.target.getAttribute('data-override-prev') || '');
      return;
    }
    var dev = e.target.getAttribute && e.target.getAttribute('data-assign');
    if (dev && e.target.value) postLink('link_set', { device_id: +dev, contact_id: +e.target.value });
  });"""
)

assert src != orig
with open(path, "w", encoding="utf-8") as f:
    f.write(src)
print("assets.js updated: Users & Assigned Services grid, overrides, inactive toggle, configurable columns, clickable stat cards")
