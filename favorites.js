/*
 * favorites.js -- shared "Add to Favorites" star button, added 2026-10-15 per Michael.
 *
 * Drop-in widget any SolutionsHub app can mount on a page that has a real, stable URL a rep
 * could want to jump straight back to (a customer dashboard, a report, etc.) -- same
 * self-contained-script convention as solution-save.js's "Save to customer" button. Talks to
 * relationships/api/favorites.php (same shared login session every app already shares -- see
 * db.php's favorites table for the full design rationale).
 *
 * Usage, anywhere in a page's HTML:
 *   <script src="../favorites.js" data-api="../relationships/api/favorites.php"></script>
 *   ...
 *   <span data-favorite-mount
 *         data-favorite-app="relationships"
 *         data-favorite-label="Acme Corp"
 *         data-favorite-url="relationships/?cw_company=123"></span>
 *
 * data-api is relative to the HOST PAGE (not this script) -- each app sets it once to wherever
 * relationships/api/favorites.php is from that app's own location. data-favorite-url is what
 * gets saved and what the Overview sidebar's favorite link points at -- normally a RELATIVE path
 * from the SITE ROOT (e.g. "relationships/?cw_company=123"), since the sidebar lives at the root
 * and needs a href that works from there; if left off, falls back to window.location.href (not
 * usually what you want from a subdirectory -- better to pass it explicitly).
 *
 * A page can have more than one [data-favorite-mount] (e.g. several report tabs, each its own
 * favoritable "screen") -- each is wired up independently with its own label/url.
 *
 * Also exposes window.CBTFavorites = { list(), refreshButtons() } for anything that wants the
 * raw favorites list itself (the root Overview sidebar fetches relationships/api/favorites.php
 * directly instead, since it already talks to that same backend for Saved Solutions -- this
 * export is for any OTHER page that wants to show "you have N favorites" or similar without
 * re-implementing the fetch).
 */
(function () {
  'use strict';

  var scriptEl = document.currentScript;
  var API = (scriptEl && scriptEl.getAttribute('data-api')) || 'relationships/api/favorites.php';

  var cache = null; // array of { id, app, label, url, created_at } | null (not loaded yet)
  var loadPromise = null;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function apiGet(url) {
    return fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.json(); });
  }
  function apiPost(url, body) {
    return fetch(url, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body)
    }).then(function (r) { return r.json(); });
  }

  function loadFavorites(force) {
    if (loadPromise && !force) return loadPromise;
    loadPromise = apiGet(API + '?action=list').then(function (r) {
      cache = (r && r.ok && r.favorites) || [];
      return cache;
    }).catch(function () {
      cache = cache || [];
      return cache;
    });
    return loadPromise;
  }

  function isFavorited(url) {
    return (cache || []).some(function (f) { return f.url === url; });
  }

  function renderOneButton(el) {
    var app = el.getAttribute('data-favorite-app') || '';
    var label = el.getAttribute('data-favorite-label') || document.title || '';
    var url = el.getAttribute('data-favorite-url') || window.location.href;
    var fav = isFavorited(url);
    var busy = el.getAttribute('data-favorite-busy') === '1';
    el.innerHTML = '<button type="button" class="favorite-star-btn' + (fav ? ' active' : '') + '" ' +
      (busy ? 'disabled' : '') + ' title="' + (fav ? 'Remove from Favorites' : 'Add to Favorites') + '">' +
      (busy ? '…' : (fav ? '★ Favorited' : '☆ Add to Favorites')) +
    '</button>';
    var btn = el.querySelector('button');
    btn.addEventListener('click', function () {
      el.setAttribute('data-favorite-busy', '1');
      renderOneButton(el);
      var already = isFavorited(url);
      var req = already
        ? apiPost(API + '?action=remove', { url: url })
        : apiPost(API + '?action=add', { app: app, label: label, url: url });
      req.then(function () {
        return loadFavorites(true);
      }).then(function () {
        el.removeAttribute('data-favorite-busy');
        renderOneButton(el);
      }).catch(function () {
        el.removeAttribute('data-favorite-busy');
        renderOneButton(el);
      });
    });
  }

  function renderButtons() {
    var mounts = document.querySelectorAll('[data-favorite-mount]');
    for (var i = 0; i < mounts.length; i++) renderOneButton(mounts[i]);
  }

  function init() {
    loadFavorites().then(renderButtons);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  // Re-render buttons whenever the host page re-renders its own DOM and a
  // mount point comes back with (possibly) a different label/url -- host
  // apps that re-render on navigation (e.g. picking a different customer)
  // should call this after updating the DOM, same way solution-save.js's
  // mount gets re-driven on state changes.
  window.CBTFavorites = {
    list: function () { return loadFavorites(); },
    refreshButtons: renderButtons
  };
})();
