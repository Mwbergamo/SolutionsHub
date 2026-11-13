/* marketing/app.js — Marketing document hub (added 2026-11-12), per Michael:
   "a filing cabinet" of marketing collateral organized by service pillar --
   upload/download/search PDFs, pptx, xlsx and other files; folders and
   sub-folders reps create and remove routinely; search a company, pick a
   contact's email, attach a file and send it; and a record of what's been
   sent to each company, by whom, and when.

   Vanilla JS, renders straight into #app-root (same approach as
   relationships/app.js and client-finance/app.js) -- full re-render on
   every state change, with the focused-input refocus trick client-finance's
   search box already uses, generalized here to every text input in this
   app (several: global search, company search, folder name, rename,
   subject/note) rather than one hardcoded id. */
(function () {
  'use strict';

  var root = document.getElementById('app-root');

  var EXT_LABEL = {
    pdf: 'PDF', doc: 'DOC', docx: 'DOCX', xls: 'XLS', xlsx: 'XLSX', xlsm: 'XLSM',
    csv: 'CSV', ppt: 'PPT', pptx: 'PPTX', txt: 'TXT', rtf: 'RTF', odt: 'ODT',
    ods: 'ODS', odp: 'ODP', png: 'PNG', jpg: 'JPG', jpeg: 'JPG', gif: 'GIF',
    svg: 'SVG', vsd: 'VSD', vsdx: 'VSD', zip: 'ZIP'
  };
  var EXT_COLOR = {
    pdf: '#d5636d', doc: '#4d7bd6', docx: '#4d7bd6', xls: '#2e9d6b', xlsx: '#2e9d6b',
    xlsm: '#2e9d6b', csv: '#2e9d6b', ppt: '#e0833a', pptx: '#e0833a', txt: '#8a93a6',
    rtf: '#8a93a6', odt: '#4d7bd6', ods: '#2e9d6b', odp: '#e0833a',
    png: '#7a5cd6', jpg: '#7a5cd6', jpeg: '#7a5cd6', gif: '#7a5cd6', svg: '#7a5cd6',
    vsd: '#2aa3b8', vsdx: '#2aa3b8', zip: '#9dc22c'
  };

  var state = {
    user: null,
    error: null,
    pillars: [],
    folders: [],              // flat, every non-trashed folder
    activePillar: null,       // pillar id
    activeFolderId: null,     // currently open folder id
    documents: [],
    docsLoading: false,
    sort: 'date',
    dir: 'desc',
    view: 'browse',           // browse | trash
    searchQuery: '',
    searchResults: null,      // null = not searching; [] = no results
    searchSeq: 0,
    newFolderOpen: false,
    newFolderName: '',
    renamingFolderId: null,
    renameValue: '',
    uploading: false,
    uploadError: null,
    trash: { folders: [], documents: [], loading: false, loaded: false },
    sendModal: null,          // see openSendModal()
    lastFocusId: null
  };

  // ---- helpers ------------------------------------------------------------

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function fmtBytes(n) {
    n = Number(n) || 0;
    if (n < 1024) return n + ' B';
    if (n < 1024 * 1024) return (n / 1024).toFixed(1) + ' KB';
    return (n / (1024 * 1024)).toFixed(1) + ' MB';
  }

  function fmtDate(iso) {
    if (!iso) return '';
    var d = new Date(iso.replace(' ', 'T') + (iso.indexOf('Z') >= 0 ? '' : 'Z'));
    if (isNaN(d.getTime())) return iso;
    return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })
      + ' ' + d.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
  }

  function api(url, opts) {
    opts = opts || {};
    opts.credentials = 'same-origin';
    return fetch(url, opts).then(function (r) {
      return r.json().catch(function () { return null; }).then(function (data) {
        return { status: r.status, data: data };
      });
    });
  }

  function debounce(fn, ms) {
    var t = null;
    return function () {
      var args = arguments;
      clearTimeout(t);
      t = setTimeout(function () { fn.apply(null, args); }, ms);
    };
  }

  function activeFolder() {
    for (var i = 0; i < state.folders.length; i++) {
      if (state.folders[i].id === state.activeFolderId) return state.folders[i];
    }
    return null;
  }

  function childFolders(parentId) {
    return state.folders.filter(function (f) { return f.parent_id === parentId; })
      .sort(function (a, b) { return a.name.localeCompare(b.name); });
  }

  function pillarRoot(pillarId) {
    for (var i = 0; i < state.folders.length; i++) {
      if (state.folders[i].pillar === pillarId && state.folders[i].parent_id === null) return state.folders[i];
    }
    return null;
  }

  function folderPath(folderId) {
    var path = [];
    var f = null;
    for (var i = 0; i < state.folders.length; i++) { if (state.folders[i].id === folderId) { f = state.folders[i]; break; } }
    while (f) {
      path.unshift(f);
      if (f.parent_id === null) break;
      var parent = null;
      for (var j = 0; j < state.folders.length; j++) { if (state.folders[j].id === f.parent_id) { parent = state.folders[j]; break; } }
      f = parent;
    }
    return path;
  }

  // ---- boot -----------------------------------------------------------------

  function boot() {
    api('api/auth.php?action=me').then(function (r) {
      if (r.status === 401) { window.location.href = 'login.html'; return; }
      if (!r.data || !r.data.ok) { state.error = (r.data && r.data.error) || 'Could not load.'; render(); return; }
      state.user = r.data.user;
      loadFolders(true);
    }).catch(function () { state.error = 'Could not reach the server.'; render(); });
  }

  function loadFolders(isBoot) {
    api('api/folders.php?action=list').then(function (r) {
      if (!r.data || !r.data.ok) { state.error = (r.data && r.data.error) || 'Could not load folders.'; render(); return; }
      state.pillars = r.data.pillars;
      state.folders = r.data.folders;
      if (isBoot) {
        state.activePillar = state.pillars[0] ? state.pillars[0].id : null;
        var root0 = pillarRoot(state.activePillar);
        state.activeFolderId = root0 ? root0.id : null;
      }
      render();
      if (state.activeFolderId) loadDocuments();
    }).catch(function () { state.error = 'Could not reach the server.'; render(); });
  }

  function loadDocuments() {
    state.docsLoading = true;
    render();
    var url = 'api/documents.php?action=list&folder_id=' + state.activeFolderId + '&sort=' + state.sort + '&dir=' + state.dir;
    api(url).then(function (r) {
      state.docsLoading = false;
      if (!r.data || !r.data.ok) { state.error = (r.data && r.data.error) || 'Could not load files.'; render(); return; }
      state.documents = r.data.documents;
      render();
    }).catch(function () { state.docsLoading = false; state.error = 'Could not reach the server.'; render(); });
  }

  // ---- navigation ------------------------------------------------------------

  function selectPillar(pillarId) {
    state.view = 'browse';
    state.searchQuery = '';
    state.searchResults = null;
    state.activePillar = pillarId;
    var root0 = pillarRoot(pillarId);
    state.activeFolderId = root0 ? root0.id : null;
    render();
    if (state.activeFolderId) loadDocuments();
  }

  function openFolder(folderId) {
    state.activeFolderId = folderId;
    var f = activeFolder();
    if (f) state.activePillar = f.pillar;
    state.searchQuery = '';
    state.searchResults = null;
    render();
    loadDocuments();
  }

  // ---- folders -----------------------------------------------------------

  function submitNewFolder() {
    var name = state.newFolderName.trim();
    if (!name || !state.activeFolderId) return;
    api('api/folders.php?action=create', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ parent_id: state.activeFolderId, name: name })
    }).then(function (r) {
      if (!r.data || !r.data.ok) { window.alert((r.data && r.data.error) || 'Could not create the folder.'); return; }
      state.newFolderOpen = false;
      state.newFolderName = '';
      loadFolders(false);
    });
  }

  function startRename(folder) {
    state.renamingFolderId = folder.id;
    state.renameValue = folder.name;
    render();
  }

  function submitRename() {
    var name = state.renameValue.trim();
    if (!name) return;
    api('api/folders.php?action=rename', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ folder_id: state.renamingFolderId, name: name })
    }).then(function (r) {
      if (!r.data || !r.data.ok) { window.alert((r.data && r.data.error) || 'Could not rename the folder.'); return; }
      state.renamingFolderId = null;
      loadFolders(false);
    });
  }

  function trashFolder(folder) {
    if (!window.confirm('Move "' + folder.name + '" to Trash? Everything inside it moves too. You can restore it later from Trash.')) return;
    api('api/folders.php?action=trash', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ folder_id: folder.id })
    }).then(function (r) {
      if (!r.data || !r.data.ok) { window.alert((r.data && r.data.error) || 'Could not remove the folder.'); return; }
      if (state.activeFolderId === folder.id) {
        var root0 = pillarRoot(folder.pillar);
        state.activeFolderId = root0 ? root0.id : null;
      }
      loadFolders(false);
    });
  }

  // ---- documents -----------------------------------------------------------

  function setSort(col) {
    if (state.sort === col) { state.dir = state.dir === 'asc' ? 'desc' : 'asc'; }
    else { state.sort = col; state.dir = col === 'name' ? 'asc' : 'desc'; }
    loadDocuments();
  }

  function triggerUpload() {
    var input = document.getElementById('mk-upload-input');
    if (input) input.click();
  }

  function handleUploadFiles(fileList) {
    if (!fileList || !fileList.length || !state.activeFolderId) return;
    state.uploading = true;
    state.uploadError = null;
    render();
    var files = Array.prototype.slice.call(fileList);
    var next = function () {
      if (!files.length) { state.uploading = false; render(); loadDocuments(); return; }
      var file = files.shift();
      var fd = new FormData();
      fd.append('folder_id', String(state.activeFolderId));
      fd.append('file', file);
      api('api/documents.php?action=upload', { method: 'POST', body: fd }).then(function (r) {
        if (!r.data || !r.data.ok) {
          state.uploadError = ((r.data && r.data.error) || 'Upload failed.') + ' (' + file.name + ')';
        }
        next();
      }).catch(function () {
        state.uploadError = 'Could not reach the server. (' + file.name + ')';
        next();
      });
    };
    next();
  }

  function trashDocument(doc) {
    if (!window.confirm('Move "' + doc.original_filename + '" to Trash?')) return;
    api('api/documents.php?action=trash', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ document_id: doc.id })
    }).then(function (r) {
      if (!r.data || !r.data.ok) { window.alert((r.data && r.data.error) || 'Could not remove the file.'); return; }
      loadDocuments();
    });
  }

  function downloadDocument(doc) {
    window.location.href = 'api/documents.php?action=download&id=' + doc.id;
  }

  // ---- search ----------------------------------------------------------

  var runSearch = debounce(function (q) {
    var seq = ++state.searchSeq;
    if (!q) { state.searchResults = null; render(); return; }
    api('api/documents.php?action=search&q=' + encodeURIComponent(q)).then(function (r) {
      if (seq !== state.searchSeq) return;
      state.searchResults = (r.data && r.data.ok) ? r.data.documents : [];
      render();
    });
  }, 250);

  function onSearchInput(v) {
    state.searchQuery = v;
    state.lastFocusId = 'mk-search-input';
    render();
    runSearch(v.trim());
  }

  // ---- trash ---------------------------------------------------------------

  function openTrash() {
    state.view = 'trash';
    state.trash.loading = true;
    render();
    api('api/trash.php?action=list').then(function (r) {
      state.trash.loading = false;
      state.trash.loaded = true;
      if (r.data && r.data.ok) { state.trash.folders = r.data.folders; state.trash.documents = r.data.documents; }
      render();
    });
  }

  function restoreFolder(id) {
    api('api/trash.php?action=restore_folder', {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ folder_id: id })
    }).then(function () { loadFolders(false); openTrash(); });
  }

  function restoreDocument(id) {
    api('api/trash.php?action=restore_document', {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ document_id: id })
    }).then(function () { openTrash(); if (state.activeFolderId) loadDocuments(); });
  }

  function purgeFolder(id, name) {
    if (!window.confirm('Permanently delete "' + name + '" and everything inside it? This can’t be undone.')) return;
    api('api/trash.php?action=purge_folder', {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ folder_id: id })
    }).then(function () { openTrash(); });
  }

  function purgeDocument(id, name) {
    if (!window.confirm('Permanently delete "' + name + '"? This can’t be undone.')) return;
    api('api/trash.php?action=purge_document', {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ document_id: id })
    }).then(function () { openTrash(); });
  }

  // ---- send / history modal -------------------------------------------------

  function openSendModal(doc) {
    state.sendModal = {
      mode: 'send', document: doc,
      companyQuery: '', companyResults: null, companySeq: 0,
      company: null,
      contacts: null, contactsLoading: false,
      contact: null,
      subject: doc.original_filename + ' — from CodeBlue Technology',
      note: '', sending: false, error: null,
      history: null, historyLoading: false
    };
    render();
  }

  function openHistoryLookup() {
    state.sendModal = {
      mode: 'history', document: null,
      companyQuery: '', companyResults: null, companySeq: 0,
      company: null,
      contacts: null, contactsLoading: false,
      contact: null,
      subject: '', note: '', sending: false, error: null,
      history: null, historyLoading: false
    };
    render();
  }

  function closeModal() {
    state.sendModal = null;
    render();
  }

  var runCompanySearch = debounce(function (q) {
    var m = state.sendModal;
    if (!m) return;
    var seq = ++m.companySeq;
    if (!q) { m.companyResults = null; render(); return; }
    api('api/send.php?action=company_search&q=' + encodeURIComponent(q)).then(function (r) {
      if (!state.sendModal || state.sendModal.companySeq !== seq) return;
      if (!r.data || !r.data.ok) {
        // A real failure (bad session, server error) looks identical to a
        // genuine zero-result search unless we surface it here -- show it
        // in the same error banner the rest of this modal already uses,
        // rather than letting it read as "no companies match".
        state.sendModal.companyResults = [];
        state.sendModal.error = (r.data && r.data.error) || ('Company search failed (HTTP ' + r.status + '). Try again.');
        render();
        return;
      }
      state.sendModal.error = null;
      state.sendModal.companyResults = r.data.companies;
      render();
    }).catch(function () {
      if (!state.sendModal || state.sendModal.companySeq !== seq) return;
      state.sendModal.companyResults = [];
      state.sendModal.error = 'Could not reach the server.';
      render();
    });
  }, 250);

  function onCompanyInput(v) {
    if (!state.sendModal) return;
    state.sendModal.companyQuery = v;
    state.lastFocusId = 'mk-company-input';
    render();
    runCompanySearch(v.trim());
  }

  function selectCompany(company) {
    var m = state.sendModal;
    if (!m) return;
    m.company = company;
    m.companyResults = null;
    m.companyQuery = company.name;
    m.contact = null;
    render();

    if (m.mode === 'send') {
      m.contactsLoading = true;
      render();
      api('api/send.php?action=company_contacts&customer_id=' + company.id).then(function (r) {
        if (!state.sendModal) return;
        state.sendModal.contactsLoading = false;
        if (r.data && r.data.ok) { state.sendModal.contacts = r.data.contacts; }
        else { state.sendModal.error = (r.data && r.data.error) || 'Could not load contacts.'; }
        render();
      });
    } else {
      m.historyLoading = true;
      render();
      api('api/send.php?action=history&customer_id=' + company.id).then(function (r) {
        if (!state.sendModal) return;
        state.sendModal.historyLoading = false;
        if (r.data && r.data.ok) { state.sendModal.history = r.data.sends; }
        render();
      });
    }
  }

  function selectContact(contact) {
    if (!state.sendModal) return;
    state.sendModal.contact = contact;
    render();
  }

  function submitSend() {
    var m = state.sendModal;
    if (!m || !m.company || !m.contact || !m.contact.email) return;
    m.sending = true;
    m.error = null;
    render();
    api('api/send.php?action=send', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        document_id: m.document.id,
        customer_id: m.company.id,
        customer_name: m.company.name,
        contact_id: m.contact.id,
        contact_name: (m.contact.first_name + ' ' + m.contact.last_name).trim(),
        contact_email: m.contact.email,
        subject: m.subject,
        note: m.note
      })
    }).then(function (r) {
      if (!state.sendModal) return;
      state.sendModal.sending = false;
      if (!r.data || !r.data.ok) { state.sendModal.error = (r.data && r.data.error) || 'Could not send the email.'; render(); return; }
      window.alert('Sent "' + m.document.original_filename + '" to ' + m.contact.email + '.');
      closeModal();
    }).catch(function () {
      if (!state.sendModal) return;
      state.sendModal.sending = false;
      state.sendModal.error = 'Could not reach the server.';
      render();
    });
  }

  // ---- render ----------------------------------------------------------------

  function pillarTabsHtml() {
    var html = '<div class="mk-pillar-tabs">';
    state.pillars.forEach(function (p) {
      var cls = 'mk-pillar-tab' + (state.view === 'browse' && state.activePillar === p.id ? ' active' : '');
      html += '<button type="button" class="' + cls + '" data-action="select-pillar" data-id="' + esc(p.id) + '">' + esc(p.name) + '</button>';
    });
    html += '</div>';
    return html;
  }

  function breadcrumbHtml() {
    var path = folderPath(state.activeFolderId);
    var html = '<div class="mk-breadcrumb">';
    path.forEach(function (f, i) {
      if (i > 0) html += '<span class="mk-crumb-sep">/</span>';
      html += '<button type="button" class="mk-crumb" data-action="open-folder" data-id="' + f.id + '">' + esc(f.name) + '</button>';
    });
    html += '</div>';
    return html;
  }

  function folderListHtml() {
    var kids = childFolders(state.activeFolderId);
    if (!kids.length) return '';
    var html = '<div class="mk-folder-grid">';
    kids.forEach(function (f) {
      if (state.renamingFolderId === f.id) {
        html += '<div class="mk-folder-card mk-folder-card--editing">'
          + '<input type="text" class="mk-rename-input" id="mk-rename-input" value="' + esc(state.renameValue) + '" autocomplete="off">'
          + '<div class="mk-folder-card-actions">'
          + '<button type="button" class="mk-icon-btn" data-action="submit-rename" title="Save">✓</button>'
          + '<button type="button" class="mk-icon-btn" data-action="cancel-rename" title="Cancel">✕</button>'
          + '</div></div>';
      } else {
        html += '<div class="mk-folder-card">'
          + '<button type="button" class="mk-folder-open" data-action="open-folder" data-id="' + f.id + '">'
          + '<svg viewBox="0 0 24 24" aria-hidden="true" class="mk-folder-icon"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path></svg>'
          + '<span title="' + esc(f.name) + '">' + esc(f.name) + '</span>'
          + '</button>'
          + '<div class="mk-folder-card-actions">'
          + '<button type="button" class="mk-icon-btn" data-action="rename-folder" data-id="' + f.id + '" title="Rename">✎</button>'
          + '<button type="button" class="mk-icon-btn mk-icon-btn--danger" data-action="trash-folder" data-id="' + f.id + '" title="Remove">🗑</button>'
          + '</div></div>';
      }
    });
    html += '</div>';
    return html;
  }

  function sortArrow(col) {
    if (state.sort !== col) return '';
    return state.dir === 'asc' ? ' ↑' : ' ↓';
  }

  function documentsTableHtml() {
    if (state.docsLoading) return '<div class="mk-empty">Loading files…</div>';
    if (!state.documents.length) return '<div class="mk-empty">No files in this folder yet. Drag files in, or use Upload.</div>';
    var html = '<table class="mk-table"><thead><tr>'
      + '<th></th>'
      + '<th class="mk-sortable" data-action="sort" data-col="name">Name' + sortArrow('name') + '</th>'
      + '<th class="mk-sortable" data-action="sort" data-col="type">Type' + sortArrow('type') + '</th>'
      + '<th>Size</th>'
      + '<th class="mk-sortable" data-action="sort" data-col="date">Uploaded' + sortArrow('date') + '</th>'
      + '<th></th>'
      + '</tr></thead><tbody>';
    state.documents.forEach(function (d) {
      html += documentRowHtml(d);
    });
    html += '</tbody></table>';
    return html;
  }

  function documentRowHtml(d) {
    var color = EXT_COLOR[d.ext] || '#8a93a6';
    var label = EXT_LABEL[d.ext] || d.ext.toUpperCase();
    return '<tr>'
      + '<td><span class="mk-ext-chip" style="--c:' + color + '">' + esc(label) + '</span></td>'
      + '<td class="mk-filename">' + esc(d.original_filename) + (d.folder_name ? '<div class="mk-filename-sub">' + esc(d.pillar_label || d.folder_name) + '</div>' : '') + '</td>'
      + '<td>' + esc(label) + '</td>'
      + '<td>' + fmtBytes(d.size_bytes) + '</td>'
      + '<td>' + fmtDate(d.uploaded_at) + '<div class="mk-filename-sub">' + esc(d.uploaded_by_name) + '</div></td>'
      + '<td class="mk-row-actions">'
      + '<button type="button" class="mk-icon-btn" data-action="download-doc" data-id="' + d.id + '" title="Download">⬇</button>'
      + '<button type="button" class="mk-icon-btn" data-action="send-doc" data-id="' + d.id + '" title="Send by email">✉</button>'
      + '<button type="button" class="mk-icon-btn mk-icon-btn--danger" data-action="trash-doc" data-id="' + d.id + '" title="Remove">🗑</button>'
      + '</td></tr>';
  }

  function searchResultsHtml() {
    if (state.searchResults === null) return '';
    if (!state.searchResults.length) return '<div class="mk-empty">No files match "' + esc(state.searchQuery) + '".</div>';
    var pillarName = {};
    state.pillars.forEach(function (p) { pillarName[p.id] = p.name; });
    var html = '<table class="mk-table"><thead><tr><th></th><th>Name</th><th>Pillar / Folder</th><th>Size</th><th>Uploaded</th><th></th></tr></thead><tbody>';
    state.searchResults.forEach(function (d) {
      var color = EXT_COLOR[d.ext] || '#8a93a6';
      var label = EXT_LABEL[d.ext] || d.ext.toUpperCase();
      html += '<tr>'
        + '<td><span class="mk-ext-chip" style="--c:' + color + '">' + esc(label) + '</span></td>'
        + '<td class="mk-filename">' + esc(d.original_filename) + '</td>'
        + '<td>' + esc(pillarName[d.pillar] || d.pillar) + ' / ' + esc(d.folder_name) + '</td>'
        + '<td>' + fmtBytes(d.size_bytes) + '</td>'
        + '<td>' + fmtDate(d.uploaded_at) + '</td>'
        + '<td class="mk-row-actions">'
        + '<button type="button" class="mk-icon-btn" data-action="download-doc" data-id="' + d.id + '" title="Download">⬇</button>'
        + '<button type="button" class="mk-icon-btn" data-action="send-doc" data-id="' + d.id + '" title="Send by email">✉</button>'
        + '</td></tr>';
    });
    html += '</tbody></table>';
    return html;
  }

  function trashViewHtml() {
    if (state.trash.loading) return '<div class="mk-empty">Loading Trash…</div>';
    var html = '<h2 class="mk-view-title">Trash</h2>';
    html += '<p class="mk-view-sub">Removed folders and files stay here until you permanently delete them.</p>';
    html += '<h3 class="mk-subhead">Folders</h3>';
    if (!state.trash.folders.length) {
      html += '<div class="mk-empty">No folders in Trash.</div>';
    } else {
      html += '<table class="mk-table"><thead><tr><th>Name</th><th>Removed</th><th>By</th><th></th></tr></thead><tbody>';
      state.trash.folders.forEach(function (f) {
        html += '<tr><td class="mk-filename">' + esc(f.name) + '</td><td>' + fmtDate(f.deleted_at) + '</td><td>' + esc(f.deleted_by_name) + '</td>'
          + '<td class="mk-row-actions">'
          + '<button type="button" class="mk-btn mk-btn--small" data-action="restore-folder" data-id="' + f.id + '">Restore</button>'
          + '<button type="button" class="mk-btn mk-btn--small mk-btn--danger" data-action="purge-folder" data-id="' + f.id + '" data-name="' + esc(f.name) + '">Delete Permanently</button>'
          + '</td></tr>';
      });
      html += '</tbody></table>';
    }
    html += '<h3 class="mk-subhead">Files</h3>';
    if (!state.trash.documents.length) {
      html += '<div class="mk-empty">No files in Trash.</div>';
    } else {
      html += '<table class="mk-table"><thead><tr><th>Name</th><th>Removed</th><th>By</th><th></th></tr></thead><tbody>';
      state.trash.documents.forEach(function (d) {
        html += '<tr><td class="mk-filename">' + esc(d.original_filename) + '</td><td>' + fmtDate(d.deleted_at) + '</td><td>' + esc(d.deleted_by_name) + '</td>'
          + '<td class="mk-row-actions">'
          + '<button type="button" class="mk-btn mk-btn--small" data-action="restore-doc" data-id="' + d.id + '">Restore</button>'
          + '<button type="button" class="mk-btn mk-btn--small mk-btn--danger" data-action="purge-doc" data-id="' + d.id + '" data-name="' + esc(d.original_filename) + '">Delete Permanently</button>'
          + '</td></tr>';
      });
      html += '</tbody></table>';
    }
    return html;
  }

  function companyPickerHtml(m) {
    var html = '<div class="mk-field">';
    html += '<label>Company</label>';
    html += '<input type="text" class="mk-input" id="mk-company-input" placeholder="Search for a company…" value="' + esc(m.companyQuery) + '" autocomplete="off">';
    if (m.companyResults && m.companyResults.length) {
      html += '<div class="mk-dropdown">';
      m.companyResults.forEach(function (c) {
        html += '<button type="button" class="mk-dropdown-row" data-action="select-company" data-id="' + c.id + '">' + esc(c.name) + '</button>';
      });
      html += '</div>';
    } else if (m.companyResults && !m.companyResults.length) {
      html += '<div class="mk-dropdown"><div class="mk-dropdown-empty">No companies match.</div></div>';
    }
    html += '</div>';
    return html;
  }

  function sendModalHtml() {
    var m = state.sendModal;
    if (!m) return '';
    var title = m.mode === 'send' ? 'Send File' : 'Send History';
    var html = '<div class="mk-modal-backdrop" data-action="close-modal">';
    html += '<div class="mk-modal" data-action="noop">';
    html += '<div class="mk-modal-head"><h3>' + title + '</h3><button type="button" class="mk-icon-btn" data-action="close-modal">✕</button></div>';
    html += '<div class="mk-modal-body">';

    if (m.mode === 'send' && m.document) {
      html += '<div class="mk-send-file"><span class="mk-ext-chip" style="--c:' + (EXT_COLOR[m.document.ext] || '#8a93a6') + '">' + esc(EXT_LABEL[m.document.ext] || m.document.ext.toUpperCase()) + '</span> ' + esc(m.document.original_filename) + '</div>';
    }

    html += companyPickerHtml(m);

    if (m.company && m.mode === 'send') {
      html += '<div class="mk-field"><label>Contact</label>';
      if (m.contactsLoading) {
        html += '<div class="mk-hint">Loading contacts from ConnectWise…</div>';
      } else if (m.contacts && m.contacts.length) {
        html += '<div class="mk-contact-list">';
        m.contacts.forEach(function (c) {
          var hasEmail = !!c.email;
          var selected = m.contact && m.contact.id === c.id;
          html += '<button type="button" class="mk-contact-row' + (selected ? ' selected' : '') + (hasEmail ? '' : ' disabled') + '"'
            + (hasEmail ? ' data-action="select-contact" data-id="' + c.id + '"' : ' disabled')
            + '>'
            + '<span class="mk-contact-name">' + esc((c.first_name + ' ' + c.last_name).trim() || '(unnamed)') + (c.inactive ? ' <span class="mk-tag">Inactive</span>' : '') + '</span>'
            + '<span class="mk-contact-email">' + (hasEmail ? esc(c.email) : 'No email on file') + '</span>'
            + '</button>';
        });
        html += '</div>';
      } else if (m.contacts) {
        html += '<div class="mk-hint">No contacts found for this company.</div>';
      }
      html += '</div>';

      if (m.contact) {
        html += '<div class="mk-field"><label>Subject</label><input type="text" class="mk-input" id="mk-subject-input" value="' + esc(m.subject) + '"></div>';
        html += '<div class="mk-field"><label>Note (optional)</label><textarea class="mk-input mk-textarea" id="mk-note-input" rows="3" placeholder="A short note to include above the attachment…">' + esc(m.note) + '</textarea></div>';
      }

      if (m.error) html += '<div class="mk-banner mk-banner--error">' + esc(m.error) + '</div>';

      html += '<div class="mk-modal-actions">';
      html += '<button type="button" class="mk-btn" data-action="close-modal">Cancel</button>';
      html += '<button type="button" class="mk-btn mk-btn--primary" data-action="submit-send"' + ((!m.contact || m.sending) ? ' disabled' : '') + '>' + (m.sending ? 'Sending…' : 'Send') + '</button>';
      html += '</div>';
    }

    if (m.mode === 'history' && m.company) {
      html += '<h4 class="mk-subhead">Sent to ' + esc(m.company.name) + '</h4>';
      if (m.historyLoading) {
        html += '<div class="mk-hint">Loading…</div>';
      } else if (m.history && m.history.length) {
        html += '<table class="mk-table mk-table--compact"><thead><tr><th>File</th><th>To</th><th>Sent</th><th>By</th></tr></thead><tbody>';
        m.history.forEach(function (s) {
          html += '<tr><td class="mk-filename">' + esc(s.document_filename) + '</td>'
            + '<td>' + esc(s.contact_name) + '<div class="mk-filename-sub">' + esc(s.contact_email) + '</div></td>'
            + '<td>' + fmtDate(s.sent_at) + '</td>'
            + '<td>' + esc(s.sent_by_name) + '</td></tr>';
        });
        html += '</tbody></table>';
      } else if (m.history) {
        html += '<div class="mk-empty">Nothing has been sent to this company yet.</div>';
      }
    }

    html += '</div></div></div>';
    return html;
  }

  function render() {
    if (state.error) {
      root.innerHTML = '<div class="mk-topbar"><div class="mk-brand">Marketing</div></div><div class="mk-empty mk-empty--error">' + esc(state.error) + '</div>';
      return;
    }
    if (!state.user) {
      root.innerHTML = '<div class="loading">Loading…</div>';
      return;
    }

    var html = '<div class="mk-topbar">';
    html += '<div class="mk-topbar-left"><div><div class="mk-brand">Marketing</div><div class="mk-brand-sub">CodeBlue Technology</div></div></div>';
    html += '<div class="mk-topbar-search"><input type="text" class="mk-input" id="mk-search-input" placeholder="Search all files by name…" value="' + esc(state.searchQuery) + '" autocomplete="off"></div>';
    html += '<div class="mk-topbar-right">';
    html += '<button type="button" class="mk-btn" data-action="open-history-lookup">Send History</button>';
    html += '<button type="button" class="mk-btn' + (state.view === 'trash' ? ' mk-btn--active' : '') + '" data-action="open-trash">Trash</button>';
    html += '<a class="mk-back-to-hub" href="../">← Home</a>';
    html += '</div></div>';

    html += '<main class="mk-main">';

    if (state.searchResults !== null) {
      html += '<h2 class="mk-view-title">Search results</h2>';
      html += searchResultsHtml();
    } else if (state.view === 'trash') {
      html += trashViewHtml();
    } else {
      html += pillarTabsHtml();
      html += breadcrumbHtml();
      html += '<div class="mk-toolbar">';
      html += '<button type="button" class="mk-btn" data-action="new-folder">+ New Folder</button>';
      html += '<button type="button" class="mk-btn mk-btn--primary" data-action="trigger-upload">Upload File' + (state.uploading ? 's…' : '') + '</button>';
      html += '<input type="file" id="mk-upload-input" class="mk-visually-hidden" multiple>';
      html += '</div>';
      if (state.uploadError) html += '<div class="mk-banner mk-banner--error">' + esc(state.uploadError) + '</div>';
      if (state.newFolderOpen) {
        html += '<div class="mk-new-folder-row">'
          + '<input type="text" class="mk-input" id="mk-new-folder-input" placeholder="Folder name" value="' + esc(state.newFolderName) + '" autocomplete="off">'
          + '<button type="button" class="mk-btn mk-btn--primary mk-btn--small" data-action="submit-new-folder">Create</button>'
          + '<button type="button" class="mk-btn mk-btn--small" data-action="cancel-new-folder">Cancel</button>'
          + '</div>';
      }
      html += folderListHtml();
      html += documentsTableHtml();
    }

    html += '</main>';
    html += sendModalHtml();

    root.innerHTML = html;

    if (state.lastFocusId) {
      var el = document.getElementById(state.lastFocusId);
      if (el) {
        el.focus();
        if (el.setSelectionRange && typeof el.value === 'string') {
          try { el.setSelectionRange(el.value.length, el.value.length); } catch (e) {}
        }
      }
    }
  }

  // ---- events ----------------------------------------------------------------

  root.addEventListener('input', function (e) {
    var t = e.target;
    if (!t || !t.id) return;
    if (t.id === 'mk-search-input') { onSearchInput(t.value); }
    else if (t.id === 'mk-company-input') { onCompanyInput(t.value); }
    else if (t.id === 'mk-new-folder-input') { state.newFolderName = t.value; }
    else if (t.id === 'mk-rename-input') { state.renameValue = t.value; }
    else if (t.id === 'mk-subject-input' && state.sendModal) { state.sendModal.subject = t.value; }
    else if (t.id === 'mk-note-input' && state.sendModal) { state.sendModal.note = t.value; }
  });

  root.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter') return;
    var t = e.target;
    if (t && t.id === 'mk-new-folder-input') { e.preventDefault(); submitNewFolder(); }
    else if (t && t.id === 'mk-rename-input') { e.preventDefault(); submitRename(); }
  });

  root.addEventListener('change', function (e) {
    if (e.target && e.target.id === 'mk-upload-input') {
      handleUploadFiles(e.target.files);
      e.target.value = '';
    }
  });

  // Drag-and-drop upload straight onto the main area.
  root.addEventListener('dragover', function (e) {
    if (state.view === 'browse' && state.activeFolderId) e.preventDefault();
  });
  root.addEventListener('drop', function (e) {
    if (state.view !== 'browse' || !state.activeFolderId) return;
    if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) {
      e.preventDefault();
      handleUploadFiles(e.dataTransfer.files);
    }
  });

  root.addEventListener('click', function (e) {
    var el = e.target.closest('[data-action]');
    if (!el) return;
    var action = el.getAttribute('data-action');
    var id = el.getAttribute('data-id');

    if (action === 'select-pillar') { selectPillar(el.getAttribute('data-id')); }
    else if (action === 'open-folder') { openFolder(Number(id)); }
    else if (action === 'new-folder') { state.newFolderOpen = true; state.newFolderName = ''; state.lastFocusId = 'mk-new-folder-input'; render(); }
    else if (action === 'cancel-new-folder') { state.newFolderOpen = false; render(); }
    else if (action === 'submit-new-folder') { submitNewFolder(); }
    else if (action === 'rename-folder') {
      var f = state.folders.filter(function (x) { return x.id === Number(id); })[0];
      if (f) { startRename(f); state.lastFocusId = 'mk-rename-input'; render(); }
    }
    else if (action === 'cancel-rename') { state.renamingFolderId = null; render(); }
    else if (action === 'submit-rename') { submitRename(); }
    else if (action === 'trash-folder') {
      var tf = state.folders.filter(function (x) { return x.id === Number(id); })[0];
      if (tf) trashFolder(tf);
    }
    else if (action === 'trigger-upload') { triggerUpload(); }
    else if (action === 'sort') { setSort(el.getAttribute('data-col')); }
    else if (action === 'download-doc') {
      var dd = findDoc(Number(id));
      if (dd) downloadDocument(dd);
    }
    else if (action === 'trash-doc') {
      var td = findDoc(Number(id));
      if (td) trashDocument(td);
    }
    else if (action === 'send-doc') {
      var sd = findDoc(Number(id));
      if (sd) openSendModal(sd);
    }
    else if (action === 'open-trash') { openTrash(); }
    else if (action === 'restore-folder') { restoreFolder(Number(id)); }
    else if (action === 'restore-doc') { restoreDocument(Number(id)); }
    else if (action === 'purge-folder') { purgeFolder(Number(id), el.getAttribute('data-name')); }
    else if (action === 'purge-doc') { purgeDocument(Number(id), el.getAttribute('data-name')); }
    else if (action === 'open-history-lookup') { openHistoryLookup(); }
    else if (action === 'close-modal') { closeModal(); }
    else if (action === 'select-company') {
      var m = state.sendModal;
      var company = (m && m.companyResults || []).filter(function (c) { return c.id === Number(id); })[0];
      if (company) selectCompany(company);
    }
    else if (action === 'select-contact') {
      var m2 = state.sendModal;
      var contact = (m2 && m2.contacts || []).filter(function (c) { return c.id === Number(id); })[0];
      if (contact) selectContact(contact);
    }
    else if (action === 'submit-send') { submitSend(); }
    else if (action === 'noop') { /* swallow bubbling from inside the modal panel */ }
  });

  function findDoc(id) {
    var inFolder = state.documents.filter(function (d) { return d.id === id; })[0];
    if (inFolder) return inFolder;
    return (state.searchResults || []).filter(function (d) { return d.id === id; })[0];
  }

  boot();
})();
