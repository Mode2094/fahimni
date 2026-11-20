import { test, expect, type Page } from '@playwright/test';
import { INTERACTIVE_EXAMPLES } from '../src/lib/interactive-examples';
import {
  IQ_CHANNEL,
  buildInitMessage,
  buildSandboxUrl,
  createMountToken,
} from '../src/lib/interactive-question';

/**
 * Real-browser verification of the interactive-question sandbox.
 *
 * Nothing here is mocked: the real harness document is served by `next start`,
 * the real Content-Security-Policy is enforced by Chromium, and the frame is
 * created with the exact `sandbox="allow-scripts"` attribute the app uses. The
 * mount URL and init message come from the real protocol module, and the host
 * gate below mirrors `decideIqChildMessage` (which is exercised exhaustively in
 * Vitest). These specs are the proof that TypeScript / a unit test cannot give.
 */

const SANDBOX_URL = '/embed/question';
const CHANNEL = IQ_CHANNEL;

type SandboxConfig = {
  html: string;
  css?: string;
  js?: string;
  autoCapture?: boolean;
  height?: number;
  value?: unknown;
};

/**
 * Mount the sandbox in a same-origin host page and speak the real protocol.
 * The token is baked into the iframe URL and required back on `ready`; a second
 * frame load marks the frame as navigated and permanently ignored.
 */
async function mountSandbox(page: Page, cfg: SandboxConfig): Promise<string> {
  const token = createMountToken();
  const url = buildSandboxUrl(SANDBOX_URL, token);
  const init = buildInitMessage(token, 'q1', {
    html: cfg.html,
    css: cfg.css || '',
    js: cfg.js || '',
    height: cfg.height || 320,
    autoCapture: cfg.autoCapture !== false,
    value: cfg.value ?? null,
    locked: false,
  });

  await page.evaluate(
    ({ url, token, init, channel }) => {
      const w = window as any;
      document.getElementById('iq-frame')?.remove();

      const state = {
        msgs: [] as any[],
        out: [] as any[],
        ready: false,
        initSent: false,
        navigated: false,
        loads: 0,
        token,
      };
      w.__iq = state;

      const frame = document.createElement('iframe');
      frame.id = 'iq-frame';
      frame.setAttribute('sandbox', 'allow-scripts'); // same attribute as the app
      frame.setAttribute('title', 'interactive-question');
      frame.style.width = '520px';
      frame.style.height = '340px';
      frame.src = url;
      document.body.appendChild(frame);

      // Mirrors the host component: never init from `load`, and treat any load
      // after the first as "the frame left our sandbox document" (off-limits).
      frame.addEventListener('load', () => {
        state.loads += 1;
        if (state.loads > 1) state.navigated = true;
      });

      w.addEventListener('message', (event: MessageEvent) => {
        if (event.source !== frame.contentWindow) return; // source window is the trust boundary
        const data = event.data;
        if (!data || data.channel !== channel) return;
        state.msgs.push(data);

        if (state.navigated) return;
        if (data.type === 'ready' && data.token === token && !state.initSent) {
          state.initSent = true;
          state.out.push(init);
          frame.contentWindow!.postMessage(init, '*');
        } else if (data.type === 'ready' && data.token === token) {
          state.ready = true;
        }
      });
    },
    { url, token, init, channel: CHANNEL },
  );

  await page.waitForFunction(() => (window as any).__iq?.ready === true, undefined, { timeout: 15_000 });
  return token;
}

/** The current sandbox frame (there is at most one `#iq-frame`). */
function sandboxFrame(page: Page) {
  return page.frames().find((f) => f.url().includes(SANDBOX_URL));
}

async function answers(page: Page): Promise<any[]> {
  return page.evaluate(() => ((window as any).__iq.msgs as any[]).filter((m) => m.type === 'answer').map((m) => m.payload.value));
}

async function errors(page: Page): Promise<string[]> {
  return page.evaluate(() => ((window as any).__iq.msgs as any[]).filter((m) => m.type === 'error').map((m) => String(m.payload?.message)));
}

test.beforeEach(async ({ page }) => {
  await page.goto('/'); // same-origin, non-sandboxed host page
});

test('the sandbox document is served with its own locked-down CSP and an opaque origin', async ({ page }) => {
  const response = await page.goto(SANDBOX_URL);
  expect(response).not.toBeNull();

  const csp = response!.headers()['content-security-policy'] || '';
  expect(csp).toContain("default-src 'none'");
  expect(csp).toContain("connect-src 'none'");
  expect(csp).toContain("frame-src 'none'");
  expect(csp).toContain('sandbox allow-scripts');
  expect(csp).not.toContain('allow-same-origin');
  // The app's nonce policy must NOT be on this document.
  expect(csp).not.toContain('strict-dynamic');
  expect(response!.headers()['x-content-type-options']).toBe('nosniff');

  // The harness script actually ran under that policy and exposed the tiny API.
  const apiType = await page.evaluate(() => typeof (window as any).FahimniQuestion);
  expect(apiType).toBe('object');
  await expect(page.locator('#fq-root')).toBeAttached();

  // The CSP `sandbox` directive gives even a TOP-LEVEL load an opaque origin,
  // so app cookies are unreachable.
  const origin = await page.evaluate(() => window.origin);
  expect(origin).toBe('null');
  const cookie = await page.evaluate(() => {
    try {
      return document.cookie;
    } catch {
      // Chromium throws here because the document is sandboxed without
      // allow-same-origin — the strongest possible isolation signal.
      return 'BLOCKED';
    }
  });
  expect(cookie).not.toContain('TOPSECRET');
  expect(['BLOCKED', '']).toContain(cookie);
});

test('runs teacher HTML/CSS/JS and reports the answer through the validated protocol', async ({ page }) => {
  await mountSandbox(page, {
    html: '<button id="pick" class="pick">اختر</button><p id="out"></p>',
    css: '.pick { background: #0d9488; color: #fff; border: 0; padding: 10px 18px; border-radius: 10px; }',
    js: `
      var b = document.getElementById('pick');
      b.addEventListener('click', function () {
        document.getElementById('out').textContent = 'تم';
        FahimniQuestion.setAnswer({ choice: 'A', n: 3 });
      });
    `,
  });

  await page.frameLocator('#iq-frame').locator('#pick').click();

  await expect.poll(() => answers(page)).toContainEqual({ choice: 'A', n: 3 });
});

test('auto-captures standard form fields without any teacher JS', async ({ page }) => {
  await mountSandbox(page, {
    html: '<label>درجة الحرارة <input id="temp" name="temperature" type="number" value="80" /></label>',
    autoCapture: true,
    js: '',
  });

  await page.frameLocator('#iq-frame').locator('#temp').fill('85');

  await expect.poll(async () => (await answers(page)).length).toBeGreaterThan(0);
  expect(await answers(page)).toContain(85);
});

test('restores a previously saved answer when the question is revisited', async ({ page }) => {
  await mountSandbox(page, {
    html: '<div id="ro"></div>',
    value: { restored: 42 },
    js: `FahimniQuestion.onRestore(function (v) { FahimniQuestion.setAnswer({ seen: v }); });`,
  });

  await expect.poll(() => answers(page)).toContainEqual({ seen: { restored: 42 } });
});

test('restores scalar, number, checkbox, radio and structured answers into their fields', async ({ page }) => {
  // A single text field reporting a bare string (the bug: restore only handled
  // objects, so a lone field was never refilled).
  await mountSandbox(page, { html: '<input id="a" name="a" type="text" />', value: 'hello world' });
  await expect(page.frameLocator('#iq-frame').locator('#a')).toHaveValue('hello world');

  // A single number field.
  await mountSandbox(page, { html: '<input id="n" name="n" type="number" />', value: 42 });
  await expect(page.frameLocator('#iq-frame').locator('#n')).toHaveValue('42');

  // A single checkbox reporting a boolean.
  await mountSandbox(page, { html: '<input id="c" name="c" type="checkbox" value="yes" />', value: true });
  await expect(page.frameLocator('#iq-frame').locator('#c')).toBeChecked();

  // A checkbox group reporting an array under one name.
  await mountSandbox(page, {
    html: '<input id="c1" name="c" type="checkbox" value="a" /><input id="c2" name="c" type="checkbox" value="b" /><input id="c3" name="c" type="checkbox" value="c" />',
    value: { c: ['a', 'c'] },
  });
  await expect(page.frameLocator('#iq-frame').locator('#c1')).toBeChecked();
  await expect(page.frameLocator('#iq-frame').locator('#c2')).not.toBeChecked();
  await expect(page.frameLocator('#iq-frame').locator('#c3')).toBeChecked();

  // A radio group reporting the picked value under one name.
  await mountSandbox(page, {
    html: '<input id="r1" name="r" type="radio" value="x" /><input id="r2" name="r" type="radio" value="y" />',
    value: { r: 'y' },
  });
  await expect(page.frameLocator('#iq-frame').locator('#r2')).toBeChecked();
  await expect(page.frameLocator('#iq-frame').locator('#r1')).not.toBeChecked();

  // Structured values across several named fields.
  await mountSandbox(page, {
    html: '<input id="w" name="w" type="number" /><input id="h" name="h" type="number" />',
    value: { w: 3, h: 4 },
  });
  await expect(page.frameLocator('#iq-frame').locator('#w')).toHaveValue('3');
  await expect(page.frameLocator('#iq-frame').locator('#h')).toHaveValue('4');
});

test('a single-field answer survives navigating away from and back to the question', async ({ page }) => {
  const html = '<input id="a" name="a" type="text" />';

  // Answer, then leave the question (the frame is torn down).
  await mountSandbox(page, { html });
  await page.frameLocator('#iq-frame').locator('#a').fill('my answer');
  await expect.poll(() => answers(page)).toContain('my answer');

  // Return: a fresh mount that carries the stored answer as its initial value.
  await mountSandbox(page, { html, value: 'my answer' });
  await expect(page.frameLocator('#iq-frame').locator('#a')).toHaveValue('my answer');
});

test('a self-navigated frame never receives init again or the student answer', async ({ page }) => {
  await mountSandbox(page, {
    html: '<input id="a" name="a" type="text" />',
    js: `FahimniQuestion.setAnswer('SECRET');`,
  });
  await expect.poll(() => answers(page)).toContain('SECRET');

  const before = await page.evaluate(() => (window as any).__iq.out.length);

  // From *inside* the frame, navigate it to a tokenless copy of the sandbox —
  // a stand-in for any document that replaced our sandbox document.
  await sandboxFrame(page)!.evaluate(() => {
    window.location.href = '/embed/question';
  });

  // Give the replacement document time to ask for init; the host must refuse.
  await page.waitForTimeout(1500);

  const state = await page.evaluate(() => ({
    out: (window as any).__iq.out.length,
    navigated: (window as any).__iq.navigated,
    loads: (window as any).__iq.loads,
  }));

  expect(state.loads).toBeGreaterThan(1); // the frame really navigated
  expect(state.navigated).toBe(true);
  expect(state.out).toBe(before); // no second init was ever posted

  // The navigated document was never configured: no teacher HTML, no value.
  const replacement = await sandboxFrame(page)!.evaluate(() => ({
    value: (window as any).FahimniQuestion.getValue(),
    stageHtml: document.getElementById('fq-stage')?.innerHTML ?? '',
  }));
  expect(replacement.stageHtml).toBe('');
  expect(replacement.value).toBeNull();
});

test('the harness refuses an init that does not carry its own mount token', async ({ page }) => {
  const token = createMountToken();

  await page.evaluate(
    ({ url, token, channel }) => {
      const w = window as any;
      const frame = document.createElement('iframe');
      frame.id = 'iq-frame';
      frame.setAttribute('sandbox', 'allow-scripts');
      frame.src = url;
      document.body.appendChild(frame);

      w.addEventListener('message', (event: MessageEvent) => {
        if (event.source !== frame.contentWindow) return;
        if (!event.data || event.data.channel !== channel) return;
        if (event.data.type !== 'ready') return;

        // A forged init with the wrong token must be ignored …
        frame.contentWindow!.postMessage(
          { channel, type: 'init', token: 'WRONG-TOKEN', payload: { html: '<div id="leak">leaked</div>' } },
          '*',
        );
        // … while the legitimate one still configures the frame.
        frame.contentWindow!.postMessage(
          { channel, type: 'init', token, payload: { html: '<div id="ok">ok</div>' } },
          '*',
        );
      });
    },
    { url: buildSandboxUrl(SANDBOX_URL, token), token, channel: CHANNEL },
  );

  await expect(page.frameLocator('#iq-frame').locator('#ok')).toBeVisible();
  await expect(page.frameLocator('#iq-frame').locator('#leak')).toHaveCount(0);
});

test('a duplicate init for the same mount never re-runs teacher JS', async ({ page }) => {
  const token = await mountSandbox(page, {
    html: '<div id="one">one</div>',
    js: `window.__runs = (window.__runs || 0) + 1; FahimniQuestion.setAnswer(window.__runs);`,
  });
  await expect.poll(() => answers(page)).toContain(1);

  await page.evaluate(
    ({ token, channel }) => {
      const frame = document.getElementById('iq-frame') as HTMLIFrameElement;
      frame.contentWindow!.postMessage(
        {
          channel,
          type: 'init',
          token,
          payload: {
            html: '<div id="two">two</div>',
            js: 'window.__runs = (window.__runs || 0) + 1; FahimniQuestion.setAnswer(window.__runs);',
          },
        },
        '*',
      );
    },
    { token, channel: CHANNEL },
  );

  await page.waitForTimeout(400);
  expect(await sandboxFrame(page)!.evaluate(() => (window as any).__runs)).toBe(1);
  await expect(page.frameLocator('#iq-frame').locator('#one')).toBeAttached();
  await expect(page.frameLocator('#iq-frame').locator('#two')).toHaveCount(0);
});


test('an <script> inside the pasted HTML never executes (only the JS field does)', async ({ page }) => {
  await mountSandbox(page, {
    html: '<div id="x">hi</div><scr' + 'ipt>window.__htmlRan = true;</scr' + 'ipt>',
    js: `FahimniQuestion.setAnswer({ htmlRan: !!window.__htmlRan });`,
  });

  await expect.poll(() => answers(page)).toContainEqual({ htmlRan: false });
});

test('a runtime error in teacher code is reported and does not wedge the frame', async ({ page }) => {
  await mountSandbox(page, {
    html: '<button id="ok">ok</button>',
    js: `FahimniQuestion.setAnswer('captured-before-error'); throw new Error('boom-123');`,
  });

  await expect.poll(() => errors(page)).toContainEqual(expect.stringContaining('boom-123'));
  expect(await answers(page)).toContain('captured-before-error');

  // The frame is still alive and interactive after the throw.
  await expect(page.frameLocator('#iq-frame').locator('#ok')).toBeVisible();
});

test('the sandbox cannot read parent cookies, storage, or DOM (opaque origin)', async ({ page, context }) => {
  const origin = new URL(page.url()).origin;
  await context.addCookies([{ name: 'iqsecret', value: 'TOPSECRET', url: origin }]);
  await page.evaluate(() => localStorage.setItem('iqsecret', 'STORAGE'));

  await mountSandbox(page, {
    html: '<div id="probe"></div>',
    js: `
      var out = {};
      try { out.cookie = document.cookie; } catch (e) { out.cookie = 'BLOCKED'; }
      try { out.storage = String(localStorage.getItem('iqsecret')); } catch (e) { out.storage = 'BLOCKED'; }
      try { out.parentCookie = String(window.parent.document.cookie); } catch (e) { out.parentCookie = 'BLOCKED'; }
      try { out.parentTitle = String(window.parent.document.title); } catch (e) { out.parentTitle = 'BLOCKED'; }
      FahimniQuestion.setAnswer(out);
    `,
  });

  await expect.poll(async () => (await answers(page)).length).toBeGreaterThan(0);
  const probe = (await answers(page))[0] as Record<string, string>;

  expect(probe.cookie || '').not.toContain('TOPSECRET');
  expect(probe.parentCookie).toBe('BLOCKED');
  expect(probe.parentTitle).toBe('BLOCKED');
  expect(probe.storage).toBe('BLOCKED');
});

test('the sandbox cannot open popups or navigate the top page', async ({ page }) => {
  const popups: string[] = [];
  page.on('popup', (p) => popups.push(p.url()));

  const before = page.url();

  await mountSandbox(page, {
    html: '<div id="probe"></div>',
    js: `
      var opened = null;
      try { opened = window.open('https://example.com'); } catch (e) { opened = 'THREW'; }
      try { window.top.location.href = 'https://example.com'; } catch (e) {}
      FahimniQuestion.setAnswer({ opened: opened === null ? 'BLOCKED' : 'OPENED' });
    `,
  });

  await expect.poll(async () => (await answers(page)).length).toBeGreaterThan(0);
  const result = (await answers(page))[0] as { opened: string };

  expect(result.opened).toBe('BLOCKED');
  expect(popups).toHaveLength(0);
  // Give any (wrongly) allowed navigation a moment to happen, then assert it did not.
  await page.waitForTimeout(750);
  expect(page.url()).toBe(before);
});

test('the harness ignores messages from any window that is not its parent', async ({ page }) => {
  await mountSandbox(page, {
    html: '<div id="n"></div>',
    js: `FahimniQuestion.setAnswer('one');`,
  });

  await expect.poll(() => answers(page)).toContainEqual('one');

  // A forged message from a sibling popup/window must not change the state.
  await page.evaluate((channel) => {
    window.postMessage({ channel, type: 'answer', token: 'forged', payload: { value: 'FORGED' } }, '*');
  }, CHANNEL);

  await page.waitForTimeout(300);
  expect(await answers(page)).not.toContain('FORGED');
});

// ── the three shipped examples must work in a real browser ───────────────────

const example = (id: string) => {
  const found = INTERACTIVE_EXAMPLES.find((e) => e.id === id);
  if (!found) throw new Error(`missing example ${id}`);
  return found;
};

async function mountExample(page: Page, id: string) {
  const ex = example(id);
  await mountSandbox(page, { html: ex.html, css: ex.css, js: ex.js, autoCapture: ex.autoCapture, height: ex.height });
  await page.waitForTimeout(250);
  expect(await errors(page), `${id} must not raise a runtime error`).toHaveLength(0);
}

test('example 1 (math) works: clicking the correct point reports the key value', async ({ page }) => {
  await mountExample(page, 'math-line');

  // The hidden key is {x:2,y:5}; click exactly that point in the SVG.
  await page.frameLocator('#iq-frame').locator('.m-pt[data-x="2"][data-y="5"]').click();

  await expect.poll(() => answers(page)).toContainEqual({ x: 2, y: 5 });
});

test('example 2 (science) works: the ordered stages report as the key order', async ({ page }) => {
  await mountExample(page, 'science-order');

  const frame = page.frames().find((f) => f.url().includes(SANDBOX_URL));
  expect(frame).toBeDefined();

  // Reorder the list into the key order, then trigger the example's own report.
  await frame!.evaluate((keys) => {
    const list = document.getElementById('s-list')!;
    const byKey: Record<string, Element> = {};
    list.querySelectorAll('li').forEach((li) => {
      byKey[li.getAttribute('data-key')!] = li;
    });
    list.innerHTML = '';
    keys.forEach((k) => list.appendChild(byKey[k]));
    list.dispatchEvent(new Event('drop', { bubbles: true }));
  }, ['light', 'co2', 'reactions', 'sugar']);

  await expect.poll(async () => (await answers(page)).length).toBeGreaterThan(0);
  expect(await answers(page)).toContainEqual(['light', 'co2', 'reactions', 'sugar']);
});

test('example 3 (simulation) works: moving a slider auto-captures its fields', async ({ page }) => {
  await mountExample(page, 'physics-sim');

  const frame = page.frames().find((f) => f.url().includes(SANDBOX_URL));
  expect(frame).toBeDefined();

  await frame!.evaluate(() => {
    const angle = document.getElementById('angle') as HTMLInputElement;
    angle.value = '60';
    angle.dispatchEvent(new Event('input', { bubbles: true }));
  });

  await expect.poll(async () => (await answers(page)).length).toBeGreaterThan(0);
  const last = (await answers(page)).slice(-1)[0] as Record<string, unknown>;
  expect(last).toMatchObject({ angle: 60, speed: 20 });
});
