'use client';

import { useState } from 'react';
import Link from 'next/link';
import { Menu, X, Globe } from 'lucide-react';
import { useSettings } from '@/store/settings';
import { resolveLogoUrl } from '@/lib/logo';
import { useLocale } from '@/store/locale';

/** Slim public header + footer for standalone public pages (pricing, auth, checkout). */
export function PublicShell({ children }: { children: React.ReactNode }) {
  const { settings } = useSettings();
  const { locale } = useLocale();
  const [open, setOpen] = useState(false);
  const ar = locale === 'ar';

  const links = [
    { href: '/', label: ar ? 'الرئيسية' : 'Home' },
    { href: '/pricing', label: ar ? 'الأسعار' : 'Pricing' },
  ];

  return (
    <div className="flex min-h-screen flex-col bg-background">
      <header className="sticky top-0 z-50 border-b bg-background/85 backdrop-blur-xl">
        <div className="mx-auto flex h-16 max-w-7xl items-center gap-4 px-4 sm:px-6">
          <Link href="/" className="flex shrink-0 items-center gap-2.5">
            <img src={resolveLogoUrl(settings.logo_url)} alt="logo" className="h-9 w-9 rounded-xl object-cover shadow-sm" onError={(e) => { (e.currentTarget as HTMLImageElement).style.display = 'none'; }} />
            <span className="text-base font-bold">{settings.platform_name || (ar ? 'منصة التعليم' : 'Tadreesly')}</span>
          </Link>
          <nav className="hidden items-center gap-1 md:flex" aria-label="Main">
            {links.map((l) => (
              <Link
                key={l.href}
                href={l.href}
                className="rounded-lg px-3.5 py-2 text-sm text-gray-600 transition-colors hover:bg-gray-50 hover:text-gray-900"
              >
                {l.label}
              </Link>
            ))}
          </nav>
          <div className="ms-auto flex items-center gap-2">
            <button
              onClick={() => setOpen(!open)}
              className="flex h-10 w-10 items-center justify-center rounded-xl text-gray-600 hover:bg-gray-50 md:hidden"
              aria-label={open ? (ar ? 'إغلاق القائمة' : 'Close menu') : (ar ? 'فتح القائمة' : 'Open menu')}
              aria-expanded={open}
            >
              {open ? <X className="h-5 w-5" /> : <Menu className="h-5 w-5" />}
            </button>
          </div>
        </div>
        {open && (
          <nav className="space-y-1 border-t px-4 py-3 md:hidden" aria-label="Mobile">
            {links.map((l) => (
              <Link
                key={l.href}
                href={l.href}
                onClick={() => setOpen(false)}
                className="block rounded-lg px-3 py-2.5 text-sm text-gray-700 hover:bg-gray-50"
              >
                {l.label}
              </Link>
            ))}
          </nav>
        )}
      </header>

      <main className="flex-1">{children}</main>

      <footer className="border-t bg-slate-900 py-8">
        <div className="mx-auto flex max-w-7xl flex-col items-center justify-between gap-4 px-4 text-sm text-white/50 sm:flex-row sm:px-6">
          <p className="flex items-center gap-1.5">
            <Globe className="h-4 w-4 text-teal-400" />
            © {new Date().getFullYear()} {settings.platform_name || 'Tadreesly'} · {ar ? 'جميع الحقوق محفوظة' : 'All rights reserved'}
          </p>
          <div className="flex items-center gap-4">
            <Link href="/" className="transition-colors hover:text-white">{ar ? 'الرئيسية' : 'Home'}</Link>
            <Link href="/pricing" className="transition-colors hover:text-white">{ar ? 'الأسعار' : 'Pricing'}</Link>
            <Link href="/teacher/login" className="transition-colors hover:text-white">{ar ? 'دخول المعلمين' : 'Teacher Login'}</Link>
          </div>
        </div>
      </footer>
    </div>
  );
}
