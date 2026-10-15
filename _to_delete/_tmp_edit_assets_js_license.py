#!/usr/bin/env python3
path = "assets.js"
with open(path, "r", encoding="utf-8") as f:
    src = f.read()
orig = src

old1 = "  function licenseOptionNames() { return (data.licenses || []).map(function (l) { return l.name; }); }\n"
assert src.count(old1) == 1, "old1 count=%d" % src.count(old1)
new1 = """  // Microsoft License options, enriched with IT Glue's synced seat counts (Active/Consumed/
  // Unused) so a rep has real context when picking a tier by hand. These counts are ORG-WIDE
  // totals -- IT Glue's "Licensed Users" tag (which would say WHICH person holds a seat) isn't
  // populated for any license, at any customer, as of 2026-10-15 (confirmed live against several
  // real orgs) -- so there is no per-person baseline to show here, only an informed manual pick.
  function licenseOptionsWithCounts() {
    return (data.licenses || []).map(function (l) {
      var big = l.active >= 1000; // treat as "effectively unlimited" (free/trial SKUs often report active:10000)
      return { name: l.name, label: l.name + ' \\u2014 ' + l.consumed + ' used' + (big ? '' : ', ' + l.unused + ' free') };
    });
  }
"""
src = src.replace(old1, new1, 1)

old2 = """  function licenseCell(p) {
    var baseline = p.licenses.map(function (l) { return l.name; }).join(', ');
    var val = overrideValue(p.c.id, 'microsoft_license') || baseline;
    return selectCell(p.c.id, 'microsoft_license', 'Microsoft License', licenseOptionNames(), val, (baseline && baseline !== val) ? 'IT Glue shows: ' + baseline : '');
  }"""
assert src.count(old2) == 1, "old2 count=%d" % src.count(old2)
new2 = """  function licenseCell(p) {
    var val = overrideValue(p.c.id, 'microsoft_license');
    var opts = licenseOptionsWithCounts();
    var optsHtml = ['<option value="">\\u2014 not set \\u2014</option>'].concat(opts.map(function (o) {
      return '<option value="' + esc(o.name) + '"' + (o.name === val ? ' selected' : '') + '>' + esc(o.label) + '</option>';
    }));
    if (val && !opts.some(function (o) { return o.name === val; })) {
      optsHtml.push('<option value="' + esc(val) + '" selected>' + esc(val) + '</option>');
    }
    var hint = opts.length
      ? 'Seat counts are IT Glue\\u2019s synced totals, not tied to this person yet \\u2014 pick the tier that applies.'
      : 'IT Glue has no Microsoft License records synced for this organization yet.';
    return '<div class="as-cell"><select data-override-contact="' + p.c.id + '" data-override-col="microsoft_license" data-override-label="Microsoft License" data-override-prev="' + esc(val) + '" aria-label="Microsoft License for ' + esc(p.c.name) + '">' +
      optsHtml.join('') + '</select><small class="as-hint">' + esc(hint) + '</small></div>';
  }"""
src = src.replace(old2, new2, 1)

assert src != orig
with open(path, "w", encoding="utf-8") as f:
    f.write(src)
print("assets.js updated: Microsoft License dropdown now shows IT Glue's synced seat counts per option")
