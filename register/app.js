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

    syncing: false,
    syncMessage: null,
    syncTotal: 0,
    syncProcessed: 0,

    // cart: [{ catalog_item_id, identifier, description, unit_price, quantity, on_hand }]
    cart: [],

    checkoutOpen: false,
    checkoutSubmitting: false,
    checkoutError: null,
    checkoutForm: { payment_method: 'cash', payment_reference: '', tax_amount: '0.00', customer_name: '', note: '' },

    // Set right after a successful checkout (or when reopening one from
    // History) -- the receipt overlay shows whenever this is non-null.
    receipt: null,

    history: null,
    historyLoading: false
  };

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
    }).catch(function () {
      window.location.href = 'login.html?next=' + encodeURIComponent('index.html');
    });
  }

  var searchDebounce = null;
  function loadCatalog() {
    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(function () {
      state.catalogLoading = true;
      state.catalogError = null;
      render();
      var url = 'api/catalog.php?action=list';
      if (state.search.trim()) url += '&q=' + encodeURIComponent(state.search.trim());
      if (state.inStockOnly) url += '&in_stock_only=1';
      apiGet(url).then(function (r) {
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

  // Catalog sync is a queue-based start()/step() pair (added 2026-09-12
  // after a real "Sync failed — check your connection" error: a single
  // synchronous request doing one ConnectWise round-trip per catalog item
  // ran ~4 minutes before failing, almost certainly Bluehost's execution-
  // time limit). Same start-once/step-repeatedly shape as relationships/
  // app.js's runFullSync()/stepSyncLoop() -- each step processes a bounded
  // batch and reports progress, so no single HTTP request risks timing out
  // no matter how large the catalog grows.
  function runSync() {
    state.syncing = true;
    state.syncMessage = 'Starting sync…';
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
      state.syncTotal = r.data.total;
      state.syncMessage = 'Syncing 0 of ' + state.syncTotal + '…';
      render();
      syncStepLoop();
    }).catch(function () {
      state.syncing = false;
      state.syncMessage = null;
      state.error = 'Could not start the sync — check your connection and try again.';
      render();
    });
  }

  function syncStepLoop() {
    apiPost('api/catalog.php?action=sync-step', { batch_size: 15 }).then(function (r) {
      if (!r.data || !r.data.ok) {
        state.syncing = false;
        state.syncMessage = null;
        state.error = (r.data && r.data.error) || 'Sync failed partway through.';
        render();
        return;
      }
      state.syncProcessed = r.data.totals.done + r.data.totals.error;
      state.syncMessage = 'Syncing ' + state.syncProcessed + ' of ' + state.syncTotal + '…';
      if (r.data.done) {
        state.syncing = false;
        state.syncMessage = 'Synced ' + r.data.totals.done + ' item' + (r.data.totals.done === 1 ? '' : 's') +
          (r.data.totals.error ? (' (' + r.data.totals.error + ' failed)') : '') + ' from ConnectWise.';
        loadCatalogNow();
      } else {
        render();
        syncStepLoop();
      }
      render();
    }).catch(function () {
      state.syncing = false;
      state.syncMessage = null;
      state.error = 'Sync failed partway through — check your connection and try again.';
      render();
    });
  }

  // Immediate (non-debounced) catalog reload, used right after a sync or a
  // checkout so the list/on-hand counts reflect what just happened.
  function loadCatalogNow() {
    var url = 'api/catalog.php?action=list';
    if (state.search.trim()) url += '&q=' + encodeURIComponent(state.search.trim());
    if (state.inStockOnly) url += '&in_stock_only=1';
    apiGet(url).then(function (r) {
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
    if (currentQty + 1 > item.on_hand) {
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
        on_hand: item.on_hand
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
    if (qty > line.on_hand) {
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
    state.checkoutForm = { payment_method: 'cash', payment_reference: '', tax_amount: '0.00', customer_name: '', note: '' };
    state.checkoutError = null;
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

    state.checkoutSubmitting = true;
    state.checkoutError = null;
    render();

    apiPost('api/checkout.php?action=create', {
      items: state.cart.map(function (c) { return { catalog_item_id: c.catalog_item_id, quantity: c.quantity }; }),
      payment_method: form.payment_method,
      payment_reference: form.payment_reference,
      tax_amount: taxAmount,
      customer_name: form.customer_name,
      note: form.note
    }).then(function (r) {
      state.checkoutSubmitting = false;
      if (r.data && r.data.ok) {
        state.checkoutOpen = false;
        state.cart = [];
        state.receipt = r.data.sale;
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

  function captureSearchFocus() {
    var el = document.getElementById('catalogSearchInput');
    if (el && document.activeElement === el) {
      return { start: el.selectionStart, end: el.selectionEnd };
    }
    return null;
  }

  function restoreSearchFocus(focusInfo) {
    if (!focusInfo) return;
    var el = document.getElementById('catalogSearchInput');
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
        '<div class="catalog-pane">' + catalogToolbarHtml() + catalogGridHtml() + '</div>' +
        '<div class="cart-pane">' + cartHtml() + '</div>' +
      '</div>'
    );
  }

  function catalogToolbarHtml() {
    return (
      '<div class="catalog-toolbar">' +
        '<input id="catalogSearchInput" type="text" placeholder="Search products…" value="' + escapeHtml(state.search) + '">' +
        '<label class="in-stock-toggle"><input type="checkbox" id="inStockOnlyToggle" ' + (state.inStockOnly ? 'checked' : '') + '> In stock only</label>' +
        '<button type="button" class="sync-btn" data-action="sync" ' + (state.syncing ? 'disabled' : '') + '>' +
          (state.syncing ? 'Syncing…' : '↻ Sync from ConnectWise') +
        '</button>' +
        (state.syncMessage ? '<span class="sync-message">' + escapeHtml(state.syncMessage) + '</span>' : '') +
      '</div>'
    );
  }

  function catalogGridHtml() {
    if (state.catalogLoading && state.catalogItems.length === 0) {
      return '<div class="loading">Loading catalog…</div>';
    }
    if (state.catalogError) {
      return '<div class="error-banner">' + escapeHtml(state.catalogError) + '</div>';
    }
    if (state.catalogItems.length === 0) {
      return '<div class="empty-state">No items found. Try "Sync from ConnectWise" if the catalog looks empty or out of date.</div>';
    }
    var html = '<div class="catalog-grid">';
    state.catalogItems.forEach(function (item) {
      var outOfStock = item.on_hand <= 0;
      html += '<div class="catalog-card' + (outOfStock ? ' out-of-stock' : '') + '" ' + (outOfStock ? '' : 'data-action="add-to-cart" data-id="' + item.id + '"') + '>' +
        '<div class="catalog-card-name">' + escapeHtml(item.identifier) + '</div>' +
        (item.description ? '<div class="catalog-card-desc">' + escapeHtml(item.description) + '</div>' : '') +
        '<div class="catalog-card-footer">' +
          '<span class="catalog-card-price">' + fmtMoney(item.price) + '</span>' +
          '<span class="catalog-card-stock' + (outOfStock ? ' zero' : '') + '">' + (outOfStock ? 'Out of stock' : fmtQty(item.on_hand) + ' on hand') + '</span>' +
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
          '<label>Payment Method</label>' +
          '<select data-action="payment-method-select">' +
            ['cash', 'card', 'check', 'other'].map(function (m) {
              return '<option value="' + m + '"' + (f.payment_method === m ? ' selected' : '') + '>' + m.charAt(0).toUpperCase() + m.slice(1) + '</option>';
            }).join('') +
          '</select>' +
          '<label>Reference (optional — last 4, check #, etc.)</label>' +
          '<input type="text" data-action="payment-reference-input" value="' + escapeHtml(f.payment_reference) + '">' +
          '<label>Customer Name (optional)</label>' +
          '<input type="text" data-action="customer-name-input" value="' + escapeHtml(f.customer_name) + '">' +
          '<label>Note (optional)</label>' +
          '<input type="text" data-action="note-input" value="' + escapeHtml(f.note) + '">' +
          '<div class="modal-actions">' +
            '<button type="button" class="modal-cancel" data-action="close-checkout">Cancel</button>' +
            '<button type="button" class="modal-confirm" data-action="submit-checkout" ' + (state.checkoutSubmitting ? 'disabled' : '') + '>' +
              (state.checkoutSubmitting ? 'Recording Sale…' : 'Record Payment & Complete') +
            '</button>' +
          '</div>' +
        '</div>' +
      '</div>'
    );
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
              (r.customer_name ? '<div class="receipt-meta">Customer: ' + escapeHtml(r.customer_name) + '</div>' : '') +
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
        '<td>' + escapeHtml(s.customer_name || '—') + '</td>' +
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
      else if (action === 'open-checkout') handler = openCheckout;
      else if (action === 'close-checkout') handler = closeCheckout;
      else if (action === 'close-checkout-backdrop') handler = closeCheckout;
      else if (action === 'submit-checkout') handler = submitCheckout;
      else if (action === 'payment-method-select') { /* bound below via change */ }
      else if (action === 'close-receipt') handler = function () { state.receipt = null; state.checkoutForm = { payment_method: 'cash', payment_reference: '', tax_amount: '0.00', customer_name: '', note: '' }; render(); };
      else if (action === 'close-receipt-backdrop') handler = function () { state.receipt = null; state.checkoutForm = { payment_method: 'cash', payment_reference: '', tax_amount: '0.00', customer_name: '', note: '' }; render(); };
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
    var customerNameInput = root.querySelector('[data-action="customer-name-input"]');
    if (customerNameInput) {
      customerNameInput.addEventListener('input', function () { state.checkoutForm.customer_name = customerNameInput.value; });
    }
    var noteInput = root.querySelector('[data-action="note-input"]');
    if (noteInput) {
      noteInput.addEventListener('input', function () { state.checkoutForm.note = noteInput.value; });
    }
  }

  boot();
})();
