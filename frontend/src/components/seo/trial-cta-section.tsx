'use client';

import { useLocale } from '@/store/locale';
import { useSettings } from '@/store/settings';
import { CtaSection } from '@/components/seo/cta-section';
import { trialCtaLabel } from '@/lib/trial';

/**
 * Client wrapper around the server-rendered CtaSection.
 *
 * When the master trial switch is OFF (or the limited offer is exhausted) the
 * primary label is replaced with «أنشئ منصتك واختر اشتراكك» so no marketing
 * page keeps promising a free trial that no longer exists. When the trial is
 * available the page's own (trial-aware) label is kept.
 */
export function TrialCtaSection({
  title,
  text,
  primaryLabel,
  primaryHref,
  secondaryLabel,
  secondaryHref,
  offTitle,
  offText,
}: {
  title: string;
  text: string;
  primaryLabel?: string;
  primaryHref?: string;
  secondaryLabel?: string;
  secondaryHref?: string;
  /** Copy used instead of title/text when no trial is available. */
  offTitle?: string;
  offText?: string;
}) {
  const { locale } = useLocale();
  const { settings } = useSettings();

  const available = settings.trial_available;
  const label = available && primaryLabel ? primaryLabel : trialCtaLabel(settings, locale);

  return (
    <CtaSection
      title={available ? title : offTitle ?? title}
      text={available ? text : offText ?? text}
      primaryLabel={label}
      primaryHref={primaryHref}
      secondaryLabel={secondaryLabel}
      secondaryHref={secondaryHref}
    />
  );
}
