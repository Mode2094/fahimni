import { describe, it, expect } from 'vitest';
import { sanitizeHtmlEditorPrefs, HTML_EDITOR_DEFAULTS } from './html-editor-prefs';

/**
 * Preferences are read back from the server, so anything malformed must fall
 * back to a safe default rather than corrupt the editor (an out-of-range height
 * could break the sandbox iframe sizing).
 */
describe('sanitizeHtmlEditorPrefs', () => {
  it('coerces a valid stored payload', () => {
    expect(sanitizeHtmlEditorPrefs({ height: '700', grading_mode: 'manual', grading_rule: 'numeric', auto_capture: true }))
      .toEqual({ height: '700', grading_mode: 'manual', grading_rule: 'numeric', auto_capture: true });
  });

  it('falls back to defaults for null / garbage input', () => {
    expect(sanitizeHtmlEditorPrefs(null)).toEqual(HTML_EDITOR_DEFAULTS);
    expect(sanitizeHtmlEditorPrefs({ height: 'nope', grading_mode: 'weird' }).height).toBe(HTML_EDITOR_DEFAULTS.height);
    expect(sanitizeHtmlEditorPrefs({ grading_mode: 'weird' }).grading_mode).toBe('manual');
  });

  it('rejects an out-of-range height', () => {
    expect(sanitizeHtmlEditorPrefs({ height: 5 }).height).toBe(HTML_EDITOR_DEFAULTS.height);
    expect(sanitizeHtmlEditorPrefs({ height: 99999 }).height).toBe(HTML_EDITOR_DEFAULTS.height);
  });

  it('never treats auto_capture as true unless explicitly set', () => {
    expect(sanitizeHtmlEditorPrefs({ auto_capture: false }).auto_capture).toBe(false);
    expect(sanitizeHtmlEditorPrefs({}).auto_capture).toBe(true);
  });
});
