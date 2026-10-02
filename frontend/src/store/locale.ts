import { create } from 'zustand';

export type Locale = 'en' | 'ar';

export const useLocale = create<{ locale: Locale; setLocale: (l: Locale) => void }>((set) => ({
  // Initialize from the saved preference so a saved English user does not
  // flash an Arabic UI on every reload.
  locale: (typeof window !== 'undefined' && (localStorage.getItem('locale') as Locale)) || 'ar',
  setLocale: (l) => {
    localStorage.setItem('locale', l);
    document.documentElement.lang = l;
    document.documentElement.dir = l === 'ar' ? 'rtl' : 'ltr';
    document.documentElement.classList.remove('rtl', 'ltr');
    document.documentElement.classList.add(l === 'ar' ? 'rtl' : 'ltr');
    set({ locale: l });
  },
}));
