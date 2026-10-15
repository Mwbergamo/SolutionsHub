#!/usr/bin/env python3

# ---------------------------------------------------------------------------
# index.html: include favorites.js, bump app.js cache-bust.
# ---------------------------------------------------------------------------
path = "index.html"
with open(path, "r", encoding="utf-8") as f:
    src = f.read()
orig = src

old = '<script src="../assets/clyde/clyde-season.js?v=1"></script>\n<script src="app.js?v=67"></script>'
assert src.count(old) == 1, "index.html anchor count=%d" % src.count(old)
new = ('<script src="../assets/clyde/clyde-season.js?v=1"></script>\n'
       '<script src="../favorites.js" data-api="api/favorites.php"></script>\n'
       '<script src="app.js?v=68"></script>')
src = src.replace(old, new, 1)
assert src != orig
with open(path, "w", encoding="utf-8") as f:
    f.write(src)
print("index.html updated")
