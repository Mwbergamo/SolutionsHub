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

    // Checklist data, keyed by "customerId::pillarId::serviceId". Each
    // value is: undefined (not fetched yet), 'error', or an array of the
    // 7 step objects from checklist.php?action=get.
    checklists: {},
    openChecklistKey: null,

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
    activityInvoicesMonth: null, // { month: "YYYY-MM", label: "Aug 2026" }
    activityInvoices: null, // array | 'error' | null
    activityInvoicesLoading: false,
    activityInvoiceNumber: null, // shown as the drilldown title while loading
    activityInvoiceDetail: null, // object | 'error' | null
    activityInvoiceDetailLoading: false
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

  // ---- Data loading -----------------------------------------------------

  function boot() {
    apiGet('api/auth.php?action=me').then(function (r) {
      if (!r.data || !r.data.ok || !r.data.user) {
        window.location.href = 'login.html?next=' + encodeURIComponent('index.html');
        return;
      }
      state.user = r.data.user;
      render();
    }).catch(function () {
      window.location.href = 'login.html?next=' + encodeURIComponent('index.html');
    });
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
    state.activityInvoicesMonth = null;
    state.activityInvoices = null;
    state.activityInvoicesLoading = false;
    state.activityInvoiceNumber = null;
    state.activityInvoiceDetail = null;
    state.activityInvoiceDetailLoading = false;
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

  function loadActivityInvoices(customerId, month, label) {
    state.activityView = 'invoices';
    state.activityInvoicesMonth = { month: month, label: label };
    state.activityInvoicesLoading = true;
    state.activityInvoices = null;
    state.error = null;
    render();
    apiGet(
      'api/activity.php?action=invoices&customer_id=' + encodeURIComponent(customerId) + '&month=' + encodeURIComponent(month)
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
      state.checklists[key] = (r.data && r.data.ok) ? r.data.steps : 'error';
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

  function openCustomerAtChecklist(customerId, pillarId, serviceId, serviceName) {
    state.view = 'dashboard';
    state.pendingFocus = { pillarId: pillarId, serviceId: serviceId, serviceName: serviceName };
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
            '<button class="nav-btn ' + (state.view === 'sync' ? 'active' : '') + '" type="button" data-action="show-sync">ConnectWise Sync</button>' +
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
    if (state.view === 'sync') {
      return (state.error ? '<div class="error-banner">' + escapeHtml(state.error) + '</div>' : '') + syncHtml();
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
      html += '<div class="empty-state">Search for a customer above to see their active CodeBlue services and what they’re missing.</div>';
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

  function syncHtml() {
    var html = '<div class="view-header">' +
      '<div class="view-title">ConnectWise Sync</div>' +
      '<div class="view-sub">Pulls active services from ConnectWise — IT Services, Voice, Premise Security, and Data Center agreements — into this dashboard, refreshes every customer’s Monthly Billing chart, then pulls in Active/Delinquent/Special Info companies with no agreement at all as Prospects. Checklist progress already recorded isn’t touched.</div>' +
    '</div>';

    html += '<div class="sync-panel">';

    var running = state.syncRunning || state.billingSyncRunning || state.prospectSyncRunning;

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

    html += '</div>';
    return html;
  }

  function searchBoxHtml() {
    var box =
      '<div class="search-box">' +
        '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="oklch(0.5 0.02 255)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex:0 0 auto;"><circle cx="11" cy="11" r="7"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>' +
        '<input id="customerSearchInput" type="text" value="' + escapeHtml(state.query) + '" placeholder="Search customers by name…" autocomplete="off">' +
      '</div>';

    if (state.resultsOpen && state.query.trim()) {
      box += '<div class="search-results">';
      if (state.searching) {
        box += '<div class="search-empty">Searching…</div>';
      } else if (state.results.length) {
        state.results.forEach(function (c) {
          var rowClass = c.is_peoplefirst ? ' peoplefirst' : (c.is_prospect_only ? ' prospect' : '');
          box += '<div class="search-result-row' + rowClass + '" data-action="select-customer" data-id="' + c.id + '">' +
            '<span>' + escapeHtml(c.name) + '</span>' +
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
      var maxTotal = Math.max.apply(null, billing.series.map(function (m) { return m.total; }).concat([1]));
      var trend = billing.trend;
      var trendIcon = trend.direction === 'up' ? '▲' : (trend.direction === 'down' ? '▼' : '—');
      var trendPctText = trend.percent == null ? '' : (trend.percent + '%');
      var trendSummary = trend.direction === 'flat'
        ? 'Holding steady'
        : ('Trending ' + trend.direction + (trendPctText ? ' ' + trendPctText : '') + ' on average');

      html += '<div class="activity-card billing-card">' +
        '<div class="activity-card-label-row">' +
          '<div class="activity-card-label">Monthly Billing</div>' +
          '<div class="trend-badge ' + trend.direction + '">' + trendIcon + (trendPctText ? ' ' + trendPctText : '') + '</div>' +
        '</div>' +
        '<div class="billing-chart">' +
          billing.series.map(function (m) {
            var pct = maxTotal > 0 ? Math.max(4, Math.round((m.total / maxTotal) * 100)) : 4;
            return '<button class="billing-bar-col" type="button" data-action="open-invoices" data-month="' + m.month + '" data-label="' + escapeHtml(m.label) + '" title="' + escapeHtml(m.label) + ': ' + fmtCurrency(m.total) + '">' +
              '<div class="billing-bar-value">' + fmtCurrency(m.total) + '</div>' +
              '<div class="billing-bar-track"><div class="billing-bar-fill" style="height:' + pct + '%"></div></div>' +
              '<div class="billing-bar-label">' + escapeHtml(m.label.split(' ')[0]) + '</div>' +
            '</button>';
          }).join('') +
        '</div>' +
        '<div class="activity-card-sub">' + escapeHtml(trendSummary) + ' vs. the prior 3 months — Agreement invoices only, click a bar for detail. Synced ' + escapeHtml(fmtTimestamp(summary.billing_synced_at)) + '.</div>' +
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
      var m = state.activityInvoicesMonth || {};
      html += '<div class="drilldown-header">' + activityDrilldownBackBtn('activity-close', 'Close') +
        '<div class="drilldown-title">Agreement Invoices — ' + escapeHtml(m.label || '') + '</div>' +
      '</div>';
      if (state.activityInvoicesLoading || !state.activityInvoices) {
        html += '<div class="loading">Loading invoices…</div>';
      } else if (state.activityInvoices === 'error') {
        html += '<div class="activity-error">Could not load invoices from ConnectWise.</div>';
      } else if (state.activityInvoices.length === 0) {
        html += '<div class="empty-state">No Agreement invoices this month.</div>';
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
      '<button class="change-customer-btn" type="button" data-action="change-customer">Search a different customer</button>' +
    '</div>';
    if (detail.customer.is_peoplefirst) {
      html += '<div class="peoplefirst-note">PeopleFirst Support Members - Quarterly Risk Scans and Monthly Client Checkin\'s are required.</div>';
      html += peopleFirstFieldsHtml(detail.customer);
    } else if (detail.customer.is_prospect_only) {
      html += '<div class="prospect-note">Prospect — a ConnectWise company with no active CodeBlue services yet. Every pillar below is a cross-sell opportunity.</div>';
    }

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
      html += '<div class="pillar-tile ' + tileClass + '" data-action="open-pillar" data-pillar="' + pillar.id + '"' +
        (isHostedVoip ? ' title="Voice hosted directly by the manufacturer' + (detail.customer.voip_hosted_agreement_name ? ' (' + escapeHtml(detail.customer.voip_hosted_agreement_name) + ')' : '') + ' — do not market phone/VoIP services to this customer."' : '') + '>' +
        '<div>' +
          '<div class="pillar-tile-name">' + escapeHtml(pillar.name) + '</div>' +
          '<div class="pillar-tile-count">' + countText + '</div>' +
        '</div>' +
        '<div class="pillar-tile-status">' + statusText + '</div>' +
      '</div>';
    });
    html += '</div>';

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

    html += '</div>'; // .dashboard-grid

    if (state.activePillarId) {
      var pillar = detail.pillars.filter(function (p) { return p.id === state.activePillarId; })[0];
      if (pillar) {
        html += drilldownHtml(pillar, detail.customer);
      }
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

    html += '<div class="checklist-panel" data-checklist-key="' + key + '">';
    var data = state.checklists[key];
    if (data === 'error') {
      html += '<div class="checklist-error">Could not load the checklist — try again.</div>';
    } else if (!data) {
      html += '<div class="checklist-loading">Loading checklist…</div>';
    } else {
      data.forEach(function (step) {
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
      });
    }
    html += '</div>';

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
      render();
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
    } else if (action === 'show-sync') {
      state.view = 'sync';
      state.error = null;
      render();
      loadSyncStatus();
    } else if (action === 'run-sync') {
      runFullSync();
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
    } else if (action === 'open-tickets') {
      loadActivityTickets(state.selectedCustomer.customer.id);
    } else if (action === 'open-invoices') {
      loadActivityInvoices(state.selectedCustomer.customer.id, el.getAttribute('data-month'), el.getAttribute('data-label'));
    } else if (action === 'open-invoice-detail') {
      loadActivityInvoiceDetail(el.getAttribute('data-invoice'), el.getAttribute('data-number'));
    } else if (action === 'activity-close') {
      state.activityView = null;
      render();
    } else if (action === 'activity-back-to-invoices') {
      state.activityView = 'invoices';
      render();
    } else if (action === 'signout') {
      signOut();
    }
  }

  // Close the search dropdown on an outside click, without a rebind loop.
  function onDocumentClick(e) {
    if (!state.resultsOpen) return;
    if (e.target.closest('.search-wrap')) return;
    state.resultsOpen = false;
    render();
  }

  // Bound once — root's contents are replaced on every render(), so these
  // rely on event delegation rather than being rebound each time.
  root.addEventListener('click', onRootClick);
  document.addEventListener('click', onDocumentClick);

  boot();
})();
