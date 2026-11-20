import { describe, it, expect } from 'vitest';
import {
  IQ_CHANNEL,
  IQ_LIMITS,
  IQ_MOUNT_PARAM,
  answerToSubmitValue,
  buildInitMessage,
  buildSandboxUrl,
  clampHeight,
  createMountToken,
  decideIqChildMessage,
  gradingKeyToText,
  isIqChildMessage,
  parseGradingKey,
  parseMountTokenFromSearch,
  sanitizeAnswer,
  splitFullDocument,
} from '@/lib/interactive-question';

describe('isIqChildMessage — the host trusts nothing from the sandbox', () => {
  it('accepts a well-formed message from the harness', () => {
    expect(isIqChildMessage({ channel: IQ_CHANNEL, type: 'answer', token: 'abc', payload: { value: 1 } })).toBe(true);
    expect(isIqChildMessage({ channel: IQ_CHANNEL, type: 'ready', token: '' })).toBe(true);
    expect(isIqChildMessage({ channel: IQ_CHANNEL, type: 'resize', payload: { height: 400 } })).toBe(true);
  });

  it('rejects a wrong channel, unknown type, or non-object payload', () => {
    expect(isIqChildMessage({ channel: 'evil', type: 'answer' })).toBe(false);
    expect(isIqChildMessage({ channel: IQ_CHANNEL, type: 'grade', token: 'x' })).toBe(false);
    expect(isIqChildMessage({ channel: IQ_CHANNEL, type: 'answer', payload: 42 })).toBe(false);
    expect(isIqChildMessage(null)).toBe(false);
    expect(isIqChildMessage('answer')).toBe(false);
    expect(isIqChildMessage([])).toBe(false);
  });

  it('rejects a suspiciously long token (message-shape guard)', () => {
    expect(isIqChildMessage({ channel: IQ_CHANNEL, type: 'answer', token: 'x'.repeat(201) })).toBe(false);
  });
});

describe('sanitizeAnswer — never let a runaway value reach the exam state', () => {
  it('round-trips JSON-serializable scalars and structures', () => {
    expect(sanitizeAnswer(3).value).toBe(3);
    expect(sanitizeAnswer('a').value).toBe('a');
    expect(sanitizeAnswer(['x', 'y']).value).toEqual(['x', 'y']);
    expect(sanitizeAnswer({ x: 2, y: 5 }).value).toEqual({ x: 2, y: 5 });
  });

  it('rejects oversized payloads', () => {
    const huge = 'x'.repeat(IQ_LIMITS.answerBytes + 1);
    const result = sanitizeAnswer(huge);
    expect(result.ok).toBe(false);
    expect(result.value).toBeNull();
  });

  it('rejects values that cannot be serialized (circular references)', () => {
    const circular: Record<string, unknown> = {};
    circular.self = circular;
    expect(sanitizeAnswer(circular).ok).toBe(false);
  });
});

describe('answerToSubmitValue — matches the existing exam submit contract', () => {
  it('keeps scalars and JSON-encodes structures', () => {
    expect(answerToSubmitValue('a')).toBe('a');
    expect(answerToSubmitValue(3)).toBe('3');
    expect(answerToSubmitValue(null)).toBe('');
    expect(answerToSubmitValue(undefined)).toBe('');
    expect(answerToSubmitValue(['x', 'y'])).toBe('["x","y"]');
    expect(answerToSubmitValue({ x: 2 })).toBe('{"x":2}');
  });
});

describe('clampHeight — bounds a sandbox-reported height', () => {
  it('clamps to the allowed band and falls back for garbage', () => {
    expect(clampHeight(10)).toBe(IQ_LIMITS.heightMin);
    expect(clampHeight(99999)).toBe(IQ_LIMITS.heightMax);
    expect(clampHeight(480)).toBe(480);
    expect(clampHeight(undefined)).toBe(IQ_LIMITS.heightDefault);
    expect(clampHeight('tall')).toBe(IQ_LIMITS.heightDefault);
    expect(clampHeight(NaN)).toBe(IQ_LIMITS.heightDefault);
  });
});

describe('buildInitMessage — display payload only, never the answer key', () => {
  it('truncates oversized code and clamps the height', () => {
    const msg = buildInitMessage('tok', 'q1', {
      html: 'h'.repeat(IQ_LIMITS.html + 50),
      css: 'c'.repeat(IQ_LIMITS.css + 50),
      js: 'j'.repeat(IQ_LIMITS.js + 50),
      height: 99999,
      autoCapture: true,
      value: null,
      locked: false,
    });

    const payload = msg.payload as Record<string, unknown>;
    expect((payload.html as string).length).toBe(IQ_LIMITS.html);
    expect((payload.css as string).length).toBe(IQ_LIMITS.css);
    expect((payload.js as string).length).toBe(IQ_LIMITS.js);
    expect(payload.height).toBe(IQ_LIMITS.heightMax);
    expect(msg.token).toBe('tok');
    expect(msg.questionId).toBe('q1');
    // No grading field can ever be part of the init message.
    expect(JSON.stringify(msg)).not.toContain('grading');
    expect(JSON.stringify(msg)).not.toContain('key');
  });

  it('defaults autoCapture on and locked off', () => {
    const payload = buildInitMessage('t', 'q', {
      html: '<div></div>', css: '', js: '', height: 300, autoCapture: true, value: null, locked: false,
    }).payload as Record<string, unknown>;

    expect(payload.autoCapture).toBe(true);
    expect(payload.locked).toBe(false);
    expect(payload.css).toBe('');
    expect(payload.js).toBe('');
  });
});

describe('createMountToken — unique per mount', () => {
  it('returns a distinct token each call', () => {
    const seen = new Set<string>();
    for (let i = 0; i < 200; i++) seen.add(createMountToken());
    expect(seen.size).toBe(200);
  });
});

describe('grading key text <-> value helpers', () => {
  it('renders stored keys back to editable text', () => {
    expect(gradingKeyToText('A')).toBe('A');
    expect(gradingKeyToText(3.14)).toBe('3.14');
    expect(gradingKeyToText(['x', 'y'])).toBe('["x","y"]');
    expect(gradingKeyToText({ x: 2, y: 5 })).toBe('{"x":2,"y":5}');
    expect(gradingKeyToText(null)).toBe('');
  });

  it('parses the teacher text into the stored JSON value', () => {
    expect(parseGradingKey('A')).toBe('A');
    expect(parseGradingKey('3.14')).toBe(3.14);
    expect(parseGradingKey('true')).toBe(true);
    expect(parseGradingKey('false')).toBe(false);
    expect(parseGradingKey('["x","y"]')).toEqual(['x', 'y']);
    expect(parseGradingKey('{"x":2,"y":5}')).toEqual({ x: 2, y: 5 });
    expect(parseGradingKey('  ')).toBe('');
    // Malformed JSON stays a plain string rather than throwing.
    expect(parseGradingKey('{oops')).toBe('{oops');
  });

  it('round-trips the three shipped examples', () => {
    expect(parseGradingKey('{"x":2,"y":5}')).toEqual({ x: 2, y: 5 });
    expect(parseGradingKey('["light","co2","reactions","sugar"]')).toEqual(['light', 'co2', 'reactions', 'sugar']);
    expect(parseGradingKey('')).toBe('');
  });
});

describe('mount token in the sandbox URL', () => {
  it('builds a URL the harness can read back', () => {
    const url = buildSandboxUrl('/embed/question', 'tok 1/2');
    expect(url.startsWith('/embed/question?')).toBe(true);
    expect(parseMountTokenFromSearch(url.slice(url.indexOf('?')))).toBe('tok 1/2');
  });

  it('preserves an existing query string', () => {
    const url = buildSandboxUrl('/embed/question?v=1', 'abc');
    expect(url).toContain('?v=1&');
    expect(parseMountTokenFromSearch(url.slice(url.indexOf('?')))).toBe('abc');
  });

  it('returns an empty token when none is present or it is oversized', () => {
    expect(parseMountTokenFromSearch('')).toBe('');
    expect(parseMountTokenFromSearch('?x=1')).toBe('');
    expect(parseMountTokenFromSearch(`?${IQ_MOUNT_PARAM}=${'a'.repeat(500)}`).length).toBe(200);
  });
});

describe('decideIqChildMessage — the host trust boundary', () => {
  const gate = (over: Partial<{ token: string; initialized: boolean; navigated: boolean }> = {}) => ({
    token: 'tok',
    initialized: false,
    navigated: false,
    ...over,
  });
  const ready = (token: string) => ({ channel: IQ_CHANNEL, type: 'ready', token });

  it('ignores everything when it has no mount token', () => {
    expect(decideIqChildMessage(ready('tok'), gate({ token: '' })).kind).toBe('ignore');
  });

  it('ignores everything once the frame has navigated away', () => {
    const g = gate({ initialized: true, navigated: true });
    expect(decideIqChildMessage(ready('tok'), g).kind).toBe('ignore');
    expect(decideIqChildMessage({ channel: IQ_CHANNEL, type: 'answer', token: 'tok', payload: { value: 1 } }, g).kind).toBe('ignore');
  });

  it('only accepts a handshake that carries the exact mount token', () => {
    expect(decideIqChildMessage(ready('wrong'), gate()).kind).toBe('ignore');
    expect(decideIqChildMessage(ready(''), gate()).kind).toBe('ignore');
    expect(decideIqChildMessage(ready('tok'), gate()).kind).toBe('send-init');
  });

  it('sends init at most once per mount, then only acks', () => {
    expect(decideIqChildMessage(ready('tok'), gate({ initialized: true })).kind).toBe('ready');
    // Never a second init from a repeated ready.
    expect(decideIqChildMessage(ready('tok'), gate({ initialized: true })).kind).not.toBe('send-init');
  });

  it('drops non-handshake messages until init has been sent', () => {
    const answer = { channel: IQ_CHANNEL, type: 'answer', token: 'tok', payload: { value: 'x' } };
    expect(decideIqChildMessage(answer, gate({ initialized: false })).kind).toBe('ignore');
    expect(decideIqChildMessage(answer, gate({ initialized: true })).kind).toBe('answer');
  });

  it('drops a forged answer whose token does not match', () => {
    const forged = { channel: IQ_CHANNEL, type: 'answer', token: 'forged', payload: { value: 'FORGED' } };
    expect(decideIqChildMessage(forged, gate({ initialized: true })).kind).toBe('ignore');
  });

  it('routes resize and error only for the mounted frame', () => {
    expect(decideIqChildMessage({ channel: IQ_CHANNEL, type: 'resize', token: 'tok', payload: { height: 400 } }, gate({ initialized: true }))).toEqual({ kind: 'resize', height: 400 });
    expect(decideIqChildMessage({ channel: IQ_CHANNEL, type: 'error', token: 'tok', payload: { message: 'boom' } }, gate({ initialized: true }))).toEqual({ kind: 'error', message: 'boom' });
  });
});

describe('splitFullDocument — safe extraction of a pasted document', () => {
  it('leaves a plain fragment untouched in the HTML tab', () => {
    const out = splitFullDocument('<div class="x">hi</div>');
    expect(out.detected).toBe(false);
    expect(out.html).toBe('<div class="x">hi</div>');
    expect(out.css).toBe('');
    expect(out.js).toBe('');
  });

  it('pulls inline <style> and <script> into the css/js fields', () => {
    const doc = [
      '<!DOCTYPE html>',
      '<html><head><style>.a{color:red}</style></head>',
      '<body><div id="root">hello</div>',
      '<scr' + 'ipt>FahimniQuestion.setAnswer(1);</scr' + 'ipt></body></html>',
    ].join('\n');

    const out = splitFullDocument(doc);
    expect(out.detected).toBe(true);
    expect(out.css).toContain('.a{color:red}');
    expect(out.js).toContain('FahimniQuestion.setAnswer(1)');
    expect(out.html).toContain('id="root"');
    // The extracted script must not remain in the HTML the sandbox injects.
    expect(out.html).not.toContain('setAnswer');
    expect(out.extractedCss).toBe(true);
    expect(out.extractedJs).toBe(true);
  });

  it('never executes scripts (no live DOM side effects) and keeps external refs out', () => {
    const doc = '<html><head><script src="https://evil.example/x.js"></scr' + 'ipt></head><body><p>ok</p></body></html>';
    const out = splitFullDocument(doc);
    expect(out.detected).toBe(true);
    expect(out.js).toBe('');
    expect(out.html).toContain('ok');
  });
});
