import type { PlatformSettings } from '@/store/settings';

/** The subset of platform settings the trial UI needs. */
export type TrialSettings = Pick<
  PlatformSettings,
  | 'trial_available'
  | 'trial_days'
  | 'teacher_trial_enabled'
  | 'teacher_trial_offer_enabled'
  | 'teacher_trial_offer_seats'
  | 'teacher_trial_offer_seats_remaining'
  | 'teacher_trial_offer_text'
  | 'teacher_trial_offer_text_rendered'
>;

/** Primary conversion CTA — trial-aware. */
export function trialCtaLabel(s: TrialSettings, locale: string): string {
  if (s.trial_available) {
    return locale === 'ar'
      ? `أنشئ منصتك مجانًا لمدة ${s.trial_days} أيام`
      : `Create your platform free for ${s.trial_days} days`;
  }
  return locale === 'ar' ? 'أنشئ منصتك واختر اشتراكك' : 'Create your platform & choose your plan';
}

/** Secondary CTA — always "see how the platform works". */
export function howItWorksLabel(locale: string): string {
  return locale === 'ar' ? 'شاهد كيف تعمل المنصة' : 'See how the platform works';
}

/** An active limited-offer banner shows only when the master switch is ON and seats remain. */
export function showOfferBanner(s: TrialSettings): boolean {
  return (
    !!s.teacher_trial_enabled &&
    !!s.teacher_trial_offer_enabled &&
    !!s.trial_available &&
    s.teacher_trial_offer_seats_remaining > 0
  );
}

/** Offer banner text, localized. The Arabic template is rendered server-side. */
export function offerBannerText(s: TrialSettings, locale: string): string {
  if (locale === 'ar') {
    return (
      s.teacher_trial_offer_text_rendered ||
      s.teacher_trial_offer_text
        .replace('{seats}', String(s.teacher_trial_offer_seats))
        .replace('{remaining}', String(s.teacher_trial_offer_seats_remaining))
        .replace('{days}', String(s.trial_days))
    );
  }
  return `🎁 ${s.trial_days}-day free trial for the first ${s.teacher_trial_offer_seats} teachers — ${s.teacher_trial_offer_seats_remaining} seats left`;
}
