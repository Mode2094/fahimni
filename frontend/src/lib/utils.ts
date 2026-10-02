import { clsx, type ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs));
}
export const curr = (a: number, c = 'JOD') =>
  new Intl.NumberFormat('en-US', { style: 'currency', currency: c, maximumFractionDigits: 2 }).format(a);

/** Localized currency name — "دينار أردني" instead of a bare code like JOD. */
export const currencyLabel = (c: string, ar = true): string => {
  const labels: Record<string, [string, string]> = {
    JOD: ['دينار أردني', 'Jordanian Dinar'],
    SAR: ['ريال سعودي', 'Saudi Riyal'],
    USD: ['دولار أمريكي', 'US Dollar'],
    EGP: ['جنيه مصري', 'Egyptian Pound'],
    AED: ['درهم إماراتي', 'UAE Dirham'],
  };
  const l = labels[(c || '').toUpperCase()];
  return l ? (ar ? l[0] : l[1]) : c;
};
export const date = (d: string | null) =>
  d ? new Intl.DateTimeFormat('en-US', { month: 'short', day: 'numeric', year: 'numeric' }).format(new Date(d)) : '-';
export const init = (n: string) =>
  n.split(' ').map(s => s[0]).join('').toUpperCase().slice(0, 2);
