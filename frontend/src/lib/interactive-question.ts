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
