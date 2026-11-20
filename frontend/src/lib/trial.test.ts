import { describe, it, expect } from 'vitest';
import { offerBannerText, showOfferBanner, trialCtaLabel, howItWorksLabel } from './trial';
import type { TrialSettings } from './trial';

function s(overrides: Partial<TrialSettings> = {}): TrialSettings {
  return {
    trial_available: true,
    trial_days: 7,
    teacher_trial_enabled: true,
    teacher_trial_offer_enabled: false,
    teacher_trial_offer_seats: 50,
    teacher_trial_offer_seats_remaining: 50,
    teacher_trial_offer_text: 'عرض {seats} مقعد — المتبقي {remaining} — {days} أيام',
    teacher_trial_offer_text_rendered: '',
    ...overrides,
  };
}

describe('trial helpers', () => {
  it('shows the trial CTA only while a trial is available', () => {
    expect(trialCtaLabel(s(), 'ar')).toContain('مجانًا');
    expect(trialCtaLabel(s({ trial_available: false }), 'ar')).toContain('اختر اشتراكك');
    expect(howItWorksLabel('ar')).toBe('شاهد كيف تعمل المنصة');
  });

  it('never shows the offer banner when the master switch is off', () => {
    expect(
      showOfferBanner(s({ teacher_trial_enabled: false, teacher_trial_offer_enabled: true, teacher_trial_offer_seats_remaining: 10 })),
    ).toBe(false);
  });

  it('hides the offer banner once the seats are exhausted', () => {
    expect(showOfferBanner(s({ teacher_trial_offer_enabled: true, teacher_trial_offer_seats_remaining: 0 }))).toBe(false);
    expect(showOfferBanner(s({ teacher_trial_offer_enabled: true, teacher_trial_offer_seats_remaining: 3 }))).toBe(true);
  });

  it('renders the offer text from real seat numbers', () => {
    expect(offerBannerText(s({ teacher_trial_offer_seats_remaining: 7 }), 'ar')).toBe('عرض 50 مقعد — المتبقي 7 — 7 أيام');
    // Server-rendered text wins when present.
    expect(offerBannerText(s({ teacher_trial_offer_text_rendered: 'جاهز' }), 'ar')).toBe('جاهز');
  });
});
