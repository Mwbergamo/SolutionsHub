#!/usr/bin/env python3
path = "styles.css"
with open(path, "r", encoding="utf-8") as f:
    src = f.read()
orig = src

old = """.hub-tile-soon { font-size: 13px; font-weight: 600; padding: 4px 12px; border-radius: 999px; background: rgba(227,176,75,.15); color: #f0c870; }
"""
assert src.count(old) == 1, "anchor count=%d" % src.count(old)
new = old + """
/* Favorites sidebar (added 2026-10-15, per Michael) -- sits to the left of
   the hub-tiles grid on the Overview screen; a rep's starred pages from any
   SolutionsHub app (see favorites.js / relationships/api/favorites.php). */
.favorites-sidebar {
  flex: 0 0 220px;
  background: rgba(255,255,255,.03);
  border: 1px solid rgba(255,255,255,.08);
  border-radius: 18px;
  padding: 18px;
  margin-top: 36px;
}
.favorites-sidebar-title {
  font-family: 'JetBrains Mono', monospace;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--cbt-brand-green, #9dc22c);
  margin-bottom: 12px;
}
.favorites-sidebar-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  padding: 9px 4px;
  border-bottom: 1px solid rgba(255,255,255,.06);
}
.favorites-sidebar-item:last-child { border-bottom: none; }
.favorites-sidebar-link {
  color: var(--cbt-text-on-dark-primary, #eef0f5);
  text-decoration: none;
  font-size: 13.5px;
  font-weight: 600;
  line-height: 1.3;
}
.favorites-sidebar-link:hover { color: #6cb3ff; }
.favorites-sidebar-remove {
  flex: 0 0 auto;
  background: transparent;
  border: none;
  color: var(--cbt-text-on-dark-faint, #8993a8);
  font-size: 16px;
  line-height: 1;
  cursor: pointer;
  padding: 2px 4px;
}
.favorites-sidebar-remove:hover { color: #e0413f; }
"""
src = src.replace(old, new, 1)
assert src != orig
with open(path, "w", encoding="utf-8") as f:
    f.write(src)

with open(path, "rb") as f:
    data = f.read()
data = data.replace(b"\r\n", b"\n").replace(b"\n", b"\r\n")
with open(path, "wb") as f:
    f.write(data)

print("styles.css (root) updated: .favorites-sidebar + CRLF normalized")
