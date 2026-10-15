#!/usr/bin/env python3
path = "assets.css"
with open(path, "r", encoding="utf-8") as f:
    src = f.read()
orig = src

old = "@media print { .as-assign { display: none; } }"
assert src.count(old) == 1, "anchor count=%d" % src.count(old)

new = old + """

/* Users & Assigned Services grid (added 2026-10-15 per Michael) -- a spreadsheet-style,
   row-per-person view under the web graph, with a column per service (Computer, Microsoft
   License, Software, plus any configurable manual columns). */
.as-matrix-card { overflow-x: auto; }
.as-matrix-head { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 10px; }
.as-matrix-head h2 { margin: 0; }
.as-matrix { width: 100%; border-collapse: collapse; font-size: 13px; }
.as-matrix th, .as-matrix td { border: 1px solid var(--line); padding: 8px 10px; text-align: left; vertical-align: top; }
.as-matrix thead th { background: var(--band); font-size: 12px; color: var(--ink); white-space: nowrap; }
.as-matrix tbody tr:nth-child(even) { background: #fafbfd; }
.as-matrix tbody tr.as-row-inactive { opacity: .55; }
.as-person-cell b { display: block; }
.as-person-cell small { color: var(--muted); }
.as-cell select, .as-cell input[type=text] { font: inherit; font-size: 12.5px; padding: 4px 6px; border: 1px solid var(--line); border-radius: 6px; background: #fff; max-width: 180px; width: 100%; }
.as-cell small.as-hint { display: block; margin-top: 3px; }
.as-col-remove { border: 0; background: none; color: var(--muted); cursor: pointer; font-size: 13px; padding: 0 0 0 4px; }
.as-col-remove:hover { color: var(--red); }
.as-status-pill { display: inline-block; border-radius: 999px; padding: 2px 9px; font-size: 11px; font-weight: 700; background: #dff4e7; color: #17703c; }
.as-status-pill.inactive { background: #ece9e4; color: #6b6458; }
.as-stat-click { cursor: pointer; }
.as-stat-click:hover { border-color: var(--blue); }
.as-col-flash { background: #fff6da !important; transition: background 1.4s ease; }
.as-node.inactive circle.p { opacity: .5; }
.as-node.inactive text.nm { opacity: .6; }
@media print {
  .as-matrix-head button, .as-col-remove, .as-cell button.as-link { display: none; }
  .as-matrix select, .as-matrix input[type=text] { border: none; background: none; padding: 0; }
}
"""
assert src.count(old) == 1
src = src.replace(old, new, 1)
assert src != orig
with open(path, "w", encoding="utf-8") as f:
    f.write(src)
print("assets.css updated: Users & Assigned Services grid styles")
