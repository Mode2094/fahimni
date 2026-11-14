/**
 * Canonical platform identity: منصة فهمني (short: فهمني, English: Fahimni).
 *
 * The platform name is user-editable at runtime (platform_settings /
 * academy_name). Older installs still store the previous brand, and deployments
 * never rewrite that data, so any legacy value is normalised here on read while
 * a genuinely custom name chosen by an admin is preserved.
 */

export const BRAND_NAME = 'منصة فهمني';
export const BRAND_NAME_SHORT = 'فهمني';
export const BRAND_NAME_EN = 'Fahimni';
export const BRAND_SUPPORT_EMAIL = 'support@fahimni.me';

const LEGACY_PATTERN = /tadreesly|تدريسلي|تدريسلى/i;

const LEGACY_EMAILS = ['support@tadreesly.com', 'info@tadreesly.com', 'hello@tadreesly.com'];

/** Map a legacy brand name to the official one; keep anything custom. */
export function normalizeBrandName(value?: string | null): string {
  const name = (value ?? '').trim();
  if (!name) return BRAND_NAME;
  return LEGACY_PATTERN.test(name) ? BRAND_NAME : name;
}

/** Normalise a contact address, mapping legacy support mailboxes to the new one. */
export function normalizeBrandEmail(value?: string | null): string {
  const email = (value ?? '').trim().toLowerCase();
  if (!email || LEGACY_EMAILS.includes(email)) return BRAND_SUPPORT_EMAIL;
  return email;
}
