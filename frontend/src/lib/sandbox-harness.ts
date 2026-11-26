/**
 * The isolated sandbox document served at `/embed/question`.
 *
 * It is our own trusted bootstrap — no teacher content is embedded server-side.
 * The teacher's html/css/js arrives only through the postMessage protocol at
 * runtime, is injected into a container inside this opaque-origin iframe, and
 * can never reach the host except by reporting an answer/size/error.
 *
 * Kept as a plain string (no template placeholders) so the route can serve it
 * verbatim and tests can load it in a real browser.
 */
export const sandboxHarnessHtml = `<!DOCTYPE html>
<html lang="ar">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Fahimni interactive question</title>
<style>
  :root { color-scheme: light; }
  html, body { margin: 0; padding: 0; }
  body {
    font-family: system-ui, -apple-system, "Segoe UI", Tahoma, Arial, sans-serif;
    background: #ffffff;
    color: #111827;
    direction: inherit;
    padding: 8px;
    box-sizing: border-box;
    overflow-x: hidden;
  }
  #fq-stage { width: 100%; box-sizing: border-box; }
  #fq-stage img, #fq-stage svg { max-width: 100%; }
  #fq-stage table { max-width: 100%; border-collapse: collapse; }
  button, input, select, textarea { font: inherit; max-width: 100%; }
</style>
</head>
<body>
<div id="fq-root"></div>
<script>
(function () {
  'use strict';
  var CHANNEL = 'fahimni-question';
  var PARENT_TYPES = ['init', 'restore', 'lock', 'reset'];
  var MIN_HEIGHT = 120, MAX_HEIGHT = 1600;

  var mountToken = readMountToken();
  var token = mountToken || null, questionId = null, currentValue = null;
  var locked = false, autoCapture = true, initialized = false;
  var changeListeners = [], restoreListeners = [];

  function readMountToken() {
    try {
      var params = new URLSearchParams(window.location.search);
      var m = params.get('m');
      return m ? String(m).slice(0, 200) : '';
    } catch (e) { return ''; }
  }

  var root = document.getElementById('fq-root');
  var stage = document.createElement('div');
  stage.id = 'fq-stage';
  var styleEl = document.createElement('style');
  styleEl.id = 'fq-user-style';
  root.appendChild(styleEl);
  root.appendChild(stage);

  function post(type, payload) {
    try {
      parent.postMessage({
        channel: CHANNEL,
        type: type,
        token: token || '',
        questionId: questionId || undefined,
        payload: payload || undefined
      }, '*');
    } catch (e) { /* opaque origin edge cases must never break the page */ }
  }

  function reportError(message) {
    post('error', { message: String(message == null ? 'unknown error' : message).slice(0, 500) });
  }

  window.onerror = function (message) { reportError(message); return true; };
  window.addEventListener('unhandledrejection', function (e) {
    var reason = e && e.reason;
    reportError(reason && reason.message ? reason.message : reason);
  });

  function clone(value) {
    if (value === undefined) return null;
    try { return JSON.parse(JSON.stringify(value)); } catch (e) { return null; }
  }

  function setValue(value, silent) {
    currentValue = value;
    if (!silent) {
      for (var i = 0; i < changeListeners.length; i++) {
        try { changeListeners[i](value); } catch (e) { /* teacher callback must not break capture */ }
      }
      post('answer', { value: clone(value) });
    }
  }

  function readField(f) {
    var t = (f.type || '').toLowerCase();
    var tag = (f.tagName || '').toLowerCase();
    if (t === 'checkbox') return !!f.checked;
    if (t === 'radio') return f.checked ? f.value : undefined;
    if (tag === 'select' && f.multiple) {
      var vals = [];
      for (var i = 0; i < f.options.length; i++) {
        if (f.options[i].selected) vals.push(f.options[i].value);
      }
      return vals;
    }
    if (t === 'number' || t === 'range') {
      if (f.value === '') return null;
      var n = Number(f.value);
      return isFinite(n) ? n : f.value;
    }
    return f.value;
  }

  function collectFields() {
    var nodes = stage.querySelectorAll('input,select,textarea');
    if (!nodes.length) return undefined;
    if (nodes.length === 1) {
      var single = readField(nodes[0]);
      return single === undefined ? undefined : single;
    }
    var out = {};
    for (var i = 0; i < nodes.length; i++) {
      var f = nodes[i];
      var t = (f.type || '').toLowerCase();
      if (t === 'radio' && !f.checked) continue;
      var name = f.name || f.id || ('field' + (i + 1));
      var val = readField(f);
      if (val === undefined) continue;
      if (Object.prototype.hasOwnProperty.call(out, name)) {
        if (!Array.isArray(out[name])) out[name] = [out[name]];
        out[name].push(val);
      } else {
        out[name] = val;
      }
    }
    return out;
  }

  function cssEscape(s) {
    return String(s).replace(/["\\\\]/g, '\\\\$&');
  }

  function normBool(v) {
    if (typeof v === 'boolean') return v;
    if (typeof v === 'number') return v !== 0;
    if (typeof v === 'string') {
      var s = v.trim().toLowerCase();
      return s === 'true' || s === '1' || s === 'on' || s === 'yes';
    }
    return !!v;
  }

  function setFieldValue(f, val) {
    var t = (f.type || '').toLowerCase();
    var tag = (f.tagName || '').toLowerCase();
    if (t === 'checkbox') {
      if (Array.isArray(val)) {
        var list = [];
        for (var a = 0; a < val.length; a++) list.push(String(val[a]));
        f.checked = list.indexOf(String(f.value)) >= 0;
      } else {
        f.checked = normBool(val);
      }
    } else if (t === 'radio') {
      f.checked = String(f.value) === String(val == null ? '' : val);
    } else if (tag === 'select' && f.multiple) {
      var want = [];
      if (Array.isArray(val)) { for (var b = 0; b < val.length; b++) want.push(String(val[b])); }
      else if (val != null) { want.push(String(val)); }
      for (var o = 0; o < f.options.length; o++) {
        f.options[o].selected = want.indexOf(String(f.options[o].value)) >= 0;
      }
    } else {
      if (Array.isArray(val)) f.value = val[0] == null ? '' : String(val[0]);
      else f.value = val == null ? '' : String(val);
    }
  }

  // Restore a previously stored value into the native fields. Handles the
  // scalar shapes a single field reports (text/number/checkbox/radio) as well
  // as the object/array shapes a group of named fields reports.
  function applyValueToFields(v) {
    var nodes = stage.querySelectorAll('input,select,textarea');
    if (!nodes.length) return;

    if (v === null || typeof v !== 'object') {
      setFieldValue(nodes[0], v);
      return;
    }
    if (Array.isArray(v)) {
      if (nodes.length === 1) setFieldValue(nodes[0], v);
      return;
    }
    var keys = Object.keys(v);
    for (var i = 0; i < keys.length; i++) {
      var k = keys[i];
      var matched;
      try { matched = stage.querySelectorAll('[name="' + cssEscape(k) + '"], #' + cssEscape(k)); }
      catch (e) { matched = []; }
      for (var j = 0; j < matched.length; j++) setFieldValue(matched[j], v[k]);
    }
  }

  function executeJs(js) {
    if (!js || !String(js).trim()) return;
    var s = document.createElement('script');
    // A classic inline script in this opaque, sandboxed document.
    // 'unsafe-inline' is allowed by the sandbox CSP only; it is a separate
    // policy from the app.
    s.textContent = '(function(){\\n' + js + '\\n})();\\n//# sourceURL=fahimni-question.js';
    stage.appendChild(s);
  }

  function measure() {
    try {
      var h = Math.max(document.documentElement.scrollHeight, stage.scrollHeight, MIN_HEIGHT);
      post('resize', { height: Math.min(h, MAX_HEIGHT) });
    } catch (e) { /* measurement is best-effort */ }
  }

  function applyLock() {
    if (locked) {
      stage.setAttribute('inert', '');
      stage.style.pointerEvents = 'none';
      stage.style.opacity = '0.85';
    } else {
      stage.removeAttribute('inert');
      stage.style.pointerEvents = '';
      stage.style.opacity = '';
    }
  }

  function restore(value) {
    currentValue = value;
    applyValueToFields(value);
    for (var i = 0; i < restoreListeners.length; i++) {
      try { restoreListeners[i](value); } catch (e) { /* ignore */ }
    }
  }

  function render(payload) {
    payload = payload || {};
    stage.innerHTML = String(payload.html || '');
    styleEl.textContent = String(payload.css || '');
    autoCapture = payload.autoCapture !== false;
    locked = !!payload.locked;
    applyLock();
    try { executeJs(payload.js); } catch (e) { reportError(e && e.message); }
    if (payload.value !== undefined && payload.value !== null) restore(payload.value);
    measure();
  }

  stage.addEventListener('input', function () {
    if (locked || !autoCapture) return;
    var v = collectFields();
    if (v !== undefined) setValue(v);
  }, true);
  stage.addEventListener('change', function () {
    if (locked || !autoCapture) return;
    var v = collectFields();
    if (v !== undefined) setValue(v);
  }, true);

  // Public API the teacher's code uses. Deliberately tiny: it can only report
  // an answer or read state — never scores, timers or exam controls.
  window.FahimniQuestion = {
    version: 1,
    setAnswer: function (v) { setValue(v); },
    getValue: function () { return currentValue; },
    onChange: function (cb) { if (typeof cb === 'function') changeListeners.push(cb); },
    onRestore: function (cb) { if (typeof cb === 'function') restoreListeners.push(cb); },
    collect: function () { return collectFields(); },
    // Signal that the exercise is finished. A hint only: the host marks an
    // ungraded practice exercise complete; it can never score or end an exam.
    done: function () { post('done', {}); }
  };

  window.addEventListener('message', function (event) {
    if (event.source !== window.parent) return;
    var data = event.data;
    if (!data || typeof data !== 'object' || data.channel !== CHANNEL) return;
    if (PARENT_TYPES.indexOf(data.type) < 0) return;

    if (data.type === 'init') {
      // Only this mount's own handshake may configure the frame. A document a
      // self-navigation landed on cannot know the mount token, so it can never
      // trigger teacher-code execution here (a tokenless document refuses init).
      if (!mountToken || String(data.token || '') !== mountToken) return;
      // Configure exactly once: a second init for the same mount must never run
      // teacher JS again.
      if (initialized) return;
      initialized = true;

      token = mountToken || String(data.token || '');
      questionId = String(data.questionId || '');
      render(data.payload || {});
      post('ready', {}); // configured ack, carrying the token
      return;
    }
    if (!token || String(data.token || '') !== token) return;

    if (data.type === 'restore') {
      restore(data.payload ? data.payload.value : null);
    } else if (data.type === 'lock') {
      locked = !!(data.payload && data.payload.locked);
      applyLock();
    } else if (data.type === 'reset') {
      stage.innerHTML = '';
      styleEl.textContent = '';
      currentValue = null;
    }
    measure();
  }, false);

  window.addEventListener('load', function () {
    measure();
    post('ready', {}); // initial handshake, carrying the URL mount token
  });
})();
</script>
</body>
</html>`;
