'use client';

import { useState } from 'react';
import { useLocale } from '@/store/locale';
import { Users, CreditCard } from 'lucide-react';
import AdminTeachersPanel from './teachers-panel';
import AdminSubscriptionsPanel from './subscriptions-panel';

/**
 * قسم موحّد: «المعلمون والاشتراكات».
 * كان المعلمون والاشتراكات صفحتين منفصلتين ويختلط الأمر بينهما — الآن
 * تبويب واحد يجمع الاثنين في مكان واحد.
 */
export default function TeachersSubscriptions({ defaultTab = 'teachers' }: { defaultTab?: 'teachers' | 'subscriptions' }) {
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);
  const [tab, setTab] = useState<'teachers' | 'subscriptions'>(defaultTab);

  const tabs = [
    { key: 'teachers' as const, icon: Users, ar: 'المعلمون', en: 'Teachers' },
    { key: 'subscriptions' as const, icon: CreditCard, ar: 'الاشتراكات', en: 'Subscriptions' },
  ];

  return (
    <div className="space-y-5">
      <div className="flex gap-1.5 p-1.5 bg-muted/50 rounded-2xl w-full sm:w-fit mx-auto sm:mx-0">
        {tabs.map((x) => (
          <button
            key={x.key}
            onClick={() => setTab(x.key)}
            className={`flex-1 sm:flex-none inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-xl text-sm font-medium transition-all ${tab === x.key ? 'bg-white shadow text-teal-700' : 'text-gray-500 hover:text-gray-800'}`}
          >
            <x.icon className="h-4 w-4" />{t(x.ar, x.en)}
          </button>
        ))}
      </div>
      {tab === 'teachers' ? <AdminTeachersPanel /> : <AdminSubscriptionsPanel />}
    </div>
  );
}
