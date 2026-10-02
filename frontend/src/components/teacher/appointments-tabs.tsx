'use client';

import { useLocale } from '@/store/locale';
import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { CalendarClock, HandCoins, CalendarDays } from 'lucide-react';

/**
 * تبويبات المواعيد والجلسات المدفوعة — تنقل بين الصفحتين (لا iframe).
 */
export default function AppointmentsTabs() {
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);
  const path = usePathname();

  const tabs = [
    { href: '/teacher/appointments', icon: CalendarClock, ar: 'المواعيد', en: 'Appointments' },
    { href: '/teacher/services', icon: HandCoins, ar: 'الجلسات المدفوعة', en: 'Paid Sessions' },
  ];

  return (
    <div className="flex items-center gap-1.5 rounded-2xl bg-white border p-1.5 shadow-sm mb-4">
      <div className="flex items-center gap-1.5 px-3 text-teal-700">
        <CalendarDays className="h-4 w-4" />
        <span className="text-sm font-bold">{t('المواعيد والجلسات', 'Appointments & Sessions')}</span>
      </div>
      <div className="flex flex-1 flex-wrap gap-1.5">
        {tabs.map((tab) => {
          const active = path === tab.href;
          return (
            <Link
              key={tab.href}
              href={tab.href}
              className={`flex items-center gap-2 rounded-xl px-4 py-2 text-sm font-semibold transition ${active ? 'bg-teal-600 text-white shadow' : 'text-gray-500 hover:bg-teal-50 hover:text-teal-700'}`}
            >
              <tab.icon className="h-4 w-4" />
              {t(tab.ar, tab.en)}
            </Link>
          );
        })}
      </div>
    </div>
  );
}
