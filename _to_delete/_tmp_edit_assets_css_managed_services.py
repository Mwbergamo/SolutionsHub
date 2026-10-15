#!/usr/bin/env python3
path = "assets.css"
with open(path, "r", encoding="utf-8") as f:
    src = f.read()
orig = src

old = """.as-node.inactive circle.p { opacity: .5; }
.as-node.inactive text.nm { opacity: .6; }"""
assert src.count(old) == 1, "anchor count=%d" % src.count(old)

new = old + """

/* Managed Services column (added 2026-10-15 per Michael): real agreement + RMM data, replacing
   the old Software column and the four manual columns (Firewall/VPN/DUO/Guardz). */
.as-svc-device { margin-bottom: 6px; }
.as-svc-device:last-child { margin-bottom: 0; }
.as-svc-device > small { display: block; color: var(--muted); margin-bottom: 2px; }
.as-svc-chips { display: flex; flex-wrap: wrap; gap: 4px; margin-bottom: 4px; }
.as-svc-manual { display: flex; flex-direction: column; gap: 3px; }
.as-svc-toggle { display: flex; align-items: baseline; gap: 5px; font-size: 12.5px; cursor: pointer; }
.as-svc-toggle input { margin: 0; }
.as-svc-toggle small { color: var(--muted); }
.as-svc-banner { background: #fff6da; border-color: #e8d48a; }
"""
assert src.count(old) == 1
src = src.replace(old, new, 1)
assert src != orig
with open(path, "w", encoding="utf-8") as f:
    f.write(src)
print("assets.css updated: Managed Services column + coverage banner styles")
