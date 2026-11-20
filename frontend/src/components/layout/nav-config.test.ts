import { describe, it, expect } from 'vitest';
import { TEACHER_NAV, isTeacherRoute } from './nav-config';

/**
 * The AI assistant lives in the teacher sidebar (not a floating button):
 * there must be exactly one «اسألني عن المنصة» entry and it must open inside
 * the teacher panel shell.
 */
describe('teacher assistant nav entry', () => {
  const items = TEACHER_NAV.flatMap((g) => g.items);

  it('exposes the assistant as a single sidebar item', () => {
    const assistantItems = items.filter((i) => i.href === '/teacher/assistant');
    expect(assistantItems).toHaveLength(1);
    expect(assistantItems[0].ar).toBe('اسألني عن المنصة');
    // No plan gate: the assistant is available on every plan.
    expect(assistantItems[0].gate).toBeFalsy();
  });

  it('renders the assistant page inside the teacher shell', () => {
    expect(isTeacherRoute('/teacher/assistant')).toBe(true);
  });
});

/**
 * Regression: the "Live Sessions" nav item was rendered for every plan, so a
 * teacher on a plan without live streaming saw the link and got a 403 API
 * error on the page. It must be gated by `live_stream_enabled`.
 */
describe('teacher nav plan gating', () => {
  const items = TEACHER_NAV.flatMap((g) => g.items);

  it('gates the live sessions item behind live_stream_enabled', () => {
    const live = items.find((i) => i.href === '/teacher/live');
    expect(live, 'live nav item must exist').toBeTruthy();
    expect(live?.gate).toBe('live_stream_enabled');
  });

  it('every gated teacher item references a real plan gate key', () => {
    const gated = items.filter((i) => i.gate);
    // Guard against typos in gate keys — the shell hides items by exact match.
    for (const item of gated) {
      expect(item.gate).toMatch(/^[a-z_]+_enabled$|^export_reports$/);
    }
  });
});
