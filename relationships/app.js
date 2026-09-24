/**
 * relationships/app.js
 *
 * CRC "Relationships" dashboard — a small, separate vanilla-JS app (no
 * runtime.js template engine; this is a fraction of SolutionsHub's size and
 * plain string-rendering is simpler than pulling that engine in). Talks to
 * relationships/api/auth.php and relationships/api/customers.php.
 *
 * Phase 1: sign-in gate, customer search, pillar summary (bright = has at
 * least one active service in that pillar, dark = none), pillar drill-down
 * showing active products + missing services, a missing-services roster,
 * deep-links back into the main Solutions Hub for a missing service, and a
 * link out to the (placeholder, pending real per-service folders)
 * SharePoint marketing library.
 *
 * Phase 2 (relationships/api/checklist.php): a 7-step cross-sell checklist
 * per (customer, missing service), expandable inline under that service in
 * the drill-down; and a step-queue reporting view (Cross-Sell Report) —
 * per-service counts of how many customers are pending at each step,
 * drilling into who they are and jumping straight to that customer's
 * checklist at that step.
 */

(function () {
  'use strict';

  // SolutionsHub site root is one level up from /relationships/.
  var HUB_URL = '../index.html';

  // Placeholder until CodeBlue supplies the real per-pillar/per-service
  // SharePoint folder links — for now every "View Marketing" action opens
  // the shared library root.
  var MARKETING_LIBRARY_URL = 'https://codebluetechnology.sharepoint.com/sites/Training/Customer%20Marketing/Forms/AllItems.aspx';

  var root = document.getElementById('app-root');

  var state = {
    user: null,
    query: '',
    searching: false,
    results: [],
    resultsOpen: false,
    selectedCustomer: null, // { customer: {id,name}, pillars: [...] }
    loadingDetail: false,
    activePillarId: null,
    error: null,

    // 'dashboard' | 'report' | 'queue' | 'sync'
    view: 'dashboard',

    // ConnectWise sync (api/sync.php) -- see runFullSync()/stepSyncLoop().
    syncRunning: false,
    syncDone: false,
    syncTotal: 0,
    syncProcessed: 0,
    syncTotals: null, // { pending, done, error } -- from the last start/step/status call
    syncStartedAt: null,
    syncErrors: [],

    // Monthly Billing sync (api/sync.php's billing-* actions) -- added
    // 2026-09-10. "Run Sync Now" chains this after the agreement sync
    // above finishes (see stepSyncLoop()), so one click still does the
    // whole nightly-cron-equivalent sync; kept as separate state since it's
    // a genuinely separate queue (by customer, not by agreement) that can
    // succeed/fail independently of the agreement sync.
    billingSyncRunning: false,
    billingSyncDone: false,
    billingSyncTotal: 0,
    billingSyncProcessed: 0,
    billingSyncTotals: null,
    billingSyncStartedAt: null,
    billingSyncErrors: [],

    // Prospect Companies sync (api/sync.php's prospect-* actions) -- added
    // 2026-09-10. "Run Sync Now" chains this after the billing sync above
    // finishes, same reasoning as billing chaining after agreements: one
    // click still does the whole nightly-cron-equivalent sync, and this is
    // a genuinely separate queue (by ConnectWise Company, not by agreement
    // or by already-synced customer) that can succeed/fail independently.
    prospectSyncRunning: false,
    prospectSyncDone: false,
    prospectSyncTotal: 0,
    prospectSyncProcessed: 0,
    prospectSyncTotals: null,
    prospectSyncStartedAt: null,
    prospectSyncErrors: [],

    // Ticket History sync (api/sync.php's ticket-history-* actions) --
    // added 2026-09-10. "Run Sync Now" chains this after the prospect sync
    // above finishes, populating the front-page Primary Relationship
    // Dashboard's per-customer Service Tickets YTD + 6-month trend
    // (dashboard.php reads what this writes). Independent queue, by
    // customer, same reasoning as billing/prospect above.
    ticketHistorySyncRunning: false,
    ticketHistorySyncDone: false,
    ticketHistorySyncTotal: 0,
    ticketHistorySyncProcessed: 0,
    ticketHistorySyncTotals: null,
    ticketHistorySyncStartedAt: null,
    ticketHistorySyncErrors: [],

    // Contacts sync (api/sync.php's contacts-* actions) -- added
    // 2026-09-10. Runs last as part of "Run Sync Now", populating the
    // front page's per-customer Active Contacts count + 6-month trend, and
    // the `contacts` table that customer search matches by name/email.
    // Independent queue, by customer.
    contactsSyncRunning: false,
    contactsSyncDone: false,
    contactsSyncTotal: 0,
    contactsSyncProcessed: 0,
    contactsSyncTotals: null,
    contactsSyncStartedAt: null,
    contactsSyncErrors: [],

    // Territory sync (api/sync.php's territory-* actions) -- added
    // 2026-09-16 per Michael's rep-based territory filtering request. Runs
    // last as part of "Run Sync Now" (after contacts), tagging every
    // customer with its synced ConnectWise territory so a restricted rep's
    // customer lists can be filtered -- see connectwise-territory-sync-core.php.
    // Independent queue, by ConnectWise Company.
    territorySyncRunning: false,
    territorySyncDone: false,
    territorySyncTotal: 0,
    territorySyncProcessed: 0,
    territorySyncTotals: null,
    territorySyncStartedAt: null,
    territorySyncErrors: [],

    // Territory Admin screen (api/territory-admin.php) -- added 2026-09-16
    // per Michael, restricted to territory admins only (state.user.is_territory_admin
    // -- see territory-access.php). Manages which CRC email is restricted
    // to which synced territory_name(s).
    territoryAdminLoading: false,
    territoryAdmin: null, // { assignments: [{id,email,territory_name,created_at}], territory_options: [...] } once loaded
    territoryAdminError: null,
    territoryAdminAddEmail: '',
    territoryAdminAddTerritory: '',
    territoryAdminSaving: false,
    territoryAdminRemovingId: null,

    // Primary Relationship Dashboard overview (api/dashboard.php) -- added
    // 2026-09-10: the gauges + per-customer trend list shown on the front
    // page when no customer is selected. Loaded once at boot() and re-shown
    // (not reloaded) whenever the user backs out to the front page; a full
    // "Run Sync Now" reloads it at the end so the numbers reflect the sync
    // that just ran. null while never (successfully) loaded yet.
    overview: null,
    overviewLoading: false,
    overviewError: null,
    // Current sort for the per-customer overview list -- added 2026-09-10
    // per Michael. column is 'name' | 'billing_trend' | 'ticket_count_ytd' |
    // 'contact_count'; direction 'asc' (alphabetical A-Z for name, low-to-
    // high for the numeric/trend columns) or 'desc' (Z-A / high-to-low).
    // Purely a display concern -- re-sorts state.overview.customers on
    // every render rather than mutating the fetched data.
    overviewSort: { column: 'name', direction: 'asc' },
    // Customer-list filter (added 2026-09-15 per Michael) -- a purely
    // client-side display filter over the same state.overview.customers
    // array dashboard.php already returns is_peoplefirst for; see
    // filteredOverviewCustomers(). (The old Prospects on/off toggle was
    // removed 2026-09-23: prospects now have their own tile and list.)
    overviewPeopleFirstOnly: false,
    // Front-page customer list is hidden by default (2026-09-23, per
    // Michael: the front page is a team dashboard + global action items
    // list, not a customer directory) -- clicking the Total Customers
    // gauge tile toggles it. See gaugesHtml()/overviewHtml().
    // overviewListMode: null (hidden) | 'customers' | 'prospects' -- which
    // group the front-page list shows; the Total Customers / Total
    // Prospects tiles set it (prospects are their own group, not part of
    // Total Customers, per Michael 2026-09-23).
    overviewListMode: null,
    // OutGrow-stale list sort (front-page "60+ Days Since Last OutGrow
    // Touch" tile): 'asc' = earliest touch first (never-touched customers
    // lead), 'desc' = most recent first. One-shot flag so the background
    // ConnectWise date backfill is only requested once per page load.
    outgrowSortDir: 'asc',
    outgrowBackfillStarted: false,

    // Prospecting view (api/prospecting.php, added 2026-09-23) -- see
    // prospectingHtml() below.
    prospecting: {
      tab: 'search', // 'search' | 'mine'
      loaded: false, loading: false, error: null,
      configured: true, industries: [], dailyCap: null, remainingToday: null,
      form: { industry: 'Any', location: 'Richmond, VA', radius: 150 },
      searching: false, searchStartedAt: 0,
      search: null, candidates: [], skipped: [],
      filterTier: 'all', filterIndustry: 'all', filterLocation: '',
      selectedId: null, draft: null,
      profileLoading: false, profileError: null,
      saving: false, claiming: false, claimError: null, claimResult: null,
      claims: null, claimsLoading: false, claimsScope: 'mine'
    },

    // Checklist data, keyed by "customerId::pillarId::serviceId". Each
    // value is: undefined (not fetched yet), 'error', or
    // { steps: [...7 step objects...], killed: bool } from
    // checklist.php?action=get.
    checklists: {},
    openChecklistKey: null,

    // Cross-sell step notes (api/checklist.php?action=notes_get/notes_add)
    // -- added 2026-09-23, per Michael: "add the ability to click a small
    // + icon next to each step for a rep to put in their notes." Same
    // "customerId::pillarId::serviceId" key shape as state.checklists;
    // value is undefined (not fetched), 'error', or an array of note rows.
    // Only one checklist's notes panel is ever open at a time, same
    // single-key pattern as openChecklistKey itself.
    checklistNotes: {},
    openChecklistNotesKey: null,
    // "customerId::pillarId::serviceId::stepNumber" of the single note
    // textarea currently open (the "+" button per step), or null. Fully
    // compound so switching customers/pillars never shows a stray open
    // textarea against the wrong step.
    checklistNoteDraftOpenKey: null,
    checklistNoteDraftText: '',
    checklistNoteSaving: false,

    // Which contact is selected for a checklist's outreach actions --
    // keyed the same "customerId::pillarId::serviceId" way. Independent
    // of state.contactCardSelectedId (the OutGrow contact card's own
    // selection just above) since a rep may want a different contact for
    // a specific cross-sell push than whatever's selected for OutGrow.
    // Reuses state.contactCard.contacts (already loaded per customer,
    // filtered to contacts with complete info) as its data source --
    // deliberately no second contact fetch for this.
    checklistContactSelected: {},
    openChecklistContactDropdownKey: null,

    // "customerId::pillarId::serviceId" currently mid Recycle/Kill/Unkill
    // request, so those buttons can show a saving state and can't
    // double-fire.
    checklistCloseoutSaving: null,

    // Set right before selectCustomer() when arriving from the queue view,
    // so the customer's dashboard opens straight to that pillar with that
    // service's checklist already expanded and scrolled to.
    pendingFocus: null,

    report: null, // rows from checklist.php?action=summary
    reportLoading: false,

    queue: null, // customers from checklist.php?action=queue
    queueLoading: false,
    queueParams: null, // { pillarId, serviceId, step, pillarName, serviceName }

    // PeopleFirst checkin/risk-scan tracking (api/peoplefirst.php). Shown
    // as a summary line atop the Cross-Sell Report, drilling into a
    // 'pf-queue' view for who currently needs a checkin or a scan.
    pfSummary: null, // { total, needs_checkin, needs_scan } from ?action=summary
    pfSummaryLoading: false,
    pfQueue: null, // customers from ?action=queue
    pfQueueLoading: false,
    pfQueueType: null, // 'checkin' | 'scan'
    pfLogging: null, // "customerId::type" currently being logged, or null

    // Ticket/billing activity for the currently-open customer
    // (api/activity.php) -- reset whenever a different customer is opened.
    // { available: bool, ticket_count_ytd (live), billing: {series, trend}
    // (nightly-synced as of 2026-09-10 -- see billing_synced_at), billing_synced_at }
    // or null while loading, or { available: false } for a mock customer /
    // on error.
    activitySummary: null,
    activitySummaryLoading: false,

    // Which activity drill-down (if any) is open under the activity cards:
    // null | 'tickets' | 'invoices' | 'invoice-detail'.
    activityView: null,
    activityTickets: null, // array | 'error' | null (not loaded yet)
    activityTicketsLoading: false,
    activityInvoicesPeriod: null, // { type: 'month'|'year', value: "YYYY-MM"|"YYYY", label }
    activityInvoices: null, // array | 'error' | null
    activityInvoicesLoading: false,
    activityInvoiceNumber: null, // shown as the drilldown title while loading
    activityInvoiceDetail: null, // object | 'error' | null
    activityInvoiceDetailLoading: false,

    // Customer Service Summary print view (added 2026-09-14, per Michael) --
    // a formatted, printable page for Relationship Coordinators: Service
    // Tickets YTD + top-3-by-hours tickets as check-in talking points,
    // services currently in place (Pillar / product description / qty),
    // and pillar services not yet in place (IT/DC/VoIP/Security only,
    // using the same cross_sell_eligible flag as the Cross-Sell
    // Opportunities roster) with a short factual blurb + free-comparison
    // offer for each. printTickets is loaded independently of
    // activityTickets so opening the print view doesn't disturb whatever's
    // open in the Service Tickets drill-down (or vice versa).
    printSummaryOpen: false,
    printTickets: null, // array | 'error' | null (not loaded yet)
    printTicketsLoading: false,

    // OutGrow Last Touch (added 2026-09-15, per Michael) -- a CRC-editable
    // date per customer, with full history (api/outgrow.php), that also
    // tries to push into ConnectWise's own "OutGrow Last Touch" Company
    // custom field on every save. outgrowCurrent/outgrowHistory are reset
    // (resetOutgrowState()) and reloaded (loadOutgrow()) whenever a
    // different customer is opened, same as the activity state above.
    outgrowLoading: false,
    outgrowCurrent: null, // { touch_date, set_by_name, source, created_at } | null
    outgrowHistory: null, // array | null (not loaded yet)
    outgrowHistoryOpen: false,
    outgrowEditing: false,
    outgrowDraftDate: '', // "YYYY-MM-DD" -- bound to the <input type="date"> while editing
    outgrowSaving: false,
    outgrowError: null, // shown inline -- a save failure, or a "saved here but didn't reach ConnectWise" warning

    // "Current vendor if not CodeBlue" -- one editable note per pillar
    // (api/vendor.php), added 2026-09-15 per Michael's Customer Meeting
    // Capture request. No history list (unlike OutGrow above) -- just the
    // current value + who/when it was last touched.
    vendorNotes: null, // { "<pillar_id>": {vendor_name, updated_at, updated_by_name} | null, ... } once loaded
    vendorEditingPillarId: null,
    vendorDraft: '',
    vendorSaving: false,
    vendorError: null,

    // Customer Meeting Capture -- meetings logged against a customer, and
    // the to-do tasks logged under them (api/meetings.php), added
    // 2026-09-15 per Michael.
    meetingsLoading: false,
    meetings: null, // array once loaded (newest meeting first), each with a nested .tasks array (oldest first)
    meetingsRoster: [], // the 7 fixed assignee names, from the server (relationships_todo_roster())
    meetingsError: null,
    meetingAddOpen: false,
    meetingDraftSubject: '',
    meetingDraftDate: '',
    meetingDraftNotes: '',
    meetingSaving: false,
    openMeetingId: null, // which logged meeting is expanded, if any
    taskAddOpenForMeeting: null, // meeting id whose "+ Add Task" form is open, if any
    taskDraftDescription: '',
    taskDraftAssignee: '',
    taskDraftDueDate: '', // "YYYY-MM-DD" | '' -- optional, added 2026-09-16 (scheduled to-dos)
    taskSaving: false,
    taskTogglingId: null, // task id currently mid-toggle (checkbox disabled while true)
    // Deep-link target set by openCustomerAtTask() (global to-do panel ->
    // a specific customer's task) -- consumed once inside selectCustomer(),
    // same pattern state.pendingFocus already uses for the checklist.
    pendingTaskFocus: null, // { meetingId, taskId } | null

    // Risk-scan file uploads (api/risk-scans.php) -- added 2026-09-23 per
    // Michael: a service team member uploads a customer's risk-scan zip
    // from that customer's dashboard; a rep downloads and reviews it, then
    // marks it reviewed. Open (unreviewed) uploads also show as alerts in
    // the Global To-Do Checklist panel -- server-computed (reviewed_at IS
    // NULL in meetings.php's 'global' action), not tracked in state here.
    riskScans: null, // [ { id, original_filename, size_bytes, uploaded_by_name, uploaded_at, reviewed_at, reviewed_by_name }, ... ] | null while loading
    riskScansLoading: false,
    riskScansError: null,
    riskScanDraftFile: null, // File object chosen but not yet uploaded, or null
    riskScanUploading: false,
    riskScanTogglingId: null, // scan id currently mid mark/unmark-reviewed (button disabled while true)
    riskScanRetryingId: null, // scan id currently mid ConnectWise re-attach (button disabled while true)
    // Deep-link target set by openCustomerAtRiskScan() (Global To-Do panel
    // -> a specific customer's risk-scan alert) -- same pattern as
    // pendingTaskFocus above, consumed once inside loadRiskScans().
    pendingRiskScanFocus: null, // { scanId } | null

    // Global master to-do dashboard -- the Relationships front page's new
    // right-hand panel (api/meetings.php?action=global), added 2026-09-15.
    globalTodosLoading: false,
    globalTodos: null, // { roster, counts, tasks } once loaded
    globalTodosError: null,

    // Per-coordinator to-do view (state.view === 'rep-todos') -- added
    // 2026-09-16 per Michael: click a name in the Global To-Do Checklist
    // to see that person's own open/scheduled/recently-completed to-dos,
    // plus a month calendar of their scheduled ones
    // (api/meetings.php?action=rep_todos).
    repTodosLoading: false,
    repTodosName: null, // the roster name this view is currently showing
    repTodosData: null, // { rep_name, roster, open_tasks, recent_completed_tasks } once loaded
    repTodosError: null,
    repTodosCalYear: null, // calendar's currently-shown year, set when the view opens
    repTodosCalMonth: null // calendar's currently-shown month (1-12), set when the view opens
  };

  function escapeHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function fmtQty(p) {
    var qty = p.qty;
    var unit = p.unit ? ' ' + escapeHtml(p.unit) : '';
    return escapeHtml(qty) + unit;
  }

  function fmtTimestamp(raw) {
    if (!raw) return '';
    // checklist.php writes datetime('now') -- SQLite gives that back as
    // "YYYY-MM-DD HH:MM:SS" in UTC, with no "T" or offset. sync.php's
    // started_at is a full ISO 8601 string (PHP's date('c')) and already
    // has both -- only pad the SQLite shape.
    var iso = raw.indexOf('T') === -1 ? raw.replace(' ', 'T') + 'Z' : raw;
    var d = new Date(iso);
    if (isNaN(d.getTime())) return raw;
    return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }) +
      ' ' + d.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
  }

  // Risk-scan file sizes (bytes from the server) -- KB up to 1000 KB, MB
  // above that, one decimal place either way.
  function fmtFileSize(bytes) {
    var n = Number(bytes) || 0;
    if (n < 1024) return n + ' B';
    if (n < 1024 * 1024) return (n / 1024).toFixed(1) + ' KB';
    return (n / (1024 * 1024)).toFixed(1) + ' MB';
  }

  // Date-only formatting for ConnectWise ticket/invoice dates -- these come
  // back as full ISO datetimes but only the date is meaningful here.
  function fmtDate(raw) {
    if (!raw) return '';
    var d = new Date(raw);
    if (isNaN(d.getTime())) return String(raw).slice(0, 10);
    return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
  }

  function fmtCurrency(n) {
    return '$' + Number(n || 0).toLocaleString(undefined, { maximumFractionDigits: 0 });
  }

  // ---- API helpers ----------------------------------------------------

  function apiGet(url) {
    return fetch(url, { credentials: 'same-origin' }).then(function (r) {
      return r.json().then(function (data) { return { status: r.status, data: data }; });
    });
  }

  function apiPost(url, body) {
    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body || {})
    }).then(function (r) {
      return r.json().then(function (data) { return { status: r.status, data: data }; });
    });
  }

  // multipart/form-data POST -- for the risk-scan zip upload
  // (api/risk-scans.php?action=upload) only. Deliberately NOT JSON like
  // apiPost() above: a File object can't go in a JSON body, and setting
  // Content-Type by hand here would drop the multipart boundary the
  // browser generates -- fetch sets it correctly on its own as long as we
  // leave the header out entirely.
  function apiUpload(url, formData) {
    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      body: formData
    }).then(function (r) {
      return r.json().then(function (data) { return { status: r.status, data: data }; });
    });
  }

  // ---- Data loading -----------------------------------------------------

  function boot() {
    apiGet('api/auth.php?action=me').then(function (r) {
      if (!r.data || !r.data.ok || !r.data.user) {
        window.location.href = 'login.html?next=' + encodeURIComponent('index.html');
        return;
      }
      state.user = r.data.user;
      render();
      loadOverview();
      loadGlobalTodos();
    }).catch(function () {
      window.location.href = 'login.html?next=' + encodeURIComponent('index.html');
    });
  }

  // Front-page gauges + per-customer trend list (api/dashboard.php) --
  // synced-data-only, so this is one cheap GET rather than a per-customer
  // round-trip. Safe to call more than once (e.g. a defensive call from
  // 'change-customer'/'show-dashboard' if boot()'s call hasn't resolved
  // yet) -- overlapping calls just both resolve into the same state.
  function loadOverview() {
    if (state.overviewLoading) return;
    state.overviewLoading = true;
    state.overviewError = null;
    render();
    apiGet('api/dashboard.php?action=overview').then(function (r) {
      state.overviewLoading = false;
      if (r.data && r.data.ok) {
        state.overview = r.data;
        maybeBackfillOutgrow(r.data);
      } else {
        state.overviewError = (r.data && r.data.error) || 'Could not load the dashboard overview.';
      }
      render();
    }).catch(function () {
      state.overviewLoading = false;
      state.overviewError = 'Could not load the dashboard overview — check your connection.';
      render();
    });
  }

  // The "60+ Days Since Last OutGrow Touch" count is only right once every
  // customer's ConnectWise-held date has been pulled in locally (see
  // outgrow.php's 'backfill_all'). dashboard.php says when that's due
  // (never run / 12h+ ago); this fires it once, quietly, then refreshes the
  // numbers if it actually found anything. Failures are silent -- the
  // server won't offer it again for 12 hours regardless.
  function maybeBackfillOutgrow(overview) {
    if (!overview.outgrow_backfill_stale || state.outgrowBackfillStarted) return;
    state.outgrowBackfillStarted = true;
    apiPost('api/outgrow.php?action=backfill_all', {}).then(function (r) {
      if (r.data && r.data.ok && r.data.inserted > 0) {
        loadOverview();
      }
    }).catch(function () { /* silent -- see above */ });
  }

  var searchDebounce = null;
  function runSearch(q) {
    clearTimeout(searchDebounce);
    if (!q.trim()) {
      state.results = [];
      state.searching = false;
      render();
      return;
    }
    searchDebounce = setTimeout(function () {
      state.searching = true;
      render();
      apiGet('api/customers.php?action=list&q=' + encodeURIComponent(q)).then(function (r) {
        state.searching = false;
        if (r.data && r.data.ok) {
          state.results = r.data.customers;
        }
        render();
      }).catch(function () {
        state.searching = false;
        render();
      });
    }, 2000);
  }

  function resetActivityState() {
    state.activitySummary = null;
    state.activitySummaryLoading = false;
    state.activityView = null;
    state.activityTickets = null;
    state.activityTicketsLoading = false;
    state.activityInvoicesPeriod = null;
    state.activityInvoices = null;
    state.activityInvoicesLoading = false;
    state.activityInvoiceNumber = null;
    state.activityInvoiceDetail = null;
    state.activityInvoiceDetailLoading = false;
    state.printSummaryOpen = false;
    state.printTickets = null;
    state.printTicketsLoading = false;
  }

  function resetOutgrowState() {
    state.outgrowLoading = false;
    state.outgrowCurrent = null;
    state.outgrowHistory = null;
    state.outgrowHistoryOpen = false;
    state.outgrowEditing = false;
    state.outgrowDraftDate = '';
    state.outgrowSaving = false;
    state.outgrowError = null;
  }

  // Contact card (address + primary contact + tap-to-call/email) --
  // added 2026-09-23 per Michael: "pull in address, primary contact name,
  // email and phone number from ConnectWise on each Customer in
  // Relationships and show that data cleanly above Outgrow Last Touch."
  // Loaded live alongside the rest of a customer's dashboard data (same
  // per-open pattern as loadActivitySummary's ticket count -- see that
  // function's own comment) rather than nightly-synced, since address and
  // phone have never been synced anywhere in this app before now. See
  // api/contact-card.php's file header for what's confirmed vs. an
  // unverified guess about ConnectWise's field shapes.
  function resetContactCardState() {
    state.contactCard = null;
    state.contactCardLoading = false;
    state.contactCardError = null;
    // Dropdown open/closed, and which contact (by ConnectWise contact id,
    // a string) is currently picked -- added 2026-09-23 per Michael:
    // "show a drop down list where the new contact info is... When you
    // select the contact in question, you can then tap on email or
    // phone." Nothing is pre-selected -- the dropdown always starts
    // closed with no contact chosen, even when there's only one to pick,
    // so picking one is always a deliberate step.
    state.contactCardOpen = false;
    state.contactCardSelectedId = null;
    // Pending "log this as an OutGrow touch?" confirmation -- per
    // Michael's explicit choice (AskUserQuestion) that tapping call/email
    // must NOT log the touch immediately; it only opens the dialer/email
    // app and logs after this confirm step is answered "yes".
    // { source: 'call' | 'email' } | null
    state.outgrowConfirm = null;
  }

  function outgrowTodayYmd() {
    var d = new Date();
    var mm = d.getMonth() + 1;
    var dd = d.getDate();
    return d.getFullYear() + '-' + (mm < 10 ? '0' : '') + mm + '-' + (dd < 10 ? '0' : '') + dd;
  }

  // "YYYY-MM-DD" -> "M/D/YY" -- plain string slicing rather than
  // new Date(ymd), which parses a bare date as UTC midnight and can roll
  // back a day once formatted in a negative-UTC-offset timezone (US
  // Eastern included) -- a real, silent off-by-one this avoids entirely.
  function fmtOutgrowDate(ymd) {
    if (!ymd) return '';
    var parts = String(ymd).split('-');
    if (parts.length !== 3) return ymd;
    return parseInt(parts[1], 10) + '/' + parseInt(parts[2], 10) + '/' + parts[0].slice(2);
  }

  // A short lead-in for who-set-this text, distinguishing a touch logged
  // via the contact card's tap-to-call/email confirm step (2026-09-23)
  // from an ordinary manual date edit -- e.g. "after a call by Jane Doe"
  // vs. plain "by Jane Doe". Empty string for 'manual' (and anything
  // else unrecognized) leaves the existing "by <name>" phrasing alone.
  function outgrowSourceNote(source) {
    if (source === 'call') return 'after a call ';
    if (source === 'email') return 'after an email ';
    return '';
  }

  function loadOutgrow(customerId) {
    state.outgrowLoading = true;
    var requestFor = Number(customerId);
    apiGet('api/outgrow.php?action=get&customer_id=' + encodeURIComponent(customerId)).then(function (r) {
      if (!state.selectedCustomer || Number(state.selectedCustomer.customer.id) !== requestFor) return;
      state.outgrowLoading = false;
      if (r.data && r.data.ok) {
        state.outgrowCurrent = r.data.current;
        state.outgrowHistory = r.data.history;
      }
      render();
    }).catch(function () {
      if (!state.selectedCustomer || Number(state.selectedCustomer.customer.id) !== requestFor) return;
      state.outgrowLoading = false;
      render();
    });
  }

  function saveOutgrow(customerId) {
    var date = state.outgrowDraftDate;
    if (!date) {
      state.outgrowError = 'Pick a date first.';
      render();
      return;
    }
    state.outgrowSaving = true;
    state.outgrowError = null;
    render();
    apiPost('api/outgrow.php?action=set', { customer_id: customerId, touch_date: date }).then(function (r) {
      state.outgrowSaving = false;
      if (r.data && r.data.ok) {
        state.outgrowCurrent = r.data.current;
        state.outgrowHistory = r.data.history;
        state.outgrowEditing = false;
        if (r.data.cw_push && r.data.cw_push.status === 'error') {
          state.outgrowError = 'Saved here, but didn\u2019t reach ConnectWise: ' + r.data.cw_push.error;
        }
      } else {
        state.outgrowError = (r.data && r.data.error) || 'Could not save.';
      }
      render();
    }).catch(function () {
      state.outgrowSaving = false;
      state.outgrowError = 'Could not save \u2014 check your connection.';
      render();
    });
  }

  // Logs an OutGrow touch with an explicit source ('call' or 'email'),
  // reached only after the confirm-step banner in contactCardHtml() is
  // answered "yes" -- see resetContactCardState()'s comment. Posts to the
  // exact same outgrow.php?action=set endpoint saveOutgrow() above uses
  // (same local-save-then-ConnectWise-push behavior, same history/current
  // response shape) so both the date-edit flow and this one stay one
  // source of truth; only the touch_date (today) and source differ.
  function logOutgrowTouch(customerId, source) {
    state.outgrowSaving = true;
    state.outgrowError = null;
    render();
    apiPost('api/outgrow.php?action=set', { customer_id: customerId, touch_date: outgrowTodayYmd(), source: source }).then(function (r) {
      state.outgrowSaving = false;
      if (r.data && r.data.ok) {
        state.outgrowCurrent = r.data.current;
        state.outgrowHistory = r.data.history;
        if (r.data.cw_push && r.data.cw_push.status === 'error') {
          state.outgrowError = 'Logged here, but didn\u2019t reach ConnectWise: ' + r.data.cw_push.error;
        }
      } else {
        state.outgrowError = (r.data && r.data.error) || 'Could not log the touch.';
      }
      render();
    }).catch(function () {
      state.outgrowSaving = false;
      state.outgrowError = 'Could not log the touch \u2014 check your connection.';
      render();
    });
  }

  function loadContactCard(customerId) {
    state.contactCardLoading = true;
    var requestFor = Number(customerId);
    apiGet('api/contact-card.php?action=get&customer_id=' + encodeURIComponent(customerId)).then(function (r) {
      if (!state.selectedCustomer || Number(state.selectedCustomer.customer.id) !== requestFor) return;
      state.contactCardLoading = false;
      if (r.data && r.data.ok) {
        state.contactCard = r.data;
      } else {
        state.contactCard = { available: false };
        state.contactCardError = (r.data && r.data.error) || 'Could not load contact info from ConnectWise.';
      }
      render();
    }).catch(function () {
      if (!state.selectedCustomer || Number(state.selectedCustomer.customer.id) !== requestFor) return;
      state.contactCardLoading = false;
      state.contactCardError = 'Could not load contact info \u2014 check your connection.';
      render();
    });
  }

  // Contact card HTML -- address, primary contact name/email, and
  // tap-to-call/tap-to-email links, shown just above the OutGrow Last
  // Touch card (per Michael's "show that data cleanly above Outgrow Last
  // Touch"). Renders nothing at all for a mock/unsynced customer
  // (available: false) rather than an empty card. The inline confirm
  // banner (state.outgrowConfirm) is what actually logs the OutGrow touch
  // -- tapping the tel:/mailto: link itself only opens the dialer/email
  // app and shows this banner; see logOutgrowTouch()'s comment and
  // resetContactCardState()'s comment on why that's a separate step.
  function contactCardHtml() {
    var card = state.contactCard;
    if (state.contactCardLoading && !card) {
      return '<div class="contact-card"><div class="loading">Loading contact info\u2026</div></div>';
    }
    if (!card || !card.available) {
      return '';
    }

    var html = '<div class="contact-card">';

    if (card.address) {
      var addr = card.address;
      var cityLine = [addr.city, addr.state, addr.zip].filter(Boolean).join(', ');
      html += '<div class="contact-card-address">' +
        (addr.line1 ? escapeHtml(addr.line1) + '<br>' : '') +
        (addr.line2 ? escapeHtml(addr.line2) + '<br>' : '') +
        (cityLine ? escapeHtml(cityLine) : '') +
      '</div>';
    }

    var contacts = card.contacts || [];
    html += '<div class="contact-card-person">';
    if (contacts.length === 0) {
      html += '<div class="contact-card-empty">No contacts with complete info on file.</div>';
    } else {
      var selected = null;
      for (var ci = 0; ci < contacts.length; ci++) {
        if (state.contactCardSelectedId && contacts[ci].id === state.contactCardSelectedId) {
          selected = contacts[ci];
          break;
        }
      }

      // Dropdown -- added 2026-09-23 per Michael: "show a drop down list
      // where the new contact info is... you should see the contacts
      // name info, email info and phone info in the list. When you
      // select the contact in question, you can then tap on email or
      // phone." .contact-card-dropdown is the outside-click boundary
      // onDocumentClick() checks to auto-close this, same pattern as the
      // customer search box's .search-wrap.
      html += '<div class="contact-card-dropdown">';
      html += '<button type="button" class="contact-card-dropdown-toggle" data-action="contact-dropdown-toggle" aria-expanded="' + (state.contactCardOpen ? 'true' : 'false') + '">' +
        '<span>' + (selected ? escapeHtml(selected.name || 'Contact') : 'Select a contact…') + '</span>' +
        '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="contact-card-dropdown-chevron"><polyline points="6 9 12 15 18 9"></polyline></svg>' +
      '</button>';

      if (state.contactCardOpen) {
        html += '<div class="contact-card-dropdown-panel">';
        contacts.forEach(function (c) {
          var rowClass = 'contact-card-dropdown-row' + (selected && c.id === selected.id ? ' selected' : '');
          html += '<div class="' + rowClass + '" data-action="contact-select" data-contact-id="' + escapeHtml(c.id) + '">' +
            '<div class="contact-card-dropdown-name">' + escapeHtml(c.name || 'Contact') + '</div>' +
            '<div class="contact-card-dropdown-meta">' + escapeHtml(c.email) + ' · ' + escapeHtml(c.phone) + '</div>' +
          '</div>';
        });
        html += '</div>';
      }
      html += '</div>'; // .contact-card-dropdown

      if (selected) {
        var links = '<a class="contact-card-link" href="tel:' + escapeHtml(selected.phone) + '" data-action="contact-call">' +
          '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.362 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.338 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>' +
          escapeHtml(selected.phone) + '</a>' +
          '<a class="contact-card-link" href="mailto:' + escapeHtml(selected.email) + '" data-action="contact-email">' +
          '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>' +
          escapeHtml(selected.email) + '</a>';
        html += '<div class="contact-card-links">' + links + '</div>';
      }
    }
    html += '</div>'; // .contact-card-person

    if (state.outgrowConfirm) {
      var confirmLabel = state.outgrowConfirm.source === 'call' ? 'Log this call as an OutGrow touch?' : 'Log this email as an OutGrow touch?';
      html += '<div class="contact-card-confirm">' +
        '<span class="contact-card-confirm-label">' + escapeHtml(confirmLabel) + '</span>' +
        '<div class="contact-card-confirm-actions">' +
          '<button type="button" class="svc-action-btn primary" data-action="contact-confirm-yes" ' + (state.outgrowSaving ? 'disabled' : '') + '>' + (state.outgrowSaving ? 'Logging\u2026' : 'Yes, log it') + '</button>' +
          '<button type="button" class="svc-action-btn secondary" data-action="contact-confirm-no" ' + (state.outgrowSaving ? 'disabled' : '') + '>No</button>' +
        '</div>' +
      '</div>';
    }

    if (state.contactCardError) {
      html += '<div class="contact-card-error">' + escapeHtml(state.contactCardError) + '</div>';
    }

    html += '</div>';
    return html;
  }

  function outgrowFieldHtml() {
    var current = state.outgrowCurrent;
    var valueText = current ? fmtOutgrowDate(current.touch_date) : 'Not recorded yet';
    var subText = current
      ? (current.source === 'connectwise_seed' ? 'Synced from ConnectWise' : outgrowSourceNote(current.source) + 'by ' + escapeHtml(current.set_by_name))
      : '';

    var html = '<div class="outgrow-card">';
    html += '<div class="outgrow-card-label-row">' +
      '<div class="outgrow-card-label">OutGrow Last Touch</div>' +
      '<button class="outgrow-history-btn" type="button" data-action="outgrow-history-toggle" aria-label="View history" title="View history">' +
        '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"></circle><polyline points="12 7 12 12 15.5 14"></polyline></svg>' +
      '</button>' +
    '</div>';
    html += '<div class="outgrow-card-value">' + escapeHtml(valueText) + '</div>';
    if (subText) {
      html += '<div class="outgrow-card-sub">' + subText + '</div>';
    }

    if (state.outgrowEditing) {
      html += '<div class="outgrow-edit-row">' +
        '<input type="date" id="outgrowDateInput" class="outgrow-date-input" value="' + escapeHtml(state.outgrowDraftDate) + '">' +
        '<button class="svc-action-btn primary" type="button" data-action="outgrow-save" ' + (state.outgrowSaving ? 'disabled' : '') + '>' + (state.outgrowSaving ? 'Saving\u2026' : 'Save') + '</button>' +
        '<button class="svc-action-btn secondary" type="button" data-action="outgrow-edit-cancel">Cancel</button>' +
      '</div>';
    } else {
      html += '<button class="outgrow-update-btn" type="button" data-action="outgrow-edit-start">Update</button>';
    }

    if (state.outgrowError) {
      html += '<div class="outgrow-error">' + escapeHtml(state.outgrowError) + '</div>';
    }

    if (state.outgrowHistoryOpen) {
      html += outgrowHistoryHtml();
    }

    html += '</div>';
    return html;
  }

  function outgrowHistoryHtml() {
    var html = '<div class="outgrow-history">';
    html += '<div class="outgrow-history-title">History</div>';
    if (state.outgrowLoading && !state.outgrowHistory) {
      html += '<div class="loading">Loading\u2026</div>';
    } else if (!state.outgrowHistory || state.outgrowHistory.length === 0) {
      html += '<div class="empty-state">No history yet.</div>';
    } else {
      html += '<div class="outgrow-history-list">';
      state.outgrowHistory.forEach(function (h) {
        var warn = h.cw_push_status === 'error'
          ? '<div class="outgrow-history-warn" title="' + escapeHtml(h.cw_push_error || '') + '">Didn\u2019t sync to ConnectWise</div>'
          : '';
        var whoText = h.source === 'connectwise_seed' ? 'Synced from ConnectWise' : outgrowSourceNote(h.source) + escapeHtml(h.set_by_name);
        html += '<div class="outgrow-history-row">' +
          '<div class="outgrow-history-main">' +
            '<span class="outgrow-history-date">' + escapeHtml(fmtOutgrowDate(h.touch_date)) + '</span>' +
            '<span class="outgrow-history-who">' + whoText + '</span>' +
          '</div>' +
          '<div class="outgrow-history-when">' + escapeHtml(fmtTimestamp(h.created_at)) + '</div>' +
          warn +
        '</div>';
      });
      html += '</div>';
    }
    html += '</div>';
    return html;
  }

  // ---- "Current vendor if not CodeBlue" (per pillar) --------------------

  function resetVendorState() {
    state.vendorNotes = null;
    state.vendorEditingPillarId = null;
    state.vendorDraft = '';
    state.vendorSaving = false;
    state.vendorError = null;
  }

  function loadVendorNotes(customerId) {
    var requestFor = Number(customerId);
    apiGet('api/vendor.php?action=list&customer_id=' + encodeURIComponent(customerId)).then(function (r) {
      if (!state.selectedCustomer || Number(state.selectedCustomer.customer.id) !== requestFor) return;
      if (r.data && r.data.ok) {
        state.vendorNotes = r.data.notes;
        render();
      }
    }).catch(function () { /* silent -- the field still renders, just without a saved value yet */ });
  }

  function saveVendorNote(customerId, pillarId) {
    state.vendorSaving = true;
    state.vendorError = null;
    render();
    apiPost('api/vendor.php?action=set', { customer_id: customerId, pillar_id: pillarId, vendor_name: state.vendorDraft }).then(function (r) {
      state.vendorSaving = false;
      if (r.data && r.data.ok) {
        if (!state.vendorNotes) state.vendorNotes = {};
        state.vendorNotes[pillarId] = r.data.note;
        state.vendorEditingPillarId = null;
      } else {
        state.vendorError = (r.data && r.data.error) || 'Could not save.';
      }
      render();
    }).catch(function () {
      state.vendorSaving = false;
      state.vendorError = 'Could not save \u2014 check your connection and try again.';
      render();
    });
  }

  // ---- Customer Meeting Capture (meetings + their to-do tasks) ----------

  function resetMeetingsState() {
    state.meetingsLoading = false;
    state.meetings = null;
    state.meetingsRoster = [];
    state.meetingsError = null;
    state.meetingAddOpen = false;
    state.meetingDraftSubject = '';
    state.meetingDraftDate = '';
    state.meetingDraftNotes = '';
    state.meetingSaving = false;
    state.openMeetingId = null;
    state.taskAddOpenForMeeting = null;
    state.taskDraftDescription = '';
    state.taskDraftAssignee = '';
    state.taskDraftDueDate = '';
    state.taskSaving = false;
    state.taskTogglingId = null;
    // pendingTaskFocus is deliberately NOT cleared here -- openCustomerAtTask()
    // sets it BEFORE calling selectCustomer(), which calls resetMeetingsState()
    // on its way to loadMeetings(); clearing it here would lose the deep-link
    // target before loadMeetings() ever gets to consume it.
  }

  function loadMeetings(customerId) {
    state.meetingsLoading = true;
    var requestFor = Number(customerId);
    apiGet('api/meetings.php?action=list&customer_id=' + encodeURIComponent(customerId)).then(function (r) {
      if (!state.selectedCustomer || Number(state.selectedCustomer.customer.id) !== requestFor) return;
      state.meetingsLoading = false;
      var focusTaskId = null;
      if (r.data && r.data.ok) {
        state.meetings = r.data.meetings;
        state.meetingsRoster = r.data.roster;
        // Deliberately no default assignee here (2026-09-23, per Michael:
        // "By default, the to-do should not show any rep" -- a rep must
        // actively pick one from the blank-first dropdown, see
        // taskAssigneeSelect's markup below). Used to default to
        // r.data.roster[0] (Claire Hayden, first in the fixed roster) the
        // moment meetings loaded; that's exactly the silent default
        // Michael asked to remove.
        if (state.pendingTaskFocus) {
          state.openMeetingId = state.pendingTaskFocus.meetingId;
          focusTaskId = state.pendingTaskFocus.taskId;
          state.pendingTaskFocus = null;
        }
      } else {
        state.meetingsError = (r.data && r.data.error) || 'Could not load meetings.';
      }
      render();
      if (focusTaskId) {
        var el = document.querySelector('[data-task-row="' + focusTaskId + '"]');
        if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
    }).catch(function () {
      if (!state.selectedCustomer || Number(state.selectedCustomer.customer.id) !== requestFor) return;
      state.meetingsLoading = false;
      state.meetingsError = 'Could not load meetings \u2014 check your connection and try again.';
      render();
    });
  }

  // ---- Risk-scan uploads (api/risk-scans.php) ---------------------------
  // Added 2026-09-23 per Michael -- see the state block's comment above
  // and risk-scans.php's file header for the full design. Same
  // load/reset/deep-link pattern as Meetings/Checklist above.

  function resetRiskScansState() {
    state.riskScans = null;
    state.riskScansLoading = false;
    state.riskScansError = null;
    state.riskScanDraftFile = null;
    state.riskScanUploading = false;
    state.riskScanTogglingId = null;
    // pendingRiskScanFocus is deliberately NOT cleared here -- same reason
    // pendingTaskFocus isn't cleared in resetMeetingsState() above.
  }

  function loadRiskScans(customerId) {
    state.riskScansLoading = true;
    var requestFor = Number(customerId);
    apiGet('api/risk-scans.php?action=list&customer_id=' + encodeURIComponent(customerId)).then(function (r) {
      if (!state.selectedCustomer || Number(state.selectedCustomer.customer.id) !== requestFor) return;
      state.riskScansLoading = false;
      var focusScanId = null;
      if (r.data && r.data.ok) {
        state.riskScans = r.data.scans;
        if (state.pendingRiskScanFocus) {
          focusScanId = state.pendingRiskScanFocus.scanId;
          state.pendingRiskScanFocus = null;
        }
      } else {
        state.riskScansError = (r.data && r.data.error) || 'Could not load risk scans.';
      }
      render();
      if (focusScanId) {
        var el = document.querySelector('[data-riskscan-row="' + focusScanId + '"]');
        if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
    }).catch(function () {
      if (!state.selectedCustomer || Number(state.selectedCustomer.customer.id) !== requestFor) return;
      state.riskScansLoading = false;
      state.riskScansError = 'Could not load risk scans \u2014 check your connection and try again.';
      render();
    });
  }

  function uploadRiskScan(customerId) {
    var file = state.riskScanDraftFile;
    if (!file) {
      state.riskScansError = 'Choose a .zip file first.';
      render();
      return;
    }
    state.riskScanUploading = true;
    state.riskScansError = null;
    render();
    var formData = new FormData();
    formData.append('customer_id', String(customerId));
    formData.append('file', file);
    apiUpload('api/risk-scans.php?action=upload', formData).then(function (r) {
      state.riskScanUploading = false;
      if (r.data && r.data.ok) {
        state.riskScanDraftFile = null;
        // The upload response already carries the updated PeopleFirst
        // fields when this is a PeopleFirst customer (risk-scans.php,
        // 'upload' action) -- apply them locally rather than a whole
        // extra round-trip just to refresh two date fields.
        if (r.data.customer && state.selectedCustomer && Number(state.selectedCustomer.customer.id) === Number(customerId)) {
          state.selectedCustomer.customer.last_risk_scan_at = r.data.customer.last_risk_scan_at;
          state.selectedCustomer.customer.last_risk_scan_by = r.data.customer.last_risk_scan_by;
        }
        loadRiskScans(customerId);
      } else {
        state.riskScansError = (r.data && r.data.error) || 'Could not upload that file.';
        render();
      }
    }).catch(function () {
      state.riskScanUploading = false;
      state.riskScansError = 'Could not upload that file \u2014 check your connection and try again.';
      render();
    });
  }

  function retryRiskScanCw(scanId) {
    state.riskScanRetryingId = scanId;
    render();
    apiPost('api/risk-scans.php?action=retry_cw_upload', { id: scanId }).then(function (r) {
      state.riskScanRetryingId = null;
      if (r.data && r.data.ok && state.riskScans) {
        var updated = r.data.scan;
        state.riskScans = state.riskScans.map(function (s) { return s.id === updated.id ? updated : s; });
      } else {
        state.riskScansError = (r.data && r.data.error) || 'Could not retry the ConnectWise attachment.';
      }
      render();
    }).catch(function () {
      state.riskScanRetryingId = null;
      state.riskScansError = 'Could not retry the ConnectWise attachment \u2014 check your connection and try again.';
      render();
    });
  }

  function setRiskScanReviewed(scanId, reviewed) {
    state.riskScanTogglingId = scanId;
    render();
    apiPost('api/risk-scans.php?action=' + (reviewed ? 'mark_reviewed' : 'unmark_reviewed'), { id: scanId }).then(function (r) {
      state.riskScanTogglingId = null;
      if (r.data && r.data.ok && state.riskScans) {
        var updated = r.data.scan;
        state.riskScans = state.riskScans.map(function (s) { return s.id === updated.id ? updated : s; });
      } else {
        state.riskScansError = (r.data && r.data.error) || 'Could not update that scan.';
      }
      render();
    }).catch(function () {
      state.riskScanTogglingId = null;
      state.riskScansError = 'Could not update that scan \u2014 check your connection and try again.';
      render();
    });
  }

  function openCustomerAtRiskScan(customerId, scanId) {
    state.view = 'dashboard';
    state.pendingRiskScanFocus = { scanId: scanId };
    selectCustomer(customerId);
  }

  function saveMeeting(customerId) {
    var subject = (state.meetingDraftSubject || '').trim();
    var date = state.meetingDraftDate;
    if (!subject || !date) {
      state.meetingsError = 'Enter a subject and a date.';
      render();
      return;
    }
    state.meetingSaving = true;
    state.meetingsError = null;
    render();
    apiPost('api/meetings.php?action=create_meeting', {
      customer_id: customerId, subject: subject, meeting_date: date, notes: state.meetingDraftNotes || ''
    }).then(function (r) {
      state.meetingSaving = false;
      if (r.data && r.data.ok) {
        state.meetings = [r.data.meeting].concat(state.meetings || []);
        state.meetingAddOpen = false;
        state.meetingDraftSubject = '';
        state.meetingDraftDate = '';
        state.meetingDraftNotes = '';
        state.openMeetingId = r.data.meeting.id;
        if (r.data.meeting.cw_push && r.data.meeting.cw_push.status === 'error') {
          state.meetingsError = 'Saved here, but didn\u2019t reach ConnectWise: ' + r.data.meeting.cw_push.error;
        }
      } else {
        state.meetingsError = (r.data && r.data.error) || 'Could not save the meeting.';
      }
      render();
    }).catch(function () {
      state.meetingSaving = false;
      state.meetingsError = 'Could not save the meeting \u2014 check your connection and try again.';
      render();
    });
  }

  function saveTask(meetingId) {
    var description = (state.taskDraftDescription || '').trim();
    var assignee = state.taskDraftAssignee;
    if (!description || !assignee) {
      state.meetingsError = 'Enter a task description and pick who it\u2019s assigned to.';
      render();
      return;
    }
    state.taskSaving = true;
    state.meetingsError = null;
    render();
    apiPost('api/meetings.php?action=add_task', {
      meeting_id: meetingId, description: description, assigned_to_name: assignee,
      due_date: state.taskDraftDueDate || null
    }).then(function (r) {
      state.taskSaving = false;
      if (r.data && r.data.ok) {
        (state.meetings || []).forEach(function (m) {
          if (m.id === meetingId) m.tasks.push(r.data.task);
        });
        state.taskAddOpenForMeeting = null;
        state.taskDraftDescription = '';
        state.taskDraftDueDate = '';
        if (r.data.task.cw_push && r.data.task.cw_push.status === 'error') {
          state.meetingsError = 'Task saved here, but didn\u2019t reach ConnectWise: ' + r.data.task.cw_push.error;
        }
      } else {
        state.meetingsError = (r.data && r.data.error) || 'Could not save the task.';
      }
      render();
    }).catch(function () {
      state.taskSaving = false;
      state.meetingsError = 'Could not save the task \u2014 check your connection and try again.';
      render();
    });
  }

  // formstackTab (added 2026-09-17, per Michael -- "every To-Do... completed
  // [should] create an entry in" CBT's Outgrow/Formstack activity-tracking
  // form) is a blank tab the caller already opened SYNCHRONOUSLY inside the
  // click handler, before this async call started -- browsers only allow
  // window.open() without a popup-blocker prompt when it happens directly
  // inside a user gesture, and by the time this function's apiPost().then()
  // callback runs, that gesture has long since ended. So the click handler
  // opens the blank tab up front and hands it in here; this function either
  // redirects it to the pre-filled form (completed=true, task really is now
  // done) or closes it (completed=false, or the save failed) once it knows
  // which. The form itself still needs a human to review and click Submit
  // (it has a reCAPTCHA, and CBT has no Formstack API access -- see
  // meetings.php's relationships_formstack_todo_url() for why this can't be
  // a silent backend submission).
  function toggleTaskDone(taskId, completed, formstackTab) {
    state.taskTogglingId = taskId;
    render();
    apiPost('api/meetings.php?action=set_task_done', { task_id: taskId, completed: completed }).then(function (r) {
      state.taskTogglingId = null;
      if (r.data && r.data.ok) {
        (state.meetings || []).forEach(function (m) {
          m.tasks = m.tasks.map(function (t) { return t.id === r.data.task.id ? r.data.task : t; });
        });
        if (formstackTab) {
          if (r.data.formstack_url) {
            formstackTab.location.href = r.data.formstack_url;
          } else {
            formstackTab.close();
          }
        }
      } else if (formstackTab) {
        formstackTab.close();
      }
      render();
    }).catch(function () {
      state.taskTogglingId = null;
      if (formstackTab) formstackTab.close();
      render();
    });
  }

  // ---- Global master to-do dashboard (Relationships front page) ---------

  function loadGlobalTodos() {
    if (state.globalTodosLoading) return;
    state.globalTodosLoading = true;
    state.globalTodosError = null;
    render();
    apiGet('api/meetings.php?action=global').then(function (r) {
      state.globalTodosLoading = false;
      if (r.data && r.data.ok) {
        state.globalTodos = r.data;
      } else {
        state.globalTodosError = (r.data && r.data.error) || 'Could not load the to-do dashboard.';
      }
      render();
    }).catch(function () {
      state.globalTodosLoading = false;
      state.globalTodosError = 'Could not load the to-do dashboard \u2014 check your connection.';
      render();
    });
  }

  // ---- Per-coordinator to-do view (state.view === 'rep-todos') ----------
  // Added 2026-09-16 per Michael: click a name in the Global To-Do
  // Checklist above to land here -- that person's own to-do list plus a
  // month calendar of the ones they've scheduled.

  function loadRepTodos(name) {
    state.repTodosLoading = true;
    state.repTodosError = null;
    render();
    apiGet('api/meetings.php?action=rep_todos&assigned_to_name=' + encodeURIComponent(name)).then(function (r) {
      state.repTodosLoading = false;
      if (r.data && r.data.ok) {
        state.repTodosData = r.data;
      } else {
        state.repTodosError = (r.data && r.data.error) || 'Could not load that to-do list.';
      }
      render();
    }).catch(function () {
      state.repTodosLoading = false;
      state.repTodosError = 'Could not load that to-do list \u2014 check your connection.';
      render();
    });
  }

  // Moves the rep-todos calendar by whole months, wrapping the year at
  // both ends (e.g. December 2026 + 1 -> January 2027).
  function shiftRepTodosMonth(delta) {
    var month = state.repTodosCalMonth + delta;
    var year = state.repTodosCalYear;
    while (month < 1) { month += 12; year -= 1; }
    while (month > 12) { month -= 12; year += 1; }
    state.repTodosCalMonth = month;
    state.repTodosCalYear = year;
    render();
  }

  // Zero-padded 2-digit number, for building "YYYY-MM-DD" strings without
  // relying on String.prototype.padStart (not used anywhere else in this
  // file).
  function rtPad2(n) {
    return n < 10 ? '0' + n : String(n);
  }

  // Fired once, right after a customer's dashboard loads -- non-blocking
  // (the rest of the dashboard renders immediately; these two stat cards
  // show their own loading state) since this means 1-2 extra live
  // ConnectWise round-trips that shouldn't hold up anything else.
  function loadActivitySummary(customerId) {
    state.activitySummaryLoading = true;
    // Guards against a slow response for a customer the CRC has since
    // navigated away from landing late and showing stale/wrong data (or
    // silently hiding the panel) for whoever's open now. customerId can
    // arrive as a string (a data-id DOM attribute) while
    // selectedCustomer.customer.id is always a number (from JSON) -- Number()
    // both sides rather than risk a strict-equality type mismatch that
    // would make every response look "stale" and never resolve.
    var requestFor = Number(customerId);
    render();
    apiGet('api/activity.php?action=summary&customer_id=' + encodeURIComponent(customerId)).then(function (r) {
      if (!state.selectedCustomer || Number(state.selectedCustomer.customer.id) !== requestFor) return;
      state.activitySummaryLoading = false;
      if (r.data && r.data.ok) {
        // { available: true, ... } or { available: false } (mock customer,
        // nothing to show, not an error) -- either way this is real data.
        state.activitySummary = r.data;
      } else {
        // A real failure (ConnectWise unreachable, a field-mapping bug,
        // etc.) -- kept distinct from "available: false" with no error so
        // the panel can show what went wrong instead of just vanishing.
        state.activitySummary = { available: false, error: (r.data && r.data.error) || 'Could not load ticket/billing activity from ConnectWise.' };
      }
      render();
    }).catch(function () {
      if (!state.selectedCustomer || state.selectedCustomer.customer.id !== requestFor) return;
      state.activitySummaryLoading = false;
      state.activitySummary = { available: false, error: 'Could not load ticket/billing activity — check your connection.' };
      render();
    });
  }

  function loadActivityTickets(customerId) {
    state.activityView = 'tickets';
    state.activityTicketsLoading = true;
    state.activityTickets = null;
    state.error = null;
    render();
    apiGet('api/activity.php?action=tickets&customer_id=' + encodeURIComponent(customerId)).then(function (r) {
      state.activityTicketsLoading = false;
      if (r.data && r.data.ok) {
        state.activityTickets = r.data.tickets;
      } else {
        state.activityTickets = 'error';
        state.error = (r.data && r.data.error) || 'Could not load tickets from ConnectWise.';
      }
      render();
    }).catch(function () {
      state.activityTicketsLoading = false;
      state.activityTickets = 'error';
      state.error = 'Could not load tickets — check your connection.';
      render();
    });
  }

  // periodType is 'month' (periodValue "YYYY-MM") or 'year' (periodValue
  // "YYYY", added 2026-09-15 for the annual-billing-cadence bars) --
  // whichever kind of Monthly Billing bar was clicked.
  function loadActivityInvoices(customerId, periodType, periodValue, label) {
    state.activityView = 'invoices';
    state.activityInvoicesPeriod = { type: periodType, value: periodValue, label: label };
    state.activityInvoicesLoading = true;
    state.activityInvoices = null;
    state.error = null;
    render();
    var paramName = periodType === 'year' ? 'year' : 'month';
    apiGet(
      'api/activity.php?action=invoices&customer_id=' + encodeURIComponent(customerId) + '&' + paramName + '=' + encodeURIComponent(periodValue)
    ).then(function (r) {
      state.activityInvoicesLoading = false;
      if (r.data && r.data.ok) {
        state.activityInvoices = r.data.invoices;
      } else {
        state.activityInvoices = 'error';
        state.error = (r.data && r.data.error) || 'Could not load invoices from ConnectWise.';
      }
      render();
    }).catch(function () {
      state.activityInvoicesLoading = false;
      state.activityInvoices = 'error';
      state.error = 'Could not load invoices — check your connection.';
      render();
    });
  }

  function loadActivityInvoiceDetail(invoiceId, invoiceNumber) {
    state.activityView = 'invoice-detail';
    state.activityInvoiceNumber = invoiceNumber;
    state.activityInvoiceDetailLoading = true;
    state.activityInvoiceDetail = null;
    state.error = null;
    render();
    apiGet('api/activity.php?action=invoice-detail&invoice_id=' + encodeURIComponent(invoiceId)).then(function (r) {
      state.activityInvoiceDetailLoading = false;
      if (r.data && r.data.ok) {
        state.activityInvoiceDetail = r.data.invoice;
      } else {
        state.activityInvoiceDetail = 'error';
        state.error = (r.data && r.data.error) || 'Could not load that invoice from ConnectWise.';
      }
      render();
    }).catch(function () {
      state.activityInvoiceDetailLoading = false;
      state.activityInvoiceDetail = 'error';
      state.error = 'Could not load that invoice — check your connection.';
      render();
    });
  }

  function selectCustomer(id) {
    state.loadingDetail = true;
    state.resultsOpen = false;
    state.activePillarId = null;
    state.error = null;
    render();
    apiGet('api/customers.php?action=detail&id=' + encodeURIComponent(id)).then(function (r) {
      state.loadingDetail = false;
      var scrollToKey = null;
      if (r.data && r.data.ok) {
        state.selectedCustomer = r.data;
        resetActivityState();
        loadActivitySummary(id);
        resetOutgrowState();
        loadOutgrow(id);
        resetContactCardState();
        loadContactCard(id);
        resetVendorState();
        loadVendorNotes(id);
        resetMeetingsState();
        loadMeetings(id);
        resetRiskScansState();
        loadRiskScans(id);
        if (state.pendingFocus) {
          var pf = state.pendingFocus;
          state.pendingFocus = null;
          state.activePillarId = pf.pillarId;
          scrollToKey = id + '::' + pf.pillarId + '::' + pf.serviceId;
          state.openChecklistKey = scrollToKey;
          loadChecklist(id, pf.pillarId, pf.serviceId);
        }
      } else {
        state.error = (r.data && r.data.error) || 'Could not load that customer.';
      }
      render();
      if (scrollToKey) {
        var el = document.querySelector('[data-checklist-key="' + scrollToKey + '"]');
        if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
    }).catch(function () {
      state.loadingDetail = false;
      state.error = 'Could not load that customer — check your connection and try again.';
      render();
    });
  }

  function loadChecklist(customerId, pillarId, serviceId) {
    var key = customerId + '::' + pillarId + '::' + serviceId;
    apiGet(
      'api/checklist.php?action=get&customer_id=' + encodeURIComponent(customerId) +
      '&pillar_id=' + encodeURIComponent(pillarId) + '&service_id=' + encodeURIComponent(serviceId)
    ).then(function (r) {
      state.checklists[key] = (r.data && r.data.ok) ? { steps: r.data.steps, killed: !!r.data.killed } : 'error';
      render();
    }).catch(function () {
      state.checklists[key] = 'error';
      render();
    });
  }

  function setChecklistStep(customerId, pillarId, serviceId, serviceName, stepNumber, completed) {
    apiPost('api/checklist.php?action=set', {
      customer_id: customerId, pillar_id: pillarId, service_id: serviceId,
      service_name: serviceName, step_number: stepNumber, completed: completed
    }).then(function (r) {
      if (r.data && r.data.ok) {
        loadChecklist(customerId, pillarId, serviceId);
      } else {
        state.error = (r.data && r.data.error) || 'Could not save that — try again.';
        render();
      }
    }).catch(function () {
      state.error = 'Could not save that — check your connection and try again.';
      render();
    });
  }

  // Cross-sell outreach scripts, notes, and Recycle/Kill actions -- added
  // 2026-09-23 per Michael: "I want to specify what happens with each
  // cross sell opportunity by step... create content dynamically for each
  // Pillar and step." Per Michael's own confirmed scope choice, only
  // Voice over IP / Cloud Voice System has a real script right now
  // (CROSS_SELL_SCRIPTS below) -- the notes/contact-selection/Recycle/Kill
  // machinery is generic and already live for every cross-sell-tracked
  // service (see relationships_cross_sell_map() in catalog.php); the
  // other 5 just show plain checkboxes + notes until their scripts arrive.

  // One entry per scripted cross-sell service, keyed "pillarId::serviceId".
  // Steps 2/4/6 are always the generic "Phone Call Follow-Up" (no content
  // needed -- see checklistStepRowHtml(), which just offers a tel: link
  // once a contact is selected) and step 7 is the Recycle/Kill row (also
  // generic, rendered once every step is checked off) -- so a script only
  // ever needs entries for steps 1/3/5, each { subject, servicesIntro,
  // body }. `body` is an array of lines (joined with \n for the plain-text
  // mailto: body Michael chose over an in-app HTML send -- see
  // claude/relationships-connectwise-sync.md for that decision) containing
  // {{FirstName}}/{{CompanyName}} merge tokens and, where Michael's script
  // has "Today, CodeBlue currently provides your team with...", the
  // '{{SERVICES_BLOCK}}' marker -- crossSellEmailContent() below splices
  // in that customer's actual active services there, or removes the line
  // entirely when they have none (per Michael: "ignore the line... if
  // there are no active pillar services in place").
  //
  // Steps 1 and 3's Subject lines were left blank when Michael first
  // pasted this script into chat (only Step 5 had one) -- rather than
  // guess, the real subjects (and a couple of small wording refinements
  // Michael had already made) were pulled from his own saved Outlook
  // drafts, found sitting in this repo's working tree as
  // "Marketing Emails/*.msg" while this feature was being built:
  // Step 1 = "Communication Solution with CodeBlue", Step 3 = "Zultys vs.
  // the others: What changes with CodeBlue" (Step 5's .msg matched the
  // chat script exactly). One stray citation-link artifact in Step 1's
  // .msg body ("...in 2023.gitnux <https://gitnux.org/...>") was cleaned
  // up to a plain sentence, and Step 3's "Hey{{FirstName}}," (missing
  // "there"/a space) was straightened out to match the greeting style of
  // the other two steps -- everything else below is verbatim.
  //
  // Still worth a look before relying on this: Step 3's original content
  // included a two-column comparison TABLE (Consideration /
  // Zultys+CodeBlue / Others) -- a mailto: body is plain text with no
  // table support, so it's flattened below into one line per
  // consideration ("Zultys + CodeBlue: ... / Others: ..."), preserving
  // every word of the original cell text.
  var CROSS_SELL_SCRIPTS = {
    'voip::cloud-voice': {
      1: {
        subject: 'Communication Solution with CodeBlue',
        servicesIntro: 'Today, CodeBlue currently provides your team with:',
        body: [
          'Hey there {{FirstName}},',
          'We appreciate the opportunity to support you and your team here at CodeBlue.',
          '{{SERVICES_BLOCK}}',
          'As your business evolves, we want to make sure every part of your technology—including the way customers and employees communicate—keeps pace.',
          '',
          'A better way to stay connected',
          'CodeBlue’s Zultys Voice over IP (VoIP) solution brings business calling, messaging, collaboration, and mobility into one secure, scalable platform.',
          '',
          '• Secure, reliable communications designed to support your business and customer experience',
          '• Advanced call routing, mobile access, chat, texting, voicemail tools, and collaboration features',
          '• Guidance from CodeBlue’s own voice and network engineering professionals, with responsive support when you need it',
          '',
          'Why businesses are moving to VoIP',
          '• Businesses using VoIP commonly report 50–75% lower telephony costs compared with traditional public switched telephone network (PSTN) service',
          '• 65% of enterprises worldwide used VoIP as their primary telephony system in 2023.',
          '• VoIP allows calls to move between desktop phones, computers, and mobile devices—so employees can remain available without being tied to a single physical office',
          '',
          'Why bundle voice with CodeBlue?',
          '• Simpler support experience: One knowledgeable partner that already understands your business and technology environment',
          '• One-stop IT and voice partner: Coordinate your network, cybersecurity, managed IT, and communications through CodeBlue rather than multiple vendors',
          '• End-to-end communications quality control: Our voice and networking engineers can help ensure the infrastructure behind your calls is designed for clear, dependable communication',
          '',
          'Every organization’s needs are different—we would welcome the opportunity to meet with you, and discuss a Zultys solution designed around your specific requirements.',
          'Thank you again for your partnership and for trusting CodeBlue Technology. We are always here to help you solve your next technical challenge.',
          '',
          'Best regards,'
        ]
      },
      3: {
        subject: 'Zultys vs. the others: What changes with CodeBlue',
        servicesIntro: 'Today, CodeBlue supports your organization with:',
        body: [
          'Hey there {{FirstName}},',
          'We value the opportunity to support {{CompanyName}} and help keep your technology dependable, secure, and aligned with your business goals.',
          '{{SERVICES_BLOCK}}',
          'Because we already understand your environment, we wanted to introduce a communications option that can bring your phone system, IT infrastructure, and support experience closer together: Zultys Voice over IP, delivered and supported by CodeBlue.',
          '',
          'More than a hosted phone platform',
          'There are over 2600 cloud-phone providers. However, a phone system is only as good as the support, network readiness, deployment planning, and long-term accountability behind it.',
          '',
          'With Zultys and CodeBlue, you receive a unified communications platform along with a local technology partner that can support the voice system and the IT environment it relies on. Zultys combines calling, messaging, video, mobility, and collaboration capabilities in one platform, while CodeBlue’s voice and network engineers help guide design, deployment, troubleshooting, and ongoing support.',
          '',
          'Side-by-side at a glance',
          '• Support experience — Zultys + CodeBlue: Direct relationship with CodeBlue engineers and support staff who can understand both your voice and IT environment. Others: Centralized cloud-provider support; support availability may vary by plan and service.',
          '• IT and voice accountability — Zultys + CodeBlue: One partner for communications, network readiness, managed IT, cybersecurity, and related technology services. Others: Voice platform provider; internal IT or another partner may manage network and endpoint issues.',
          '• Deployment flexibility — Zultys + CodeBlue: Cloud, on-premise, and hybrid configurations can be evaluated around operational, continuity, and business requirements. Others: Primarily cloud-delivered unified communications.',
          '• Hardware support approach — Zultys + CodeBlue: CodeBlue can help coordinate phones, configuration, deployment, and support as part of the broader solution. Others: Hardware terms, warranty coverage, and replacement processes should be reviewed in the applicable order and service agreement.',
          '• Contract discussion — Zultys + CodeBlue: CodeBlue can structure an engagement around your requirements; ask us about month-to-month service options and equipment terms. Others: Plan, payment, and commitment options vary by offer and agreement.',
          '• Quality control — Zultys + CodeBlue: One team can assess voice, internet connectivity, LAN/Wi-Fi, security, and user experience together. Others: Responsibility may span phone provider, internet provider, network partner, and internal IT.',
          '',
          'The CodeBlue difference',
          '• Simpler support: Instead of determining whether an issue belongs to the phone vendor, internet provider, network provider, or IT company, start with CodeBlue. We can help coordinate the right response and support the full technology picture.',
          '• One trusted technology partner: Your phones should not operate separately from the network, security, devices, and IT services your business relies on each day.',
          '• End-to-end communication quality: Voice quality depends on more than the handset. CodeBlue can evaluate the systems behind the call—including network performance, connectivity, configuration, and business-continuity needs.',
          '• Flexible commercial conversation: We will clearly review service, hardware, warranty, support, and contract terms before recommending a path. This matters because published equipment and subscription terms can differ significantly by provider, service type, and deployment model. For example, Zultys’ Hardware-as-a-Service offering is advertised with predictable monthly pricing but may require a three- or five-year agreement for qualifying deployments, while its equipment-rental program lists specific minimum commitments and early-termination terms.',
          '',
          'Let’s compare your actual needs',
          'A meaningful comparison should go beyond a per-user monthly price. We would love the opportunity to review your current phone environment, service agreement, renewal date, support concerns, office locations, remote-work needs, and hardware requirements.',
          '',
          'From there, CodeBlue can help determine whether Zultys is the right fit—and provide a clear comparison of costs, features, support responsibilities, warranty coverage, and contract options based on your organization’s specific needs.',
          'Thank you again for your partnership and your openness to letting CodeBlue help with your next technical challenge.',
          '',
          'Best regards,'
        ]
      },
      5: {
        subject: 'Is CodeBlue’s voice solution a fit for your business?',
        body: [
          'Hey there {{FirstName}},',
          'Thank you for taking the time to review the information we recently shared about CodeBlue Technology’s Zultys Voice over IP solution, including how it compares with other business communications platforms.',
          'Our goal is to understand how {{CompanyName}} handled customer phone calls and communications today, where you want to go, and whether CodeBlue can provide meaningful value through a more unified voice and IT support experience.',
          '',
          'We would appreciate the opportunity to schedule a 30-minute conversation to discuss:',
          '• Your current communications environment, provider, and support experience',
          '• Business goals around customer service, mobility, multiple locations, remote work, growth, and continuity',
          '• Any communication challenges or upcoming contract, equipment, or renewal considerations',
          '• Whether Zultys and CodeBlue’s engineering-led support model align with your needs',
          '',
          'At the end of the conversation, we can determine together whether there is a practical fit and value in moving forward. If there is not, you will still have a clearer view of the options available for your business communications strategy.',
          '',
          'Would you be available for a 30-minute meeting next week?',
          'Thank you again for your continued partnership with CodeBlue Technology. We appreciate the opportunity to support your business and remain ready to help with any technical challenge your team faces.',
          '',
          'Best regards,'
        ]
      }
    }
  };

  // Every currently-active service's name across every pillar for the
  // selected customer -- the data behind {{SERVICES_BLOCK}} above. Same
  // "active" flag customers.php?action=detail already returns (used
  // identically by printSummaryHtml()'s "Services Currently In Place"
  // section) -- not scoped to any one pillar, since Michael's script means
  // this literally ("the active customer pillar's we currently provide"),
  // and the service being marketed is by definition not active yet anyway.
  function crossSellActiveServiceNames(detail) {
    var names = [];
    (detail.pillars || []).forEach(function (pillar) {
      (pillar.services || []).forEach(function (svc) {
        if (svc.active) names.push(svc.name);
      });
    });
    return names;
  }

  // Fills in a scripted email for the given pillar/service/step + selected
  // contact. Returns null when this pillar/service has no script yet (the
  // 5 cross-sell services other than Cloud Voice System, until Michael
  // supplies their content) or the step isn't one of the scripted ones
  // (2/4/6 are plain phone-call steps, 7 is the closeout row).
  function crossSellEmailContent(pillarId, serviceId, stepNumber, contact) {
    var script = CROSS_SELL_SCRIPTS[pillarId + '::' + serviceId];
    var tpl = script && script[stepNumber];
    if (!tpl || !state.selectedCustomer) return null;

    var companyName = state.selectedCustomer.customer.name;
    var firstName = (contact.name || '').trim().split(/\s+/)[0] || 'there';
    var activeServices = crossSellActiveServiceNames(state.selectedCustomer);
    var servicesBlockLines = activeServices.length
      ? [tpl.servicesIntro].concat(activeServices.map(function (s) { return '• ' + s; })).concat([''])
      : [];

    var lines = [];
    tpl.body.forEach(function (line) {
      if (line === '{{SERVICES_BLOCK}}') {
        lines = lines.concat(servicesBlockLines);
      } else {
        lines.push(line);
      }
    });

    var mergeFields = function (s) {
      return s.split('{{FirstName}}').join(firstName).split('{{CompanyName}}').join(companyName);
    };

    return { subject: mergeFields(tpl.subject), body: mergeFields(lines.join('\n')) };
  }

  function crossSellMailtoHref(email, subject, body) {
    return 'mailto:' + escapeHtml(email) + '?subject=' + encodeURIComponent(subject) + '&body=' + encodeURIComponent(body);
  }

  function loadChecklistNotes(customerId, pillarId, serviceId) {
    var key = customerId + '::' + pillarId + '::' + serviceId;
    apiGet(
      'api/checklist.php?action=notes_get&customer_id=' + encodeURIComponent(customerId) +
      '&pillar_id=' + encodeURIComponent(pillarId) + '&service_id=' + encodeURIComponent(serviceId)
    ).then(function (r) {
      state.checklistNotes[key] = (r.data && r.data.ok) ? r.data.notes : 'error';
      render();
    }).catch(function () {
      state.checklistNotes[key] = 'error';
      render();
    });
  }

  function addChecklistNote(customerId, pillarId, serviceId, stepNumber) {
    var text = (state.checklistNoteDraftText || '').trim();
    if (!text) return;
    var key = customerId + '::' + pillarId + '::' + serviceId;
    state.checklistNoteSaving = true;
    render();
    apiPost('api/checklist.php?action=notes_add', {
      customer_id: customerId, pillar_id: pillarId, service_id: serviceId,
      step_number: stepNumber, note_text: text
    }).then(function (r) {
      state.checklistNoteSaving = false;
      if (r.data && r.data.ok) {
        state.checklistNoteDraftOpenKey = null;
        state.checklistNoteDraftText = '';
        loadChecklistNotes(customerId, pillarId, serviceId); // re-render happens inside
      } else {
        state.error = (r.data && r.data.error) || 'Could not save that note — try again.';
        render();
      }
    }).catch(function () {
      state.checklistNoteSaving = false;
      state.error = 'Could not save that note — check your connection and try again.';
      render();
    });
  }

  function recycleChecklist(customerId, pillarId, serviceId) {
    var key = customerId + '::' + pillarId + '::' + serviceId;
    state.checklistCloseoutSaving = key;
    render();
    apiPost('api/checklist.php?action=recycle', { customer_id: customerId, pillar_id: pillarId, service_id: serviceId }).then(function (r) {
      state.checklistCloseoutSaving = null;
      if (r.data && r.data.ok) {
        loadChecklist(customerId, pillarId, serviceId);
        if (state.openChecklistNotesKey === key) loadChecklistNotes(customerId, pillarId, serviceId);
      } else {
        state.error = (r.data && r.data.error) || 'Could not recycle that opportunity — try again.';
        render();
      }
    }).catch(function () {
      state.checklistCloseoutSaving = null;
      state.error = 'Could not recycle that opportunity — check your connection and try again.';
      render();
    });
  }

  function setChecklistKilled(customerId, pillarId, serviceId, killed) {
    var key = customerId + '::' + pillarId + '::' + serviceId;
    state.checklistCloseoutSaving = key;
    render();
    apiPost('api/checklist.php?action=kill', { customer_id: customerId, pillar_id: pillarId, service_id: serviceId, killed: killed }).then(function (r) {
      state.checklistCloseoutSaving = null;
      if (r.data && r.data.ok) {
        loadChecklist(customerId, pillarId, serviceId);
      } else {
        state.error = (r.data && r.data.error) || 'Could not save that — try again.';
        render();
      }
    }).catch(function () {
      state.checklistCloseoutSaving = null;
      state.error = 'Could not save that — check your connection and try again.';
      render();
    });
  }

  function loadReport() {
    state.reportLoading = true;
    state.report = null;
    state.error = null;
    render();
    apiGet('api/checklist.php?action=summary').then(function (r) {
      state.reportLoading = false;
      if (r.data && r.data.ok) {
        state.report = r.data.rows;
      } else {
        state.error = (r.data && r.data.error) || 'Could not load the report.';
      }
      render();
    }).catch(function () {
      state.reportLoading = false;
      state.error = 'Could not load the report — check your connection and try again.';
      render();
    });
  }

  function loadQueue(pillarId, serviceId, step, pillarName, serviceName) {
    state.queueLoading = true;
    state.queue = null;
    state.error = null;
    state.queueParams = { pillarId: pillarId, serviceId: serviceId, step: step, pillarName: pillarName, serviceName: serviceName };
    render();
    apiGet(
      'api/checklist.php?action=queue&pillar_id=' + encodeURIComponent(pillarId) +
      '&service_id=' + encodeURIComponent(serviceId) + '&step=' + encodeURIComponent(step)
    ).then(function (r) {
      state.queueLoading = false;
      if (r.data && r.data.ok) {
        state.queue = r.data.customers;
      } else {
        state.error = (r.data && r.data.error) || 'Could not load that list.';
      }
      render();
    }).catch(function () {
      state.queueLoading = false;
      state.error = 'Could not load that list — check your connection and try again.';
      render();
    });
  }

  function loadPeopleFirstSummary() {
    state.pfSummaryLoading = true;
    apiGet('api/peoplefirst.php?action=summary').then(function (r) {
      state.pfSummaryLoading = false;
      if (r.data && r.data.ok) {
        state.pfSummary = r.data;
      }
      render();
    }).catch(function () {
      state.pfSummaryLoading = false;
      render();
    });
  }

  function loadPeopleFirstQueue(type) {
    state.pfQueueLoading = true;
    state.pfQueue = null;
    state.pfQueueType = type;
    state.error = null;
    render();
    apiGet('api/peoplefirst.php?action=queue&type=' + encodeURIComponent(type)).then(function (r) {
      state.pfQueueLoading = false;
      if (r.data && r.data.ok) {
        state.pfQueue = r.data.customers;
      } else {
        state.error = (r.data && r.data.error) || 'Could not load that list.';
      }
      render();
    }).catch(function () {
      state.pfQueueLoading = false;
      state.error = 'Could not load that list — check your connection and try again.';
      render();
    });
  }

  function logPeopleFirst(customerId, type) {
    var key = customerId + '::' + type;
    state.pfLogging = key;
    render();
    apiPost('api/peoplefirst.php?action=log', { customer_id: customerId, type: type }).then(function (r) {
      state.pfLogging = null;
      if (r.data && r.data.ok && state.selectedCustomer && state.selectedCustomer.customer.id === r.data.customer.id) {
        state.selectedCustomer.customer = r.data.customer;
      } else if (!r.data || !r.data.ok) {
        state.error = (r.data && r.data.error) || 'Could not log that — try again.';
      }
      render();
    }).catch(function () {
      state.pfLogging = null;
      state.error = 'Could not log that — check your connection and try again.';
      render();
    });
  }

  function loadSyncStatus() {
    apiGet('api/sync.php?action=status').then(function (r) {
      if (r.data && r.data.ok) {
        state.syncTotals = r.data.totals;
        state.syncStartedAt = r.data.started_at;
      }
      render();
    }).catch(function () { /* silent -- the view still offers "Run Sync Now" */ });
    apiGet('api/sync.php?action=billing-status').then(function (r) {
      if (r.data && r.data.ok) {
        state.billingSyncTotals = r.data.totals;
        state.billingSyncStartedAt = r.data.started_at;
      }
      render();
    }).catch(function () { /* silent, same as above */ });
    apiGet('api/sync.php?action=prospect-status').then(function (r) {
      if (r.data && r.data.ok) {
        state.prospectSyncTotals = r.data.totals;
        state.prospectSyncStartedAt = r.data.started_at;
      }
      render();
    }).catch(function () { /* silent, same as above */ });
    apiGet('api/sync.php?action=ticket-history-status').then(function (r) {
      if (r.data && r.data.ok) {
        state.ticketHistorySyncTotals = r.data.totals;
        state.ticketHistorySyncStartedAt = r.data.started_at;
      }
      render();
    }).catch(function () { /* silent, same as above */ });
    apiGet('api/sync.php?action=contacts-status').then(function (r) {
      if (r.data && r.data.ok) {
        state.contactsSyncTotals = r.data.totals;
        state.contactsSyncStartedAt = r.data.started_at;
      }
      render();
    }).catch(function () { /* silent, same as above */ });
    apiGet('api/sync.php?action=territory-status').then(function (r) {
      if (r.data && r.data.ok) {
        state.territorySyncTotals = r.data.totals;
        state.territorySyncStartedAt = r.data.started_at;
      }
      render();
    }).catch(function () { /* silent, same as above */ });
  }

  // Kicks off a full ConnectWise sync: api/sync.php?action=start builds the
  // queue of agreements to pull (cheap -- a handful of list calls), then
  // stepSyncLoop() drains it in bounded batches, one HTTP request per
  // batch, so no single request risks Bluehost's execution-time limit even
  // though a full sync (~440 agreements) can take a few minutes overall.
  // Once the agreement queue is fully drained, runBillingSync() below picks
  // up automatically -- "Run Sync Now" does the same two-part sync the
  // nightly cron does, in one click.
  function runFullSync() {
    state.syncRunning = true;
    state.syncDone = false;
    state.syncErrors = [];
    state.error = null;
    render();
    apiPost('api/sync.php?action=start', {}).then(function (r) {
      if (!r.data || !r.data.ok) {
        state.syncRunning = false;
        state.error = (r.data && r.data.error) || 'Could not start the sync.';
        render();
        return;
      }
      state.syncTotal = r.data.total;
      state.syncProcessed = 0;
      render();
      stepSyncLoop();
    }).catch(function () {
      state.syncRunning = false;
      state.error = 'Could not start the sync — check your connection and try again.';
      render();
    });
  }

  function stepSyncLoop() {
    apiPost('api/sync.php?action=step', { batch_size: 20 }).then(function (r) {
      if (!r.data || !r.data.ok) {
        state.syncRunning = false;
        state.error = (r.data && r.data.error) || 'Sync failed partway through.';
        render();
        return;
      }
      state.syncTotals = r.data.totals;
      state.syncProcessed = r.data.totals.done + r.data.totals.error;
      if (r.data.errors && r.data.errors.length) {
        state.syncErrors = state.syncErrors.concat(r.data.errors);
      }
      if (r.data.done) {
        state.syncRunning = false;
        state.syncDone = true;
        render();
        runBillingSync();
      } else {
        render();
        stepSyncLoop();
      }
    }).catch(function () {
      state.syncRunning = false;
      state.error = 'Sync failed partway through — check your connection and try again.';
      render();
    });
  }

  // Same start()/step() shape as the agreement sync above, run right after
  // it as part of the same "Run Sync Now" click -- a failure here is shown
  // (sync-errors-title/list, same pattern) but doesn't retroactively
  // un-succeed the agreement sync that already completed; they're
  // independent queues (see api/sync.php's file header).
  function runBillingSync() {
    state.billingSyncRunning = true;
    state.billingSyncDone = false;
    state.billingSyncErrors = [];
    render();
    apiPost('api/sync.php?action=billing-start', {}).then(function (r) {
      if (!r.data || !r.data.ok) {
        state.billingSyncRunning = false;
        state.error = (r.data && r.data.error) || 'Agreements synced, but could not start the billing sync.';
        render();
        return;
      }
      state.billingSyncTotal = r.data.total;
      state.billingSyncProcessed = 0;
      render();
      billingStepSyncLoop();
    }).catch(function () {
      state.billingSyncRunning = false;
      state.error = 'Agreements synced, but the billing sync could not start — check your connection and try again.';
      render();
    });
  }

  function billingStepSyncLoop() {
    apiPost('api/sync.php?action=billing-step', { batch_size: 20 }).then(function (r) {
      if (!r.data || !r.data.ok) {
        state.billingSyncRunning = false;
        state.error = (r.data && r.data.error) || 'Billing sync failed partway through.';
        render();
        return;
      }
      state.billingSyncTotals = r.data.totals;
      state.billingSyncProcessed = r.data.totals.done + r.data.totals.error;
      if (r.data.errors && r.data.errors.length) {
        state.billingSyncErrors = state.billingSyncErrors.concat(r.data.errors);
      }
      if (r.data.done) {
        state.billingSyncRunning = false;
        state.billingSyncDone = true;
        render();
        runProspectSync();
      } else {
        render();
        billingStepSyncLoop();
      }
    }).catch(function () {
      state.billingSyncRunning = false;
      state.error = 'Billing sync failed partway through — check your connection and try again.';
      render();
    });
  }

  // Same start()/step() shape as the two syncs above, run right after
  // billing as part of the same "Run Sync Now" click -- see api/sync.php's
  // file header. Independent queue: a failure here doesn't retroactively
  // un-succeed the agreement or billing sync that already completed.
  function runProspectSync() {
    state.prospectSyncRunning = true;
    state.prospectSyncDone = false;
    state.prospectSyncErrors = [];
    render();
    apiPost('api/sync.php?action=prospect-start', {}).then(function (r) {
      if (!r.data || !r.data.ok) {
        state.prospectSyncRunning = false;
        state.error = (r.data && r.data.error) || 'Agreements and billing synced, but could not start the prospect sync.';
        render();
        return;
      }
      state.prospectSyncTotal = r.data.total;
      state.prospectSyncProcessed = 0;
      render();
      prospectStepSyncLoop();
    }).catch(function () {
      state.prospectSyncRunning = false;
      state.error = 'Agreements and billing synced, but the prospect sync could not start — check your connection and try again.';
      render();
    });
  }

  function prospectStepSyncLoop() {
    apiPost('api/sync.php?action=prospect-step', { batch_size: 50 }).then(function (r) {
      if (!r.data || !r.data.ok) {
        state.prospectSyncRunning = false;
        state.error = (r.data && r.data.error) || 'Prospect sync failed partway through.';
        render();
        return;
      }
      state.prospectSyncTotals = r.data.totals;
      state.prospectSyncProcessed = r.data.totals.done + r.data.totals.error;
      if (r.data.errors && r.data.errors.length) {
        state.prospectSyncErrors = state.prospectSyncErrors.concat(r.data.errors);
      }
      if (r.data.done) {
        state.prospectSyncRunning = false;
        state.prospectSyncDone = true;
        render();
        runTicketHistorySync();
      } else {
        render();
        prospectStepSyncLoop();
      }
    }).catch(function () {
      state.prospectSyncRunning = false;
      state.error = 'Prospect sync failed partway through — check your connection and try again.';
      render();
    });
  }

  // Same start()/step() shape as the three syncs above, run right after
  // prospects as part of the same "Run Sync Now" click -- see
  // api/sync.php's file header. Independent queue: a failure here doesn't
  // retroactively un-succeed any sync that already completed.
  function runTicketHistorySync() {
    state.ticketHistorySyncRunning = true;
    state.ticketHistorySyncDone = false;
    state.ticketHistorySyncErrors = [];
    render();
    apiPost('api/sync.php?action=ticket-history-start', {}).then(function (r) {
      if (!r.data || !r.data.ok) {
        state.ticketHistorySyncRunning = false;
        state.error = (r.data && r.data.error) || 'Agreements, billing, and prospects synced, but could not start the ticket history sync.';
        render();
        return;
      }
      state.ticketHistorySyncTotal = r.data.total;
      state.ticketHistorySyncProcessed = 0;
      render();
      ticketHistoryStepSyncLoop();
    }).catch(function () {
      state.ticketHistorySyncRunning = false;
      state.error = 'The ticket history sync could not start — check your connection and try again.';
      render();
    });
  }

  function ticketHistoryStepSyncLoop() {
    apiPost('api/sync.php?action=ticket-history-step', { batch_size: 50 }).then(function (r) {
      if (!r.data || !r.data.ok) {
        state.ticketHistorySyncRunning = false;
        state.error = (r.data && r.data.error) || 'Ticket history sync failed partway through.';
        render();
        return;
      }
      state.ticketHistorySyncTotals = r.data.totals;
      state.ticketHistorySyncProcessed = r.data.totals.done + r.data.totals.error;
      if (r.data.errors && r.data.errors.length) {
        state.ticketHistorySyncErrors = state.ticketHistorySyncErrors.concat(r.data.errors);
      }
      if (r.data.done) {
        state.ticketHistorySyncRunning = false;
        state.ticketHistorySyncDone = true;
        render();
        runContactsSync();
      } else {
        render();
        ticketHistoryStepSyncLoop();
      }
    }).catch(function () {
      state.ticketHistorySyncRunning = false;
      state.error = 'Ticket history sync failed partway through — check your connection and try again.';
      render();
    });
  }

  // Same shape again, run last as part of "Run Sync Now" -- once this
  // finishes, the front-page overview is reloaded so its gauges/list
  // reflect the sync that just ran (see loadOverview()).
  function runContactsSync() {
    state.contactsSyncRunning = true;
    state.contactsSyncDone = false;
    state.contactsSyncErrors = [];
    render();
    apiPost('api/sync.php?action=contacts-start', {}).then(function (r) {
      if (!r.data || !r.data.ok) {
        state.contactsSyncRunning = false;
        state.error = (r.data && r.data.error) || 'Everything else synced, but could not start the contacts sync.';
        render();
        return;
      }
      state.contactsSyncTotal = r.data.total;
      state.contactsSyncProcessed = 0;
      render();
      contactsStepSyncLoop();
    }).catch(function () {
      state.contactsSyncRunning = false;
      state.error = 'The contacts sync could not start — check your connection and try again.';
      render();
    });
  }

  function contactsStepSyncLoop() {
    apiPost('api/sync.php?action=contacts-step', { batch_size: 50 }).then(function (r) {
      if (!r.data || !r.data.ok) {
        state.contactsSyncRunning = false;
        state.error = (r.data && r.data.error) || 'Contacts sync failed partway through.';
        render();
        return;
      }
      state.contactsSyncTotals = r.data.totals;
      state.contactsSyncProcessed = r.data.totals.done + r.data.totals.error;
      if (r.data.errors && r.data.errors.length) {
        state.contactsSyncErrors = state.contactsSyncErrors.concat(r.data.errors);
      }
      if (r.data.done) {
        state.contactsSyncRunning = false;
        state.contactsSyncDone = true;
        render();
        runTerritorySync();
      } else {
        render();
        contactsStepSyncLoop();
      }
    }).catch(function () {
      state.contactsSyncRunning = false;
      state.error = 'Contacts sync failed partway through — check your connection and try again.';
      render();
    });
  }

  // Same shape again, run last as part of "Run Sync Now" -- once this
  // finishes, the front-page overview is reloaded so its gauges/list
  // reflect the sync that just ran (see loadOverview()), same as contacts
  // used to do directly before this stage was added.
  function runTerritorySync() {
    state.territorySyncRunning = true;
    state.territorySyncDone = false;
    state.territorySyncErrors = [];
    render();
    apiPost('api/sync.php?action=territory-start', {}).then(function (r) {
      if (!r.data || !r.data.ok) {
        state.territorySyncRunning = false;
        state.error = (r.data && r.data.error) || 'Everything else synced, but could not start the territory sync.';
        render();
        return;
      }
      state.territorySyncTotal = r.data.total;
      state.territorySyncProcessed = 0;
      render();
      territoryStepSyncLoop();
    }).catch(function () {
      state.territorySyncRunning = false;
      state.error = 'The territory sync could not start — check your connection and try again.';
      render();
    });
  }

  function territoryStepSyncLoop() {
    apiPost('api/sync.php?action=territory-step', { batch_size: 50 }).then(function (r) {
      if (!r.data || !r.data.ok) {
        state.territorySyncRunning = false;
        state.error = (r.data && r.data.error) || 'Territory sync failed partway through.';
        render();
        return;
      }
      state.territorySyncTotals = r.data.totals;
      state.territorySyncProcessed = r.data.totals.done + r.data.totals.error;
      if (r.data.errors && r.data.errors.length) {
        state.territorySyncErrors = state.territorySyncErrors.concat(r.data.errors);
      }
      if (r.data.done) {
        state.territorySyncRunning = false;
        state.territorySyncDone = true;
        render();
        loadOverview();
      } else {
        render();
        territoryStepSyncLoop();
      }
    }).catch(function () {
      state.territorySyncRunning = false;
      state.error = 'Territory sync failed partway through — check your connection and try again.';
      render();
    });
  }

  // ---- Territory Admin (api/territory-admin.php) -----------------------

  function loadTerritoryAdmin() {
    state.territoryAdminLoading = true;
    state.territoryAdminError = null;
    render();
    apiGet('api/territory-admin.php?action=list').then(function (r) {
      state.territoryAdminLoading = false;
      if (r.data && r.data.ok) {
        state.territoryAdmin = { assignments: r.data.assignments, territory_options: r.data.territory_options };
      } else {
        state.territoryAdminError = (r.data && r.data.error) || 'Could not load territory assignments.';
      }
      render();
    }).catch(function () {
      state.territoryAdminLoading = false;
      state.territoryAdminError = 'Could not load territory assignments — check your connection.';
      render();
    });
  }

  function addTerritoryAssignment() {
    var email = state.territoryAdminAddEmail.trim();
    var territory = state.territoryAdminAddTerritory.trim();
    if (!email || !territory) {
      state.territoryAdminError = 'Both an email and a territory name are required.';
      render();
      return;
    }
    state.territoryAdminSaving = true;
    state.territoryAdminError = null;
    render();
    apiPost('api/territory-admin.php?action=add', { email: email, territory_name: territory }).then(function (r) {
      state.territoryAdminSaving = false;
      if (!r.data || !r.data.ok) {
        state.territoryAdminError = (r.data && r.data.error) || 'Could not add that assignment.';
        render();
        return;
      }
      state.territoryAdminAddEmail = '';
      state.territoryAdminAddTerritory = '';
      loadTerritoryAdmin();
    }).catch(function () {
      state.territoryAdminSaving = false;
      state.territoryAdminError = 'Could not add that assignment — check your connection.';
      render();
    });
  }

  function removeTerritoryAssignment(id) {
    state.territoryAdminRemovingId = id;
    state.territoryAdminError = null;
    render();
    apiPost('api/territory-admin.php?action=remove', { id: id }).then(function (r) {
      state.territoryAdminRemovingId = null;
      if (!r.data || !r.data.ok) {
        state.territoryAdminError = (r.data && r.data.error) || 'Could not remove that assignment.';
        render();
        return;
      }
      loadTerritoryAdmin();
    }).catch(function () {
      state.territoryAdminRemovingId = null;
      state.territoryAdminError = 'Could not remove that assignment — check your connection.';
      render();
    });
  }

  function openCustomerAtChecklist(customerId, pillarId, serviceId, serviceName) {
    state.view = 'dashboard';
    state.pendingFocus = { pillarId: pillarId, serviceId: serviceId, serviceName: serviceName };
    selectCustomer(customerId);
  }

  // Global to-do panel -> a specific customer's task, same deep-link
  // pattern as openCustomerAtChecklist() above (click something in a
  // cross-customer view -> jump straight to that customer's dashboard,
  // focused on the item). loadMeetings() (called from inside
  // selectCustomer()) is what actually consumes state.pendingTaskFocus
  // once the customer's meetings have loaded.
  function openCustomerAtTask(customerId, meetingId, taskId) {
    state.view = 'dashboard';
    state.pendingTaskFocus = { meetingId: meetingId, taskId: taskId };
    selectCustomer(customerId);
  }

  function signOut() {
    apiPost('api/auth.php?action=logout', {}).finally(function () {
      window.location.href = 'login.html';
    });
  }

  // ---- Derived data -----------------------------------------------------

  // Only the services flagged cross_sell_eligible by the server (see
  // catalog.php's relationships_cross_sell_map()) show up here — the rest
  // of the catalog is missing-but-not-marketed, per CodeBlue's process.
  function missingRoster(detail) {
    var roster = [];
    detail.pillars.forEach(function (pillar) {
      pillar.services.forEach(function (svc) {
        if (!svc.active && svc.cross_sell_eligible) {
          roster.push({ pillarId: pillar.id, pillarName: pillar.name, serviceId: svc.id, serviceName: svc.name });
        }
      });
    });
    return roster;
  }

  // ---- Customer Service Summary (print) --------------------------------

  // The 4 pillars included in the printed summary, in display order --
  // Data Cabling is deliberately excluded, per Michael's request.
  var PRINT_SUMMARY_PILLAR_IDS = ['it', 'dc', 'voip', 'security'];

  // Short, factual blurb per "not in place" candidate service, ending with
  // a free-comparison offer -- per Michael's 2026-09-14 request, strictly
  // factual (no persuasive/salesy language). Keyed "pillarId::serviceId".
  // Only covers services that are cross_sell_eligible (see missingRoster()
  // above), since Michael chose to match the existing cross-sell list
  // rather than the full catalog for this feature's "not in place" filter.
  // That means Data Center Services has no entries here at all -- this app
  // currently has no cross-sell definition for that pillar (see
  // relationships_cross_sell_map() in catalog.php), so DC's "not in place"
  // section never has anything to show, and Voice over IP is narrowed to
  // Cloud Voice System only. Flagged to Michael when this shipped.
  var PRINT_SUMMARY_BLURBS = {
    'it::managed-it': 'Managed IT Services provides proactive monitoring, maintenance, and support for a customer\u2019s servers, workstations, and network under a single agreement, rather than on a break-fix basis. CodeBlue offers a free, no-obligation comparison of your current IT support arrangement against a Managed IT Services agreement.',
    'it::cyber-security': 'Cyber Security adds layered protection \u2014 including endpoint detection, email security, and ongoing vulnerability monitoring \u2014 beyond what\u2019s included in standard IT support. CodeBlue offers a free, no-obligation comparison of your current security coverage against CodeBlue\u2019s Cyber Security offering.',
    'it::provided-equipment': 'Provided Equipment supplies and maintains the workstations, servers, and related hardware a business runs on, in place of purchasing and managing that equipment separately. CodeBlue offers a free, no-obligation comparison of your current equipment arrangement against CodeBlue\u2019s Provided Equipment program.',
    'voip::cloud-voice': 'A Cloud Voice System delivers phone service hosted in the cloud \u2014 calling, voicemail, and desktop/mobile apps \u2014 without on-site phone system hardware to maintain. CodeBlue offers a free, no-obligation comparison of your current phone system against CodeBlue\u2019s Cloud Voice System.',
    'security::ip-cameras': 'IP Security Camera Systems provide networked video surveillance for a customer\u2019s premises, with remote viewing and recorded footage available from any location. CodeBlue offers a free, no-obligation comparison of your current camera setup (or lack of one) against a CodeBlue IP Security Camera System.',
    'security::access-control': 'Access Control Systems replace or supplement traditional keys with badge, fob, or code-based entry, along with a log of who accessed a location and when. CodeBlue offers a free, no-obligation comparison of your current access setup (or lack of one) against a CodeBlue Access Control System.'
  };

  function loadPrintTickets(customerId) {
    state.printTicketsLoading = true;
    state.printTickets = null;
    render();
    apiGet('api/activity.php?action=tickets&customer_id=' + encodeURIComponent(customerId)).then(function (r) {
      state.printTicketsLoading = false;
      if (r.data && r.data.ok) {
        state.printTickets = r.data.tickets;
      } else {
        state.printTickets = 'error';
      }
      render();
    }).catch(function () {
      state.printTicketsLoading = false;
      state.printTickets = 'error';
      render();
    });
  }

  function printSummaryHtml(detail) {
    var customer = detail.customer;
    var summary = state.activitySummary;
    var ticketsAvailable = !!(summary && summary.available !== false);

    var html = '<div class="print-summary-panel">';
    html += '<div class="print-summary-toolbar no-print">' +
      '<div class="drilldown-title">Print Service Summary \u2014 ' + escapeHtml(customer.name) + '</div>' +
      '<div class="print-summary-toolbar-actions">' +
        '<button class="svc-action-btn primary" type="button" data-action="print-summary-go">Print</button>' +
        '<button class="drilldown-back" type="button" data-action="close-print-summary" aria-label="Close">' +
          '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"></polyline></svg>' +
        '</button>' +
      '</div>' +
    '</div>';

    html += '<div id="printableSummary">';
    html += '<div class="print-doc-header">' +
      '<div class="print-doc-title">Service Summary</div>' +
      '<div class="print-doc-customer">' + escapeHtml(customer.name) + '</div>' +
      '<div class="print-doc-date">Prepared ' + escapeHtml(fmtDate(new Date().toISOString())) + '</div>' +
    '</div>';

    // Service Tickets YTD + highlighted highest-hours tickets
    html += '<div class="print-section">';
    html += '<div class="print-section-title">Service Tickets \u2014 Year to Date</div>';
    if (!ticketsAvailable) {
      html += '<div class="print-empty">Not available for this customer (no ConnectWise record on file).</div>';
    } else {
      html += '<div class="print-ticket-count">' + summary.ticket_count_ytd + ' ticket' + (summary.ticket_count_ytd === 1 ? '' : 's') + ' so far this year</div>';
      if (state.printTicketsLoading || state.printTickets === null) {
        html += '<div class="print-empty no-print">Loading highlighted tickets\u2026</div>';
      } else if (state.printTickets === 'error') {
        html += '<div class="print-empty">Could not load ticket detail from ConnectWise.</div>';
      } else {
        var top = state.printTickets.slice().sort(function (a, b) { return (b.hours || 0) - (a.hours || 0); }).slice(0, 3).filter(function (t) { return (t.hours || 0) > 0; });
        if (top.length === 0) {
          html += '<div class="print-empty">No tickets with logged hours this year.</div>';
        } else {
          html += '<div class="print-subhead">Highest-Hours Tickets \u2014 Check-In Talking Points</div>';
          html += '<table class="activity-table print-table"><thead><tr>' +
            '<th>Date</th><th>Ticket #</th><th>Summary</th><th>Engineer</th><th>Hours</th>' +
          '</tr></thead><tbody>';
          top.forEach(function (t) {
            html += '<tr><td>' + escapeHtml(fmtDate(t.date)) + '</td><td>#' + t.ticket_number + '</td>' +
              '<td>' + escapeHtml(t.summary) + '</td><td>' + escapeHtml(t.engineer) + '</td>' +
              '<td>' + t.hours + '</td></tr>';
          });
          html += '</tbody></table>';
        }
      }
    }
    html += '</div>';

    // Services currently in place
    html += '<div class="print-section">';
    html += '<div class="print-section-title">Services Currently In Place</div>';
    var anyActive = false;
    PRINT_SUMMARY_PILLAR_IDS.forEach(function (pillarId) {
      var pillar = detail.pillars.filter(function (p) { return p.id === pillarId; })[0];
      if (!pillar) return;
      var activeServices = pillar.services.filter(function (s) { return s.active; });
      if (activeServices.length === 0) return;
      anyActive = true;
      html += '<div class="print-pillar-block">';
      html += '<div class="print-pillar-name">' + escapeHtml(pillar.name) + '</div>';
      activeServices.forEach(function (svc) {
        svc.products.forEach(function (p) {
          html += '<div class="product-row"><span>' + escapeHtml(p.label) + '</span><span class="product-qty">' + fmtQty(p) + '</span></div>';
        });
      });
      html += '</div>';
    });
    if (!anyActive) {
      html += '<div class="print-empty">No active services in these pillars.</div>';
    }
    html += '</div>';

    // Pillar services not yet in place
    html += '<div class="print-section">';
    html += '<div class="print-section-title">Pillar Services Not Yet In Place</div>';
    var anyMissing = false;
    PRINT_SUMMARY_PILLAR_IDS.forEach(function (pillarId) {
      var pillar = detail.pillars.filter(function (p) { return p.id === pillarId; })[0];
      if (!pillar) return;
      var missing = pillar.services.filter(function (s) { return !s.active && s.cross_sell_eligible; });
      if (missing.length === 0) return;
      anyMissing = true;
      html += '<div class="print-pillar-block">';
      html += '<div class="print-pillar-name">' + escapeHtml(pillar.name) + '</div>';
      missing.forEach(function (svc) {
        var blurb = PRINT_SUMMARY_BLURBS[pillarId + '::' + svc.id] || '';
        html += '<div class="print-missing-service">' +
          '<div class="print-missing-service-name">' + escapeHtml(svc.name) + '</div>' +
          (blurb ? '<div class="print-missing-service-blurb">' + escapeHtml(blurb) + '</div>' : '') +
        '</div>';
      });
      html += '</div>';
    });
    if (!anyMissing) {
      html += '<div class="print-empty">This customer already has every eligible service in these pillars.</div>';
    }
    html += '</div>';

    html += '</div>'; // #printableSummary
    html += '</div>'; // .print-summary-panel
    return html;
  }

  // ---- Rendering ----------------------------------------------------

  function render() {
    // Capture the search input's focus/caret state *before* touching the
    // DOM -- replacing root.innerHTML while it's focused fires a 'blur' on
    // the old node synchronously, so anything read after the swap is
    // already stale. Reading document.activeElement here, before any
    // mutation, is the only reliable way to know it was focused.
    var searchFocus = captureSearchFocus();
    root.innerHTML = topbarHtml() + '<div class="main">' + mainHtml() + '</div>';
    bindEvents();
    restoreSearchFocus(searchFocus);
    adjustOverviewListScroll();
  }

  function captureSearchFocus() {
    var el = document.getElementById('customerSearchInput');
    if (el && document.activeElement === el) {
      return { start: el.selectionStart, end: el.selectionEnd };
    }
    return null;
  }

  function restoreSearchFocus(focusInfo) {
    if (!focusInfo) return;
    var el = document.getElementById('customerSearchInput');
    if (!el) return;
    el.focus();
    try {
      el.setSelectionRange(focusInfo.start, focusInfo.end);
    } catch (e) {
      // setSelectionRange can throw on some input types -- ignore, focus
      // alone is the important part.
    }
  }

  function topbarHtml() {
    if (!state.user) return '';
    return (
      '<div class="topbar">' +
        '<div class="topbar-left">' +
          '<div>' +
            '<div class="brand">Relationships</div>' +
            '<div class="brand-sub">CodeBlue Technology — Client Relationship Dashboard</div>' +
          '</div>' +
          '<nav class="topbar-nav">' +
            '<button class="nav-btn ' + (state.view === 'dashboard' ? 'active' : '') + '" type="button" data-action="show-dashboard">Dashboard</button>' +
            '<button class="nav-btn ' + (state.view === 'report' || state.view === 'queue' ? 'active' : '') + '" type="button" data-action="show-report">Cross-Sell Report</button>' +
            '<button class="nav-btn ' + (state.view === 'prospecting' ? 'active' : '') + '" type="button" data-action="show-prospecting">Prospecting</button>' +
            '<button class="nav-btn ' + (state.view === 'sync' ? 'active' : '') + '" type="button" data-action="show-sync">ConnectWise Sync</button>' +
            (state.user.is_territory_admin
              ? '<button class="nav-btn ' + (state.view === 'territory-admin' ? 'active' : '') + '" type="button" data-action="show-territory-admin">Territory Admin</button>'
              : '') +
          '</nav>' +
          '<a class="back-to-hub" href="' + HUB_URL + '">← Solutions Hub</a>' +
        '</div>' +
        '<div class="topbar-right">' +
          '<span>' + escapeHtml(state.user.name) + '</span>' +
          '<button class="signout-btn" data-action="signout" type="button">Sign Out</button>' +
        '</div>' +
      '</div>'
    );
  }

  function mainHtml() {
    if (!state.user) return '<div class="loading">Loading…</div>';

    if (state.view === 'report') {
      return (state.error ? '<div class="error-banner">' + escapeHtml(state.error) + '</div>' : '') + reportHtml();
    }
    if (state.view === 'queue') {
      return (state.error ? '<div class="error-banner">' + escapeHtml(state.error) + '</div>' : '') + queueHtml();
    }
    if (state.view === 'pf-queue') {
      return (state.error ? '<div class="error-banner">' + escapeHtml(state.error) + '</div>' : '') + pfQueueHtml();
    }
    if (state.view === 'prospecting') {
      return (state.error ? '<div class="error-banner">' + escapeHtml(state.error) + '</div>' : '') + prospectingHtml();
    }
    if (state.view === 'sync') {
      return (state.error ? '<div class="error-banner">' + escapeHtml(state.error) + '</div>' : '') + syncHtml();
    }
    if (state.view === 'territory-admin') {
      return (state.error ? '<div class="error-banner">' + escapeHtml(state.error) + '</div>' : '') + territoryAdminHtml();
    }
    if (state.view === 'rep-todos') {
      return (state.error ? '<div class="error-banner">' + escapeHtml(state.error) + '</div>' : '') + repTodosHtml();
    }

    var html = '<div class="search-wrap">' + searchBoxHtml() + '</div>';

    if (state.error) {
      html += '<div class="error-banner">' + escapeHtml(state.error) + '</div>';
    }

    if (state.loadingDetail) {
      html += '<div class="loading">Loading customer…</div>';
    } else if (state.selectedCustomer) {
      html += customerDashboardHtml(state.selectedCustomer);
    } else {
      // Front page split left/right, per Michael 2026-09-15: existing
      // Customer Information/overview stays left-justified, the new
      // Global Check-List To-Do's dashboard is right-justified.
      html += '<div class="frontpage-grid">' +
        '<div class="frontpage-left">' + overviewHtml() + '</div>' +
        '<div class="frontpage-right">' + globalTodosPanelHtml() + '</div>' +
      '</div>';
    }

    return html;
  }

  function reportHtml() {
    var html = '<div class="view-header">' +
      '<div class="view-title">Cross-Sell Step Report</div>' +
      '<div class="view-sub">How many customers are currently sitting at each step, per missing service. Click a number to see who.</div>' +
    '</div>';

    html += peopleFirstSummaryHtml();

    if (state.reportLoading || !state.report) {
      return html + '<div class="loading">Loading report…</div>';
    }
    if (state.report.length === 0) {
      return html + '<div class="empty-state">No cross-sell activity yet.</div>';
    }

    html += '<div class="report-table-wrap"><table class="report-table"><thead><tr>' +
      '<th class="report-service-col">Pillar / Service</th>' +
      [1, 2, 3, 4, 5, 6, 7].map(function (n) { return '<th>Step ' + n + '</th>'; }).join('') +
      '<th>Closed</th><th>Total</th>' +
    '</tr></thead><tbody>';

    state.report.forEach(function (row) {
      html += '<tr><td class="report-service-col">' +
        '<div class="report-pillar-name">' + escapeHtml(row.pillar_name) + '</div>' +
        '<div class="report-service-name">' + escapeHtml(row.service_name) + '</div>' +
      '</td>';
      for (var n = 1; n <= 7; n++) {
        html += '<td>' + reportCellHtml(row, String(n), row.steps[String(n)] || 0) + '</td>';
      }
      html += '<td>' + reportCellHtml(row, 'closed', row.closed) + '</td>';
      html += '<td class="report-total">' + row.total + '</td></tr>';
    });

    html += '</tbody></table></div>';
    return html;
  }

  function reportCellHtml(row, step, count) {
    if (!count) return '<span class="report-count zero">0</span>';
    return '<button class="report-count" type="button" data-action="report-cell" ' +
      'data-pillar="' + row.pillar_id + '" data-service="' + row.service_id + '" data-step="' + step + '" ' +
      'data-pillar-name="' + escapeHtml(row.pillar_name) + '" data-service-name="' + escapeHtml(row.service_name) + '">' +
      count + '</button>';
  }

  // PeopleFirst summary line atop the Cross-Sell Report — separate from
  // the step-report table above since these customers aren't cross-sell
  // targets; the two numbers here are click-through counts of who needs a
  // client checkin this calendar month or a risk scan this calendar
  // quarter (see peoplefirst.php's need_checkin/need_scan for the exact
  // rule).
  function peopleFirstSummaryHtml() {
    if (state.pfSummaryLoading && !state.pfSummary) {
      return '<div class="peoplefirst-summary loading">Loading PeopleFirst status…</div>';
    }
    if (!state.pfSummary) return '';

    var s = state.pfSummary;
    return '<div class="peoplefirst-summary">' +
      '<div class="peoplefirst-summary-title">★ PeopleFirst Members — ' + s.total + ' total</div>' +
      '<div class="peoplefirst-summary-counts">' +
        peopleFirstCountHtml(s.needs_checkin, 'need a client checkin this month', 'checkin') +
        peopleFirstCountHtml(s.needs_scan, 'need a risk scan this quarter', 'scan') +
      '</div>' +
    '</div>';
  }

  function peopleFirstCountHtml(count, label, type) {
    if (!count) {
      return '<div class="peoplefirst-summary-count zero"><span class="peoplefirst-summary-number">0</span> ' + escapeHtml(label) + '</div>';
    }
    return '<button class="peoplefirst-summary-count" type="button" data-action="pf-open-queue" data-type="' + type + '">' +
      '<span class="peoplefirst-summary-number">' + count + '</span> ' + escapeHtml(label) +
    '</button>';
  }

  function pfQueueHtml() {
    var type = state.pfQueueType;
    var title = type === 'scan' ? 'Needs a Risk Scan This Quarter' : 'Needs a Client Checkin This Month';
    var html = '<div class="view-header">' +
      '<button class="back-link" type="button" data-action="pf-queue-back">← Back to report</button>' +
      '<div class="view-title">' + title + '</div>' +
      '<div class="view-sub">Click a customer to open their dashboard and log it.</div>' +
    '</div>';

    if (state.pfQueueLoading || !state.pfQueue) {
      return html + '<div class="loading">Loading…</div>';
    }
    if (state.pfQueue.length === 0) {
      return html + '<div class="empty-state">Nobody currently needs this — everyone’s up to date.</div>';
    }

    html += '<div class="queue-list">';
    state.pfQueue.forEach(function (c) {
      var lastLabel = c.last_at ? ('Last: ' + fmtTimestamp(c.last_at) + (c.last_by ? ' — ' + escapeHtml(c.last_by) : '')) : 'Never logged';
      html += '<div class="queue-item" data-action="open-pf-queue-customer" data-customer="' + c.id + '">' +
        '<div class="queue-item-name">' + escapeHtml(c.name) + '<div class="queue-item-meta">' + escapeHtml(lastLabel) + '</div></div>' +
        '<div class="queue-item-go">Open →</div>' +
      '</div>';
    });
    html += '</div>';
    return html;
  }

  function queueHtml() {
    var qp = state.queueParams || {};
    var stepLabel = qp.step === 'closed' ? 'Closed / re-address in 180 days' : ('Step ' + qp.step);
    var html = '<div class="view-header">' +
      '<button class="back-link" type="button" data-action="queue-back">← Back to report</button>' +
      '<div class="view-title">' + escapeHtml(qp.serviceName || '') + ' — ' + escapeHtml(stepLabel) + '</div>' +
      '<div class="view-sub">' + escapeHtml(qp.pillarName || '') + '. Click a customer to open their checklist at this step.</div>' +
    '</div>';

    if (state.queueLoading || !state.queue) {
      return html + '<div class="loading">Loading…</div>';
    }
    if (state.queue.length === 0) {
      return html + '<div class="empty-state">No customers currently at this step.</div>';
    }

    html += '<div class="queue-list">';
    state.queue.forEach(function (c) {
      html += '<div class="queue-item" data-action="open-queue-customer" data-customer="' + c.customer_id + '">' +
        '<div class="queue-item-name">' + escapeHtml(c.customer_name) + '</div>' +
        '<div class="queue-item-go">Open →</div>' +
      '</div>';
    });
    html += '</div>';
    return html;
  }

  // ---- Prospecting (api/prospecting.php) -- added 2026-09-23, per Michael ---
  // A rep issues a "Prospect" command, a research agent finds 5-8 target-
  // market businesses, the rep opens one for a profile and claims it as a
  // ConnectWise Prospect (90-day clock). See api/prospecting.php and
  // prospecting-agent.php for the server side and what the agent can see.

  function safeUrl(u) {
    return (typeof u === 'string' && /^https?:\/\//i.test(u)) ? u : '';
  }

  function pfLink(label, url) {
    var href = safeUrl(url);
    return href
      ? '<a href="' + escapeHtml(href) + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(label) + '</a>'
      : escapeHtml(label);
  }

  function applyProspectPayload(d) {
    var p = state.prospecting;
    p.configured = d.configured !== false;
    p.industries = d.industries || p.industries || [];
    p.dailyCap = d.daily_cap;
    p.remainingToday = d.remaining_today;
    p.search = d.search || null;
    p.candidates = d.candidates || [];
    p.skipped = d.skipped || [];
    p.selectedId = null;
    p.draft = null;
    p.claimError = null;
    p.profileError = null;
  }

  function loadProspecting() {
    var p = state.prospecting;
    if (p.loading) return;
    p.loading = true;
    p.error = null;
    render();
    apiGet('api/prospecting.php?action=latest').then(function (r) {
      p.loading = false;
      p.loaded = true;
      if (r.data && r.data.ok) {
        applyProspectPayload(r.data);
      } else {
        p.error = (r.data && r.data.error) || 'Could not load Prospecting.';
      }
      render();
    }).catch(function () {
      p.loading = false;
      p.error = 'Could not load Prospecting — check your connection and try again.';
      render();
    });
  }

  var pfElapsedTimer = null;
  function pfStopElapsedTimer() {
    if (pfElapsedTimer) { clearInterval(pfElapsedTimer); pfElapsedTimer = null; }
  }
  function pfStartElapsedTimer() {
    pfStopElapsedTimer();
    pfElapsedTimer = setInterval(function () {
      var el = document.getElementById('pfElapsed');
      if (!el) return;
      var s = Math.floor((Date.now() - state.prospecting.searchStartedAt) / 1000);
      el.textContent = Math.floor(s / 60) + ':' + ('0' + (s % 60)).slice(-2);
    }, 1000);
  }

  function runProspectSearch() {
    var p = state.prospecting;
    if (p.searching) return;
    p.searching = true;
    p.error = null;
    p.searchStartedAt = Date.now();
    render();
    pfStartElapsedTimer();
    apiPost('api/prospecting.php?action=search', {
      industry: p.form.industry,
      location: p.form.location,
      radius_miles: parseInt(p.form.radius, 10) || 150
    }).then(function (r) {
      pfStopElapsedTimer();
      p.searching = false;
      if (r.data && r.data.ok) {
        applyProspectPayload(r.data);
      } else {
        p.error = (r.data && r.data.error) || 'The search did not finish. Please try again.';
      }
      render();
    }).catch(function () {
      pfStopElapsedTimer();
      p.searching = false;
      p.error = 'The connection dropped or the search timed out. It may still finish on the server — reopen Prospecting in a minute to see the results.';
      render();
    });
  }

  function findProspect(id) {
    var list = state.prospecting.candidates;
    for (var i = 0; i < list.length; i++) {
      if (list[i].id === id) return list[i];
    }
    return null;
  }

  function prospectDraftFrom(c) {
    return {
      website: c.website || '',
      phone: c.phone || '',
      address_line1: c.address_line1 || '',
      city: c.city || '',
      state: c.state || '',
      zip: c.zip || '',
      contact_first_name: c.contact.first_name || '',
      contact_last_name: c.contact.last_name || '',
      contact_title: c.contact.title || '',
      contact_email: c.contact.email || '',
      contact_phone: c.contact.phone || ''
    };
  }

  function selectProspect(id) {
    var c = findProspect(id);
    var p = state.prospecting;
    p.selectedId = id;
    p.draft = c ? prospectDraftFrom(c) : null;
    p.claimError = null;
    p.profileError = null;
    render();
  }

  function replaceProspect(updated) {
    var p = state.prospecting;
    p.candidates = p.candidates.map(function (c) { return c.id === updated.id ? updated : c; });
  }

  function saveProspectDraft(then) {
    var p = state.prospecting;
    var c = findProspect(p.selectedId);
    if (!c || !p.draft) return;
    p.saving = true;
    p.claimError = null;
    render();
    var body = { candidate_id: c.id };
    Object.keys(p.draft).forEach(function (k) { body[k] = p.draft[k]; });
    apiPost('api/prospecting.php?action=update_candidate', body).then(function (r) {
      p.saving = false;
      if (r.data && r.data.ok) {
        replaceProspect(r.data.candidate);
        p.draft = prospectDraftFrom(r.data.candidate);
        if (then) { then(r.data.candidate); return; }
      } else {
        p.claimError = (r.data && r.data.error) || 'Could not save those details.';
      }
      render();
    }).catch(function () {
      p.saving = false;
      p.claimError = 'Could not save — check your connection and try again.';
      render();
    });
  }

  function buildProspectProfile() {
    var p = state.prospecting;
    var c = findProspect(p.selectedId);
    if (!c || p.profileLoading) return;
    p.profileLoading = true;
    p.profileError = null;
    render();
    apiPost('api/prospecting.php?action=profile', { candidate_id: c.id }).then(function (r) {
      p.profileLoading = false;
      if (r.data && r.data.ok) {
        replaceProspect(r.data.candidate);
        p.draft = prospectDraftFrom(r.data.candidate);
      } else {
        p.profileError = (r.data && r.data.error) || 'Could not build the profile. Please try again.';
      }
      render();
    }).catch(function () {
      p.profileLoading = false;
      p.profileError = 'The profile took too long or the connection dropped — please try again.';
      render();
    });
  }

  function pfMissingForClaim(c, d) {
    var m = [];
    if (!c.name) m.push('business name');
    if (!(d.contact_first_name || '').trim()) m.push('contact first name');
    if (!(d.contact_last_name || '').trim()) m.push('contact last name');
    if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test((d.contact_email || '').trim())) m.push('contact email');
    if (!(d.contact_phone || '').trim() && !(d.phone || '').trim()) m.push('phone number');
    return m;
  }

  function claimProspect() {
    var p = state.prospecting;
    var c = findProspect(p.selectedId);
    if (!c || p.claiming) return;
    var missing = pfMissingForClaim(c, p.draft || {});
    if (missing.length) {
      p.claimError = 'Before claiming, fill in: ' + missing.join(', ') + '.';
      render();
      return;
    }
    // Save whatever the rep typed first, then claim.
    saveProspectDraft(function (saved) {
      p.claiming = true;
      p.claimError = null;
      render();
      apiPost('api/prospecting.php?action=claim', { candidate_id: saved.id }).then(function (r) {
        p.claiming = false;
        if (r.data && r.data.ok) {
          saved.claimed_customer_id = r.data.customer_id;
          replaceProspect(saved);
          p.claimResult = { id: saved.id, customerId: r.data.customer_id, warnings: r.data.warnings || [] };
          p.claims = null; // refresh My Prospects next time it's opened
          state.overview = null; // front-page counts changed
        } else {
          p.claimError = (r.data && r.data.error) || 'Could not add this prospect to ConnectWise.';
        }
        render();
      }).catch(function () {
        p.claiming = false;
        p.claimError = 'The connection dropped while claiming. Check My Prospects before trying again so it isn’t created twice.';
        render();
      });
    });
  }

  function loadProspectClaims() {
    var p = state.prospecting;
    if (p.claimsLoading) return;
    p.claimsLoading = true;
    render();
    apiGet('api/prospecting.php?action=my_claims').then(function (r) {
      p.claimsLoading = false;
      if (r.data && r.data.ok) {
        p.claims = r.data.claims || [];
      } else {
        p.error = (r.data && r.data.error) || 'Could not load claimed prospects.';
      }
      render();
    }).catch(function () {
      p.claimsLoading = false;
      p.error = 'Could not load claimed prospects — check your connection.';
      render();
    });
  }

  function filteredProspects() {
    var p = state.prospecting;
    var loc = (p.filterLocation || '').trim().toLowerCase();
    return p.candidates.filter(function (c) {
      if (p.filterTier === 'High' && c.tier !== 'High') return false;
      if (p.filterTier === 'Medium' && c.tier !== 'High' && c.tier !== 'Medium') return false;
      if (p.filterIndustry !== 'all' && c.industry !== p.filterIndustry) return false;
      if (loc) {
        var hay = ((c.city || '') + ' ' + (c.state || '') + ' ' + (c.zip || '')).toLowerCase();
        if (hay.indexOf(loc) === -1) return false;
      }
      return true;
    });
  }

  function pfChipHtml(ok, okText, missingText) {
    return ok
      ? '<span class="pf-chip ok">✓ ' + escapeHtml(okText) + '</span>'
      : '<span class="pf-chip missing">' + escapeHtml(missingText) + '</span>';
  }

  function prospectCardHtml(c) {
    var p = state.prospecting;
    var contactName = ((c.contact.first_name || '') + ' ' + (c.contact.last_name || '')).trim();
    var emp = c.employee_low != null || c.employee_high != null
      ? (c.employee_low != null && c.employee_high != null && c.employee_low !== c.employee_high
          ? c.employee_low + '–' + c.employee_high : (c.employee_low != null ? c.employee_low : c.employee_high)) + ' employees (est.)'
      : 'Size unknown';
    var place = [c.city, c.state].filter(Boolean).join(', ');
    return '<div class="pf-card' + (p.selectedId === c.id ? ' selected' : '') + (c.claimed_customer_id ? ' claimed' : '') + '" data-action="prospect-select" data-id="' + c.id + '">' +
      '<div class="pf-card-top">' +
        '<div class="pf-card-name">' + escapeHtml(c.name) + '</div>' +
        '<span class="pf-tier pf-tier-' + escapeHtml(c.tier) + '" title="Confidence score ' + c.confidence + '/100">' + escapeHtml(c.tier) + ' · ' + c.confidence + '</span>' +
      '</div>' +
      '<div class="pf-card-meta">' + escapeHtml(c.industry || 'Industry unknown') + ' · ' + escapeHtml(place || 'Location unknown') +
        (c.distance_mi != null ? ' · ' + Math.round(c.distance_mi) + ' mi from Richmond' : '') + '</div>' +
      '<div class="pf-card-meta">' + escapeHtml(emp) + '</div>' +
      '<div class="pf-card-contact">' + (contactName ? escapeHtml(contactName) + (c.contact.title ? ', ' + escapeHtml(c.contact.title) : '') : '<span class="pf-chip missing">no contact found</span>') + '</div>' +
      '<div class="pf-chips">' +
        pfChipHtml(!!c.contact.email, 'email', 'missing email') +
        pfChipHtml(!!(c.contact.phone || c.phone), 'phone', 'missing phone') +
        (c.claimed_customer_id ? '<span class="pf-chip claimed">Claimed</span>' : '') +
      '</div>' +
    '</div>';
  }

  function pfInput(field, label, type) {
    var d = state.prospecting.draft || {};
    return '<label class="pf-field"><span>' + escapeHtml(label) + '</span>' +
      '<input type="' + (type || 'text') + '" data-pf="draft.' + field + '" value="' + escapeHtml(d[field] || '') + '"></label>';
  }

  function prospectDetailHtml(c) {
    var p = state.prospecting;
    var prof = c.profile;
    var html = '<div class="pf-detail">';
    html += '<div class="pf-detail-head"><div>' +
      '<div class="pf-detail-name">' + escapeHtml(c.name) + '</div>' +
      '<div class="pf-detail-sub">' + escapeHtml(c.industry || '') + (safeUrl(c.website) ? ' · ' + pfLink(c.website.replace(/^https?:\/\//i, ''), c.website) : '') + '</div>' +
    '</div><button type="button" class="pf-close" data-action="prospect-close" aria-label="Close">×</button></div>';

    html += '<div class="pf-chips">' +
      '<span class="pf-tier pf-tier-' + escapeHtml(c.tier) + '">' + escapeHtml(c.tier) + ' confidence · ' + c.confidence + '/100</span>' +
      (c.missing || []).map(function (m) { return '<span class="pf-chip missing">missing: ' + escapeHtml(m) + '</span>'; }).join('') +
    '</div>';

    if (c.summary) html += '<p class="pf-text">' + escapeHtml(c.summary) + '</p>';
    if (c.employee_evidence) {
      html += '<p class="pf-note">Size: ' + escapeHtml(c.employee_evidence) + (safeUrl(c.employee_source_url) ? ' — ' + pfLink('source', c.employee_source_url) : '') + '</p>';
    }

    // ---- Profile (bio, contact background, recommendations) ----
    if (prof) {
      html += '<div class="pf-section-title">Business profile</div>';
      if (prof.business_summary) html += '<p class="pf-text">' + escapeHtml(prof.business_summary) + '</p>';
      if (prof.primary_contact && prof.primary_contact.background) {
        html += '<div class="pf-section-title">Primary contact</div><p class="pf-text">' + escapeHtml(prof.primary_contact.background) +
          (safeUrl(prof.primary_contact.profile_url) ? ' ' + pfLink('Public profile →', prof.primary_contact.profile_url) : '') + '</p>';
      } else if (prof.primary_contact && safeUrl(prof.primary_contact.profile_url)) {
        html += '<div class="pf-section-title">Primary contact</div><p class="pf-text">' + pfLink('Public profile →', prof.primary_contact.profile_url) + '</p>';
      }
      if (prof.recommendations && prof.recommendations.length) {
        html += '<div class="pf-section-title">Recommended CodeBlue services</div><ul class="pf-list">';
        prof.recommendations.forEach(function (r) {
          html += '<li><strong>' + escapeHtml(r.service || '') + '</strong> <span class="pf-muted">(' + escapeHtml(r.pillar || '') + ')</span> — ' + escapeHtml(r.why || '') + '</li>';
        });
        html += '</ul>';
      }
      if (prof.talking_points && prof.talking_points.length) {
        html += '<div class="pf-section-title">Conversation openers</div><ul class="pf-list">' +
          prof.talking_points.map(function (t) { return '<li>' + escapeHtml(t) + '</li>'; }).join('') + '</ul>';
      }
      if (prof.sources && prof.sources.length) {
        html += '<div class="pf-sources">Sources: ' + prof.sources.slice(0, 6).map(function (u, i) { return pfLink(String(i + 1), u); }).join(' · ') + '</div>';
      }
    } else {
      html += '<button type="button" class="pf-btn secondary" data-action="prospect-build-profile"' + (p.profileLoading ? ' disabled' : '') + '>' +
        (p.profileLoading ? 'Researching this company… (about a minute)' : 'Build full profile & recommendations') + '</button>';
      if (p.profileError) html += '<div class="error-banner">' + escapeHtml(p.profileError) + '</div>';
    }

    // ---- Editable details (fill in anything the search couldn't find) ----
    html += '<div class="pf-section-title">Contact &amp; company details</div>' +
      '<div class="pf-grid2">' +
        pfInput('contact_first_name', 'Contact first name') + pfInput('contact_last_name', 'Contact last name') +
        pfInput('contact_title', 'Title') + pfInput('contact_email', 'Contact email', 'email') +
        pfInput('contact_phone', 'Contact phone', 'tel') + pfInput('phone', 'Company phone', 'tel') +
        pfInput('website', 'Website') + pfInput('address_line1', 'Street address') +
        pfInput('city', 'City') + pfInput('state', 'State') + pfInput('zip', 'ZIP') +
      '</div>';
    var srcBits = [];
    if (safeUrl(c.contact.name_source_url)) srcBits.push(pfLink('name found here', c.contact.name_source_url));
    if (safeUrl(c.contact.email_source_url)) srcBits.push(pfLink('email found here', c.contact.email_source_url));
    if (safeUrl(c.contact.phone_source_url)) srcBits.push(pfLink('phone found here', c.contact.phone_source_url));
    if (srcBits.length) html += '<div class="pf-sources">' + srcBits.join(' · ') + '</div>';

    // ---- Claim ----
    if (c.claimed_customer_id) {
      html += '<div class="pf-claimed-box">✓ Claimed as a Prospect. ' +
        '<button type="button" class="pf-btn primary" data-action="prospect-open-customer" data-id="' + c.claimed_customer_id + '">Open in Relationships →</button></div>';
      if (p.claimResult && p.claimResult.id === c.id && p.claimResult.warnings.length) {
        html += '<div class="pf-note">Note: ' + p.claimResult.warnings.map(escapeHtml).join(' ') + '</div>';
      }
    } else {
      html += '<div class="pf-claim">' +
        '<p class="pf-note">Claiming creates this company in ConnectWise as a <strong>Prospect</strong> in your territory with this contact, assigned to you, and starts your 90-day clock. Every service starts unworked.</p>' +
        '<button type="button" class="pf-btn primary" data-action="prospect-claim"' + (p.claiming || p.saving ? ' disabled' : '') + '>' +
          (p.claiming ? 'Adding to ConnectWise…' : (p.saving ? 'Saving…' : 'Claim as Prospect')) + '</button>' +
        '<button type="button" class="pf-btn secondary" data-action="prospect-save"' + (p.claiming || p.saving ? ' disabled' : '') + '>Save details</button>' +
      '</div>';
      if (p.claimError) html += '<div class="error-banner">' + escapeHtml(p.claimError) + '</div>';
    }
    html += '</div>';
    return html;
  }

  function prospectSearchFormHtml() {
    var p = state.prospecting;
    var industries = ['Any'].concat(p.industries || []);
    var opts = industries.map(function (i) {
      return '<option value="' + escapeHtml(i) + '"' + (p.form.industry === i ? ' selected' : '') + '>' + escapeHtml(i === 'Any' ? 'Any target industry' : i) + '</option>';
    }).join('');
    var html = '<div class="pf-search">' +
      '<label class="pf-field"><span>Industry</span><select data-pf="form.industry"' + (p.searching ? ' disabled' : '') + '>' + opts + '</select></label>' +
      '<label class="pf-field"><span>Location</span><input type="text" data-pf="form.location" value="' + escapeHtml(p.form.location) + '" placeholder="City or ZIP"' + (p.searching ? ' disabled' : '') + '></label>' +
      '<label class="pf-field pf-field-sm"><span>Radius (mi)</span><input type="number" min="10" max="150" data-pf="form.radius" value="' + escapeHtml(p.form.radius) + '"' + (p.searching ? ' disabled' : '') + '></label>' +
      '<button type="button" class="pf-btn primary pf-go" data-action="prospect-run"' + (p.searching || !p.configured || p.remainingToday === 0 ? ' disabled' : '') + '>' +
        (p.searching ? 'Researching… <span id="pfElapsed">0:00</span>' : 'Prospect') + '</button>' +
    '</div>';
    if (p.searching) {
      html += '<div class="pf-progress">Searching the public web for target-market businesses, checking for duplicates and locating them. This usually takes 1–3 minutes — you can leave this page open; results are saved either way.</div>';
    } else if (!p.configured) {
      html += '<div class="error-banner">Prospecting isn’t set up yet — the research service key (relationships/api/anthropic-config.php) hasn’t been added on the server.</div>';
    } else if (p.remainingToday != null) {
      html += '<div class="pf-hint">' + p.remainingToday + ' of ' + p.dailyCap + ' Prospect searches left today. Searches use only public web information — emails and phones are shown only when found on a public page, and can be edited before you claim.</div>';
    }
    return html;
  }

  function prospectResultsHtml() {
    var p = state.prospecting;
    var html = '';
    if (!p.search) {
      return '<div class="empty-state">Pick an industry and location, then press <strong>Prospect</strong> to find businesses in CodeBlue’s target market.</div>';
    }
    var list = filteredProspects();
    var industries = ['all'];
    p.candidates.forEach(function (c) { if (c.industry && industries.indexOf(c.industry) === -1) industries.push(c.industry); });
    html += '<div class="pf-filters">' +
      '<label class="pf-field pf-field-sm"><span>Confidence</span><select data-pf="filter.tier">' +
        '<option value="all"' + (p.filterTier === 'all' ? ' selected' : '') + '>All</option>' +
        '<option value="Medium"' + (p.filterTier === 'Medium' ? ' selected' : '') + '>Medium and up</option>' +
        '<option value="High"' + (p.filterTier === 'High' ? ' selected' : '') + '>High only</option></select></label>' +
      '<label class="pf-field pf-field-sm"><span>Industry</span><select data-pf="filter.industry">' +
        industries.map(function (i) { return '<option value="' + escapeHtml(i) + '"' + (p.filterIndustry === i ? ' selected' : '') + '>' + escapeHtml(i === 'all' ? 'All' : i) + '</option>'; }).join('') + '</select></label>' +
      '<label class="pf-field pf-field-sm"><span>City / ZIP</span><input type="text" data-pf="filter.location" value="' + escapeHtml(p.filterLocation || '') + '" placeholder="filter results"></label>' +
    '</div>';
    html += '<div class="pf-result-meta">' + escapeHtml(p.search.industry === 'Any' ? 'Any industry' : p.search.industry) + ' near ' + escapeHtml(p.search.location_text) +
      ' — ' + list.length + ' shown, ranked by confidence' + (p.search.created_at ? ' · ' + escapeHtml(fmtTimestamp(p.search.created_at)) : '') + '</div>';
    if (!list.length) {
      html += '<div class="empty-state">' + (p.candidates.length ? 'No results match those filters.' : 'That search didn’t turn up new businesses. Try another industry or location.') + '</div>';
    } else {
      html += '<div class="pf-cards">' + list.map(prospectCardHtml).join('') + '</div>';
    }
    if (p.skipped && p.skipped.length) {
      html += '<div class="pf-skipped">Skipped ' + p.skipped.length + ' already known: ' +
        p.skipped.map(function (s) { return escapeHtml(s.name); }).join(', ') + '</div>';
    }
    return html;
  }

  function daysLeftBadgeHtml(daysLeft) {
    var cls = daysLeft < 0 ? 'over' : (daysLeft <= 7 ? 'red' : (daysLeft <= 30 ? 'amber' : 'green'));
    var text = daysLeft < 0 ? (Math.abs(daysLeft) + ' days overdue') : (daysLeft + ' days left');
    return '<span class="pf-days ' + cls + '">' + escapeHtml(text) + '</span>';
  }

  function prospectClaimsHtml() {
    var p = state.prospecting;
    if (p.claimsLoading && !p.claims) return '<div class="loading">Loading…</div>';
    if (!p.claims) return '';
    var rows = p.claims.filter(function (c) { return p.claimsScope === 'all' || c.is_mine; });
    var html = '<div class="pf-filters"><label class="pf-field pf-field-sm"><span>Show</span><select data-pf="claimsScope">' +
      '<option value="mine"' + (p.claimsScope === 'mine' ? ' selected' : '') + '>My prospects</option>' +
      '<option value="all"' + (p.claimsScope === 'all' ? ' selected' : '') + '>Everyone’s</option></select></label></div>';
    if (!rows.length) {
      return html + '<div class="empty-state">No claimed prospects yet. Find one on the Find Prospects tab.</div>';
    }
    html += '<div class="pf-claims">';
    rows.forEach(function (c) {
      html += '<div class="pf-claim-row" data-action="prospect-open-customer" data-id="' + c.customer_id + '">' +
        '<div class="pf-claim-name">' + escapeHtml(c.name) + '<div class="pf-card-meta">' + escapeHtml([c.city, c.state].filter(Boolean).join(', ')) +
          ' · claimed by ' + escapeHtml(c.claimed_by_name) + ' · ' + escapeHtml(fmtTimestamp(c.claimed_at)) + '</div></div>' +
        daysLeftBadgeHtml(c.days_left) +
      '</div>';
    });
    html += '</div>';
    return html;
  }

  function prospectingHtml() {
    var p = state.prospecting;
    var html = '<div class="view-header"><div class="view-title">Prospecting</div>' +
      '<div class="view-sub">Find target-market businesses within 150 miles of Richmond, review a quick profile, and claim them as ConnectWise Prospects. You have 90 days to move each one forward.</div></div>';
    html += '<div class="pf-tabs">' +
      '<button type="button" class="pf-tab' + (p.tab === 'search' ? ' active' : '') + '" data-action="prospect-tab" data-tab="search">Find Prospects</button>' +
      '<button type="button" class="pf-tab' + (p.tab === 'mine' ? ' active' : '') + '" data-action="prospect-tab" data-tab="mine">My Prospects</button>' +
    '</div>';
    if (p.error) html += '<div class="error-banner">' + escapeHtml(p.error) + '</div>';
    if (p.loading && !p.loaded) return html + '<div class="loading">Loading…</div>';

    if (p.tab === 'mine') return html + prospectClaimsHtml();

    html += prospectSearchFormHtml();
    var selected = p.selectedId != null ? findProspect(p.selectedId) : null;
    html += '<div class="pf-layout' + (selected ? ' has-detail' : '') + '">' +
      '<div class="pf-results">' + prospectResultsHtml() + '</div>' +
      (selected ? '<div class="pf-detail-col">' + prospectDetailHtml(selected) + '</div>' : '') +
    '</div>';
    return html;
  }

  // Delegated input/change handling for the Prospecting view. Fields carry
  // data-pf="<group>.<field>". Typing only updates state (no re-render, so
  // focus isn't lost); selects re-render since they change what's shown.
  function onProspectInput(ev) {
    var el = ev.target;
    var key = el.getAttribute && el.getAttribute('data-pf');
    if (!key) return;
    var p = state.prospecting;
    var isSelect = el.tagName === 'SELECT';
    if (ev.type === 'input' && isSelect) return;
    if (ev.type === 'change' && !isSelect) return;
    var val = el.value;
    if (key.indexOf('draft.') === 0) {
      if (!p.draft) p.draft = {};
      p.draft[key.slice(6)] = val;
    } else if (key.indexOf('form.') === 0) {
      p.form[key.slice(5)] = val;
    } else if (key === 'filter.tier') {
      p.filterTier = val;
    } else if (key === 'filter.industry') {
      p.filterIndustry = val;
    } else if (key === 'filter.location') {
      p.filterLocation = val;
      return; // re-rendered on blur/enter via change below
    } else if (key === 'claimsScope') {
      p.claimsScope = val;
    }
    if (isSelect) render();
  }

  function onProspectFilterCommit(ev) {
    var el = ev.target;
    if (el.getAttribute && el.getAttribute('data-pf') === 'filter.location' && ev.type === 'change') {
      render();
    }
  }

  function syncHtml() {
    var html = '<div class="view-header">' +
      '<div class="view-title">ConnectWise Sync</div>' +
      '<div class="view-sub">Pulls active services from ConnectWise — IT Services, Voice, Premise Security, and Data Center agreements — into this dashboard, refreshes every customer’s Monthly Billing chart, pulls in Active/Delinquent/Special Info companies with no agreement at all as Prospects, refreshes the front page’s Service Tickets YTD/trend and Active Contacts count/trend and search-by-contact data, then tags every company with its ConnectWise Territory so rep-based customer filtering (Territory Admin) stays current. Checklist progress already recorded isn’t touched.</div>' +
    '</div>';

    html += '<div class="sync-panel">';

    var running = state.syncRunning || state.billingSyncRunning || state.prospectSyncRunning ||
      state.ticketHistorySyncRunning || state.contactsSyncRunning || state.territorySyncRunning;

    if (state.syncRunning) {
      var pct = state.syncTotal ? Math.min(100, Math.round((state.syncProcessed / state.syncTotal) * 100)) : 0;
      html += '<div class="sync-progress-label">Syncing services… ' + state.syncProcessed + ' of ' + state.syncTotal + ' agreements (' + pct + '%)</div>' +
        '<div class="sync-progress-bar"><div class="sync-progress-fill" style="width:' + pct + '%"></div></div>';
    } else if (state.billingSyncRunning) {
      var bpct = state.billingSyncTotal ? Math.min(100, Math.round((state.billingSyncProcessed / state.billingSyncTotal) * 100)) : 0;
      html += '<div class="sync-progress-label">Services synced. Syncing Monthly Billing… ' + state.billingSyncProcessed + ' of ' + state.billingSyncTotal + ' customers (' + bpct + '%)</div>' +
        '<div class="sync-progress-bar"><div class="sync-progress-fill" style="width:' + bpct + '%"></div></div>';
    } else if (state.prospectSyncRunning) {
      var ppct = state.prospectSyncTotal ? Math.min(100, Math.round((state.prospectSyncProcessed / state.prospectSyncTotal) * 100)) : 0;
      html += '<div class="sync-progress-label">Billing synced. Syncing Prospect Companies… ' + state.prospectSyncProcessed + ' of ' + state.prospectSyncTotal + ' companies (' + ppct + '%)</div>' +
        '<div class="sync-progress-bar"><div class="sync-progress-fill" style="width:' + ppct + '%"></div></div>';
    } else if (state.ticketHistorySyncRunning) {
      var thpct = state.ticketHistorySyncTotal ? Math.min(100, Math.round((state.ticketHistorySyncProcessed / state.ticketHistorySyncTotal) * 100)) : 0;
      html += '<div class="sync-progress-label">Prospects synced. Syncing Ticket History… ' + state.ticketHistorySyncProcessed + ' of ' + state.ticketHistorySyncTotal + ' customers (' + thpct + '%)</div>' +
        '<div class="sync-progress-bar"><div class="sync-progress-fill" style="width:' + thpct + '%"></div></div>';
    } else if (state.contactsSyncRunning) {
      var cpct = state.contactsSyncTotal ? Math.min(100, Math.round((state.contactsSyncProcessed / state.contactsSyncTotal) * 100)) : 0;
      html += '<div class="sync-progress-label">Ticket history synced. Syncing Contacts… ' + state.contactsSyncProcessed + ' of ' + state.contactsSyncTotal + ' customers (' + cpct + '%)</div>' +
        '<div class="sync-progress-bar"><div class="sync-progress-fill" style="width:' + cpct + '%"></div></div>';
    } else if (state.territorySyncRunning) {
      var tpct = state.territorySyncTotal ? Math.min(100, Math.round((state.territorySyncProcessed / state.territorySyncTotal) * 100)) : 0;
      html += '<div class="sync-progress-label">Contacts synced. Syncing Territories… ' + state.territorySyncProcessed + ' of ' + state.territorySyncTotal + ' companies (' + tpct + '%)</div>' +
        '<div class="sync-progress-bar"><div class="sync-progress-fill" style="width:' + tpct + '%"></div></div>';
    }

    html += '<button class="sync-run-btn" type="button" data-action="run-sync"' + (running ? ' disabled' : '') + '>' + (running ? 'Syncing…' : 'Run Sync Now') + '</button>';

    if (!running) {
      if (state.syncDone) {
        html += '<div class="sync-result">Services: ' + (state.syncTotals ? state.syncTotals.done : 0) + ' agreements synced' +
          (state.syncTotals && state.syncTotals.error ? ', ' + state.syncTotals.error + ' failed (see below)' : '') + '.</div>';
      } else if (state.syncTotals && (state.syncTotals.done || state.syncTotals.error)) {
        html += '<div class="sync-result">Services — last run: ' + state.syncTotals.done + ' synced' +
          (state.syncTotals.error ? ', ' + state.syncTotals.error + ' failed' : '') +
          (state.syncStartedAt ? ' — started ' + escapeHtml(fmtTimestamp(state.syncStartedAt)) : '') + '.</div>';
      } else if (state.syncTotals) {
        html += '<div class="sync-result">Services: no sync has been run yet.</div>';
      }

      if (state.billingSyncDone) {
        html += '<div class="sync-result">Monthly Billing: ' + (state.billingSyncTotals ? state.billingSyncTotals.done : 0) + ' customers synced' +
          (state.billingSyncTotals && state.billingSyncTotals.error ? ', ' + state.billingSyncTotals.error + ' failed (see below)' : '') + '.</div>';
      } else if (state.billingSyncTotals && (state.billingSyncTotals.done || state.billingSyncTotals.error)) {
        html += '<div class="sync-result">Monthly Billing — last run: ' + state.billingSyncTotals.done + ' synced' +
          (state.billingSyncTotals.error ? ', ' + state.billingSyncTotals.error + ' failed' : '') +
          (state.billingSyncStartedAt ? ' — started ' + escapeHtml(fmtTimestamp(state.billingSyncStartedAt)) : '') + '.</div>';
      } else if (state.billingSyncTotals) {
        html += '<div class="sync-result">Monthly Billing: no sync has been run yet.</div>';
      }

      if (state.prospectSyncDone) {
        html += '<div class="sync-result">Prospect Companies: ' + (state.prospectSyncTotals ? state.prospectSyncTotals.done : 0) + ' companies synced' +
          (state.prospectSyncTotals && state.prospectSyncTotals.error ? ', ' + state.prospectSyncTotals.error + ' failed (see below)' : '') + '.</div>';
      } else if (state.prospectSyncTotals && (state.prospectSyncTotals.done || state.prospectSyncTotals.error)) {
        html += '<div class="sync-result">Prospect Companies — last run: ' + state.prospectSyncTotals.done + ' synced' +
          (state.prospectSyncTotals.error ? ', ' + state.prospectSyncTotals.error + ' failed' : '') +
          (state.prospectSyncStartedAt ? ' — started ' + escapeHtml(fmtTimestamp(state.prospectSyncStartedAt)) : '') + '.</div>';
      } else if (state.prospectSyncTotals) {
        html += '<div class="sync-result">Prospect Companies: no sync has been run yet.</div>';
      }

      if (state.ticketHistorySyncDone) {
        html += '<div class="sync-result">Ticket History: ' + (state.ticketHistorySyncTotals ? state.ticketHistorySyncTotals.done : 0) + ' customers synced' +
          (state.ticketHistorySyncTotals && state.ticketHistorySyncTotals.error ? ', ' + state.ticketHistorySyncTotals.error + ' failed (see below)' : '') + '.</div>';
      } else if (state.ticketHistorySyncTotals && (state.ticketHistorySyncTotals.done || state.ticketHistorySyncTotals.error)) {
        html += '<div class="sync-result">Ticket History — last run: ' + state.ticketHistorySyncTotals.done + ' synced' +
          (state.ticketHistorySyncTotals.error ? ', ' + state.ticketHistorySyncTotals.error + ' failed' : '') +
          (state.ticketHistorySyncStartedAt ? ' — started ' + escapeHtml(fmtTimestamp(state.ticketHistorySyncStartedAt)) : '') + '.</div>';
      } else if (state.ticketHistorySyncTotals) {
        html += '<div class="sync-result">Ticket History: no sync has been run yet.</div>';
      }

      if (state.contactsSyncDone) {
        html += '<div class="sync-result">Contacts: ' + (state.contactsSyncTotals ? state.contactsSyncTotals.done : 0) + ' customers synced' +
          (state.contactsSyncTotals && state.contactsSyncTotals.error ? ', ' + state.contactsSyncTotals.error + ' failed (see below)' : '') + '.</div>';
      } else if (state.contactsSyncTotals && (state.contactsSyncTotals.done || state.contactsSyncTotals.error)) {
        html += '<div class="sync-result">Contacts — last run: ' + state.contactsSyncTotals.done + ' synced' +
          (state.contactsSyncTotals.error ? ', ' + state.contactsSyncTotals.error + ' failed' : '') +
          (state.contactsSyncStartedAt ? ' — started ' + escapeHtml(fmtTimestamp(state.contactsSyncStartedAt)) : '') + '.</div>';
      } else if (state.contactsSyncTotals) {
        html += '<div class="sync-result">Contacts: no sync has been run yet.</div>';
      }

      if (state.territorySyncDone) {
        html += '<div class="sync-result">Territories: ' + (state.territorySyncTotals ? state.territorySyncTotals.done : 0) + ' companies synced' +
          (state.territorySyncTotals && state.territorySyncTotals.error ? ', ' + state.territorySyncTotals.error + ' failed (see below)' : '') + '.</div>';
      } else if (state.territorySyncTotals && (state.territorySyncTotals.done || state.territorySyncTotals.error)) {
        html += '<div class="sync-result">Territories — last run: ' + state.territorySyncTotals.done + ' synced' +
          (state.territorySyncTotals.error ? ', ' + state.territorySyncTotals.error + ' failed' : '') +
          (state.territorySyncStartedAt ? ' — started ' + escapeHtml(fmtTimestamp(state.territorySyncStartedAt)) : '') + '.</div>';
      } else if (state.territorySyncTotals) {
        html += '<div class="sync-result">Territories: no sync has been run yet.</div>';
      }
    }

    if (state.syncErrors.length) {
      html += '<div class="sync-errors-title">Agreements that failed to sync (' + state.syncErrors.length + '):</div><div class="sync-errors-list">';
      state.syncErrors.forEach(function (err) {
        html += '<div class="sync-error-row"><strong>' + escapeHtml(err.company_name) + '</strong> — agreement #' + err.agreement_id + ': ' + escapeHtml(err.error) + '</div>';
      });
      html += '</div>';
    }

    if (state.billingSyncErrors.length) {
      html += '<div class="sync-errors-title">Customers whose billing failed to sync (' + state.billingSyncErrors.length + '):</div><div class="sync-errors-list">';
      state.billingSyncErrors.forEach(function (err) {
        html += '<div class="sync-error-row"><strong>' + escapeHtml(err.company_name) + '</strong>: ' + escapeHtml(err.error) + '</div>';
      });
      html += '</div>';
    }

    if (state.prospectSyncErrors.length) {
      html += '<div class="sync-errors-title">Companies that failed to sync as prospects (' + state.prospectSyncErrors.length + '):</div><div class="sync-errors-list">';
      state.prospectSyncErrors.forEach(function (err) {
        html += '<div class="sync-error-row"><strong>' + escapeHtml(err.company_name) + '</strong>: ' + escapeHtml(err.error) + '</div>';
      });
      html += '</div>';
    }

    if (state.ticketHistorySyncErrors.length) {
      html += '<div class="sync-errors-title">Customers whose ticket history failed to sync (' + state.ticketHistorySyncErrors.length + '):</div><div class="sync-errors-list">';
      state.ticketHistorySyncErrors.forEach(function (err) {
        html += '<div class="sync-error-row"><strong>' + escapeHtml(err.company_name) + '</strong>: ' + escapeHtml(err.error) + '</div>';
      });
      html += '</div>';
    }

    if (state.contactsSyncErrors.length) {
      html += '<div class="sync-errors-title">Customers whose contacts failed to sync (' + state.contactsSyncErrors.length + '):</div><div class="sync-errors-list">';
      state.contactsSyncErrors.forEach(function (err) {
        html += '<div class="sync-error-row"><strong>' + escapeHtml(err.company_name) + '</strong>: ' + escapeHtml(err.error) + '</div>';
      });
      html += '</div>';
    }

    if (state.territorySyncErrors.length) {
      html += '<div class="sync-errors-title">Companies whose territory failed to sync (' + state.territorySyncErrors.length + '):</div><div class="sync-errors-list">';
      state.territorySyncErrors.forEach(function (err) {
        html += '<div class="sync-error-row"><strong>' + escapeHtml(err.company_name) + '</strong>: ' + escapeHtml(err.error) + '</div>';
      });
      html += '</div>';
    }

    html += '</div>';
    return html;
  }

  // Territory Admin screen -- added 2026-09-16 per Michael. Nav item is
  // only shown to state.user.is_territory_admin (topbarHtml()), but this
  // view is reachable by URL/state manipulation too -- territory-admin.php
  // itself re-checks admin status server-side on every call, so there's no
  // real access to gain by forcing this view open without the flag; the
  // client-side gate is purely about not showing a confusing "Access
  // Denied" nav item to every other CRC.
  function territoryAdminHtml() {
    var html = '<div class="view-header">' +
      '<div class="view-title">Territory Admin</div>' +
      '<div class="view-sub">Restrict a CRC to only their assigned territories’ customers. A rep with no rows below sees every customer, same as before this feature existed. Territory names must match the synced ConnectWise Territory exactly — pick from the list where possible rather than typing, since a typo silently shows that rep zero customers with no error anywhere.</div>' +
    '</div>';

    if (state.territoryAdminLoading || !state.territoryAdmin) {
      return html + '<div class="loading">Loading territory assignments…</div>';
    }

    var data = state.territoryAdmin;

    html += '<div class="territory-admin-panel">';

    if (state.territoryAdminError) {
      html += '<div class="error-banner">' + escapeHtml(state.territoryAdminError) + '</div>';
    }

    // Add-assignment form.
    html += '<div class="territory-admin-add">' +
      '<input type="email" id="territoryAdminEmailInput" placeholder="rep@codebluetechnology.com" value="' + escapeHtml(state.territoryAdminAddEmail) + '" autocomplete="off">' +
      '<input type="text" id="territoryAdminTerritoryInput" placeholder="Territory name" value="' + escapeHtml(state.territoryAdminAddTerritory) + '" list="territoryAdminOptionsList" autocomplete="off">' +
      '<datalist id="territoryAdminOptionsList">' +
        data.territory_options.map(function (t) { return '<option value="' + escapeHtml(t) + '"></option>'; }).join('') +
      '</datalist>' +
      '<button type="button" class="territory-admin-add-btn" data-action="territory-admin-add"' + (state.territoryAdminSaving ? ' disabled' : '') + '>' +
        (state.territoryAdminSaving ? 'Adding…' : '+ Add') +
      '</button>' +
    '</div>';

    if (data.territory_options.length === 0) {
      html += '<div class="territory-admin-hint">No synced territory names yet — run a ConnectWise Sync first (Territories is the last stage) to populate the picker above. You can still type a name manually.</div>';
    }

    // Current assignments, grouped by email so each rep's rows sit together.
    var byEmail = {};
    var emailOrder = [];
    data.assignments.forEach(function (a) {
      if (!byEmail[a.email]) {
        byEmail[a.email] = [];
        emailOrder.push(a.email);
      }
      byEmail[a.email].push(a);
    });

    if (emailOrder.length === 0) {
      html += '<div class="territory-admin-empty">No restricted reps yet — every CRC currently sees every customer.</div>';
    } else {
      emailOrder.forEach(function (email) {
        html += '<div class="territory-admin-rep">' +
          '<div class="territory-admin-rep-email">' + escapeHtml(email) + '</div>' +
          '<div class="territory-admin-rep-territories">';
        byEmail[email].forEach(function (a) {
          html += '<div class="territory-admin-chip">' +
            '<span>' + escapeHtml(a.territory_name) + '</span>' +
            '<button type="button" class="territory-admin-remove-btn" data-action="territory-admin-remove" data-id="' + a.id + '"' +
              (state.territoryAdminRemovingId === a.id ? ' disabled' : '') + ' title="Remove">' +
              (state.territoryAdminRemovingId === a.id ? '…' : '×') +
            '</button>' +
          '</div>';
        });
        html += '</div></div>';
      });
    }

    html += '</div>';
    return html;
  }

  // Primary Relationship Dashboard front page -- added 2026-09-10, per
  // Michael's "gauges" request. Replaces the old plain empty-state div
  // whenever no customer is selected. Reads only state.overview
  // (api/dashboard.php), which loadOverview() populates; this function
  // itself never triggers a fetch, so it's safe to call from render().
  function overviewHtml() {
    if (state.overviewLoading && !state.overview) {
      return '<div class="loading">Loading dashboard…</div>';
    }
    if (state.overview) {
      return gaugesHtml(state.overview.gauges || []) + leaderboardsHtml(state.overview.leaderboards || []) +
        (state.overviewListMode === 'outgrow'
          ? outgrowStaleListHtml(state.overview.customers || [])
          : (state.overviewListMode ? customerOverviewListHtml(state.overview.customers || []) : ''));
    }
    // Never loaded (still pending) or failed to load -- either way, fall
    // back to the original guidance rather than showing nothing. A load
    // failure here doesn't block searching/selecting a customer directly.
    return (state.overviewError ? '<div class="error-banner">' + escapeHtml(state.overviewError) + '</div>' : '') +
      '<div class="empty-state">Search for a customer above to see their active CodeBlue services and what they’re missing.</div>';
  }

  // Shared trend badge -- same "▲ 12.3%" / "▼ 8.0%" / "— " markup and
  // .trend-badge.<direction> classes activityPanelHtml() already uses for
  // the Monthly Billing card, so a trend means the same thing everywhere
  // it appears. trend.percent === null (no real prior-period data yet --
  // see relationships_cw_activity_billing_series_from_totals()) renders as
  // a bare direction arrow with a "not enough history yet" title instead
  // of a misleading percentage.
  function trendBadgeHtml(trend, size) {
    if (!trend) return '';
    var icon = trend.direction === 'up' ? '▲' : (trend.direction === 'down' ? '▼' : '—');
    var pctText = trend.percent == null ? '' : (trend.percent + '%');
    var title = trend.percent == null ? ' title="Not enough synced history yet"' : '';
    var cls = 'trend-badge ' + trend.direction + (size ? ' trend-badge-' + size : '');
    return '<span class="' + cls + '"' + title + '>' + icon + (pctText ? ' ' + pctText : '') + '</span>';
  }

  // Rectangular KPI tiles under the search bar -- gauges is a flat ordered
  // array from the server (see dashboard.php's header for why), so this
  // renders whatever comes back rather than a fixed set of named fields;
  // more gauges can be added later without an app.js change.
  function gaugesHtml(gauges) {
    if (!gauges.length) return '';
    var html = '<div class="gauges-grid">';
    gauges.forEach(function (g) {
      if (g.format === 'trend') {
        html += '<div class="gauge-tile">' +
          '<div class="gauge-label">' + escapeHtml(g.label) + '</div>' +
          '<div class="gauge-value-row">' + trendBadgeHtml(g.trend, 'lg') + '</div>' +
        '</div>';
      } else if (g.key === 'total_customers' || g.key === 'total_prospects' || g.key === 'outgrow_stale') {
        // Clickable: shows/hides that group's list (hidden by default).
        var listMode = g.key === 'total_customers' ? 'customers' : (g.key === 'total_prospects' ? 'prospects' : 'outgrow');
        var listOpen = state.overviewListMode === listMode;
        html += '<button type="button" class="gauge-tile gauge-tile-clickable' + (g.key === 'outgrow_stale' ? ' gauge-tile-alert' : '') + (listOpen ? ' active' : '') + '" data-action="toggle-overview-list" data-mode="' + listMode + '" ' +
          'title="' + (listOpen ? 'Hide the list' : 'Show the list') + '">' +
          '<div class="gauge-label">' + escapeHtml(g.label) + '</div>' +
          '<div class="gauge-value">' + (g.value == null ? '—' : g.value) + '</div>' +
          '<div class="gauge-hint">' + (listOpen ? 'Hide list ▲' : 'View list ▼') + '</div>' +
        '</button>';
      } else {
        html += '<div class="gauge-tile">' +
          '<div class="gauge-label">' + escapeHtml(g.label) + '</div>' +
          '<div class="gauge-value">' + (g.value == null ? '—' : g.value) + '</div>' +
        '</div>';
      }
    });
    html += '</div>';
    return html;
  }

  // Weekly rep leaderboards -- added 2026-09-23, per Michael: "Top 3 reps
  // based on closed tasks for the week" / "Top 3 reps based on number of
  // meetings created," a gold star on whoever's #1, sized and styled like
  // the gauge tiles above ("match size of the current pills") but in
  // their own always-side-by-side pair rather than folded into the
  // auto-fill gauges grid, since these two belong together as a set. See
  // dashboard.php's relationships_leaderboard_week_start_utc() for
  // exactly what "week" means and when it resets -- nothing client-side
  // needs to know about that; this just renders whatever ranked list
  // comes back, 0-3 entries.
  function leaderboardsHtml(leaderboards) {
    if (!leaderboards || !leaderboards.length) return '';
    var html = '<div class="leaderboard-grid">';
    leaderboards.forEach(function (lb) {
      html += '<div class="gauge-tile leaderboard-tile">' +
        '<div class="gauge-label">' + escapeHtml(lb.label) + '</div>';
      var entries = lb.entries || [];
      if (entries.length === 0) {
        html += '<div class="leaderboard-empty">Nobody yet this week.</div>';
      } else {
        html += '<div class="leaderboard-list">';
        entries.forEach(function (e, i) {
          html += '<div class="leaderboard-row">' +
            (i === 0
              ? '<span class="leaderboard-star" title="This week\u2019s leader">\u2605</span>'
              : '<span class="leaderboard-rank-spacer"></span>') +
            '<span class="leaderboard-name">' + escapeHtml(e.name) + '</span>' +
            '<span class="leaderboard-count">' + e.count + '</span>' +
          '</div>';
        });
        html += '</div>';
      }
      html += '</div>';
    });
    html += '</div>';
    return html;
  }

  // A trend's signed percent for sorting purposes -- trend.percent is
  // always stored as a non-negative magnitude (see
  // relationships_cw_activity_billing_series_from_totals() in
  // connectwise-activity.php), with the sign carried separately in
  // trend.direction, so "low to high" only makes sense once direction is
  // folded back in (a 20% drop sorts below a 5% rise). No history yet
  // (percent: null) sorts as a flat 0, same as a genuinely flat trend.
  function overviewTrendSignedPercent(trend) {
    if (!trend || trend.percent == null) return 0;
    return trend.direction === 'down' ? -trend.percent : trend.percent;
  }

  function overviewSortValue(c, column) {
    switch (column) {
      case 'billing_trend': return overviewTrendSignedPercent(c.billing_trend);
      case 'ticket_count_ytd': return c.ticket_count_ytd || 0;
      case 'contact_count': return c.contact_count || 0;
      case 'name':
      default:
        return (c.name || '').toLowerCase();
    }
  }

  function sortOverviewCustomers(customers) {
    var sort = state.overviewSort || { column: 'name', direction: 'asc' };
    var dir = sort.direction === 'desc' ? -1 : 1;
    return customers.slice().sort(function (a, b) {
      var av = overviewSortValue(a, sort.column);
      var bv = overviewSortValue(b, sort.column);
      if (av < bv) return -1 * dir;
      if (av > bv) return 1 * dir;
      // Stable, readable tiebreak -- alphabetical, regardless of which
      // column is actually being sorted.
      return a.name.localeCompare(b.name);
    });
  }

  // One clickable column-header label. Clicking a new column sorts it
  // ascending (A-Z for name, low-to-high for the numeric/trend columns);
  // clicking the already-active column flips asc/desc.
  function overviewHeaderCellHtml(column, label) {
    var sort = state.overviewSort || { column: 'name', direction: 'asc' };
    var active = sort.column === column;
    var arrow = active ? (sort.direction === 'asc' ? ' ▲' : ' ▼') : '';
    return '<button class="overview-col-sort' + (active ? ' active' : '') + '" type="button" ' +
      'data-action="sort-overview" data-column="' + column + '">' + escapeHtml(label) + arrow + '</button>';
  }

  // Per-customer trend list: 6-month Agreement Billing trend, Service
  // Tickets YTD + 6-month trend, and Active Contacts + 6-month trend, all
  // from synced local data (see dashboard.php). Reuses the same
  // data-action="select-customer" the search box already uses, so tapping
  // a row opens that customer's account summary exactly like a search
  // result does -- no separate click handler needed. Sortable by any
  // column (see overviewHeaderCellHtml()/sortOverviewCustomers()); once
  // there are more than 25 rows, the list itself becomes a fixed-height
  // scroll area (see adjustOverviewListScroll(), called after every
  // render()) instead of growing the whole page -- roughly the first 25
  // stay visible without scrolling.
  // Client-side filters for the front-page customer list -- added
  // 2026-09-15 per Michael: a Prospects on/off toggle, and a "PeopleFirst
  // Only" toggle that singles out just the PeopleFirst member companies.
  // Both read the same is_prospect_only / is_peoplefirst flags the row
  // badges already use, so no API change is needed. PeopleFirst Only wins
  // when both are somehow set, since a PeopleFirst company is never also
  // a prospect.
  function filteredOverviewCustomers(customers) {
    if (state.overviewListMode === 'prospects') {
      return customers.filter(function (c) { return c.is_prospect_only; });
    }
    var customersOnly = customers.filter(function (c) { return !c.is_prospect_only; });
    if (state.overviewPeopleFirstOnly) {
      return customersOnly.filter(function (c) { return c.is_peoplefirst; });
    }
    return customersOnly;
  }

  // Toolbar of filter toggle buttons shown above the list header. Counts
  // are always computed off the *unfiltered* customers array so a hidden
  // group's count doesn't disappear along with its rows.
  function overviewFilterBarHtml(customers) {
    // Prospects have their own tile/list now, so the only filter left is
    // PeopleFirst Only -- which only makes sense in the customers list.
    if (state.overviewListMode === 'prospects') return '';
    var peopleFirstCount = customers.filter(function (c) { return c.is_peoplefirst; }).length;
    var pfOnly = !!state.overviewPeopleFirstOnly;
    return '<div class="overview-filter-bar">' +
      '<button class="overview-filter-btn peoplefirst' + (pfOnly ? ' active' : '') + '" type="button" ' +
        'data-action="toggle-overview-peoplefirst" title="Show only PeopleFirst member companies">' +
        '★ PeopleFirst Only' +
        ' <span class="overview-filter-count">' + peopleFirstCount + '</span>' +
      '</button>' +
    '</div>';
  }

  // "60+ Days Since Last OutGrow Touch" list (front-page tile, 2026-09-23,
  // per Michael): real customers (never prospects) with no published Last
  // OutGrow Touch, or one 60+ days old -- the exact set the tile counts
  // (dashboard.php's outgrow_days_since). Sortable by touch date,
  // earliest -> latest by default; never-touched customers sort as the
  // "earliest". Clicking a name opens that customer's dashboard via the
  // same select-customer action every other list uses.
  function outgrowStaleListHtml(customers) {
    var stale = customers.filter(function (c) {
      return !c.is_prospect_only && (c.outgrow_days_since == null || c.outgrow_days_since >= 60);
    });
    var dir = state.outgrowSortDir === 'desc' ? -1 : 1;
    stale.sort(function (a, b) {
      var av = a.last_outgrow_touch || '';
      var bv = b.last_outgrow_touch || '';
      if (av < bv) return -1 * dir;
      if (av > bv) return 1 * dir;
      return a.name.localeCompare(b.name);
    });
    var arrow = state.outgrowSortDir === 'desc' ? ' ▼' : ' ▲';
    var html = '<div class="overview-list-wrap">' +
      '<div class="overview-list-header">' +
        '<div class="overview-col-name"><span class="overview-col-sort">Customer</span></div>' +
        '<div class="overview-col"><button class="overview-col-sort active" type="button" data-action="sort-outgrow" title="Flip between earliest-first and latest-first">Last OutGrow Touch' + arrow + '</button></div>' +
        '<div class="overview-col"><span class="overview-col-sort">Days Since</span></div>' +
        '<div class="overview-col"><span class="overview-col-sort">Last Touched By</span></div>' +
      '</div>' +
      '<div class="overview-list">';
    if (!stale.length) {
      html += '<div class="overview-list-empty">Every customer has had an OutGrow touch in the last 60 days.</div>';
    }
    stale.forEach(function (c) {
      var badge = c.is_peoplefirst ? peopleFirstBadgeHtml() : '';
      html += '<div class="overview-row" data-action="select-customer" data-id="' + c.id + '">' +
        '<div class="overview-col-name"><span class="overview-name">' + escapeHtml(c.name) + '</span>' + badge + '</div>' +
        '<div class="overview-col">' + (c.last_outgrow_touch ? escapeHtml(fmtOutgrowDate(c.last_outgrow_touch)) : '<span class="outgrow-none">None recorded</span>') + '</div>' +
        '<div class="overview-col">' + (c.outgrow_days_since == null ? '<span class="overview-dash">—</span>' : c.outgrow_days_since + ' days') + '</div>' +
        '<div class="overview-col">' + (c.last_outgrow_touch_by ? escapeHtml(c.last_outgrow_touch_by) : '<span class="overview-dash">—</span>') + '</div>' +
      '</div>';
    });
    html += '</div></div>';
    return html;
  }

  function customerOverviewListHtml(customers) {
    if (!customers.length) return '';
    var filtered = filteredOverviewCustomers(customers);
    var sorted = sortOverviewCustomers(filtered);
    var html = '<div class="overview-list-wrap">' +
      overviewFilterBarHtml(customers) +
      '<div class="overview-list-header">' +
        '<div class="overview-col-name">' + overviewHeaderCellHtml('name', 'Customer') + '</div>' +
        '<div class="overview-col">' + overviewHeaderCellHtml('billing_trend', 'Billing Trend (6mo)') + '</div>' +
        '<div class="overview-col">' + overviewHeaderCellHtml('ticket_count_ytd', 'Tickets YTD') + '</div>' +
        '<div class="overview-col">' + overviewHeaderCellHtml('contact_count', 'Active Contacts') + '</div>' +
      '</div>' +
      '<div class="overview-list">';
    if (!sorted.length) {
      html += '<div class="overview-list-empty">' + (state.overviewListMode === 'prospects' ? 'No prospects.' : 'No customers match the current filter.') + '</div>';
    }
    sorted.forEach(function (c) {
      var badge = c.is_peoplefirst ? peopleFirstBadgeHtml() : (c.is_prospect_only ? prospectBadgeHtml() : '');
      html += '<div class="overview-row" data-action="select-customer" data-id="' + c.id + '">' +
        '<div class="overview-col-name"><span class="overview-name">' + escapeHtml(c.name) + '</span>' + badge + '</div>' +
        '<div class="overview-col">' + (trendBadgeHtml(c.billing_trend) || '<span class="overview-dash">—</span>') + '</div>' +
        '<div class="overview-col"><span class="overview-count">' + c.ticket_count_ytd + '</span>' + trendBadgeHtml(c.ticket_trend) + '</div>' +
        '<div class="overview-col"><span class="overview-count">' + c.contact_count + '</span>' + trendBadgeHtml(c.contact_trend) + '</div>' +
      '</div>';
    });
    html += '</div></div>';
    return html;
  }

  // Caps the overview list's visible height to roughly 25 rows once there
  // are more than that many, so the customer list scrolls inside its own
  // nested box instead of stretching the whole page -- measured from the
  // actual rendered row height (rather than a hardcoded pixel guess) so it
  // stays correct whether rows are single-line (desktop) or stacked
  // (narrow/mobile -- see styles.css's @media rule for .overview-row).
  // Called after every render() that might have (re)built the list.
  var OVERVIEW_VISIBLE_ROWS = 25;
  function adjustOverviewListScroll() {
    var list = document.querySelector('.overview-list');
    if (!list) return;
    var rows = list.children;
    if (rows.length <= OVERVIEW_VISIBLE_ROWS) {
      list.style.maxHeight = '';
      return;
    }
    var rowRect = rows[0].getBoundingClientRect();
    var gap = parseFloat(getComputedStyle(list).rowGap || getComputedStyle(list).gap || '0') || 0;
    var maxHeight = (rowRect.height * OVERVIEW_VISIBLE_ROWS) + (gap * (OVERVIEW_VISIBLE_ROWS - 1));
    list.style.maxHeight = Math.ceil(maxHeight) + 'px';
  }

  function searchBoxHtml() {
    var box =
      '<div class="search-box">' +
        '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="oklch(0.5 0.02 255)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex:0 0 auto;"><circle cx="11" cy="11" r="7"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>' +
        '<input id="customerSearchInput" type="text" value="' + escapeHtml(state.query) + '" placeholder="Search by company, contact name, or email…" autocomplete="off">' +
      '</div>';

    if (state.resultsOpen && state.query.trim()) {
      box += '<div class="search-results">';
      if (state.searching) {
        box += '<div class="search-empty">Searching…</div>';
      } else if (state.results.length) {
        state.results.forEach(function (c) {
          var rowClass = c.is_peoplefirst ? ' peoplefirst' : (c.is_prospect_only ? ' prospect' : '');
          // matched_contact_name (customers.php's list action, added
          // 2026-09-10) is set when this result matched via a synced
          // ConnectWise Contact's name/email rather than the company name
          // itself -- see that file's header for the local-data-only search.
          var viaHint = c.matched_contact_name ? '<span class="search-result-via">via ' + escapeHtml(c.matched_contact_name) + '</span>' : '';
          box += '<div class="search-result-row' + rowClass + '" data-action="select-customer" data-id="' + c.id + '">' +
            '<span>' + escapeHtml(c.name) + viaHint + '</span>' +
            (c.is_peoplefirst ? peopleFirstBadgeHtml() : (c.is_prospect_only ? prospectBadgeHtml() : '')) +
          '</div>';
        });
      } else {
        box += '<div class="search-empty">No customers match “' + escapeHtml(state.query) + '”.</div>';
      }
      box += '</div>';
    }

    return box;
  }

  // PeopleFirst: CodeBlue's top-tier, most-inclusive IT Services package
  // (see connectwise-sync-core.php). These customers get little to no
  // cross-sell -- they already have most everything -- so the badge is a
  // cue to schedule a quarterly risk assessment / client visit instead.
  function peopleFirstBadgeHtml() {
    return '<span class="peoplefirst-badge" title="PeopleFirst top-tier member — due a quarterly risk assessment / client visit">★ PeopleFirst</span>';
  }

  // Prospect: a real ConnectWise Company (Active/Delinquent/Special Info
  // status, not a Vendor) with no active agreement of any kind yet -- see
  // connectwise-prospect-sync-core.php. Zero existing services, so every
  // pillar is a cross-sell opportunity; the badge is the cue that this is a
  // cold/warm lead rather than an existing customer just missing a few
  // add-ons.
  function prospectBadgeHtml() {
    return '<span class="prospect-badge" title="Prospect — a ConnectWise company with no active CodeBlue services yet. Full cross-sell opportunity.">◇ Prospect</span>';
  }

  // Live ConnectWise ticket count + 6-month Agreement-invoice billing for
  // the open customer (api/activity.php). Three distinct "nothing to show
  // yet" states, deliberately NOT collapsed into one: still loading (show
  // a loading card), a mock customer with no ConnectWise id (nothing to
  // show, not an error -- render nothing), and an actual ConnectWise
  // failure (show why, rather than silently vanishing -- that silent-hide
  // was the bug reported 2026-09-10: any real error was getting treated
  // exactly like "mock customer, nothing to show" and the whole panel
  // just disappeared with no explanation).
  function activityPanelHtml(detail) {
    var summary = state.activitySummary;
    if (state.activitySummaryLoading && !summary) {
      return '<div class="activity-panel"><div class="activity-card loading-card">Loading ticket & billing activity…</div></div>';
    }
    if (!summary) {
      return '';
    }
    if (summary.available === false) {
      if (summary.error) {
        return '<div class="activity-panel"><div class="activity-card error-card">' +
          'Couldn’t load ticket/billing activity from ConnectWise: ' + escapeHtml(summary.error) +
        '</div></div>';
      }
      return ''; // mock customer -- no ConnectWise id, nothing to show, not an error
    }

    var html = '<div class="activity-panel">';

    html += '<button class="activity-card" type="button" data-action="open-tickets">' +
      '<div class="activity-card-label">Service Tickets YTD</div>' +
      '<div class="activity-card-value">' + summary.ticket_count_ytd + '</div>' +
      '<div class="activity-card-sub">Professional Services board — click to view</div>' +
    '</button>';

    // Monthly Billing is read from the nightly sync, not live (see
    // activity.php) -- billing_synced_at is null when this customer hasn't
    // been covered by a billing sync run yet, which reads as a real,
    // confirmed $0 if shown as a normal chart. Show a plain "not yet
    // synced" message instead, deliberately not a chart, so nobody mistakes
    // "hasn't synced" for "no billing."
    if (!summary.billing_synced_at) {
      html += '<div class="activity-card billing-card">' +
        '<div class="activity-card-label">Monthly Billing</div>' +
        '<div class="activity-card-sub">Not yet synced — run ConnectWise Sync to populate this customer’s billing history.</div>' +
      '</div>';
    } else {
      var billing = summary.billing;
      var isAnnual = billing.mode === 'annual';
      var maxTotal = Math.max.apply(null, billing.series.map(function (m) { return m.total; }).concat([1]));
      var trend = billing.trend;
      var trendIcon = trend.direction === 'up' ? '▲' : (trend.direction === 'down' ? '▼' : '—');
      var trendPctText = trend.percent == null ? '' : (trend.percent + '%');
      var trendSummary = trend.direction === 'flat'
        ? 'Holding steady'
        : ('Trending ' + trend.direction + (trendPctText ? ' ' + trendPctText : '') + (isAnnual ? '' : ' on average'));

      // Annual-cadence customers (added 2026-09-15, per Michael: "For
      // accounts that are only being billed annually, I want to see
      // their last 3 years of billings, just like the last 3 months for
      // normal monthly customers.") -- see billing.php's/connectwise-
      // billing-sync-core.php's cadence detection. Same bar-chart markup,
      // just keyed by `year` instead of `month` and captioned for a
      // year-over-year comparison instead of the monthly rolling average.
      html += '<div class="activity-card billing-card">' +
        '<div class="activity-card-label-row">' +
          '<div class="activity-card-label">' + (isAnnual ? 'Annual Billing' : 'Monthly Billing') + '</div>' +
          '<div class="trend-badge ' + trend.direction + '">' + trendIcon + (trendPctText ? ' ' + trendPctText : '') + '</div>' +
        '</div>' +
        '<div class="billing-chart">' +
          billing.series.map(function (m) {
            var pct = maxTotal > 0 ? Math.max(4, Math.round((m.total / maxTotal) * 100)) : 4;
            var periodAttr = isAnnual ? ('data-year="' + m.year + '"') : ('data-month="' + m.month + '"');
            var barLabel = isAnnual ? m.label : m.label.split(' ')[0];
            return '<button class="billing-bar-col" type="button" data-action="open-invoices" ' + periodAttr + ' data-label="' + escapeHtml(m.label) + '" title="' + escapeHtml(m.label) + ': ' + fmtCurrency(m.total) + '">' +
              '<div class="billing-bar-value">' + fmtCurrency(m.total) + '</div>' +
              '<div class="billing-bar-track"><div class="billing-bar-fill" style="height:' + pct + '%"></div></div>' +
              '<div class="billing-bar-label">' + escapeHtml(barLabel) + '</div>' +
            '</button>';
          }).join('') +
        '</div>' +
        '<div class="activity-card-sub">' +
          (isAnnual
            ? ('Billed annually — ' + escapeHtml(trendSummary) + ' vs. the prior year — Agreement invoices only, click a bar for detail.')
            : (escapeHtml(trendSummary) + ' vs. the prior 3 months — Agreement invoices only, click a bar for detail.')) +
          ' Synced ' + escapeHtml(fmtTimestamp(summary.billing_synced_at)) + '.</div>' +
      '</div>';
    }

    html += '</div>';
    html += activityDrilldownHtml(detail);
    return html;
  }

  function activityDrilldownBackBtn(action, label) {
    return '<button class="drilldown-back" type="button" data-action="' + action + '" aria-label="' + escapeHtml(label) + '">' +
      '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"></polyline></svg>' +
    '</button>';
  }

  function activityDrilldownHtml(detail) {
    if (!state.activityView) return '';
    var html = '<div class="activity-drilldown">';

    if (state.activityView === 'tickets') {
      html += '<div class="drilldown-header">' + activityDrilldownBackBtn('activity-close', 'Close') +
        '<div class="drilldown-title">Service Tickets YTD — ' + escapeHtml(detail.customer.name) + '</div>' +
      '</div>';
      if (state.activityTicketsLoading || !state.activityTickets) {
        html += '<div class="loading">Loading tickets…</div>';
      } else if (state.activityTickets === 'error') {
        html += '<div class="activity-error">Could not load tickets from ConnectWise.</div>';
      } else if (state.activityTickets.length === 0) {
        html += '<div class="empty-state">No Professional Services tickets so far this year.</div>';
      } else {
        html += '<div class="activity-table-wrap"><table class="activity-table"><thead><tr>' +
          '<th>Date</th><th>Ticket #</th><th>Summary</th><th>Engineer</th><th>Hours</th>' +
        '</tr></thead><tbody>';
        state.activityTickets.forEach(function (t) {
          html += '<tr><td>' + escapeHtml(fmtDate(t.date)) + '</td><td>#' + t.ticket_number + '</td>' +
            '<td>' + escapeHtml(t.summary) + '</td><td>' + escapeHtml(t.engineer) + '</td>' +
            '<td>' + (t.hours || 0) + '</td></tr>';
        });
        html += '</tbody></table></div>';
      }
    } else if (state.activityView === 'invoices') {
      var m = state.activityInvoicesPeriod || {};
      html += '<div class="drilldown-header">' + activityDrilldownBackBtn('activity-close', 'Close') +
        '<div class="drilldown-title">Agreement Invoices — ' + escapeHtml(m.label || '') + '</div>' +
      '</div>';
      if (state.activityInvoicesLoading || !state.activityInvoices) {
        html += '<div class="loading">Loading invoices…</div>';
      } else if (state.activityInvoices === 'error') {
        html += '<div class="activity-error">Could not load invoices from ConnectWise.</div>';
      } else if (state.activityInvoices.length === 0) {
        html += '<div class="empty-state">No Agreement invoices this ' + (m.type === 'year' ? 'year' : 'month') + '.</div>';
      } else {
        html += invoicesByAgreementTypeHtml(state.activityInvoices);
      }
    } else if (state.activityView === 'invoice-detail') {
      html += '<div class="drilldown-header">' + activityDrilldownBackBtn('activity-back-to-invoices', 'Back') +
        '<div class="drilldown-title">Invoice #' + escapeHtml(String(state.activityInvoiceNumber || '')) + '</div>' +
      '</div>';
      if (state.activityInvoiceDetailLoading || !state.activityInvoiceDetail) {
        html += '<div class="loading">Loading invoice…</div>';
      } else if (state.activityInvoiceDetail === 'error') {
        html += '<div class="activity-error">Could not load this invoice from ConnectWise.</div>';
      } else {
        html += invoiceDetailHtml(state.activityInvoiceDetail);
      }
    }

    html += '</div>';
    return html;
  }

  function invoicesByAgreementTypeHtml(invoices) {
    var groups = {};
    var order = [];
    invoices.forEach(function (inv) {
      var key = inv.agreement_type || 'Unknown';
      if (!groups[key]) { groups[key] = []; order.push(key); }
      groups[key].push(inv);
    });

    var html = '<div class="invoice-groups">';
    order.forEach(function (key) {
      var rows = groups[key];
      var subtotal = rows.reduce(function (sum, r) { return sum + (r.total || 0); }, 0);
      html += '<div class="invoice-group">' +
        '<div class="invoice-group-head"><span>' + escapeHtml(key) + '</span><span>' + fmtCurrency(subtotal) + '</span></div>';
      rows.forEach(function (inv) {
        html += '<div class="invoice-row" data-action="open-invoice-detail" data-invoice="' + inv.id + '" data-number="' + escapeHtml(String(inv.invoice_number)) + '">' +
          '<div class="invoice-row-main">' +
            '<div class="invoice-row-number">#' + escapeHtml(String(inv.invoice_number)) + '</div>' +
            '<div class="invoice-row-agreement">' + escapeHtml(inv.agreement_name || '—') + '</div>' +
          '</div>' +
          '<div class="invoice-row-date">' + escapeHtml(fmtDate(inv.date)) + '</div>' +
          '<div class="invoice-row-total">' + fmtCurrency(inv.total) + '</div>' +
        '</div>';
      });
      html += '</div>';
    });
    html += '</div>';
    return html;
  }

  function invoiceDetailHtml(inv) {
    var html = '<div class="invoice-detail-meta">' +
      '<div><span class="meta-label">Date</span><span>' + escapeHtml(fmtDate(inv.date)) + '</span></div>' +
      '<div><span class="meta-label">Total</span><span>' + fmtCurrency(inv.total) + '</span></div>' +
      '<div><span class="meta-label">Agreement</span><span>' + escapeHtml(inv.agreement_name || '—') + '</span></div>' +
      '<div><span class="meta-label">Agreement Type</span><span>' + escapeHtml(inv.agreement_type || '—') + '</span></div>' +
    '</div>';

    var hasHours = inv.hours_remaining !== null && inv.hours_remaining !== undefined;
    if (hasHours) {
      html += '<div class="block-time-note">Hours Remaining (Block Time): <strong>' + escapeHtml(String(inv.hours_remaining)) + '</strong></div>';
    }

    if (inv.line_items && inv.line_items.length) {
      html += '<div class="invoice-line-items">';
      inv.line_items.forEach(function (li) {
        html += '<div class="product-row"><span>' + escapeHtml(li.description) + '</span><span class="product-qty">' + (li.qty != null ? li.qty : '') + '</span></div>';
      });
      html += '</div>';
      // ConnectWise's invoice record never carries its own line items for a
      // normal Agreement invoice (confirmed 2026-09-10) -- these are the
      // agreement's current active additions instead, a close but not
      // always exact stand-in for what that specific past invoice billed.
      if (inv.line_items_source === 'agreement_additions') {
        html += '<div class="activity-card-sub">Current active additions on this agreement — not a historical snapshot of this specific invoice.</div>';
      }
    } else if (!hasHours) {
      html += '<div class="empty-state">No active additions found on this agreement.</div>';
      if (inv.raw_hour_fields && Object.keys(inv.raw_hour_fields).length) {
        html += '<div class="raw-fields-note">Possible hours-remaining fields found on the agreement (not yet confirmed): ' +
          Object.keys(inv.raw_hour_fields).map(function (k) { return escapeHtml(k) + ' = ' + escapeHtml(String(inv.raw_hour_fields[k])); }).join(', ') +
        '</div>';
      }
    }

    return html;
  }

  function customerDashboardHtml(detail) {
    var roster = missingRoster(detail);
    var html = '';

    var headerClass = detail.customer.is_peoplefirst ? ' peoplefirst' : (detail.customer.is_prospect_only ? ' prospect' : '');
    html += '<div class="customer-header' + headerClass + '">' +
      '<div class="customer-header-left">' +
        '<div class="customer-name">' + escapeHtml(detail.customer.name) + '</div>' +
        (detail.customer.is_peoplefirst ? peopleFirstBadgeHtml() : (detail.customer.is_prospect_only ? prospectBadgeHtml() : '')) +
      '</div>' +
      '<div class="customer-header-right">' +
        '<button class="print-summary-btn" type="button" data-action="open-print-summary">Print Service Summary</button>' +
        '<button class="change-customer-btn" type="button" data-action="change-customer">Search a different customer</button>' +
      '</div>' +
    '</div>';

    html += contactCardHtml();
    html += outgrowFieldHtml();

    if (detail.customer.is_peoplefirst) {
      html += '<div class="peoplefirst-note">PeopleFirst Support Members - Quarterly Risk Scans and Monthly Client Checkin\'s are required.</div>';
      html += peopleFirstFieldsHtml(detail.customer);
    } else if (detail.customer.is_prospect_only) {
      html += '<div class="prospect-note">Prospect — a ConnectWise company with no active CodeBlue services yet. Every pillar below is a cross-sell opportunity.</div>';
      var pclaim = detail.customer.prospect_claim;
      if (pclaim) {
        html += '<div class="prospect-claim-note">Claimed by ' + escapeHtml(pclaim.claimed_by_name) + ' on ' + escapeHtml(fmtTimestamp(pclaim.claimed_at)) +
          ' \u2014 90 days to move this account forward: ' + daysLeftBadgeHtml(pclaim.days_left) + '</div>';
      }
    }

    html += riskScansPanelHtml(detail.customer.id);

    html += activityPanelHtml(detail);

    html += '<div class="dashboard-grid">';

    html += '<div class="pillar-grid">';
    detail.pillars.forEach(function (pillar) {
      var activeCount = pillar.services.filter(function (s) { return s.active; }).length;
      var totalCount = pillar.services.length;
      var isHostedVoip = pillar.id === 'voip' && detail.customer.voip_hosted_elsewhere;
      var tileClass = isHostedVoip ? 'hosted-elsewhere' : (pillar.active ? 'active' : 'inactive');
      var statusText = isHostedVoip ? 'HOSTED PLATFORM →' : (pillar.active ? 'ACTIVE →' : 'NOT IN USE →');
      var countText = isHostedVoip
        ? 'Hosted by manufacturer — not marketed by CodeBlue'
        : activeCount + ' of ' + totalCount + ' service areas in use';
      html += '<div class="pillar-tile-wrap">' +
        vendorFieldHtml(detail.customer.id, pillar) +
        '<div class="pillar-tile ' + tileClass + '" data-action="open-pillar" data-pillar="' + pillar.id + '"' +
        (isHostedVoip ? ' title="Voice hosted directly by the manufacturer' + (detail.customer.voip_hosted_agreement_name ? ' (' + escapeHtml(detail.customer.voip_hosted_agreement_name) + ')' : '') + ' — do not market phone/VoIP services to this customer."' : '') + '>' +
          '<div>' +
            '<div class="pillar-tile-name">' + escapeHtml(pillar.name) + '</div>' +
            '<div class="pillar-tile-count">' + countText + '</div>' +
          '</div>' +
          '<div class="pillar-tile-status">' + statusText + '</div>' +
        '</div>' +
      '</div>';
    });
    html += '</div>';

    html += '<div class="right-column">';

    html += '<div class="roster-panel">' +
      '<div class="roster-title">Cross-Sell Opportunities</div>' +
      '<div class="roster-sub">Services this customer isn’t using yet. Click one to open its pillar.</div>' +
      '<div class="roster-list">';
    if (roster.length === 0) {
      html += '<div class="roster-empty">This customer is using every CodeBlue service area — nothing to cross-sell right now.</div>';
    } else {
      roster.forEach(function (item) {
        html += '<div class="roster-item" data-action="open-pillar" data-pillar="' + item.pillarId + '">' +
          '<div class="roster-item-pillar">' + escapeHtml(item.pillarName) + '</div>' +
          '<div class="roster-item-name">' + escapeHtml(item.serviceName) + '</div>' +
        '</div>';
      });
    }
    html += '</div></div>';

    // Customer Meeting Capture -- added 2026-09-15 per Michael: a
    // "Meetings" box, and a separate "Meeting To-Dos" box formatted like
    // the Cross-Sell Checklist, both stacked directly under Cross-Sell
    // Opportunities in the same right-hand column.
    html += meetingsPanelHtml(detail.customer.id);
    html += meetingTasksPanelHtml();

    html += '</div>'; // .right-column

    html += '</div>'; // .dashboard-grid

    if (state.activePillarId) {
      var pillar = detail.pillars.filter(function (p) { return p.id === state.activePillarId; })[0];
      if (pillar) {
        html += drilldownHtml(pillar, detail.customer);
      }
    }

    if (state.printSummaryOpen) {
      html += printSummaryHtml(detail);
    }

    return html;
  }

  function peopleFirstFieldsHtml(customer) {
    return '<div class="peoplefirst-fields">' +
      peopleFirstFieldHtml(customer, 'checkin', 'Last Client Checkin', customer.last_client_checkin_at, customer.last_client_checkin_by) +
      peopleFirstFieldHtml(customer, 'scan', 'Last Risk Scan', customer.last_risk_scan_at, customer.last_risk_scan_by) +
    '</div>';
  }

  function peopleFirstFieldHtml(customer, type, label, at, by) {
    var key = customer.id + '::' + type;
    var isLogging = state.pfLogging === key;
    var valueHtml = at
      ? escapeHtml(fmtTimestamp(at)) + (by ? '<div class="peoplefirst-field-by">by ' + escapeHtml(by) + '</div>' : '')
      : '<span class="peoplefirst-field-empty">Not recorded yet</span>';

    return '<div class="peoplefirst-field">' +
      '<div class="peoplefirst-field-label">' + escapeHtml(label) + '</div>' +
      '<div class="peoplefirst-field-value">' + valueHtml + '</div>' +
      '<button class="peoplefirst-log-btn" type="button" data-action="log-peoplefirst" data-customer="' + customer.id + '" data-type="' + type + '" ' +
        (isLogging ? 'disabled' : '') + '>' + (isLogging ? 'Logging…' : 'Log Today') + '</button>' +
    '</div>';
  }

  // ---- Risk scans (upload/download/review) -------------------------------
  // Added 2026-09-23 per Michael. Shown on EVERY customer's dashboard, not
  // only PeopleFirst members (AskUserQuestion, 2026-09-23) -- a scan can be
  // uploaded for any customer; it only also stamps the PeopleFirst
  // "Last Risk Scan" fields above when this customer actually is one (see
  // risk-scans.php's 'upload' action).

  function riskScansPanelHtml(customerId) {
    var html = '<div class="risk-scans-panel">';
    html += '<div class="risk-scans-panel-header">' +
      '<div class="view-title">Risk Scans</div>' +
      '<div class="risk-scan-upload-row">' +
        '<label class="risk-scan-file-label" for="riskScanFileInput">' +
          (state.riskScanDraftFile ? escapeHtml(state.riskScanDraftFile.name) : 'Choose .zip file…') +
        '</label>' +
        '<input type="file" id="riskScanFileInput" accept=".zip" class="risk-scan-file-input">' +
        '<button class="risk-scan-upload-btn" type="button" data-action="riskscan-upload" data-customer="' + customerId + '" ' +
          (!state.riskScanDraftFile || state.riskScanUploading ? 'disabled' : '') + '>' +
          (state.riskScanUploading ? 'Uploading…' : 'Upload') +
        '</button>' +
      '</div>' +
    '</div>';

    if (state.riskScansError) {
      html += '<div class="error-banner">' + escapeHtml(state.riskScansError) + '</div>';
    }

    if (state.riskScansLoading && !state.riskScans) {
      html += '<div class="loading">Loading…</div>';
    } else if (!state.riskScans || !state.riskScans.length) {
      html += '<div class="roster-empty">No risk scans uploaded yet.</div>';
    } else {
      html += '<div class="risk-scan-list">';
      state.riskScans.forEach(function (scan) {
        html += riskScanItemHtml(scan);
      });
      html += '</div>';
    }

    html += '</div>';
    return html;
  }

  // ConnectWise attachment status line (risk-scans.php's
  // relationships_risk_scan_push_to_cw()) -- every scan is also supposed
  // to land in the customer's ConnectWise Documents, so anything other
  // than 'uploaded' is called out, with a Retry for failed/never-attempted.
  function riskScanCwStatusHtml(scan) {
    var st = scan.cw_upload_status;
    if (st === 'uploaded') {
      return '<div class="risk-scan-cw-status ok">✓ Saved to ConnectWise attachments</div>';
    }
    if (st === 'skipped') {
      return '<div class="risk-scan-cw-status muted">Not attached in ConnectWise — no ConnectWise company for this customer.</div>';
    }
    var retrying = state.riskScanRetryingId === scan.id;
    var msg = st === 'failed'
      ? 'ConnectWise attachment failed' + (scan.cw_upload_error ? ': ' + escapeHtml(scan.cw_upload_error) : '.')
      : 'Not yet saved to ConnectWise attachments.';
    return '<div class="risk-scan-cw-status bad">' + msg +
      ' <button class="risk-scan-cw-retry" type="button" data-action="riskscan-retry-cw" data-scan="' + scan.id + '" ' + (retrying ? 'disabled' : '') + '>' +
        (retrying ? 'Retrying…' : 'Retry') +
      '</button></div>';
  }

  function riskScanItemHtml(scan) {
    var isReviewed = !!scan.reviewed_at;
    var isToggling = state.riskScanTogglingId === scan.id;
    return '<div class="risk-scan-item' + (isReviewed ? ' reviewed' : '') + '" data-riskscan-row="' + scan.id + '">' +
      '<div class="risk-scan-item-main">' +
        '<div class="risk-scan-item-name">' + escapeHtml(scan.original_filename) + '</div>' +
        '<div class="risk-scan-item-meta">' + fmtFileSize(scan.size_bytes) + ' · uploaded by ' + escapeHtml(scan.uploaded_by_name) + ' · ' + escapeHtml(fmtTimestamp(scan.uploaded_at)) + '</div>' +
        (isReviewed
          ? '<div class="risk-scan-item-reviewed-meta">✓ Reviewed by ' + escapeHtml(scan.reviewed_by_name) + ' — ' + escapeHtml(fmtTimestamp(scan.reviewed_at)) + '</div>'
          : '') +
        riskScanCwStatusHtml(scan) +
      '</div>' +
      '<div class="risk-scan-item-actions">' +
        '<a class="risk-scan-download-btn" href="api/risk-scans.php?action=download&id=' + scan.id + '">Download</a>' +
        '<button class="risk-scan-review-btn" type="button" data-action="' + (isReviewed ? 'riskscan-unmark-reviewed' : 'riskscan-mark-reviewed') + '" data-scan="' + scan.id + '" ' + (isToggling ? 'disabled' : '') + '>' +
          (isToggling ? '…' : (isReviewed ? 'Reopen' : 'Mark Reviewed')) +
        '</button>' +
      '</div>' +
    '</div>';
  }

  // ---- "Current vendor if not CodeBlue" (per pillar) --------------------

  function vendorFieldHtml(customerId, pillar) {
    var pillarId = pillar.id;
    var note = state.vendorNotes ? state.vendorNotes[pillarId] : null;
    var isEditing = state.vendorEditingPillarId === pillarId;

    if (isEditing) {
      return '<div class="vendor-field editing">' +
        '<input type="text" id="vendorFieldInput" class="vendor-field-input" placeholder="Vendor name" value="' + escapeHtml(state.vendorDraft) + '" maxlength="200">' +
        '<div class="vendor-field-actions">' +
          '<button type="button" class="vendor-field-btn primary" data-action="vendor-save" data-pillar="' + pillarId + '" ' + (state.vendorSaving ? 'disabled' : '') + '>' + (state.vendorSaving ? 'Saving…' : 'Save') + '</button>' +
          '<button type="button" class="vendor-field-btn secondary" data-action="vendor-edit-cancel" ' + (state.vendorSaving ? 'disabled' : '') + '>Cancel</button>' +
        '</div>' +
        (state.vendorError ? '<div class="vendor-field-error">' + escapeHtml(state.vendorError) + '</div>' : '') +
      '</div>';
    }

    var valueHtml;
    if (note) {
      valueHtml = (note.vendor_name
        ? '<span class="vendor-field-name">' + escapeHtml(note.vendor_name) + '</span>'
        : '<span class="vendor-field-empty">(cleared)</span>') +
        '<span class="vendor-field-meta">' + escapeHtml(fmtTimestamp(note.updated_at)) + ' · ' + escapeHtml(note.updated_by_name) + '</span>';
    } else {
      valueHtml = '<span class="vendor-field-empty">Not recorded</span>';
    }

    return '<div class="vendor-field">' +
      '<span class="vendor-field-label">Current Vendor (if not CodeBlue)</span>' +
      '<span class="vendor-field-value">' + valueHtml + '</span>' +
      '<button type="button" class="vendor-field-edit-btn" data-action="vendor-edit-start" data-pillar="' + pillarId + '">Edit</button>' +
    '</div>';
  }

  // ---- Customer Meeting Capture: Meetings box ----------------------------

  function meetingsPanelHtml(customerId) {
    var html = '<div class="meetings-panel">';
    html += '<div class="meetings-panel-header">' +
      '<div class="roster-title">Meetings</div>' +
      '<button type="button" class="meetings-add-btn" data-action="meeting-add-open">+ Log a Meeting</button>' +
    '</div>';

    if (state.meetingsError) {
      html += '<div class="meetings-error">' + escapeHtml(state.meetingsError) + '</div>';
    }

    if (state.meetingAddOpen) {
      html += '<div class="meeting-add-form">' +
        '<input type="text" id="meetingSubjectInput" class="meeting-form-input" placeholder="Subject (e.g. CRC Check-in 9/15/2026 - Services Review)" value="' + escapeHtml(state.meetingDraftSubject) + '" maxlength="200">' +
        '<input type="date" id="meetingDateInput" class="meeting-form-input" value="' + escapeHtml(state.meetingDraftDate) + '">' +
        '<textarea id="meetingNotesInput" class="meeting-form-textarea" placeholder="Notes from the meeting…" rows="3">' + escapeHtml(state.meetingDraftNotes) + '</textarea>' +
        '<div class="meeting-form-actions">' +
          '<button type="button" class="vendor-field-btn primary" data-action="meeting-save" data-customer="' + customerId + '" ' + (state.meetingSaving ? 'disabled' : '') + '>' + (state.meetingSaving ? 'Saving…' : 'Save Meeting') + '</button>' +
          '<button type="button" class="vendor-field-btn secondary" data-action="meeting-add-cancel" ' + (state.meetingSaving ? 'disabled' : '') + '>Cancel</button>' +
        '</div>' +
      '</div>';
    }

    if (state.meetingsLoading && !state.meetings) {
      html += '<div class="loading">Loading meetings…</div>';
    } else if (!state.meetings || state.meetings.length === 0) {
      html += '<div class="roster-empty">No meetings logged yet.</div>';
    } else {
      html += '<div class="meeting-list">';
      state.meetings.forEach(function (m) {
        html += meetingRowHtml(m);
      });
      html += '</div>';
    }

    html += '</div>';
    return html;
  }

  function meetingRowHtml(m) {
    var isOpen = state.openMeetingId === m.id;
    var openTaskCount = m.tasks.filter(function (t) { return !t.completed_at; }).length;
    var cwWarn = m.cw_push && m.cw_push.status === 'error'
      ? '<div class="meeting-cw-warn" title="' + escapeHtml(m.cw_push.error || '') + '">Didn’t sync to ConnectWise</div>'
      : '';

    var html = '<div class="meeting-row">' +
      '<div class="meeting-row-head" data-action="meeting-toggle" data-meeting="' + m.id + '">' +
        '<div class="meeting-row-main">' +
          '<div class="meeting-row-subject">' + escapeHtml(m.subject) + '</div>' +
          '<div class="meeting-row-meta">' + escapeHtml(fmtOutgrowDate(m.meeting_date)) + ' · logged by ' + escapeHtml(m.logged_by_name) +
            (openTaskCount ? ' · ' + openTaskCount + ' open task' + (openTaskCount === 1 ? '' : 's') : '') +
          '</div>' +
        '</div>' +
        '<div class="meeting-row-toggle">' + (isOpen ? '▴' : '▾') + '</div>' +
      '</div>';

    if (isOpen) {
      html += '<div class="meeting-row-body">';
      if (m.notes) {
        html += '<div class="meeting-row-notes">' + escapeHtml(m.notes).replace(/\n/g, '<br>') + '</div>';
      }
      html += cwWarn;

      if (state.taskAddOpenForMeeting === m.id) {
        html += '<div class="task-add-form">' +
          '<input type="text" id="taskDescriptionInput" class="meeting-form-input" placeholder="Task description" value="' + escapeHtml(state.taskDraftDescription) + '" maxlength="500">' +
          '<select id="taskAssigneeSelect" class="meeting-form-select">' +
            // Blank placeholder above the roster -- added 2026-09-23 per
            // Michael: "create a blank choice for to-do assignments above
            // Claire Hayden so that a rep has to choose an assignment for
            // someone." Selected whenever nothing's been picked yet
            // (state.taskDraftAssignee === ''), which is now always true
            // when this form first opens -- see task-add-open above.
            '<option value=""' + (state.taskDraftAssignee === '' ? ' selected' : '') + '>Select a rep\u2026</option>' +
            state.meetingsRoster.map(function (name) {
              return '<option value="' + escapeHtml(name) + '"' + (state.taskDraftAssignee === name ? ' selected' : '') + '>' + escapeHtml(name) + '</option>';
            }).join('') +
          '</select>' +
          '<label class="task-due-date-label">Due date (optional)' +
            '<input type="date" id="taskDueDateInput" class="meeting-form-input" value="' + escapeHtml(state.taskDraftDueDate) + '">' +
          '</label>' +
          '<div class="meeting-form-actions">' +
            '<button type="button" class="vendor-field-btn primary" data-action="task-save" data-meeting="' + m.id + '" ' + (state.taskSaving ? 'disabled' : '') + '>' + (state.taskSaving ? 'Saving…' : 'Add Task') + '</button>' +
            '<button type="button" class="vendor-field-btn secondary" data-action="task-add-cancel" ' + (state.taskSaving ? 'disabled' : '') + '>Cancel</button>' +
          '</div>' +
        '</div>';
      } else {
        html += '<button type="button" class="meetings-add-btn small" type="button" data-action="task-add-open" data-meeting="' + m.id + '">+ Add Task</button>';
      }

      html += '</div>'; // .meeting-row-body
    }

    html += '</div>'; // .meeting-row
    return html;
  }

  // Same visual formatting as the 7-step Cross-Sell Checklist
  // (.checklist-step / checklistHtml() above) -- per Michael: "It should
  // follow the same formatting as the Check-list items."
  function meetingTaskItemHtml(t) {
    var isDone = !!t.completed_at;
    var toggling = state.taskTogglingId === t.id;
    var metaLine = isDone
      ? '✓ ' + escapeHtml(t.completed_by_name) + ' — ' + escapeHtml(fmtTimestamp(t.completed_at))
      : 'Assigned to ' + escapeHtml(t.assigned_to_name) + (t.due_date ? ' · Due ' + escapeHtml(fmtOutgrowDate(t.due_date)) : '');
    var cwWarn = t.cw_push && t.cw_push.status === 'error'
      ? ' <span class="meeting-task-cw-warn" title="' + escapeHtml(t.cw_push.error || '') + '">⚠</span>'
      : '';
    // "We have a next step!" notification email (added 2026-09-16) --
    // same inline-warning treatment as the ConnectWise push above, so a
    // failed send to the assigned rep isn't silently lost on the CRC's
    // screen either.
    var emailWarn = t.email && t.email.status === 'failed'
      ? ' <span class="meeting-task-email-warn" title="' + escapeHtml('Didn’t email ' + (t.assigned_to_name || '') + (t.email.error ? ': ' + t.email.error : '')) + '">✉⚠</span>'
      : '';
    // Close-on-done attempt (added 2026-09-17) -- same inline-warning
    // treatment as the create-time cwWarn above, so a failed close isn't
    // silently lost on the CRC's screen either.
    var cwCloseWarn = t.cw_close && t.cw_close.status === 'error'
      ? ' <span class="meeting-task-cw-warn" title="' + escapeHtml('Didn’t close in ConnectWise: ' + (t.cw_close.error || '')) + '">⚠</span>'
      : '';

    return '<label class="checklist-step ' + (isDone ? 'done' : '') + '" data-task-row="' + t.id + '">' +
      '<input type="checkbox" ' + (isDone ? 'checked' : '') + (toggling ? ' disabled' : '') +
        ' data-action="task-toggle-done" data-task="' + t.id + '" data-completed="' + (isDone ? '1' : '0') + '">' +
      '<div class="checklist-step-text">' +
        '<div class="checklist-step-label">' + escapeHtml(t.description) + cwWarn + emailWarn + cwCloseWarn + '</div>' +
        '<div class="checklist-step-meta">' + metaLine + '</div>' +
      '</div>' +
    '</label>';
  }

  // The separate box "under the Cross-Sell Opportunities box" Michael
  // asked for -- every task from every one of this customer's meetings,
  // flattened into one checklist-styled list (open first).
  function meetingTasksPanelHtml() {
    var html = '<div class="meeting-tasks-panel">';
    html += '<div class="roster-title">Meeting To-Dos</div>';
    html += '<div class="roster-sub">Tasks from this customer’s meetings. Check one off when it’s done.</div>';

    var allTasks = [];
    (state.meetings || []).forEach(function (m) {
      m.tasks.forEach(function (t) { allTasks.push({ task: t, meetingSubject: m.subject }); });
    });
    allTasks.sort(function (a, b) {
      var aDone = a.task.completed_at ? 1 : 0;
      var bDone = b.task.completed_at ? 1 : 0;
      return aDone - bDone;
    });

    if (state.meetingsLoading && !state.meetings) {
      html += '<div class="loading">Loading…</div>';
    } else if (allTasks.length === 0) {
      html += '<div class="roster-empty">No tasks yet — add one from a logged meeting above.</div>';
    } else {
      html += '<div class="meeting-task-list">';
      allTasks.forEach(function (item) {
        html += '<div class="meeting-task-with-context">' +
          meetingTaskItemHtml(item.task) +
          '<div class="meeting-task-context">from “' + escapeHtml(item.meetingSubject) + '”</div>' +
        '</div>';
      });
      html += '</div>';
    }

    html += '</div>';
    return html;
  }

  // ---- Global master to-do dashboard (Relationships front page) ---------

  function globalTodosPanelHtml() {
    var html = '<div class="global-todo-panel">';
    html += '<div class="view-header">' +
      '<div class="view-title">Global To-Do Checklist</div>' +
      '<div class="view-sub">Open tasks from every customer’s meetings. Click one to open that company and complete it there. Click a coordinator’s name below to see just their to-do list and calendar.</div>' +
    '</div>';

    if (state.globalTodosError) {
      html += '<div class="error-banner">' + escapeHtml(state.globalTodosError) + '</div>';
    }

    if (state.globalTodosLoading && !state.globalTodos) {
      return html + '<div class="loading">Loading…</div></div>';
    }
    if (!state.globalTodos) {
      return html + '</div>';
    }

    var g = state.globalTodos;
    html += '<div class="global-todo-summary">';
    g.roster.forEach(function (name) {
      var n = g.counts[name] || 0;
      html += '<div class="global-todo-summary-item' + (n === 0 ? ' zero' : '') + '" data-action="show-rep-todos" data-rep="' + escapeHtml(name) + '" title="See ' + escapeHtml(name) + '’s to-do list and calendar">' +
        '<span class="global-todo-summary-count">' + n + '</span>' +
        '<span class="global-todo-summary-name">' + escapeHtml(name) + '</span>' +
      '</div>';
    });
    html += '</div>';

    if (g.risk_scan_alerts && g.risk_scan_alerts.length) {
      html += '<div class="global-riskscan-section">';
      html += '<div class="global-riskscan-title">Risk Scans Awaiting Review (' + g.risk_scan_alerts.length + ')</div>';
      html += '<div class="global-todo-list">';
      g.risk_scan_alerts.forEach(function (a) {
        html += '<div class="global-todo-item riskscan-alert" data-action="open-customer-riskscan" data-customer="' + a.customer_id + '" data-scan="' + a.id + '">' +
          '<div class="global-todo-item-main">' +
            '<div class="global-todo-item-desc">' + escapeHtml(a.original_filename) + '</div>' +
            '<div class="global-todo-item-meta">' + escapeHtml(a.customer_name) + ' · uploaded by ' + escapeHtml(a.uploaded_by_name) + ' · ' + escapeHtml(fmtTimestamp(a.uploaded_at)) + '</div>' +
          '</div>' +
          '<div class="global-todo-item-go">Unassigned — Open →</div>' +
        '</div>';
      });
      html += '</div></div>';
    }

    if (g.prospect_alerts && g.prospect_alerts.length) {
      html += '<div class="global-riskscan-section">';
      html += '<div class="global-riskscan-title">Prospects Nearing 90 Days (' + g.prospect_alerts.length + ')</div>';
      html += '<div class="global-todo-list">';
      g.prospect_alerts.forEach(function (a) {
        html += '<div class="global-todo-item riskscan-alert" data-action="prospect-open-customer" data-id="' + a.customer_id + '">' +
          '<div class="global-todo-item-main">' +
            '<div class="global-todo-item-desc">' + escapeHtml(a.customer_name) + '</div>' +
            '<div class="global-todo-item-meta">Claimed by ' + escapeHtml(a.claimed_by_name) + '</div>' +
          '</div>' +
          '<div class="global-todo-item-go">' + daysLeftBadgeHtml(a.days_left) + '</div>' +
        '</div>';
      });
      html += '</div></div>';
    }

    if (g.tasks.length === 0) {
      html += '<div class="roster-empty">No meeting tasks yet.</div>';
    } else {
      html += '<div class="global-todo-list">';
      g.tasks.forEach(function (t) {
        var isDone = !!t.completed_at;
        html += '<div class="global-todo-item' + (isDone ? ' done' : '') + '" data-action="open-customer-task" data-customer="' + t.customer_id + '" data-meeting="' + t.meeting_id + '" data-task="' + t.id + '">' +
          '<div class="global-todo-item-main">' +
            '<div class="global-todo-item-desc">' + escapeHtml(t.description) + '</div>' +
            '<div class="global-todo-item-meta">' + escapeHtml(t.customer_name) + ' · “' + escapeHtml(t.meeting_subject) + '” · ' + escapeHtml(t.assigned_to_name) + '</div>' +
          '</div>' +
          (isDone
            ? '<div class="global-todo-item-done-meta">✓ ' + escapeHtml(t.completed_by_name) + ' — ' + escapeHtml(fmtTimestamp(t.completed_at)) + '</div>'
            : '<div class="global-todo-item-go">Open →</div>') +
        '</div>';
      });
      html += '</div>';
    }

    html += '</div>';
    return html;
  }

  // ---- Per-coordinator to-do view (state.view === 'rep-todos') ----------
  // Added 2026-09-16 per Michael. Reached by clicking a name in the Global
  // To-Do Checklist above (data-action="show-rep-todos"). Two columns: a
  // month calendar of this person's scheduled to-dos on the left, and
  // their unscheduled + recently-completed to-dos as plain lists on the
  // right -- same click-through-to-the-customer pattern as the global
  // panel (data-action="open-customer-task"), not an inline checkbox.

  function repTodosHtml() {
    var name = state.repTodosName || '';
    var html = '<div class="view-header">' +
      '<button class="back-link" type="button" data-action="rep-todos-back">← Back to Dashboard</button>' +
      '<div class="view-title">' + escapeHtml(name) + '’s To-Dos</div>' +
      '<div class="view-sub">Open and recently completed meeting to-dos assigned to ' + escapeHtml(name) + '. Click one to open that customer and complete it there.</div>' +
    '</div>';

    if (state.repTodosError) {
      html += '<div class="error-banner">' + escapeHtml(state.repTodosError) + '</div>';
    }

    if (state.repTodosLoading && !state.repTodosData) {
      return html + '<div class="loading">Loading…</div>';
    }
    if (!state.repTodosData) {
      return html;
    }

    var d = state.repTodosData;
    var openTasks = d.open_tasks || [];
    var recentCompleted = d.recent_completed_tasks || [];
    var openWithDate = openTasks.filter(function (t) { return !!t.due_date; });
    var openNoDate = openTasks.filter(function (t) { return !t.due_date; });

    html += '<div class="rep-todos-layout">';
    html += '<div class="rep-todos-calendar-col">' + repTodosCalendarHtml(openWithDate, recentCompleted) + '</div>';
    html += '<div class="rep-todos-list-col">' +
      repTodosListSectionHtml('Unscheduled', openNoDate, 'No unscheduled to-dos — everything open has a due date.') +
      repTodosListSectionHtml('Recently completed', recentCompleted, 'Nothing completed yet.') +
    '</div>';
    html += '</div>';

    return html;
  }

  function repTodosListSectionHtml(title, tasks, emptyMessage) {
    var html = '<div class="rep-todos-section">';
    html += '<div class="roster-title">' + escapeHtml(title) + '</div>';
    if (!tasks || tasks.length === 0) {
      html += '<div class="roster-empty">' + escapeHtml(emptyMessage) + '</div>';
    } else {
      html += '<div class="global-todo-list">';
      tasks.forEach(function (t) { html += repTodoItemHtml(t); });
      html += '</div>';
    }
    html += '</div>';
    return html;
  }

  // Same markup/behavior as a global-todo-item (click -> open that
  // customer's task) with a due-date badge appended when the task has one.
  function repTodoItemHtml(t) {
    var isDone = !!t.completed_at;
    var dueBadge = t.due_date
      ? ' <span class="rep-todo-item-due">Due ' + escapeHtml(fmtOutgrowDate(t.due_date)) + '</span>'
      : '';
    return '<div class="global-todo-item' + (isDone ? ' done' : '') + '" data-action="open-customer-task" data-customer="' + t.customer_id + '" data-meeting="' + t.meeting_id + '" data-task="' + t.id + '">' +
      '<div class="global-todo-item-main">' +
        '<div class="global-todo-item-desc">' + escapeHtml(t.description) + dueBadge + '</div>' +
        '<div class="global-todo-item-meta">' + escapeHtml(t.customer_name) + ' · “' + escapeHtml(t.meeting_subject) + '”</div>' +
      '</div>' +
      (isDone
        ? '<div class="global-todo-item-done-meta">✓ ' + escapeHtml(t.completed_by_name) + ' — ' + escapeHtml(fmtTimestamp(t.completed_at)) + '</div>'
        : '<div class="global-todo-item-go">Open →</div>') +
    '</div>';
  }

  // Renders a standard month grid (Sun-Sat) for state.repTodosCalYear /
  // state.repTodosCalMonth. openWithDate + recentCompleted (already
  // capped to the last 10 by the server) are grouped onto the day cells
  // they fall on; a day outside this month is left as an empty filler
  // cell so the grid always lands on whole weeks.
  function repTodosCalendarHtml(openWithDate, recentCompleted) {
    var year = state.repTodosCalYear;
    var month = state.repTodosCalMonth; // 1-12
    var monthLabel = new Date(year, month - 1, 1).toLocaleDateString(undefined, { month: 'long', year: 'numeric' });

    var tasksByDate = {};
    openWithDate.forEach(function (t) {
      (tasksByDate[t.due_date] = tasksByDate[t.due_date] || []).push(t);
    });
    recentCompleted.forEach(function (t) {
      if (!t.due_date) return;
      (tasksByDate[t.due_date] = tasksByDate[t.due_date] || []).push(t);
    });

    var daysInMonth = new Date(year, month, 0).getDate();
    var startWeekday = new Date(year, month - 1, 1).getDay(); // 0 = Sunday

    var html = '<div class="rep-todos-calendar">';
    html += '<div class="rep-todos-cal-header">' +
      '<button class="rep-todos-cal-nav" type="button" data-action="rep-todos-prev-month" aria-label="Previous month">‹</button>' +
      '<div class="rep-todos-cal-month">' + escapeHtml(monthLabel) + '</div>' +
      '<button class="rep-todos-cal-nav" type="button" data-action="rep-todos-next-month" aria-label="Next month">›</button>' +
    '</div>';

    html += '<div class="rep-todos-cal-grid rep-todos-cal-daylabels">';
    ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].forEach(function (label) {
      html += '<div class="rep-todos-cal-daylabel">' + label + '</div>';
    });
    html += '</div>';

    html += '<div class="rep-todos-cal-grid">';
    for (var lead = 0; lead < startWeekday; lead++) {
      html += '<div class="rep-todos-cal-cell empty"></div>';
    }
    for (var day = 1; day <= daysInMonth; day++) {
      var ymd = year + '-' + rtPad2(month) + '-' + rtPad2(day);
      var dayTasks = tasksByDate[ymd] || [];
      html += '<div class="rep-todos-cal-cell">' +
        '<div class="rep-todos-cal-cell-date">' + day + '</div>';
      dayTasks.forEach(function (t) {
        var isDone = !!t.completed_at;
        html += '<div class="rep-todos-cal-task' + (isDone ? ' done' : '') + '" data-action="open-customer-task" data-customer="' + t.customer_id + '" data-meeting="' + t.meeting_id + '" data-task="' + t.id + '" title="' + escapeHtml(t.customer_name + ': ' + t.description) + '">' +
          escapeHtml(t.description) +
        '</div>';
      });
      html += '</div>';
    }
    var totalCells = startWeekday + daysInMonth;
    var trailing = (7 - (totalCells % 7)) % 7;
    for (var trail = 0; trail < trailing; trail++) {
      html += '<div class="rep-todos-cal-cell empty"></div>';
    }
    html += '</div>';

    html += '</div>';
    return html;
  }

  function drilldownHtml(pillar, customer) {
    var customerId = customer.id;
    var html = '<div class="drilldown">' +
      '<div class="drilldown-header">' +
        '<button class="drilldown-back" type="button" data-action="close-drilldown" aria-label="Close">' +
          '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"></polyline></svg>' +
        '</button>' +
        '<div class="drilldown-title">' + escapeHtml(pillar.name) + '</div>' +
      '</div>';

    if (pillar.id === 'voip' && customer.voip_hosted_elsewhere) {
      html += '<div class="hosted-elsewhere-note">' +
        'This customer’s voice is hosted directly by the manufacturer' +
        (customer.voip_hosted_agreement_name ? ' (per ConnectWise: “' + escapeHtml(customer.voip_hosted_agreement_name) + '”)' : '') +
        ' — CodeBlue doesn’t sell or market phone/VoIP services here.' +
      '</div>';
    }

    pillar.services.forEach(function (svc) {
      if (svc.active) {
        html += '<div class="service-block active">' +
          '<div class="service-block-head">' +
            '<div class="service-name">' + escapeHtml(svc.name) + '</div>' +
            '<div class="service-badge active">ACTIVE</div>' +
          '</div>' +
          '<div class="product-list">' +
            svc.products.map(function (p) {
              return '<div class="product-row"><span>' + escapeHtml(p.label) + '</span><span class="product-qty">' + fmtQty(p) + '</span></div>';
            }).join('') +
          '</div>' +
        '</div>';
      } else {
        var hubUrl = HUB_URL + '?pillar=' + encodeURIComponent(pillar.id) + '&service=' + encodeURIComponent(svc.id);
        // Every missing service can still be opened in Solutions Hub (that's
        // just navigation) — but the marketing link and checklist are only
        // for the services CodeBlue actually cross-sells blanket-style.
        // Everything else needs a rep to spot an actual need first.
        html += '<div class="service-block inactive">' +
          '<div class="service-block-head">' +
            '<div class="service-name">' + escapeHtml(svc.name) + '</div>' +
            '<div class="service-badge inactive">NOT IN USE</div>' +
          '</div>' +
          '<div class="service-actions">' +
            '<a class="svc-action-btn primary" href="' + hubUrl + '" target="_blank" rel="noopener">Open in Solutions Hub →</a>' +
            (svc.cross_sell_eligible
              ? '<a class="svc-action-btn secondary" href="' + MARKETING_LIBRARY_URL + '" target="_blank" rel="noopener">View Marketing ↗</a>'
              : '') +
          '</div>' +
          (svc.cross_sell_eligible ? checklistHtml(customerId, pillar, svc) : '') +
        '</div>';
      }
    });

    html += '</div>';
    return html;
  }

  function checklistHtml(customerId, pillar, svc) {
    var key = customerId + '::' + pillar.id + '::' + svc.id;
    var isOpen = state.openChecklistKey === key;

    var html = '<div class="checklist-toggle-row">' +
      '<button class="checklist-toggle-btn" type="button" data-action="toggle-checklist" data-pillar="' + pillar.id + '" data-service="' + svc.id + '">' +
        (isOpen ? 'Hide Cross-Sell Checklist ▴' : 'Cross-Sell Checklist ▾') +
      '</button>' +
    '</div>';

    if (!isOpen) return html;

    var data = state.checklists[key];

    html += '<div class="checklist-panel" data-checklist-key="' + key + '">';

    if (data === 'error') {
      html += '<div class="checklist-error">Could not load the checklist — try again.</div>' + '</div>';
      return html;
    }
    if (!data) {
      html += '<div class="checklist-loading">Loading checklist…</div>' + '</div>';
      return html;
    }

    if (data.killed) {
      html += '<div class="checklist-killed-note">' +
        'Marked not interested — excluded from the Cross-Sell Report.' +
        '<button type="button" class="checklist-inline-link" data-action="checklist-unkill" data-customer="' + customerId + '" data-pillar="' + pillar.id + '" data-service="' + svc.id + '"' +
          (state.checklistCloseoutSaving === key ? ' disabled' : '') + '>Restore opportunity</button>' +
      '</div>';
    }

    // Contact selection -- drives the Email/Call actions on the steps
    // below. Reuses state.contactCard.contacts (already loaded for the
    // OutGrow card, same completeness filtering) rather than a second
    // fetch -- per Michael: "add a contact selection drop down... actions
    // below that step will use the contact selected."
    var contacts = (state.contactCard && state.contactCard.contacts) || [];
    var selectedContactId = state.checklistContactSelected[key] || null;
    var selectedContact = null;
    for (var sci = 0; sci < contacts.length; sci++) {
      if (contacts[sci].id === selectedContactId) { selectedContact = contacts[sci]; break; }
    }
    var contactDropdownOpen = state.openChecklistContactDropdownKey === key;

    if (contacts.length) {
      html += '<div class="checklist-contact-row">' +
        '<div class="contact-card-dropdown checklist-contact-dropdown">' +
          '<button type="button" class="contact-card-dropdown-toggle" data-action="checklist-contact-dropdown-toggle" data-checklist-key="' + key + '" aria-expanded="' + (contactDropdownOpen ? 'true' : 'false') + '">' +
            '<span>' + (selectedContact ? escapeHtml(selectedContact.name || 'Contact') : 'Select a contact for outreach…') + '</span>' +
            '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="contact-card-dropdown-chevron"><polyline points="6 9 12 15 18 9"></polyline></svg>' +
          '</button>';
      if (contactDropdownOpen) {
        html += '<div class="contact-card-dropdown-panel">';
        contacts.forEach(function (c) {
          var rowClass = 'contact-card-dropdown-row' + (selectedContact && c.id === selectedContact.id ? ' selected' : '');
          html += '<div class="' + rowClass + '" data-action="checklist-contact-select" data-checklist-key="' + key + '" data-contact-id="' + escapeHtml(c.id) + '">' +
            '<div class="contact-card-dropdown-name">' + escapeHtml(c.name || 'Contact') + '</div>' +
            '<div class="contact-card-dropdown-meta">' + escapeHtml(c.email) + ' · ' + escapeHtml(c.phone) + '</div>' +
          '</div>';
        });
        html += '</div>';
      }
      html += '</div>' +
        '<div class="checklist-contact-hint">' + (selectedContact ? 'Email and call steps below will use this contact.' : 'Pick a contact to enable the Email/Call actions below.') + '</div>' +
      '</div>';
    }

    // Notes toggle + the two-column layout it opens (steps on the right,
    // a scrollable notes feed on the left) -- per Michael: "click (open
    // notes) and have the notes appear in a box to the left of the
    // outreach steps that you can scroll through."
    var notesOpen = state.openChecklistNotesKey === key;
    var notes = state.checklistNotes[key];
    html += '<div class="checklist-notes-toggle-row">' +
      '<button type="button" class="checklist-notes-toggle" data-action="checklist-notes-toggle" data-customer="' + customerId + '" data-pillar="' + pillar.id + '" data-service="' + svc.id + '">' +
        (notesOpen ? 'Hide Notes ▴' : 'Open Notes ▾') + (notes && notes !== 'error' && notes.length ? ' (' + notes.length + ')' : '') +
      '</button>' +
    '</div>';

    html += '<div class="checklist-notes-layout' + (notesOpen ? ' notes-open' : '') + '">';

    if (notesOpen) {
      html += '<div class="checklist-notes-panel">';
      if (notes === 'error') {
        html += '<div class="checklist-error">Could not load notes — try again.</div>';
      } else if (!notes) {
        html += '<div class="checklist-loading">Loading notes…</div>';
      } else if (!notes.length) {
        html += '<div class="checklist-notes-empty">No notes yet on this opportunity. A note added on any step — what the customer said, current services, contract renewal dates — shows up here.</div>';
      } else {
        html += '<div class="checklist-notes-scroll">' +
          notes.map(function (n) {
            return '<div class="checklist-note-item">' +
              '<div class="checklist-note-item-step">Step ' + n.step_number + '</div>' +
              '<div class="checklist-note-item-text">' + escapeHtml(n.note_text) + '</div>' +
              '<div class="checklist-note-item-meta">' + escapeHtml(n.created_by_name || '') + ' — ' + escapeHtml(fmtTimestamp(n.created_at)) + '</div>' +
            '</div>';
          }).join('') +
        '</div>';
      }
      html += '</div>';
    }

    html += '<div class="checklist-steps-col">';
    data.steps.forEach(function (step) {
      html += checklistStepRowHtml(customerId, pillar, svc, step, selectedContact, key);
    });
    html += '</div>'; // .checklist-steps-col

    html += '</div>'; // .checklist-notes-layout

    // Recycle / Kill Opportunity -- shown once every step is checked off,
    // per Michael's script ending in "Recycle in 180 days or Kill
    // Opportunity Button." No automatic 180-day timer -- see recycle()'s
    // own comment in checklist.php.
    var allDone = data.steps.every(function (s) { return s.completed; });
    if (allDone && !data.killed) {
      html += '<div class="checklist-closeout-row">' +
        '<div class="checklist-closeout-label">Fully worked — recycle for another pass, or close it out.</div>' +
        '<div class="checklist-closeout-actions">' +
          '<button type="button" class="svc-action-btn secondary" data-action="checklist-recycle" data-customer="' + customerId + '" data-pillar="' + pillar.id + '" data-service="' + svc.id + '"' +
            (state.checklistCloseoutSaving === key ? ' disabled' : '') + '>Recycle in 180 Days</button>' +
          '<button type="button" class="svc-action-btn danger" data-action="checklist-kill" data-customer="' + customerId + '" data-pillar="' + pillar.id + '" data-service="' + svc.id + '"' +
            (state.checklistCloseoutSaving === key ? ' disabled' : '') + '>Kill Opportunity</button>' +
        '</div>' +
      '</div>';
    }

    html += '</div>'; // .checklist-panel

    return html;
  }

  // One step row: the existing checkbox, a "+" note button, an inline
  // note-draft form when open, and (once a contact is selected) an
  // Email/Call action for the scripted odd/even steps. Split out of
  // checklistHtml() above once that function started doing much more than
  // render a plain list.
  function checklistStepRowHtml(customerId, pillar, svc, step, selectedContact, checklistKey) {
    var stepKey = checklistKey + '::' + step.step_number;
    var draftOpen = state.checklistNoteDraftOpenKey === stepKey;

    var html = '<div class="checklist-step-row">';
    html += '<label class="checklist-step ' + (step.completed ? 'done' : '') + '">' +
      '<input type="checkbox" ' + (step.completed ? 'checked' : '') +
        ' data-action="toggle-step" data-customer="' + customerId + '" data-pillar="' + pillar.id + '" data-service="' + svc.id + '"' +
        ' data-service-name="' + escapeHtml(svc.name) + '" data-step="' + step.step_number + '" data-completed="' + (step.completed ? '1' : '0') + '">' +
      '<div class="checklist-step-text">' +
        '<div class="checklist-step-label">' + step.step_number + '. ' + escapeHtml(step.label) + '</div>' +
        (step.completed
          ? '<div class="checklist-step-meta">✓ ' + escapeHtml(step.completed_by_name) + ' — ' + escapeHtml(fmtTimestamp(step.completed_at)) + '</div>'
          : '') +
      '</div>' +
    '</label>';
    html += '<button type="button" class="checklist-note-add-btn" title="Add a note on this step" data-action="checklist-note-open" data-checklist-key="' + checklistKey + '" data-step="' + step.step_number + '">+</button>';
    html += '</div>'; // .checklist-step-row

    // Odd steps (1/3/5) are the scripted marketing-email steps; even steps
    // (2/4/6) are always "Phone Call Follow-Up." Both need a contact
    // selected first; Email additionally needs a script for this
    // pillar/service (see CROSS_SELL_SCRIPTS -- only Cloud Voice System
    // has one today).
    var isEmailStep = step.step_number === 1 || step.step_number === 3 || step.step_number === 5;
    var isCallStep = step.step_number === 2 || step.step_number === 4 || step.step_number === 6;
    if (selectedContact && isEmailStep) {
      var email = crossSellEmailContent(pillar.id, svc.id, step.step_number, selectedContact);
      if (email) {
        html += '<a class="checklist-step-action" href="' + crossSellMailtoHref(selectedContact.email, email.subject, email.body) + '" data-action="checklist-email-step">' +
          'Email ' + escapeHtml(selectedContact.name || 'contact') + ' →' +
        '</a>';
      }
    } else if (selectedContact && isCallStep) {
      html += '<a class="checklist-step-action" href="tel:' + escapeHtml(selectedContact.phone) + '">' +
        'Call ' + escapeHtml(selectedContact.name || 'contact') + ' — ' + escapeHtml(selectedContact.phone) + ' →' +
      '</a>';
    }

    if (draftOpen) {
      html += '<div class="checklist-note-form">' +
        '<textarea id="checklistNoteTextarea" class="checklist-note-textarea" rows="3" placeholder="What did the customer say? Current service, contract renewal date, etc.">' + escapeHtml(state.checklistNoteDraftText) + '</textarea>' +
        '<div class="checklist-note-form-actions">' +
          '<button type="button" class="svc-action-btn primary" data-action="checklist-note-save" data-customer="' + customerId + '" data-pillar="' + pillar.id + '" data-service="' + svc.id + '" data-step="' + step.step_number + '"' +
            (state.checklistNoteSaving ? ' disabled' : '') + '>' + (state.checklistNoteSaving ? 'Saving…' : 'Save Note') + '</button>' +
          '<button type="button" class="svc-action-btn secondary" data-action="checklist-note-cancel"' + (state.checklistNoteSaving ? ' disabled' : '') + '>Cancel</button>' +
        '</div>' +
      '</div>';
    }

    return html;
  }

  // ---- Event binding ----------------------------------------------------

  function bindEvents() {
    var searchInput = document.getElementById('customerSearchInput');
    if (searchInput) {
      searchInput.addEventListener('input', function (e) {
        state.query = e.target.value;
        state.resultsOpen = true;
        runSearch(state.query);
      });
    }

    var outgrowDateInput = document.getElementById('outgrowDateInput');
    if (outgrowDateInput) {
      outgrowDateInput.addEventListener('input', function (e) {
        state.outgrowDraftDate = e.target.value;
      });
    }

    var vendorFieldInput = document.getElementById('vendorFieldInput');
    if (vendorFieldInput) {
      vendorFieldInput.addEventListener('input', function (e) {
        state.vendorDraft = e.target.value;
      });
    }

    var meetingSubjectInput = document.getElementById('meetingSubjectInput');
    if (meetingSubjectInput) {
      meetingSubjectInput.addEventListener('input', function (e) {
        state.meetingDraftSubject = e.target.value;
      });
    }
    var meetingDateInput = document.getElementById('meetingDateInput');
    if (meetingDateInput) {
      meetingDateInput.addEventListener('input', function (e) {
        state.meetingDraftDate = e.target.value;
      });
    }
    var meetingNotesInput = document.getElementById('meetingNotesInput');
    if (meetingNotesInput) {
      meetingNotesInput.addEventListener('input', function (e) {
        state.meetingDraftNotes = e.target.value;
      });
    }

    var taskDescriptionInput = document.getElementById('taskDescriptionInput');
    if (taskDescriptionInput) {
      taskDescriptionInput.addEventListener('input', function (e) {
        state.taskDraftDescription = e.target.value;
      });
    }
    var taskAssigneeSelect = document.getElementById('taskAssigneeSelect');
    if (taskAssigneeSelect) {
      taskAssigneeSelect.addEventListener('change', function (e) {
        state.taskDraftAssignee = e.target.value;
      });
    }
    var taskDueDateInput = document.getElementById('taskDueDateInput');
    if (taskDueDateInput) {
      taskDueDateInput.addEventListener('input', function (e) {
        state.taskDraftDueDate = e.target.value;
      });
    }

    var territoryAdminEmailInput = document.getElementById('territoryAdminEmailInput');
    if (territoryAdminEmailInput) {
      territoryAdminEmailInput.addEventListener('input', function (e) {
        state.territoryAdminAddEmail = e.target.value;
      });
    }
    var territoryAdminTerritoryInput = document.getElementById('territoryAdminTerritoryInput');
    if (territoryAdminTerritoryInput) {
      territoryAdminTerritoryInput.addEventListener('input', function (e) {
        state.territoryAdminAddTerritory = e.target.value;
      });
    }

    var checklistNoteTextarea = document.getElementById('checklistNoteTextarea');
    if (checklistNoteTextarea) {
      checklistNoteTextarea.addEventListener('input', function (e) {
        state.checklistNoteDraftText = e.target.value;
      });
    }

    var riskScanFileInput = document.getElementById('riskScanFileInput');
    if (riskScanFileInput) {
      riskScanFileInput.addEventListener('change', function (e) {
        state.riskScanDraftFile = (e.target.files && e.target.files[0]) || null;
        state.riskScansError = null;
        render();
      });
    }
  }

  function onRootClick(e) {
    var el = e.target.closest('[data-action]');
    if (!el) return;
    var action = el.getAttribute('data-action');

    if (action === 'select-customer') {
      selectCustomer(el.getAttribute('data-id'));
    } else if (action === 'change-customer') {
      state.selectedCustomer = null;
      state.activePillarId = null;
      state.query = '';
      state.results = [];
      state.resultsOpen = false;
      resetActivityState();
      resetOutgrowState();
      resetContactCardState();
      resetVendorState();
      resetMeetingsState();
      resetRiskScansState();
      render();
      if (!state.overview) loadOverview();
      loadGlobalTodos();
    } else if (action === 'open-pillar') {
      state.activePillarId = el.getAttribute('data-pillar');
      render();
      var dd = document.querySelector('.drilldown');
      if (dd) dd.scrollIntoView({ behavior: 'smooth', block: 'start' });
    } else if (action === 'close-drilldown') {
      state.activePillarId = null;
      render();
    } else if (action === 'show-report') {
      state.view = 'report';
      loadReport();
      loadPeopleFirstSummary();
    } else if (action === 'show-dashboard') {
      state.view = 'dashboard';
      state.error = null;
      render();
      if (!state.selectedCustomer && !state.overview) loadOverview();
      if (!state.selectedCustomer) loadGlobalTodos();
    } else if (action === 'show-prospecting') {
      state.view = 'prospecting';
      state.error = null;
      render();
      if (!state.prospecting.loaded) loadProspecting();
      if (state.prospecting.tab === 'mine' && !state.prospecting.claims) loadProspectClaims();
    } else if (action === 'prospect-run') {
      runProspectSearch();
    } else if (action === 'prospect-select') {
      selectProspect(parseInt(el.getAttribute('data-id'), 10));
    } else if (action === 'prospect-close') {
      state.prospecting.selectedId = null;
      state.prospecting.draft = null;
      render();
    } else if (action === 'prospect-build-profile') {
      buildProspectProfile();
    } else if (action === 'prospect-save') {
      saveProspectDraft();
    } else if (action === 'prospect-claim') {
      claimProspect();
    } else if (action === 'prospect-open-customer') {
      state.view = 'dashboard';
      selectCustomer(el.getAttribute('data-id'));
    } else if (action === 'prospect-tab') {
      state.prospecting.tab = el.getAttribute('data-tab') === 'mine' ? 'mine' : 'search';
      render();
      if (state.prospecting.tab === 'mine' && !state.prospecting.claims) loadProspectClaims();
    } else if (action === 'show-sync') {
      state.view = 'sync';
      state.error = null;
      render();
      loadSyncStatus();
    } else if (action === 'run-sync') {
      runFullSync();
    } else if (action === 'show-territory-admin') {
      state.view = 'territory-admin';
      state.error = null;
      render();
      loadTerritoryAdmin();
    } else if (action === 'territory-admin-add') {
      addTerritoryAssignment();
    } else if (action === 'territory-admin-remove') {
      removeTerritoryAssignment(parseInt(el.getAttribute('data-id'), 10));
    } else if (action === 'report-cell') {
      state.view = 'queue';
      loadQueue(
        el.getAttribute('data-pillar'), el.getAttribute('data-service'), el.getAttribute('data-step'),
        el.getAttribute('data-pillar-name'), el.getAttribute('data-service-name')
      );
    } else if (action === 'queue-back') {
      state.view = 'report';
      state.error = null;
      render();
    } else if (action === 'open-queue-customer') {
      var qp = state.queueParams;
      openCustomerAtChecklist(el.getAttribute('data-customer'), qp.pillarId, qp.serviceId, qp.serviceName);
    } else if (action === 'pf-open-queue') {
      state.view = 'pf-queue';
      loadPeopleFirstQueue(el.getAttribute('data-type'));
    } else if (action === 'pf-queue-back') {
      state.view = 'report';
      state.error = null;
      render();
    } else if (action === 'open-pf-queue-customer') {
      state.view = 'dashboard';
      selectCustomer(el.getAttribute('data-customer'));
    } else if (action === 'log-peoplefirst') {
      logPeopleFirst(parseInt(el.getAttribute('data-customer'), 10), el.getAttribute('data-type'));
    } else if (action === 'toggle-checklist') {
      var custId = state.selectedCustomer.customer.id;
      var checklistKey = custId + '::' + el.getAttribute('data-pillar') + '::' + el.getAttribute('data-service');
      if (state.openChecklistKey === checklistKey) {
        state.openChecklistKey = null;
        render();
      } else {
        state.openChecklistKey = checklistKey;
        render();
        if (!state.checklists[checklistKey]) {
          loadChecklist(custId, el.getAttribute('data-pillar'), el.getAttribute('data-service'));
        }
      }
    } else if (action === 'toggle-step') {
      var wasCompleted = el.getAttribute('data-completed') === '1';
      setChecklistStep(
        el.getAttribute('data-customer'), el.getAttribute('data-pillar'), el.getAttribute('data-service'),
        el.getAttribute('data-service-name'), parseInt(el.getAttribute('data-step'), 10), !wasCompleted
      );
    } else if (action === 'checklist-contact-dropdown-toggle') {
      var ccdKey = el.getAttribute('data-checklist-key');
      state.openChecklistContactDropdownKey = state.openChecklistContactDropdownKey === ccdKey ? null : ccdKey;
      render();
    } else if (action === 'checklist-contact-select') {
      var ccsKey = el.getAttribute('data-checklist-key');
      state.checklistContactSelected[ccsKey] = el.getAttribute('data-contact-id');
      state.openChecklistContactDropdownKey = null;
      render();
    } else if (action === 'checklist-notes-toggle') {
      var cnCustId = state.selectedCustomer.customer.id;
      var cnKey = cnCustId + '::' + el.getAttribute('data-pillar') + '::' + el.getAttribute('data-service');
      if (state.openChecklistNotesKey === cnKey) {
        state.openChecklistNotesKey = null;
        render();
      } else {
        state.openChecklistNotesKey = cnKey;
        render();
        if (!state.checklistNotes[cnKey]) {
          loadChecklistNotes(cnCustId, el.getAttribute('data-pillar'), el.getAttribute('data-service'));
        }
      }
    } else if (action === 'checklist-note-open') {
      state.checklistNoteDraftOpenKey = el.getAttribute('data-checklist-key') + '::' + el.getAttribute('data-step');
      state.checklistNoteDraftText = '';
      render();
      var newNoteTextarea = document.getElementById('checklistNoteTextarea');
      if (newNoteTextarea) newNoteTextarea.focus();
    } else if (action === 'checklist-note-cancel') {
      state.checklistNoteDraftOpenKey = null;
      state.checklistNoteDraftText = '';
      render();
    } else if (action === 'checklist-note-save') {
      addChecklistNote(
        el.getAttribute('data-customer'), el.getAttribute('data-pillar'), el.getAttribute('data-service'),
        parseInt(el.getAttribute('data-step'), 10)
      );
    } else if (action === 'checklist-recycle') {
      recycleChecklist(el.getAttribute('data-customer'), el.getAttribute('data-pillar'), el.getAttribute('data-service'));
    } else if (action === 'checklist-kill') {
      setChecklistKilled(el.getAttribute('data-customer'), el.getAttribute('data-pillar'), el.getAttribute('data-service'), true);
    } else if (action === 'checklist-unkill') {
      setChecklistKilled(el.getAttribute('data-customer'), el.getAttribute('data-pillar'), el.getAttribute('data-service'), false);
    } else if (action === 'open-tickets') {
      loadActivityTickets(state.selectedCustomer.customer.id);
    } else if (action === 'open-invoices') {
      var periodType = el.getAttribute('data-year') !== null ? 'year' : 'month';
      var periodValue = periodType === 'year' ? el.getAttribute('data-year') : el.getAttribute('data-month');
      loadActivityInvoices(state.selectedCustomer.customer.id, periodType, periodValue, el.getAttribute('data-label'));
    } else if (action === 'open-invoice-detail') {
      loadActivityInvoiceDetail(el.getAttribute('data-invoice'), el.getAttribute('data-number'));
    } else if (action === 'activity-close') {
      state.activityView = null;
      render();
    } else if (action === 'activity-back-to-invoices') {
      state.activityView = 'invoices';
      render();
    } else if (action === 'sort-overview') {
      var sortCol = el.getAttribute('data-column');
      if (!state.overviewSort || state.overviewSort.column !== sortCol) {
        state.overviewSort = { column: sortCol, direction: 'asc' };
      } else {
        state.overviewSort.direction = state.overviewSort.direction === 'asc' ? 'desc' : 'asc';
      }
      render();
    } else if (action === 'sort-outgrow') {
      state.outgrowSortDir = state.outgrowSortDir === 'asc' ? 'desc' : 'asc';
      render();
    } else if (action === 'toggle-overview-list') {
      var mode = el.getAttribute('data-mode');
      state.overviewListMode = state.overviewListMode === mode ? null : mode;
      render();
    } else if (action === 'toggle-overview-peoplefirst') {
      state.overviewPeopleFirstOnly = !state.overviewPeopleFirstOnly;
      render();
    } else if (action === 'open-print-summary') {
      state.printSummaryOpen = true;
      render();
      if (state.printTickets === null && !state.printTicketsLoading) {
        loadPrintTickets(state.selectedCustomer.customer.id);
      }
      var psPanel = document.querySelector('.print-summary-panel');
      if (psPanel) psPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
    } else if (action === 'close-print-summary') {
      state.printSummaryOpen = false;
      render();
    } else if (action === 'print-summary-go') {
      window.print();
    } else if (action === 'outgrow-edit-start') {
      state.outgrowEditing = true;
      state.outgrowDraftDate = (state.outgrowCurrent && state.outgrowCurrent.touch_date) || outgrowTodayYmd();
      state.outgrowError = null;
      render();
    } else if (action === 'outgrow-edit-cancel') {
      state.outgrowEditing = false;
      state.outgrowError = null;
      render();
    } else if (action === 'outgrow-save') {
      saveOutgrow(state.selectedCustomer.customer.id);
    } else if (action === 'outgrow-history-toggle') {
      state.outgrowHistoryOpen = !state.outgrowHistoryOpen;
      render();
    } else if (action === 'contact-dropdown-toggle') {
      state.contactCardOpen = !state.contactCardOpen;
      render();
    } else if (action === 'contact-select') {
      state.contactCardSelectedId = el.getAttribute('data-contact-id');
      state.contactCardOpen = false;
      // Switching contacts mid-confirm would log the touch against
      // whichever one happens to be selected when "Yes" is tapped --
      // clear any pending confirmation rather than let that happen.
      state.outgrowConfirm = null;
      render();
    } else if (action === 'contact-call') {
      // Doesn't preventDefault -- the <a href="tel:..."> still opens the
      // dialer as normal. This just surfaces the confirm banner alongside
      // it; per Michael's explicit choice, the touch is NOT logged yet.
      state.outgrowConfirm = { source: 'call' };
      render();
    } else if (action === 'contact-email') {
      state.outgrowConfirm = { source: 'email' };
      render();
    } else if (action === 'contact-confirm-yes') {
      var confirmedSource = state.outgrowConfirm ? state.outgrowConfirm.source : 'manual';
      state.outgrowConfirm = null;
      logOutgrowTouch(state.selectedCustomer.customer.id, confirmedSource);
    } else if (action === 'contact-confirm-no') {
      state.outgrowConfirm = null;
      render();
    } else if (action === 'vendor-edit-start') {
      var vPillarId = el.getAttribute('data-pillar');
      var vNote = state.vendorNotes ? state.vendorNotes[vPillarId] : null;
      state.vendorEditingPillarId = vPillarId;
      state.vendorDraft = (vNote && vNote.vendor_name) || '';
      state.vendorError = null;
      render();
    } else if (action === 'vendor-edit-cancel') {
      state.vendorEditingPillarId = null;
      state.vendorError = null;
      render();
    } else if (action === 'vendor-save') {
      saveVendorNote(state.selectedCustomer.customer.id, el.getAttribute('data-pillar'));
    } else if (action === 'meeting-add-open') {
      state.meetingAddOpen = true;
      state.meetingDraftDate = outgrowTodayYmd();
      state.meetingsError = null;
      render();
    } else if (action === 'meeting-add-cancel') {
      state.meetingAddOpen = false;
      state.meetingsError = null;
      render();
    } else if (action === 'meeting-save') {
      saveMeeting(state.selectedCustomer.customer.id);
    } else if (action === 'meeting-toggle') {
      var mId = parseInt(el.getAttribute('data-meeting'), 10);
      state.openMeetingId = state.openMeetingId === mId ? null : mId;
      state.taskAddOpenForMeeting = null;
      render();
    } else if (action === 'task-add-open') {
      state.taskAddOpenForMeeting = parseInt(el.getAttribute('data-meeting'), 10);
      state.taskDraftDescription = '';
      // Blank, not state.meetingsRoster[0] -- per Michael's "by default,
      // the to-do should not show any rep," a rep has to actively choose
      // an assignment for someone (see the blank placeholder option in
      // the taskAssigneeSelect markup below, and saveTask()'s existing
      // "pick who it's assigned to" validation, which now actually fires
      // instead of always passing against the old Claire Hayden default).
      state.taskDraftAssignee = '';
      state.taskDraftDueDate = '';
      state.meetingsError = null;
      render();
    } else if (action === 'task-add-cancel') {
      state.taskAddOpenForMeeting = null;
      state.taskDraftDueDate = '';
      state.meetingsError = null;
      render();
    } else if (action === 'task-save') {
      saveTask(parseInt(el.getAttribute('data-meeting'), 10));
    } else if (action === 'task-toggle-done') {
      var wasDone = el.getAttribute('data-completed') === '1';
      var completing = !wasDone;
      // Reserve a blank tab HERE, synchronously inside the click gesture --
      // see toggleTaskDone()'s formstackTab docblock for why this can't
      // happen after the async save resolves. Only on completion, never on
      // an un-check (matches Michael's choice: one Formstack entry per
      // to-do, filed at completion).
      var formstackTab = completing ? window.open('', '_blank') : null;
      toggleTaskDone(parseInt(el.getAttribute('data-task'), 10), completing, formstackTab);
    } else if (action === 'open-customer-task') {
      openCustomerAtTask(
        parseInt(el.getAttribute('data-customer'), 10),
        parseInt(el.getAttribute('data-meeting'), 10),
        parseInt(el.getAttribute('data-task'), 10)
      );
    } else if (action === 'open-customer-riskscan') {
      openCustomerAtRiskScan(
        parseInt(el.getAttribute('data-customer'), 10),
        parseInt(el.getAttribute('data-scan'), 10)
      );
    } else if (action === 'riskscan-upload') {
      uploadRiskScan(parseInt(el.getAttribute('data-customer'), 10));
    } else if (action === 'riskscan-retry-cw') {
      retryRiskScanCw(parseInt(el.getAttribute('data-scan'), 10));
    } else if (action === 'riskscan-mark-reviewed') {
      setRiskScanReviewed(parseInt(el.getAttribute('data-scan'), 10), true);
    } else if (action === 'riskscan-unmark-reviewed') {
      setRiskScanReviewed(parseInt(el.getAttribute('data-scan'), 10), false);
    } else if (action === 'show-rep-todos') {
      var repName = el.getAttribute('data-rep');
      var today = new Date();
      state.view = 'rep-todos';
      state.error = null;
      state.repTodosName = repName;
      state.repTodosData = null;
      state.repTodosError = null;
      state.repTodosCalYear = today.getFullYear();
      state.repTodosCalMonth = today.getMonth() + 1;
      render();
      loadRepTodos(repName);
    } else if (action === 'rep-todos-back') {
      state.view = 'dashboard';
      state.error = null;
      render();
      if (!state.selectedCustomer && !state.overview) loadOverview();
      if (!state.selectedCustomer) loadGlobalTodos();
    } else if (action === 'rep-todos-prev-month') {
      shiftRepTodosMonth(-1);
    } else if (action === 'rep-todos-next-month') {
      shiftRepTodosMonth(1);
    } else if (action === 'signout') {
      signOut();
    }
  }

  // Close the search dropdown on an outside click, without a rebind loop.
  function onDocumentClick(e) {
    var changed = false;
    if (state.resultsOpen && !e.target.closest('.search-wrap')) {
      state.resultsOpen = false;
      changed = true;
    }
    // Contact card dropdown -- added 2026-09-23, same outside-click-closes
    // pattern as the customer search box above.
    if (state.contactCardOpen && !e.target.closest('.contact-card-dropdown')) {
      state.contactCardOpen = false;
      changed = true;
    }
    // Checklist contact dropdown -- same pattern, separate open-key since
    // more than one checklist's dropdown can exist on the page at once.
    if (state.openChecklistContactDropdownKey && !e.target.closest('.checklist-contact-dropdown')) {
      state.openChecklistContactDropdownKey = null;
      changed = true;
    }
    if (changed) render();
  }

  // Bound once — root's contents are replaced on every render(), so these
  // rely on event delegation rather than being rebound each time.
  root.addEventListener('click', onRootClick);
  root.addEventListener('input', onProspectInput);
  root.addEventListener('change', onProspectInput);
  root.addEventListener('change', onProspectFilterCommit);
  document.addEventListener('click', onDocumentClick);

  boot();
})();
