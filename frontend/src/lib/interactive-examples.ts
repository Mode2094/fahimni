/**
 * Three complete, ready-to-use interactive questions (teacher-side presets).
 *
 * Each one is fully self-contained: no external network resource (the sandbox
 * forbids it), logic lives in the separate `js` field (inline `<script>` inside
 * the pasted HTML is never executed), and the hidden answer key lives in
 * `grading` — never in the code the student receives.
 *
 * They are loaded into the editor via `InteractiveHtmlEditor` and are also
 * exercised by the real-browser Playwright spec, so they must keep working.
 */

export interface InteractiveExample {
  id: string;
  titleAr: string;
  titleEn: string;
  html: string;
  css: string;
  js: string;
  height: number;
  autoCapture: boolean;
  gradingMode: 'auto' | 'manual';
  gradingRule: string;
  /** Already-serialized answer key, as the teacher types it in the editor. */
  gradingKey: string;
  tolerance: string;
}

// ── (1) Math: pick the point on a line, auto-graded with json_equals ──────────
const MATH_HTML = `<div class="m">
  <p class="m-prompt">اختر النقطة التي تحقق المعادلة y = 2x + 1 عند x = 2</p>
  <svg viewBox="0 0 320 200" class="m-plot" role="img" aria-label="رسم بياني">
    <line x1="24" y1="176" x2="304" y2="176" class="m-axis" />
    <line x1="24" y1="176" x2="24" y2="16" class="m-axis" />
    <circle class="m-pt" data-x="2" data-y="5" cx="140" cy="56" r="10" />
    <circle class="m-pt" data-x="2" data-y="3" cx="140" cy="104" r="10" />
    <circle class="m-pt" data-x="1" data-y="5" cx="82" cy="56" r="10" />
    <circle class="m-pt" data-x="3" data-y="4" cx="198" cy="80" r="10" />
  </svg>
  <p class="m-hint" id="m-hint">لم تختر بعد</p>
</div>`;

const MATH_CSS = `.m { font-family: inherit; }
.m-prompt { font-weight: 700; margin: 0 0 8px; }
.m-plot { width: 100%; max-width: 340px; height: auto; display: block; }
.m-axis { stroke: #94a3b8; stroke-width: 2; }
.m-pt { fill: #0d9488; cursor: pointer; transition: fill .15s ease; }
.m-pt:hover { fill: #14b8a6; }
.m-pt.m-sel { fill: #f59e0b; stroke: #b45309; stroke-width: 2.5; }
.m-hint { font-size: 13px; color: #475569; min-height: 18px; margin: 8px 0 0; }`;

const MATH_JS = `var pts = document.querySelectorAll('#fq-stage .m-pt');
var hint = document.getElementById('m-hint');
function pick(el) {
  for (var i = 0; i < pts.length; i++) pts[i].classList.remove('m-sel');
  el.classList.add('m-sel');
  var x = Number(el.getAttribute('data-x'));
  var y = Number(el.getAttribute('data-y'));
  if (hint) hint.textContent = 'اخترت النقطة (' + x + ' , ' + y + ')';
  FahimniQuestion.setAnswer({ x: x, y: y });
}
for (var i = 0; i < pts.length; i++) {
  (function (el) { el.addEventListener('click', function () { pick(el); }); })(pts[i]);
}`;

// ── (2) Science: order the stages, auto-graded with `ordered` ────────────────
const SCIENCE_HTML = `<div class="s">
  <p class="s-prompt">رتّب مراحل عملية البناء الضوئي من الأولى إلى الأخيرة (اسحب وأفلت)</p>
  <ul class="s-list" id="s-list">
    <li class="s-item" draggable="true" data-key="light">امتصاص ضوء الشمس بواسطة الكلوروفيل</li>
    <li class="s-item" draggable="true" data-key="co2">دخول ثاني أكسيد الكربون عبر الثغور</li>
    <li class="s-item" draggable="true" data-key="reactions">تفاعلات كيميائية داخل البلاستيدة</li>
    <li class="s-item" draggable="true" data-key="sugar">إنتاج الجلوكوز وانطلاق الأكسجين</li>
  </ul>
  <p class="s-hint">اسحب كل عنصر لتغيير ترتيبه.</p>
</div>`;

const SCIENCE_CSS = `.s { font-family: inherit; }
.s-prompt { font-weight: 700; margin: 0 0 10px; }
.s-list { list-style: none; margin: 0; padding: 0; display: grid; gap: 8px; }
.s-item { padding: 12px 14px; border: 1px solid #cbd5e1; border-radius: 12px; background: #f8fafc; cursor: grab; font-size: 14px; }
.s-item:active { cursor: grabbing; }
.s-item.s-drag { opacity: .5; }
.s-hint { font-size: 12px; color: #64748b; margin: 10px 0 0; }`;

const SCIENCE_JS = `var list = document.getElementById('s-list');
var dragging = null;

function report() {
  var items = [].slice.call(list.querySelectorAll('li')).map(function (li) { return li.getAttribute('data-key'); });
  FahimniQuestion.setAnswer(items);
}

list.addEventListener('dragstart', function (e) {
  dragging = e.target.closest ? e.target.closest('li') : e.target;
  if (dragging) dragging.classList.add('s-drag');
  if (e.dataTransfer) e.dataTransfer.effectAllowed = 'move';
});
list.addEventListener('dragover', function (e) {
  e.preventDefault();
  if (!dragging) return;
  var target = e.target.closest ? e.target.closest('li') : null;
  if (!target || target === dragging) return;
  var rect = target.getBoundingClientRect();
  var after = (e.clientY - rect.top) > rect.height / 2;
  list.insertBefore(dragging, after ? target.nextSibling : target);
});
list.addEventListener('drop', function (e) { e.preventDefault(); report(); });
list.addEventListener('dragend', function () {
  if (dragging) dragging.classList.remove('s-drag');
  report();
});

// Allow keyboard/click fallback (accessibility + touch): clicking an item moves
// it one step down.
list.addEventListener('click', function (e) {
  var li = e.target.closest ? e.target.closest('li') : null;
  if (!li || li.nextElementSibling === null) return;
  list.insertBefore(li.nextElementSibling, li);
  report();
});`;

// ── (3) Simulation: projectile sliders, manual review ────────────────────────
const SIM_HTML = `<div class="p">
  <p class="p-prompt">اضبط الزاوية والسرعة لتصيب الهدف، ثم لاحظ المسار.</p>
  <label class="p-row">الزاوية: <output id="p-angle-out">45</output>°
    <input id="angle" name="angle" type="range" min="10" max="80" value="45" />
  </label>
  <label class="p-row">السرعة: <output id="p-speed-out">20</output> م/ث
    <input id="speed" name="speed" type="range" min="5" max="40" value="20" />
  </label>
  <svg viewBox="0 0 320 160" class="p-canvas" role="img" aria-label="مسار المقذوف">
    <line x1="8" y1="150" x2="312" y2="150" class="p-ground" />
    <circle id="p-target" cx="250" cy="150" r="7" class="p-target" />
    <path id="p-path" class="p-path" fill="none" d="" />
    <circle id="p-ball" cx="8" cy="150" r="6" class="p-ball" />
  </svg>
  <p class="p-hint" id="p-hint">المدى الحالي: —</p>
</div>`;

const SIM_CSS = `.p { font-family: inherit; }
.p-prompt { font-weight: 700; margin: 0 0 8px; }
.p-row { display: block; margin: 6px 0; font-size: 14px; }
.p-row input[type="range"] { width: 100%; }
.p-canvas { width: 100%; max-width: 340px; height: auto; display: block; margin-top: 8px; }
.p-ground { stroke: #94a3b8; stroke-width: 2; }
.p-target { fill: #ef4444; }
.p-path { stroke: #0d9488; stroke-width: 2; stroke-dasharray: 4 3; }
.p-ball { fill: #0f766e; }
.p-hint { font-size: 13px; color: #475569; margin: 8px 0 0; }`;

const SIM_JS = `var angle = document.getElementById('angle');
var speed = document.getElementById('speed');
var path = document.getElementById('p-path');
var ball = document.getElementById('p-ball');
var hint = document.getElementById('p-hint');
var angleOut = document.getElementById('p-angle-out');
var speedOut = document.getElementById('p-speed-out');

function redraw() {
  var a = Number(angle.value) * Math.PI / 180;
  var v = Number(speed.value);
  var g = 9.8;
  // Scale metres → viewBox units (max range ≈ v^2/g ≈ 163 m at v=40).
  var scale = 1.7;
  if (angleOut) angleOut.textContent = angle.value;
  if (speedOut) speedOut.textContent = speed.value;
  var pts = [];
  var maxT = (2 * v * Math.sin(a)) / g;
  for (var t = 0; t <= maxT; t += maxT / 24) {
    var x = 8 + v * Math.cos(a) * t * scale;
    var y = 150 - (v * Math.sin(a) * t - 0.5 * g * t * t) * scale;
    pts.push((pts.length ? 'L' : 'M') + x.toFixed(1) + ' ' + Math.max(2, Math.min(150, y)).toFixed(1));
  }
  if (path) path.setAttribute('d', pts.join(' '));
  if (ball) { ball.setAttribute('cx', (8 + v * Math.cos(a) * maxT * scale).toFixed(1)); ball.setAttribute('cy', '150'); }
  if (hint) hint.textContent = 'المدى الحالي: ' + (v * v * Math.sin(2 * a) / 9.8).toFixed(1) + ' م';
}

angle.addEventListener('input', redraw);
speed.addEventListener('input', redraw);
redraw();`;

export const INTERACTIVE_EXAMPLES: InteractiveExample[] = [
  {
    id: 'math-line',
    titleAr: 'رياضيات: اختر نقطة على المستقيم (تصحيح تلقائي)',
    titleEn: 'Math: pick a point on the line (auto-graded)',
    html: MATH_HTML,
    css: MATH_CSS,
    js: MATH_JS,
    height: 340,
    autoCapture: false,
    gradingMode: 'auto',
    gradingRule: 'json_equals',
    gradingKey: '{"x":2,"y":5}',
    tolerance: '',
  },
  {
    id: 'science-order',
    titleAr: 'علوم: رتّب مراحل البناء الضوئي (تصحيح تلقائي)',
    titleEn: 'Science: order photosynthesis stages (auto-graded)',
    html: SCIENCE_HTML,
    css: SCIENCE_CSS,
    js: SCIENCE_JS,
    height: 320,
    autoCapture: false,
    gradingMode: 'auto',
    gradingRule: 'ordered',
    gradingKey: '["light","co2","reactions","sugar"]',
    tolerance: '',
  },
  {
    id: 'physics-sim',
    titleAr: 'محاكاة فيزيائية: مسار المقذوف (مراجعة يدوية)',
    titleEn: 'Physics sim: projectile path (manual review)',
    html: SIM_HTML,
    css: SIM_CSS,
    js: SIM_JS,
    height: 360,
    autoCapture: true,
    gradingMode: 'manual',
    gradingRule: 'exact_set',
    gradingKey: '',
    tolerance: '',
  },
];
