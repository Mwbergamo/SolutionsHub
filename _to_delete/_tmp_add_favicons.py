#!/usr/bin/env python3
import re

FILES = [
    "call-experience.html",
    "collections/index.html",
    "collections/login.html",
    "commissions/index.html",
    "commissions/login.html",
    "finance/index.html",
    "help/index.html",
    "index.html",
    "projects/index.html",
    "ratesheet/index.html",
    "ratesheet/login.html",
    "ratesheet/receipt.html",
    "ratesheet/signup.html",
    "register/index.html",
    "register/login.html",
    "relationships/assets.html",
    "relationships/index.html",
    "relationships/login.html",
    "relationships/network.html",
    "retail/index.html",
    "sales/index.html",
    "walk-in/index.html",
]

title_re = re.compile(r"(<title>.*?</title>\r?\n)")
icon_line_re = re.compile(r'<link rel="icon"[^\n]*\n')

changed = []
skipped = []

for rel in FILES:
    with open(rel, "rb") as f:
        raw = f.read()
    crlf = raw.count(b"\r\n")
    lf_only = raw.count(b"\n") - crlf
    eol = "\r\n" if crlf >= lf_only else "\n"

    with open(rel, "r", encoding="utf-8", newline="") as f:
        src = f.read()
    orig = src

    prefix = "../assets/" if "/" in rel else "assets/"
    apple_line = f'<link rel="apple-touch-icon" href="{prefix}apple-touch-icon.png">{eol}'
    favicon_line = f'<link rel="icon" type="image/png" href="{prefix}favicon.png">{eol}'

    if 'rel="apple-touch-icon"' in src:
        skipped.append(rel + " (already has apple-touch-icon)")
        continue

    m = icon_line_re.search(src)
    if m:
        # Already has a favicon link -- add apple-touch-icon right after it.
        insert_at = m.end()
        src = src[:insert_at] + apple_line + src[insert_at:]
    else:
        # No favicon at all -- add both, right after the <title> line.
        tm = title_re.search(src)
        assert tm, f"no <title> found in {rel}"
        insert_at = tm.end()
        src = src[:insert_at] + favicon_line + apple_line + src[insert_at:]

    assert src != orig
    with open(rel, "w", encoding="utf-8", newline="") as f:
        f.write(src)
    changed.append(rel)

print("Changed:")
for c in changed:
    print("  " + c)
print("Skipped:")
for s in skipped:
    print("  " + s)
