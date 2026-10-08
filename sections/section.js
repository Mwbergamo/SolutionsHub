/* Shared script for the Hub section pages (finance/, sales/, projects/, retail/).
 * Each page sets window.SECTION = { title, lead, cards: [...] } and loads this file.
 * A card is { num, title, desc, href, go, icon (inner SVG markup), needs (optional flag on /auth/me.php user) }.
 * To add a tool to a section: add a card to that section's index.html, then add a Help Center article + What's New line. */
(function () {
  var S = window.SECTION || { title: '', lead: '', cards: [] };
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function render(user) {
    var cards = S.cards.filter(function (c) { return !c.needs || (user && user[c.needs]); });
    var h = '<div class="swrap"><div class="eyebrow"><i></i><span>CodeBlue Technology &middot; SolutionsHub</span></div>' +
      '<h1>' + esc(S.title) + '</h1><p class="lead">' + esc(S.lead) + '</p>';
    if (!cards.length) {
      h += '<div class="snone">You do not have access to anything in this section yet. If you think you should, ask Michael Bergamo.</div>';
    } else {
      h += '<div class="sgrid">' + cards.map(function (c, i) {
        return '<a class="scard" href="' + esc(c.href) + '"><div><div class="ico"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">' + c.icon + '</svg></div></div>' +
          '<div><span class="num">' + (i < 9 ? '0' : '') + (i + 1) + '</span><div class="ttl">' + esc(c.title) + '</div><div class="dsc">' + esc(c.desc) + '</div></div>' +
          '<div class="go">' + esc(c.go || 'OPEN') + ' &rarr;</div></a>';
      }).join('') + '</div>';
    }
    document.getElementById('sect-root').innerHTML = h + '</div>';
  }
  function toLogin() { location.replace('/login.php?return_to=' + encodeURIComponent(location.pathname + location.search)); }
  document.title = S.title + ' - SolutionsHub';
  fetch('/auth/me.php', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (r) {
    if (!r.ok || !r.user) { toLogin(); return; }
    render(r.user);
  }).catch(toLogin);
})();
