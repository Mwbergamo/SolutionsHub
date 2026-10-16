#!/usr/bin/env python3
path = "relationships/styles.css"
with open(path, "r", encoding="utf-8") as f:
    src = f.read()
orig = src

old = """.outgrow-none { color: oklch(0.72 0.15 27); font-weight: 600; }"""
assert src.count(old) == 1, "anchor count=%d" % src.count(old)

new = old + """

/* Front-page "To-Do List" gauge (gaugesHtml()'s 'todo3' format) -- added
   2026-10-16 per Michael: one gauge-tile-sized card with three
   independently clickable numbers (Overdue / Due Today / Future) instead
   of the single value+hint the other clickable gauges use. Same
   red/amber/green convention as everywhere else in this app (opportunity
   badges, pf-days -- see that comment above): red = needs attention now,
   amber = watch, green = fine. */
.gauge-tile-todo3 { padding: 14px 14px 12px; }
.todo3-row {
  margin-top: 10px;
  display: flex;
  gap: 8px;
}
.todo3-stat {
  flex: 1 1 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 2px;
  padding: 8px 4px 9px;
  font: inherit;
  background: oklch(0.22 0.02 255);
  border: 1px solid oklch(0.28 0.02 255);
  border-radius: 10px;
  cursor: pointer;
  transition: border-color .15s, background .15s;
}
.todo3-stat-value {
  font-family: 'Space Grotesk', sans-serif;
  font-size: 22px;
  font-weight: 700;
  line-height: 1;
}
.todo3-stat-label {
  font-size: 9.5px;
  font-weight: 700;
  letter-spacing: .02em;
  text-transform: uppercase;
  color: oklch(0.6 0.01 255);
}
.todo3-stat-overdue .todo3-stat-value { color: oklch(0.72 0.17 27); }
.todo3-stat-overdue:hover, .todo3-stat-overdue.active { border-color: oklch(0.6 0.18 27); background: oklch(0.24 0.04 27); }
.todo3-stat-due-today .todo3-stat-value { color: oklch(0.78 0.14 80); }
.todo3-stat-due-today:hover, .todo3-stat-due-today.active { border-color: oklch(0.65 0.15 80); background: oklch(0.24 0.04 80); }
.todo3-stat-future .todo3-stat-value { color: oklch(0.75 0.14 155); }
.todo3-stat-future:hover, .todo3-stat-future.active { border-color: oklch(0.6 0.15 155); background: oklch(0.22 0.04 155); }

/* The gauge's own drilldown list (todoOverviewPanelHtml()) -- reuses
   .global-todo-list/.global-todo-item's existing row styling (same look
   as the Global To-Do Checklist panel) so this reads as the same kind of
   list, just scoped to one bucket. */
.todo-overview-panel {
  margin-top: 16px;
  background: oklch(0.19 0.014 255);
  border: 1px solid oklch(0.26 0.02 255);
  border-radius: 16px;
  padding: 14px 16px;
}
.todo-overview-panel-title {
  font-size: 13px;
  font-weight: 700;
  color: oklch(0.85 0.006 255);
  margin-bottom: 4px;
}"""
assert src.count(old) == 1
src = src.replace(old, new, 1)

assert src != orig
with open(path, "w", encoding="utf-8") as f:
    f.write(src)
print("styles.css updated: todo3 gauge + drilldown panel styles added")
