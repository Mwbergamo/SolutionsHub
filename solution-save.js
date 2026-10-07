/*
 * solution-save.js -- save / edit saved solutions to a customer (added 2026-10-07).
 *
 * Adds, on the Solution Summary screen (into <span id="solution-save-mount">):
 *   - "Save to customer"  -> pick the customer, name the solution, attach documents/images, save.
 *   - once a solution is saved or reopened: "Update saved solution" and "Save as new".
 * The saved solution shows up on that customer's dashboard (Relationships > Solutions card), where it can
 * be reopened here (index.html?solution=ID), or deleted.
 *
 * Storage is relationships/api/solutions.php (same login + territory rules as Customer Documents).
 * What is saved: the Hub's working state (Component.getSolutionSnapshot()), the camera-layout photos, and any
 * attached files. Reopening goes through Component.loadSolutionSnapshot().
 *
 * Also handles two URL parameters when the Hub opens:
 *   ?solution=ID                 reopen that saved solution on the Summary screen for editing
 *   ?customer_id=ID&customer_name=NAME   (from the dashboard's "New solution") pre-selects the customer for Save
 */
(function () {
  'use strict';

  var API = 'relationships/api/solutions.php';
  function fileUrl(f) { return 'relationships/' + f.url; } // server urls are relative to relationships/
  var CUSTOMERS_API = 'relationships/api/customers.php';
  var ACCEPT = '.pdf,.doc,.docx,.xls,.xlsx,.xlsm,.csv,.ppt,.pptx,.txt,.rtf,.odt,.ods,.msg,.eml,.png,.jpg,.jpeg,.gif,.webp,.vsd,.vsdx,.zip';

  // meta = what the Hub currently has open: null for an unsaved solution
  var meta = { id: 0, name: '', customerId: 0, customerName: '', files: [], link: '', cwLinks: [] };
  var lk = null; // ConnectWise-link picker state, null when closed
  var dlg = null; // modal state, null when closed
  var mountEl = null;
  var seq = 0;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function app() { return window.SolutionsHubApp && window.SolutionsHubApp.instance; }
  function sizeText(n) {
    if (n >= 1048576) return (n / 1048576).toFixed(1) + ' MB';
    if (n >= 1024) return Math.round(n / 1024) + ' KB';
    return n + ' B';
  }
  function jsonFetch(url, opts) {
    return fetch(url, Object.assign({ credentials: 'same-origin' }, opts || {})).then(function (r) {
      return r.json().catch(function () { return { ok: false, error: 'Unexpected response (HTTP ' + r.status + ').' }; })
        .then(function (data) { data._status = r.status; return data; });
    });
  }

  // ---- styles ---------------------------------------------------------------------------------
  function injectStyles() {
    if (document.getElementById('ss-styles')) return;
    var css = [
      '.ss-btn{border:none;border-radius:999px;padding:11px 20px;font-size:13.5px;font-weight:700;cursor:pointer;background:#2f8fef;color:#fff}',
      '.ss-btn.alt{background:transparent;border:1px solid var(--cbt-border-control,#3a4258);color:var(--cbt-text-on-dark-primary,inherit)}',
      '.ss-btn[disabled]{opacity:.55;cursor:default}',
      '.ss-banner{flex-basis:100%;font-size:12.5px;font-weight:600;color:var(--cbt-text-on-dark-muted,#8b93a7)}',
      '.ss-banner b{color:var(--cbt-text-on-dark-primary,inherit)}',
      '.ss-back{position:fixed;inset:0;z-index:2000;background:rgba(8,11,18,.62);display:flex;align-items:center;justify-content:center;padding:16px}',
      '.ss-modal{font-family:"Libre Franklin",system-ui,-apple-system,"Segoe UI",sans-serif;width:min(560px,100%);max-height:92vh;overflow:auto;background:var(--cbt-card-bg,#12161f);color:var(--cbt-text-on-light-primary,#fff);border-radius:16px;padding:22px 22px 18px;box-shadow:0 20px 60px rgba(0,0,0,.45);font-family:inherit}',
      '.ss-h{font-size:19px;font-weight:700}',
      '.ss-sub{margin-top:3px;font-size:12.5px;font-weight:600;color:var(--cbt-text-on-dark-muted,#8b93a7)}',
      '.ss-lbl{margin-top:16px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--cbt-text-on-dark-faint,#7b839a)}',
      '.ss-in,.ss-btn,.ss-res,.ss-link{font-family:inherit}',
      '.ss-in{margin-top:6px;width:100%;box-sizing:border-box;font-size:14px;padding:10px 12px;border-radius:8px;border:1px solid var(--cbt-bg-control-5,#3a4258);background:var(--cbt-bg-panel,#0c1018);color:inherit}',
      '.ss-chip{margin-top:6px;display:flex;align-items:center;justify-content:space-between;gap:10px;padding:9px 12px;border-radius:8px;border:1px solid #2f8fef;font-size:14px;font-weight:700}',
      '.ss-link{border:none;background:none;color:#2f8fef;font-weight:700;font-size:12.5px;cursor:pointer;padding:2px 4px}',
      '.ss-results{margin-top:4px;border:1px solid var(--cbt-card-border,#2a3144);border-radius:8px;max-height:190px;overflow:auto}',
      '.ss-res{display:block;width:100%;text-align:left;border:none;background:transparent;color:inherit;font-size:13.5px;font-weight:600;padding:9px 12px;cursor:pointer}',
      '.ss-res:hover{background:var(--cbt-bg-control-2,rgba(47,143,239,.12))}',
      '.ss-res small{color:var(--cbt-text-on-dark-muted,#8b93a7);font-weight:600;margin-left:6px}',
      '.ss-empty{padding:10px 12px;font-size:12.5px;font-weight:600;color:var(--cbt-text-on-dark-muted,#8b93a7)}',
      '.ss-files{margin-top:8px;display:flex;flex-direction:column;gap:6px}',
      '.ss-file{display:flex;align-items:center;gap:10px;padding:7px 10px;border-radius:8px;border:1px solid var(--cbt-card-border,#2a3144);font-size:13px;font-weight:600}',
      '.ss-file span.n{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}',
      '.ss-file span.s{font-size:11.5px;color:var(--cbt-text-on-dark-muted,#8b93a7);white-space:nowrap}',
      '.ss-drop{margin-top:8px;border:2px dashed var(--cbt-card-border,#2a3144);border-radius:10px;padding:14px;text-align:center;font-size:13px;font-weight:600;color:var(--cbt-text-on-dark-muted,#8b93a7);cursor:pointer}',
      '.ss-drop.over{border-color:#2f8fef;color:#2f8fef}',
      '.ss-err{margin-top:12px;font-size:13px;font-weight:700;color:var(--cbt-danger-text,#ff6b6b)}',
      '.ss-foot{margin-top:20px;display:flex;justify-content:flex-end;gap:10px;flex-wrap:wrap}'
    ].join('\n');
    var st = document.createElement('style');
    st.id = 'ss-styles';
    st.textContent = css;
    document.head.appendChild(st);
  }

  // ---- summary-screen buttons -----------------------------------------------------------------
  function renderMount() {
    if (!mountEl) return;
    var a = app();
    var info = a ? a.solutionSaveInfo() : { count: 0 };
    var h = '';
    if (meta.id) {
      h += '<button type="button" class="ss-btn" data-ss="update"' + (info.count ? '' : ' disabled') + '>Update saved solution</button>' +
        '<button type="button" class="ss-btn alt" data-ss="save-new"' + (info.count ? '' : ' disabled') + '>Save as new</button>' +
        '<button type="button" class="ss-btn alt" data-ss="copy-link" title="Copy this solution\'s unique link">Copy link</button>' +
        '<button type="button" class="ss-btn alt" data-ss="open-link" title="Post this solution\'s link as a comment on a pre-sales ConnectWise project">Link to ConnectWise project</button>' +
        '<div class="ss-banner">Editing saved solution <b>' + esc(meta.name) + '</b> for <b>' + esc(meta.customerName) + '</b>. “Update” replaces the saved copy on the customer\'s dashboard.' +
        (meta.cwLinks.length ? '<br>Linked in ConnectWise: ' + meta.cwLinks.map(function (l) { return '<b>' + esc(l.project_name || ('Project #' + l.project_id)) + '</b>' + (l.status === 'failed' ? ' (note failed)' : ''); }).join(', ') : '') + '</div>';
    } else {
      h += '<button type="button" class="ss-btn" data-ss="save-new"' + (info.count ? '' : ' disabled') + ' title="' + (info.count ? 'Save this solution to a customer\'s dashboard' : 'Add something to the solution first') + '">Save to customer</button>';
    }
    if (mountEl.getAttribute('data-ss-html') !== h) {
      mountEl.innerHTML = h;
      mountEl.setAttribute('data-ss-html', h);
    }
  }

  function tryMount() {
    var el = document.getElementById('solution-save-mount');
    if (!el) { mountEl = null; return; }
    if (el !== mountEl) { mountEl = el; el.removeAttribute('data-ss-html'); }
    renderMount();
  }

  // ---- modal ----------------------------------------------------------------------------------
  function openDialog(mode) {
    var a = app();
    if (!a) return;
    var info = a.solutionSaveInfo();
    var asNew = mode === 'new';
    var pre = window.__ssPreselect || null;
    dlg = {
      mode: asNew ? 'new' : 'update',
      customerId: asNew ? (pre ? pre.id : 0) : meta.customerId,
      customerName: asNew ? (pre ? pre.name : '') : meta.customerName,
      name: asNew ? (meta.id ? meta.name + ' (copy)' : suggestName(info)) : meta.name,
      query: '', results: null, searching: false,
      keep: asNew ? [] : meta.files.filter(function (f) { return f.kind === 'attachment'; }).slice(),
      added: [], busy: false, error: ''
    };
    renderDialog();
  }
  function suggestName(info) {
    var who = info.companyName || '';
    var pillar = info.pillars.length === 1 ? info.pillars[0] : (info.pillars.length ? 'Multi-service' : '');
    var d = new Date();
    return (who ? who + ' — ' : '') + (pillar || 'Solution') + ' ' + (d.getMonth() + 1) + '/' + d.getDate() + '/' + d.getFullYear();
  }
  function closeDialog() {
    dlg = null;
    var m = document.getElementById('ss-modal-root');
    if (m && m.parentNode) m.parentNode.removeChild(m);
  }

  function renderDialog() {
    if (!dlg) return;
    var a = app();
    var info = a.solutionSaveInfo();
    var photoCount = ((a.state.cameraPlan && a.state.cameraPlan.photos) || []).length;
    var h = '<div class="ss-back" data-ss="back"><div class="ss-modal" role="dialog" aria-modal="true">' +
      '<div class="ss-h">' + (dlg.mode === 'update' ? 'Update saved solution' : 'Save solution to customer') + '</div>' +
      '<div class="ss-sub">' + info.count + (info.count === 1 ? ' item' : ' items') + ' across ' + (info.pillars.join(', ') || 'no service') + '. It will appear on the customer\'s dashboard under Solutions.</div>';

    h += '<div class="ss-lbl">Customer</div>';
    if (dlg.customerId && dlg.mode === 'update') {
      h += '<div class="ss-chip"><span>' + esc(dlg.customerName) + '</span></div>';
    } else if (dlg.customerId) {
      h += '<div class="ss-chip"><span>' + esc(dlg.customerName) + '</span><button type="button" class="ss-link" data-ss="change-customer">Change</button></div>';
    } else {
      h += '<input class="ss-in" id="ss-cust" type="text" placeholder="Search customers by name or contact…" value="' + esc(dlg.query) + '" autocomplete="off">';
      if (dlg.searching) h += '<div class="ss-results"><div class="ss-empty">Searching…</div></div>';
      else if (dlg.results) {
        h += '<div class="ss-results">' + (dlg.results.length ? dlg.results.map(function (c) {
          return '<button type="button" class="ss-res" data-ss="pick" data-id="' + c.id + '" data-name="' + esc(c.name) + '">' + esc(c.name) +
            (c.is_prospect_only ? '<small>prospect</small>' : '') + (c.matched_contact_name ? '<small>' + esc(c.matched_contact_name) + '</small>' : '') + '</button>';
        }).join('') : '<div class="ss-empty">No customers found.</div>') + '</div>';
      }
    }

    h += '<div class="ss-lbl">Solution name</div><input class="ss-in" id="ss-name" type="text" maxlength="200" value="' + esc(dlg.name) + '">';

    h += '<div class="ss-lbl">Documents &amp; images</div>';
    var rows = '';
    dlg.keep.forEach(function (f) {
      rows += '<div class="ss-file"><span class="n">' + esc(f.name) + '</span><span class="s">' + sizeText(f.size_bytes) + '</span><button type="button" class="ss-link" data-ss="rm-keep" data-id="' + f.id + '">Remove</button></div>';
    });
    dlg.added.forEach(function (f, i) {
      rows += '<div class="ss-file"><span class="n">' + esc(f.name) + '</span><span class="s">' + sizeText(f.size) + ' · new</span><button type="button" class="ss-link" data-ss="rm-added" data-i="' + i + '">Remove</button></div>';
    });
    if (rows) h += '<div class="ss-files">' + rows + '</div>';
    h += '<div class="ss-drop" id="ss-drop" data-ss="choose">Drop files here or tap to choose (Word, PDF, Excel, images, Visio… up to 100 MB each)</div>' +
      '<input type="file" id="ss-file" multiple accept="' + ACCEPT + '" style="display:none">';
    if (photoCount) h += '<div class="ss-sub" style="margin-top:8px">' + photoCount + ' camera layout ' + (photoCount === 1 ? 'photo is' : 'photos are') + ' saved with the solution automatically.</div>';

    if (dlg.error) h += '<div class="ss-err">' + esc(dlg.error) + '</div>';
    h += '<div class="ss-foot"><button type="button" class="ss-btn alt" data-ss="cancel"' + (dlg.busy ? ' disabled' : '') + '>Cancel</button>' +
      '<button type="button" class="ss-btn" data-ss="submit"' + (dlg.busy ? ' disabled' : '') + '>' + (dlg.busy ? 'Saving…' : (dlg.mode === 'update' ? 'Update solution' : 'Save solution')) + '</button></div>' +
      '</div></div>';

    var wrap = document.getElementById('ss-modal-root');
    if (!wrap) { wrap = document.createElement('div'); wrap.id = 'ss-modal-root'; document.body.appendChild(wrap); }
    // keep the typing focus/caret across redraws
    var ae = document.activeElement, focusId = ae && ae.id, pos = ae && ae.selectionStart;
    wrap.innerHTML = h;
    if (focusId) {
      var f = document.getElementById(focusId);
      if (f) { f.focus(); try { f.setSelectionRange(pos, pos); } catch (e) { /* not a text field */ } }
    }
  }

  var searchTimer = null;
  function searchCustomers(q) {
    clearTimeout(searchTimer);
    if (q.trim().length < 2) { dlg.results = null; dlg.searching = false; renderDialog(); return; }
    dlg.searching = true;
    searchTimer = setTimeout(function () {
      jsonFetch(CUSTOMERS_API + '?action=list&q=' + encodeURIComponent(q.trim())).then(function (r) {
        if (!dlg || dlg.query !== q) return;
        dlg.searching = false;
        dlg.results = r.ok ? r.customers.slice(0, 25) : [];
        if (!r.ok) dlg.error = r.error || 'Could not search customers.';
        renderDialog();
      }).catch(function () { if (dlg) { dlg.searching = false; dlg.error = 'Could not reach the server.'; renderDialog(); } });
    }, 250);
  }

  function addFiles(list) {
    Array.prototype.forEach.call(list || [], function (f) {
      if (f.size > 100 * 1024 * 1024) { dlg.error = '"' + f.name + '" is larger than 100 MB.'; return; }
      dlg.added.push(f);
    });
    renderDialog();
  }

  // ---- saving ---------------------------------------------------------------------------------
  function submit() {
    var a = app();
    var name = (document.getElementById('ss-name') || {}).value || dlg.name;
    dlg.name = name.trim();
    if (!dlg.customerId) { dlg.error = 'Choose the customer this solution is for.'; renderDialog(); return; }
    if (!dlg.name) { dlg.error = 'Give the solution a name.'; renderDialog(); return; }
    dlg.busy = true; dlg.error = ''; renderDialog();

    var info = a.solutionSaveInfo();
    var snap = a.getSolutionSnapshot();
    var fd = new FormData();
    var isUpdate = dlg.mode === 'update' && meta.id;
    fd.append('id', isUpdate ? String(meta.id) : '0');
    fd.append('customer_id', String(dlg.customerId));
    fd.append('name', dlg.name);
    fd.append('pillars', JSON.stringify(info.pillars));
    fd.append('search_text', dlg.name + '\n' + dlg.customerName + '\n' + info.searchText);
    fd.append('state', JSON.stringify(snap));

    var keep = dlg.keep.map(function (f) { return f.id; });
    var pending = [];
    var photos = (a.state.cameraPlan && a.state.cameraPlan.photos) || [];
    photos.forEach(function (p) {
      var m = /[?&]id=(\d+)/.exec(p.src || '');
      if (isUpdate && m && /relationships\/api\/solutions\.php\?action=file/.test(p.src || '')) { keep.push(parseInt(m[1], 10)); return; }
      // a photo that came from another saved solution (or is a fresh blob) is uploaded as a new file
      pending.push(fetch(p.src).then(function (r) { return r.blob(); }).then(function (b) {
        var base = (p.name || 'Photo').replace(/[^\w\- ]+/g, '').trim() || 'Photo';
        return { file: new File([b], base + '.jpg', { type: 'image/jpeg' }), kind: 'camera_photo', ref: p.id };
      }));
    });
    // Marked-up copy of every photo (numbered cameras drawn on it): attached to the customer's ConnectWise
    // company by the server. A photo whose cameras/name haven't changed since the last save keeps its earlier copy.
    var W = window.CameraPlanWidget;
    if (W && W.renderMarked) {
      photos.forEach(function (p) {
        var sig = photoSig(p);
        var ref = p.id + ':' + sig;
        var old = isUpdate ? meta.files.filter(function (f) { return f.kind === 'camera_marked' && f.ref === ref; })[0] : null;
        if (old) { keep.push(old.id); return; }
        pending.push(W.renderMarked(p, 1600, 0.85).then(function (b) {
          var base = (p.name || 'Photo').replace(/[^\w\- ]+/g, '').trim() || 'Photo';
          return { file: new File([b], base + ' (cameras marked).jpg', { type: 'image/jpeg' }), kind: 'camera_marked', ref: ref };
        }));
      });
    }
    dlg.added.forEach(function (f) { pending.push(Promise.resolve({ file: f, kind: 'attachment', ref: '' })); });

    Promise.all(pending).then(function (items) {
      fd.append('keep_file_ids', JSON.stringify(keep));
      items.forEach(function (it) {
        fd.append('files[]', it.file, it.file.name);
        fd.append('file_kinds[]', it.kind);
        fd.append('file_refs[]', it.ref);
      });
      return jsonFetch(API + '?action=save', { method: 'POST', body: fd });
    }).then(function (r) {
      if (!r.ok) {
        dlg.busy = false;
        dlg.error = r._status === 401 ? 'You are not signed in to Relationships. Sign in and try again.' : (r.error || 'Could not save the solution.');
        renderDialog();
        return;
      }
      var s = r.solution;
      meta = { id: s.id, name: s.name, customerId: s.customer_id, customerName: s.customer_name, files: s.files || [], link: s.link || '', cwLinks: s.cw_links || [] };
      adoptServerPhotos(s.files || []);
      closeDialog();
      renderMount();
      var failed = (s.files || []).filter(function (f) { return f.kind === 'camera_marked' && f.cw_upload_status === 'failed'; });
      var skipped = (s.files || []).filter(function (f) { return f.kind === 'camera_marked' && f.cw_upload_status === 'skipped'; });
      if (failed.length) {
        toast('Saved, but ' + failed.length + (failed.length === 1 ? ' photo' : ' photos') + ' could not be attached in ConnectWise (' + (failed[0].cw_upload_error || 'unknown error').slice(0, 120) + '). Retry from the customer\'s dashboard.', true);
      } else if (skipped.length) {
        toast('Saved. This customer has no ConnectWise company, so the photos were not attached there.', true);
      } else
      toast((r.created ? 'Saved' : 'Updated') + ' “' + s.name + '” on ' + s.customer_name + '’s dashboard.' + (r.created ? ' You can now copy its link or link it to a ConnectWise project.' : ''));
    }).catch(function (e) {
      dlg.busy = false;
      dlg.error = 'Could not save the solution: ' + (e && e.message ? e.message : 'network problem') + '. Check your connection and try again.';
      renderDialog();
    });
  }

  // short fingerprint of what a marked-up picture shows (its name, cameras and where they sit)
  function photoSig(p) {
    var str = (p.name || '') + '|' + (p.markers || []).map(function (m) { return [m.type, m.color, Math.round(m.x * 10), Math.round(m.y * 10)].join(','); }).join(';');
    var h = 5381;
    for (var i = 0; i < str.length; i++) h = ((h << 5) + h + str.charCodeAt(i)) | 0;
    return (h >>> 0).toString(36);
  }

  // After a save the camera photos exist on the server: point the Hub's copies at them so the next
  // "Update" keeps them instead of uploading again.
  function adoptServerPhotos(files) {
    var a = app();
    var photos = (a.state.cameraPlan && a.state.cameraPlan.photos) || [];
    if (!photos.length) return;
    var next = photos.map(function (p) {
      var f = files.filter(function (x) { return x.kind === 'camera_photo' && x.ref === p.id; })[0];
      return f ? { id: p.id, name: p.name, src: fileUrl(f), markers: p.markers } : p;
    });
    a.setState({ cameraPlan: { photos: next } });
    if (window.CameraPlanWidget) window.CameraPlanWidget.refresh();
  }

  function toast(msg, warn) {
    var t = document.createElement('div');
    t.textContent = msg;
    t.style.cssText = 'position:fixed;left:50%;bottom:28px;transform:translateX(-50%);z-index:2100;background:' + (warn ? '#b45309' : '#16a34a') + ';color:#fff;font:700 13.5px/1.3 system-ui,-apple-system,Segoe UI,sans-serif;padding:12px 18px;border-radius:999px;box-shadow:0 8px 28px rgba(0,0,0,.35);max-width:90vw;text-align:center';
    document.body.appendChild(t);
    setTimeout(function () { if (t.parentNode) t.parentNode.removeChild(t); }, warn ? 9000 : 4200);
  }


  // ---- unique link / ConnectWise project note ---------------------------------------------------
  function copyText(text) {
    function fallback() {
      var ta = document.createElement('textarea');
      ta.value = text; ta.style.cssText = 'position:fixed;left:-9999px;top:0';
      document.body.appendChild(ta); ta.select();
      var ok = false;
      try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
      document.body.removeChild(ta);
      return ok;
    }
    if (navigator.clipboard && navigator.clipboard.writeText) {
      return navigator.clipboard.writeText(text).then(function () { return true; }, function () { return fallback(); });
    }
    return Promise.resolve(fallback());
  }
  function copyLink() {
    if (!meta.link) return;
    copyText(meta.link).then(function (ok) { toast(ok ? 'Link copied — paste it into ConnectWise.' : 'Copy failed. Link: ' + meta.link); });
  }

  function openLinkDialog() {
    if (!meta.id) return;
    lk = { loading: true, projects: [], q: '', error: '', busy: false, warning: '' };
    renderLink();
    jsonFetch(API + '?action=projects&id=' + meta.id).then(function (r) {
      if (!lk) return;
      lk.loading = false;
      if (r.ok) lk.projects = r.projects; else lk.error = r.error || 'Could not load the ConnectWise projects.';
      renderLink();
    }).catch(function () { if (lk) { lk.loading = false; lk.error = 'Could not reach the server.'; renderLink(); } });
  }
  function closeLink() {
    lk = null;
    var m = document.getElementById('ss-modal-root');
    if (m && m.parentNode) m.parentNode.removeChild(m);
  }
  function renderLink() {
    if (!lk) return;
    var q = lk.q.trim().toLowerCase();
    var list = lk.projects.filter(function (p) { return !q || (p.name + ' ' + p.company_name).toLowerCase().indexOf(q) >= 0; });
    var h = '<div class="ss-back" data-ss="lk-back"><div class="ss-modal" role="dialog" aria-modal="true">' +
      '<div class="ss-h">Link to a ConnectWise project</div>' +
      '<div class="ss-sub">Posts a Comment note on the pre-sales project with <b>' + esc(meta.name) + '</b> and its link, so the project points straight back to this solution.</div>' +
      '<div class="ss-lbl">Link</div><div class="ss-chip"><span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:12.5px">' + esc(meta.link) + '</span><button type="button" class="ss-link" data-ss="copy-link">Copy</button></div>' +
      '<div class="ss-lbl">Pre-sales project</div><input class="ss-in" id="ss-lk-q" type="text" placeholder="Filter by project or company…" value="' + esc(lk.q) + '" autocomplete="off">';
    if (lk.loading) h += '<div class="ss-results"><div class="ss-empty">Loading projects from ConnectWise…</div></div>';
    else {
      h += '<div class="ss-results" style="max-height:260px">' + (list.length ? list.slice(0, 100).map(function (p) {
        var done = meta.cwLinks.some(function (l) { return l.project_id === p.id && l.status !== 'failed'; });
        return '<button type="button" class="ss-res" data-ss="lk-pick" data-id="' + p.id + '" data-name="' + esc(p.name) + '"' + (lk.busy ? ' disabled' : '') + '>' + esc(p.name) +
          '<small>' + esc(p.company_name) + (p.status_name ? ' · ' + esc(p.status_name) : '') + '</small>' +
          (p.same_customer ? '<small style="color:#2f8fef">this customer</small>' : '') + (done ? '<small style="color:#16a34a">already linked</small>' : '') + '</button>';
      }).join('') : '<div class="ss-empty">No matching open pre-sales projects.</div>') + '</div>';
    }
    if (lk.error) h += '<div class="ss-err">' + esc(lk.error) + '</div>';
    if (lk.warning) h += '<div class="ss-err">' + esc(lk.warning) + '</div>';
    h += '<div class="ss-foot"><button type="button" class="ss-btn alt" data-ss="lk-close"' + (lk.busy ? ' disabled' : '') + '>' + (lk.busy ? 'Posting…' : 'Close') + '</button></div></div></div>';
    var wrap = document.getElementById('ss-modal-root');
    if (!wrap) { wrap = document.createElement('div'); wrap.id = 'ss-modal-root'; document.body.appendChild(wrap); }
    var had = document.activeElement && document.activeElement.id === 'ss-lk-q', pos = had ? document.activeElement.selectionStart : 0;
    var scroll = wrap.querySelector('.ss-results') ? wrap.querySelector('.ss-results').scrollTop : 0;
    wrap.innerHTML = h;
    var inp = document.getElementById('ss-lk-q');
    if (had && inp) { inp.focus(); try { inp.setSelectionRange(pos, pos); } catch (e) { /* noop */ } }
    var rs = wrap.querySelector('.ss-results'); if (rs) rs.scrollTop = scroll;
  }
  function pickProject(id, name) {
    if (lk.busy) return;
    lk.busy = true; lk.error = ''; lk.warning = ''; renderLink();
    jsonFetch(API + '?action=link_project', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id: meta.id, project_id: id, project_name: name }) }).then(function (r) {
      if (!lk) return;
      lk.busy = false;
      if (!r.ok) { lk.error = r.error || 'Could not link the project.'; renderLink(); return; }
      meta.cwLinks = r.links || meta.cwLinks;
      renderMount();
      if (r.cw_warning) { lk.warning = r.cw_warning; renderLink(); return; }
      closeLink();
      toast('Posted the link on ConnectWise project “' + name + '”.');
    }).catch(function () { if (lk) { lk.busy = false; lk.error = 'Could not reach the server.'; renderLink(); } });
  }

  // ---- reopening a saved solution -------------------------------------------------------------
  function openSaved(id) {
    jsonFetch(API + '?action=get&id=' + encodeURIComponent(id)).then(function (r) {
      var a = app();
      if (!r.ok || !a) {
        toast(r._status === 401 ? 'Sign in to Relationships to open saved solutions.' : (r.error || 'Could not open that saved solution.'));
        return;
      }
      var s = r.solution;
      var snap = s.state || {};
      var photos = ((snap.cameraPlan && snap.cameraPlan.photos) || []).map(function (p) {
        var f = (s.files || []).filter(function (x) { return x.kind === 'camera_photo' && x.ref === p.id; })[0];
        return { id: p.id, name: p.name, src: f ? fileUrl(f) : '', markers: p.markers || [] };
      }).filter(function (p) { return p.src; });
      snap.cameraPlan = { photos: photos };
      meta = { id: s.id, name: s.name, customerId: s.customer_id, customerName: s.customer_name, files: s.files || [], link: s.link || '', cwLinks: s.cw_links || [] };
      a.loadSolutionSnapshot(snap);
      if (window.CameraPlanWidget) window.CameraPlanWidget.refresh();
      renderMount();
    }).catch(function () { toast('Could not reach the server to open that solution.'); });
  }

  // ---- events ---------------------------------------------------------------------------------
  document.addEventListener('click', function (e) {
    var t = e.target.closest ? e.target.closest('[data-ss]') : null;
    if (!t) return;
    var act = t.getAttribute('data-ss');
    if (act === 'copy-link') { copyLink(); return; }
    if (act === 'open-link') { openLinkDialog(); return; }
    if (act === 'lk-back') { if (e.target === t && lk && !lk.busy) closeLink(); return; }
    if (act === 'lk-close') { if (lk && !lk.busy) closeLink(); return; }
    if (act === 'lk-pick') { if (lk) pickProject(parseInt(t.getAttribute('data-id'), 10), t.getAttribute('data-name')); return; }
    if (act === 'back') { if (e.target === t && dlg && !dlg.busy) closeDialog(); return; }
    if (act === 'update') { openDialog('update'); }
    else if (act === 'save-new') { openDialog('new'); }
    else if (!dlg) return;
    else if (act === 'cancel') { if (!dlg.busy) closeDialog(); }
    else if (act === 'submit') { submit(); }
    else if (act === 'change-customer') { dlg.customerId = 0; dlg.customerName = ''; dlg.query = ''; dlg.results = null; renderDialog(); var i = document.getElementById('ss-cust'); if (i) i.focus(); }
    else if (act === 'pick') { dlg.customerId = parseInt(t.getAttribute('data-id'), 10); dlg.customerName = t.getAttribute('data-name'); dlg.results = null; dlg.error = ''; renderDialog(); }
    else if (act === 'rm-keep') { var id = parseInt(t.getAttribute('data-id'), 10); dlg.keep = dlg.keep.filter(function (f) { return f.id !== id; }); renderDialog(); }
    else if (act === 'rm-added') { dlg.added.splice(parseInt(t.getAttribute('data-i'), 10), 1); renderDialog(); }
    else if (act === 'choose') { var inp = document.getElementById('ss-file'); if (inp) inp.click(); }
  });
  document.addEventListener('input', function (e) {
    if (lk && e.target.id === 'ss-lk-q') { lk.q = e.target.value; renderLink(); return; }
    if (!dlg) return;
    if (e.target.id === 'ss-cust') { dlg.query = e.target.value; dlg.error = ''; searchCustomers(dlg.query); }
    else if (e.target.id === 'ss-name') { dlg.name = e.target.value; }
  });
  document.addEventListener('change', function (e) {
    if (dlg && e.target.id === 'ss-file') { addFiles(e.target.files); e.target.value = ''; }
  });
  document.addEventListener('dragover', function (e) {
    var d = e.target.closest ? e.target.closest('#ss-drop') : null;
    if (dlg && d) { e.preventDefault(); d.classList.add('over'); }
  });
  document.addEventListener('dragleave', function (e) {
    var d = e.target.closest ? e.target.closest('#ss-drop') : null;
    if (d) d.classList.remove('over');
  });
  document.addEventListener('drop', function (e) {
    var d = e.target.closest ? e.target.closest('#ss-drop') : null;
    if (dlg && d) { e.preventDefault(); d.classList.remove('over'); addFiles(e.dataTransfer && e.dataTransfer.files); }
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && dlg && !dlg.busy) closeDialog();
    if (e.key === 'Escape' && lk && !lk.busy) closeLink();
  });

  // ---- boot -----------------------------------------------------------------------------------
  function start() {
    injectStyles();
    tryMount();
    new MutationObserver(function () { tryMount(); }).observe(document.body, { childList: true, subtree: true });
    // the Summary buttons depend on the solution's contents -- refresh them as the Hub redraws
    setInterval(function () { if (mountEl && document.body.contains(mountEl)) renderMount(); else tryMount(); }, 700);

    var q = new URLSearchParams(window.location.search);
    var solId = parseInt(q.get('solution') || '0', 10);
    var cid = parseInt(q.get('customer_id') || '0', 10);
    if (cid > 0) window.__ssPreselect = { id: cid, name: q.get('customer_name') || ('Customer #' + cid) };
    if (solId > 0 || cid > 0) {
      var tries = 0;
      var iv = setInterval(function () {
        tries += 1;
        if (app()) {
          clearInterval(iv);
          if (solId > 0) openSaved(solId);
        } else if (tries > 300) clearInterval(iv);
      }, 100);
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();

  window.SolutionSave = { openSaved: openSaved };
})();
