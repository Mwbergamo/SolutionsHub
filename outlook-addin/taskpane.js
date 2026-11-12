/* outlook-addin/taskpane.js -- "Relationships Hub" Outlook add-in
   task pane. Added 2026-11-12 per Michael; see
   claude/relationships-outlook-addin-plan.md for the full product
   design this implements. Vanilla JS + Office.js, same render()-from-
   state approach as relationships/app.js and myday/app.js.

   AUTH: this task pane runs inside Outlook's own sandboxed webview,
   which does NOT reliably share portal.codebluetechnology.com's normal
   session cookie -- so instead of the cookie, a rep signs in once per
   device via a small OAuth dialog (Office.context.ui.displayDialogAsync
   -> auth/addin-login.php -> auth/addin-callback.php), which hands back
   a bearer token that's stored in THIS PAGE'S OWN localStorage (the task
   pane is a persistent page at a fixed URL, so localStorage here behaves
   like any other site's "remember me" -- it's scoped to this add-in's
   origin, not shared with portal.codebluetechnology.com's main site).
   Every API call after that sends `Authorization: Bearer <token>` --
   see relationships_require_login_or_bearer() in
   relationships/api/_util.php. */
(function () {
  'use strict';

  // Must be an ABSOLUTE URL (Office.context.ui.displayDialogAsync does
  // not reliably accept a relative one) and must match this add-in's
  // actual deployed domain -- same domain as manifest.xml's URLs and
  // auth-config.php's addin_redirect_uri. Change all three together if
  // this is ever deployed somewhere else.
  var SIGN_IN_DIALOG_URL = 'https://portal.codebluetechnology.com/auth/addin-login.php';
  var API_URL = '../relationships/api/outlook-addin.php';

  var LS_TOKEN = 'sendToRelationships_token';
  var LS_NAME = 'sendToRelationships_name';
  var LS_EMAIL = 'sendToRelationships_email';

  var DOCUMENT_CATEGORIES = ['General', 'Contract', 'Proposal / Quote', 'Network Diagram', 'Invoice / Billing', 'Meeting Notes', 'Assessment / Report', 'Other'];

  var root = document.getElementById('app-root');
  var dialog = null;
  var searchDebounceTimer = null;

  var state = {
    // 'loading' | 'signin' | 'resolving-customer' | 'pick-customer' | 'menu' | 'file-documents' | 'create-meeting' | 'create-todo' | 'done'
    stage: 'loading',
    error: null,
    busy: false,

    token: null,
    userName: null,

    roster: [],

    email: null, // { subject, senderName, senderEmail, receivedAt, attachments: [...] }
    bodyText: null, // lazily loaded via Office.js, cached once fetched
    bodyTextLoading: false,

    customer: null, // { id, name }
    searchQuery: '',
    searchResults: [],
    searching: false,

    selectedAttachmentIds: {},
    uploadCategory: 'General',

    meetingSubject: '',
    meetingDate: '',
    meetingTime: '',
    meetingNotes: '',

    todoDescription: '',
    todoDueDate: '',
    todoAssignedTo: '',

    doneResult: null, // { kind: 'meeting'|'todo'|'documents', payload }
  };

  // ---- small helpers -------------------------------------------------

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function todayYmd() {
    var d = new Date();
    var m = String(d.getMonth() + 1).padStart(2, '0');
    var day = String(d.getDate()).padStart(2, '0');
    return d.getFullYear() + '-' + m + '-' + day;
  }

  function fmtBytes(n) {
    n = Number(n) || 0;
    if (n < 1024) return n + ' B';
    if (n < 1024 * 1024) return Math.round(n / 1024) + ' KB';
    return (n / (1024 * 1024)).toFixed(1) + ' MB';
  }

  // ---- API calls -------------------------------------------------------

  function apiGet(action, params) {
    var qs = 'action=' + encodeURIComponent(action);
    for (var k in (params || {})) {
      qs += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
    }
    return fetch(API_URL + '?' + qs, {
      headers: { 'Authorization': 'Bearer ' + state.token },
    }).then(function (r) {
      return r.json().then(function (data) { return { status: r.status, data: data }; });
    });
  }

  function apiPostJson(action, body) {
    return fetch(API_URL + '?action=' + encodeURIComponent(action), {
      method: 'POST',
      headers: { 'Authorization': 'Bearer ' + state.token, 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    }).then(function (r) {
      return r.json().then(function (data) { return { status: r.status, data: data }; });
    });
  }

  function apiPostForm(action, formData) {
    return fetch(API_URL + '?action=' + encodeURIComponent(action), {
      method: 'POST',
      headers: { 'Authorization': 'Bearer ' + state.token },
      body: formData,
    }).then(function (r) {
      return r.json().then(function (data) { return { status: r.status, data: data }; });
    });
  }

  // ---- Office.js: reading the open email --------------------------------

  function loadEmailContext() {
    var item = Office.context.mailbox.item;
    var from = item.from || item.sender || {};
    var attachments = (item.attachments || []).filter(function (a) {
      // Only real file attachments -- skip inline images (signature
      // logos, etc.) and non-file attachment types (e.g. a forwarded
      // Outlook item), which getAttachmentContentAsync can't read back
      // as a plain base64 file the same way.
      return !a.isInline && (a.attachmentType === 'file' || a.attachmentType === Office.MailboxEnums.AttachmentType.File);
    });

    state.email = {
      subject: item.subject || '',
      senderName: from.displayName || '',
      senderEmail: from.emailAddress || '',
      receivedAt: item.dateTimeCreated ? new Date(item.dateTimeCreated).toString() : '',
      attachments: attachments.map(function (a) {
        return { id: a.id, name: a.name, size: a.size, contentType: a.contentType };
      }),
    };
    state.meetingSubject = state.email.subject;
  }

  function ensureBodyTextLoaded(callback) {
    if (state.bodyText !== null) {
      callback(state.bodyText);
      return;
    }
    state.bodyTextLoading = true;
    render();
    Office.context.mailbox.item.body.getAsync(Office.CoercionType.Text, function (result) {
      state.bodyTextLoading = false;
      state.bodyText = result.status === Office.AsyncResultStatus.Succeeded ? (result.value || '') : '';
      render();
      callback(state.bodyText);
    });
  }

  function getAttachmentContent(attachmentId) {
    return new Promise(function (resolve, reject) {
      Office.context.mailbox.item.getAttachmentContentAsync(attachmentId, function (result) {
        if (result.status === Office.AsyncResultStatus.Succeeded && result.value && result.value.format === 'base64') {
          resolve(result.value.content);
        } else {
          reject(new Error('Could not read that attachment\'s content.'));
        }
      });
    });
  }

  function base64ToBlob(base64, mimeType) {
    var byteChars = atob(base64);
    var byteNumbers = new Array(byteChars.length);
    for (var i = 0; i < byteChars.length; i++) {
      byteNumbers[i] = byteChars.charCodeAt(i);
    }
    return new Blob([new Uint8Array(byteNumbers)], { type: mimeType || 'application/octet-stream' });
  }

  // ---- Sign-in dialog ----------------------------------------------------

  function openSignInDialog() {
    state.error = null;
    Office.context.ui.displayDialogAsync(SIGN_IN_DIALOG_URL, { height: 60, width: 40 }, function (asyncResult) {
      if (asyncResult.status === Office.AsyncResultStatus.Failed) {
        state.error = 'Could not open the sign-in window: ' + asyncResult.error.message;
        render();
        return;
      }
      dialog = asyncResult.value;
      dialog.addEventHandler(Office.EventType.DialogMessageReceived, onDialogMessage);
      dialog.addEventHandler(Office.EventType.DialogEventReceived, onDialogClosed);
    });
  }

  function onDialogMessage(arg) {
    var payload;
    try {
      payload = JSON.parse(arg.message);
    } catch (e) {
      payload = { ok: false, error: 'Unexpected response from sign-in.' };
    }
    if (dialog) { dialog.close(); dialog = null; }

    if (payload.ok) {
      state.token = payload.token;
      state.userName = payload.name;
      try {
        localStorage.setItem(LS_TOKEN, payload.token);
        localStorage.setItem(LS_NAME, payload.name || '');
        localStorage.setItem(LS_EMAIL, payload.email || '');
      } catch (e) { /* private window / blocked storage -- token still works for this session */ }
      afterSignedIn();
    } else {
      state.error = payload.error || 'Sign-in failed. Please try again.';
      state.stage = 'signin';
      render();
    }
  }

  function onDialogClosed() {
    dialog = null;
  }

  function signOut() {
    state.token = null;
    state.userName = null;
    state.stage = 'signin';
    try {
      localStorage.removeItem(LS_TOKEN);
      localStorage.removeItem(LS_NAME);
      localStorage.removeItem(LS_EMAIL);
    } catch (e) { /* ignore */ }
    render();
  }

  // ---- Boot / customer resolution -----------------------------------------

  function boot() {
    loadEmailContext();

    var storedToken = null;
    try { storedToken = localStorage.getItem(LS_TOKEN); } catch (e) { /* ignore */ }

    if (!storedToken) {
      state.stage = 'signin';
      render();
      return;
    }

    state.token = storedToken;
    try { state.userName = localStorage.getItem(LS_NAME); } catch (e) { /* ignore */ }

    apiGet('me').then(function (r) {
      if (!r.data || !r.data.ok) {
        // Stored token no longer valid (revoked, or crc_users row gone) --
        // same recovery as a first-time sign-in.
        signOut();
        return;
      }
      state.userName = r.data.user.name;
      afterSignedIn();
    }).catch(function () {
      state.error = 'Could not reach Relationships. Check your connection and try again.';
      state.stage = 'signin';
      render();
    });
  }

  function afterSignedIn() {
    state.stage = 'resolving-customer';
    render();

    apiGet('roster').then(function (r) {
      state.roster = (r.data && r.data.ok) ? r.data.roster : [];
      if (state.roster.indexOf(state.userName) !== -1) {
        state.todoAssignedTo = state.userName;
      }
    }).catch(function () { /* non-fatal -- the picker just starts blank */ });

    var senderEmail = state.email.senderEmail;
    if (!senderEmail) {
      state.stage = 'pick-customer';
      render();
      return;
    }

    apiGet('match', { email: senderEmail }).then(function (r) {
      if (r.data && r.data.ok && r.data.customer) {
        state.customer = r.data.customer;
        state.stage = 'menu';
      } else {
        state.stage = 'pick-customer';
      }
      render();
    }).catch(function () {
      state.stage = 'pick-customer';
      render();
    });
  }

  function runSearch(q) {
    state.searchQuery = q;
    if (searchDebounceTimer) clearTimeout(searchDebounceTimer);
    if (q.trim().length < 2) {
      state.searchResults = [];
      render();
      return;
    }
    searchDebounceTimer = setTimeout(function () {
      state.searching = true;
      render();
      apiGet('search', { q: q }).then(function (r) {
        state.searching = false;
        state.searchResults = (r.data && r.data.ok) ? r.data.customers : [];
        render();
      }).catch(function () {
        state.searching = false;
        state.searchResults = [];
        render();
      });
    }, 300);
  }

  function pickCustomer(customer) {
    state.customer = customer;
    state.searchQuery = '';
    state.searchResults = [];
    state.stage = 'menu';
    state.error = null;
    render();
  }

  // ---- Render ---------------------------------------------------------

  function render() {
    root.innerHTML = topHtml() + bodyHtml();
    bindEvents();
  }

  function topHtml() {
    if (state.stage === 'loading') return '';
    var signOutBtn = state.token
      ? '<button class="signout-link" data-action="signout" type="button">Sign out</button>'
      : '';
    return (
      '<div class="topbar">' +
        '<div><div class="brand">Relationships Hub</div><div class="brand-sub">' + esc(state.userName || 'CodeBlue Technology') + '</div></div>' +
        signOutBtn +
      '</div>'
    );
  }

  function emailContextHtml() {
    if (!state.email) return '';
    var e = state.email;
    return (
      '<div class="email-context">' +
        '<div class="email-context-subject">' + esc(e.subject || '(no subject)') + '</div>' +
        '<div class="email-context-from">From ' + esc(e.senderName || e.senderEmail || 'unknown sender') + '</div>' +
      '</div>'
    );
  }

  function bodyHtml() {
    if (state.stage === 'loading') return '<div class="loading">Loading…</div>';

    var errorHtml = state.error ? '<div class="error-banner">' + esc(state.error) + '</div>' : '';

    switch (state.stage) {
      case 'signin': return errorHtml + signInHtml();
      case 'resolving-customer': return emailContextHtml() + '<div class="loading">Matching this email to a customer…</div>';
      case 'pick-customer': return emailContextHtml() + errorHtml + pickCustomerHtml();
      case 'menu': return emailContextHtml() + customerPillHtml() + errorHtml + menuHtml();
      case 'file-documents': return emailContextHtml() + customerPillHtml() + errorHtml + fileDocumentsHtml();
      case 'create-meeting': return emailContextHtml() + customerPillHtml() + errorHtml + createMeetingHtml();
      case 'create-todo': return emailContextHtml() + customerPillHtml() + errorHtml + createTodoHtml();
      case 'done': return emailContextHtml() + customerPillHtml() + errorHtml + doneHtml();
      default: return '';
    }
  }

  function signInHtml() {
    return (
      '<div class="card">' +
        '<p style="margin:0 0 10px;font-size:13px;color:oklch(0.8 0.015 255);">Sign in with your CodeBlue Microsoft 365 account to file this email in Relationships.</p>' +
        '<button class="btn btn-primary" data-action="signin" type="button">Sign in with Microsoft</button>' +
      '</div>'
    );
  }

  function customerPillHtml() {
    if (!state.customer) return '';
    return (
      '<div class="customer-pill">' +
        '<span>Customer: <span class="customer-pill-name">' + esc(state.customer.name) + '</span></span>' +
        '<button class="btn-link" data-action="change-customer" type="button">Change</button>' +
      '</div>'
    );
  }

  function pickCustomerHtml() {
    var resultsHtml = '';
    if (state.searching) {
      resultsHtml = '<div class="hint">Searching…</div>';
    } else if (state.searchResults.length) {
      resultsHtml = '<div class="search-results">' + state.searchResults.map(function (c) {
        return '<div class="search-result" data-action="pick-customer" data-id="' + c.id + '" data-name="' + esc(c.name) + '">' + esc(c.name) + '</div>';
      }).join('') + '</div>';
    } else if (state.searchQuery.trim().length >= 2) {
      resultsHtml = '<div class="hint">No matching customers.</div>';
    }
    return (
      '<div class="card">' +
        '<h2 class="card-title">Which customer is this?</h2>' +
        '<p class="hint" style="margin-top:-4px;">We couldn\'t match ' + esc(state.email.senderEmail || 'this sender') + ' to a customer automatically. Search by name below.</p>' +
        '<input type="text" id="customer-search-input" placeholder="Search customers…" value="' + esc(state.searchQuery) + '">' +
        resultsHtml +
      '</div>'
    );
  }

  function menuHtml() {
    var hasAttachments = state.email.attachments.length > 0;
    return (
      '<div class="menu-list">' +
        '<button class="menu-item" data-action="goto-file-documents" type="button" ' + (hasAttachments ? '' : 'disabled') + '>' +
          '<div class="menu-item-title">File as Document(s)</div>' +
          '<div class="menu-item-sub">' + (hasAttachments ? state.email.attachments.length + ' attachment' + (state.email.attachments.length === 1 ? '' : 's') + ' on this email' : 'No attachments on this email') + '</div>' +
        '</button>' +
        '<button class="menu-item" data-action="goto-create-meeting" type="button">' +
          '<div class="menu-item-title">Create a Meeting</div>' +
          '<div class="menu-item-sub">Log this as a meeting, with this email filed as a Document</div>' +
        '</button>' +
        '<button class="menu-item" data-action="goto-create-todo" type="button">' +
          '<div class="menu-item-title">Create a To-Do</div>' +
          '<div class="menu-item-sub">Quick to-do from this email, with it attached</div>' +
        '</button>' +
      '</div>'
    );
  }

  function fileDocumentsHtml() {
    var catOptions = DOCUMENT_CATEGORIES.map(function (c) {
      return '<option value="' + esc(c) + '"' + (c === state.uploadCategory ? ' selected' : '') + '>' + esc(c) + '</option>';
    }).join('');
    var rows = state.email.attachments.map(function (a) {
      var checked = state.selectedAttachmentIds[a.id] ? ' checked' : '';
      return (
        '<div class="attachment-row">' +
          '<input type="checkbox" data-attachment-id="' + esc(a.id) + '"' + checked + '>' +
          '<div><div class="attachment-name">' + esc(a.name) + '</div><div class="attachment-size">' + fmtBytes(a.size) + '</div></div>' +
        '</div>'
      );
    }).join('');
    var anySelected = Object.keys(state.selectedAttachmentIds).some(function (k) { return state.selectedAttachmentIds[k]; });
    return (
      '<div class="card">' +
        '<h2 class="card-title">File as Document(s)</h2>' +
        '<div>' + rows + '</div>' +
        '<label class="field-label">Category</label>' +
        '<select id="upload-category">' + catOptions + '</select>' +
        '<button class="btn btn-primary" data-action="submit-file-documents" type="button" ' + (anySelected && !state.busy ? '' : 'disabled') + '>' +
          (state.busy ? 'Uploading…' : 'File Selected') +
        '</button>' +
        '<button class="btn btn-secondary" data-action="back-to-menu" type="button">Back</button>' +
      '</div>'
    );
  }

  function createMeetingHtml() {
    return (
      '<div class="card">' +
        '<h2 class="card-title">Create a Meeting</h2>' +
        '<label class="field-label">Subject</label>' +
        '<input type="text" id="meeting-subject" value="' + esc(state.meetingSubject) + '">' +
        '<label class="field-label">Date</label>' +
        '<input type="date" id="meeting-date" value="' + esc(state.meetingDate || todayYmd()) + '">' +
        '<label class="field-label">Time (optional)</label>' +
        '<input type="time" id="meeting-time" value="' + esc(state.meetingTime) + '">' +
        '<label class="field-label">Notes</label>' +
        '<textarea id="meeting-notes" placeholder="What happened / what\'s next…">' + esc(state.meetingNotes) + '</textarea>' +
        '<button class="btn-link" data-action="insert-email-text" type="button" style="margin-top:6px;">' +
          (state.bodyTextLoading ? 'Loading email text…' : 'Insert email text into notes') +
        '</button>' +
        '<div class="hint">The email itself is always filed as a Document on this meeting, whether or not you insert its text above.</div>' +
        '<button class="btn btn-primary" data-action="submit-create-meeting" type="button" ' + (state.busy ? 'disabled' : '') + '>' +
          (state.busy ? 'Saving…' : 'Create Meeting') +
        '</button>' +
        '<button class="btn btn-secondary" data-action="back-to-menu" type="button">Back</button>' +
      '</div>'
    );
  }

  function createTodoHtml() {
    var rosterOptions = '<option value="">Choose…</option>' + state.roster.map(function (name) {
      return '<option value="' + esc(name) + '"' + (name === state.todoAssignedTo ? ' selected' : '') + '>' + esc(name) + '</option>';
    }).join('');
    return (
      '<div class="card">' +
        '<h2 class="card-title">Create a to-do?</h2>' +
        '<label class="field-label">What needs to happen</label>' +
        '<textarea id="todo-description" placeholder="Follow up on this email…">' + esc(state.todoDescription) + '</textarea>' +
        '<div class="hint">Leave blank to auto-fill from the email\'s subject and sender.</div>' +
        '<label class="field-label">Assigned to</label>' +
        '<select id="todo-assigned-to">' + rosterOptions + '</select>' +
        '<label class="field-label">Due date (optional)</label>' +
        '<input type="date" id="todo-due-date" value="' + esc(state.todoDueDate) + '">' +
        '<div class="hint">This email is filed as a Document and attached to this to-do.</div>' +
        '<button class="btn btn-primary" data-action="submit-create-todo" type="button" ' + (state.busy ? 'disabled' : '') + '>' +
          (state.busy ? 'Saving…' : 'Create To-Do') +
        '</button>' +
        '<button class="btn btn-secondary" data-action="back-to-menu" type="button">Back</button>' +
      '</div>'
    );
  }

  function cwChipHtml(label, pushInfo) {
    if (!pushInfo) return '';
    var status = pushInfo.status;
    var cls = status === 'pushed' || status === 'uploaded' || status === 'sent' ? 'ok' : (status === 'error' || status === 'failed' ? 'bad' : '');
    return '<span class="cw-chip ' + cls + '">' + esc(label) + ': ' + esc(status || 'unknown') + '</span>';
  }

  function doneHtml() {
    var r = state.doneResult;
    if (!r) return '';
    var chips = '';
    var heading = '';
    if (r.kind === 'meeting') {
      heading = 'Meeting created.';
      chips = cwChipHtml('ConnectWise', r.payload.meeting.cw_push);
    } else if (r.kind === 'todo') {
      heading = 'To-do created.';
      chips = cwChipHtml('ConnectWise', r.payload.task.cw_push) + cwChipHtml('Email', r.payload.task.email);
    } else if (r.kind === 'documents') {
      heading = r.payload.documents.length + ' document' + (r.payload.documents.length === 1 ? '' : 's') + ' filed.';
      chips = r.payload.documents.map(function (d) { return cwChipHtml(d.original_filename, d.cw_push); }).join('');
    }
    return (
      '<div class="success-banner">' + esc(heading) + '</div>' +
      (chips ? '<div class="cw-status-row">' + chips + '</div>' : '') +
      '<button class="btn btn-primary" data-action="back-to-menu" type="button" style="margin-top:14px;">Back to menu</button>'
    );
  }

  // ---- Submit handlers --------------------------------------------------

  function submitFileDocuments() {
    var ids = Object.keys(state.selectedAttachmentIds).filter(function (k) { return state.selectedAttachmentIds[k]; });
    if (!ids.length) return;
    state.busy = true;
    state.error = null;
    render();

    var attachmentsById = {};
    state.email.attachments.forEach(function (a) { attachmentsById[a.id] = a; });

    Promise.all(ids.map(function (id) {
      var meta = attachmentsById[id];
      return getAttachmentContent(id).then(function (base64) {
        return { meta: meta, blob: base64ToBlob(base64, meta.contentType) };
      });
    })).then(function (parts) {
      var formData = new FormData();
      formData.append('customer_id', String(state.customer.id));
      formData.append('category', state.uploadCategory);
      parts.forEach(function (p) {
        formData.append('files[]', p.blob, p.meta.name);
      });
      return apiPostForm('file_documents', formData);
    }).then(function (r) {
      state.busy = false;
      if (r.data && r.data.ok) {
        state.doneResult = { kind: 'documents', payload: r.data };
        state.stage = 'done';
      } else {
        state.error = (r.data && r.data.error) || 'Could not file those documents.';
      }
      render();
    }).catch(function (e) {
      state.busy = false;
      state.error = e.message || 'Could not read one of the attachments.';
      render();
    });
  }

  function submitCreateMeeting() {
    var subject = (document.getElementById('meeting-subject') || {}).value || '';
    var date = (document.getElementById('meeting-date') || {}).value || '';
    var time = (document.getElementById('meeting-time') || {}).value || '';
    var notes = (document.getElementById('meeting-notes') || {}).value || '';
    if (!date) {
      state.error = 'Please choose a date.';
      render();
      return;
    }
    state.busy = true;
    state.error = null;
    render();

    ensureBodyTextLoaded(function (bodyText) {
      apiPostJson('create_meeting', {
        customer_id: state.customer.id,
        subject: subject,
        meeting_date: date,
        meeting_time: time,
        notes: notes,
        email_subject: state.email.subject,
        email_sender_name: state.email.senderName,
        email_sender_email: state.email.senderEmail,
        email_received_at: state.email.receivedAt,
        email_body_text: bodyText,
      }).then(function (r) {
        state.busy = false;
        if (r.data && r.data.ok) {
          state.doneResult = { kind: 'meeting', payload: r.data };
          state.stage = 'done';
        } else {
          state.error = (r.data && r.data.error) || 'Could not create that meeting.';
        }
        render();
      }).catch(function () {
        state.busy = false;
        state.error = 'Could not reach Relationships. Please try again.';
        render();
      });
    });
  }

  function submitCreateTodo() {
    var description = (document.getElementById('todo-description') || {}).value || '';
    var assignedTo = (document.getElementById('todo-assigned-to') || {}).value || '';
    var dueDate = (document.getElementById('todo-due-date') || {}).value || '';
    if (!assignedTo) {
      state.error = 'Please choose who this is assigned to.';
      render();
      return;
    }
    state.busy = true;
    state.error = null;
    render();

    ensureBodyTextLoaded(function (bodyText) {
      apiPostJson('create_todo', {
        customer_id: state.customer.id,
        description: description,
        assigned_to_name: assignedTo,
        due_date: dueDate,
        email_subject: state.email.subject,
        email_sender_name: state.email.senderName,
        email_sender_email: state.email.senderEmail,
        email_received_at: state.email.receivedAt,
        email_body_text: bodyText,
      }).then(function (r) {
        state.busy = false;
        if (r.data && r.data.ok) {
          state.doneResult = { kind: 'todo', payload: r.data };
          state.stage = 'done';
        } else {
          state.error = (r.data && r.data.error) || 'Could not create that to-do.';
        }
        render();
      }).catch(function () {
        state.busy = false;
        state.error = 'Could not reach Relationships. Please try again.';
        render();
      });
    });
  }

  // ---- Events -------------------------------------------------------

  function bindEvents() {
    var searchInput = document.getElementById('customer-search-input');
    if (searchInput) {
      searchInput.addEventListener('input', function () { runSearch(searchInput.value); });
    }

    root.querySelectorAll('[data-attachment-id]').forEach(function (el) {
      el.addEventListener('change', function () {
        state.selectedAttachmentIds[el.getAttribute('data-attachment-id')] = el.checked;
        render();
      });
    });

    var categorySelect = document.getElementById('upload-category');
    if (categorySelect) {
      categorySelect.addEventListener('change', function () { state.uploadCategory = categorySelect.value; });
    }

    root.querySelectorAll('[data-action]').forEach(function (el) {
      el.addEventListener('click', function () {
        var action = el.getAttribute('data-action');
        if (action === 'signin') { openSignInDialog(); }
        else if (action === 'signout') { signOut(); }
        else if (action === 'change-customer') { state.customer = null; state.stage = 'pick-customer'; state.searchResults = []; render(); }
        else if (action === 'pick-customer') { pickCustomer({ id: Number(el.getAttribute('data-id')), name: el.getAttribute('data-name') }); }
        else if (action === 'back-to-menu') { state.stage = 'menu'; state.error = null; state.doneResult = null; render(); }
        else if (action === 'goto-file-documents') { state.selectedAttachmentIds = {}; state.stage = 'file-documents'; render(); }
        else if (action === 'goto-create-meeting') { state.meetingDate = todayYmd(); state.meetingTime = ''; state.meetingNotes = ''; state.stage = 'create-meeting'; render(); }
        else if (action === 'goto-create-todo') { state.todoDescription = ''; state.todoDueDate = ''; state.stage = 'create-todo'; render(); }
        else if (action === 'insert-email-text') {
          ensureBodyTextLoaded(function (bodyText) {
            var ta = document.getElementById('meeting-notes');
            if (ta) { ta.value = bodyText; state.meetingNotes = bodyText; }
          });
        }
        else if (action === 'submit-file-documents') { submitFileDocuments(); }
        else if (action === 'submit-create-meeting') { submitCreateMeeting(); }
        else if (action === 'submit-create-todo') { submitCreateTodo(); }
      });
    });
  }

  Office.onReady(function () {
    boot();
  });
})();
