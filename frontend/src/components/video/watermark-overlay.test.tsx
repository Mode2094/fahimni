import { describe, it, expect } from 'vitest';
import { render } from '@testing-library/react';

import { WatermarkOverlay } from './watermark-overlay';

/**
 * معاينة العلامة المائية للمعلّم تستخدم مكوّن العرض الحقيقي نفسه، لذلك اختبار
 * المكوّن يضمن أن ما يراه المعلّم في الإعدادات مطابق لما يراه الطالب.
 */
describe('WatermarkOverlay (teacher preview shares the real renderer)', () => {
  it('renders the student name and a traceable date/time stamp', () => {
    render(<WatermarkOverlay text="مثال: أحمد الطالب" opacity={0.35} size="1rem" speed="slow" />);

    const wm = document.querySelector('[data-watermark]') as HTMLElement;
    expect(wm).toBeTruthy();
    expect(wm.textContent).toContain('مثال: أحمد الطالب');
    expect(wm.textContent).toMatch(/\d{4}-\d{2}-\d{2} \d{2}:\d{2}/);
  });

  it('applies the configured opacity and font size', () => {
    render(<WatermarkOverlay text="سارة" opacity={0.5} size="1.3rem" speed="fast" />);

    const wm = document.querySelector('[data-watermark]') as HTMLElement;
    expect(wm.style.opacity).toBe('0.5');
    expect(wm.style.fontSize).toBe('1.3rem');
  });

  it('is marked aria-hidden so it never pollutes the accessibility tree', () => {
    render(<WatermarkOverlay text="سارة" opacity={0.35} size="1rem" speed="slow" />);
    const wm = document.querySelector('[data-watermark]') as HTMLElement;
    expect(wm.getAttribute('aria-hidden')).toBe('true');
    expect(wm.className).toContain('pointer-events-none');
  });
});
