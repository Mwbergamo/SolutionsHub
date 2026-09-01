/**
 * call-experience.js — Zultys Call Experience Configurator
 *
 * Standalone tool (not part of app.js/runtime.js) added to SolutionsHub.
 * Design notes:
 *  - The wizard's HTML is built ONCE from the SECTIONS config below and
 *    injected into #wizard. After that, nothing is ever re-rendered —
 *    every update is a targeted `hidden`/class toggle driven by native
 *    change/input events. This is deliberate: a full re-render on every
 *    keystroke (the thing runtime.js in the main app exists to avoid) would
 *    steal focus out of whatever text field the user is typing in. Text
 *    inputs here just own their own value; nothing ever writes back into
 *    them from JS, so there is nothing to lose focus over.
 *  - Every binary/choice question that should gate progress carries
 *    data-req="<name>" + data-req-type="radio|select|checkboxgroup|number".
 *    Every conditionally-revealed block carries data-show="name:value" and
 *    is hidden/shown via the native `hidden` attribute — which also makes
 *    "is this field currently relevant" trivial to compute (`el.closest('[hidden]')`).
 *  - Sections lock top-to-bottom: a section unlocks only once every
 *    currently-visible required field in every prior section is answered.
 */
(function () {
  'use strict';

  var esc = function (s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  };

  // ---------------------------------------------------------------------
  // Small markup helpers used while building the SECTIONS templates
  // ---------------------------------------------------------------------
  function ynGroup(name, opts) {
    opts = opts || {};
    var labels = opts.labels || ['Yes', 'No'];
    var values = opts.values || ['yes', 'no'];
    var req = opts.required === false ? '' : (' data-req="' + name + '" data-req-type="radio"');
    var html = '<div class="yn-group"' + req + '>';
    for (var i = 0; i < values.length; i++) {
      var id = name + '_' + values[i];
      html += '<input type="radio" name="' + name + '" id="' + id + '" value="' + values[i] + '">' +
        '<label class="yn-label" for="' + id + '">' + esc(labels[i]) + '</label>';
    }
    html += '</div>';
    return html;
  }

  function reveal(showCond, inner) {
    return '<div class="reveal" data-show="' + showCond + '" hidden>' + inner + '</div>';
  }

  function field(labelText, inputHtml, hint) {
    return '<div class="field"><label class="field-label">' + esc(labelText) + '</label>' + inputHtml +
      (hint ? '<div class="field-hint">' + hint + '</div>' : '') + '</div>';
  }

  function textInput(name, placeholder, id) {
    return '<input type="text" name="' + name + '"' + (id ? ' id="' + id + '"' : '') + ' placeholder="' + esc(placeholder || '') + '">';
  }

  function textarea(name, placeholder) {
    return '<textarea name="' + name + '" placeholder="' + esc(placeholder || '') + '" rows="2"></textarea>';
  }

  function numberInput(name, placeholder, required) {
    var req = required ? (' data-req="' + name + '" data-req-type="number"') : '';
    return '<input type="number" min="0" name="' + name + '" placeholder="' + esc(placeholder || '') + '"' + req + '>';
  }

  function selectInput(name, options, required, placeholder) {
    var req = required ? (' data-req="' + name + '" data-req-type="select"') : '';
    var html = '<select name="' + name + '"' + req + '><option value="">' + esc(placeholder || 'Choose one…') + '</option>';
    options.forEach(function (o) { html += '<option value="' + o[0] + '">' + esc(o[1]) + '</option>'; });
    html += '</select>';
    return html;
  }

  function chipGroup(groupKey, name, options, multi, required) {
    var req = required ? (' data-req="' + groupKey + '" data-req-type="checkboxgroup"') : '';
    var type = multi ? 'checkbox' : 'radio';
    var html = '<div class="chip-group"' + req + ' id="' + groupKey + '">';
    options.forEach(function (o, i) {
      var id = groupKey + '_' + i;
      html += '<input type="' + type + '" name="' + name + (multi ? '[]' : '') + '" id="' + id + '" value="' + o[0] + '" class="cbg">' +
        '<label class="chip-label" for="' + id + '">' + esc(o[1]) + '</label>';
    });
    html += '</div>';
    return html;
  }

  function callout(html) {
    return '<div class="callout">🛠️ ' + html + '</div>';
  }

  function roster(containerId, ph1, ph2, wide) {
    return '<div class="roster" id="' + containerId + '" data-ph1="' + esc(ph1) + '" data-ph2="' + esc(ph2) + '" data-wide="' + (wide ? '1' : '0') + '">' +
      '<div class="roster-empty">Nobody added yet.</div>' +
      '</div>' +
      '<button type="button" class="roster-add" data-add-row="' + containerId + '">+ Add ' + esc(ph1) + '</button>';
  }

  // ---------------------------------------------------------------------
  // The 18-question flow. `req` items feed the progress bar / gating;
  // conditional fields only count while their `data-show` ancestor is
  // visible (see isVisible()/isAnswered() below).
  // ---------------------------------------------------------------------
  var SECTIONS = [
    {
      id: 1, title: 'Current Phone System',
      sub: 'Reference for the Project Engineer.',
      body: function () {
        return field('Do you have access to your current phone system?', ynGroup('q1_access')) +
          reveal('q1_access:yes',
            field('Do you have documentation on how it is currently set up?', ynGroup('q1_docs', { required: false })) +
            '<div class="field-hint">As you progress from here, see if they need any changes to their current setup.</div>'
          ) +
          field('What is working well? What would they like to potentially change?', textarea('q1_notes', 'Notes…'));
      }
    },
    {
      id: 2, title: 'Current Internet Service Provider',
      sub: 'Reference for the Project Engineer.',
      body: function () {
        return field('Who is the current ISP?', textInput('q2_isp', 'ISP name')) +
          field('Do they know their contracted speed?', ynGroup('q2_speed_known')) +
          reveal('q2_speed_known:yes', field('Contracted speed', textInput('q2_speed', 'e.g. 300 Mbps down / 20 Mbps up'))) +
          field('Can they provide a recent invoice (one month old max)?', ynGroup('q2_invoice')) +
          '<div class="field-hint">If not, an internet speed check can be run from a computer in their office at speedtest.net — the Engineer will confirm speeds are acceptable for VoIP.</div>';
      }
    },
    {
      id: 3, title: 'Current Phone Carrier',
      sub: 'Reference for the Project Engineer.',
      body: function () {
        return field('Who is the current phone carrier?', textInput('q3_carrier', 'Carrier name')) +
          field('Can they provide a recent bill (one month old max)?', ynGroup('q3_bill')) +
          reveal('q3_bill:no', field('Current phone numbers and what they’re assigned to (fax, alarm, elevator, etc.)', textarea('q3_numbers', 'List numbers and assignments…')));
      }
    },
    {
      id: 4, title: 'Multiple Locations',
      sub: '',
      body: function () {
        return field('Do they have multiple locations?', ynGroup('q4_multi')) +
          reveal('q4_multi:yes',
            '<div class="field-hint" style="margin-bottom:10px;">Please repeat questions 1–3 for each location below, and note where each is.</div>' +
            roster('locRoster', 'Location', 'Address / current system & carrier notes', true)
          );
      }
    },
    {
      id: 5, title: 'Phone Count',
      sub: '',
      body: function () {
        return field('How many phones do they need in total?', numberInput('q5_phone_count', 'e.g. 12', true)) +
          reveal('q4_multi:yes', field('How many of those are for other locations?', numberInput('q5_other_locations', 'e.g. 4')));
      }
    },
    {
      id: 6, title: 'Extensions',
      sub: '',
      body: function () {
        return field('Would they like to keep their current extensions?', ynGroup('q6_keep_ext')) +
          reveal('q6_keep_ext:yes',
            field('Do they have a current extension list?', ynGroup('q6_ext_list')) +
            '<div class="field-hint">Note each user’s location if applicable (multi-location deployments).</div>'
          );
      }
    },
    {
      id: 7, title: 'Voicemail',
      sub: '',
      body: function () {
        return field('Personal or shared voicemail?', chipGroup('q7_type_grp', 'q7_type', [['personal', 'Personal'], ['shared', 'Shared'], ['both', 'Both, depending on user']], false, true)) +
          field('How would they like to check voicemail?', chipGroup('q7_check_grp', 'q7_check', [['zac', 'ZAC'], ['email', 'Voicemail-to-email']], true, true), 'Select both if some users want each.');
      }
    },
    {
      id: 8, title: 'Incoming Calls',
      sub: '',
      body: function () {
        return field('How are incoming calls typically answered today?', textarea('q8_how', 'e.g. front-desk receptionist, auto attendant, ring group…')) +
          field('Is there a dedicated receptionist (or receptionists)?', ynGroup('q8_receptionist')) +
          reveal('q8_receptionist:yes',
            field('Receptionist(s)', roster('recRoster', 'Name', 'Extension')) +
            field('Will they need an expansion module for their phone?', ynGroup('q8_expansion'))
          );
      }
    },
    {
      id: 9, title: 'Mobile Application',
      sub: '',
      body: function () {
        return field('Will users be using the mobile application?', ynGroup('q9_mobile')) +
          reveal('q9_mobile:yes',
            field('License tier needed', selectInput('q9_license', [['basic', 'Basic'], ['standard', 'Standard'], ['premium', 'Premium']], true), 'This determines which license is required.') +
            field('Who needs the mobile app?', roster('mobileRoster', 'Name', 'Extension'))
          );
      }
    },
    {
      id: 10, title: 'Phone Placement & Installation',
      sub: '',
      body: function () {
        return field('Is there a diagram of the building we can use for phone placement / install planning?', ynGroup('q10_diagram')) +
          field('Would users require headsets?', ynGroup('q10_headsets')) +
          reveal('q10_headsets:yes', field('Who needs a headset?', roster('headsetRoster', 'Name', 'Extension'))) +
          field('Do any phones need to be wall mounted?', ynGroup('q10_wallmount')) +
          reveal('q10_wallmount:yes', field('Which phones / locations?', textarea('q10_wallmount_notes', 'e.g. warehouse floor, break room…')));
      }
    },
    {
      id: 11, title: 'Call Monitoring',
      sub: '',
      body: function () {
        return field('Do they require call monitoring?', ynGroup('q11_monitoring')) +
          reveal('q11_monitoring:yes',
            callout('This feature requires <b>ZAC</b>. Further questions will be asked by the Engineer.') +
            field('Notes', textarea('q11_notes', 'Who monitors whom, which groups…'))
          );
      }
    },
    {
      id: 12, title: 'Call Recording',
      sub: '',
      body: function () {
        return field('Do they require call recording?', ynGroup('q12_recording')) +
          reveal('q12_recording:yes',
            callout('This feature requires <b>ZAC</b>. Further questions will be asked by the Engineer.') +
            field('Notes', textarea('q12_notes', 'Which users / groups are recorded…'))
          );
      }
    },
    {
      id: 13, title: 'Paging',
      sub: '',
      body: function () {
        return field('Do they require the ability to page?', ynGroup('q13_paging')) +
          reveal('q13_paging:yes',
            field('Will the current paging system work with Zultys, or is new hardware required?', selectInput('q13_hardware', [['existing', 'Existing system works'], ['new', 'New hardware required']], true)) +
            '<div class="field-hint">Further questions will be asked by the Engineer.</div>'
          );
      }
    },
    {
      id: 14, title: 'Fax',
      sub: '',
      body: function () {
        return field('Do they currently fax?', ynGroup('q14_fax')) +
          reveal('q14_fax:yes',
            field('How are they currently faxing?', textarea('q14_how', 'e.g. dedicated line, all-in-one printer…')) +
            field('Will the fax number be ported, or kept with the current ISP?', ynGroup('q14_port', { labels: ['Ported', 'Kept with ISP'] })) +
            reveal('q14_port:yes', field('SPA or eFax?', chipGroup('q14_method_grp', 'q14_method', [['efax', 'eFaxing (preferred)'], ['spa', 'SPA']], false, true)))
          );
      }
    },
    {
      id: 15, title: 'Remote Workers',
      sub: '',
      body: function () {
        return field('Are any of the workers remote?', ynGroup('q15_remote')) +
          reveal('q15_remote:yes',
            field('Do they require a phone / extension?', ynGroup('q15_ext_needed')) +
            reveal('q15_ext_needed:yes',
              field('Remote workers needing an extension', roster('remoteRoster', 'Name', 'Home ISP / speed (if known)', true)) +
              '<div class="field-hint">The Engineer will need to determine each remote worker’s home ISP and speeds.</div>'
            )
          );
      }
    },
    {
      id: 16, title: 'Direct Inward Dial (DID)',
      sub: '',
      body: function () {
        return field('Does anyone require a DID (direct phone number)?', ynGroup('q16_did')) +
          reveal('q16_did:yes', field('Who needs a DID? (for procurement)', roster('didRoster', 'Name', 'Notes', true)));
      }
    },
    {
      id: 17, title: 'Outbound Call Restrictions',
      sub: '',
      body: function () {
        return field('Do they need restrictions for calling out?', ynGroup('q17_restrict')) +
          reveal('q17_restrict:yes',
            field('What are the restrictions?', textarea('q17_what', 'e.g. no international, local only…')) +
            field('Who needs to be restricted?', roster('restrictRoster', 'Name', 'Restriction', true))
          );
      }
    },
    {
      id: 18, title: 'After-Hours Handling',
      sub: '',
      body: function () {
        return field('Do they need a call tree set up for normal business hours?', ynGroup('q18_calltree')) +
          reveal('q18_calltree:yes', field('Call tree menu', roster('treeRows', 'Menu option (e.g. "Press 1")', 'Routes to…', true))) +
          field('What routing would they like for after-hours calls?', selectInput('q18_afterhours', [['voicemail', 'Central voicemail box'], ['ringgroup', 'Ring a group'], ['answering', 'Answering service'], ['custom', 'Custom / other']], true)) +
          field('Who or where should after-hours calls go?', textInput('q18_afterhours_target', 'e.g. on-call manager ext. 210, answering service name…'));
      }
    }
  ];

  // ---------------------------------------------------------------------
  // Build the wizard DOM once
  // ---------------------------------------------------------------------
  var wizardForm = document.getElementById('wizard');

  function buildWizard() {
    var html = '';
    SECTIONS.forEach(function (s) {
      html += '<div class="qcard" id="qcard-' + s.id + '" data-section="' + s.id + '">' +
        '<div class="qcard-head" data-toggle-collapse>' +
          '<div class="qnum">' + s.id + '</div>' +
          '<div class="qtitle">' + esc(s.title) + (s.sub ? '<small>' + esc(s.sub) + '</small>' : '') + '</div>' +
          '<div class="qstatus"></div>' +
        '</div>' +
        '<div class="qbody">' + s.body() + '</div>' +
        '</div>';
    });
    wizardForm.innerHTML = html;
  }

  // ---------------------------------------------------------------------
  // Reveal / lock / progress engine
  // ---------------------------------------------------------------------
  function currentValue(name) {
    var checked = wizardForm.querySelector('[name="' + name + '"]:checked');
    if (checked) return checked.value;
    var el = wizardForm.querySelector('[name="' + name + '"]');
    if (el && el.tagName === 'SELECT') return el.value;
    return '';
  }

  function updateReveals() {
    wizardForm.querySelectorAll('[data-show]').forEach(function (el) {
      var cond = el.getAttribute('data-show').split(':');
      el.hidden = currentValue(cond[0]) !== cond[1];
    });
  }

  function isVisible(el) {
    return !el.closest('[hidden]');
  }

  function isAnswered(el) {
    var type = el.getAttribute('data-req-type');
    var name = el.getAttribute('data-req');
    if (type === 'radio') return !!wizardForm.querySelector('[name="' + name + '"]:checked');
    if (type === 'select') return el.value !== '';
    if (type === 'checkboxgroup') return !!el.querySelector('input:checked');
    if (type === 'number') return el.value !== '' && Number(el.value) > 0;
    return false;
  }

  function updateLocksAndProgress() {
    var totalReq = 0, totalDone = 0, prevComplete = true;
    var missing = [];
    SECTIONS.forEach(function (s) {
      var card = document.getElementById('qcard-' + s.id);
      var reqEls = Array.prototype.slice.call(card.querySelectorAll('[data-req]')).filter(isVisible);
      var doneEls = reqEls.filter(isAnswered);
      totalReq += reqEls.length;
      totalDone += doneEls.length;
      var sectionComplete = reqEls.length === doneEls.length;

      card.dataset.locked = prevComplete ? '0' : '1';
      card.classList.toggle('unlocked', prevComplete);
      card.classList.toggle('done', prevComplete && sectionComplete);
      card.classList.toggle('current', prevComplete && !sectionComplete);
      var statusEl = card.querySelector('.qstatus');
      if (!prevComplete) { statusEl.textContent = 'Locked'; statusEl.className = 'qstatus'; }
      else if (sectionComplete) { statusEl.textContent = '✓ Complete'; statusEl.className = 'qstatus done'; }
      else { statusEl.textContent = (reqEls.length - doneEls.length) + ' left'; statusEl.className = 'qstatus'; }

      if (prevComplete && !sectionComplete) {
        missing.push(s.id + '. ' + s.title);
      }
      if (!prevComplete && !sectionComplete) {
        // still locked, don't collapse-hide by lock flag change alone
      }
      prevComplete = prevComplete && sectionComplete;
    });

    var pct = totalReq ? Math.round((totalDone / totalReq) * 100) : 0;
    document.getElementById('progressLabel').textContent = totalDone + ' / ' + totalReq + ' answered';
    document.getElementById('progressFill').style.width = pct + '%';

    renderSummaryGate(prevComplete, missing);
  }

  function recompute() {
    updateReveals();
    updateLocksAndProgress();
  }

  // ---------------------------------------------------------------------
  // Roster rows (added/removed on click only — never touches other rows,
  // so nothing already being typed into loses focus)
  // ---------------------------------------------------------------------
  function addRosterRow(containerId) {
    var c = document.getElementById(containerId);
    var empty = c.querySelector('.roster-empty');
    if (empty) empty.remove();
    var wide = c.dataset.wide === '1';
    var row = document.createElement('div');
    row.className = 'roster-row' + (wide ? ' wide' : '');
    row.innerHTML = '<input type="text" class="r1" placeholder="' + esc(c.dataset.ph1) + '">' +
      '<input type="text" class="r2" placeholder="' + esc(c.dataset.ph2) + '">' +
      '<button type="button" class="roster-remove" aria-label="Remove">×</button>';
    c.appendChild(row);
  }

  document.addEventListener('click', function (e) {
    var addBtn = e.target.closest('[data-add-row]');
    if (addBtn) { addRosterRow(addBtn.getAttribute('data-add-row')); return; }
    var rmBtn = e.target.closest('.roster-remove');
    if (rmBtn) {
      var container = rmBtn.closest('.roster');
      rmBtn.closest('.roster-row').remove();
      if (container && !container.querySelector('.roster-row')) {
        container.innerHTML = '<div class="roster-empty">Nobody added yet.</div>';
      }
      return;
    }
    var head = e.target.closest('[data-toggle-collapse]');
    if (head) {
      var card = head.closest('.qcard');
      if (card.classList.contains('unlocked')) {
        card.dataset.collapsed = card.dataset.collapsed === '1' ? '0' : '1';
      }
    }
  });

  wizardForm.addEventListener('change', recompute);
  wizardForm.addEventListener('input', function (e) {
    // Only numeric/select "required" fields affect gating live as you type;
    // text/textarea fields never do, so this stays cheap.
    if (e.target.matches('input[type=number][data-req], select[data-req]')) recompute();
  });

  // ---------------------------------------------------------------------
  // Collecting answers for the summary graphic + email
  // ---------------------------------------------------------------------
  function rosterEntries(containerId) {
    var c = document.getElementById(containerId);
    if (!c) return [];
    return Array.prototype.slice.call(c.querySelectorAll('.roster-row')).map(function (row) {
      var inputs = row.querySelectorAll('input');
      return { a: (inputs[0] && inputs[0].value.trim()) || '', b: (inputs[1] && inputs[1].value.trim()) || '' };
    }).filter(function (r) { return r.a || r.b; });
  }

  function checkedValues(name) {
    return Array.prototype.slice.call(wizardForm.querySelectorAll('[name="' + name + '[]"]:checked, [name="' + name + '"]:checked'))
      .map(function (i) { return i.value; });
  }

  function val(name) { return currentValue(name); }
  function textVal(name) { var e = wizardForm.querySelector('[name="' + name + '"]'); return e ? e.value.trim() : ''; }

  function collect() {
    return {
      // reference
      isp: textVal('q2_isp'),ispSpeed: textVal('q2_speed'),
      carrier: textVal('q3_carrier'),
      multi: val('q4_multi') === 'yes', locations: rosterEntries('locRoster'),
      phoneCount: textVal('q5_phone_count'), otherLocPhones: textVal('q5_other_locations'),
      keepExt: val('q6_keep_ext') === 'yes',
      vmType: val('q7_type'), vmCheck: checkedValues('q7_check'),
      inboundHow: textVal('q8_how'),
      hasReceptionist: val('q8_receptionist') === 'yes', receptionists: rosterEntries('recRoster'),
      expansionModule: val('q8_expansion') === 'yes',
      mobile: val('q9_mobile') === 'yes', license: val('q9_license'), mobileUsers: rosterEntries('mobileRoster'),
      headsets: val('q10_headsets') === 'yes', headsetUsers: rosterEntries('headsetRoster'),
      wallmount: val('q10_wallmount') === 'yes', wallmountNotes: textVal('q10_wallmount_notes'),
      monitoring: val('q11_monitoring') === 'yes', monitoringNotes: textVal('q11_notes'),
      recording: val('q12_recording') === 'yes', recordingNotes: textVal('q12_notes'),
      paging: val('q13_paging') === 'yes', pagingHardware: val('q13_hardware'),
      fax: val('q14_fax') === 'yes', faxHow: textVal('q14_how'), faxPort: val('q14_port') === 'yes', faxMethod: val('q14_method'),
      remote: val('q15_remote') === 'yes', remoteExt: val('q15_ext_needed') === 'yes', remoteWorkers: rosterEntries('remoteRoster'),
      did: val('q16_did') === 'yes', didUsers: rosterEntries('didRoster'),
      restrict: val('q17_restrict') === 'yes', restrictWhat: textVal('q17_what'), restrictedUsers: rosterEntries('restrictRoster'),
      callTree: val('q18_calltree') === 'yes', treeRows: rosterEntries('treeRows'),
      afterHours: val('q18_afterhours'), afterHoursTarget: textVal('q18_afterhours_target')
    };
  }

  // ---------------------------------------------------------------------
  // Summary gate / generation
  // ---------------------------------------------------------------------
  var summarySection = document.getElementById('summarySection');
  var allComplete = false;

  function renderSummaryGate(complete, missing) {
    allComplete = complete;
    if (complete) {
      if (!summarySection.dataset.built) buildSummaryShell();
      summarySection.hidden = false;
    } else {
      summarySection.hidden = false;
      summarySection.dataset.built = '';
      summarySection.innerHTML = '<div class="summary-gate"><h3>Keep going — ' + missing.length + ' section' + (missing.length === 1 ? '' : 's') + ' still need' + (missing.length === 1 ? 's' : '') + ' an answer</h3>' +
        '<p>Every binary question unlocks the next part of the flow. Once everything above is answered, the call-handling graphic and email builder appear here.</p>' +
        '<div class="missing-list">' + missing.map(function (m) { return '<div>• ' + esc(m) + '</div>'; }).join('') + '</div>' +
        '</div>';
    }
  }

  function buildSummaryShell() {
    summarySection.dataset.built = '1';
    summarySection.innerHTML =
      '<div class="summary-toolbar"><h2>Call Experience Summary</h2></div>' +
      '<div class="field"><label class="field-label">Company / customer name</label>' + textInput('sumCompany', 'Company name', 'sumCompany') + '</div>' +
      '<div class="field"><label class="field-label">Site / address (optional)</label>' + textInput('sumSite', 'Site address', 'sumSite') + '</div>' +
      '<div class="field"><label class="field-label">Prepared by (optional)</label>' + textInput('sumPreparedBy', 'Your name', 'sumPreparedBy') + '</div>' +
      '<button type="button" class="btn btn-primary" id="genBtn">Generate Call Experience Graphic</button>' +
      '<div id="genOutput"></div>';
    document.getElementById('genBtn').addEventListener('click', generateGraphic);
  }

  function groupBox(title, entries, emptyText, meta) {
    var list = entries.length
      ? '<ul>' + entries.map(function (e) { return '<li>' + esc(e.a) + (e.b ? ' <span style="opacity:.65">— ' + esc(e.b) + '</span>' : '') + '</li>'; }).join('') + '</ul>'
      : '<div class="empty">' + esc(emptyText) + '</div>';
    return '<div class="group-box"><h4>' + esc(title) + '</h4>' + list + (meta ? '<div class="meta">' + esc(meta) + '</div>' : '') + '</div>';
  }

  var AFTER_HOURS_LABEL = { voicemail: 'Central voicemail box', ringgroup: 'Ring a group', answering: 'Answering service', custom: 'Custom / other' };
  var LICENSE_LABEL = { basic: 'Basic', standard: 'Standard', premium: 'Premium' };

  function generateGraphic() {
    var A = collect();
    var out = document.getElementById('genOutput');

    var groups = [];
    groups.push(groupBox('Receptionist(s)', A.hasReceptionist ? A.receptionists : [], A.hasReceptionist ? 'Add names above' : 'Not used — no dedicated receptionist', A.hasReceptionist && A.expansionModule ? 'Needs phone expansion module' : ''));
    groups.push(groupBox('Mobile App Users', A.mobile ? A.mobileUsers : [], A.mobile ? 'Add names above' : 'Mobile app not in use', A.mobile ? ('License: ' + (LICENSE_LABEL[A.license] || '—')) : ''));
    groups.push(groupBox('Headset Users', A.headsets ? A.headsetUsers : [], A.headsets ? 'Add names above' : 'No headsets requested', ''));
    groups.push(groupBox('Remote Workers (extension)', (A.remote && A.remoteExt) ? A.remoteWorkers : [], (A.remote && A.remoteExt) ? 'Add names above' : (A.remote ? 'Remote, but no extension needed' : 'No remote workers'), ''));
    groups.push(groupBox('DID Holders', A.did ? A.didUsers : [], A.did ? 'Add names above' : 'No individual DIDs requested', ''));
    groups.push(groupBox('Outbound-Restricted Users', A.restrict ? A.restrictedUsers : [], A.restrict ? 'Add names above' : 'No restrictions requested', A.restrict ? A.restrictWhat : ''));

    var flags = [];
    if (A.monitoring) flags.push('Call Monitoring (requires ZAC)');
    if (A.recording) flags.push('Call Recording (requires ZAC)');
    if (A.paging) flags.push('Paging — ' + (A.pagingHardware === 'new' ? 'new hardware required' : 'existing system works'));
    if (A.fax) flags.push('Fax — ' + (A.faxPort ? ('porting, via ' + (A.faxMethod === 'spa' ? 'SPA' : 'eFax')) : 'keeping number with ISP'));

    var vmLine = (A.vmType ? (A.vmType === 'both' ? 'Personal &amp; shared' : (A.vmType.charAt(0).toUpperCase() + A.vmType.slice(1))) : '—') +
      (A.vmCheck.length ? ' · checked via ' + A.vmCheck.map(function (v) { return v === 'zac' ? 'ZAC' : 'voicemail-to-email'; }).join(' & ') : '');

    var treeHtml = A.callTree && A.treeRows.length
      ? '<ul>' + A.treeRows.map(function (r) { return '<li>' + esc(r.a) + ' → ' + esc(r.b) + '</li>'; }).join('') + '</ul>'
      : (A.callTree ? '<div class="empty">Add menu options above</div>' : '<div class="empty">No business-hours call tree — calls go straight to ' + esc(A.inboundHow || 'the front line') + '</div>');

    out.innerHTML =
      '<div class="graphic" id="graphicRoot">' +
        '<div class="flow-node entry"><b>Incoming Call</b></div>' +
        '<div class="flow-arrow">↓</div>' +
        '<div class="flow-node"><b>' + (A.hasReceptionist ? 'Receptionist' + (A.receptionists.length > 1 ? 's' : '') : 'Auto Attendant / Front Line') + '</b><div style="font-size:12px;color:var(--text-faint);margin-top:4px;">' + esc(A.inboundHow || 'How calls are answered — see notes') + '</div></div>' +
        '<div class="flow-arrow">↓</div>' +
        '<div class="flow-node"><b>Voicemail</b><div style="font-size:12px;color:var(--text-faint);margin-top:4px;">' + vmLine + '</div></div>' +
        '<div class="flow-arrow">↓</div>' +
        '<div class="flow-node"><b>After Hours</b><div style="font-size:12px;color:var(--text-faint);margin-top:4px;">' + esc(AFTER_HOURS_LABEL[A.afterHours] || '—') + (A.afterHoursTarget ? ' → ' + esc(A.afterHoursTarget) : '') + '</div></div>' +
        '<div class="group-grid">' + groups.join('') + '</div>' +
        (flags.length ? '<div class="group-box" style="max-width:none;flex-basis:100%;margin-top:14px;"><h4>Feature Flags</h4><ul>' + flags.map(function (f) { return '<li>' + esc(f) + '</li>'; }).join('') + '</ul></div>' : '') +
        '<div class="group-box" style="max-width:none;flex-basis:100%;margin-top:14px;"><h4>Business-Hours Call Tree</h4>' + treeHtml + '</div>' +
      '</div>' +
      '<div class="ref-block"><h3>Project Engineer Reference</h3><div class="ref-grid">' +
        '<div class="ref-item"><b>Current ISP</b>' + esc(A.isp || '—') + (A.ispSpeed ? ' (' + esc(A.ispSpeed) + ')' : '') + '</div>' +
        '<div class="ref-item"><b>Current Carrier</b>' + esc(A.carrier || '—') + '</div>' +
        '<div class="ref-item"><b>Phones Needed</b>' + esc(A.phoneCount || '—') + (A.multi && A.otherLocPhones ? ' (' + esc(A.otherLocPhones) + ' at other locations)' : '') + '</div>' +
        '<div class="ref-item"><b>Extensions</b>' + (A.keepExt ? 'Keeping current extensions' : 'New extension scheme') + '</div>' +
        (A.multi ? '<div class="ref-item"><b>Locations</b>' + (A.locations.length ? esc(A.locations.map(function (l) { return l.a; }).join(', ')) : '—') + '</div>' : '') +
      '</div></div>' +
      '<div class="btn-row">' +
        '<button type="button" class="btn btn-primary" id="emailBtn">Email This Configuration</button>' +
        '<button type="button" class="btn btn-secondary" id="copyBtn">Copy as HTML</button>' +
        '<button type="button" class="btn btn-ghost" id="printBtn">Print</button>' +
      '</div>' +
      '<div class="field" style="margin-top:16px;max-width:360px;"><label class="field-label">Send to</label>' + textInput('sumTo', 'name@company.com', 'sumTo') + '</div>' +
      '<div class="send-status" id="sendStatus"></div>';

    document.getElementById('emailBtn').addEventListener('click', function () { sendEmail(A); });
    document.getElementById('copyBtn').addEventListener('click', function () { copyEmailHtml(A); });
    document.getElementById('printBtn').addEventListener('click', function () { window.print(); });
  }

  // ---------------------------------------------------------------------
  // Branded, Outlook-safe HTML email — table layout + inline styles only,
  // matching the Solution Summary quote email's visual language.
  // ---------------------------------------------------------------------
  function buildEmailHtml(A) {
    var LOGO_URL = 'https://portal.codebluetechnology.com/assets/email/codeblue-logo.png';
    var NAVY = '#182857', LIGHT = '#F4F5F7', BORDER = '#E2E5EA', ACCENT = '#2f8fef';
    var company = (document.getElementById('sumCompany') || {}).value || '';
    var site = (document.getElementById('sumSite') || {}).value || '';
    var preparedBy = (document.getElementById('sumPreparedBy') || {}).value || '';
    var today = new Date();
    var dateText = (today.getMonth() + 1) + '/' + today.getDate() + '/' + today.getFullYear();

    function box(title, entries, emptyText, meta) {
      var rows = entries.length
        ? entries.map(function (e) { return '<div style="font-size:12.5px;font-family:Arial,Helvetica,sans-serif;color:#1B2030;margin-bottom:3px;">• ' + esc(e.a) + (e.b ? ' <span style="color:#6B7280;">— ' + esc(e.b) + '</span>' : '') + '</div>'; }).join('')
        : '<div style="font-size:12px;font-family:Arial,Helvetica,sans-serif;color:#8A93A3;font-style:italic;">' + esc(emptyText) + '</div>';
      return '<td valign="top" width="33%" style="padding:10px;">' +
        '<div style="border:1px solid ' + BORDER + ';border-radius:6px;padding:12px;height:100%;">' +
        '<div style="font-size:10.5px;font-family:Arial,Helvetica,sans-serif;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:' + ACCENT + ';margin-bottom:6px;">' + esc(title) + '</div>' +
        rows + (meta ? '<div style="font-size:11px;font-family:Arial,Helvetica,sans-serif;color:#8A93A3;margin-top:6px;">' + esc(meta) + '</div>' : '') +
        '</div></td>';
    }

    var groupCells = [
      box('Receptionist(s)', A.hasReceptionist ? A.receptionists : [], A.hasReceptionist ? '—' : 'No dedicated receptionist', A.hasReceptionist && A.expansionModule ? 'Needs expansion module' : ''),
      box('Mobile App Users', A.mobile ? A.mobileUsers : [], A.mobile ? '—' : 'Not in use', A.mobile ? ('License: ' + (LICENSE_LABEL[A.license] || '—')) : ''),
      box('Headset Users', A.headsets ? A.headsetUsers : [], 'Not requested', ''),
      box('Remote Workers', (A.remote && A.remoteExt) ? A.remoteWorkers : [], A.remote ? 'No extension needed' : 'None', ''),
      box('DID Holders', A.did ? A.didUsers : [], 'None requested', ''),
      box('Outbound-Restricted', A.restrict ? A.restrictedUsers : [], 'None', A.restrict ? A.restrictWhat : '')
    ];
    var groupRows = '';
    for (var i = 0; i < groupCells.length; i += 3) {
      groupRows += '<tr>' + groupCells.slice(i, i + 3).join('') + (groupCells.slice(i, i + 3).length < 3 ? '<td width="' + ((3 - groupCells.slice(i, i + 3).length) * 33) + '%"></td>' : '') + '</tr>';
    }

    var vmLine = (A.vmType ? (A.vmType === 'both' ? 'Personal &amp; shared' : (A.vmType.charAt(0).toUpperCase() + A.vmType.slice(1))) : '—') +
      (A.vmCheck.length ? ' · checked via ' + A.vmCheck.map(function (v) { return v === 'zac' ? 'ZAC' : 'voicemail-to-email'; }).join(' &amp; ') : '');

    var flowSteps = [
      ['Incoming Call', ''],
      [(A.hasReceptionist ? 'Receptionist' + (A.receptionists.length > 1 ? 's' : '') : 'Auto Attendant / Front Line'), esc(A.inboundHow || '')],
      ['Voicemail', vmLine],
      ['After Hours', esc(AFTER_HOURS_LABEL[A.afterHours] || '—') + (A.afterHoursTarget ? ' → ' + esc(A.afterHoursTarget) : '')]
    ];
    var flowHtml = flowSteps.map(function (step, idx) {
      return '<tr><td align="center" style="padding:6px 0;">' +
        '<div style="display:inline-block;background:' + LIGHT + ';border:1px solid ' + BORDER + ';border-radius:8px;padding:10px 18px;min-width:220px;">' +
        '<div style="font-size:13px;font-family:Arial,Helvetica,sans-serif;font-weight:700;color:' + NAVY + ';">' + esc(step[0]) + '</div>' +
        (step[1] ? '<div style="font-size:11.5px;font-family:Arial,Helvetica,sans-serif;color:#6B7280;margin-top:2px;">' + step[1] + '</div>' : '') +
        '</div></td></tr>' +
        (idx < flowSteps.length - 1 ? '<tr><td align="center" style="padding:0;color:#8A93A3;font-size:15px;font-family:Arial,Helvetica,sans-serif;">↓</td></tr>' : '');
    }).join('');

    var flags = [];
    if (A.monitoring) flags.push('Call Monitoring (requires ZAC)');
    if (A.recording) flags.push('Call Recording (requires ZAC)');
    if (A.paging) flags.push('Paging — ' + (A.pagingHardware === 'new' ? 'new hardware required' : 'existing system works'));
    if (A.fax) flags.push('Fax — ' + (A.faxPort ? ('porting, via ' + (A.faxMethod === 'spa' ? 'SPA' : 'eFax')) : 'keeping number with ISP'));
    var flagsHtml = flags.length ? ('<tr><td style="padding:16px;"><div style="border:1px solid ' + BORDER + ';border-radius:6px;padding:12px;">' +
      '<div style="font-size:10.5px;font-family:Arial,Helvetica,sans-serif;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:' + ACCENT + ';margin-bottom:6px;">Feature Flags</div>' +
      flags.map(function (f) { return '<div style="font-size:12.5px;font-family:Arial,Helvetica,sans-serif;color:#1B2030;margin-bottom:3px;">• ' + esc(f) + '</div>'; }).join('') +
      '</div></td></tr>') : '';

    var treeHtml = A.callTree && A.treeRows.length
      ? A.treeRows.map(function (r) { return '<div style="font-size:12.5px;font-family:Arial,Helvetica,sans-serif;color:#1B2030;margin-bottom:3px;">' + esc(r.a) + ' → ' + esc(r.b) + '</div>'; }).join('')
      : '<div style="font-size:12px;font-family:Arial,Helvetica,sans-serif;color:#8A93A3;font-style:italic;">' + (A.callTree ? 'Menu options not specified' : 'No business-hours call tree — calls go straight to ' + esc(A.inboundHow || 'the front line')) + '</div>';

    var refRows = [
      ['Current ISP', esc(A.isp || '—') + (A.ispSpeed ? ' (' + esc(A.ispSpeed) + ')' : '')],
      ['Current Carrier', esc(A.carrier || '—')],
      ['Phones Needed', esc(A.phoneCount || '—') + (A.multi && A.otherLocPhones ? ' (' + esc(A.otherLocPhones) + ' at other locations)' : '')],
      ['Extensions', A.keepExt ? 'Keeping current extensions' : 'New extension scheme']
    ];
    if (A.multi) refRows.push(['Locations', A.locations.length ? esc(A.locations.map(function (l) { return l.a; }).join(', ')) : '—']);
    var refHtml = refRows.map(function (r) { return '<tr><td style="padding:2px 0;color:#5A6472;font-size:12.5px;font-family:Arial,Helvetica,sans-serif;"><strong style="color:#33394A;">' + esc(r[0]) + ':</strong> ' + r[1] + '</td></tr>'; }).join('');

    var metaRows = '';
    if (company) metaRows += '<tr><td style="padding:2px 0;color:#5A6472;font-size:13px;font-family:Arial,Helvetica,sans-serif;"><strong style="color:#33394A;">Prepared for:</strong> ' + esc(company) + '</td></tr>';
    if (site) metaRows += '<tr><td style="padding:2px 0;color:#5A6472;font-size:13px;font-family:Arial,Helvetica,sans-serif;"><strong style="color:#33394A;">Site:</strong> ' + esc(site) + '</td></tr>';
    if (preparedBy) metaRows += '<tr><td style="padding:2px 0;color:#5A6472;font-size:13px;font-family:Arial,Helvetica,sans-serif;"><strong style="color:#33394A;">Prepared by:</strong> ' + esc(preparedBy) + '</td></tr>';

    return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Zultys Call Experience — CodeBlue Technology</title></head>' +
    '<body style="margin:0;padding:0;background:' + LIGHT + ';">' +
    '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:' + LIGHT + ';padding:24px 0;">' +
      '<tr><td align="center">' +
        '<table role="presentation" width="640" cellpadding="0" cellspacing="0" style="width:640px;max-width:640px;background:#FFFFFF;border-radius:8px;border:1px solid ' + BORDER + ';">' +
          '<tr><td style="padding:24px 24px 20px 24px;border-bottom:3px solid ' + NAVY + ';">' +
            '<img src="' + LOGO_URL + '" width="200" alt="CodeBlue Technology" style="display:block;height:auto;width:200px;border:0;" />' +
          '</td></tr>' +
          '<tr><td style="padding:20px 24px 8px 24px;">' +
            '<div style="font-size:20px;font-family:Arial,Helvetica,sans-serif;font-weight:800;color:' + NAVY + ';">Zultys Call Experience</div>' +
            '<table role="presentation" cellpadding="0" cellspacing="0" style="margin-top:8px;">' +
              '<tr><td style="padding:2px 0;color:#5A6472;font-size:13px;font-family:Arial,Helvetica,sans-serif;"><strong style="color:#33394A;">Date:</strong> ' + dateText + '</td></tr>' +
              metaRows +
            '</table>' +
          '</td></tr>' +
          '<tr><td style="padding:8px 16px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0">' + flowHtml + '</table></td></tr>' +
          '<tr><td style="padding:8px 16px 0;"><div style="font-size:10.5px;font-family:Arial,Helvetica,sans-serif;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:' + ACCENT + ';padding:0 6px;">Who’s In Each Group</div></td></tr>' +
          '<tr><td style="padding:6px 6px 0;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0">' + groupRows + '</table></td></tr>' +
          flagsHtml +
          '<tr><td style="padding:6px 16px 16px;"><div style="border:1px solid ' + BORDER + ';border-radius:6px;padding:12px;">' +
            '<div style="font-size:10.5px;font-family:Arial,Helvetica,sans-serif;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:' + ACCENT + ';margin-bottom:6px;">Business-Hours Call Tree</div>' +
            treeHtml + '</div></td></tr>' +
          '<tr><td style="padding:0 24px 8px 24px;"><div style="border-top:1px solid ' + BORDER + ';margin:8px 0 14px;"></div>' +
            '<div style="font-size:12.5px;font-family:Arial,Helvetica,sans-serif;font-weight:700;color:' + NAVY + ';margin-bottom:6px;">Project Engineer Reference</div>' +
            '<table role="presentation" cellpadding="0" cellspacing="0">' + refHtml + '</table>' +
          '</td></tr>' +
          '<tr><td style="padding:0 24px 20px 24px;">' +
            '<div style="font-size:11px;font-family:Arial,Helvetica,sans-serif;color:#8A93A3;font-style:italic;">This is a working design captured on-site and is subject to final review by the CodeBlue Technology Project Engineer.</div>' +
          '</td></tr>' +
          '<tr><td style="padding:16px 24px;background:' + LIGHT + ';border-top:1px solid ' + BORDER + ';text-align:center;">' +
            '<div style="font-size:11.5px;font-family:Arial,Helvetica,sans-serif;color:#7A8393;">CodeBlue Technology &nbsp;|&nbsp; (804) 521-7660 &nbsp;|&nbsp; Service@codebluetechnology.com</div>' +
          '</td></tr>' +
        '</table>' +
      '</td></tr>' +
    '</table>' +
    '</body></html>';
  }

  function sendEmail(A) {
    var status = document.getElementById('sendStatus');
    var to = ((document.getElementById('sumTo') || {}).value || '').trim();
    var emailOk = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(to);
    if (!emailOk) {
      status.textContent = 'Enter a valid recipient email address first.';
      status.className = 'send-status err';
      return;
    }
    status.textContent = 'Sending…';
    status.className = 'send-status';
    var company = (document.getElementById('sumCompany') || {}).value || '';
    var payload = {
      to: to,
      companyName: company,
      subject: 'Zultys Call Experience — ' + (company || 'CodeBlue Technology'),
      html: buildEmailHtml(A),
      website: ''
    };
    fetch('mail/send-quote.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    }).then(function (res) {
      return res.json().catch(function () { return {}; }).then(function (data) {
        if (!res.ok || !data || data.ok !== true) throw new Error((data && data.error) || ('Request failed (' + res.status + ')'));
        return data;
      });
    }).then(function () {
      status.textContent = '✓ Sent to ' + to;
      status.className = 'send-status ok';
    }).catch(function (err) {
      status.textContent = (err && err.message) || 'Could not send — try Copy as HTML instead.';
      status.className = 'send-status err';
    });
  }

  function copyEmailHtml(A) {
    var status = document.getElementById('sendStatus');
    var html = buildEmailHtml(A);
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(html).then(function () {
        status.textContent = '✓ Copied — paste into an email as HTML.';
        status.className = 'send-status ok';
      }).catch(function () {
        status.textContent = 'Could not copy automatically — select and copy manually.';
        status.className = 'send-status err';
      });
    }
  }

  // ---------------------------------------------------------------------
  buildWizard();
  recompute();
})();
