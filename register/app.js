/**
 * register/app.js
 *
 * Register — CodeBlue Technology's retail checkout app. Same self-contained
 * vanilla-JS pattern as relationships/app.js (a state object + string-
 * template rendering, no framework): sign-in gate, a catalog synced from
 * ConnectWise's Procurement Catalog (productClass='Inventory' items only —
 * see api/catalog.php's header for the confirmed field/endpoint history),
 * a cart, manual payment recording (no live card processing — Michael's
 * explicit choice), and a printable receipt.
 */

(function () {
  'use strict';

  // SolutionsHub site root is one level up from /register/.
  var HUB_URL = '../index.html';

  var root = document.getElementById('app-root');

  var state = {
    user: null,
    error: null,

    // 'register' | 'history'
    view: 'register',

    catalogItems: [],
    catalogLoading: false,
    catalogError: null,
    search: '',
    inStockOnly: true,

    // Nested Type > Category > SubCategory browse menu (added 2026-09-14)
    // -- built server-side from whatever's actually synced (see api/
    // catalog.php's register_catalog_type_menu()), not hardcoded here, so
    // it can never drift out of sync with the real catalog. An empty
    // filter string means "All" at that level.
    typeMenu: [],
    typeMenuLoading: false,
    filterType: '',
    filterCategory: '',
    filterSubcategory: '',

    syncing: false,
    syncMessage: null,
    syncFilterTotal: 0,
    syncFilterTotalKnown: false,
    syncTotal: 0,
    syncProcessed: 0,

    // cart: [{ catalog_item_id, identifier, description, unit_price, quantity, on_hand }]
    cart: [],

    checkoutOpen: false,
    checkoutSubmitting: false,
    checkoutError: null,
    checkoutForm: { payment_method: 'cash', payment_reference: '', tax_amount: '0.00', note: '' },

    // Customer (Company/Contact) resolution -- added 2026-09-14, replacing
    // the old free-text customer_name field. Per Michael: live ConnectWise
    // search, no free-text/walk-in fallback -- every sale must resolve to
    // a real Company AND Contact (a Contact is always tied to a Company in
    // ConnectWise, so picking either one resolves both together). See
    // api/customers.php for the search/create/finalize-invoicing backend.
    customer: initialCustomerState(),
    customerUi: initialCustomerUiState(),

    // Set right after a successful checkout (or when reopening one from
    // History) -- the receipt overlay shows whenever this is non-null.
    receipt: null,

    history: null,
    historyLoading: false
  };

  function initialCustomerState() {
    return {
      companyId: null,
      companyName: '',
      contactId: null,
      contactName: '',
      // True only while the currently-selected company was created FRESH
      // during this checkout (not recalled) -- controls whether adding
      // the contact also triggers the required invoicing setup (Primary
      // Contact/Bill To/Billing Terms/Invoice Delivery Method). Never set
      // for a recalled/existing company, which must keep whatever billing
      // setup it already has.
      isNewCompany: false,
      invoicingWarning: null
    };
  }

  function initialCustomerUiState() {
    return {
      // 'search' (combined Company+Contact search) | 'new-company' |
      // 'contact' (contact search scoped to the chosen company) |
      // 'new-contact'
      mode: 'search',
      query: '',
      loading: false,
      error: null,
      companyResults: [],
      contactResults: [],
      newCompanyForm: { name: '', phone: '', address_line1: '', address_line2: '', city: '', state: 'VA', zip: '' },
      newCompanySubmitting: false,
      newContactForm: { first_name: '', last_name: '', phone: '', email: '' },
      newContactSubmitting: false
    };
  }

  function resetCustomerState() {
    state.customer = initialCustomerState();
    state.customerUi = initialCustomerUiState();
  }

  function escapeHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function fmtMoney(n) {
    return '$' + Number(n || 0).toFixed(2);
  }

  function fmtQty(n) {
    n = Number(n || 0);
    return n % 1 === 0 ? String(n) : n.toFixed(2);
  }

  function fmtTimestamp(raw) {
    if (!raw) return '';
    var iso = raw.indexOf('T') === -1 ? raw.replace(' ', 'T') + 'Z' : raw;
    var d = new Date(iso);
    if (isNaN(d.getTime())) return raw;
    return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }) +
      ' ' + d.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
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

  // ---- Boot / data loading ----------------------------------------------

  function boot() {
    apiGet('api/auth.php?action=me').then(function (r) {
      if (!r.data || !r.data.ok || !r.data.user) {
        window.location.href = 'login.html?next=' + encodeURIComponent('index.html');
        return;
      }
      state.user = r.data.user;
      render();
      loadCatalog();
      loadTypeMenu();
    }).catch(function () {
      window.location.href = 'login.html?next=' + encodeURIComponent('index.html');
    });
  }

  // Shared by loadCatalog()/loadCatalogNow() so the search/stock-toggle/
  // browse-menu filters never drift out of sync between the debounced and
  // immediate reload paths.
  function catalogListUrl() {
    var url = 'api/catalog.php?action=list';
    if (state.search.trim()) url += '&q=' + encodeURIComponent(state.search.trim());
    if (state.inStockOnly) url += '&in_stock_only=1';
    if (state.filterType) url += '&type=' + encodeURIComponent(state.filterType);
    if (state.filterCategory) url += '&category=' + encodeURIComponent(state.filterCategory);
    if (state.filterSubcategory) url += '&subcategory=' + encodeURIComponent(state.filterSubcategory);
    return url;
  }

  // Loads the nested Type > Category > SubCategory browse menu (added
  // 2026-09-14) -- once at boot, and again after a sync finishes in case
  // ConnectWise added a new category/subcategory. Deliberately silent on
  // failure (catch empty): the menu is a browse convenience, not something
  // that should block or error out the whole register if it hiccups --
  // search and the in-stock toggle keep working either way.
  function loadTypeMenu() {
    state.typeMenuLoading = true;
    apiGet('api/catalog.php?action=type-menu').then(function (r) {
      state.typeMenuLoading = false;
      if (r.data && r.data.ok) {
        state.typeMenu = r.data.menu;
        render();
      }
    }).catch(function () { state.typeMenuLoading = false; });
  }

  var searchDebounce = null;
  function loadCatalog() {
    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(function () {
      state.catalogLoading = true;
      state.catalogError = null;
      render();
      apiGet(catalogListUrl()).then(function (r) {
        state.catalogLoading = false;
        if (r.data && r.data.ok) {
          state.catalogItems = r.data.items;
        } else {
          state.catalogError = (r.data && r.data.error) || 'Could not load the catalog.';
        }
        render();
      }).catch(function () {
        state.catalogLoading = false;
        state.catalogError = 'Could not load the catalog — check your connection.';
        render();
      });
    }, 200);
  }

  // Catalog sync is a four-stage queue-based start()/step() pair, revised
  // 2026-09-13 after a two-phase version STILL failed to start ("Could not
  // start the sync…" turned out to mean sync-start itself was timing out --
  // even the cheap id/identifier-only catalog list calls take ~73
  // sequential ConnectWise pages at the confirmed ~14,600-item scale, and
  // doing that synchronously in one request hit the same execution-time
  // limit the original 2026-09-12 fix was meant to rule out everywhere).
  // Every stage now does at most one ConnectWise round-trip per sync-step
  // call: 'list_agreement' and 'list_inventory' page through ConnectWise's
  // catalog one page at a time (sync-start itself makes zero ConnectWise
  // calls); 'filter' does one cheap /inventory-only check per Inventory
  // candidate to find which ~603 actually have stock (confirmed-zero
  // results are cached server-side, so this shrinks to almost nothing on
  // later syncs); 'sync' is the original full-detail fetch. No single HTTP
  // request risks timing out no matter how large the catalog grows.
  function runSync() {
    state.syncing = true;
    state.syncMessage = 'Starting sync…';
    state.syncFilterTotal = 0;
    state.syncFilterTotalKnown = false;
    state.syncTotal = 0;
    state.syncProcessed = 0;
    state.error = null;
    render();
    apiPost('api/catalog.php?action=sync-start', {}).then(function (r) {
      if (!r.data || !r.data.ok) {
        state.syncing = false;
        state.syncMessage = null;
        state.error = (r.data && r.data.error) || 'Could not start the sync.';
        render();
        return;
      }
      state.syncMessage = 'Finding recurring-protection products…';
      render();
      syncStepLoop();
    }).catch(function () {
      state.syncing = false;
      state.syncMessage = null;
      state.error = 'Could not start the sync — check your connection and try again.';
      render();
    });
  }

  // A single sync-step call is retried up to this many times (with a short
  // backoff) before the sync gives up and surfaces an error -- added
  // 2026-09-13 after a "Sync failed partway through" mid-run failure. Each
  // step is safe to retry as-is: the queue/no-stock-cache state lives
  // server-side, so re-calling sync-step just resumes wherever the last
  // (possibly failed) attempt left off, rather than losing progress or
  // double-processing anything.
  var REGISTER_SYNC_STEP_MAX_RETRIES = 3;

  function syncStepLoop(retryCount) {
    retryCount = retryCount || 0;
    apiPost('api/catalog.php?action=sync-step', { batch_size: 10 }).then(function (r) {
      if (!r.data || !r.data.ok) {
        if (retryCount < REGISTER_SYNC_STEP_MAX_RETRIES) {
          state.syncMessage = (state.syncMessage || 'Syncing…').replace(/ \(retrying…\)$/, '') + ' (retrying…)';
          render();
          setTimeout(function () { syncStepLoop(retryCount + 1); }, 1000 * (retryCount + 1));
          return;
        }
        state.syncing = false;
        state.syncMessage = null;
        state.error = (r.data && r.data.error) || 'Sync failed partway through.';
        render();
        return;
      }
      var d = r.data;
      if (d.phase === 'list_agreement') {
        state.syncMessage = 'Finding recurring-protection products: ' + d.listing_totals.agreement_listed + ' found…';
      } else if (d.phase === 'list_inventory') {
        state.syncMessage = 'Scanning catalog: ' + d.listing_totals.inventory_listed + ' items seen, ' +
          d.listing_totals.filter_queued + ' need a stock check…';
      } else if (d.phase === 'filter') {
        // The first filter-phase step we see is the moment listing just
        // finished -- filter_totals.pending at that exact instant is the
        // true total (nothing's been processed or errored yet), so capture
        // it once rather than trusting a total known up front.
        if (!state.syncFilterTotalKnown) {
          state.syncFilterTotal = d.filter_totals.pending;
          state.syncFilterTotalKnown = true;
        }
        var checked = state.syncFilterTotal - d.filter_totals.pending;
        state.syncMessage = 'Checking stock: ' + checked + ' of ' + state.syncFilterTotal + '…';
      } else {
        state.syncTotal = d.sync_totals.pending + d.sync_totals.done + d.sync_totals.error;
        state.syncProcessed = d.sync_totals.done + d.sync_totals.error;
        state.syncMessage = 'Syncing ' + state.syncProcessed + ' of ' + state.syncTotal + '…';
      }
      if (d.done) {
        state.syncing = false;
        var totalErrors = (d.filter_totals.error || 0) + (d.sync_totals.error || 0);
        state.syncMessage = 'Synced ' + d.sync_totals.done + ' item' + (d.sync_totals.done === 1 ? '' : 's') +
          (totalErrors ? (' (' + totalErrors + ' failed)') : '') + ' from ConnectWise.';
        loadCatalogNow();
        loadTypeMenu();
      } else {
        render();
        syncStepLoop();
      }
      render();
    }).catch(function () {
      if (retryCount < REGISTER_SYNC_STEP_MAX_RETRIES) {
        state.syncMessage = (state.syncMessage || 'Syncing…').replace(/ \(retrying…\)$/, '') + ' (retrying…)';
        render();
        setTimeout(function () { syncStepLoop(retryCount + 1); }, 1000 * (retryCount + 1));
        return;
      }
      state.syncing = false;
      state.syncMessage = null;
      state.error = 'Sync failed partway through — check your connection and try again.';
      render();
    });
  }

  // Immediate (non-debounced) catalog reload, used right after a sync or a
  // checkout so the list/on-hand counts reflect what just happened.
  function loadCatalogNow() {
    apiGet(catalogListUrl()).then(function (r) {
      if (r.data && r.data.ok) {
        state.catalogItems = r.data.items;
        render();
      }
    }).catch(function () {});
  }

  function loadHistory() {
    state.historyLoading = true;
    state.history = null;
    render();
    apiGet('api/checkout.php?action=history&limit=50').then(function (r) {
      state.historyLoading = false;
      if (r.data && r.data.ok) {
        state.history = r.data.sales;
      } else {
        state.error = (r.data && r.data.error) || 'Could not load sale history.';
      }
      render();
    }).catch(function () {
      state.historyLoading = false;
      state.error = 'Could not load sale history — check your connection.';
      render();
    });
  }

  function viewPastReceipt(saleId) {
    apiGet('api/checkout.php?action=receipt&id=' + encodeURIComponent(saleId)).then(function (r) {
      if (r.data && r.data.ok) {
        state.receipt = r.data.sale;
      } else {
        state.error = (r.data && r.data.error) || 'Could not load that receipt.';
      }
      render();
    }).catch(function () {
      state.error = 'Could not load that receipt — check your connection.';
      render();
    });
  }

  function signOut() {
    apiPost('api/auth.php?action=logout', {}).finally(function () {
      window.location.href = 'login.html';
    });
  }

  // ---- Cart ---------------------------------------------------------

  function addToCart(catalogItemId) {
    catalogItemId = Number(catalogItemId);
    var item = state.catalogItems.filter(function (i) { return i.id === catalogItemId; })[0];
    if (!item) return;
    var existing = state.cart.filter(function (c) { return c.catalog_item_id === catalogItemId; })[0];
    var currentQty = existing ? existing.quantity : 0;
    var trackInventory = item.track_inventory !== 0;
    if (trackInventory && currentQty + 1 > item.on_hand) {
      state.error = 'Only ' + fmtQty(item.on_hand) + ' of "' + item.identifier + '" on hand.';
      render();
      return;
    }
    if (existing) {
      existing.quantity += 1;
    } else {
      state.cart.push({
        catalog_item_id: catalogItemId,
        identifier: item.identifier,
        description: item.description,
        unit_price: item.price,
        quantity: 1,
        on_hand: item.on_hand,
        track_inventory: trackInventory
      });
    }
    state.error = null;
    render();
  }

  function setCartQty(catalogItemId, qty) {
    catalogItemId = Number(catalogItemId);
    qty = Math.max(0, Math.floor(Number(qty) || 0));
    var line = state.cart.filter(function (c) { return c.catalog_item_id === catalogItemId; })[0];
    if (!line) return;
    if (line.track_inventory && qty > line.on_hand) {
      qty = line.on_hand;
    }
    if (qty === 0) {
      state.cart = state.cart.filter(function (c) { return c.catalog_item_id !== catalogItemId; });
    } else {
      line.quantity = qty;
    }
    render();
  }

  function removeFromCart(catalogItemId) {
    catalogItemId = Number(catalogItemId);
    state.cart = state.cart.filter(function (c) { return c.catalog_item_id !== catalogItemId; });
    render();
  }

  function cartSubtotal() {
    return state.cart.reduce(function (sum, c) { return sum + c.unit_price * c.quantity; }, 0);
  }

  function resetSale() {
    state.cart = [];
    state.receipt = null;
    state.checkoutForm = { payment_method: 'cash', payment_reference: '', tax_amount: '0.00', note: '' };
    state.checkoutError = null;
    resetCustomerState();
    render();
  }

  // ---- Customer (Company/Contact) --------------------------------------
  //
  // Replaces the old free-text customer_name field (2026-09-14, per
  // Michael): checkout must resolve a real ConnectWise Company AND
  // Contact before it can complete. Three ways to resolve one:
  //   1. Search finds an existing Contact -> both Company and Contact are
  //      resolved together immediately (a Contact is always tied to a
  //      Company in ConnectWise -- see the contact's `company` field).
  //   2. Search finds an existing Company -> pick/search a Contact scoped
  //      to it (or add a new one under it).
  //   3. Neither exists -> "+ New Company" quick-add, then a required
  //      "+ New Contact" quick-add under it, then -- ONLY because the
  //      company is brand-new -- action=finalize-company-invoicing sets
  //      Primary Contact/Bill To/Billing Terms/Invoice Delivery Method
  //      (see api/customers.php; never called for a recalled company).

  var customerSearchDebounce = null;

  function searchCustomers(query) {
    state.customerUi.query = query;
    clearTimeout(customerSearchDebounce);
    if (query.trim().length < 2) {
      state.customerUi.companyResults = [];
      state.customerUi.contactResults = [];
      state.customerUi.loading = false;
      render();
      return;
    }
    customerSearchDebounce = setTimeout(function () {
      state.customerUi.loading = true;
      state.customerUi.error = null;
      render();
      var q = encodeURIComponent(query.trim());
      Promise.all([
        apiGet('api/customers.php?action=search-companies&q=' + q),
        apiGet('api/customers.php?action=search-contacts&q=' + q)
      ]).then(function (results) {
        state.customerUi.loading = false;
        var companiesR = results[0], contactsR = results[1];
        state.customerUi.companyResults = (companiesR.data && companiesR.data.ok) ? companiesR.data.companies : [];
        state.customerUi.contactResults = (contactsR.data && contactsR.data.ok) ? contactsR.data.contacts : [];
        render();
      }).catch(function () {
        state.customerUi.loading = false;
        state.customerUi.error = 'Could not search — check your connection.';
        render();
      });
    }, 250);
  }

  var companyContactSearchDebounce = null;

  // Contact search scoped to the already-chosen company (customerUi.mode
  // === 'contact'). Called with an empty query to load every contact for
  // that company right after it's picked.
  function searchCompanyContacts(query) {
    state.customerUi.query = query;
    clearTimeout(companyContactSearchDebounce);
    var run = function () {
      state.customerUi.loading = true;
      state.customerUi.error = null;
      render();
      var url = 'api/customers.php?action=search-contacts&company_id=' + encodeURIComponent(state.customer.companyId);
      if (query.trim()) url += '&q=' + encodeURIComponent(query.trim());
      apiGet(url).then(function (r) {
        state.customerUi.loading = false;
        state.customerUi.contactResults = (r.data && r.data.ok) ? r.data.contacts : [];
        render();
      }).catch(function () {
        state.customerUi.loading = false;
        state.customerUi.error = 'Could not search contacts — check your connection.';
        render();
      });
    };
    if (query === '') { run(); } else { companyContactSearchDebounce = setTimeout(run, 250); }
  }

  function contactDisplayName(c) {
    return ((c.firstName || '') + ' ' + (c.lastName || '')).trim();
  }

  function selectCompany(companyId, companyName) {
    state.customer.companyId = companyId;
    state.customer.companyName = companyName;
    state.customer.isNewCompany = false;
    state.customer.contactId = null;
    state.customer.contactName = '';
    state.customerUi.mode = 'contact';
    state.customerUi.error = null;
    state.customerUi.contactResults = [];
    render();
    searchCompanyContacts('');
  }

  // A Contact result resolves BOTH Company and Contact at once, whether it
  // came from the combined search (its own `company` field supplies the
  // company) or from a company-scoped search (the company is already
  // chosen in state.customer).
  function selectContact(contact) {
    if (!state.customer.companyId) {
      var company = contact.company || {};
      if (!company.id) {
        state.customerUi.error = 'This contact has no company on file in ConnectWise -- search for the company instead.';
        render();
        return;
      }
      state.customer.companyId = company.id;
      state.customer.companyName = company.name || '';
      state.customer.isNewCompany = false;
    }
    state.customer.contactId = contact.id;
    state.customer.contactName = contactDisplayName(contact);
    state.customerUi.mode = 'resolved';
    state.customerUi.error = null;
    render();
  }

  function openNewCompanyForm() {
    state.customerUi.mode = 'new-company';
    state.customerUi.error = null;
    state.customerUi.newCompanyForm = { name: state.customerUi.query.trim(), phone: '', address_line1: '', address_line2: '', city: '', state: 'VA', zip: '' };
    render();
  }

  function submitNewCompany() {
    var f = state.customerUi.newCompanyForm;
    if (!f.name.trim()) {
      state.customerUi.error = 'Company name is required.';
      render();
      return;
    }
    state.customerUi.newCompanySubmitting = true;
    state.customerUi.error = null;
    render();
    apiPost('api/customers.php?action=create-company', {
      name: f.name.trim(),
      phone: f.phone.trim(),
      address_line1: f.address_line1.trim(),
      address_line2: f.address_line2.trim(),
      city: f.city.trim(),
      state: f.state.trim() || 'VA',
      zip: f.zip.trim()
    }).then(function (r) {
      state.customerUi.newCompanySubmitting = false;
      if (r.data && r.data.ok && r.data.company && r.data.company.id) {
        state.customer.companyId = r.data.company.id;
        state.customer.companyName = r.data.company.name || f.name.trim();
        state.customer.isNewCompany = true;
        state.customer.contactId = null;
        state.customer.contactName = '';
        // A brand-new company always needs a brand-new Primary Contact --
        // there's nothing to recall yet, so skip straight to that form.
        state.customerUi.mode = 'new-contact';
        state.customerUi.newContactForm = { first_name: '', last_name: '', phone: f.phone.trim(), email: '' };
        state.customerUi.error = null;
        render();
      } else {
        state.customerUi.error = (r.data && r.data.error) || 'Could not create the company -- try again.';
        render();
      }
    }).catch(function () {
      state.customerUi.newCompanySubmitting = false;
      state.customerUi.error = 'Could not create the company -- check your connection.';
      render();
    });
  }

  function openNewContactForm() {
    state.customerUi.mode = 'new-contact';
    state.customerUi.error = null;
    state.customerUi.newContactForm = { first_name: '', last_name: '', phone: '', email: '' };
    render();
  }

  function submitNewContact() {
    var f = state.customerUi.newContactForm;
    if (!f.first_name.trim() || !f.last_name.trim()) {
      state.customerUi.error = 'First and last name are required.';
      render();
      return;
    }
    state.customerUi.newContactSubmitting = true;
    state.customerUi.error = null;
    render();
    apiPost('api/customers.php?action=create-contact', {
      company_id: state.customer.companyId,
      first_name: f.first_name.trim(),
      last_name: f.last_name.trim(),
      phone: f.phone.trim(),
      email: f.email.trim()
    }).then(function (r) {
      if (!r.data || !r.data.ok || !r.data.contact || !r.data.contact.id) {
        state.customerUi.newContactSubmitting = false;
        state.customerUi.error = (r.data && r.data.error) || 'Could not create the contact -- try again.';
        render();
        return;
      }
      var contactId = r.data.contact.id;
      var contactName = (f.first_name.trim() + ' ' + f.last_name.trim()).trim();

      if (!state.customer.isNewCompany) {
        // Existing/recalled company -- the contact is created, done. Never
        // call finalize-company-invoicing here: that would overwrite
        // Billing Terms/Bill To/etc. staff may already have set.
        state.customerUi.newContactSubmitting = false;
        state.customer.contactId = contactId;
        state.customer.contactName = contactName;
        state.customerUi.mode = 'resolved';
        state.customerUi.error = null;
        render();
        return;
      }

      // Brand-new company + brand-new contact: complete the required
      // invoicing setup (Michael's multi-step rule -- see
      // api/customers.php's register_cw_finalize_company_invoicing()).
      apiPost('api/customers.php?action=finalize-company-invoicing', {
        company_id: state.customer.companyId,
        contact_id: contactId
      }).then(function (fr) {
        state.customerUi.newContactSubmitting = false;
        state.customer.contactId = contactId;
        state.customer.contactName = contactName;
        state.customerUi.mode = 'resolved';
        if (fr.data && fr.data.ok) {
          state.customer.invoicingWarning = null;
        } else {
          // The Company and Contact DO exist in ConnectWise by this point
          // -- only the finance/invoicing step failed. Surface it plainly
          // rather than silently losing track of a real create, and let
          // the sale still proceed (the alternative -- discarding a real
          // ConnectWise Company/Contact because one follow-up call failed
          // -- is worse).
          state.customer.invoicingWarning = 'Company and contact were created, but the invoicing setup (Primary Contact/Billing Terms) failed: ' +
            ((fr.data && fr.data.error) || 'unknown error') + '. The sale can still be completed; flag this account for manual setup in ConnectWise.';
        }
        render();
      }).catch(function () {
        state.customerUi.newContactSubmitting = false;
        state.customer.contactId = contactId;
        state.customer.contactName = contactName;
        state.customer.invoicingWarning = 'Company and contact were created, but the invoicing setup call failed -- check your connection. The sale can still be completed; flag this account for manual setup in ConnectWise.';
        state.customerUi.mode = 'resolved';
        render();
      });
    }).catch(function () {
      state.customerUi.newContactSubmitting = false;
      state.customerUi.error = 'Could not create the contact -- check your connection.';
      render();
    });
  }

  // Back out of the contact-picking step to company search -- only
  // reachable when the company was RECALLED (an existing company), since a
  // brand-new company's only path forward is its required new-contact
  // form (no "back" from there -- the company already exists in
  // ConnectWise at that point).
  function backToCompanySearch() {
    resetCustomerState();
    render();
  }

  // Lighter back-step: from the new-contact form back to the contact
  // list, WITHOUT losing the already-chosen (recalled, existing) company.
  // Only used when the company is recalled -- a brand-new company's
  // new-contact form has no cancel path (see customerNewContactFormHtml()).
  function backToContactStep() {
    state.customerUi.mode = 'contact';
    state.customerUi.error = null;
    state.customerUi.query = '';
    render();
    searchCompanyContacts('');
  }

  function changeCustomer() {
    resetCustomerState();
    render();
  }

  // ---- Checkout -------------------------------------------------------

  function openCheckout() {
    if (state.cart.length === 0) return;
    state.checkoutOpen = true;
    state.checkoutError = null;
    render();
  }

  function closeCheckout() {
    state.checkoutOpen = false;
    render();
  }

  function submitCheckout() {
    var form = state.checkoutForm;
    var taxAmount = parseFloat(form.tax_amount);
    if (isNaN(taxAmount) || taxAmount < 0) {
      state.checkoutError = 'Enter a valid tax amount (0 or more).';
      render();
      return;
    }
    // No free-text/walk-in fallback, per Michael's explicit choice -- every
    // sale must resolve to a real ConnectWise Company and Contact.
    if (!state.customer.companyId || !state.customer.contactId) {
      state.checkoutError = 'Select or create a Company and Contact before completing this sale.';
      render();
      return;
    }

    state.checkoutSubmitting = true;
    state.checkoutError = null;
    render();

    apiPost('api/checkout.php?action=create', {
      items: state.cart.map(function (c) { return { catalog_item_id: c.catalog_item_id, quantity: c.quantity }; }),
      payment_method: form.payment_method,
      payment_reference: form.payment_reference,
      tax_amount: taxAmount,
      cw_company_id: state.customer.companyId,
      cw_company_name: state.customer.companyName,
      cw_contact_id: state.customer.contactId,
      cw_contact_name: state.customer.contactName,
      note: form.note
    }).then(function (r) {
      state.checkoutSubmitting = false;
      if (r.data && r.data.ok) {
        state.checkoutOpen = false;
        state.cart = [];
        state.receipt = r.data.sale;
        resetCustomerState();
        loadCatalogNow();
      } else {
        state.checkoutError = (r.data && r.data.error) || 'Could not complete this sale — try again.';
      }
      render();
    }).catch(function () {
      state.checkoutSubmitting = false;
      state.checkoutError = 'Could not complete this sale — check your connection and try again.';
      render();
    });
  }

  // ---- Rendering ----------------------------------------------------

  function render() {
    var searchFocus = captureSearchFocus();
    root.innerHTML = topbarHtml() + '<div class="main">' + mainHtml() + '</div>' + checkoutModalHtml() + receiptOverlayHtml();
    bindEvents();
    restoreSearchFocus(searchFocus);
  }

  // IDs of text inputs that can be mid-render() while focused -- a
  // debounced search re-render (catalog search, and the 2026-09-14
  // customer/contact search boxes) would otherwise steal focus/cursor
  // position out from under whatever the rep is still typing.
  var FOCUS_PRESERVED_INPUT_IDS = ['catalogSearchInput', 'customerSearchInput', 'customerContactSearchInput'];

  function captureSearchFocus() {
    for (var i = 0; i < FOCUS_PRESERVED_INPUT_IDS.length; i++) {
      var el = document.getElementById(FOCUS_PRESERVED_INPUT_IDS[i]);
      if (el && document.activeElement === el) {
        return { id: FOCUS_PRESERVED_INPUT_IDS[i], start: el.selectionStart, end: el.selectionEnd };
      }
    }
    return null;
  }

  function restoreSearchFocus(focusInfo) {
    if (!focusInfo) return;
    var el = document.getElementById(focusInfo.id);
    if (!el) return;
    el.focus();
    try { el.setSelectionRange(focusInfo.start, focusInfo.end); } catch (e) {}
  }

  function topbarHtml() {
    if (!state.user) return '';
    return (
      '<div class="topbar">' +
        '<div class="topbar-left">' +
          '<div>' +
            '<div class="brand">Register</div>' +
            '<div class="brand-sub">CodeBlue Technology — Retail Checkout</div>' +
          '</div>' +
          '<nav class="topbar-nav">' +
            '<button class="nav-btn ' + (state.view === 'register' ? 'active' : '') + '" type="button" data-action="show-register">Register</button>' +
            '<button class="nav-btn ' + (state.view === 'history' ? 'active' : '') + '" type="button" data-action="show-history">Sale History</button>' +
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

    var html = state.error ? '<div class="error-banner">' + escapeHtml(state.error) + '</div>' : '';

    if (state.view === 'history') {
      return html + historyHtml();
    }
    return html + registerHtml();
  }

  function registerHtml() {
    return (
      '<div class="register-layout">' +
        '<div class="catalog-pane">' + catalogToolbarHtml() + typeNavHtml() + catalogGridHtml() + '</div>' +
        '<div class="cart-pane">' + cartHtml() + '</div>' +
      '</div>'
    );
  }

  function catalogToolbarHtml() {
    return (
      '<div class="catalog-toolbar">' +
        '<input id="catalogSearchInput" type="text" placeholder="Scan a barcode, or search by name, part #, SKU…" value="' + escapeHtml(state.search) + '">' +
        '<label class="in-stock-toggle"><input type="checkbox" id="inStockOnlyToggle" ' + (state.inStockOnly ? 'checked' : '') + '> In stock only</label>' +
        '<button type="button" class="sync-btn" data-action="sync" ' + (state.syncing ? 'disabled' : '') + '>' +
          (state.syncing ? 'Syncing…' : '↻ Sync from ConnectWise') +
        '</button>' +
        (state.syncMessage ? '<span class="sync-message">' + escapeHtml(state.syncMessage) + '</span>' : '') +
      '</div>'
    );
  }

  // Nested Type > Category > SubCategory browse menu (added 2026-09-14).
  // Progressive disclosure across three chip rows rather than a permanent
  // sidebar, so it stays out of the way for the common case (a rep just
  // scans a barcode) but is one click deep for browsing: Type row is
  // always visible, Category row appears once a Type is picked, SubCategory
  // row appears once a Category is picked.
  function typeNavHtml() {
    if (state.typeMenu.length === 0) {
      return state.typeMenuLoading ? '' : '';
    }

    var html = '<div class="type-nav">';

    html += '<div class="type-nav-row">' +
      chipHtml('All Types', state.filterType === '', 'select-type', '') +
      state.typeMenu.map(function (t) {
        return chipHtml(t.type, state.filterType === t.type, 'select-type', t.type);
      }).join('') +
      '</div>';

    var activeTypeEntry = state.typeMenu.filter(function (t) { return t.type === state.filterType; })[0];
    if (activeTypeEntry) {
      var categoryNames = Object.keys(activeTypeEntry.categories);
      html += '<div class="type-nav-row type-nav-row-sub">' +
        chipHtml('All Categories', state.filterCategory === '', 'select-category', '') +
        categoryNames.map(function (c) {
          return chipHtml(c, state.filterCategory === c, 'select-category', c);
        }).join('') +
        '</div>';

      var subcategories = state.filterCategory ? (activeTypeEntry.categories[state.filterCategory] || []) : [];
      if (subcategories.length > 0) {
        html += '<div class="type-nav-row type-nav-row-sub">' +
          chipHtml('All', state.filterSubcategory === '', 'select-subcategory', '') +
          subcategories.map(function (s) {
            return chipHtml(s, state.filterSubcategory === s, 'select-subcategory', s);
          }).join('') +
          '</div>';
      }
    }

    html += '</div>';
    return html;
  }

  function chipHtml(label, active, action, value) {
    return '<button type="button" class="type-chip' + (active ? ' active' : '') + '" data-action="' + action + '" data-value="' + escapeHtml(value) + '">' +
      escapeHtml(label) + '</button>';
  }

  function catalogGridHtml() {
    if (state.catalogLoading && state.catalogItems.length === 0) {
      return '<div class="loading">Loading catalog…</div>';
    }
    if (state.catalogError) {
      return '<div class="error-banner">' + escapeHtml(state.catalogError) + '</div>';
    }
    if (state.catalogItems.length === 0) {
      var hasFilter = state.filterType || state.search.trim();
      return '<div class="empty-state">No items found' + (hasFilter ? ' for this filter/search' : '') +
        '. Try "Sync from ConnectWise" if the catalog looks empty or out of date' +
        (state.filterType ? ', or clear the type filter above' : '') + '.</div>';
    }
    var html = '<div class="catalog-grid">';
    state.catalogItems.forEach(function (item) {
      // Agreement-class items (recurring-protection products, e.g.
      // extended warranty / managed services -- added 2026-09-13) have no
      // physical stock at all: never "out of stock", no on-hand count to
      // show, badged instead so staff can tell them apart from a stocked
      // product at a glance.
      var isService = item.track_inventory === 0;
      var outOfStock = !isService && item.on_hand <= 0;
      // Mfg part number shown when present (added 2026-09-14) -- lets a
      // rep who just scanned a box label visually confirm they landed on
      // the right part, since ConnectWise has no barcode/UPC field to
      // match against instead.
      var mfgLine = item.manufacturer_part_number && item.manufacturer_part_number !== item.identifier
        ? '<div class="catalog-card-mfg">MFG#: ' + escapeHtml(item.manufacturer_part_number) + '</div>' : '';
      html += '<div class="catalog-card' + (outOfStock ? ' out-of-stock' : '') + '" ' + (outOfStock ? '' : 'data-action="add-to-cart" data-id="' + item.id + '"') + '>' +
        '<div class="catalog-card-name">' + escapeHtml(item.identifier) + (isService ? ' <span class="catalog-card-badge">Protection Plan</span>' : '') + '</div>' +
        (item.description ? '<div class="catalog-card-desc">' + escapeHtml(item.description) + '</div>' : '') +
        mfgLine +
        '<div class="catalog-card-footer">' +
          '<span class="catalog-card-price">' + fmtMoney(item.price) + '</span>' +
          (isService ? '' : '<span class="catalog-card-stock' + (outOfStock ? ' zero' : '') + '">' + (outOfStock ? 'Out of stock' : fmtQty(item.on_hand) + ' on hand') + '</span>') +
        '</div>' +
      '</div>';
    });
    html += '</div>';
    return html;
  }

  function cartHtml() {
    var html = '<div class="cart-header">Current Sale</div>';
    if (state.cart.length === 0) {
      html += '<div class="cart-empty">Tap a product to add it to this sale.</div>';
    } else {
      html += '<div class="cart-items">';
      state.cart.forEach(function (c) {
        html += '<div class="cart-item">' +
          '<div class="cart-item-main">' +
            '<div class="cart-item-name">' + escapeHtml(c.identifier) + '</div>' +
            '<div class="cart-item-price">' + fmtMoney(c.unit_price) + ' each</div>' +
          '</div>' +
          '<div class="cart-item-qty">' +
            '<button type="button" data-action="qty-dec" data-id="' + c.catalog_item_id + '">–</button>' +
            '<input type="text" inputmode="numeric" value="' + fmtQty(c.quantity) + '" data-action="qty-input" data-id="' + c.catalog_item_id + '">' +
            '<button type="button" data-action="qty-inc" data-id="' + c.catalog_item_id + '">+</button>' +
          '</div>' +
          '<div class="cart-item-total">' + fmtMoney(c.unit_price * c.quantity) + '</div>' +
          '<button type="button" class="cart-item-remove" data-action="remove-from-cart" data-id="' + c.catalog_item_id + '">✕</button>' +
        '</div>';
      });
      html += '</div>';
    }

    html += '<div class="cart-subtotal-row"><span>Subtotal</span><span>' + fmtMoney(cartSubtotal()) + '</span></div>';
    html += '<button type="button" class="checkout-btn" data-action="open-checkout" ' + (state.cart.length === 0 ? 'disabled' : '') + '>Checkout</button>';
    if (state.cart.length > 0) {
      html += '<button type="button" class="clear-cart-btn" data-action="clear-cart">Clear Sale</button>';
    }
    return html;
  }

  function checkoutModalHtml() {
    if (!state.checkoutOpen) return '';
    var f = state.checkoutForm;
    var subtotal = cartSubtotal();
    var taxAmount = parseFloat(f.tax_amount) || 0;
    var total = subtotal + taxAmount;
    var customerResolved = !!(state.customer.companyId && state.customer.contactId);
    var canComplete = customerResolved && !state.checkoutSubmitting;

    return (
      '<div class="modal-backdrop" data-action="close-checkout-backdrop">' +
        '<div class="modal checkout-modal" data-stop-propagation="1">' +
          '<div class="modal-title">Complete Sale</div>' +
          (state.checkoutError ? '<div class="error-banner">' + escapeHtml(state.checkoutError) + '</div>' : '') +
          '<div class="checkout-summary">' +
            '<div class="checkout-summary-row"><span>Subtotal</span><span>' + fmtMoney(subtotal) + '</span></div>' +
            '<div class="checkout-summary-row">' +
              '<span>Tax</span>' +
              '<input type="text" inputmode="decimal" class="tax-input" data-action="tax-input" value="' + escapeHtml(f.tax_amount) + '">' +
            '</div>' +
            '<div class="checkout-summary-row total"><span>Total Due</span><span>' + fmtMoney(total) + '</span></div>' +
          '</div>' +
          '<label>Customer</label>' +
          customerSectionHtml() +
          '<label>Payment Method</label>' +
          '<select data-action="payment-method-select">' +
            ['cash', 'card', 'check', 'other'].map(function (m) {
              return '<option value="' + m + '"' + (f.payment_method === m ? ' selected' : '') + '>' + m.charAt(0).toUpperCase() + m.slice(1) + '</option>';
            }).join('') +
          '</select>' +
          '<label>Reference (optional — last 4, check #, etc.)</label>' +
          '<input type="text" data-action="payment-reference-input" value="' + escapeHtml(f.payment_reference) + '">' +
          '<label>Note (optional)</label>' +
          '<input type="text" data-action="note-input" value="' + escapeHtml(f.note) + '">' +
          '<div class="modal-actions">' +
            '<button type="button" class="modal-cancel" data-action="close-checkout">Cancel</button>' +
            '<button type="button" class="modal-confirm" data-action="submit-checkout" ' + (canComplete ? '' : 'disabled') + '>' +
              (state.checkoutSubmitting ? 'Recording Sale…' : 'Record Payment & Complete') +
            '</button>' +
          '</div>' +
        '</div>' +
      '</div>'
    );
  }

  // Customer (Company/Contact) picker -- see the "---- Customer ----"
  // functions above for the flow this renders. Lives inside the checkout
  // modal, above Payment Method, since checkout can't complete without it.
  function customerSectionHtml() {
    var ui = state.customerUi;
    var html = '<div class="customer-section">';

    if (ui.error) {
      html += '<div class="error-banner customer-error">' + escapeHtml(ui.error) + '</div>';
    }

    if (state.customer.companyId && state.customer.contactId) {
      html += '<div class="customer-resolved">' +
        '<div class="customer-resolved-name">' + escapeHtml(state.customer.contactName) + '</div>' +
        '<div class="customer-resolved-company">' + escapeHtml(state.customer.companyName) + '</div>' +
        '<button type="button" class="customer-change-btn" data-action="customer-change">Change</button>' +
      '</div>';
      if (state.customer.invoicingWarning) {
        html += '<div class="error-banner customer-warning">' + escapeHtml(state.customer.invoicingWarning) + '</div>';
      }
      html += '</div>';
      return html;
    }

    if (ui.mode === 'new-company') {
      html += customerNewCompanyFormHtml();
      html += '</div>';
      return html;
    }

    if (ui.mode === 'new-contact') {
      html += customerNewContactFormHtml();
      html += '</div>';
      return html;
    }

    if (ui.mode === 'contact') {
      // Company already chosen (recalled) -- pick or add a Contact under it.
      html += '<div class="customer-context-bar">' +
        '<span>' + escapeHtml(state.customer.companyName) + '</span>' +
        '<button type="button" class="customer-back-btn" data-action="customer-back-to-search">‹ Different company</button>' +
      '</div>';
      html += '<input type="text" id="customerContactSearchInput" class="customer-search-input" data-action="customer-contact-search-input" ' +
        'placeholder="Search contacts at this company…" value="' + escapeHtml(ui.query) + '">';
      html += customerResultsListHtml(null, ui.contactResults);
      html += '<button type="button" class="customer-add-btn" data-action="customer-new-contact-open">+ New Contact</button>';
      html += '</div>';
      return html;
    }

    // 'search' -- combined Company + Contact search.
    html += '<input type="text" id="customerSearchInput" class="customer-search-input" data-action="customer-search-input" ' +
      'placeholder="Search company or contact name…" value="' + escapeHtml(ui.query) + '">';
    if (ui.loading) {
      html += '<div class="customer-search-loading">Searching…</div>';
    } else if (ui.query.trim().length >= 2) {
      html += customerResultsListHtml(ui.companyResults, ui.contactResults);
    }
    html += '<button type="button" class="customer-add-btn" data-action="customer-new-company-open">+ New Company</button>';
    html += '</div>';
    return html;
  }

  // Renders search results as a flat list. companies may be null (the
  // company-scoped contact search only shows contacts).
  function customerResultsListHtml(companies, contacts) {
    var hasCompanies = companies && companies.length > 0;
    var hasContacts = contacts && contacts.length > 0;
    if (!hasCompanies && !hasContacts) {
      return '<div class="customer-no-results">No matches. Try a different name, or add a new one below.</div>';
    }
    var html = '<div class="customer-results">';
    if (hasCompanies) {
      companies.forEach(function (c, i) {
        html += '<button type="button" class="customer-result" data-action="customer-select-company" data-index="' + i + '">' +
          '<span class="customer-result-type">Company</span>' +
          '<span class="customer-result-name">' + escapeHtml(c.name) + '</span>' +
          (c.city || c.state ? '<span class="customer-result-sub">' + escapeHtml([c.city, c.state].filter(Boolean).join(', ')) + '</span>' : '') +
        '</button>';
      });
    }
    if (hasContacts) {
      contacts.forEach(function (c, i) {
        var companyName = (c.company && c.company.name) || '';
        html += '<button type="button" class="customer-result" data-action="customer-select-contact" data-index="' + i + '">' +
          '<span class="customer-result-type">Contact</span>' +
          '<span class="customer-result-name">' + escapeHtml(contactDisplayName(c)) + '</span>' +
          (companyName ? '<span class="customer-result-sub">' + escapeHtml(companyName) + '</span>' : '') +
        '</button>';
      });
    }
    html += '</div>';
    return html;
  }

  function customerNewCompanyFormHtml() {
    var f = state.customerUi.newCompanyForm;
    var submitting = state.customerUi.newCompanySubmitting;
    return (
      '<div class="customer-form">' +
        '<div class="customer-form-title">New Company</div>' +
        '<label>Company Name</label>' +
        '<input type="text" data-action="customer-new-company-field" data-field="name" value="' + escapeHtml(f.name) + '">' +
        '<label>Phone</label>' +
        '<input type="text" data-action="customer-new-company-field" data-field="phone" value="' + escapeHtml(f.phone) + '">' +
        '<label>Address Line 1</label>' +
        '<input type="text" data-action="customer-new-company-field" data-field="address_line1" value="' + escapeHtml(f.address_line1) + '">' +
        '<label>Address Line 2</label>' +
        '<input type="text" data-action="customer-new-company-field" data-field="address_line2" value="' + escapeHtml(f.address_line2) + '">' +
        '<div class="customer-form-row">' +
          '<div><label>City</label><input type="text" data-action="customer-new-company-field" data-field="city" value="' + escapeHtml(f.city) + '"></div>' +
          '<div><label>State</label><input type="text" data-action="customer-new-company-field" data-field="state" value="' + escapeHtml(f.state) + '"></div>' +
          '<div><label>Zip</label><input type="text" data-action="customer-new-company-field" data-field="zip" value="' + escapeHtml(f.zip) + '"></div>' +
        '</div>' +
        '<div class="customer-form-actions">' +
          '<button type="button" class="customer-back-btn" data-action="customer-back-to-search">Cancel</button>' +
          '<button type="button" class="customer-save-btn" data-action="customer-new-company-submit" ' + (submitting ? 'disabled' : '') + '>' +
            (submitting ? 'Creating…' : 'Create Company') +
          '</button>' +
        '</div>' +
      '</div>'
    );
  }

  function customerNewContactFormHtml() {
    var f = state.customerUi.newContactForm;
    var submitting = state.customerUi.newContactSubmitting;
    return (
      '<div class="customer-form">' +
        '<div class="customer-form-title">New Contact — ' + escapeHtml(state.customer.companyName) + '</div>' +
        '<div class="customer-form-row">' +
          '<div><label>First Name</label><input type="text" data-action="customer-new-contact-field" data-field="first_name" value="' + escapeHtml(f.first_name) + '"></div>' +
          '<div><label>Last Name</label><input type="text" data-action="customer-new-contact-field" data-field="last_name" value="' + escapeHtml(f.last_name) + '"></div>' +
        '</div>' +
        '<label>Phone</label>' +
        '<input type="text" data-action="customer-new-contact-field" data-field="phone" value="' + escapeHtml(f.phone) + '">' +
        '<label>Email</label>' +
        '<input type="text" data-action="customer-new-contact-field" data-field="email" value="' + escapeHtml(f.email) + '">' +
        '<div class="customer-form-actions">' +
          (state.customer.isNewCompany ? '' : '<button type="button" class="customer-back-btn" data-action="customer-back-to-contact">Cancel</button>') +
          '<button type="button" class="customer-save-btn" data-action="customer-new-contact-submit" ' + (submitting ? 'disabled' : '') + '>' +
            (submitting ? 'Creating…' : 'Create Contact') +
          '</button>' +
        '</div>' +
      '</div>'
    );
  }

  // Formats the Company/Contact stored on a sale for the receipt/history --
  // falls back to the old free-text customer_name for sales recorded
  // before the 2026-09-14 ConnectWise Company/Contact checkout change.
  function customerLineHtml(sale) {
    if (sale.cw_contact_name) {
      return escapeHtml(sale.cw_contact_name) + (sale.cw_company_name ? ' — ' + escapeHtml(sale.cw_company_name) : '');
    }
    if (sale.cw_company_name) return escapeHtml(sale.cw_company_name);
    if (sale.customer_name) return escapeHtml(sale.customer_name);
    return '';
  }

  function receiptOverlayHtml() {
    if (!state.receipt) return '';
    var r = state.receipt;
    var itemsHtml = r.items.map(function (item) {
      return '<div class="receipt-line">' +
        '<span>' + fmtQty(item.quantity) + ' × ' + escapeHtml(item.identifier) + '</span>' +
        '<span>' + fmtMoney(item.line_total) + '</span>' +
      '</div>';
    }).join('');

    return (
      '<div class="modal-backdrop" data-action="close-receipt-backdrop">' +
        '<div class="modal receipt-modal" data-stop-propagation="1">' +
          '<div id="printableReceipt" class="receipt">' +
            '<div class="receipt-header">' +
              '<div class="receipt-brand">CodeBlue Technology</div>' +
              '<div class="receipt-sub">Retail Sale Receipt</div>' +
              '<div class="receipt-meta">Sale #' + r.id + ' — ' + fmtTimestamp(r.created_at) + '</div>' +
              '<div class="receipt-meta">Rung up by ' + escapeHtml(r.cashier_name) + '</div>' +
              (customerLineHtml(r) ? '<div class="receipt-meta">Customer: ' + customerLineHtml(r) + '</div>' : '') +
            '</div>' +
            '<div class="receipt-items">' + itemsHtml + '</div>' +
            '<div class="receipt-totals">' +
              '<div class="receipt-line"><span>Subtotal</span><span>' + fmtMoney(r.subtotal) + '</span></div>' +
              '<div class="receipt-line"><span>Tax</span><span>' + fmtMoney(r.tax_amount) + '</span></div>' +
              '<div class="receipt-line total"><span>Total</span><span>' + fmtMoney(r.total) + '</span></div>' +
              '<div class="receipt-line"><span>Payment</span><span>' + escapeHtml(r.payment_method) + (r.payment_reference ? ' (' + escapeHtml(r.payment_reference) + ')' : '') + '</span></div>' +
            '</div>' +
            (r.note ? '<div class="receipt-note">' + escapeHtml(r.note) + '</div>' : '') +
            '<div class="receipt-footer">Thank you!</div>' +
          '</div>' +
          '<div class="modal-actions no-print">' +
            '<button type="button" class="modal-cancel" data-action="close-receipt">Close</button>' +
            '<button type="button" class="modal-confirm" data-action="print-receipt">Print Receipt</button>' +
          '</div>' +
        '</div>' +
      '</div>'
    );
  }

  function historyHtml() {
    if (state.historyLoading) {
      return '<div class="loading">Loading sale history…</div>';
    }
    if (!state.history) {
      return '<div class="loading">Loading…</div>';
    }
    if (state.history.length === 0) {
      return '<div class="empty-state">No sales recorded yet.</div>';
    }
    var html = '<div class="history-table-wrap"><table class="history-table"><thead><tr>' +
      '<th>Sale #</th><th>Date</th><th>Customer</th><th>Items</th><th>Payment</th><th>Cashier</th><th>Total</th>' +
    '</tr></thead><tbody>';
    state.history.forEach(function (s) {
      html += '<tr class="history-row" data-action="view-receipt" data-id="' + s.id + '">' +
        '<td>#' + s.id + '</td>' +
        '<td>' + fmtTimestamp(s.created_at) + '</td>' +
        '<td>' + (customerLineHtml(s) || '—') + '</td>' +
        '<td>' + s.item_count + '</td>' +
        '<td>' + escapeHtml(s.payment_method) + '</td>' +
        '<td>' + escapeHtml(s.cashier_name) + '</td>' +
        '<td class="history-total">' + fmtMoney(s.total) + '</td>' +
      '</tr>';
    });
    html += '</tbody></table></div>';
    return html;
  }

  // ---- Event binding ----------------------------------------------------

  function bindEvents() {
    var searchInput = document.getElementById('catalogSearchInput');
    if (searchInput) {
      searchInput.addEventListener('input', function () {
        state.search = searchInput.value;
        // A scan or a typed search always searches the WHOLE catalog --
        // added 2026-09-14 so a rep browsing "Voice over IP" who then
        // scans an unrelated part's barcode doesn't get a confusing
        // "No items found" from a stale type/category filter. Browsing
        // the menu chips is unaffected (they don't touch the search box).
        if (searchInput.value.trim() && (state.filterType || state.filterCategory || state.filterSubcategory)) {
          state.filterType = '';
          state.filterCategory = '';
          state.filterSubcategory = '';
        }
        loadCatalog();
      });
    }

    var inStockToggle = document.getElementById('inStockOnlyToggle');
    if (inStockToggle) {
      inStockToggle.addEventListener('change', function () {
        state.inStockOnly = inStockToggle.checked;
        loadCatalogNow();
      });
    }

    root.querySelectorAll('[data-action]').forEach(function (el) {
      var action = el.dataset.action;
      var handler = null;

      if (action === 'show-register') handler = function () { state.view = 'register'; state.error = null; render(); };
      else if (action === 'show-history') handler = function () { state.view = 'history'; state.error = null; loadHistory(); };
      else if (action === 'signout') handler = signOut;
      else if (action === 'sync') handler = runSync;
      else if (action === 'add-to-cart') handler = function () { addToCart(el.dataset.id); };
      else if (action === 'remove-from-cart') handler = function () { removeFromCart(el.dataset.id); };
      else if (action === 'qty-inc') handler = function () {
        var line = state.cart.filter(function (c) { return c.catalog_item_id === Number(el.dataset.id); })[0];
        if (line) setCartQty(el.dataset.id, line.quantity + 1);
      };
      else if (action === 'qty-dec') handler = function () {
        var line = state.cart.filter(function (c) { return c.catalog_item_id === Number(el.dataset.id); })[0];
        if (line) setCartQty(el.dataset.id, line.quantity - 1);
      };
      else if (action === 'clear-cart') handler = function () { state.cart = []; render(); };
      else if (action === 'select-type') handler = function () {
        // Picking a different Type invalidates whatever Category/
        // SubCategory was selected under the old one.
        state.filterType = el.dataset.value;
        state.filterCategory = '';
        state.filterSubcategory = '';
        loadCatalogNow();
      };
      else if (action === 'select-category') handler = function () {
        state.filterCategory = el.dataset.value;
        state.filterSubcategory = '';
        loadCatalogNow();
      };
      else if (action === 'select-subcategory') handler = function () {
        state.filterSubcategory = el.dataset.value;
        loadCatalogNow();
      };
      else if (action === 'open-checkout') handler = openCheckout;
      else if (action === 'close-checkout') handler = closeCheckout;
      else if (action === 'close-checkout-backdrop') handler = closeCheckout;
      else if (action === 'submit-checkout') handler = submitCheckout;
      else if (action === 'payment-method-select') { /* bound below via change */ }
      else if (action === 'customer-select-company') handler = function () {
        var c = state.customerUi.companyResults[Number(el.dataset.index)];
        if (c) selectCompany(c.id, c.name);
      };
      else if (action === 'customer-select-contact') handler = function () {
        var c = state.customerUi.contactResults[Number(el.dataset.index)];
        if (c) selectContact(c);
      };
      else if (action === 'customer-new-company-open') handler = openNewCompanyForm;
      else if (action === 'customer-new-company-submit') handler = submitNewCompany;
      else if (action === 'customer-new-contact-open') handler = openNewContactForm;
      else if (action === 'customer-new-contact-submit') handler = submitNewContact;
      else if (action === 'customer-back-to-search') handler = backToCompanySearch;
      else if (action === 'customer-back-to-contact') handler = backToContactStep;
      else if (action === 'customer-change') handler = changeCustomer;
      else if (action === 'close-receipt') handler = function () { state.receipt = null; state.checkoutForm = { payment_method: 'cash', payment_reference: '', tax_amount: '0.00', note: '' }; resetCustomerState(); render(); };
      else if (action === 'close-receipt-backdrop') handler = function () { state.receipt = null; state.checkoutForm = { payment_method: 'cash', payment_reference: '', tax_amount: '0.00', note: '' }; resetCustomerState(); render(); };
      else if (action === 'print-receipt') handler = function () { window.print(); };
      else if (action === 'view-receipt') handler = function () { viewPastReceipt(el.dataset.id); };

      if (handler) {
        var evt = (el.tagName === 'INPUT' && action === 'qty-input') ? 'change' : 'click';
        el.addEventListener(evt, function (e) {
          if (el.dataset.stopPropagation) e.stopPropagation();
          handler();
        });
      }
    });

    // Stop backdrop clicks from closing the modal when the click originated
    // inside the modal card itself.
    root.querySelectorAll('[data-stop-propagation]').forEach(function (el) {
      el.addEventListener('click', function (e) { e.stopPropagation(); });
    });

    var qtyInput = root.querySelector('[data-action="qty-input"]');
    root.querySelectorAll('input[data-action="qty-input"]').forEach(function (el) {
      el.addEventListener('change', function () { setCartQty(el.dataset.id, el.value); });
    });

    var taxInput = root.querySelector('[data-action="tax-input"]');
    if (taxInput) {
      taxInput.addEventListener('input', function () {
        state.checkoutForm.tax_amount = taxInput.value;
        render();
      });
    }
    var paymentSelect = root.querySelector('[data-action="payment-method-select"]');
    if (paymentSelect) {
      paymentSelect.addEventListener('change', function () {
        state.checkoutForm.payment_method = paymentSelect.value;
      });
    }
    var paymentRefInput = root.querySelector('[data-action="payment-reference-input"]');
    if (paymentRefInput) {
      paymentRefInput.addEventListener('input', function () { state.checkoutForm.payment_reference = paymentRefInput.value; });
    }
    var customerSearchInput = root.querySelector('[data-action="customer-search-input"]');
    if (customerSearchInput) {
      customerSearchInput.addEventListener('input', function () { searchCustomers(customerSearchInput.value); });
    }
    var customerContactSearchInput = root.querySelector('[data-action="customer-contact-search-input"]');
    if (customerContactSearchInput) {
      customerContactSearchInput.addEventListener('input', function () { searchCompanyContacts(customerContactSearchInput.value); });
    }
    root.querySelectorAll('[data-action="customer-new-company-field"]').forEach(function (el) {
      el.addEventListener('input', function () { state.customerUi.newCompanyForm[el.dataset.field] = el.value; });
    });
    root.querySelectorAll('[data-action="customer-new-contact-field"]').forEach(function (el) {
      el.addEventListener('input', function () { state.customerUi.newContactForm[el.dataset.field] = el.value; });
    });
    var noteInput = root.querySelector('[data-action="note-input"]');
    if (noteInput) {
      noteInput.addEventListener('input', function () { state.checkoutForm.note = noteInput.value; });
    }
  }

  boot();
})();
