#!/usr/bin/env python3
path = "app.js"
with open(path, "r", encoding="utf-8") as f:
    src = f.read()
orig = src

# ---------------------------------------------------------------------------
# 1. Build the favoritesList view-model array, right alongside the other
#    Overview-tile computed values (scCount etc.), before it's referenced
#    in the returned object below.
# ---------------------------------------------------------------------------
old1 = """    var scCount = itCount + dcCount + voipCount + cablingCount + securityCount;
"""
assert src.count(old1) == 1, "old1 count=%d" % src.count(old1)
new1 = old1 + """
    // Cross-app "Add to Favorites" sidebar (added 2026-10-15 per Michael) --
    // shown on the Overview screen, to the left of the hub-tiles grid. Each
    // row is a real <a href> (the favorited page is very often a different
    // app entirely -- relationships/, register/, ... -- so this is a real
    // navigation, not an in-SPA route) with a small remove (x) button that
    // stays in-page (removeFavorite() above).
    var favoritesList = (this.state.favorites || []).map(function (f) {
      return {
        id: f.id, label: f.label, url: f.url,
        onRemove: function (e) { if (e && e.preventDefault) e.preventDefault(); self.removeFavorite(f.id); }
      };
    });
    var hasFavorites = favoritesList.length > 0;
"""
src = src.replace(old1, new1, 1)

# ---------------------------------------------------------------------------
# 2. Expose favoritesList/hasFavorites on the returned view-model.
# ---------------------------------------------------------------------------
old2 = """      isOverview: view === 'overview',
      isSolutionsCreator: view === 'solutions-creator',"""
assert src.count(old2) == 1, "old2 count=%d" % src.count(old2)
new2 = """      isOverview: view === 'overview',
      isSolutionsCreator: view === 'solutions-creator',
      favoritesList: favoritesList,
      hasFavorites: hasFavorites,"""
src = src.replace(old2, new2, 1)

assert src != orig, "no changes made"
with open(path, "w", encoding="utf-8") as f:
    f.write(src)
print("app.js (root) updated: favoritesList/hasFavorites in renderVals()")
