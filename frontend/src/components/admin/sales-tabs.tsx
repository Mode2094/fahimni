'use client';

import { useLocale } from '@/store/locale';
import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { Wallet, ReceiptText, Ticket, HandCoins } from 'lucide-react';

/**
 * تبويبات المبيعات الموحدة — تنقل بين الصفحات الثلاث (لا iframe).
 * تُعرض أعلى كل صفحة من صفحات المبيعات.
 */
export default function SalesTabs() {
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);
  const path = usePathname();

  const tabs = [
    { href: '/admin/payments', icon: Wallet, ar: 'المدفوعات', en: 'Payments' },
    { href: '/admin/invoices', icon: ReceiptText, ar: 'الفواتير', en: 'Invoices' },
    { href: '/admin/coupons', icon: Ticket, ar: 'الكوبونات', en: 'Coupons' },
  ];

  return (
    <div className="flex items-center gap-1.5 rounded-2xl bg-white border p-1.5 shadow-sm mb-4">
      <div className="flex items-center gap-1.5 px-3 text-teal-700">
        <HandCoins className="h-4 w-4" />
        <span className="text-sm font-bold">{t('المبيعات', 'Sales')}</span>
      </div>
      <div className="flex flex-1 flex-wrap gap-1.5">
        {tabs.map((tab) => {
          const active = path === tab.href || (tab.href === '/admin/payments' && path === '/admin/sales');
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
