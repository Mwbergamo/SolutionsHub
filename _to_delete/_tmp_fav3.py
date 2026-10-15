#!/usr/bin/env python3
path = "styles.css"
with open(path, "r", encoding="utf-8") as f:
    src = f.read()
orig = src

old = """.change-customer-btn {
  background: transparent;
  border: none;
  color: #6cb3ff;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  padding: 6px 0;
}
"""
assert src.count(old) == 1, "anchor count=%d" % src.count(old)
new = old + """
/* "Add to Favorites" star button (favorites.js, added 2026-10-15) -- mounts
   into [data-favorite-mount] spans; same pill shape as the other header
   buttons it sits next to. */
.favorite-star-btn {
  background: transparent;
  border: 1px solid oklch(0.34 0.02 255);
  color: oklch(0.7 0.02 255);
  font-size: 12.5px;
  font-weight: 600;
  padding: 7px 14px;
  border-radius: 999px;
  cursor: pointer;
}
.favorite-star-btn:hover { border-color: #e3b04b; color: #e3b04b; }
.favorite-star-btn.active { background: rgba(227, 176, 75, 0.14); border-color: #e3b04b; color: #e3b04b; }
.favorite-star-btn:disabled { opacity: .6; cursor: default; }
"""
src = src.replace(old, new, 1)
assert src != orig
with open(path, "w", encoding="utf-8") as f:
    f.write(src)

# This file is CRLF in the repo (unlike root's styles.css being handled
# separately) -- the plain text-mode read/write above silently drops to LF
# on write, so normalize the whole file back to CRLF before finishing (same
# fix applied earlier this session for the general_todos CSS addition).
with open(path, "rb") as f:
    data = f.read()
data = data.replace(b"\r\n", b"\n").replace(b"\n", b"\r\n")
with open(path, "wb") as f:
    f.write(data)

print("styles.css updated (favorite-star-btn) + CRLF normalized")
