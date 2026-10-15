#!/usr/bin/env python3
path = "assets.html"
with open(path, "r", encoding="utf-8") as f:
    src = f.read()
orig = src
src = src.replace('assets.css?v=3', 'assets.css?v=4', 1)
src = src.replace('assets.js?v=4', 'assets.js?v=5', 1)
assert src != orig
with open(path, "w", encoding="utf-8") as f:
    f.write(src)
print("assets.html updated: cache-bust bump (css v=4, js v=5)")
