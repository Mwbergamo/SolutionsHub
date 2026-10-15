#!/usr/bin/env python3
path = "app.js"
with open(path, "r", encoding="utf-8") as f:
    src = f.read()
orig = src

# ---------------------------------------------------------------------------
# 1. render(): re-sync favorites.js's star button(s) after every re-render,
#    since root.innerHTML replacement wipes out its previously-rendered
#    button content same as it wipes everything else.
# ---------------------------------------------------------------------------
old1 = """    root.innerHTML = topbarHtml() + '<div class="main">' + mainHtml() + '</div>';
    bindEvents();
    restoreSearchFocus(searchFocus);"""
assert src.count(old1) == 1, "old1 count=%d" % src.count(old1)
new1 = """    root.innerHTML = topbarHtml() + '<div class="main">' + mainHtml() + '</div>';
    bindEvents();
    // Re-render the "Add to Favorites" star button(s) (favorites.js, added
    // 2026-10-15) -- root.innerHTML just wiped out whatever it rendered
    // into any [data-favorite-mount] spans, same as it wipes everything
    // else. Guarded in case favorites.js hasn't finished its own
    // DOMContentLoaded setup yet (harmless no-op on that first render).
    if (window.CBTFavorites) window.CBTFavorites.refreshButtons();
    restoreSearchFocus(searchFocus);"""
src = src.replace(old1, new1, 1)

# ---------------------------------------------------------------------------
# 2. Customer dashboard header: favorite-star mount, deep-linking via the
#    existing ?cw_company= param (openFromLink()) -- only when the customer
#    has a real ConnectWise id, same gate Open Balance Report already uses.
# ---------------------------------------------------------------------------
old2 = """      '<div class="customer-header-right">' +
        '<button class="print-summary-btn" type="button" data-action="open-print-summary">Print Service Summary</button>' +"""
assert src.count(old2) == 1, "old2 count=%d" % src.count(old2)
new2 = """      '<div class="customer-header-right">' +
        (detail.customer.connectwise_id ? '<span data-favorite-mount data-favorite-app="relationships" data-favorite-label="' + escapeHtml(detail.customer.name) + '" data-favorite-url="relationships/?cw_company=' + encodeURIComponent(detail.customer.connectwise_id) + '"></span>' : '') +
        '<button class="print-summary-btn" type="button" data-action="open-print-summary">Print Service Summary</button>' +"""
src = src.replace(old2, new2, 1)

assert src != orig, "no changes made"
with open(path, "w", encoding="utf-8") as f:
    f.write(src)
print("app.js updated (favorites mount + refreshButtons)")
