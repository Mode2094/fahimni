'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { WifiOff, RefreshCw, BookOpen, Home } from 'lucide-react';

/**
 * Offline screen. Reconnects automatically the moment the browser reports the
 * network is back, so the student never has to know what a service worker is.
 */
export default function OfflineView() {
  const [online, setOnline] = useState(false);
  const [retrying, setRetrying] = useState(false);

  useEffect(() => {
    const sync = () => setOnline(navigator.onLine);
    sync();
    window.addEventListener('online', sync);
    window.addEventListener('offline', sync);

    const onOnline = () => {
      // A real connection: reload the page the student actually wanted.
      window.location.reload();
    };
    window.addEventListener('online', onOnline);

    return () => {
      window.removeEventListener('online', sync);
      window.removeEventListener('offline', sync);
      window.removeEventListener('online', onOnline);
    };
  }, []);

  const retry = () => {
    setRetrying(true);
    window.location.reload();
  };

  return (
    <main className="flex min-h-screen flex-col items-center justify-center bg-background px-6 text-center">
      <div className="flex h-20 w-20 items-center justify-center rounded-3xl bg-teal-50 text-teal-600">
        <WifiOff className="h-10 w-10" />
      </div>

      <h1 className="mt-6 text-2xl font-bold">لا يوجد اتصال بالإنترنت</h1>
      <p className="mt-3 max-w-sm text-sm leading-relaxed text-muted-foreground">
        التطبيق يعمل، لكن جزءًا من المحتوى — الدروس والفيديوهات والاختبارات — يحتاج
        اتصالاً بالإنترنت ليعمل. تحقّق من الشبكة ثم أعد المحاولة.
      </p>

      {online && (
        <p role="status" className="mt-4 text-sm font-medium text-teal-700">
          عاد الاتصال — جارٍ إعادة التحميل…
        </p>
      )}

      <div className="mt-8 flex flex-col gap-3 sm:flex-row">
        <button
          type="button"
          onClick={retry}
          disabled={retrying}
          className="inline-flex min-h-[48px] items-center justify-center gap-2 rounded-2xl bg-teal-600 px-6 font-bold text-white shadow-lg transition-colors hover:bg-teal-700 disabled:opacity-60"
        >
          <RefreshCw className={`h-4 w-4 ${retrying ? 'animate-spin' : ''}`} />
          إعادة المحاولة
        </button>
        <Link
          href="/my-learning"
          className="inline-flex min-h-[48px] items-center justify-center gap-2 rounded-2xl border px-6 font-bold text-teal-700 transition-colors hover:bg-teal-50"
        >
          <BookOpen className="h-4 w-4" />
          كورساتي
        </Link>
      </div>

      <p className="mt-6 flex items-center gap-1.5 text-xs text-muted-foreground">
        <Home className="h-3.5 w-3.5" />
        الصفحات المحفوظة تعمل دون اتصال، والمحتوى المباشر يُحمّل عند عودة الشبكة.
      </p>
    </main>
  );
}
