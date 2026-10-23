/**
 * call-experience-designer/record.js
 *
 * Customer-facing (no login) Call In Experience recording page (added
 * 2026-10-23, per Michael). Loaded by record.html with a ?t=<token>
 * query param, talks only to api/public.php (no auth/session involved
 * at all) -- same token-is-the-only-access-control pattern as
 * ratesheet/signup.js.
 *
 * Four fixed tracks (TRACKS below, kept in sync with api/db.php's
 * call_experience_track_defs()): for each, the customer can set an
 * optional date-start/date-stop window, and either record live from
 * their microphone or upload a .wav file -- Record/Play/Clear/Upload,
 * exactly as Michael specified (mockup + verbatim message, 2026-10-23).
 *
 * LIVE RECORDING / WAV ENCODING: there is no MediaRecorder precedent
 * anywhere in this codebase, and MediaRecorder itself doesn't produce a
 * .wav container in any browser without a 3rd-party muxer -- so this
 * uses the lower-level Web Audio API directly: getUserMedia -> a plain
 * ScriptProcessorNode tap on the raw PCM samples (deprecated in favor of
 * AudioWorklet, but far simpler for a short fixed-format mono recording
 * and still broadly supported -- flagged here as known tech debt, not
 * an oversight) -> a manually-built 44-byte RIFF/WAVE header + 16-bit
 * PCM mono Blob on stop. Zero external dependencies, consistent with
 * this codebase's no-CDN-except-Google-Fonts convention. The processor
 * is routed through a silent (gain 0) node to audioCtx.destination --
 * required to keep onaudioprocess actually firing in every browser,
 * without the customer hearing their own live mic feedback.
 *
 * Recording is capped at 5 minutes per track (RECORD_MAX_MS) as a
 * sanity limit Michael didn't specify but which keeps an accidentally
 * unstopped recording from growing into an enormous upload.
 *
 * PLAYBACK: one persistent <audio id="global-player"> lives in
 * record.html OUTSIDE #app-root specifically so a render() -- which
 * rebuilds #app-root's innerHTML from scratch -- never yanks the audio
 * element out from under an in-progress playback, the same class of bug
 * ratesheet/signup.js's signature-pad comment calls out for its canvas.
 */

(function () {
  'use strict';

  var root = document.getElementById('app-root');
  var player = document.getElementById('global-player');
  var params = new URLSearchParams(window.location.search);
  var token = params.get('t') || '';

  var RECORD_MAX_MS = 5 * 60 * 1000;

  // Keep in sync with api/db.php's call_experience_track_defs().
  var TRACKS = [
    { key: 'main_greeting', label: 'Main System Greeting' },
    { key: 'phone_tree', label: 'Phone Tree Menu' },
    { key: 'general_holiday', label: 'General Holiday Message' },
    { key: 'emergency', label: 'Emergency Message' }
  ];

  var state = {
    loading: true,
    loadError: null,
    status: 'pending',
    companyName: '',
    tracks: {}, // track_key -> { date_start, date_stop, has_file, source, original_filename, size_bytes }
    busy: {}, // track_key -> 'recording' | 'uploading' | 'clearing' | null
    trackError: {}, // track_key -> string|null
    playingTrack: null,
    submitting: false,
    submitError: null,
    submitted: false
  };

  var recordingSessions = {}; // track_key -> { stream, audioCtx, source, processor, silentGain, buffers, sampleRate, stopTimer }

  function e(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function formatBytes(n) {
    if (n == null) return '';
    if (n < 1024) return n + ' B';
    if (n < 1024 * 1024) return (n / 1024).toFixed(0) + ' KB';
    return (n / (1024 * 1024)).toFixed(1) + ' MB';
  }

  function apiGet(url) {
    return fetch(url, { credentials: 'same-origin' }).then(function (r) {
      return r.json().then(function (data) { return { status: r.status, data: data }; });
    });
  }
  function apiPostForm(url, formData) {
    return fetch(url, { method: 'POST', credentials: 'same-origin', body: formData }).then(function (r) {
      return r.json().then(function (data) { return { status: r.status, data: data }; });
    });
  }
  function apiPostUrlEncoded(url, fields) {
    var body = new URLSearchParams();
    Object.keys(fields).forEach(function (k) { body.append(k, fields[k] == null ? '' : fields[k]); });
    return fetch(url, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString()
    }).then(function (r) {
      return r.json().then(function (data) { return { status: r.status, data: data }; });
    });
  }

  function applyTracksFromServer(tracks) {
    tracks.forEach(function (t) {
      state.tracks[t.track_key] = {
        date_start: t.date_start, date_stop: t.date_stop, has_file: t.has_file,
        source: t.source, original_filename: t.original_filename, size_bytes: t.size_bytes,
        version: Date.now()
      };
    });
  }

  function loadContext() {
    if (!token) {
      state.loading = false;
      state.loadError = 'This link is missing its recording code — please use the link from your email.';
      render();
      return;
    }
    apiGet('api/public.php?action=context&t=' + encodeURIComponent(token)).then(function (r) {
      state.loading = false;
      if (r.data && r.data.ok) {
        state.status = r.data.request.status;
        state.companyName = r.data.request.company_name || '';
        applyTracksFromServer(r.data.request.tracks);
      } else {
        state.loadError = (r.data && r.data.error) || 'This recording link is not valid.';
      }
      render();
    }).catch(function () {
      state.loading = false;
      state.loadError = 'Could not load this recording link — check your connection and try again.';
      render();
    });
  }

  // ---- Recording (Web Audio -> manual WAV encode) ----------------------

  function mergeBuffers(buffers, totalLength) {
    var result = new Float32Array(totalLength);
    var offset = 0;
    buffers.forEach(function (b) {
      result.set(b, offset);
      offset += b.length;
    });
    return result;
  }

  function encodeWav(samples, sampleRate) {
    var buffer = new ArrayBuffer(44 + samples.length * 2);
    var view = new DataView(buffer);
    function writeString(offset, str) {
      for (var i = 0; i < str.length; i++) view.setUint8(offset + i, str.charCodeAt(i));
    }
    writeString(0, 'RIFF');
    view.setUint32(4, 36 + samples.length * 2, true);
    writeString(8, 'WAVE');
    writeString(12, 'fmt ');
    view.setUint32(16, 16, true);
    view.setUint16(20, 1, true); // PCM
    view.setUint16(22, 1, true); // mono
    view.setUint32(24, sampleRate, true);
    view.setUint32(28, sampleRate * 2, true); // byte rate (mono, 16-bit)
    view.setUint16(32, 2, true); // block align
    view.setUint16(34, 16, true); // bits per sample
    writeString(36, 'data');
    view.setUint32(40, samples.length * 2, true);
    var offset = 44;
    for (var i = 0; i < samples.length; i++, offset += 2) {
      var s = Math.max(-1, Math.min(1, samples[i]));
      view.setInt16(offset, s < 0 ? s * 0x8000 : s * 0x7fff, true);
    }
    return new Blob([view], { type: 'audio/wav' });
  }

  function startRecording(trackKey) {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      setTrackError(trackKey, 'Your browser doesn’t support microphone recording — try uploading a .wav file instead.');
      return;
    }
    if (recordingSessions[trackKey]) return;
    setTrackError(trackKey, null);

    navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
      var AC = window.AudioContext || window.webkitAudioContext;
      var audioCtx = new AC();
      var source = audioCtx.createMediaStreamSource(stream);
      var processor = audioCtx.createScriptProcessor(4096, 1, 1);
      var silentGain = audioCtx.createGain();
      silentGain.gain.value = 0;

      var sess = { stream: stream, audioCtx: audioCtx, source: source, processor: processor, silentGain: silentGain, buffers: [], totalLength: 0, sampleRate: audioCtx.sampleRate };
      processor.onaudioprocess = function (ev) {
        var input = ev.inputBuffer.getChannelData(0);
        var copy = new Float32Array(input.length);
        copy.set(input);
        sess.buffers.push(copy);
        sess.totalLength += copy.length;
      };
      source.connect(processor);
      processor.connect(silentGain);
      silentGain.connect(audioCtx.destination);

      sess.stopTimer = window.setTimeout(function () { stopRecording(trackKey); }, RECORD_MAX_MS);
      recordingSessions[trackKey] = sess;
      state.busy[trackKey] = 'recording';
      render();
    }).catch(function () {
      setTrackError(trackKey, 'Could not access your microphone — check your browser’s permission settings and try again.');
      render();
    });
  }

  function stopRecording(trackKey) {
    var sess = recordingSessions[trackKey];
    if (!sess) return;
    window.clearTimeout(sess.stopTimer);
    try { sess.processor.disconnect(); } catch (err) { /* already disconnected */ }
    try { sess.source.disconnect(); } catch (err) { /* already disconnected */ }
    try { sess.silentGain.disconnect(); } catch (err) { /* already disconnected */ }
    sess.stream.getTracks().forEach(function (t) { t.stop(); });
    sess.audioCtx.close();
    delete recordingSessions[trackKey];

    if (sess.totalLength === 0) {
      state.busy[trackKey] = null;
      setTrackError(trackKey, 'No audio was captured — please try recording again.');
      render();
      return;
    }

    var samples = mergeBuffers(sess.buffers, sess.totalLength);
    var blob = encodeWav(samples, sess.sampleRate);
    uploadTrackFile(trackKey, blob, 'recorded', trackKey + '.wav');
  }

  function toggleRecord(trackKey) {
    if (recordingSessions[trackKey]) {
      stopRecording(trackKey);
    } else {
      startRecording(trackKey);
    }
  }

  // ---- Playback (shared, persistent <audio>) ----------------------------

  function playTrack(trackKey) {
    if (state.playingTrack === trackKey) {
      stopPlayback();
      return;
    }
    var t = state.tracks[trackKey];
    if (!t || !t.has_file) return;
    player.src = 'api/public.php?action=track_audio&t=' + encodeURIComponent(token) + '&track=' + encodeURIComponent(trackKey) + '&v=' + (t.version || 0);
    player.play().catch(function () { /* autoplay/permission edge case -- button just stays idle */ });
    state.playingTrack = trackKey;
    render();
  }

  function stopPlayback() {
    player.pause();
    player.currentTime = 0;
    state.playingTrack = null;
  }

  player.addEventListener('ended', function () {
    state.playingTrack = null;
    render();
  });

  // ---- Upload / Clear / Dates -------------------------------------------

  function setTrackError(trackKey, msg) {
    state.trackError[trackKey] = msg;
  }

  function uploadTrackFile(trackKey, fileOrBlob, source, filename) {
    state.busy[trackKey] = 'uploading';
    setTrackError(trackKey, null);
    render();

    var fd = new FormData();
    fd.append('t', token);
    fd.append('track', trackKey);
    fd.append('source', source);
    fd.append('file', fileOrBlob, filename);

    apiPostForm('api/public.php?action=upload', fd).then(function (r) {
      state.busy[trackKey] = null;
      if (r.data && r.data.ok) {
        applyTracksFromServer(r.data.tracks);
      } else {
        setTrackError(trackKey, (r.data && r.data.error) || 'Could not save that recording. Please try again.');
      }
      render();
    }).catch(function () {
      state.busy[trackKey] = null;
      setTrackError(trackKey, 'Could not reach the server — check your connection and try again.');
      render();
    });
  }

  function handleFileUpload(trackKey, file) {
    if (!file) return;
    uploadTrackFile(trackKey, file, 'uploaded', file.name);
  }

  function clearTrack(trackKey) {
    if (state.playingTrack === trackKey) stopPlayback();
    state.busy[trackKey] = 'clearing';
    setTrackError(trackKey, null);
    render();
    apiPostUrlEncoded('api/public.php?action=clear', { t: token, track: trackKey }).then(function (r) {
      state.busy[trackKey] = null;
      if (r.data && r.data.ok) {
        applyTracksFromServer(r.data.tracks);
      } else {
        setTrackError(trackKey, (r.data && r.data.error) || 'Could not clear that recording.');
      }
      render();
    }).catch(function () {
      state.busy[trackKey] = null;
      setTrackError(trackKey, 'Could not reach the server — check your connection and try again.');
      render();
    });
  }

  function saveDate(trackKey, field, value) {
    state.tracks[trackKey][field] = value || null;
    render();
    apiPostUrlEncoded('api/public.php?action=save_dates', {
      t: token, track: trackKey,
      date_start: state.tracks[trackKey].date_start, date_stop: state.tracks[trackKey].date_stop
    }).then(function (r) {
      if (!r.data || !r.data.ok) {
        setTrackError(trackKey, (r.data && r.data.error) || 'Could not save that date.');
        render();
      }
    }).catch(function () {
      setTrackError(trackKey, 'Could not reach the server to save that date — it may not have been saved.');
      render();
    });
  }

  // ---- Submit -------------------------------------------------------------

  function submitForm() {
    if (!state.companyName.trim()) {
      state.submitError = 'Company Name is required before submitting.';
      render();
      return;
    }
    state.submitting = true;
    state.submitError = null;
    render();
    apiPostUrlEncoded('api/public.php?action=submit', { t: token, company_name: state.companyName }).then(function (r) {
      state.submitting = false;
      if (r.data && r.data.ok) {
        state.submitted = true;
        state.status = 'submitted';
        render();
      } else {
        state.submitError = (r.data && r.data.error) || 'Something went wrong submitting your recordings. Please try again.';
        render();
      }
    }).catch(function () {
      state.submitting = false;
      state.submitError = 'Could not reach CodeBlue Technology — check your connection and try again.';
      render();
    });
  }

  // ---- Render ---------------------------------------------------------

  function letterheadHtml() {
    return '<div class="letterhead"><div class="name">CodeBlue Technology</div><div class="sub">Call In Experience Designer</div></div>';
  }

  function trackStatusHtml(trackKey, t, busy) {
    if (busy === 'recording') return '<div class="track-status is-busy">Recording… click Record again to stop.</div>';
    if (busy === 'uploading') return '<div class="track-status is-busy">Saving…</div>';
    if (busy === 'clearing') return '<div class="track-status is-busy">Clearing…</div>';
    if (!t || !t.has_file) return '<div class="track-status">No message recorded or uploaded yet.</div>';
    var label = t.source === 'recorded' ? 'Recorded' : 'Uploaded: ' + e(t.original_filename || '');
    var size = t.size_bytes ? ' (' + formatBytes(t.size_bytes) + ')' : '';
    return '<div class="track-status has-file">' + label + size + '</div>';
  }

  function trackRowHtml(track) {
    var t = state.tracks[track.key] || {};
    var busy = state.busy[track.key];
    var isRecording = busy === 'recording';
    var isPlaying = state.playingTrack === track.key;
    var locked = state.status === 'submitted';
    var fileOpBusy = busy === 'uploading' || busy === 'clearing';
    var recordDisabled = fileOpBusy || (locked && !isRecording);
    var playDisabled = !t.has_file || fileOpBusy; // intentionally NOT gated by `locked` -- a customer revisiting an already-submitted link can still listen back (see render()'s submitted-view branch).
    var clearDisabled = !t.has_file || fileOpBusy || locked || isRecording;
    var disableUpload = locked || !!busy;
    var err = state.trackError[track.key];

    return '' +
      '<div class="card">' +
      '  <div class="track-title">' + e(track.label) + '</div>' +
      '  <div class="date-row">' +
      '    <div class="date-field"><label class="field-label">Use Starting</label>' +
      '      <input class="date-input" type="date" value="' + e(t.date_start || '') + '" ' + (locked ? 'disabled' : '') + ' data-action="date-start" data-track="' + track.key + '" /></div>' +
      '    <div class="date-sep">to</div>' +
      '    <div class="date-field"><label class="field-label">Use Until</label>' +
      '      <input class="date-input" type="date" value="' + e(t.date_stop || '') + '" ' + (locked ? 'disabled' : '') + ' data-action="date-stop" data-track="' + track.key + '" /></div>' +
      '  </div>' +
      trackStatusHtml(track.key, t, busy) +
      '  <div class="track-controls">' +
      '    <button type="button" class="ctrl-btn ctrl-record ' + (isRecording ? 'is-recording' : '') + '" data-action="toggle-record" data-track="' + track.key + '" ' + (recordDisabled ? 'disabled' : '') + ' data-tip="' + (isRecording ? 'Stop recording' : 'Record from your microphone') + '">' +
      (isRecording
        ? '<svg width="16" height="16" viewBox="0 0 16 16"><rect x="3" y="3" width="10" height="10" rx="1.5" fill="currentColor"/></svg>'
        : '<svg width="18" height="18" viewBox="0 0 18 18"><circle cx="9" cy="9" r="7" fill="currentColor"/></svg>') +
      '    </button>' +
      '    <button type="button" class="ctrl-btn ctrl-play ' + (isPlaying ? 'is-playing' : '') + '" data-action="toggle-play" data-track="' + track.key + '" ' + (playDisabled ? 'disabled' : '') + ' data-tip="' + (isPlaying ? 'Stop playback' : 'Play the most recent recording') + '">' +
      (isPlaying
        ? '<svg width="14" height="14" viewBox="0 0 14 14"><rect x="1" y="1" width="4" height="12" fill="currentColor"/><rect x="9" y="1" width="4" height="12" fill="currentColor"/></svg>'
        : '<svg width="14" height="16" viewBox="0 0 14 16"><polygon points="0,0 14,8 0,16" fill="currentColor"/></svg>') +
      '    </button>' +
      '    <button type="button" class="ctrl-btn ctrl-clear" data-action="clear-track" data-track="' + track.key + '" ' + (clearDisabled ? 'disabled' : '') + ' data-tip="Remove this recording so you can record or upload again">✕</button>' +
      '    <label class="ctrl-btn ctrl-upload" data-tip="Upload a .wav file for this message">' +
      '      <svg width="16" height="16" viewBox="0 0 16 16"><path d="M8 1 L12 6 L9 6 L9 11 L7 11 L7 6 L4 6 Z" fill="currentColor"/><rect x="2" y="13" width="12" height="2" rx="1" fill="currentColor"/></svg>' +
      '      <input type="file" accept=".wav,audio/wav" data-action="upload-file" data-track="' + track.key + '" ' + (disableUpload ? 'disabled' : '') + ' />' +
      '    </label>' +
      '  </div>' +
      (err ? '<div class="track-error">' + e(err) + '</div>' : '') +
      '</div>';
  }

  function renderForm() {
    var html = '<div class="wrap">' + letterheadHtml();
    html += '<div class="card"><p class="intro-text">To finish setting up your phone system, please record or upload each message below. For any message, you can also set an optional start and stop date for when it should be in use — both are optional.</p></div>';
    html += TRACKS.map(trackRowHtml).join('');
    html += '' +
      '<div class="card">' +
      '  <label class="field-label">Company Name</label>' +
      '  <input class="text-input" type="text" placeholder="Your company name" value="' + e(state.companyName) + '" data-action="company-name" />' +
      '</div>' +
      (state.submitError ? '<div class="error-banner">' + e(state.submitError) + '</div>' : '') +
      '<button type="button" class="submit-btn" data-action="submit-form" ' + (state.submitting ? 'disabled' : '') + '>' + (state.submitting ? 'Submitting…' : 'Submit') + '</button>' +
      '<div class="footer-contact">CodeBlue Technology &nbsp;|&nbsp; (804) 521-7660 &nbsp;|&nbsp; Service@codebluetechnology.com</div>' +
      '</div>';
    root.innerHTML = html;
  }

  function render() {
    if (state.loading) {
      root.innerHTML = '<div class="wrap"><div class="card" style="text-align:center;color:#5A6472;">Loading…</div></div>';
      return;
    }
    if (state.loadError) {
      root.innerHTML = '<div class="wrap"><div class="card"><div class="error-banner">' + e(state.loadError) + '</div></div></div>';
      return;
    }
    if (state.submitted) {
      root.innerHTML = '<div class="wrap"><div class="card success-card"><div class="big">✅</div><h2>Thank You!</h2>' +
        '<p style="color:#5A6472;">Your recordings have been received. A CodeBlue Technology team member will finish configuring your phone system shortly.</p></div>' +
        '<div class="footer-contact">CodeBlue Technology &nbsp;|&nbsp; (804) 521-7660 &nbsp;|&nbsp; Service@codebluetechnology.com</div></div>';
      return;
    }
    if (state.status === 'submitted') {
      // Revisiting an already-submitted link -- still shown with every
      // track's Play control live (see trackRowHtml()'s `locked` logic),
      // so the customer can listen back to what they sent, just not
      // change anything.
      root.innerHTML = '<div class="wrap">' + letterheadHtml() +
        '<div class="card"><p class="intro-text">This request has already been submitted. You can still play back what was recorded, but changes are no longer possible — please contact CodeBlue Technology if something needs to change.</p></div>' +
        TRACKS.map(trackRowHtml).join('') +
        '<div class="footer-contact">CodeBlue Technology &nbsp;|&nbsp; (804) 521-7660 &nbsp;|&nbsp; Service@codebluetechnology.com</div></div>';
      return;
    }
    renderForm();
  }

  // ---- Events -----------------------------------------------------------

  root.addEventListener('click', function (ev) {
    var el = ev.target.closest('[data-action]');
    if (!el) return;
    var action = el.getAttribute('data-action');
    var track = el.getAttribute('data-track');
    if (action === 'toggle-record') {
      toggleRecord(track);
    } else if (action === 'toggle-play') {
      playTrack(track);
    } else if (action === 'clear-track') {
      clearTrack(track);
    } else if (action === 'submit-form') {
      submitForm();
    }
  });

  root.addEventListener('change', function (ev) {
    var el = ev.target;
    if (el.getAttribute('data-action') === 'upload-file') {
      handleFileUpload(el.getAttribute('data-track'), el.files && el.files[0]);
    } else if (el.getAttribute('data-action') === 'date-start') {
      saveDate(el.getAttribute('data-track'), 'date_start', el.value);
    } else if (el.getAttribute('data-action') === 'date-stop') {
      saveDate(el.getAttribute('data-track'), 'date_stop', el.value);
    }
  });

  root.addEventListener('input', function (ev) {
    var el = ev.target;
    if (el.getAttribute('data-action') === 'company-name') {
      state.companyName = el.value;
    }
  });

  loadContext();
})();
