/*
 * camera-plan.js -- "Camera Placement Photos" for Premise Security > IP Security Camera Systems > Cameras
 * (added 2026-10-07).
 *
 * A rep uploads (or takes) photos of the spaces to cover, then drags a Bullet, Dome or Turret camera
 * onto each photo. Every dropped camera is a marker on that photo. After each change the totals are
 * pushed into the solution through Component.setCameraPlan() (app.js): the camera quantities on the
 * Cameras list and the "New IP Security Camera Installation" scope line follow the placements, and
 * "Add to Solution" summarizes every photo as ONE camera job.
 *
 * Why a standalone module instead of template bindings: dragging needs live pointer handling and
 * stable DOM nodes, which the patch-in-place template runtime (runtime.js) is not built for. The
 * template only supplies an empty <div id="camera-plan-mount">; this file fills it, and re-fills it
 * whenever the Cameras screen is opened again (photos live in the app's state, in memory only --
 * they are never uploaded or stored anywhere).
 *
 * Uses Pointer Events so the same code drags with a mouse, a finger or a stylus.
 */
(function () {
  'use strict';

  var TYPES = [
    { id: 'bullet', label: 'Bullet' },
    { id: 'dome', label: 'Dome' },
    { id: 'turret', label: 'Turret' }
  ];
  var COLORS = [
    { id: 'white', label: 'White' },
    { id: 'black', label: 'Black' }
  ];
  var MAX_DIM = 1600; // photos are downscaled to this on upload
  var ui = { color: 'white', selected: null };
  var root = null;
  var seq = 0;

  function uid(p) { seq += 1; return p + Date.now().toString(36) + seq; }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function app() { return window.SolutionsHubApp && window.SolutionsHubApp.instance; }
  function photos() {
    var a = app();
    return (a && a.state.cameraPlan && a.state.cameraPlan.photos) || [];
  }
  function typeLabel(id) { for (var i = 0; i < TYPES.length; i++) if (TYPES[i].id === id) return TYPES[i].label; return id; }
  function colorLabel(id) { return id === 'black' ? 'Black' : 'White'; }

  // push a new photo list (deep-copied so state is never mutated in place) into the app, then redraw
  function commit(list) {
    var a = app();
    if (!a) return;
    a.setCameraPlan(list.map(function (p) {
      return { id: p.id, name: p.name, src: p.src, markers: p.markers.map(function (m) { return { id: m.id, x: m.x, y: m.y, type: m.type, color: m.color }; }) };
    }));
    render();
  }
  function clone() {
    return photos().map(function (p) {
      return { id: p.id, name: p.name, src: p.src, markers: p.markers.map(function (m) { return Object.assign({}, m); }) };
    });
  }

  // ---- icons ----------------------------------------------------------------
  function iconSvg(type, fg, bg) {
    var s = 'fill="' + fg + '" stroke="none"';
    var lens = '<circle cx="16" cy="17" r="2.6" fill="' + bg + '"/>';
    var body;
    if (type === 'dome') {
      body = '<path d="M5 21a11 11 0 0 1 22 0z" ' + s + '/><rect x="3" y="21" width="26" height="3" rx="1.5" ' + s + '/>' + lens;
    } else if (type === 'turret') {
      body = '<ellipse cx="16" cy="24" rx="11" ry="3" ' + s + '/><circle cx="16" cy="16" r="8.5" ' + s + '/>' + '<circle cx="16" cy="17" r="3.2" fill="' + bg + '"/>';
    } else {
      body = '<rect x="3" y="9" width="21" height="11" rx="5" ' + s + '/><rect x="22" y="11" width="6" height="7" rx="1.5" ' + s + '/><circle cx="25" cy="14.5" r="2" fill="' + bg + '"/><path d="M10 20v5h7" stroke="' + fg + '" stroke-width="2.4" fill="none" stroke-linecap="round" stroke-linejoin="round"/>';
    }
    return '<svg viewBox="0 0 32 32" width="100%" height="100%" aria-hidden="true">' + body + '</svg>';
  }
  function markerIcon(m) {
    var white = m.color !== 'black';
    return iconSvg(m.type, white ? '#1B2030' : '#FFFFFF', white ? '#FFFFFF' : '#1B2030');
  }

  // ---- styles (once) --------------------------------------------------------
  function injectStyles() {
    if (document.getElementById('camera-plan-styles')) return;
    var css = [
      '.cp{background:var(--cbt-card-bg,#12161f);border-radius:14px;padding:18px 20px;color:var(--cbt-text-on-light-primary,#fff)}',
      '.cp *{box-sizing:border-box}',
      '.cp-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap}',
      '.cp-title{font-weight:700;font-size:14.5px}',
      '.cp-sub{margin-top:2px;font-size:11.5px;font-weight:600;color:var(--cbt-text-on-dark-muted,#8b93a7);max-width:640px;line-height:1.4}',
      '.cp-btn{border:none;border-radius:999px;padding:9px 16px;font-size:12.5px;font-weight:700;cursor:pointer;background:#2f8fef;color:#fff}',
      '.cp-btn.alt{background:transparent;border:1px solid var(--cbt-bg-control-5,#3a4258);color:inherit}',
      '.cp-palette{position:sticky;top:8px;z-index:20;box-shadow:0 4px 14px rgba(0,0,0,.12);margin-top:14px;display:flex;align-items:center;gap:14px;flex-wrap:wrap;padding:10px 12px;border-radius:12px;background:var(--cbt-bg-panel,#0c1018);border:1px solid var(--cbt-card-border,#2a3144)}',
      '.cp-palette-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--cbt-text-on-dark-faint,#7b839a)}',
      '.cp-tiles{display:flex;gap:10px;flex-wrap:wrap}',
      '.cp-tile{width:92px;padding:8px 6px 7px;border-radius:12px;border:1px solid var(--cbt-card-border,#2a3144);background:var(--cbt-card-bg,#12161f);cursor:grab;text-align:center;touch-action:none;user-select:none;-webkit-user-select:none}',
      '.cp-tile:active{cursor:grabbing}',
      '.cp-tile-icon{width:46px;height:46px;margin:0 auto;border-radius:50%;padding:7px}',
      '.cp-tile-label{margin-top:5px;font-size:12px;font-weight:700}',
      '.cp-colors{display:inline-flex;gap:4px;padding:3px;border-radius:999px;border:1px solid var(--cbt-card-border,#2a3144)}',
      '.cp-color{border:none;border-radius:999px;padding:6px 12px;font-size:12px;font-weight:700;cursor:pointer;background:transparent;color:inherit}',
      '.cp-color.on{background:#2f8fef;color:#fff}',
      '.cp-empty{margin-top:14px;padding:26px 16px;text-align:center;border:2px dashed var(--cbt-card-border,#2a3144);border-radius:12px;font-size:13px;font-weight:600;color:var(--cbt-text-on-dark-muted,#8b93a7)}',
      '.cp-photo{margin-top:14px;border:1px solid var(--cbt-card-border,#2a3144);border-radius:12px;overflow:hidden;background:var(--cbt-bg-panel,#0c1018)}',
      '.cp-photo-bar{display:flex;align-items:center;gap:10px;padding:8px 10px}',
      '.cp-name{flex:1;min-width:0;border:1px solid transparent;background:transparent;color:inherit;font-weight:700;font-size:13px;padding:5px 7px;border-radius:7px}',
      '.cp-name:hover,.cp-name:focus{border-color:var(--cbt-card-border,#2a3144);outline:none}',
      '.cp-count{font-size:12px;font-weight:700;white-space:nowrap;color:var(--cbt-text-on-dark-muted,#8b93a7)}',
      '.cp-x{border:none;background:transparent;color:var(--cbt-text-on-dark-muted,#8b93a7);font-size:12px;font-weight:700;cursor:pointer;padding:4px 8px}',
      '.cp-x:hover{color:#ff6b6b}',
      '.cp-stage{position:relative;line-height:0;touch-action:pan-y;user-select:none;-webkit-user-select:none}',
      '.cp-stage.over{outline:3px solid #2f8fef;outline-offset:-3px}',
      '.cp-stage img{width:100%;height:auto;display:block;-webkit-user-drag:none;pointer-events:none}',
      '.cp-marker{position:absolute;width:38px;height:38px;margin:-19px 0 0 -19px;border-radius:50%;padding:6px;border:2px solid;box-shadow:0 2px 8px rgba(0,0,0,.55);cursor:grab;touch-action:none;line-height:0}',
      '.cp-marker.w{background:#fff;border-color:#1B2030}',
      '.cp-marker.b{background:#1B2030;border-color:#fff}',
      '.cp-marker.sel{box-shadow:0 0 0 3px #2f8fef,0 2px 8px rgba(0,0,0,.55)}',
      '.cp-marker.ghosted{opacity:.3}',
      '.cp-tools{position:absolute;transform:translate(-50%,-100%);margin-top:-26px;display:flex;align-items:center;gap:4px;padding:5px;border-radius:12px;background:#0c1018;border:1px solid #3a4258;box-shadow:0 6px 18px rgba(0,0,0,.55);z-index:3;line-height:1;white-space:nowrap}',
      '.cp-tools button{border:none;border-radius:8px;padding:6px 9px;font-size:11.5px;font-weight:700;cursor:pointer;background:#1b2233;color:#fff}',
      '.cp-tools button.on{background:#2f8fef}',
      '.cp-tools button.danger{background:#5b1f27;color:#ffd9dd}',
      '.cp-ghost{position:fixed;z-index:99999;width:44px;height:44px;margin:-22px 0 0 -22px;border-radius:50%;padding:7px;border:2px solid;pointer-events:none;box-shadow:0 8px 22px rgba(0,0,0,.6);line-height:0}',
      '.cp-ghost.w{background:#fff;border-color:#1B2030}',
      '.cp-ghost.b{background:#1B2030;border-color:#fff}',
      '.cp-summary{margin-top:16px;padding:12px 14px;border-radius:12px;background:var(--cbt-bg-panel,#0c1018);border:1px solid var(--cbt-card-border,#2a3144)}',
      '.cp-sum-total{font-size:15px;font-weight:700}',
      '.cp-sum-rows{margin-top:8px;display:flex;flex-wrap:wrap;gap:8px}',
      '.cp-chip{padding:5px 10px;border-radius:999px;border:1px solid var(--cbt-card-border,#2a3144);font-size:12px;font-weight:700}',
      '.cp-note{margin-top:8px;font-size:11.5px;font-weight:600;color:var(--cbt-text-on-dark-muted,#8b93a7);line-height:1.4}'
    ].join('\n');
    var el = document.createElement('style');
    el.id = 'camera-plan-styles';
    el.textContent = css;
    document.head.appendChild(el);
  }

  // ---- rendering --------------------------------------------------------------
  function render() {
    if (!root || !document.body.contains(root)) return;
    var a = app();
    var list = photos();
    var totals = a ? a.cameraPlanTotals({ photos: list }) : { total: 0, byProduct: {} };
    var h = '<div class="cp">' +
      '<div class="cp-head"><div><div class="cp-title">Camera Placement Photos</div>' +
      '<div class="cp-sub">Upload or take a photo of each area, then drag a Bullet, Dome or Turret camera onto it and drop it where it will mount. Every camera you place is counted into this solution automatically — add as many photos as you need.</div></div>' +
      '<div><button class="cp-btn" type="button" data-cp="upload">+ Add photo</button>' +
      '<input type="file" accept="image/*" multiple data-cp="file" style="display:none"></div></div>';

    h += '<div class="cp-palette"><span class="cp-palette-label">Drag onto a photo</span><div class="cp-tiles">';
    TYPES.forEach(function (t) {
      var white = ui.color !== 'black';
      h += '<div class="cp-tile" data-cp-tile="' + t.id + '" title="Drag the ' + t.label.toLowerCase() + ' camera onto a photo">' +
        '<div class="cp-tile-icon" style="background:' + (white ? '#fff' : '#1B2030') + ';border:2px solid ' + (white ? '#1B2030' : '#fff') + '">' + iconSvg(t.id, white ? '#1B2030' : '#FFFFFF', white ? '#FFFFFF' : '#1B2030') + '</div>' +
        '<div class="cp-tile-label">' + t.label + '</div></div>';
    });
    h += '</div><div class="cp-colors">';
    COLORS.forEach(function (c) {
      h += '<button type="button" class="cp-color' + (ui.color === c.id ? ' on' : '') + '" data-cp="color" data-color="' + c.id + '">' + c.label + '</button>';
    });
    h += '</div></div>';

    if (!list.length) {
      h += '<div class="cp-empty">No photos yet — tap “+ Add photo” to upload one (on a phone or tablet you can take it with the camera).</div>';
    }
    list.forEach(function (p) {
      h += '<div class="cp-photo" data-photo="' + esc(p.id) + '"><div class="cp-photo-bar">' +
        '<input class="cp-name" data-cp="name" value="' + esc(p.name) + '" aria-label="Photo name">' +
        '<span class="cp-count">' + p.markers.length + (p.markers.length === 1 ? ' camera' : ' cameras') + '</span>' +
        '<button class="cp-x" type="button" data-cp="remove-photo" title="Remove this photo and its cameras">Remove photo</button></div>' +
        '<div class="cp-stage" data-stage="' + esc(p.id) + '"><img src="' + esc(p.src) + '" alt="' + esc(p.name) + '" draggable="false">';
      p.markers.forEach(function (m) {
        var isSel = ui.selected && ui.selected.photoId === p.id && ui.selected.markerId === m.id;
        h += '<div class="cp-marker ' + (m.color === 'black' ? 'b' : 'w') + (isSel ? ' sel' : '') + '" data-marker="' + esc(m.id) + '" style="left:' + m.x.toFixed(2) + '%;top:' + m.y.toFixed(2) + '%" title="' +
          esc(colorLabel(m.color) + ' ' + typeLabel(m.type).toLowerCase() + ' camera — drag to move, click to edit') + '">' + markerIcon(m) + '</div>';
        if (isSel) {
          h += '<div class="cp-tools" style="left:' + m.x.toFixed(2) + '%;top:' + m.y.toFixed(2) + '%" data-tools="1">';
          TYPES.forEach(function (t) {
            h += '<button type="button" class="' + (m.type === t.id ? 'on' : '') + '" data-cp="set-type" data-type="' + t.id + '">' + t.label + '</button>';
          });
          COLORS.forEach(function (c) {
            h += '<button type="button" class="' + (m.color === c.id ? 'on' : '') + '" data-cp="set-color" data-color="' + c.id + '">' + c.label + '</button>';
          });
          h += '<button type="button" class="danger" data-cp="remove-marker">Remove</button></div>';
        }
      });
      h += '</div></div>';
    });

    h += '<div class="cp-summary"><div class="cp-sum-total">' + totals.total + (totals.total === 1 ? ' camera' : ' cameras') + ' placed across ' + list.length + (list.length === 1 ? ' photo' : ' photos') + '</div>';
    if (totals.total > 0) {
      h += '<div class="cp-sum-rows">';
      TYPES.forEach(function (t) {
        COLORS.forEach(function (c) {
          var n = totals.byProduct[t.id + '-' + c.id] || 0;
          if (n > 0) h += '<span class="cp-chip">' + n + ' × ' + c.label + ' ' + t.label + '</span>';
        });
      });
      h += '</div>';
    }
    h += '<div class="cp-note">These counts set the camera quantities and the “New IP Security Camera Installation” scope line below. Tap <b>Add to Solution</b> at the bottom of this screen to record all photos as one camera job. Photos stay in this browser session unless you save the solution to a customer from the Solution Summary.</div></div></div>';
    root.innerHTML = h;
  }

  // ---- dragging ---------------------------------------------------------------
  function stageAt(x, y) {
    var el = document.elementFromPoint(x, y);
    return el && el.closest ? el.closest('.cp-stage') : null;
  }
  function relPos(stage, x, y) {
    var r = stage.getBoundingClientRect();
    return {
      x: Math.max(0, Math.min(100, (x - r.left) / r.width * 100)),
      y: Math.max(0, Math.min(100, (y - r.top) / r.height * 100))
    };
  }

  // spec: { kind:'new', type, color } | { kind:'move', photoId, markerId, type, color }
  function startDrag(ev, spec) {
    if (ev.button !== undefined && ev.button !== 0) return;
    ev.preventDefault();
    var startX = ev.clientX, startY = ev.clientY, lastX = startX, lastY = startY, moved = false, lastStage = null;
    var ghost = document.createElement('div');
    ghost.className = 'cp-ghost ' + (spec.color === 'black' ? 'b' : 'w');
    ghost.innerHTML = markerIcon({ type: spec.type, color: spec.color });
    ghost.style.display = spec.kind === 'new' ? 'block' : 'none';
    ghost.style.left = startX + 'px';
    ghost.style.top = startY + 'px';
    document.body.appendChild(ghost);
    var origEl = spec.kind === 'move' ? root.querySelector('[data-marker="' + spec.markerId + '"]') : null;

    function onMove(e) {
      if (!moved && Math.abs(e.clientX - startX) + Math.abs(e.clientY - startY) > 5) {
        moved = true;
        ghost.style.display = 'block';
        if (origEl) origEl.classList.add('ghosted');
      }
      lastX = e.clientX; lastY = e.clientY;
      ghost.style.left = e.clientX + 'px';
      ghost.style.top = e.clientY + 'px';
      refreshOver();
    }
    function refreshOver() {
      var st = stageAt(lastX, lastY);
      if (st !== lastStage) {
        if (lastStage) lastStage.classList.remove('over');
        if (st) st.classList.add('over');
        lastStage = st;
      }
    }
    // Long pages: scroll while the pointer is held near the top or bottom edge of the screen.
    var scrollTimer = setInterval(function () {
      if (!moved) return;
      var edge = 70, vh = window.innerHeight, dy = 0;
      if (lastY < edge) dy = -Math.ceil((edge - lastY) / 4);
      else if (lastY > vh - edge) dy = Math.ceil((lastY - (vh - edge)) / 4);
      if (dy) { window.scrollBy(0, dy); refreshOver(); }
    }, 16);
    function finish(e, cancelled) {
      clearInterval(scrollTimer);
      window.removeEventListener('pointermove', onMove, true);
      window.removeEventListener('pointerup', onUp, true);
      window.removeEventListener('pointercancel', onCancel, true);
      if (ghost.parentNode) ghost.parentNode.removeChild(ghost);
      if (lastStage) lastStage.classList.remove('over');
      if (origEl) origEl.classList.remove('ghosted');
      if (cancelled) return;
      if (spec.kind === 'move' && !moved) {
        // a plain click on a marker: select / deselect it
        var same = ui.selected && ui.selected.markerId === spec.markerId;
        ui.selected = same ? null : { photoId: spec.photoId, markerId: spec.markerId };
        render();
        return;
      }
      var st = stageAt(e.clientX, e.clientY);
      if (!st) { if (spec.kind === 'move') render(); return; } // dropped outside any photo: leave things as they were
      var pos = relPos(st, e.clientX, e.clientY);
      var targetId = st.getAttribute('data-stage');
      var list = clone();
      if (spec.kind === 'move') {
        var src = list.filter(function (p) { return p.id === spec.photoId; })[0];
        var dst = list.filter(function (p) { return p.id === targetId; })[0];
        if (!src || !dst) return;
        var mk = src.markers.filter(function (m) { return m.id === spec.markerId; })[0];
        if (!mk) return;
        src.markers = src.markers.filter(function (m) { return m.id !== spec.markerId; });
        mk.x = pos.x; mk.y = pos.y;
        dst.markers.push(mk);
        ui.selected = { photoId: dst.id, markerId: mk.id };
      } else {
        var target = list.filter(function (p) { return p.id === targetId; })[0];
        if (!target) return;
        var nm = { id: uid('m'), x: pos.x, y: pos.y, type: spec.type, color: spec.color };
        target.markers.push(nm);
        ui.selected = { photoId: target.id, markerId: nm.id };
      }
      commit(list);
    }
    function onUp(e) { finish(e, false); }
    function onCancel(e) { finish(e, true); }
    window.addEventListener('pointermove', onMove, true);
    window.addEventListener('pointerup', onUp, true);
    window.addEventListener('pointercancel', onCancel, true);
  }

  // ---- uploading --------------------------------------------------------------
  function downscale(file) {
    return new Promise(function (resolve, reject) {
      var url = URL.createObjectURL(file);
      var img = new Image();
      img.onload = function () {
        var w = img.naturalWidth, h = img.naturalHeight;
        var scale = Math.min(1, MAX_DIM / Math.max(w, h));
        var cw = Math.max(1, Math.round(w * scale)), ch = Math.max(1, Math.round(h * scale));
        var canvas = document.createElement('canvas');
        canvas.width = cw; canvas.height = ch;
        canvas.getContext('2d').drawImage(img, 0, 0, cw, ch);
        URL.revokeObjectURL(url);
        canvas.toBlob(function (blob) {
          if (!blob) { reject(new Error('Could not process that image.')); return; }
          resolve(URL.createObjectURL(blob));
        }, 'image/jpeg', 0.86);
      };
      img.onerror = function () { URL.revokeObjectURL(url); reject(new Error('That file could not be read as a picture.')); };
      img.src = url;
    });
  }
  function addFiles(files) {
    var imgs = Array.prototype.filter.call(files || [], function (f) { return /^image\//.test(f.type) || /\.(jpe?g|png|gif|webp|bmp|heic|heif)$/i.test(f.name); });
    if (!imgs.length) return;
    var base = photos().length;
    Promise.all(imgs.map(function (f) { return downscale(f).catch(function () { return null; }); })).then(function (urls) {
      var list = clone();
      var added = 0;
      urls.forEach(function (u) {
        if (!u) return;
        added += 1;
        list.push({ id: uid('p'), name: 'Photo ' + (base + added), src: u, markers: [] });
      });
      if (added) commit(list);
      else render();
    });
  }

  // ---- events (delegated on the mount node) ------------------------------------
  function onRootEvents() {
    root.addEventListener('click', function (e) {
      var t = e.target.closest ? e.target.closest('[data-cp]') : null;
      if (!t) return;
      var act = t.getAttribute('data-cp');
      var list;
      if (act === 'upload') { root.querySelector('[data-cp="file"]').click(); return; }
      if (act === 'color') { ui.color = t.getAttribute('data-color'); render(); return; }
      if (act === 'remove-photo') {
        var pid = t.closest('.cp-photo').getAttribute('data-photo');
        list = clone();
        var gone = list.filter(function (p) { return p.id === pid; })[0];
        if (gone && /^blob:/.test(gone.src)) { try { URL.revokeObjectURL(gone.src); } catch (err) { /* ignore */ } }
        if (ui.selected && ui.selected.photoId === pid) ui.selected = null;
        commit(list.filter(function (p) { return p.id !== pid; }));
        return;
      }
      if (!ui.selected) return;
      list = clone();
      var ph = list.filter(function (p) { return p.id === ui.selected.photoId; })[0];
      var mk = ph ? ph.markers.filter(function (m) { return m.id === ui.selected.markerId; })[0] : null;
      if (!mk) return;
      if (act === 'set-type') mk.type = t.getAttribute('data-type');
      else if (act === 'set-color') mk.color = t.getAttribute('data-color');
      else if (act === 'remove-marker') { ph.markers = ph.markers.filter(function (m) { return m.id !== mk.id; }); ui.selected = null; }
      commit(list);
    });
    root.addEventListener('change', function (e) {
      var t = e.target;
      var act = t.getAttribute && t.getAttribute('data-cp');
      if (act === 'file') { addFiles(t.files); t.value = ''; return; }
      if (act === 'name') {
        var pid = t.closest('.cp-photo').getAttribute('data-photo');
        var list = clone();
        var ph = list.filter(function (p) { return p.id === pid; })[0];
        if (ph) { ph.name = (t.value || '').trim() || ph.name; commit(list); }
      }
    });
    root.addEventListener('pointerdown', function (e) {
      var tile = e.target.closest ? e.target.closest('[data-cp-tile]') : null;
      if (tile) { startDrag(e, { kind: 'new', type: tile.getAttribute('data-cp-tile'), color: ui.color }); return; }
      var mkEl = e.target.closest ? e.target.closest('[data-marker]') : null;
      if (mkEl) {
        var photoId = mkEl.closest('.cp-stage').getAttribute('data-stage');
        var markerId = mkEl.getAttribute('data-marker');
        var mk = photos().filter(function (p) { return p.id === photoId; })[0];
        mk = mk ? mk.markers.filter(function (m) { return m.id === markerId; })[0] : null;
        if (mk) startDrag(e, { kind: 'move', photoId: photoId, markerId: markerId, type: mk.type, color: mk.color });
        return;
      }
      // tapping empty photo area or anywhere that is not the toolbar dismisses the marker editor
      if (ui.selected && !(e.target.closest && e.target.closest('[data-tools]'))) { ui.selected = null; render(); }
    });
  }

  // ---- mounting -----------------------------------------------------------------
  function tryMount() {
    var el = document.getElementById('camera-plan-mount');
    if (!el || el.__cpInit) return;
    el.__cpInit = true;
    root = el;
    injectStyles();
    onRootEvents();
    render();
  }
  var obs = new MutationObserver(function () { tryMount(); });
  function start() {
    obs.observe(document.body, { childList: true, subtree: true });
    tryMount();
  }
  if (document.body) start(); else document.addEventListener('DOMContentLoaded', start);

  window.CameraPlanWidget = { refresh: render };
})();
