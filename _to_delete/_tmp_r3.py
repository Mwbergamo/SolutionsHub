#!/usr/bin/env python3
path = "index.html"
with open(path, "r", encoding="utf-8") as f:
    src = f.read()
orig = src

old = """        <div class="hub-tiles">"""
assert src.count(old) == 1, "anchor1 count=%d" % src.count(old)
new = """        <div style="display:flex;align-items:flex-start;gap:28px;flex-wrap:wrap;">
        <sc-if value="{{hasFavorites}}" hint-placeholder-val="{{false}}">
        <div class="favorites-sidebar">
          <div class="favorites-sidebar-title">Favorites</div>
          <sc-for list="{{favoritesList}}" as="fav" hint-placeholder-count="2">
            <div class="favorites-sidebar-item">
              <a href="{{fav.url}}" class="favorites-sidebar-link">{{fav.label}}</a>
              <button type="button" class="favorites-sidebar-remove" data-on-click="{{fav.onRemove}}" title="Remove from Favorites">&times;</button>
            </div>
          </sc-for>
        </div>
        </sc-if>
        <div class="hub-tiles">"""
assert src.count(old) == 1
src = src.replace(old, new, 1)

# Close the new wrapping flex row right after .hub-tiles' own closing </div>
# (the one immediately preceding the Overview view-pad's closing </div>) --
# anchored on the exact tail text (Marketing tile + the two blank lines
# that follow it in the source).
old2 = """            <div class="hub-tile-txt"><div class="hub-tile-title">Marketing</div><div class="hub-tile-desc">Internal marketing material for the team.</div></div>
            <svg class="hub-beat" viewBox="0 0 240 30" preserveAspectRatio="none" aria-hidden="true"><path class="base" d="M0 15H78l5-3 5 3H102l4 4 6-17 6 27 5-14H148l5-5 7 5H240"></path><path class="pulse" pathLength="100" d="M0 15H78l5-3 5 3H102l4 4 6-17 6 27 5-14H148l5-5 7 5H240"></path></svg>
          </div>


        </div>
      </div>
    </sc-if>"""
assert src.count(old2) == 1, "anchor2 count=%d" % src.count(old2)
new2 = """            <div class="hub-tile-txt"><div class="hub-tile-title">Marketing</div><div class="hub-tile-desc">Internal marketing material for the team.</div></div>
            <svg class="hub-beat" viewBox="0 0 240 30" preserveAspectRatio="none" aria-hidden="true"><path class="base" d="M0 15H78l5-3 5 3H102l4 4 6-17 6 27 5-14H148l5-5 7 5H240"></path><path class="pulse" pathLength="100" d="M0 15H78l5-3 5 3H102l4 4 6-17 6 27 5-14H148l5-5 7 5H240"></path></svg>
          </div>


        </div>
        </div>
      </div>
    </sc-if>"""
src = src.replace(old2, new2, 1)

assert src != orig
with open(path, "w", encoding="utf-8") as f:
    f.write(src)

# index.html is CRLF in the repo -- normalize back after the plain
# text-mode read/write above (same fix applied to relationships/styles.css
# earlier this session).
with open(path, "rb") as f:
    data = f.read()
data = data.replace(b"\r\n", b"\n").replace(b"\n", b"\r\n")
with open(path, "wb") as f:
    f.write(data)

print("index.html (root) updated: favorites sidebar markup + CRLF normalized")
