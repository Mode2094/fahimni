/**
 * Trusted postMessage protocol between the Fahimni host app and the isolated
 * interactive-question sandbox iframe (served at `SANDBOX_PATH`).
 *
 * The sandbox has an opaque origin and runs teacher-authored JavaScript, so the
 * host never trusts it: every inbound message is checked against this module
 * (channel, message type, mount token, source window and payload size). The
 * sandbox can only ever report an answer, a size hint or an error — it can
 * never change a score, the timer or the exam state.
 *
 * Kept dependency-free and framework-free so it can be unit-tested with
 * `node --test` and reused by the harness.
 */

export const IQ_CHANNEL = 'fahimni-question';

/**
 * Query-string parameter that carries the per-mount token to the sandbox
 * document. The harness reads it back out of its own URL and echoes it in the
 * `ready` handshake, which lets the host prove the frame is still the sandbox
 * document it created — a self-navigated or forged frame cannot reproduce it.
 */
export const IQ_MOUNT_PARAM = 'm';

export const IQ_LIMITS = {
  /** Server mirrors these in StoreQuestionRequest. */
  html: 200_000,
  css: 100_000,
  js: 100_000,
  /** Client-side answer cap (server allows 64KB; be stricter here). */
  answerBytes: 32_768,
  heightMin: 120,
  heightMax: 1600,
  heightDefault: 360,
  /** Drop a message larger than this without parsing further. */
  messageBytes: 400_000,
} as const;

export type IqChildType = 'ready' | 'answer' | 'resize' | 'error';

export interface IqChildMessage {
  channel: typeof IQ_CHANNEL;
  type: IqChildType;
  /** Empty on the initial handshake `ready`; the mount token otherwise. */
  token: string;
  questionId?: string;
  payload?: {
    value?: unknown;
    height?: number;
    message?: string;
  };
}

export interface IqInitPayload {
  html: string;
  css: string;
  js: string;
  height: number;
  autoCapture: boolean;
  value: unknown;
  locked: boolean;
}

const CHILD_TYPES: readonly IqChildType[] = ['ready', 'answer', 'resize', 'error'];

export function createMountToken(): string {
  const c = globalThis.crypto;

  if (c && typeof c.randomUUID === 'function') {
    return c.randomUUID();
  }
  if (c && typeof c.getRandomValues === 'function') {
    const bytes = new Uint8Array(16);
    c.getRandomValues(bytes);

    return Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
  }

  // Last resort (non-crypto environments): still unique enough to reject a
  // stale iframe; never used as a security boundary on its own.
  return `t-${Date.now()}-${Math.random().toString(36).slice(2)}`;
}

/** Shape-check an inbound sandbox message. Token is verified separately. */
export function isIqChildMessage(data: unknown): data is IqChildMessage {
  if (!data || typeof data !== 'object' || Array.isArray(data)) {
    return false;
  }

  const m = data as Record<string, unknown>;

  if (m.channel !== IQ_CHANNEL) {
    return false;
  }
  if (typeof m.type !== 'string' || !CHILD_TYPES.includes(m.type as IqChildType)) {
    return false;
  }
  if (m.token !== undefined && typeof m.token !== 'string') {
    return false;
  }
  if (typeof m.token === 'string' && m.token.length > 200) {
    return false;
  }
  if (m.questionId !== undefined && typeof m.questionId !== 'string') {
    return false;
  }
  if (m.payload !== undefined && (typeof m.payload !== 'object' || m.payload === null)) {
    return false;
  }

  return true;
}

/**
 * Build the sandbox iframe URL for a mount. The token is a random capability,
 * not teacher content: the harness only uses it to prove *which* mount it is,
 * and an external page a self-navigation lands on can never know it.
 */
export function buildSandboxUrl(basePath: string, mountToken: string): string {
  const sep = basePath.includes('?') ? '&' : '?';

  return `${basePath}${sep}${IQ_MOUNT_PARAM}=${encodeURIComponent(mountToken)}`;
}

/** Read the mount token out of a `location.search`-style string (harness side). */
export function parseMountTokenFromSearch(search: string): string {
  const raw = String(search ?? '');
  const query = raw.startsWith('?') ? raw.slice(1) : raw;
  const token = new URLSearchParams(query).get(IQ_MOUNT_PARAM);

  return token ? token.slice(0, 200) : '';
}

export type IqGateResult =
  | { kind: 'ignore' }
  | { kind: 'send-init' }
  | { kind: 'ready' }
  | { kind: 'answer'; value: unknown }
  | { kind: 'resize'; height: unknown }
  | { kind: 'error'; message: string };

export interface IqHostGate {
  /** The mount token this host generated (must be non-empty). */
  token: string;
  /** True once `init` has been sent for this mount. */
  initialized: boolean;
  /** True once the frame navigated away from our sandbox document. */
  navigated: boolean;
}

/**
 * The single trust decision for an inbound sandbox message. Kept pure and
 * framework-free so it can be exhaustively unit-tested; the React host only
 * reacts to the returned verb.
 *
 * Rules:
 *  - a `navigated` frame is ignored entirely (never re-init, never re-send the
 *    student's answer to a document that replaced our sandbox);
 *  - only a `ready` carrying the exact mount token may trigger `init`, and only
 *    once per mount (a second `ready` is the post-init ack);
 *  - every other message must carry the mount token and arrive after init.
 */
export function decideIqChildMessage(data: unknown, gate: IqHostGate): IqGateResult {
  if (!gate.token || gate.navigated) {
    return { kind: 'ignore' };
  }
  if (!isIqChildMessage(data)) {
    return { kind: 'ignore' };
  }

  const msg = data;

  if (msg.type === 'ready') {
    if (msg.token !== gate.token) {
      return { kind: 'ignore' };
    }

    return gate.initialized ? { kind: 'ready' } : { kind: 'send-init' };
  }

  if (msg.token !== gate.token || !gate.initialized) {
    return { kind: 'ignore' };
  }

  switch (msg.type) {
    case 'answer':
      return { kind: 'answer', value: msg.payload?.value };
    case 'resize':
      return { kind: 'resize', height: msg.payload?.height };
    case 'error':
      return { kind: 'error', message: String(msg.payload?.message ?? 'error') };
    default:
      return { kind: 'ignore' };
  }
}

/**
 * Clamp a sandbox-reported height to the allowed band.
 */
export function clampHeight(height: unknown): number {
  const n = typeof height === 'number' && Number.isFinite(height) ? height : IQ_LIMITS.heightDefault;

  return Math.min(IQ_LIMITS.heightMax, Math.max(IQ_LIMITS.heightMin, Math.round(n)));
}
/**
 * Drop non-JSON-serializable and oversized values. Returns `{ ok: false }` when
 * the value cannot be represented safely, so the host can ignore it instead of
 * crashing.
 */
export function sanitizeAnswer(value: unknown, maxBytes: number = IQ_LIMITS.answerBytes): { ok: boolean; value: unknown; serialized: string } {
  let serialized: string;

  try {
    serialized = JSON.stringify(value ?? null) ?? 'null';
  } catch {
    return { ok: false, value: null, serialized: '' };
  }

  if (serialized.length > maxBytes) {
    return { ok: false, value: null, serialized };
  }

  try {
    return { ok: true, value: JSON.parse(serialized), serialized };
  } catch {
    return { ok: false, value: null, serialized };
  }
}

/**
 * Convert a reported answer into the string the exam submit path expects:
 * scalars stay as-is, structures become a JSON string (matching how the exam
 * page already serializes arrays/objects).
 */
export function answerToSubmitValue(value: unknown): string {
  if (typeof value === 'string') {
    return value;
  }
  if (value === undefined || value === null) {
    return '';
  }

  try {
    return JSON.stringify(value) ?? '';
  } catch {
    return '';
  }
}

/** Build the init message the host sends once the sandbox reports ready. */
export function buildInitMessage(token: string, questionId: string, payload: IqInitPayload): Record<string, unknown> {
  return {
    channel: IQ_CHANNEL,
    type: 'init',
    token,
    questionId,
    payload: {
      html: String(payload.html ?? '').slice(0, IQ_LIMITS.html),
      css: String(payload.css ?? '').slice(0, IQ_LIMITS.css),
      js: String(payload.js ?? '').slice(0, IQ_LIMITS.js),
      height: clampHeight(payload.height),
      autoCapture: payload.autoCapture !== false,
      value: payload.value ?? null,
      locked: !!payload.locked,
    },
  };
}

/** Render a stored grading key back into the text the teacher edits. */
export function gradingKeyToText(key: unknown): string {
  if (key === undefined || key === null) return '';
  if (typeof key === 'string') return key;
  try {
    return JSON.stringify(key);
  } catch {
    return '';
  }
}

/**
 * Parse the teacher's answer-key text into the JSON value to store. Structured
 * text (`{…}` / `[…]` / `"…"`) is parsed, `true`/`false` become booleans, plain
 * numeric text becomes a number, and anything else stays a string.
 */
export function parseGradingKey(text: string): unknown {
  const trimmed = (text ?? '').trim();
  if (trimmed === '') return '';

  if (trimmed[0] === '{' || trimmed[0] === '[' || trimmed[0] === '"') {
    try {
      return JSON.parse(trimmed);
    } catch {
      return text;
    }
  }
  if (trimmed === 'true') return true;
  if (trimmed === 'false') return false;
  if (/^-?\d+(\.\d+)?$/.test(trimmed)) return Number(trimmed);

  return text;
}

export interface SplitDocumentResult {
  html: string;
  css: string;
  js: string;
  /** True when the input looked like a full HTML document and was parsed. */
  detected: boolean;
  extractedCss: boolean;
  extractedJs: boolean;
}

/**
 * Split a pasted full HTML document into the three editor tabs.
 *
 * Parsing uses `DOMParser`, whose documents are inert: `<script>` never runs
 * and no subresource is fetched. Inline `<style>` / `<script>` bodies are moved
 * into the css/js fields (so they land in the correct tab and are only ever
 * executed through the sandbox's own controlled `js` field, never via
 * `innerHTML`). External `<script src>`/`<link>` tags are deliberately left out
 * of the code tabs — the sandbox forbids network access anyway.
 *
 * Falls back to `detected: false` (leave the text untouched in the HTML tab) so
 * teachers who paste partial fragments keep the current manual workflow.
 */
export function splitFullDocument(input: string): SplitDocumentResult {
  const raw = String(input ?? '');
  const passthrough: SplitDocumentResult = {
    html: raw, css: '', js: '', detected: false, extractedCss: false, extractedJs: false,
  };

  if (typeof DOMParser === 'undefined') {
    return passthrough;
  }

  // Only treat it as a full document when it actually carries document-level
  // markup or embedded code; a plain fragment such as `<div>…</div>` is left
  // exactly where the teacher put it.
  if (!/<!doctype\s+html|<html[\s>]|<head[\s>]|<body[\s>]|<style[\s>]|<script[\s>]/i.test(raw)) {
    return passthrough;
  }

  let doc: Document;
  try {
    doc = new DOMParser().parseFromString(raw, 'text/html');
  } catch {
    return passthrough;
  }

  let css = '';
  doc.querySelectorAll('style').forEach((el) => {
    css += (css ? '\n' : '') + (el.textContent || '');
    el.remove();
  });

  let js = '';
  doc.querySelectorAll('script').forEach((el) => {
    if (!el.getAttribute('src')) {
      js += (js ? '\n' : '') + (el.textContent || '');
    }
    el.remove();
  });

  const html = (doc.body ? doc.body.innerHTML : raw).trim();

  return {
    html: html || raw,
    css,
    js,
    detected: true,
    extractedCss: css.trim().length > 0,
    extractedJs: js.trim().length > 0,
  };
}
