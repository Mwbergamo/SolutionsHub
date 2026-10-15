#!/usr/bin/env python3
path = "app.js"
with open(path, "r", encoding="utf-8") as f:
    src = f.read()
orig = src

# ---------------------------------------------------------------------------
# 1. Constructor: init state.favorites (null = not loaded yet) and kick off
#    the initial load. Placed right after the imagePreview state field, the
#    last entry in the constructor's state object.
# ---------------------------------------------------------------------------
old1 = """      imagePreview: { isOpen: false, url: '', caption: '' }
    };
  }

  openImagePreview(url, caption) {"""
assert src.count(old1) == 1, "old1 count=%d" % src.count(old1)
new1 = """      imagePreview: { isOpen: false, url: '', caption: '' },
      // Cross-app "Add to Favorites" (added 2026-10-15 per Michael) -- see
      // db.php's favorites table (relationships/api/) for the full design.
      // null = not loaded yet (sidebar stays hidden, same "don't show a
      // half-built empty state" convention as activitySummary elsewhere in
      // this codebase); [] once loaded with nothing starred.
      favorites: null
    };
    this.loadFavorites();
  }

  // Fetches this rep's favorited pages from the shared backend
  // (relationships/api/favorites.php -- same login session this app
  // already shares, and the same backend Saved Solutions already talks to
  // via solution-save.js) and shows them in the Overview sidebar.
  loadFavorites() {
    var self = this;
    fetch('relationships/api/favorites.php?action=list', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data && data.ok) self.setState({ favorites: data.favorites });
      })
      .catch(function () { /* silent -- sidebar just stays empty/hidden, same as a failed activitySummary load elsewhere */ });
  }

  // Optimistic local removal (sidebar updates immediately) -- a failed
  // request just gets silently corrected on the next loadFavorites().
  removeFavorite(id) {
    var self = this;
    var prev = this.state.favorites || [];
    this.setState({ favorites: prev.filter(function (f) { return f.id !== id; }) });
    fetch('relationships/api/favorites.php?action=remove', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id: id })
    }).catch(function () {});
  }

  openImagePreview(url, caption) {"""
src = src.replace(old1, new1, 1)

assert src != orig, "no changes made"
with open(path, "w", encoding="utf-8") as f:
    f.write(src)
print("app.js (root) updated: state.favorites + loadFavorites()/removeFavorite()")
