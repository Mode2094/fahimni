'use client';

import { useLocale } from '@/store/locale';
import { useSettings } from '@/store/settings';
import { ArrowRight, ArrowLeft, ShieldCheck, FileText } from 'lucide-react';

export type LegalSection = {
  title: { ar: string; en: string };
  body?: { ar: string; en: string };
  items?: { ar: string; en: string }[];
};

export function LegalPage({ icon, title, subtitle, updated, sections }: {
  icon: 'shield' | 'file';
  title: { ar: string; en: string };
  subtitle: { ar: string; en: string };
  updated: string;
  sections: LegalSection[];
}) {
  const { locale } = useLocale();
  const { settings } = useSettings();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);
  const platform = settings.platform_name || 'Tadreesly';
  const Icon = icon === 'shield' ? ShieldCheck : FileText;

  return (
    <div className="min-h-screen bg-slate-50" dir={locale === 'ar' ? 'rtl' : 'ltr'}>
      {/* Header */}
      <header className="bg-slate-900 text-white">
        <div className="max-w-3xl mx-auto px-4 py-12">
          <a href="/" className="inline-flex items-center gap-1.5 text-sm text-white/60 hover:text-white transition-colors mb-6">
            {locale === 'ar' ? <ArrowRight className="h-4 w-4" /> : <ArrowLeft className="h-4 w-4" />}
            {t('العودة للرئيسية', 'Back to home')}
          </a>
          <div className="flex items-center gap-4">
            <span className="flex h-12 w-12 items-center justify-center rounded-2xl bg-teal-600 shrink-0"><Icon className="h-6 w-6 text-white" /></span>
            <div>
              <h1 className="text-2xl sm:text-3xl font-bold">{t(title.ar, title.en)}</h1>
              <p className="text-white/60 text-sm mt-1">{t(subtitle.ar, subtitle.en)}</p>
            </div>
          </div>
          <p className="text-xs text-white/40 mt-4">{t('آخر تحديث:', 'Last updated:')} {updated} · {platform}</p>
        </div>
      </header>

      {/* Body */}
      <main className="max-w-3xl mx-auto px-4 py-10">
        <div className="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
          {sections.map((s, i) => (
            <section key={i} className={`p-6 sm:p-8 ${i > 0 ? 'border-t border-slate-100' : ''}`}>
              <h2 className="text-lg font-bold flex items-center gap-2.5">
                <span className="flex h-7 w-7 items-center justify-center rounded-lg bg-teal-50 text-teal-700 text-xs font-bold shrink-0">{i + 1}</span>
                {t(s.title.ar, s.title.en)}
              </h2>
              {s.body && (
                <p className="mt-3 text-sm leading-7 text-slate-600">{t(s.body.ar, s.body.en)}</p>
              )}
              {s.items && (
                <ul className="mt-3 space-y-2.5">
                  {s.items.map((it, j) => (
                    <li key={j} className="flex items-start gap-2 text-sm leading-7 text-slate-600">
                      <span className="text-teal-600 font-bold mt-0.5 shrink-0">•</span>
                      <span>{t(it.ar, it.en)}</span>
                    </li>
                  ))}
                </ul>
              )}
            </section>
          ))}
        </div>

        <p className="text-center text-xs text-slate-400 mt-8">
          © {new Date().getFullYear()} {platform} · {t('جميع الحقوق محفوظة', 'All rights reserved')}
        </p>
      </main>
    </div>
  );
}
