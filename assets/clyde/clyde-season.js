/*
 * assets/clyde/clyde-season.js  (added 2026-10-05)
 *
 * Picks which seasonal set of Clyde mascot art to show, by today's date.
 * Shared by the Solutions Hub (index.html) and the Relationships app.
 *
 *   Jan 1  - Feb 28   default Clyde      assets/clyde/clyde-<name>.png
 *   Mar 1  - May 31   spring             assets/clyde/spring/
 *   Jun 1  - Sep 30   summer             assets/clyde/summer/
 *   Oct 1  - Oct 31   halloween          assets/clyde/halloween/
 *   Nov 1  - Dec 31   christmas          assets/clyde/christmas/
 *
 * (Repeats every year.) To preview a season without waiting for it, add
 * ?clyde=halloween (or christmas / spring / summer / default) to the page URL.
 *
 * Usage: ClydeSeason.src('hub', '../assets/clyde/')  ->  '../assets/clyde/halloween/clyde-hub.png'
 * Names: hub, prospecting, projects, todo, cross-sell, solutions-creator.
 */
(function () {
  var SEASONS = ['default', 'spring', 'summer', 'halloween', 'christmas'];

  function seasonForDate(d) {
    var m = d.getMonth(); // 0 = Jan
    if (m <= 1) return 'default';
    if (m <= 4) return 'spring';
    if (m <= 8) return 'summer';
    if (m === 9) return 'halloween';
    return 'christmas'; // Nov, Dec
  }

  function current() {
    try {
      var forced = new URLSearchParams(window.location.search).get('clyde');
      if (forced && SEASONS.indexOf(forced) !== -1) return forced;
    } catch (e) { /* fall through to the date */ }
    return seasonForDate(new Date());
  }

  function src(name, base) {
    var s = current();
    return (base || 'assets/clyde/') + (s === 'default' ? '' : s + '/') + 'clyde-' + name + '.png';
  }

  window.ClydeSeason = { current: current, src: src, forDate: seasonForDate };
})();
