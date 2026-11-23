'use client';

import { useSettings } from '@/store/settings';
import { Faq } from '@/components/seo/faq';

/**
 * FAQ that drops every trial-related question (and its FAQPage structured
 * data) the moment the master trial switch is OFF, so the page never promises
 * a trial that no longer exists.
 */
export function TrialFaq({
  title,
  hint,
  baseItems,
  trialItems,
}: {
  title: string;
  hint?: string;
  baseItems: [string, string][];
  trialItems: [string, string][];
}) {
  const { settings } = useSettings();
  const items = settings.trial_available ? [...trialItems, ...baseItems] : baseItems;

  return <Faq title={title} hint={hint} items={items} />;
}
