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

  var token = null, questionId = null, currentValue = null;
  var locked = false, autoCapture = true;
  var changeListeners = [], restoreListeners = [];

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
    if (t === 'checkbox') return !!f.checked;
    if (t === 'radio') return f.checked ? f.value : undefined;
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

  function applyValueToFields(v) {
    if (!v || typeof v !== 'object' || Array.isArray(v)) return;
    var keys = Object.keys(v);
    for (var i = 0; i < keys.length; i++) {
      var k = keys[i];
      var nodes;
      try { nodes = stage.querySelectorAll('[name="' + cssEscape(k) + '"], #' + cssEscape(k)); }
      catch (e) { nodes = []; }
      for (var j = 0; j < nodes.length; j++) {
        var f = nodes[j];
        var val = v[k];
        if ((f.type || '').toLowerCase() === 'checkbox') {
          f.checked = Array.isArray(val) ? val.indexOf(f.value) >= 0 : !!val;
        } else if ((f.type || '').toLowerCase() === 'radio') {
          f.checked = String(f.value) === String(val);
        } else {
          f.value = Array.isArray(val) ? (val[0] == null ? '' : String(val[0])) : (val == null ? '' : String(val));
        }
      }
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
    collect: function () { return collectFields(); }
  };

  window.addEventListener('message', function (event) {
    if (event.source !== window.parent) return;
    var data = event.data;
    if (!data || typeof data !== 'object' || data.channel !== CHANNEL) return;
    if (PARENT_TYPES.indexOf(data.type) < 0) return;

    if (data.type === 'init') {
      token = String(data.token || '');
      questionId = String(data.questionId || '');
      render(data.payload || {});
      post('ready', {}); // configured ack, now carrying the token
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
    post('ready', {}); // initial handshake before any token exists
  });
})();
</script>
</body>
</html>`;
